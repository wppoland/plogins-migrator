<?php
/**
 * PRO upsell content, generated from the plogins.com registry by
 * scripts/gen-pro-upsell.mjs. The admin upsell renders this; curate the
 * feature list to fit this plugin's settings screen (do not invent features).
 *
 * @package plogins-migrator-pro
 */

defined('ABSPATH') || exit;

return [
    'name'       => 'Migrator Pro',
    'url'        => 'https://plogins.com/plogins-migrator-pro/pricing/',
    'sellable'   => true,
    'price_from' => 49,
    'currency'   => 'EUR',
    'price_pln'  => 215,
    'lead'       => [
        'en' => 'Everything you need to make backups run themselves and migrations safe. Every feature ships in the current release.',
        'pl' => 'Wszystko, czego potrzeba, żeby kopie działały same, a przenosiny były bezpieczne. Każda funkcja jest w bieżącym wydaniu.',
    ],
    'features'   => [
        [
            'en' => ['title' => 'Scheduled and incremental backups', 'desc' => 'Daily or weekly backups with retention. Incremental mode stores only the files that changed, and every archive still carries the full database.'],
            'pl' => ['title' => 'Harmonogram i kopie przyrostowe', 'desc' => 'Codzienne lub tygodniowe kopie z retencją. Tryb przyrostowy zapisuje tylko zmienione pliki, a baza danych trafia do archiwum w całości za każdym razem.'],
        ],
        [
            'en' => ['title' => 'Recovery points', 'desc' => 'A list of known-good backups with one-click rollback. A point is captured automatically after every successful backup or on demand, and remembers the site URL and database prefix.'],
            'pl' => ['title' => 'Punkty przywracania', 'desc' => 'Lista sprawdzonych kopii z rollbackiem jednym kliknięciem. Punkt tworzy się automatycznie po każdej udanej kopii lub na żądanie i zapamiętuje adres oraz prefiks bazy.'],
        ],
        [
            'en' => ['title' => 'Cloud and off-site copies', 'desc' => 'S3-compatible storage with presets (S3, R2, Backblaze B2, Wasabi, DigitalOcean Spaces), FTP/FTPS, SFTP, WebDAV (Nextcloud, ownCloud), Dropbox, Google Drive, and a local or mounted folder.'],
            'pl' => ['title' => 'Kopie w chmurze i poza witryną', 'desc' => 'Storage zgodny z S3 z presetami (S3, R2, Backblaze B2, Wasabi, DigitalOcean Spaces), FTP/FTPS, SFTP, WebDAV (Nextcloud, ownCloud), Dropbox, Google Drive oraz folder lokalny lub zamontowany.'],
        ],
        [
            'en' => ['title' => 'Server-to-server transfer', 'desc' => 'Pull a site from one server to another with no manual download.'],
            'pl' => ['title' => 'Transfer serwer-serwer', 'desc' => 'Przeciągnij witrynę z jednego serwera na drugi bez ręcznego pobierania pliku.'],
        ],
        [
            'en' => ['title' => 'Deploy to a new server', 'desc' => 'A standalone installer downloads WordPress core, extracts the files, imports the database, rewrites URLs and writes wp-config.php. It can also fetch the backup itself from a link, including a presigned S3, R2 or Dropbox URL.'],
            'pl' => ['title' => 'Wdrożenie na pusty serwer', 'desc' => 'Samodzielny instalator pobiera rdzeń WordPressa, rozpakowuje pliki, importuje bazę, przepisuje adresy i zapisuje wp-config.php. Kopię może też pobrać sam z linku, w tym z podpisanego adresu S3, R2 lub Dropbox.'],
        ],
        [
            'en' => ['title' => 'Table sync', 'desc' => 'Pick database tables from a backup and import only those into the live site. Push content from staging without rolling back the orders, customers and comments that arrived in the meantime. Preview what would change first, and the chosen tables are dumped before anything is written.'],
            'pl' => ['title' => 'Synchronizacja tabel', 'desc' => 'Wybierz tabele bazy danych z kopii zapasowej i wgraj do działającej witryny tylko je. Przenieś treści ze środowiska testowego bez cofania zamówień, klientów i komentarzy, które pojawiły się w międzyczasie. Najpierw zobaczysz podgląd zmian, a wybrane tabele są zrzucane, zanim cokolwiek zostanie zapisane.'],
        ],
    ],
];
