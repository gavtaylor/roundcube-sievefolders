# sievefolders

A [Roundcube](https://roundcube.net/) webmail plugin that keeps your Sieve
filters pointing at the right folder when you rename or move a folder in
Roundcube.

> **This project was built using agentic AI development** (Claude Code).
> Review it accordingly before you install it.

## Why

Roundcube's core managesieve plugin (Settings → Filters) doesn't follow folder
renames. If you rename `Receipts`, or move it under `INBOX`, every rule that
files into it now points at a folder that no longer exists. Depending on the
server, the mail either quietly stays in your Inbox, or the old folder is
recreated, often unsubscribed and so invisible.

This has been raised upstream as
[roundcube/roundcubemail#10358](https://github.com/roundcube/roundcubemail/issues/10358).
The plugin is a stop-gap until managesieve handles it itself.

## Status

Verified on one live instance (Roundcube 1.7.4, Dovecot 2.3.16 / Pigeonhole,
Elastic skin). The tests used throwaway folders and a separate, inactive
filter set; real filters were never the test subject.

- Renaming a folder through the edit form updated the rules for that folder
  and its subfolder.
- Moving a folder (the request a drag-and-drop sends) did the same.
- A rule for a folder that merely shares the prefix was left alone.
- The inactive set stayed inactive, and the active set wasn't touched.
- Deleting a folder that rules still pointed at showed the warning.
- `helper/sieve-target-check` reported exactly the three broken targets in a
  copy of that set, delivered its email report, and stayed silent against the
  real filters.

## What it does

- **Rename or move** a folder in Settings → Folders, either by editing the
  folder or by dragging it. Every `fileinto` that targets that folder, or
  anything inside it, is updated to the new location. This covers every filter
  set, not just the active one, and the scripts are saved back through the same
  ManageSieve connection Settings → Filters uses. Only the folder name inside
  each matching `fileinto` changes; comments, formatting and every other rule
  are left exactly as they were.
- **Delete** a folder that filters still file into, and you get a warning
  saying how many rules are affected.

It can only see renames made *in Roundcube*; renames from a phone or another
IMAP client never reach it. For those, `helper/sieve-target-check` is a
read-only server-side check that emails a report when any account's active
filters point at a folder that doesn't exist. See
[`helper/sieve-target-check.service`](helper/sieve-target-check.service) and
its timer.

## Installation

Requires the core `managesieve` plugin.

```bash
cd /path/to/roundcube
composer require gavtaylor/sievefolders
```

Then add `sievefolders` to `$config['plugins']`. There's no configuration;
it uses managesieve's own settings (host, auth,
`managesieve_mbox_encoding`, `managesieve_replace_delimiter`).

Optional server-side check (Dovecot/Pigeonhole host, as root):

```bash
install -m 0755 helper/sieve-target-check /usr/local/sbin/
install -m 0644 helper/sieve-target-check.service helper/sieve-target-check.timer /etc/systemd/system/
# edit --notify/--from in the .service first
systemctl daemon-reload && systemctl enable --now sieve-target-check.timer
```

## Tests

```bash
php tests/rewriter_test.php
```

## License

[GPL-3.0-or-later](LICENSE).
