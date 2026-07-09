=== Plogins Migrator - Site Migration and Backup ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Twórz kopie zapasowe, klonuj i migruj całą witrynę do jednego pliku, a następnie przywróć ją tutaj lub na nowym serwerze. Adresy URL i ścieżki poprawiamy za Ciebie. Działa na Twoim serwerze.

== Description ==

Migrator pakuje Twoją bazę danych i całą zawartość `wp-content` do jednego pliku, który możesz pobrać, zachować jako kopię zapasową i przywrócić — na tej samej witrynie albo na zupełnie nowej instalacji w innym miejscu. Gdy przywracasz pod innym adresem, Migrator przepisuje za Ciebie stare adresy URL i ścieżki plików na nowe, dzięki czemu witryna po prostu działa.

Wszystko dzieje się na Twoim własnym serwerze. Nie musisz zakładać konta, nikt nie sprzedaje Ci z powrotem miejsca na pliki i nic nigdy nie jest wysyłane do usługi zewnętrznej. Ponieważ projekt jest w pełni otwarty, możesz dokładnie sprawdzić, co robi: kod źródłowy znajduje się na https://github.com/wppoland/plogins-migrator, gdzie zgłosisz też błąd lub poprosisz o nową funkcję.

<strong>Jak to działa</strong>

1. Na witrynie, którą chcesz skopiować, utwórz kopię zapasową. Migrator zapisuje Twoją bazę danych do przenośnego zrzutu SQL i strumieniowo pakuje każdy plik z `wp-content` do jednego archiwum obok niego.
2. Pobierz to archiwum (albo, na dużej witrynie, zbuduj je z wiersza poleceń — patrz niżej).
3. W miejscu docelowym — tej samej witrynie do przywrócenia albo świeżej instalacji WordPressa, na którą się przenosisz — przywróć archiwum. Migrator importuje bazę danych, odkłada pliki na miejsce i przepisuje adres oraz ścieżki witryny źródłowej na te z bieżącej.

Przepisywanie adresu jest <strong>bezpieczne dla danych serializowanych</strong>: Migrator przechodzi po rzeczywistych strukturach danych, zamiast wykonywać ślepe zastępowanie tekstu, dzięki czemu liczniki długości w bajtach, które PHP przechowuje wewnątrz serializowanych opcji i metadanych, pozostają poprawne i nic się nie psuje.

<strong>Kilka rzeczy, które warto wiedzieć</strong>

Kopie zapasowe są zapisywane do chronionego folderu (`wp-content/migrator-backups`), który blokuje bezpośredni dostęp z sieci, a pobieranie w przeglądarce jest udostępniane wyłącznie zalogowanym administratorom przez uwierzytelniony mechanizm — pliki nigdy nie są dostępne pod możliwym do odgadnięcia adresem URL. Każdy element wewnątrz archiwum ma sumę kontrolną, więc uszkodzona lub obcięta kopia zapasowa zostanie wykryta, zanim kiedykolwiek zostanie przywrócona na działającą witrynę.

Przywracanie <strong>nadpisuje</strong> bazę danych i pliki w miejscu docelowym — o to właśnie chodzi w przywracaniu — dlatego prosi o potwierdzenie i jest ograniczone do administratorów. Migrator nigdy nie nadpisuje własnego folderu wtyczki podczas przywracania, więc nie może w trakcie importu wyciągnąć sobie gruntu spod nóg.

W przypadku dużych witryn, gdzie żądanie przeglądarki przekroczyłoby limit czasu, każde zadanie działa również z WP-CLI, które nie ma limitu czasu:

`wp migrator export`
`wp migrator import path/to/backup.migrator`

<strong>Co zawiera</strong>

* Kopia zapasowa bazy danych i całej zawartości `wp-content` do jednego archiwum jednym kliknięciem
* Przywracanie na tej samej witrynie lub migracja na nowy serwer z automatycznym, bezpiecznym dla serializacji przepisywaniem adresów URL i ścieżek
* Wybór tego, co pominąć: multimedia, motywy, wtyczki, pamięć podręczną, komentarze spamowe, wersje wpisów, transienty, sesje WooCommerce lub tabele Action Scheduler
* Eksport w przeglądarce z paskiem postępu i bezpośrednim pobieraniem, wznawialny, dzięki czemu duże witryny kończą go w kilku krokach, oraz przywracanie metodą „przeciągnij i upuść”
* Polecenia WP-CLI `export` i `import` dla witryn zbyt dużych dla przeglądarki
* Migawka bezpieczeństwa bazy danych przed każdym przywracaniem, wycofywana automatycznie, jeśli coś się nie powiedzie
* Sumy kontrolne każdego elementu, dzięki czemu uszkodzone archiwum zostaje wykryte, a nie przywrócone
* Hostowane u Ciebie: bez konta, bez usługi zewnętrznej, nic nie opuszcza Twojego serwera

== Installation ==

1. Prześlij wtyczkę do `/wp-content/plugins/migrator` lub zainstaluj ją z Wtyczki → Dodaj nową.
2. Włącz ją. Nie ma żadnych wymaganych zależności.
3. Otwórz <strong>Migrator</strong> w menu administracyjnym, aby utworzyć kopię zapasową, albo użyj `wp migrator export` z wiersza poleceń.

== Frequently Asked Questions ==

= Documentation and links =

* <strong>Dokumentacja</strong> - https://plogins.com/pl/plogins-migrator/docs/
* <strong>Strona wtyczki</strong> - https://plogins.com/pl/plogins-migrator/
* <strong>Kod źródłowy</strong> - https://github.com/wppoland/plogins-migrator
* <strong>Zgłoszenia błędów i propozycje funkcji</strong> - https://github.com/wppoland/plogins-migrator/issues


= Does restoring delete what is already on the destination? =

Tak. Przywracanie zastępuje bazę danych i pliki w miejscu docelowym zawartością archiwum — to właśnie oznacza przywrócenie kopii zapasowej. Jest ograniczone do administratorów i najpierw prosi o potwierdzenie. Zawsze zachowuj osobną kopię zapasową wszystkiego, co chcesz zachować w miejscu docelowym.

= Will my links break when I move to a new domain? =

Nie. Gdy przywracasz pod innym adresem, Migrator przepisuje stary adres URL witryny i ścieżki plików na nowe, również wewnątrz danych serializowanych, dzięki czemu linki wewnętrzne i ustawienia nadal działają.

= My site is large and the browser export stops. What do I do? =

Użyj WP-CLI, które nie ma limitu czasu żądania: `wp migrator export`, aby zbudować archiwum, i `wp migrator import <file>`, aby je przywrócić.

= Does it send my data anywhere? =

Nie. Migrator działa w całości na Twoim własnym serwerze. Nie zakłada konta i nie łączy się z żadną usługą zewnętrzną. Twoje kopie zapasowe pozostają w `wp-content/migrator-backups`, dopóki ich nie pobierzesz lub nie usuniesz.

= Where are my backups stored? =

W `wp-content/migrator-backups` — folderze chronionym przed bezpośrednim dostępem z sieci. Usunięcie wtyczki kasuje ten folder wraz z zawartością.


= Does this plugin work on WordPress Multisite? =

Tak. Ta wtyczka jest zgodna z WordPress Multisite. Włącz ją dla całej sieci lub w pojedynczych witrynach; każda witryna zachowuje własne ustawienia i dane.

== Screenshots ==

1. Ekran Migrator: utwórz kopię zapasową z gotowymi ustawieniami i opcjami wykluczeń, obok przywracania i Twoich zapisanych kopii zapasowych.
2. Eksplorator rozmiaru plików: przeskanuj `wp-content` i zobacz rozmiar każdego folderu, aby pominąć to, czego nie potrzebujesz.

== Translations ==

Plogins Migrator zawiera polskie, niemieckie i hiszpańskie tłumaczenie interfejsu wtyczki. Domena tekstowa to `plogins-migrator`, dzięki czemu paczki językowe z WordPress.org mogą również nadpisywać lub rozszerzać dołączone tłumaczenia.

== Changelog ==

= 1.0.2 =
* Dodano dołączone polskie, niemieckie i hiszpańskie tłumaczenia interfejsu wtyczki.

= 1.0.1 =
* Pierwsza stabilna wersja.

= 0.3.3 =
* Zmieniono nazwę na Plogins Migrator for WooCommerce, aby uzyskać bardziej charakterystyczną nazwę wtyczki.

= 0.3.2 =
* Bardziej uporządkowane wiersze zapisanych kopii zapasowych: na pierwszym planie data i rozmiar, długa nazwa pliku to wyciszona pojedyncza linia, która nie zawija się już do kolejnych wierszy, a wiersz ładnie układa się na wąskich ekranach.

= 0.3.1 =
* Twoje zapisane kopie zapasowe pojawiają się teraz w karcie „Przywróć kopię zapasową”, dzięki czemu możesz przywrócić kopię na miejscu, bez przewijania obok niej.

= 0.3.0 =
* Nowa sekcja „Twoje kopie zapasowe”: każda kopia zapasowa przechowywana w witrynie jest wypisana wraz z datą i rozmiarem, dzięki czemu możesz ją pobrać, przywrócić lub usunąć jednym kliknięciem, bez opuszczania ekranu.
* Przywracanie bezpośrednio z zapisanej kopii zapasowej (kopie w formacie gzip są rozpakowywane automatycznie). Plik kopii zapasowej jest zachowywany, a nie zużywany.
* Dodano do ekranu kopii zapasowej gotowe ustawienia dostępne jednym kliknięciem: Pełna witryna, Tylko baza danych i Tylko multimedia ustawiają za Ciebie właściwe wykluczenia.

= 0.2.0 =
* Do ekranu kopii zapasowej dodano eksplorator rozmiaru plików: przeskanuj `wp-content`, zobacz rozmiar i liczbę plików w każdym folderze oraz zaznacz foldery lub duże pliki do pominięcia w kopii. Rozwija istniejące wykluczenia ścieżek.

= 0.1.0 =
* Pierwsze wydanie.
* Kopia zapasowa w jednym pliku obejmująca bazę danych (tabele, widoki, wyzwalacze i procedury składowane) oraz całą zawartość `wp-content`.
* Przywracanie na tej samej witrynie lub migracja na nowy serwer z bezpiecznym dla serializacji przepisywaniem adresów URL i ścieżek.
* Selektywna kopia zapasowa: pomiń multimedia, motywy, wtyczki, pamięć podręczną, komentarze spamowe, wersje wpisów, transienty, sesje WooCommerce lub tabele Action Scheduler.
* Wznawialny eksport w przeglądarce z paskiem postępu i bezpośrednim pobieraniem oraz przywracanie metodą „przeciągnij i upuść”.
* Polecenia WP-CLI `export` i `import` dla witryn zbyt dużych dla przeglądarki.
* Bezpieczeństwo przede wszystkim: migawka bazy danych przed importem z automatycznym wycofaniem, jeśli przywracanie się nie powiedzie, sumy kontrolne każdego elementu i odmowa importu przy niedopasowanym przedrostku tabel.
