<?php

require_once __DIR__ . '/sievefolders_rewriter.php';

/**
 * sievefolders
 *
 * Keeps Sieve filters pointing at the right folder when a folder is
 * renamed or moved in Roundcube. The core managesieve plugin doesn't follow
 * folder renames at all: every `fileinto` for the old name (or anything
 * under it) silently breaks, and mail for those rules falls back to INBOX.
 * This hooks Roundcube's own folder rename/move and rewrites matching
 * fileinto targets in every one of the user's filter sets, saved back
 * through the same ManageSieve connection Settings -> Filters uses.
 *
 * Only renames made *through Roundcube* can be followed -- one done in
 * another IMAP client never reaches Roundcube. See README.md for the
 * server-side check that catches those.
 *
 * Requires the core managesieve plugin, loaded before this one.
 *
 * @author Gavin Taylor
 *
 * Copyright (C) Gavin Taylor
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */
class sievefolders extends rcube_plugin
{
    // Folder rename/move/delete only happen under Settings -> Folders.
    public $task = 'settings';

    private $rc;
    private $engine;

    #[\Override]
    public function init()
    {
        $this->rc = rcmail::get_instance();
        $this->add_texts('localization/');

        $this->add_hook('folder_rename', [$this, 'folder_rename']);
        $this->add_hook('folder_update', [$this, 'folder_update']);
        $this->add_hook('folder_delete', [$this, 'folder_delete']);
    }

    /**
     * Drag-and-drop move in the Settings -> Folders list.
     */
    public function folder_rename($args)
    {
        $this->follow((string) $args['oldname'], (string) $args['newname']);

        return $args;
    }

    /**
     * Saving the folder edit form, which renames when the name or parent
     * changed.
     */
    public function folder_update($args)
    {
        $record = $args['record'] ?? [];

        if (!empty($record['oldname']) && $record['oldname'] !== $record['name']) {
            $this->follow((string) $record['oldname'], (string) $record['name']);
        }

        return $args;
    }

    /**
     * Deleting can't be "followed", but a filter still filing into the
     * deleted folder is worth knowing about straight away.
     */
    public function folder_delete($args)
    {
        if (!($engine = $this->engine())) {
            return $args;
        }

        $folder = $engine->script_folder((string) $args['name']);
        $count = 0;
        foreach ($this->scripts($engine) as $script) {
            $count += sievefolders_rewriter::references($script, $folder, $engine->script_delimiter());
        }

        if ($count) {
            $this->rc->output->show_message($this->gettext(['name' => 'deletewarning', 'vars' => ['count' => $count]]), 'warning');
        }

        return $args;
    }

    /**
     * Rewrites every filter set's fileinto targets from $old to $new.
     *
     * This runs from the hook, i.e. just *before* core performs the
     * rename -- core has no after-rename hook. If the new name already
     * exists the rename is bound to fail, so nothing is touched; any other
     * failure would leave filters pointing at the new name, the same
     * trade-off core makes for its own per-folder message_threading
     * setting.
     */
    private function follow(string $old, string $new): void
    {
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }

        if ($this->rc->get_storage()->folder_exists($new)) {
            return;
        }

        if (!($engine = $this->engine())) {
            return;
        }

        $sieve = $engine->sieve();
        $old_s = $engine->script_folder($old);
        $new_s = $engine->script_folder($new);
        $updated = 0;

        foreach ($this->scripts($engine) as $name => $script) {
            [$rewritten, $count] = sievefolders_rewriter::rename($script, $old_s, $new_s, $engine->script_delimiter());

            if (!$count) {
                continue;
            }

            if ($sieve->save_script($name, $rewritten) === false) {
                rcube::write_log('sievefolders', sprintf('user=%s script=%s rename=%s->%s error=save-failed',
                    $this->rc->get_user_name(), $name, $old_s, $new_s));
                $this->rc->output->show_message($this->gettext(['name' => 'savefailed', 'vars' => ['script' => $name]]), 'error');
                continue;
            }

            $updated += $count;
            rcube::write_log('sievefolders', sprintf('user=%s script=%s rename=%s->%s rules=%d',
                $this->rc->get_user_name(), $name, $old_s, $new_s, $count));
        }

        if ($updated) {
            $this->rc->output->show_message($this->gettext(['name' => 'updated', 'vars' => ['count' => $updated]]), 'confirmation');
        }
    }

    /**
     * Every filter set's raw text, keyed by name.
     */
    private function scripts(sievefolders_engine $engine): array
    {
        $sieve = $engine->sieve();
        $result = [];

        foreach ((array) $sieve->get_scripts() as $name) {
            $text = $sieve->get_script($name);
            if (is_string($text)) {
                $result[$name] = $text;
            }
        }

        return $result;
    }

    /**
     * A connected engine, or null (logged) if managesieve isn't available.
     */
    private function engine(): ?sievefolders_engine
    {
        if ($this->engine !== null) {
            return $this->engine ?: null;
        }

        $this->engine = false;
        $managesieve = $this->rc->plugins->get_plugin('managesieve');

        if (!$managesieve) {
            rcube::write_log('sievefolders', 'managesieve plugin not loaded -- nothing to update');
            return null;
        }

        // Puts managesieve's lib/ on the include path so its engine class
        // (our parent) can be loaded.
        $managesieve->get_engine();
        require_once __DIR__ . '/sievefolders_engine.php';

        $engine = new sievefolders_engine($managesieve);
        $error = $engine->connect($_SESSION['username'], $this->rc->decrypt($_SESSION['password']));

        if ($error || !$engine->sieve()) {
            rcube::write_log('sievefolders', sprintf('user=%s error=connect-failed code=%s', $this->rc->get_user_name(), (string) $error));
            return null;
        }

        return $this->engine = $engine;
    }
}
