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
            'en' => ['title' => 'Scheduled and incremental backups', 'desc' => 'Daily or weekly backups with retention; incremental mode stores only changed files on a full baseline.'],
            'pl' => ['title' => 'Harmonogram i kopie przyrostowe', 'desc' => 'Codzienne lub tygodniowe kopie z retencją; tryb przyrostowy zapisuje tylko zmienione pliki na pełnej bazie.'],
        ],
        [
            'en' => ['title' => 'Recovery points', 'desc' => 'A list of known-good backups with one-click rollback. A point is captured automatically after every successful backup or on demand, and remembers the site URL and database prefix.'],
            'pl' => ['title' => 'Punkty przywracania', 'desc' => 'Lista sprawdzonych kopii z rollbackiem jednym kliknięciem. Punkt tworzy się automatycznie po każdej udanej kopii lub na żądanie i zapamiętuje adres oraz prefiks bazy.'],
        ],
        [
            'en' => ['title' => 'Email notifications', 'desc' => 'Get an email when a scheduled backup completes or fails, so a silent failure never slips by.'],
            'pl' => ['title' => 'Powiadomienia e-mail', 'desc' => 'E-mail po zakończeniu lub nieudanej kopii zaplanowanej, żeby cicha awaria nie umknęła.'],
        ],
        [
            'en' => ['title' => 'Activity log', 'desc' => 'A newest-first record of backups, restores, off-site copies and recovery points.'],
            'pl' => ['title' => 'Dziennik aktywności', 'desc' => 'Zapis kopii, przywracań, kopii poza witryną i punktów przywracania, od najnowszych.'],
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
            'en' => ['title' => 'Encrypted backups', 'desc' => 'Password-protected backups; the archive is decrypted on the fly when you restore.'],
            'pl' => ['title' => 'Szyfrowane kopie', 'desc' => 'Kopie chronione hasłem; przy przywracaniu archiwum jest deszyfrowane w locie.'],
        ],
        [
            'en' => ['title' => 'Full multisite', 'desc' => 'Back up and migrate a whole multisite network, with correct URL rewriting.'],
            'pl' => ['title' => 'Pełny multisite', 'desc' => 'Kopia i migracja całej sieci multisite, z poprawnym przepisaniem adresów.'],
        ],
        [
            'en' => ['title' => 'Deploy to a new server', 'desc' => 'A standalone installer downloads WordPress core, extracts the files, imports the database, rewrites URLs and writes wp-config.php.'],
            'pl' => ['title' => 'Wdrożenie na pusty serwer', 'desc' => 'Samodzielny instalator pobiera rdzeń WordPressa, rozpakowuje pliki, importuje bazę, przepisuje adresy i zapisuje wp-config.php.'],
        ],
    ],
];
