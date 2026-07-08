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

* <strong>Documentation</strong> - https://plogins.com/pl/plogins-migrator/docs/
* <strong>Plugin page</strong> - https://plogins.com/pl/plogins-migrator/
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

Tak. Ta wtyczka jest kompatybilna z WordPress Multisite. Aktywuj go w sieci lub aktywuj na poszczególnych stronach; każda witryna przechowuje własne ustawienia i dane.

== Screenshots ==

1. Ekran Migrator: utwórz kopię zapasową z ustawieniami wstępnymi i opcjami wykluczeń, a także przywróć i zapisane kopie zapasowe.
2. Eksplorator rozmiaru pliku: przeskanuj zawartość wp i sprawdź rozmiar każdego folderu, aby pominąć to, czego nie potrzebujesz.

== Changelog ==

= 1.0.1 =
* Pierwsza stabilna wersja.

= 0.3.3 =
* Zmieniono nazwę na Plogins Migrator dla WooCommerce, aby uzyskać bardziej charakterystyczną nazwę wtyczki.

= 0.3.2 =
* Bardziej uporządkowane wiersze zapisanej kopii zapasowej: data i rozmiar głównej, długa nazwa pliku to wyciszona pojedyncza linia, która nie jest już zawijana, a wiersz jest ładnie rozmieszczany na wąskich ekranach.

= 0.3.1 =
* Twoje zapisane kopie zapasowe pojawiają się teraz na karcie „Przywróć kopię zapasową”, dzięki czemu możesz przywrócić kopię na miejscu bez przewijania obok niej.

= 0.3.0 =
* Nowa sekcja „Twoje kopie zapasowe”: każda kopia zapasowa przechowywana w witrynie jest wyświetlona wraz z datą i rozmiarem, dzięki czemu możesz ją pobrać, przywrócić lub usunąć jednym kliknięciem, bez opuszczania ekranu.
* Przywróć bezpośrednio z zapisanej kopii zapasowej (kopie zapasowe gzip są rozpakowywane automatycznie). Plik kopii zapasowej jest przechowywany, a nie zużywany.
* Dodano ustawienia wstępne do ekranu kopii zapasowej jednym kliknięciem: Pełna witryna, Tylko baza danych i Tylko multimedia ustawiają dla Ciebie odpowiednie wyjątki.

= 0.2.0 =
* Do ekranu kopii zapasowej dodano eksplorator rozmiaru pliku: przeskanuj zawartość wp, sprawdź rozmiar każdego folderu i liczbę plików oraz zaznacz foldery lub duże pliki, które chcesz pominąć w kopii zapasowej. Opiera się na istniejących wykluczeniach ścieżek.

= 0.1.0 =
* Pierwsze wydanie.
* Jednoplikowa kopia zapasowa bazy danych (tabele, widoki, wyzwalacze i procedury składowane) oraz całej zawartości `wp`.
* Przywróć do tej samej witryny lub migruj na nowy host z bezpiecznym dla serializacji adresem URL i przepisywaniem ścieżki.
* Selektywna kopia zapasowa: pomiń multimedia, motywy, wtyczki, pamięć podręczną, komentarze spamowe, poprawki postów, stany przejściowe, sesje WooCommerce lub tabele Harmonogramu akcji.
* Możliwość wznawiania eksportu w przeglądarce z paskiem postępu i bezpośrednim pobieraniem oraz przywracaniem metodą „przeciągnij i upuść”.
* „Eksport” i „import” WP-CLI dla witryn zbyt dużych dla przeglądarki.
* Bezpieczeństwo przede wszystkim: migawka bazy danych przed importem z automatycznym przywracaniem danych w przypadku niepowodzenia przywracania, sumami kontrolnymi poszczególnych elementów i odmową importu w przypadku niedopasowanego przedrostka tabeli.
