=== Migrator - Site Migration and Backup ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, clone and migrate your whole site to one file, then restore it here or on a new host. URLs and paths are fixed for you. Runs on your server.

== Description ==

Migrator packs your database and everything in `wp-content` into a single file you can download, keep as a backup, and restore, on the same site or on a brand-new install somewhere else. When you restore onto a different address, Migrator rewrites the old URLs and file paths to the new ones for you, so the site just works.

Everything happens on your own server. There is no account to create, no file size sold back to you, and nothing is ever sent to a third-party service. Because it is fully open, you can read exactly what it does: the source lives at [github.com/wppoland/plogins-migrator](https://github.com/wppoland/plogins-migrator), which is also where to file a bug or request a feature.

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

== How Migrator compares ==

All six plugins named here back up and migrate WordPress. What separates them is what you get without paying, so the grid reads the free edition of each. Every entry was checked against vendor pricing pages and vendor documentation on 29 July 2026.

The columns, left to right: **Migrator**, **AIO** is All-in-One WP Migration, **Dupl** is Duplicator, **WPvivid**, **Updraft** is UpdraftPlus, **WPMigr** is WP Migrate. A cell reads **yes** if the free edition does it, **paid** if that vendor sells it in a paid tier or add-on, **no** if the vendor does not offer it at any price, **part** if only partly, and **?** if the vendor does not say. A row of **paid** is a paywall, not a missing feature; **no** is the one that means the thing does not exist.

                               Migrator   AIO   Dupl  WPvivid  Updraft  WPMigr
    --------------------------------------------------------------------------
    Full backup + migration         yes   yes    yes      yes      yes    part
    No size cap, free tier          yes    no     no      yes      yes       ?
    Scheduled backups               yes  paid   paid      yes      yes      no
    Cloud or FTP destination        yes  paid   paid      yes      yes      no
    Incremental backups            paid  paid     no     paid     paid      no
    Encrypted archives             paid   yes   paid     part     part       ?
    Server to server transfer      paid  paid   paid      yes     paid    paid
    Multisite net. restore         paid  paid   paid     part     paid    paid
    Imports chosen tables          paid     ?     no     paid     paid    paid
    Paid plan, from               EUR49   $69    $99      $49      $70     $49

**Where Migrator is ahead in free.** Scheduled backups with a retention rule, an off-site copy over FTP/FTPS or to a folder outside the web root, no size limit beyond what your own server allows, and a database snapshot taken before every import and restored automatically if the restore fails. No other free tier here documents that rollback. Automation is not the paid tier here: a shop that wants a nightly backup landing somewhere other than the server it protects needs nothing beyond the free plugin.

**What PRO adds.** Cloud destinations (S3, R2, Backblaze, Wasabi, SFTP, WebDAV, Dropbox, Google Drive), incremental backups, encrypted archives, recovery points, server-to-server transfer, Table Sync, multisite restore, deploying to an empty server, resetting a staging site to a clean install, and a white-label mode that puts an agency's own name on the plugin. From 49 EUR per year.

= All-in-One WP Migration =

Exports to a single `.wpress` file, and its AES-256 password-protected export is in the free plugin, which is one thing Migrator keeps in PRO. The free export is bounded by your host's PHP limits; lifting them is the Unlimited extension at $69/yr. Multisite is a separate add-on at $319/yr.

= Duplicator =

Builds a package plus an installer. The vendor publishes a 4 GB ceiling for the free edition, and 500 MB on the DupArchive engine. Scheduling, recovery points and importing start at $99/yr.

= WPvivid =

Scheduled backups with one retention rule, Dropbox, Google Drive, S3, OneDrive, DO Spaces, FTP and SFTP as destinations, and server-to-server transfer with a migration key. Archives split at 200 MB. The vendor states databases cannot be backed up incrementally, encryption in Pro covers the database only, and table-level merging is sold as a separate product.

= UpdraftPlus =

Free scheduling from every 2 hours up to monthly, with Google Drive, Dropbox, S3, Rackspace, FTP, Swift and email as destinations. Archives split at 400 MB. Multisite, incremental file backups and database encryption are Premium, $70 to $399/yr excluding VAT.

= WP Migrate =

Lite exports a ZIP and does not import at all. Moving files between live sites, and push and pull, are paid, $49 to $219 for the first year. No tier offers a cloud or FTP destination.

Competitor prices are in USD, Migrator PRO is priced in EUR, and no conversion is implied. List prices and tier limits change without notice, so confirm on the vendor's own page before you buy. Migrator itself is free under GPLv2 with no account to create; PRO is 49 to 149 EUR per year.

== Plogins Migrator PRO ==

The free edition backs up on a schedule and copies each backup off the server. **Plogins Migrator PRO** is for putting those copies further away, keeping more of them, and getting back faster:

* **Cloud destinations** - S3, R2, Backblaze B2, Wasabi, SFTP, WebDAV, Dropbox and Google Drive, on top of the FTP and folder destinations the free edition ships
* **Incremental backups** - store only the files that changed between fulls, with retention that keeps whole chains so a base is never orphaned
* **Recovery points** - one-click rollback to a known-good backup
* **Encrypted backups** - password-protected archives, decrypted on restore
* **Server-to-server transfer** - move a site between servers with no manual download
* **Table sync** - import chosen database tables from a backup into a live site and leave the rest alone
* **Email notifications and activity log** - a silent failure never slips by
* **Multisite, network to network** - back up and migrate a whole network with correct URL rewriting; pulling a single subsite out is a WP-CLI job
* **Reset a site to a clean install** - take a staging site back to fresh WordPress without reinstalling: a safety backup first, then the tables dropped, a clean install, and the address, title and administrator password put back. Single sites only, not networks
* **White label** - replace the menu name with your own, point the upgrade link elsewhere and hide the purchase prompt, so a client sees the tool under your brand

Everything in the free edition stays free and open. Plogins Migrator PRO starts at 49 EUR per year, billed in EUR.

Compare editions and pricing: [plogins.com/plogins-migrator-pro/pricing/](https://plogins.com/plogins-migrator-pro/pricing/)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/plogins-migrator`, or install it from Plugins → Add New.
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

= 1.3.0 =
* Added: **scheduled backups are now free.** Daily or weekly, with a retention rule, running unattended through WordPress cron and using the same engine as a manual backup.
* Added: **off-site copies are now free.** Every backup can be copied to an FTP or FTPS server, or to a folder outside the web root such as a mounted drive or NAS. A backup that lives only on the machine it protects is not a backup.
* Added: a destination registry, so an add-on contributes further destinations through the `migrator/backup_destinations` filter instead of the free plugin knowing about them.
* Changed: the free plugin no longer stores settings it cannot act on. A backup strategy or an encryption passphrase belongs to whatever implements them.
* Fixed: deactivating the paid add-on used to clear the backup cron event, which stopped the site's backups. Scheduling belongs to this plugin now, so it keeps running.
* Note for existing Migrator PRO users: your schedule, retention, exclusions and off-site credentials carry over automatically, including the passwords. Nothing needs re-entering.

= 1.2.18 =
* Fixed: the package no longer ships its own translation files. WordPress.org builds language packs from translate.wordpress.org, and a bundled catalogue shadows that pack, so a translation corrected upstream could not reach you until the next release. Your language now comes from the language pack, which is the copy that stays current.

= 1.2.17 =
* Fixed: the safeguard that is supposed to stop a restore writing over Migrator's own plugin folder had never worked. It compared against the folder name the plugin used before it was renamed, so on every current installation nothing matched, and restoring an older backup could extract an older copy of the plugin over the code that was running the restore. The folder names are now taken from the installation itself, so a future rename cannot break it again.
* Fixed: the same stale names were used to decide which plugin folders an export must never prune.

= 1.2.16 =
* Fixed: the plugin recorded 1.2.13 as its own version inside every archive it wrote, because the version constant had been left behind when the header was bumped. The number is stamped into the manifest of each backup and also busts the admin asset cache, so an archive said it came from an older plugin than the one that made it. The constant is now checked against the header when the package is built, so it cannot drift again.

= 1.2.15 =
* Fixed: the backup directory's .htaccess carried only the Apache 2.2 form of the deny rule. Apache 2.4 does not understand it without mod_access_compat, so on those servers the directory holding full database dumps had no server-level protection, leaving only the random token in the filename, which is a backstop for hosts that ignore .htaccess and not a replacement for the rule. Both forms are now written, each behind its own IfModule. An existing installation is corrected the next time the workspace is prepared.
* Fixed: addresses stored without a scheme, in the "//host/wp-content/..." form, matched none of the replacement pairs and survived a restore still pointing at the site the backup came from. They are now rewritten with the rest.

= 1.2.14 =
* Fixed the PRO promo on the settings screen quoting a price in PLN. PRO is priced and charged in EUR, so an admin on a Polish site was shown a zloty amount and then billed in euro, and the zloty figure was a fixed conversion that drifted from the real charge as the rate moved. The promo now shows the euro price that is actually taken.

= 1.2.13 =
* Fixed: the PRO pricing line claimed a PLN price would appear during purchase. Purchases settle in EUR, so the line now says the price is billed in EUR.

= 1.2.12 =
* Fixed: ticking a database view under "Exclude specific database tables" now leaves it out of the backup. The list offers views alongside tables, but only tables were being skipped, so a ticked view still ended up in the archive.

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
