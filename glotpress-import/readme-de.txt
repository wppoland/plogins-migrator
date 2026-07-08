=== Plogins Migrator - Migration and Backup for WooCommerce ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, clone and migrate your whole site to one file, then restore it here or on a new host. URLs and paths are fixed for you. Runs on your server.

== Description ==

Migrator packs your database and everything in `wp-content` into a single file you can download, keep as a backup, and restore, on the same site or on a brand-new install somewhere else. When you restore onto a different address, Migrator rewrites the old URLs and file paths to the new ones for you, so the site just works.

Everything happens on your own server. There is no account to create, no file size sold back to you, and nothing is ever sent to a third-party service. Because it is fully open, you can read exactly what it does: the source lives at https://github.com/wppoland/plogins-migrator, which is also where to file a bug or request a feature.

<strong>How it works</strong>

1. On the site you want to copy, create a backup. Migrator writes your database to a portable SQL dump and streams every file in `wp-content` into one archive next to it.
2. Download that archive (or, on a big site, build it from the command line, see below).
3. On the destination, the same site to roll back, or a fresh WordPress install to move to, restore the archive. Migrator imports the database, puts the files back, and rewrites the source site's web address and paths to this one.

The address rewrite is <strong>safe for serialized data</strong>: Migrator walks the actual data structures rather than doing a blind text replace, so the byte-length counts PHP stores inside serialized options and meta stay correct and nothing breaks.

<strong>A few things worth knowing</strong>

Backups are written to a protected folder (`wp-content/migrator-backups`) that denies direct web access, and the in-browser download is served only to logged-in administrators through an authenticated handler, the files are never exposed at a guessable URL. Each item inside an archive carries a checksum, so a truncated or corrupted backup is caught before it is ever restored over a live site.

Restoring <strong>overwrites</strong> the destination database and files, that is the point of a restore, so it asks for confirmation and is limited to administrators. Migrator never overwrites its own plugin folder during a restore, so it cannot pull the rug out from under itself mid-import.

For large sites where a browser request would time out, every job also runs from WP-CLI, which has no timeout:

`wp migrator export`
`wp migrator import path/to/backup.migrator`

<strong>What's included</strong>

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
3. Open <strong>Migrator</strong> in the admin menu to create a backup, or use `wp migrator export` from the command line.

== Frequently Asked Questions ==

= Documentation and links =

* <strong>Documentation</strong> - https://plogins.com/de/plogins-migrator/docs/
* <strong>Plugin page</strong> - https://plogins.com/de/plogins-migrator/
* <strong>Source code</strong> - https://github.com/wppoland/plogins-migrator
* <strong>Bug reports and feature requests</strong> - https://github.com/wppoland/plogins-migrator/issues


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

Ja. Dieses Plugin ist mit WordPress Multisite kompatibel. Aktiviere es im Netzwerk oder auf einzelnen Websites. Jede Site behält ihre eigenen Einstellungen und Daten.

== Screenshots ==

1. Der Migrator-Bildschirm: Erstelle neben der Wiederherstellung und deinen gespeicherten Backups ein Backup mit Voreinstellungen und Ausschlussoptionen.
2. Der Dateigrößen-Explorer: Scanne den wp-Inhalt und sehen Sie sich die Größe jedes Ordners an, damit du weglassen können, was du nicht benötigen.

== Changelog ==

= 1.0.1 =
* Erste stabile Version.

= 0.3.3 =
* Für einen eindeutigeren Plugin-Namen in Plogins Migrator für WooCommerce umbenannt.

= 0.3.2 =
* Aufgeräumtere gespeicherte Backup-Zeilen: Das Datum und die Größe stehen im Vordergrund, der lange Dateiname ist eine gedämpfte einzelne Zeile, die nicht mehr umbrochen wird, und die Zeile fließt auf schmalen Bildschirmen sauber um.

= 0.3.1 =
* deine gespeicherten Backups werden jetzt auf der Karte „Backup wiederherstellen“ angezeigt, sodass du eines direkt wiederherstellen können, ohne darüber scrollen zu müssen.

= 0.3.0 =
* Neuer Abschnitt „deine Backups“: Jedes auf der Website gespeicherte Backup wird mit Datum und Größe aufgelistet, sodass du es mit einem Klick herunterladen, wiederherstellen oder löschen können, ohne den Bildschirm zu verlassen.
* Direkte Wiederherstellung von einem gespeicherten Backup (gzip-Backups werden automatisch entpackt). Die Sicherungsdatei wird aufbewahrt und nicht verbraucht.
* Dem Backup-Bildschirm wurden Ein-Klick-Voreinstellungen hinzugefügt: „Vollständige Site“, „Nur Datenbank“ und „Nur Medien“ legen die richtigen Ausschlüsse für dich fest.

= 0.2.0 =
* Dem Backup-Bildschirm wurde ein Dateigrößen-Explorer hinzugefügt: Scanne den wp-Inhalt, sehen Sie sich die Größe und Dateianzahl jedes Ordners an und markiere Ordner oder große Dateien, die aus dem Backup ausgeschlossen werden sollen. Baut auf den vorhandenen Pfadausschlüssen auf.

= 0.1.0 =
* Erste Veröffentlichung.
* Sicherung der Datenbank (Tabellen, Ansichten, Trigger und gespeicherte Routinen) und des gesamten „wp-Inhalts“ in einer Datei.
* Wiederherstellen auf derselben Site oder Migration auf einen neuen Host mit serialisierungssicherer URL- und Pfadumschreibung.
* Selektives Backup: Lass Medien, Themes, Plugins, Cache, Spam-Kommentare, Post-Revisionen, Transienten, WooCommerce-Sitzungen oder Action-Scheduler-Tabellen weg.
* Fortsetzbarer Export im Browser mit Fortschrittsbalken und direktem Download sowie Wiederherstellung per Drag-and-Drop.
* WP-CLI „Export“ und „Import“ für Websites, die zu groß für den Browser sind.
* Sicherheit geht vor: ein Datenbank-Snapshot vor dem Import mit automatischem Rollback, wenn eine Wiederherstellung fehlschlägt, Prüfsummen pro Element und einer Importverweigerung bei einem nicht übereinstimmenden Tabellenpräfix.
