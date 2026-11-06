# Timesheet: Technische Anleitung / Technical guide

[App-Beschreibung / App overview](../README.md)

<details open>
<summary>Deutsch</summary>

## 1. Voraussetzungen

Timesheet läuft innerhalb einer eingerichteten Nextcloud. App-ID und Installationsordner heißen `timesheet`. Die gültige App-Version steht in `appinfo/info.xml`.

| Bestandteil | Voraussetzung |
| --- | --- |
| Nextcloud | Version 30–33 laut App-Metadaten. |
| PHP | Version 8.2–8.5 laut App-Metadaten; benötigte Erweiterungen ergeben sich aus `composer.lock`. |
| Datenbank | Eine für die eingesetzte Nextcloud geeignete Datenbank. Die App legt ihre Tabellen selbst an. |
| Composer 2 | Für die Installation der PHP-Bibliotheken aus dem Quellcode. Ein fertiges App-Paket enthält sie bereits unter `vendor/`. |
| Node.js ab 22 | Nur für Entwicklungsprüfungen. Für den Betrieb sind weder Node.js noch ein JavaScript-Build erforderlich. |

Die Versionsbereiche ersetzen keine Prüfung der jeweiligen Installation. `composer.json` und `composer.lock` enthalten derzeit unterschiedliche PHP-Vorgaben. Diese Abweichung ist noch zu bereinigen; Plattformfehler werden nicht durch ein ungeprüftes `composer update` behoben.

## 2. Projektaufbau

| Pfad | Inhalt |
| --- | --- |
| `appinfo/` | App-Metadaten, Version, Änderungshinweise und Zuordnung der Browseranfragen. |
| `lib/` | PHP-Code, Datenbankzugriffe, Migrationen und Hintergrundaufgaben. |
| `templates/` | Seitenvorlagen. |
| `js/`, `css/`, `img/` | Browsercode, Gestaltung und Bilder. |
| `l10n/` | Übersetzungen für PHP und Browser. Nextcloud erwartet diesen Ordnernamen. |
| `vendor/` | Mit Composer installierte PHP-Bibliotheken. Für den Betrieb erforderlich. |
| `tests/` | Automatisierte Prüfungen. |
| `tools/`, `vendor-bin/` | Prüfprogramme und deren Einstellungen. |
| `composer.json`, `composer.lock` | PHP-Abhängigkeiten und festgelegte Bibliotheksversionen. |
| `package.json`, `.nvmrc` | JavaScript-Prüfbefehle und vorgesehene Node-Version. |

`README.md` beschreibt die App. `CHANGELOG.md` enthält die Versionsgeschichte, `LICENSE` die Lizenz. Eine eigene Docker-Konfiguration ist im Repository nicht enthalten.

## 3. Lokale Installation

Die Befehle beziehen sich auf die vorhandene Docker-Demo mit dem Container `timesheet-handover-nextcloud-1`. PHP und Composer sind dort vorausgesetzt; `www-data` benötigt für die Bibliotheksinstallation Schreibzugriff auf den App-Ordner. Bei anderen Installationen gelten deren Containername, PHP-Benutzer und Nextcloud-Pfad.

1. Den gewünschten Git-Branch auswählen. Den lokalen Repositoryordner in der separaten Docker-Konfiguration unter `/var/www/html/custom_apps/timesheet` einbinden.
2. Im Ordner der Compose-Datei die Demo starten:

   ```powershell
   docker compose config --quiet
   docker compose up -d
   ```

3. Im eingebundenen App-Ordner die Laufzeitbibliotheken installieren und die PHP-Voraussetzungen prüfen:

   ```powershell
   docker exec --user www-data --workdir /var/www/html/custom_apps/timesheet timesheet-handover-nextcloud-1 composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
   docker exec --user www-data --workdir /var/www/html/custom_apps/timesheet timesheet-handover-nextcloud-1 composer check-platform-reqs --no-dev
   ```

4. Nextcloud-Status prüfen und die App aktivieren:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ status
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ app:enable timesheet
   ```

5. Die HR-Zuordnungen unter **Profil → Einstellungen → Administration → Arbeitszeitnachweis** einrichten. Gruppen und Konten müssen bereits bestehen. Nur Administratoren ändern diese Zuordnungen. Zeitregeln, Erinnerungen und Mail-Einstellungen bleiben für HR unter **Profil → Einstellungen → Arbeitszeitnachweis** verfügbar.
6. Die Demo unter [localhost:8133/apps/timesheet/](http://localhost:8133/apps/timesheet/) öffnen. Das lokale Testpostfach liegt unter [localhost:8025](http://localhost:8025).

Änderungen an eingebundenen PHP-, JavaScript- und CSS-Dateien benötigen keinen Upload und keinen Build. Bei zwischengespeicherten Browserdateien hilft ein vollständiges Neuladen mit `Strg + F5`. Ein Branchwechsel setzt die Datenbank nicht zurück.

## 4. Betrieb und Datenerhalt

- Nextcloud-Hintergrundaufgaben regelmäßig ausführen lassen. `HrNotificationJob`, `MissingEntryReminderJob` und `ChangeHistoryCleanupJob` werden täglich eingeplant; die ältere HR-Warnungsübersicht versendet regulär montags.
- Für E-Mails sind Nextcloud-Mailversand und gültige Kontoadressen erforderlich. Versandtests verwenden Testkonten und Mailpit. Die Glockenhinweise benötigen die aktivierte Nextcloud-App **Benachrichtigungen**.
- Feiertagsdaten benötigen ausgehenden HTTPS-Zugriff auf `get.api-feiertage.de`, sofern keine gültigen gespeicherten Daten vorliegen. Ohne verlässliche Feiertagsdaten wird die betreffende Erinnerungsprüfung ausgesetzt.
- Die Gruppenkennung `Zeitnachweis` bestimmt den Aktivstatus. HR-Zugriff und Mail-Empfänger werden getrennt in `hr_access_rules` festgelegt. Änderungen an Gruppenkennungen betreffen daher auch die App.
- App-Daten liegen in Nextcloud-Datenbanktabellen, Konfigurationswerten und Benutzereinstellungen. Sicherungen umfassen Datenbank, Nextcloud-Konfiguration und App-Dateien gemeinsam.
- Der Änderungsverlauf liegt in `ts_change_history` und wird zwölf Kalendermonate aufbewahrt. Kontolöschungen entfernen den betroffenen Verlauf beziehungsweise anonymisieren gelöschte Bearbeiter. Fehlgeschlagene Bereinigungen werden erneut versucht; betroffene Zugriffe bleiben bis dahin gesperrt.
- Veröffentlichte Migrationen unter `lib/Migration/` bleiben unverändert. Historische negative Pausenwerte können Saldoüberträge enthalten und dürfen nicht pauschal bereinigt werden.

## 5. Entwicklung und Prüfungen

Code und Kommentare stehen auf Englisch. Sprechende Namen, kurze Abschnittskommentare, Tabs und LF-Zeilenumbrüche folgen den vorhandenen Einstellungen in `.editorconfig` und `.gitattributes`. Reine Aufräumarbeiten erhalten Berechnungen, Zugriffsprüfungen und bestehende Schnittstellen.

Übersetzungen werden in den passenden JSON- und JS-Dateien unter `l10n/` gemeinsam gepflegt. `de` und `de_DE` enthalten die beiden deutschen Kontosprachen. Sprachabhängige Abwesenheitsbegriffe liegen gesondert in `lib/Service/absence-comment-terms.json`.

Im Hauptordner des Repositorys, bei lokal verfügbarem PHP:

```powershell
npm run check:javascript
npm run test:javascript
php tools/check-php-syntax.php
```

Ohne lokales PHP läuft die Syntaxprüfung in der vorhandenen Demo:

```powershell
docker exec --user www-data --workdir /var/www/html/custom_apps/timesheet timesheet-handover-nextcloud-1 php tools/check-php-syntax.php
```

Eigenständige PHP-Tests liegen unter `tests/reminders/`, `tests/preferences/`, `tests/tour/`, `tests/settings/`, `tests/employment/`, `tests/updates/`, `tests/history/` und `tests/balance-notifications/`. Beispiel: `php tests/history/backend.php`. Die zum geänderten Bereich passenden Tests ergänzen die Syntaxprüfung.

Integrationstests legen Konten und Daten an und können Gruppenregeln ersetzen. Sie laufen ausschließlich in ihren getrennten Testinstanzen mit den im jeweiligen Programm festgelegten Datenbanknamen und Freigabevariablen. Die normale Demo und Firmeninstallationen sind dafür ausgeschlossen.

PHP-Stilprüfung, Psalm, Rector und PHPUnit sind vorbereitet, aber noch keine vollständig geprüfte Werkzeugkette. Ihre Einstellungen liegen unter `tools/php/` und `vendor-bin/`. PHPUnit benötigt zusätzlich die Nextcloud-Testumgebung.

## 6. Ein Update vorbereiten und aktivieren

1. Datenbank, Konfiguration und bisherige App-Dateien sichern.
2. Die Version in `appinfo/info.xml` erhöhen, beispielsweise von `1.4.0` auf `1.4.1`.
3. In `appinfo/release-notes.json` einen neuen Eintrag am Anfang der vorhandenen `releases`-Liste ergänzen. Seine Version stimmt mit `info.xml` überein. Frühere Einträge bleiben erhalten. Neue englische Texte werden in allen vorhandenen Sprachdateien übersetzt und in `tests/updates/translation-coverage.json` ergänzt.
4. Codeprüfungen sowie Neuinstallation und Update in einer getrennten Testumgebung durchführen. Vorhandene Daten und Stundenstände vergleichen.
5. Die geprüften App-Dateien samt Laufzeitbibliotheken in die Zielinstallation übernehmen. In der lokalen Demo geschieht dies über den eingebundenen Repositoryordner. Anschließend aktivieren:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ upgrade --no-interaction
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ config:app:get timesheet installed_version
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ status
   ```

   Die installierte App-Version entspricht anschließend der neuen Versionsnummer. Nextcloud meldet `maintenance: false` und `needsDbUpgrade: false`.

6. Die App neu laden und die geänderten Abläufe prüfen. Die Änderungsliste lässt sich in der Demo über [diesen Aufruf](http://localhost:8133/apps/timesheet/?update-notes=1) erneut öffnen.

Nur eine erhöhte Versionsnummer löst einen neuen Update-Hinweis aus. Eine Textänderung, ein Push oder ein Merge installiert kein Update. Bei einer fehlgeschlagenen Datenbankumstellung erfordert eine Rückkehr die zusammengehörige Sicherung von Datenbank, Konfiguration und App-Dateien.

## 7. Ein App-Paket veröffentlichen

1. Einen vollständigen Ausgabeordner `timesheet` mit Laufzeitbibliotheken und Lizenzhinweisen vorbereiten. Prüfprogramme und Entwicklungsdateien gehören nicht ins Installationspaket.
2. Neuinstallation, Update und Excelexport mit genau diesem Paket prüfen.
3. Den fertigen Ordner mit `occ integrity:sign-app` und dem verwalteten Schlüssel samt Zertifikat signieren. Dabei entsteht `appinfo/signature.json`.
4. Den danach unveränderten Ordner als `timesheet-VERSION.tar.gz` verpacken. Im Archiv liegt genau ein oberster Ordner `timesheet`.
5. Für das Archiv eine SHA-512-Signatur im Base64-Format erstellen und das Release im Nextcloud App Store einreichen.

App-Signatur und Archivsignatur sind getrennte Schritte. `vendor/` und `appinfo/signature.json` werden von Git ignoriert und gehören ausdrücklich ins fertige Paket. Ein Git-Export allein reicht dafür nicht. Private Schlüssel und Zugangstoken bleiben außerhalb des Repositorys.

Die Installation auf der Firmeninstanz erfolgt zentral durch die Nextcloud-Administration. Der genaue Veröffentlichungs- und Installationsweg der Firma ist noch festzulegen.

Weitere Angaben: [App-Signatur](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html), [Release im App Store einreichen](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#uploading-an-app-release).

</details>

<details>
<summary>English</summary>

## 1. Requirements

Timesheet runs inside an installed Nextcloud instance. Its app ID and installation directory are `timesheet`. The authoritative app version is in `appinfo/info.xml`.

| Component | Requirement |
| --- | --- |
| Nextcloud | Versions 30–33 according to the app metadata. |
| PHP | Versions 8.2–8.5 according to the app metadata; required extensions are defined by `composer.lock`. |
| Database | A database supported by the installed Nextcloud version. The app creates its own tables. |
| Composer 2 | Required to install PHP dependencies from source. A complete app package already includes them in `vendor/`. |
| Node.js 22 or later | Required only for development checks. Runtime operation needs neither Node.js nor a JavaScript build. |

Declared version ranges do not replace testing the actual installation. `composer.json` and `composer.lock` currently contain different PHP constraints. This discrepancy remains unresolved; platform errors require investigation rather than an unreviewed `composer update`.

## 2. Project structure

| Path | Contents |
| --- | --- |
| `appinfo/` | App metadata, version, release notes and request routing. |
| `lib/` | PHP code, database access, migrations and background jobs. |
| `templates/` | Page templates. |
| `js/`, `css/`, `img/` | Browser code, styles and images. |
| `l10n/` | PHP and browser translations. Nextcloud requires this directory name. |
| `vendor/` | Composer-installed PHP dependencies. Required at runtime. |
| `tests/` | Automated checks. |
| `tools/`, `vendor-bin/` | Checking tools and their configuration. |
| `composer.json`, `composer.lock` | PHP dependencies and locked library versions. |
| `package.json`, `.nvmrc` | JavaScript check commands and the intended Node version. |

`README.md` describes the app. `CHANGELOG.md` contains release history; `LICENSE` contains the licence. The repository does not include a Docker configuration.

## 3. Local installation

These commands target the existing Docker demo and its `timesheet-handover-nextcloud-1` container. PHP and Composer must be available there; dependency installation requires `www-data` to have write access to the app directory. Other installations require their own container name, PHP user and Nextcloud path.

1. Select the intended Git branch. Mount the local repository at `/var/www/html/custom_apps/timesheet` in the separate Docker configuration.
2. Start the demo from the directory containing its Compose file:

   ```powershell
   docker compose config --quiet
   docker compose up -d
   ```

3. Install runtime dependencies in the mounted app directory and check the PHP requirements:

   ```powershell
   docker exec --user www-data --workdir /var/www/html/custom_apps/timesheet timesheet-handover-nextcloud-1 composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
   docker exec --user www-data --workdir /var/www/html/custom_apps/timesheet timesheet-handover-nextcloud-1 composer check-platform-reqs --no-dev
   ```

4. Check Nextcloud status and enable the app:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ status
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ app:enable timesheet
   ```

5. Configure HR assignments under **Profile → Settings → Administration → Timesheet**. Groups and accounts must already exist. Only administrators can change these assignments. Time rules, reminders and email settings remain available to HR under **Profile → Settings → Timesheet**.
6. Open the demo at [localhost:8133/apps/timesheet/](http://localhost:8133/apps/timesheet/). The local test inbox is at [localhost:8025](http://localhost:8025).

Changes to mounted PHP, JavaScript and CSS files need no upload or build. A hard reload clears cached browser files. Switching branches does not reset the database.

## 4. Operation and data preservation

- Run Nextcloud background jobs regularly. `HrNotificationJob`, `MissingEntryReminderJob` and `ChangeHistoryCleanupJob` are scheduled daily; the older HR warning summary normally sends on Mondays.
- Email delivery requires configured Nextcloud mail and valid account addresses. Delivery tests use test accounts and Mailpit. Bell notices require the enabled Nextcloud **Notifications** app.
- Holiday data requires outbound HTTPS access to `get.api-feiertage.de` unless valid cached data exists. Unreliable holiday data suspends the affected reminder check.
- The exact group ID `Zeitnachweis` determines activity status. HR access and mail recipients are configured separately in `hr_access_rules`. Renaming group IDs therefore also affects the app.
- App data is stored in Nextcloud database tables, configuration values and user preferences. Backups cover the database, Nextcloud configuration and app files together.
- Change records are stored in `ts_change_history` for twelve calendar months. Account deletion removes the affected employee's records or anonymises deleted editors. Failed cleanup is retried; affected access remains blocked until it succeeds.
- Published migrations in `lib/Migration/` remain unchanged. Historical negative break values may carry balance transfers and must not be normalised indiscriminately.

## 5. Development and checks

Code and comments use English. Descriptive names, short section comments, tabs and LF line endings follow `.editorconfig` and `.gitattributes`. Structural cleanup preserves calculations, access checks and existing interfaces.

Translations are maintained in matching JSON and JS files under `l10n/`. `de` and `de_DE` cover both German account languages. Language-specific absence terms are stored separately in `lib/Service/absence-comment-terms.json`.

From the repository root, with PHP installed locally:

```powershell
npm run check:javascript
npm run test:javascript
php tools/check-php-syntax.php
```

Without local PHP, run the syntax check in the existing demo:

```powershell
docker exec --user www-data --workdir /var/www/html/custom_apps/timesheet timesheet-handover-nextcloud-1 php tools/check-php-syntax.php
```

Standalone PHP tests are in `tests/reminders/`, `tests/preferences/`, `tests/tour/`, `tests/settings/`, `tests/employment/`, `tests/updates/`, `tests/history/` and `tests/balance-notifications/`. Example: `php tests/history/backend.php`. Tests for the changed area complement syntax checks.

Integration tests create accounts and data and may replace group rules. They run only in their dedicated test instances with the database names and environment variables required by each runner. The normal demo and company installations are excluded.

PHP style checks, Psalm, Rector and PHPUnit are prepared but do not yet form a fully verified toolchain. Their configuration is in `tools/php/` and `vendor-bin/`. PHPUnit also requires the Nextcloud test environment.

## 6. Prepare and activate an update

1. Back up the database, configuration and existing app files.
2. Increase the version in `appinfo/info.xml`, for example from `1.4.0` to `1.4.1`.
3. Add a new entry at the start of the existing `releases` list in `appinfo/release-notes.json`. Its version must match `info.xml`. Retain earlier entries. Translate new English text in all existing language catalogs and add it to `tests/updates/translation-coverage.json`.
4. Run code checks and test fresh installation and upgrade in a separate environment. Compare existing data and balances.
5. Install the tested app files and runtime dependencies on the target instance. The local demo uses the mounted repository directory. Then activate the update:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ upgrade --no-interaction
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ config:app:get timesheet installed_version
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ status
   ```

   The installed app version should match the new version. Nextcloud should report `maintenance: false` and `needsDbUpgrade: false`.

6. Reload the app and check the changed behavior. The demo's release notes can be reopened using [this link](http://localhost:8133/apps/timesheet/?update-notes=1).

Only an increased version triggers a new update notice. Editing text, pushing or merging does not install an update. After a failed database migration, rollback requires the matching database, configuration and app-file backup.

## 7. Publish an app package

1. Prepare a complete `timesheet` distribution directory with runtime dependencies and licence notices. Exclude test tools and development files from the installation package.
2. Test fresh installation, upgrade and Excel export with this exact package.
3. Sign the finished directory with `occ integrity:sign-app` using the managed key and certificate. This creates `appinfo/signature.json`.
4. Package the unchanged directory as `timesheet-VERSION.tar.gz`. The archive contains exactly one top-level `timesheet` directory.
5. Create a Base64-encoded SHA-512 signature for the archive and submit the release to the Nextcloud App Store.

App signing and archive signing are separate steps. Git ignores `vendor/` and `appinfo/signature.json`; both must be explicitly included in the finished package. A Git export alone is insufficient. Private keys and access tokens stay outside the repository.

The Nextcloud administration installs updates centrally on the company instance. The company's exact publishing and installation procedure remains to be agreed.

Further details: [App signing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html), [Submitting an App Store release](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#uploading-an-app-release).

</details>
