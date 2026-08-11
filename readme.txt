=== Migrator - Site Migration and Backup ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.2.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, clone and migrate your whole site to one file, then restore it here or on a new host. URLs and paths are fixed for you. Runs on your server.

== Description ==

Migrator packs your database and everything in `wp-content` into a single file you can download, keep as a backup, and restore, on the same site or on a brand-new install somewhere else. When you restore onto a different address, Migrator rewrites the old URLs and file paths to the new ones for you, so the site just works.

Everything happens on your own server. There is no account to create, no file size sold back to you, and nothing is ever sent to a third-party service. Because it is fully open, you can read exactly what it does: the source lives at https://github.com/wppoland/plogins-migrator, which is also where to file a bug or request a feature.

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
* Serialization-safe search and replace across the database, with a dry-run preview and a `wp migrator replace` command, to change a domain, URL or path safely
* Inspect any stored backup before you restore it: source URL, WordPress and PHP versions, table count, plus pre-restore checks for table prefix, disk space and writability
* Self-hosted: no account, no third-party service, nothing leaves your server

Reporting a security issue: email hello@wppoland.com, and under our [coordinated disclosure policy](https://wppoland.com/en/security-policy/) we confirm within two business days, assess within five, and patch a critical issue within seven days of confirming it.

== Plogins Migrator PRO ==

The free edition backs up and migrates your whole site by hand. **Plogins Migrator PRO** makes it run itself:

* **Scheduled and incremental backups** - daily or weekly with retention; incremental stores only the files that changed, and every archive still carries the whole database
* **Cloud and off-site copies** - S3, R2, Backblaze B2, Wasabi, FTP/SFTP, WebDAV, Dropbox and Google Drive
* **Recovery points** - one-click rollback to a known-good backup
* **Encrypted backups** - password-protected archives, decrypted on restore
* **Server-to-server transfer** - move a site between servers with no manual download
* **Table sync** - import chosen database tables from a backup into a live site and leave the rest alone
* **Email notifications and activity log** - a silent failure never slips by
* **Multisite, network to network** - back up and migrate a whole network with correct URL rewriting; pulling a single subsite out is a WP-CLI job

Everything in the free edition stays free and open. Plogins Migrator PRO starts at 49 EUR per year (PLN shown at checkout).

Compare editions and pricing: [plogins.com/plogins-migrator-pro/pricing/](https://plogins.com/plogins-migrator-pro/pricing/)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/migrator`, or install it from Plugins → Add New.
2. Activate it. There are no required dependencies.
3. Open **Migrator** in the admin menu to create a backup, or use `wp migrator export` from the command line.

== Frequently Asked Questions ==

= Documentation and links =

* **Documentation** - [plogins.com/plogins-migrator/docs/](https://plogins.com/plogins-migrator/docs/)
* **Plugin page** - [plogins.com/plogins-migrator/](https://plogins.com/plogins-migrator/)
* **Source code** - [github.com/wppoland/plogins-migrator](https://github.com/wppoland/plogins-migrator)
* **Bug reports and feature requests** - [github.com/wppoland/plogins-migrator/issues](https://github.com/wppoland/plogins-migrator/issues)


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


= Does this plugin work on WordPress Multisite? =

It runs on Multisite: network activate it or activate it on individual sites, and each site keeps its own settings and data. Restoring a network is a different matter. The free edition refuses an import when either the archive or the target site is a network, so network migration needs the Migrator Pro add-on.

= How does Migrator compare to Duplicator and All-in-One WP Migration? =

All three back up, clone and migrate WordPress, and all three run on your own server. The differences worth knowing, comparing the free editions:

* **Size limits**: Migrator's free edition has no artificial size limit. All-in-One WP Migration limits imports in its free version and sells a paid Unlimited extension to remove that cap. Duplicator's free edition has no advertised size cap either.
* **Serialization-safe URL rewriting**: all three do it.
* **Exclusions and stored-backup management**: free in Migrator and in Duplicator; part of the paid tier for All-in-One WP Migration.
* **Scheduling, incremental backups, cloud storage and multisite**: paid in all three (Migrator PRO, Duplicator Pro, paid All-in-One WP Migration extensions).
* **WP-CLI**: Migrator ships `wp migrator export`, `import` and `replace` in the free version.
* **Licensing**: Migrator is GPLv2 and fully open source, including PRO.

Competitor details as of July 2026; check the vendors' own sites for their current features and pricing.

== Screenshots ==

1. The Migrator screen: create a backup with presets and exclusion options, alongside restore and your saved backups.
2. The file-size explorer: scan wp-content and see each folder's size so you can leave out what you do not need.

== Translations ==

Plogins Migrator includes Polish, German and Spanish translations for the plugin interface. The text domain is `plogins-migrator`, so WordPress.org language packs can also override or extend these bundled translations.

== Changelog ==

= 1.2.11 =
* The PRO notice in the admin, and the PRO section of this readme, now list what Migrator Pro actually ships, including table sync, and say plainly that incremental backups cover files while every archive still carries the whole database. Multisite is described as network to network, which is what the admin does.

= 1.2.10 =
* Docs: the Multisite answer said the plugin is Multisite compatible without saying that a network restore is refused in the free edition. It now says so, and points at the Pro add-on for network migration.


= 1.2.9 =
* Fixed: restoring into a subdirectory doubled the new address (https://site.com/shop/shop). A site's home and site address are normally the same string, and it was rewritten twice.

= 1.2.7 =
* Translations: completed Polish, German and Spanish for the PRO upgrade panel.

= 1.2.6 =
* Readme: added a factual comparison with Duplicator and All-in-One WP Migration.

= 1.2.5 =
* Security: hardened backup workspace path handling against traversal (defense in depth).

= 1.2.4 =
* Translation quality pass: corrected Polish, German and Spanish (product names kept in English, legal withdrawal terminology, WooCommerce glossary and grammar fixes).

= 1.2.3 =
* Documentation: readme links are now labelled links.

= 1.2.2 =
* Shortened display name (dropped the Plogins prefix; slug unchanged).

= 1.2.1 =
* Added a contextual written migration-help link after a successful backup.
* Fixed disk-space preflight formatting type handling.

= 1.2.0 =
* New: standalone serialization-safe search and replace (admin tool + dry run + `wp migrator replace`) to change a domain, URL or path without corrupting serialized data.
* New: inspect a stored backup before restoring - shows the source URL, WordPress and PHP versions and table count, and runs pre-restore checks (table prefix, disk space, writable files).

= 1.1.1 =
* Added a Free vs PRO overview to the readme.

= 1.1.0 =
* New: in-plugin overview of Plogins Migrator PRO (incremental + scheduled backups, off-site storage, one-click cloud restore) on the admin screen.

= 1.0.3 =
* Clearer name: Plogins Migrator - Site Migration and Backup (it backs up and migrates the whole site, not only WooCommerce).
* Hardened the export AJAX handler: the request payload is fully sanitized before any filter callback runs.

= 1.0.2 =
* Added bundled Polish, German and Spanish translations for the plugin interface.

= 1.0.1 =
* First stable release.

= 0.3.3 =
* Renamed to Plogins Migrator for a more distinctive plugin name.

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
