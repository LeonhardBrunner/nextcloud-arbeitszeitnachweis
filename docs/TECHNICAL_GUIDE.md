# Timesheet: Technische Anleitung / Technical guide

[App-Beschreibung / App overview](../README.md)

<details open>
<summary>Deutsch</summary>

## 1. Übersicht

Timesheet läuft innerhalb von Nextcloud. PHP verarbeitet und speichert die Daten. JavaScript steuert die Bedienung im Browser. Die App-ID und der Installationsordner heißen `timesheet`.

Dieses Projekt baut auf Version 1.2.0 von David Gun (Repa1253) auf. Die maßgebliche App-Version steht in `appinfo/info.xml`. Dort sind Nextcloud 30–33 und PHP 8.2–8.5 als vorgesehene Versionen eingetragen.

## 2. Die Ordner und Dateien

| Name | Bedeutung |
| --- | --- |
| `appinfo/` | Anmeldung der App bei Nextcloud, Versionsangaben und Zuordnung der Browseranfragen. |
| `lib/` | PHP-Code für Berechnungen, Datenbank, Einstellungen und E-Mail-Aufgaben. |
| `templates/` | Aufbau der App-Seite und der Verwaltungsseite. |
| `js/` | JavaScript für Zeiteinträge, Export, HR-Ansicht und Einstellungen. |
| `css/` und `img/` | Gestaltung und App-Symbole. |
| `l10n/` | Übersetzungen. Nextcloud erwartet diesen Ordnernamen. JSON-Dateien werden von PHP, JS-Dateien vom Browser verwendet. Die Tourtexte stehen in den angebotenen Kontosprachen bereit. `de` und `de_DE` enthalten die persönliche und die förmliche deutsche Sprachfassung. |
| `vendor/` | Installierte PHP-Bibliotheken, unter anderem für den Excelexport. Composer erzeugt diesen Ordner; er gehört zur vollständigen Installation. |
| `tools/` | Kleine Programme für die Prüfung des Codes. Die Einstellungen der PHP-Werkzeuge liegen gemeinsam in `tools/php/`. |
| `vendor-bin/` | Angaben und Abhängigkeiten der zusätzlichen PHP-Prüfwerkzeuge. |
| `tests/` | JavaScript-Verhaltenstests unter `tests/readability/` und Vorbereitung für PHP-Tests innerhalb einer Nextcloud-Testumgebung. |
| `docs/TECHNICAL_GUIDE.md` | Anleitung für Entwicklung und Veröffentlichung. |

Im Hauptordner stehen die Einstellungen für Composer und npm. `composer.json` und `composer.lock` legen die PHP-Bibliotheken fest. `package.json` enthält die JavaScript-Prüf- und Testbefehle, keine zusätzlichen npm-Pakete. `.nvmrc` nennt Node 22 als gemeinsame Ausgangsversion; der Prüfbefehl unterstützt Node ab Version 22. `README.md` beschreibt die App, `CHANGELOG.md` die Versionsgeschichte und `LICENSE` die Lizenz.

## 3. Dateien und Aufgaben

| Änderung | Einstieg |
| --- | --- |
| Sichtbare Texte und Seitenaufbau | `templates/main.php`; `lib/Controller/PageController.php` lädt die dazugehörigen Skripte und die Gestaltung. |
| Aussehen | `css/style.css` und `img/`. |
| Zeiteinträge | `js/timesheet-entries.js`, `lib/Controller/EntryController.php`, `lib/Service/EntryService.php`. |
| Stundenstände und Monatswerte | `lib/Db/EntryMapper.php`, `lib/Service/MonthlyBalanceService.php`, `lib/Controller/BalanceController.php`. |
| Monats- und Jahresexport | `js/timesheet-export.js` steuert die Auswahl; `lib/Service/TimesheetXlsxExporter.php` erstellt die Datei. `EntryController` prüft den Zugriff und liefert den Download. |
| HR-Übersicht und Zuständigkeiten | `js/timesheet-hr.js`, `lib/Controller/OverviewController.php`, `lib/Service/HrService.php`. |
| Anzeigeeinstellungen | `PreferencesController` und `lib/Db/UserPreferencesStore.php` speichern die persönliche Pausenanzeige. `PageController` liefert die gespeicherte Auswahl beim Seitenaufruf mit. |
| Einstellungen | `ConfigController` nimmt persönliche Einstellungen entgegen; `lib/Db/HrPreferencesStore.php` liest und speichert HR-E-Mail- und Warnungseinstellungen. Gruppenregeln liegen bei `SettingsController`, `templates/settings-admin.php` und `js/admin.js`. |
| E-Mails und Feiertage | `lib/BackgroundJob/HrNotificationJob.php`, `lib/Service/HrNotificationService.php`, `lib/Service/HolidayService.php`. |
| Gemeinsame Browserfunktionen | `js/timesheet-main.js`, `js/timesheet-core.js`, `js/timesheet-copy.js`. |

### Gemeinsame Schreibregeln

- Namen und Kommentare stehen auf Englisch. Namen beschreiben den Inhalt oder die Aufgabe, etwa `appState`, `monthDisplay` und `breakMinutes`.
- `.editorconfig` legt Tabs mit vier Zeichen Breite und LF-Zeilenumbrüche für PHP, JavaScript und CSS fest. `.gitattributes` erhält die Zeilenumbrüche auch beim Auschecken mit Git. Historische Datenbankmigrationen bleiben unverändert.
- Kommentare kennzeichnen größere Abschnitte und erklären besondere Abläufe oder Einheiten. Eine Erklärung jeder einzelnen Zeile ist nicht vorgesehen.
- Die JavaScript-Dateien bleiben nach Zeiteinträgen, HR, Export, Einstellungen und Seitensteuerung getrennt. Zusammenhängende private Funktionen übernehmen das Lesen einer Zeile, die Vorschau und das Speichern. Laufende Speichervorgänge und ihre Warteschlange bleiben an einer Stelle.
- Bestehende Browseradressen, Datenfelder, Übersetzungsschlüssel und HTML-Kennungen bleiben bei reinen Strukturänderungen erhalten. Auch bisherige Berechnungs- und Zugriffsregeln bleiben der Vergleichsmaßstab.

Neue Verbindungen zwischen Browser und PHP werden in `appinfo/routes.php` eingetragen. Datenbankänderungen liegen unter `lib/Migration/`. Bereits veröffentlichte Änderungen bleiben dort erhalten, damit Neuinstallationen und Updates dieselben Schritte durchlaufen können.

## 4. Die App lokal ausprobieren

Eine lokale Demo ist eine getrennte Nextcloud mit Datenbank und Testkonten. Dieses Repository enthält noch keine eigene Docker-Konfiguration. Der folgende Ablauf setzt deshalb eine bereits eingerichtete Demo voraus. Siehe [Nextcloud-Entwicklerdokumentation](https://docs.nextcloud.com/server/33/developer_manual/) für weitere Informationen.

Für die Beispiele heißt der Docker-Dienst `nextcloud`. Nextcloud liegt im Container unter `/var/www/html`; PHP und Composer sind vorhanden. Der Fork ist als beschreibbarer Ordner unter `/var/www/html/custom_apps/timesheet` eingebunden. Browseradresse und Testzugang gehören zur jeweiligen Demo.

1. Im Editor ist der gewünschte Branch ausgewählt, beispielsweise `Updated_App`.
2. Die Docker-Konfiguration verweist auf den vollständigen lokalen Pfad dieses Forks. Falls die Demo `TIMESHEET_APP_PATH` unterstützt, steht der Pfad in deren `.env`. Diese Einstellung gehört zur Demo.
3. Im Ordner der Compose-Datei werden Konfiguration und Start geprüft:

   ```powershell
   docker compose config --quiet
   docker compose up -d
   ```

4. Composer installiert die für den Betrieb benötigten Bibliotheken im eingebundenen Fork:

   ```powershell
   docker compose exec -u www-data -w /var/www/html/custom_apps/timesheet nextcloud composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
   docker compose exec -u www-data -w /var/www/html/custom_apps/timesheet nextcloud composer check-platform-reqs --no-dev
   ```

   Dieser Aufruf lässt zusätzliche Entwicklerwerkzeuge aus. Ein Fehler an dieser Stelle ist ein offener Einrichtungsschritt; ein Wechsel auf `composer update` würde die Bibliotheksversionen verändern.

5. Status und Aktivierung erfolgen mit den Nextcloud-Verwaltungsbefehlen:

   ```powershell
   docker compose exec -u www-data nextcloud php /var/www/html/occ status
   docker compose exec -u www-data nextcloud php /var/www/html/occ app:enable timesheet
   ```

   Eine eingerichtete Nextcloud meldet `installed: true`. Nach einem Versionswechsel kann zusätzlich ein Datenbank-Update erforderlich sein; dafür gilt der dokumentierte Upgrade-Ablauf der Demo. Die [occ-Anleitung](https://docs.nextcloud.com/server/33/admin_manual/occ_command.html) erklärt diese Verwaltungsbefehle.

6. Die App ist unter der Browseradresse der Demo mit dem Pfad `/apps/timesheet/` erreichbar. Eine kleine sichtbare Textänderung und anschließendes Neuladen zeigen, ob die Demo tatsächlich diesen Fork verwendet.

Gespeicherte Änderungen an den eingebundenen Dateien erscheinen nach dem Neuladen; ein Upload zu GitHub ist dafür nicht erforderlich. Ein Branchwechsel verändert die Dateien, setzt aber die Datenbank nicht zurück. Für Änderungen an den derzeitigen JavaScript- und CSS-Dateien ist kein Build-Schritt erforderlich.

Feiertagsdaten benötigen ausgehenden HTTPS-Zugriff auf `get.api-feiertage.de`, sofern keine gültigen gespeicherten Daten vorhanden sind. HR-E-Mails setzen persönliche Benachrichtigungseinstellungen, eine Empfängeradresse, funktionierenden Nextcloud-Mailversand und Hintergrundaufgaben voraus. Der vorhandene Job versendet regulär montags. Test-E-Mails gehören in ein Testpostfach.

### Persönliche Pausenanzeige

Der Schalter **Pause: Minuten – Stunden** speichert seine Auswahl automatisch im angemeldeten Nextcloud-Konto. Die Auswahl bleibt nach Neuladen und erneutem Anmelden erhalten und gilt für die eigene Zeiterfassung sowie die HR-Ansicht. Beim Betrachten fremder Einträge gilt die Anzeigeeinstellung der betrachtenden Person. Gespeicherte Pausenwerte und Berechnungen bleiben unverändert.

### Geführte Tour

Das Kompasssymbol zwischen Suche und Glocke öffnet eine Einführung in Timesheet. Für Nextcloud-Administratorkonten ist die Tour derzeit ausgeschaltet; dort werden weder Symbol noch Einladung angezeigt. `js/timesheet-tour.js` enthält Ablauf und Erklärungen; `js/timesheet-tour-header.js` verbindet das Symbol mit der Nextcloud-Kopfzeile. Die Darstellung liegt in `css/timesheet-tour.css`. Fehlt die erwartete Kopfzeile, erscheint der Zugang innerhalb von Timesheet.

Die Tour wird nach dem Laden der App angeboten. **Später** verschiebt die Einladung bis zum nächsten Aufruf. Ein Abschluss wird je Konto gespeichert, getrennt für den allgemeinen Teil und die HR-Erweiterung. `TourController` nimmt den Abschluss entgegen; `lib/Db/TourProgressStore.php` speichert ausschließlich die beiden Tourstände. Neue HR-Rechte führen zur zusätzlichen HR-Einführung. Eine manuell gestartete Tour zeigt alle aktuell verfügbaren Schritte.

Vor den HR-Funktionen erklärt ein eigener Schritt die Umschaltung zwischen Arbeitszeitnachweis und HR-Übersicht. Der Kommentarschritt beschreibt bereits den vorgesehenen HR-Freigabeablauf für „Urlaub“ und „Stundenausgleich“. Die technische Genehmigungsfunktion bleibt ein eigener Featureauftrag; diese Touränderung ergänzt ausschließlich die Erklärung.

Erklärungen lesen vorhandene Ansichten und öffnen bei Bedarf Menüs. Die Tour erstellt keine Zeiteinträge, ändert keine Einstellungen und startet keine Downloads. Die vorhandenen Leseaufrufe können berechnete Monatsstände zwischenspeichern, wie beim normalen Öffnen der HR-Ansicht. Nach ihrem Ende wird die zuvor geöffnete Ansicht wiederhergestellt. **Hilfe & Datenschutz** führt zur allgemeinen Nextcloud-Hilfe.

Die Kontosprache bestimmt die Tourtexte. Änderungen an Erklärungen werden in allen zugehörigen Sprachdateien gepflegt. Die Vollständigkeitsprüfung unter `tests/tour/` kontrolliert Texte, Platzhalter und die Übereinstimmung von JSON- und JS-Dateien. Sie ersetzt keine sprachliche Prüfung durch Muttersprachler. Zusätzliche Tourprüfungen laufen mit `npm run test:tour`; der getrennte PHP-Aufruf lautet `php tests/tour/progress.php`.

### Erinnerungen für einzelne offene Tage

Die Verwaltungsseite enthält in jeder Gruppenregel den Bereich **Erinnerungen an fehlende Zeiten**. Die Funktion ist nach der Installation ausgeschaltet. Einstellbar sind die Wartefrist von 1 bis 365 Kalendertagen (Voreinstellung 14), die erwarteten Wochentage (Voreinstellung Montag bis Freitag) und der Prüfstart. Beim ersten Einschalten wird das aktuelle Datum vorgeschlagen; ein rückwirkender Beginn ist möglich.

Die tägliche Prüfung findet auch einzelne ältere Lücken, wenn danach bereits weitere Zeiten eingetragen wurden. Fehlende Start- oder Endzeiten werden ebenfalls gemeldet. Bei Tagen ohne beide Zeitangaben verhindern ausschließlich die vollständigen Kommentare **Urlaub** und **Stundenausgleich** eine Erinnerung. Groß-/Kleinschreibung und Leerzeichen am Anfang oder Ende werden ignoriert. Andere Kommentare oder Zusätze genügen nicht. Bei nur einer Zeitangabe bleibt die Erinnerung auch mit einem dieser Kommentare bestehen. Die HR-Übersicht enthält denselben Hinweis.

Feiertage des persönlichen Bundeslands bleiben ausgenommen. Ohne Bundesland oder bei nicht verfügbaren Feiertagsdaten erhält die betroffene Person zunächst keine Erinnerung; zuständiges HR bekommt einen Hinweis. Die ausgewählten Wochentage gelten auch bei einer täglichen Sollzeit von null.

Beim ersten Aktivieren werden bestehende Gruppenmitglieder mit dem gewählten Prüfstart vorgemerkt. Später hinzugekommene Personen starten frühestens am Tag ihrer ersten Erkennung. Speichern und erneutes Einschalten setzen diese Zuordnung nicht zurück. Bei mehreren passenden Gruppenregeln entscheidet wie bisher die niedrigste Prioritätszahl; auch eine dort ausgeschaltete Erinnerung bleibt maßgeblich.

Mitarbeitende erhalten nur ihre eigenen offenen Tage. HR bekommt eine getrennte Übersicht ausschließlich für aktuell zugeordnete Personen. Eine erfolgreiche E-Mail startet eine Wartefrist von sieben Tagen je Versandart; neue Lücken verkürzen diese Frist nicht. Ohne offene Tage wird keine Erinnerung verschickt. Bisherige HR-Mails bleiben zusätzlich bestehen. Die Erkennung liegt in `MissingEntryDetector`, der Ablauf in `MissingEntryReminderService`, der Mailversand in `MissingEntryReminderMailer` und die gespeicherte Zuordnung in `MissingEntryReminderStore`. Die neue tägliche Aufgabe heißt `MissingEntryReminderJob`; ihre Ausführung benötigt funktionierende Nextcloud-Hintergrundaufgaben. Das Speichern einer Gruppenregel verschickt keine E-Mail.

Zum Ausprobieren dienen ausschließlich Testkonten und ein lokales Testpostfach, beispielsweise Mailpit unter `http://localhost:8025` in der vorhandenen Demo. Ein geeigneter Fall ist ein leerer Montag mit vollständigen Einträgen am Dienstag und Mittwoch. Nach Ablauf der gewählten Frist erscheint Montag in der Erinnerung. Ergänzte Zeiten verschwinden beim nächsten Versand aus der Liste. Die Versandbegrenzung und die Ausnahmen werden zusätzlich mit festen Datumswerten in den Verhaltenstests geprüft. Echte Mitarbeiteradressen gehören nicht in diese Versandtests.

## 5. Änderungen prüfen

Im Hauptordner des Repositorys prüft folgender Befehl die Schreibweise aller JavaScript-Dateien unter `js/` und `l10n/`.

```powershell
npm run check:javascript
```

Die Verhaltenstests prüfen unter anderem Zeitformate, Warnregeln, Speichervorgänge und HR-Regelkarten. Sie laufen ohne zusätzliche npm-Pakete:

```powershell
npm run test:javascript
```

Die PHP-Tests für Erinnerungen und persönliche Anzeigeeinstellungen laufen jeweils als eigener Aufruf, ohne eine Nextcloud-Installation zu laden:

```powershell
php tests/reminders/detector.php
php tests/reminders/group-policy-test.php
php tests/reminders/state.php
php tests/reminders/mailer.php
php tests/preferences/user-preferences.php
php tests/tour/progress.php
```

`tests/reminders/integration.php` prüft den gesamten Ablauf einschließlich Versand in einer eigenen Nextcloud-Testinstanz. Er erzeugt Testkonten und Einträge und ersetzt dort die Gruppenregeln. Deshalb startet er ausschließlich mit `REMINDER_TEST_ENVIRONMENT=1`, der Datenbank `timesheet_reminders_test`, einem gesetzten `REMINDER_TEST_PASSWORD` und Mailpit als Versandziel. Er gehört nicht in die normale Demo oder eine produktive Installation. Der vorhandene PHPUnit-Aufbau bleibt davon getrennt.

Mit lokal verfügbarem PHP prüft dieser Befehl alle PHP-Dateien der App und ihrer Prüfprogramme:

```powershell
php tools/check-php-syntax.php
```

In der beschriebenen Docker-Demo ist dieselbe Prüfung über Composer erreichbar:

```powershell
docker compose exec -u www-data -w /var/www/html/custom_apps/timesheet nextcloud composer lint
```

Diese Prüfungen finden Schreibfehler im Code. Ob Arbeitszeiten richtig berechnet werden und Schaltflächen funktionieren, zeigt erst die Prüfung in der App: Eintrag anlegen, ändern und löschen; Monatswerte vergleichen; Monats- und Jahresexport öffnen. Bei HR-Änderungen kommen getrennte HR-Konten und Mitarbeitergruppen hinzu. Für Berechnungen dienen bekannte Beispieltage und bestehende Stundenstände als Vergleich.

Zusätzliche PHP-Werkzeuge sind vorbereitet, aber noch nicht als vollständig eingerichtete Werkzeugkette geprüft:

| Befehl bei installierten Werkzeugen | Aufgabe und Einstellung |
| --- | --- |
| `composer cs:check` | Abweichungen von der PHP-Schreibweise anzeigen; `tools/php/code-style.php`. |
| `composer cs:fix` | Diese Schreibweise auf die Dateien anwenden. |
| `composer psalm` | Mögliche PHP-Fehler suchen; `tools/php/static-analysis.xml`. |
| `composer rector` | Vorschläge zur PHP-Modernisierung anzeigen, ohne Dateien zu ändern; `tools/php/modernize-code.php`. |
| `composer test:unit` | Künftige PHP-Testfälle in der Nextcloud-Testumgebung ausführen. Die JavaScript-Tests laufen unabhängig davon mit npm. |

## 6. Zusammenarbeit und Veröffentlichung

Ein überschaubarer Commit beschreibt eine zusammengehörige Änderung und ihre Prüfung. GitHub Desktop zeigt Branch und Dateiunterschiede vor dem Commit an. „Push origin“ lädt den Branch in den eigenen Fork. Ein Pull Request schlägt die Übernahme ins Firmen-Repository vor. Ein Commit oder Push installiert keine neue Version in Nextcloud.

Im bisherigen Checkout heißt der eigene Fork `origin`, das [Firmen-Repository](https://github.com/Xiller/nextcloud-arbeitszeitnachweis) `upstream` und das [ursprüngliche Repository](https://github.com/Repa1253/timesheet) `original`. `git remote -v` zeigt die tatsächlich eingetragenen Quellen. Die [Verhaltensregeln für die Zusammenarbeit](../CODE_OF_CONDUCT.md) bleiben Bestandteil des Projekts.

Für eine Veröffentlichung gilt diese Reihenfolge:

1. Ein vollständiger Ausgabeordner `timesheet` enthält App-Dateien, Laufzeitbibliotheken und Lizenzhinweise. Entwicklungsdateien werden bereits bei dessen Vorbereitung ausgespart.
2. Neuinstallation, Update und Excelexport werden mit dieser Ausgabe geprüft. Bestehende Daten und Stundenstände bleiben erhalten.
3. `occ integrity:sign-app` signiert den fertigen App-Ordner mit dem verwalteten Schlüssel und Zertifikat. Dabei entsteht `appinfo/signature.json`, die Prüfliste für die App-Dateien.
4. Der danach unveränderte Ordner wird als `timesheet-VERSION.tar.gz` verpackt. Im Archiv liegt genau ein oberster Ordner `timesheet`. Die Versionsangabe entspricht `appinfo/info.xml`.
5. Für genau dieses Archiv wird mit OpenSSL eine SHA-512-Signatur im Base64-Format erstellt. Der Nextcloud App Store erhält Archiv und Archivsignatur.

Die App-Signatur und die Archivsignatur sind zwei getrennte Schritte. Nachträgliche Änderungen erfordern die entsprechende erneute Signierung. Ein Git-Export nach dem App-Signieren würde die ignorierte `signature.json` verlieren. Schlüssel, Zertifikat, Zugänge und Freigabe gehören zum geregelten Veröffentlichungsprozess; private Schlüssel und Token liegen außerhalb des Repositorys.

Die [Nextcloud-Anleitung zur App-Signatur](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html) und die [App-Store-Anleitung](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#uploading-an-app-release) beschreiben die Befehle und Übergabeparameter. Die früheren umgebungsspezifischen Veröffentlichungshinweise sind damit hier zusammengeführt.

## 7. Noch offen

- Eine eigene, auf einem frischen Rechner erprobte Demo-Konfiguration fehlt.
- `composer.json` und `composer.lock` enthalten unterschiedliche PHP-Anforderungen. Die Laufzeitbibliotheken wurden mit dem dokumentierten Aufruf in der Vergleichsumgebung installiert. Die abweichende PHP-Vorgabe und die zusätzliche PHP-Werkzeugkette bleiben gesondert zu bereinigen.
- HR-Zugriffsprüfungen unterscheiden sich zwischen einzelnen Anfragen. Die Trennung zwischen Mitarbeitergruppen braucht praktische Prüffälle.
- Unvollständige Einträge, Tagesziel null und bestehende Saldoüberträge brauchen nachvollziehbare Berechnungsbeispiele. Ob Altdaten ungewöhnliche oder negative Pausenwerte für Überträge enthalten, ist anhand der tatsächlichen Daten zu klären.
- Ein vollständiger Veröffentlichungslauf mit geprüftem Archiv und Datenerhalt steht aus.

</details>

<details>
<summary>English</summary>

## 1. Overview

Timesheet runs inside Nextcloud. PHP processes and stores data. JavaScript handles browser interaction. The app ID and installation directory are both `timesheet`.

This project builds on version 1.2.0 by David Gun (Repa1253). The authoritative app version is in `appinfo/info.xml`. It declares Nextcloud 30–33 and PHP 8.2–8.5 as intended versions.

## 2. Directories and files

| Name | Meaning |
| --- | --- |
| `appinfo/` | App registration, version information and routing of browser requests. |
| `lib/` | PHP code for calculations, database access, settings and email tasks. |
| `templates/` | Structure of the app page and administration page. |
| `js/` | JavaScript for time entries, exports, the HR view and settings. |
| `css/` and `img/` | Styling and app icons. |
| `l10n/` | Translations. Nextcloud expects this directory name. PHP uses JSON files; the browser uses JS files. Tour text is supplied in the offered account languages. `de` and `de_DE` provide the informal and formal German variants. |
| `vendor/` | Installed PHP libraries, including those for Excel exports. Composer creates this directory; it belongs to a complete installation. |
| `tools/` | Small programs for checking the code. PHP tool settings are grouped in `tools/php/`. |
| `vendor-bin/` | Configuration and dependencies for the additional PHP checking tools. |
| `tests/` | JavaScript behavior tests under `tests/readability/` and PHP test setup for a Nextcloud test environment. |
| `docs/TECHNICAL_GUIDE.md` | This guide to development and publishing. |

Composer and npm settings are in the repository root. `composer.json` and `composer.lock` define PHP libraries. `package.json` contains the JavaScript check and test commands, with no additional npm packages. `.nvmrc` names Node 22 as a shared starting version; the check supports Node 22 and later. `README.md` describes the app, `CHANGELOG.md` the release history, and `LICENSE` the license.

## 3. Where changes belong

| Change | Starting point |
| --- | --- |
| Visible text and page structure | `templates/main.php`; `lib/Controller/PageController.php` loads the related scripts and styling. |
| Appearance | `css/style.css` and `img/`. |
| Time entries | `js/timesheet-entries.js`, `lib/Controller/EntryController.php`, `lib/Service/EntryService.php`. |
| Balances and monthly values | `lib/Db/EntryMapper.php`, `lib/Service/MonthlyBalanceService.php`, `lib/Controller/BalanceController.php`. |
| Monthly and yearly exports | `js/timesheet-export.js` handles the selection; `lib/Service/TimesheetXlsxExporter.php` creates the file. `EntryController` checks access and returns the download. |
| HR overview and responsibilities | `js/timesheet-hr.js`, `lib/Controller/OverviewController.php`, `lib/Service/HrService.php`. |
| Display preferences | `PreferencesController` and `lib/Db/UserPreferencesStore.php` store the personal break display. `PageController` includes the saved selection when rendering the page. |
| Settings | `ConfigController` receives personal settings; `lib/Db/HrPreferencesStore.php` reads and saves HR email and warning preferences. Group rules belong to `SettingsController`, `templates/settings-admin.php` and `js/admin.js`. |
| Emails and public holidays | `lib/BackgroundJob/HrNotificationJob.php`, `lib/Service/HrNotificationService.php`, `lib/Service/HolidayService.php`. |
| Shared browser functions | `js/timesheet-main.js`, `js/timesheet-core.js`, `js/timesheet-copy.js`. |

### Shared coding rules

- Names and comments use English. Names describe their content or purpose, such as `appState`, `monthDisplay` and `breakMinutes`.
- `.editorconfig` defines tabs displayed as four characters and LF line endings for PHP, JavaScript and CSS. `.gitattributes` preserves these line endings during Git checkouts. Historical database migrations remain unchanged.
- Comments mark larger sections and explain particular behavior or units. Individual lines do not each need an explanation.
- JavaScript files remain separated by entries, HR, exports, settings and page coordination. Related private functions read a row, update its preview and save it. Active saves and their queue remain together.
- Existing browser addresses, data fields, translation keys and HTML identifiers stay compatible during structural work. Existing calculations and access rules remain the reference behavior.

New connections between the browser and PHP are registered in `appinfo/routes.php`. Database changes live under `lib/Migration/`. Previously published changes remain there so that fresh installations and updates can follow the same steps.

## 4. Trying the app locally

A local demo is a separate Nextcloud with a database and test accounts. This repository does not yet include its own Docker configuration. The following procedure therefore assumes an existing configured demo. The [Nextcloud developer documentation](https://docs.nextcloud.com/server/33/developer_manual/) describes the framework for such an environment.

In these examples, the Docker service is called `nextcloud`. Nextcloud is at `/var/www/html` inside the container, with PHP and Composer available. The fork is mounted as a writable directory at `/var/www/html/custom_apps/timesheet`. The browser address and test login belong to the particular demo.

1. The intended branch, for example `Updated_App`, is selected in the editor.
2. The Docker configuration points to this fork's full local path. If the demo supports `TIMESHEET_APP_PATH`, the path is set in its `.env`. This setting belongs to the demo.
3. Configuration and startup are checked from the directory containing the Compose file:

   ```powershell
   docker compose config --quiet
   docker compose up -d
   ```

4. Composer installs runtime libraries in the mounted fork:

   ```powershell
   docker compose exec -u www-data -w /var/www/html/custom_apps/timesheet nextcloud composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
   docker compose exec -u www-data -w /var/www/html/custom_apps/timesheet nextcloud composer check-platform-reqs --no-dev
   ```

   This invocation skips additional development tools. An error at this point remains an unresolved setup step; switching to `composer update` would change library versions.

5. Nextcloud administration commands check status and enable the app:

   ```powershell
   docker compose exec -u www-data nextcloud php /var/www/html/occ status
   docker compose exec -u www-data nextcloud php /var/www/html/occ app:enable timesheet
   ```

   A configured Nextcloud reports `installed: true`. A version change may also require a database upgrade; the demo's documented upgrade procedure applies. The [occ guide](https://docs.nextcloud.com/server/33/admin_manual/occ_command.html) explains these administration commands.

6. The app is available at the demo's browser address under `/apps/timesheet/`. A small visible text change followed by a reload shows whether the demo actually uses this fork.

Saved changes to mounted files appear after a reload; a GitHub upload is not required. Switching branches changes files but does not roll back the database. Changes to the current JavaScript and CSS files do not require a build step.

Public holiday data requires outbound HTTPS access to `get.api-feiertage.de` unless valid cached data is available. HR emails require personal notification settings, a recipient address, working Nextcloud mail delivery and background jobs. The existing job normally sends on Mondays. Test emails belong in a test mailbox.

### Personal break display

The **Break: Minutes – Hours** switch saves its selection automatically in the signed-in Nextcloud account. The selection survives reloads and signing in again and applies to both personal entries and the HR view. When viewing another employee, the viewer’s display preference applies. Stored break values and calculations remain unchanged.

### Guided tour

The compass between search and notifications opens the Timesheet introduction. The tour is currently disabled for Nextcloud administrator accounts, which receive neither its button nor its invitation. `js/timesheet-tour.js` contains its flow and explanations; `js/timesheet-tour-header.js` connects the button to the Nextcloud header. Styling lives in `css/timesheet-tour.css`. A visible entry point inside Timesheet is used if the expected header is unavailable.

The tour is offered after the app has loaded. **Later** defers the invitation until the next visit. Completion is stored per account, separately for the general introduction and the HR extension. `TourController` accepts completion; `lib/Db/TourProgressStore.php` stores only these two completion versions. Newly granted HR access offers the additional introduction. A manually started tour includes all steps currently available.

A separate step explains switching between the personal timesheet and the HR overview before entering the HR features. The comments step already describes the planned HR approval process for “Urlaub” and “Stundenausgleich”. Implementing approval remains a separate feature task; this tour change adds only the explanation.

Explanations read existing views and open menus when needed. The tour creates no time entries, changes no settings and starts no downloads. Existing read calls can cache calculated monthly balances, just as opening the HR view normally does. The tour restores the previous view when it ends. **Help & privacy** leads to general Nextcloud help.

The account language determines the tour text. Changes to explanations are maintained in all corresponding language files. Coverage checks under `tests/tour/` verify messages, placeholders and matching JSON/JS catalogs. They do not replace linguistic review by native speakers. Tour checks run with `npm run test:tour`; the separate PHP command is `php tests/tour/progress.php`.

### Reminders for individual open dates

Each group rule on the administration page has a **Missing time entry reminders** section. The feature is disabled after installation. Settings cover a delay from 1 to 365 calendar days (default 14), expected weekdays (default Monday to Friday), and the date from which entries are checked. The current date is suggested on first enablement; an earlier start can be chosen.

The daily check finds individual older gaps even when later dates already have entries. A missing start or end time also triggers a reminder. When both times are absent, only a whole comment matching **Urlaub** or **Stundenausgleich** prevents a reminder. Letter case and surrounding spaces are ignored. Other comments or added words do not qualify. An entry with only one time remains incomplete even with one of these comments. The HR overview displays the same guidance. These two accepted German terms remain unchanged in the English interface.

Public holidays in the employee's configured German state are excluded. Without a state or when holiday data cannot be obtained, the employee's check is suspended and responsible HR receives a notice. The selected weekdays apply even when the daily target is zero.

On first activation, existing group members are enrolled using the chosen start date. Later members are checked no earlier than their first detection date. Saving and re-enabling preserve this enrollment. Where several group rules match, the lowest priority number still wins, including a rule with reminders disabled.

Employees receive only their own open dates. HR receives a separate summary limited to currently assigned employees. A successful email starts a seven-day waiting period for that message type; additional gaps do not shorten it. No reminder is sent without open dates. Existing HR emails continue separately. Detection lives in `MissingEntryDetector`, coordination in `MissingEntryReminderService`, email delivery in `MissingEntryReminderMailer`, and stored enrollment in `MissingEntryReminderStore`. The new daily task is `MissingEntryReminderJob` and requires working Nextcloud background jobs. Saving a group rule never sends an email.

Use test accounts and a local test inbox, such as Mailpit at `http://localhost:8025` in the existing demo. One useful case has an empty Monday followed by complete Tuesday and Wednesday entries. Once the selected delay expires, Monday appears in the reminder. Filling the gap removes it from the next message. Fixed-date behavior tests also cover delivery intervals and exceptions. Real employee addresses do not belong in these delivery tests.

## 5. Checking changes

From the repository root, this command checks the syntax of every JavaScript file under `js/` and `l10n/`. Node 22 or later is sufficient; `npm install` is not needed.

```powershell
npm run check:javascript
```

Behavior tests cover time formats, warning rules, saves and HR rule cards, among other cases. They run without additional npm packages:

```powershell
npm run test:javascript
```

The PHP tests for reminders and personal display preferences run as separate commands without loading a Nextcloud installation:

```powershell
php tests/reminders/detector.php
php tests/reminders/group-policy-test.php
php tests/reminders/state.php
php tests/reminders/mailer.php
php tests/preferences/user-preferences.php
php tests/tour/progress.php
```

`tests/reminders/integration.php` checks the full workflow and delivery in a dedicated Nextcloud test instance. It creates synthetic accounts and entries and replaces that instance's group rules. It therefore runs only with `REMINDER_TEST_ENVIRONMENT=1`, database `timesheet_reminders_test`, a configured `REMINDER_TEST_PASSWORD`, and Mailpit as the mail destination. It does not belong in the normal demo or a production installation. The existing PHPUnit setup remains separate.

With PHP available locally, this command checks all PHP files in the app and its checking tools:

```powershell
php tools/check-php-syntax.php
```

In the Docker demo described above, the same check is available through Composer:

```powershell
docker compose exec -u www-data -w /var/www/html/custom_apps/timesheet nextcloud composer lint
```

These checks find syntax errors. Correct time calculations and working buttons require checks inside the app: create, edit and delete an entry, compare monthly values, and open monthly and yearly exports. HR changes also need separate HR accounts and employee groups. Known example days and existing balances provide comparisons for calculations.

Additional PHP tools are configured but have not yet been verified as a fully installed toolchain:

| Command with tools installed | Purpose and configuration |
| --- | --- |
| `composer cs:check` | Show PHP code style differences; `tools/php/code-style.php`. |
| `composer cs:fix` | Apply this code style to files. |
| `composer psalm` | Find possible PHP errors; `tools/php/static-analysis.xml`. |
| `composer rector` | Show PHP modernization suggestions without changing files; `tools/php/modernize-code.php`. |
| `composer test:unit` | Run future PHP test cases inside the Nextcloud test environment. JavaScript tests run separately through npm. |

## 6. Collaboration and publishing

A focused commit describes one related change and its checks. GitHub Desktop shows the branch and file differences before committing. “Push origin” uploads the branch to the personal fork. A pull request proposes its inclusion in the company repository. A commit or push does not install a new version in Nextcloud.

In the existing checkout, `origin` means the personal fork, `upstream` the [company repository](https://github.com/Xiller/nextcloud-arbeitszeitnachweis), and `original` the [original repository](https://github.com/Repa1253/timesheet). `git remote -v` shows the configured sources. The [code of conduct](../CODE_OF_CONDUCT.md) remains part of the project.

Publishing follows this order:

1. A complete output directory named `timesheet` contains app files, runtime libraries and license notices. Development files are excluded while preparing this directory.
2. Fresh installation, upgrade and Excel exports are checked using this output. Existing data and balances are preserved.
3. `occ integrity:sign-app` signs the finished app directory using the managed key and certificate. This creates `appinfo/signature.json`, the list of signed app files.
4. The unchanged directory is then packaged as `timesheet-VERSION.tar.gz`. The archive has exactly one top-level directory named `timesheet`. Its version matches `appinfo/info.xml`.
5. OpenSSL creates a Base64-encoded SHA-512 signature for this exact archive. The Nextcloud App Store receives the archive and its archive signature.

The app signature and archive signature are separate steps. Later changes require the corresponding signature to be recreated. A Git export after app signing would lose the ignored `signature.json`. Keys, certificate, credentials and approval belong to the publishing process; private keys and tokens stay outside the repository.

The [Nextcloud app signing guide](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html) and [App Store guide](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#uploading-an-app-release) describe the commands and parameters. Earlier environment-specific publishing notes have been consolidated here.

## 7. Still open

- The repository lacks its own demo configuration tested on a fresh machine.
- `composer.json` and `composer.lock` specify different PHP requirements. Runtime libraries were installed in the comparison environment using the documented command. The PHP requirement mismatch and additional PHP toolchain still need separate work.
- HR access checks differ between individual requests. Separation between employee groups needs practical checks.
- Incomplete entries, a zero daily target and existing balance carryovers need reproducible calculation examples. Actual data is needed to establish whether old carryovers use unusual or negative break values.
- A complete publishing run with a verified archive and preserved data remains outstanding.

</details>
