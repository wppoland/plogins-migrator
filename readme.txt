=== Migrator ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, clone and migrate your whole site to one file, then restore it here or on a new host. URLs and paths are fixed for you. Runs on your server.

== Description ==

Migrator packs your database and everything in `wp-content` into a single file you can download, keep as a backup, and restore, on the same site or on a brand-new install somewhere else. When you restore onto a different address, Migrator rewrites the old URLs and file paths to the new ones for you, so the site just works.

Everything happens on your own server. There is no account to create, no file size sold back to you, and nothing is ever sent to a third-party service. Because it is fully open, you can read exactly what it does: the source lives at https://github.com/wppoland/migrator, which is also where to file a bug or request a feature.

**How it works**

1. On the site you want to copy, create a backup. Migrator writes your database to a portable SQL dump and streams every file in `wp-content` into one archive next to it.
2. Download that archive (or, on a big site, build it from the command line, see below).
3. On the destination, the same site to roll back, or a fresh WordPress install to move to, restore the archive. Migrator imports the database, puts the files back, and rewrites the source site's web address and paths to this one.

The address rewrite is **safe for serialized data**: Migrator walks the actual data structures rather than doing a blind text replace, so the byte-length counts PHP stores inside serialized options and meta stay correct and nothing breaks.

**A few things worth knowing**

Backups are written to a protected folder (`wp-content/migrator-backups`) that denies direct web access, and the in-browser download is served only to logged-in administrators through an authenticated handler, the files are never exposed at a guessable URL. Each item inside an archive carries a checksum, so a truncated or corrupted backup is caught before it is ever restored over a live site.

Restoring **overwrites** the destination database and files, that is the point of a restore, so it asks for confirmation and is limited to administrators. Migrator never overwrites its own plugin folder during a restore, so it cannot pull the rug out from under itself mid-import.

For large sites where a browser request would time out, every job also runs from WP-CLI, which has no timeout:

`wp migrator export`
`wp migrator import path/to/backup.migrator`

**What's included**

* One-click backup of your database and all of `wp-content` into a single archive
* Restore to the same site, or migrate to a new host with automatic, serialization-safe URL and path rewriting
* Choose what to leave out: media, themes, plugins, cache, spam comments, post revisions, transients, WooCommerce sessions or Action Scheduler tables
* In-browser export with a progress bar and a direct download, resumable so large sites finish across multiple steps, plus drag-and-drop restore
* WP-CLI `export` and `import` commands for sites too large for the browser
* A safety snapshot of your database before every restore, rolled back automatically if anything fails
* Per-item checksums so a corrupt archive is detected, not restored
* Self-hosted: no account, no third-party service, nothing leaves your server

== Installation ==

1. Upload the plugin to `/wp-content/plugins/migrator`, or install it from Plugins → Add New.
2. Activate it. There are no required dependencies.
3. Open **Migrator** in the admin menu to create a backup, or use `wp migrator export` from the command line.

== Frequently Asked Questions ==

= Documentation and links =

* **Documentation** - https://plogins.com/migrator/docs/
* **Plugin page** - https://plogins.com/migrator/
* **Source code** - https://github.com/wppoland/migrator
* **Bug reports and feature requests** - https://github.com/wppoland/migrator/issues
* **Discussions and questions** - https://github.com/wppoland/migrator/discussions


= Does restoring delete what is already on the destination? =

Yes. A restore replaces the destination's database and files with the contents of the archive, that is what restoring a backup means. It is limited to administrators and asks for confirmation first. Always keep a separate backup of anything on the destination you want to keep.

= Will my links break when I move to a new domain? =

No. When you restore onto a different address, Migrator rewrites the old site URL and file paths to the new ones, including inside serialized data, so internal links and settings keep working.

= My site is large and the browser export stops. What do I do? =

Use WP-CLI, which has no request time limit: `wp migrator export` to build the archive and `wp migrator import <file>` to restore it.

= Does it send my data anywhere? =

No. Migrator runs entirely on your own server. It creates no account and contacts no external service. Your backups stay in `wp-content/migrator-backups` until you download or delete them.

= Where are my backups stored? =

In `wp-content/migrator-backups`, a folder protected from direct web access. Removing the plugin deletes that folder and its contents.

== Screenshots ==

1. The Migrator screen: create a backup (with options for what to leave out) or restore one by drag and drop.
2. A finished backup, with the progress bar at 100% and a one-click download.

== Changelog ==

= 0.3.2 =
* Tidier saved-backup rows: the date and size lead, the long file name is a muted single line that no longer wraps, and the row reflows neatly on narrow screens.

= 0.3.1 =
* Your saved backups now appear inside the "Restore a backup" card, so you can restore one in place without scrolling past it.

= 0.3.0 =
* New "Your backups" section: every backup stored on the site is listed with its date and size, so you can download, restore or delete it in one click without leaving the screen.
* Restore straight from a stored backup (gzip backups are unpacked automatically). The backup file is kept, not consumed.
* Added one-click presets to the backup screen: Full site, Database only and Media only set the right exclusions for you.

= 0.2.0 =
* Added a file-size explorer to the backup screen: scan wp-content, see each folder's size and file count, and tick folders or large files to leave out of the backup. Builds on the existing path exclusions.

= 0.1.0 =
* First release.
* One-file backup of the database (tables, views, triggers and stored routines) and all of `wp-content`.
* Restore to the same site, or migrate to a new host with serialization-safe URL and path rewriting.
* Selective backup: leave out media, themes, plugins, cache, spam comments, post revisions, transients, WooCommerce sessions or Action Scheduler tables.
* In-browser resumable export with a progress bar and a direct download, plus drag-and-drop restore.
* WP-CLI `export` and `import` for sites too large for the browser.
* Safety first: a pre-import database snapshot with automatic rollback if a restore fails, per-item checksums, and a refusal to import across a mismatched table prefix.
