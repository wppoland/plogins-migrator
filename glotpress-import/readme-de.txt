=== Plogins Migrator - Site Migration and Backup ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sichere, klone und migriere deine gesamte Website in eine Datei und stelle sie hier oder auf einem neuen Host wieder her. URLs und Pfade werden für dich angepasst. Läuft auf deinem Server.

== Description ==

Migrator packt deine Datenbank und alles in `wp-content` in eine einzelne Datei, die du herunterladen, als Backup aufbewahren und wiederherstellen kannst – auf derselben Website oder auf einer brandneuen Installation woanders. Wenn du unter einer anderen Adresse wiederherstellst, schreibt Migrator die alten URLs und Dateipfade für dich auf die neuen um, sodass die Website einfach funktioniert.

Alles passiert auf deinem eigenen Server. Es gibt kein Konto zum Anlegen, keine Dateigröße, die dir zurückverkauft wird, und nichts wird jemals an einen Drittanbieterdienst gesendet. Weil das Projekt vollständig offen ist, kannst du genau nachlesen, was es tut: Der Quellcode liegt unter https://github.com/wppoland/plogins-migrator, wo du auch Fehler melden oder Funktionen vorschlagen kannst.

<strong>So funktioniert es</strong>

1. Erstelle auf der Website, die du kopieren möchtest, ein Backup. Migrator schreibt deine Datenbank in einen portablen SQL-Dump und streamt jede Datei in `wp-content` in ein Archiv daneben.
2. Lade dieses Archiv herunter (oder erstelle es bei einer großen Website über die Befehlszeile – siehe unten).
3. Am Ziel – derselben Website zum Zurücksetzen oder einer frischen WordPress-Installation, zu der du wechselst – stelle das Archiv wieder her. Migrator importiert die Datenbank, legt die Dateien zurück und schreibt die Webadresse und Pfade der Quellwebsite auf die hier um.

Das Umschreiben der Adresse ist <strong>sicher für serialisierte Daten</strong>: Migrator durchläuft die tatsächlichen Datenstrukturen, statt blind Text zu ersetzen, sodass die Byte-Längen, die PHP in serialisierten Optionen und Meta speichert, korrekt bleiben und nichts kaputtgeht.

<strong>Ein paar Dinge, die du wissen solltest</strong>

Backups werden in einen geschützten Ordner (`wp-content/migrator-backups`) geschrieben, der direkten Webzugriff verweigert, und der Download im Browser wird nur angemeldeten Administratoren über einen authentifizierten Handler bereitgestellt – die Dateien sind nie unter einer erratbaren URL erreichbar. Jedes Element in einem Archiv trägt eine Prüfsumme, sodass ein abgeschnittenes oder beschädigtes Backup erkannt wird, bevor es jemals über eine Live-Website wiederhergestellt wird.

Das Wiederherstellen <strong>überschreibt</strong> die Ziel-Datenbank und -Dateien – genau darum geht es beim Wiederherstellen –, deshalb fragt es nach Bestätigung und ist auf Administratoren beschränkt. Migrator überschreibt während einer Wiederherstellung nie seinen eigenen Plugin-Ordner, sodass es sich nicht mitten im Import den Boden unter den Füßen wegziehen kann.

Bei großen Websites, bei denen eine Browser-Anfrage in ein Timeout laufen würde, läuft jeder Job auch über WP-CLI, das kein Timeout hat:

`wp migrator export`
`wp migrator import path/to/backup.migrator`

<strong>Was enthalten ist</strong>

* Ein-Klick-Backup deiner Datenbank und des gesamten `wp-content` in ein einzelnes Archiv
* Wiederherstellung auf derselben Website oder Migration auf einen neuen Host mit automatischem, serialisierungssicherem Umschreiben von URLs und Pfaden
* Auswahl, was ausgelassen wird: Medien, Themes, Plugins, Cache, Spam-Kommentare, Beitragsrevisionen, Transients, WooCommerce-Sitzungen oder Action-Scheduler-Tabellen
* Export im Browser mit Fortschrittsbalken und direktem Download, fortsetzbar, sodass große Websites in mehreren Schritten fertig werden, plus Wiederherstellung per Drag-and-drop
* WP-CLI-Befehle `export` und `import` für Websites, die für den Browser zu groß sind
* Ein Sicherheits-Snapshot deiner Datenbank vor jeder Wiederherstellung, der bei einem Fehler automatisch zurückgerollt wird
* Prüfsummen pro Element, sodass ein beschädigtes Archiv erkannt, aber nicht wiederhergestellt wird
* Self-hosted: kein Konto, kein Drittanbieterdienst, nichts verlässt deinen Server

== Installation ==

1. Lade das Plugin nach `/wp-content/plugins/migrator` hoch oder installiere es über Plugins → Installieren.
2. Aktiviere es. Es gibt keine erforderlichen Abhängigkeiten.
3. Öffne <strong>Migrator</strong> im Admin-Menü, um ein Backup zu erstellen, oder verwende `wp migrator export` über die Befehlszeile.

== Frequently Asked Questions ==

= Documentation and links =

* <strong>Dokumentation</strong> - https://plogins.com/de/plogins-migrator/docs/
* <strong>Plugin-Seite</strong> - https://plogins.com/de/plogins-migrator/
* <strong>Quellcode</strong> - https://github.com/wppoland/plogins-migrator
* <strong>Fehlerberichte und Funktionswünsche</strong> - https://github.com/wppoland/plogins-migrator/issues


= Does restoring delete what is already on the destination? =

Ja. Eine Wiederherstellung ersetzt die Datenbank und Dateien am Ziel durch den Inhalt des Archivs – genau das bedeutet das Wiederherstellen eines Backups. Sie ist auf Administratoren beschränkt und fragt zuerst nach Bestätigung. Bewahre immer ein separates Backup von allem auf, was du am Ziel behalten möchtest.

= Will my links break when I move to a new domain? =

Nein. Wenn du unter einer anderen Adresse wiederherstellst, schreibt Migrator die alte Website-URL und die Dateipfade auf die neuen um, auch innerhalb serialisierter Daten, sodass interne Links und Einstellungen weiter funktionieren.

= My site is large and the browser export stops. What do I do? =

Verwende WP-CLI, das kein Anfragezeitlimit hat: `wp migrator export`, um das Archiv zu erstellen, und `wp migrator import <file>`, um es wiederherzustellen.

= Does it send my data anywhere? =

Nein. Migrator läuft vollständig auf deinem eigenen Server. Es legt kein Konto an und kontaktiert keinen externen Dienst. Deine Backups bleiben in `wp-content/migrator-backups`, bis du sie herunterlädst oder löschst.

= Where are my backups stored? =

In `wp-content/migrator-backups`, einem Ordner, der vor direktem Webzugriff geschützt ist. Entfernst du das Plugin, wird dieser Ordner samt Inhalt gelöscht.


= Does this plugin work on WordPress Multisite? =

Ja. Dieses Plugin ist mit WordPress Multisite kompatibel. Aktiviere es netzwerkweit oder auf einzelnen Websites; jede Website behält ihre eigenen Einstellungen und Daten.

== Screenshots ==

1. Der Migrator-Bildschirm: Erstelle ein Backup mit Voreinstellungen und Ausschlussoptionen, neben Wiederherstellung und deinen gespeicherten Backups.
2. Der Dateigrößen-Explorer: Scanne `wp-content` und sieh dir die Größe jedes Ordners an, damit du weglassen kannst, was du nicht brauchst.

== Translations ==

Plogins Migrator enthält polnische, deutsche und spanische Übersetzungen für die Plugin-Oberfläche. Die Textdomain ist `plogins-migrator`, sodass Sprachpakete von WordPress.org diese mitgelieferten Übersetzungen ebenfalls überschreiben oder erweitern können.

== Changelog ==

= 1.0.2 =
* Mitgelieferte polnische, deutsche und spanische Übersetzungen für die Plugin-Oberfläche hinzugefügt.

= 1.0.1 =
* Erste stabile Version.

= 0.3.3 =
* Umbenannt in Plogins Migrator for WooCommerce für einen unverwechselbaren Plugin-Namen.

= 0.3.2 =
* Aufgeräumtere gespeicherte Backup-Zeilen: Datum und Größe stehen vorn, der lange Dateiname ist eine gedämpfte Einzelzeile, die nicht mehr umbricht, und die Zeile fließt auf schmalen Bildschirmen sauber um.

= 0.3.1 =
* Deine gespeicherten Backups erscheinen jetzt in der Karte „Backup wiederherstellen“, sodass du eines direkt wiederherstellen kannst, ohne darüber hinaus scrollen zu müssen.

= 0.3.0 =
* Neuer Abschnitt „Deine Backups“: Jedes auf der Website gespeicherte Backup wird mit Datum und Größe aufgelistet, sodass du es mit einem Klick herunterladen, wiederherstellen oder löschen kannst, ohne den Bildschirm zu verlassen.
* Direkte Wiederherstellung aus einem gespeicherten Backup (gzip-Backups werden automatisch entpackt). Die Backup-Datei bleibt erhalten und wird nicht verbraucht.
* Ein-Klick-Voreinstellungen auf dem Backup-Bildschirm hinzugefügt: Vollständige Website, Nur Datenbank und Nur Medien setzen die richtigen Ausschlüsse für dich.

= 0.2.0 =
* Dateigrößen-Explorer auf dem Backup-Bildschirm hinzugefügt: Scanne `wp-content`, sieh dir die Größe und Dateianzahl jedes Ordners an und markiere Ordner oder große Dateien, die aus dem Backup ausgelassen werden sollen. Baut auf den vorhandenen Pfadausschlüssen auf.

= 0.1.0 =
* Erste Veröffentlichung.
* Ein-Datei-Backup der Datenbank (Tabellen, Views, Trigger und gespeicherte Routinen) und des gesamten `wp-content`.
* Wiederherstellung auf derselben Website oder Migration auf einen neuen Host mit serialisierungssicherem Umschreiben von URLs und Pfaden.
* Selektives Backup: Lass Medien, Themes, Plugins, Cache, Spam-Kommentare, Beitragsrevisionen, Transients, WooCommerce-Sitzungen oder Action-Scheduler-Tabellen weg.
* Fortsetzbarer Export im Browser mit Fortschrittsbalken und direktem Download sowie Wiederherstellung per Drag-and-drop.
* WP-CLI `export` und `import` für Websites, die für den Browser zu groß sind.
* Sicherheit zuerst: Datenbank-Snapshot vor dem Import mit automatischem Rollback bei fehlgeschlagener Wiederherstellung, Prüfsummen pro Element und Verweigerung des Imports bei nicht passendem Tabellenpräfix.
