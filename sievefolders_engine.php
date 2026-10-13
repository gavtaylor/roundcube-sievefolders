<?php

/**
 * Thin subclass of the core managesieve plugin's engine, only to reach its
 * ManageSieve connection and its folder-name encoding (both protected) --
 * so this plugin talks to the Sieve server exactly the way Settings ->
 * Filters does, with the same host/auth/TLS config and the same
 * managesieve_mbox_encoding / managesieve_replace_delimiter handling.
 *
 * Only loaded after managesieve::get_engine() has put managesieve's lib/
 * on the include path, so rcube_sieve_engine is resolvable.
 */
class sievefolders_engine extends rcube_sieve_engine
{
    /** @return rcube_sieve|null */
    public function sieve()
    {
        return $this->sieve;
    }

    /**
     * Roundcube (UTF7-IMAP, IMAP delimiter) folder name -> the form it
     * takes inside a Sieve script.
     */
    public function script_folder(string $folder): string
    {
        return $this->mod_mailbox($folder, 'in');
    }

    /**
     * The hierarchy delimiter as it appears inside Sieve scripts.
     */
    public function script_delimiter(): string
    {
        return (string) ($this->rc->config->get('managesieve_replace_delimiter') ?: $_SESSION['imap_delimiter']);
    }
}
