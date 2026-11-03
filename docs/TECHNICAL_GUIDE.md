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
| `templates/` | Aufbau der App-Seite und der HR-Einstellungsseite. |
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
| Update-Hinweise | `UpdateNotesService` verbindet Änderungsliste und gespeicherten Lesestand. `UpdateNotesNotifier` bindet die Glocke an. Das Popup liegt in `js/timesheet-update-notes.js`. |
| Freelancer und Beschäftigungsstatus | `js/timesheet-employment-status.js` bedient die beiden Einstellungen. `EmploymentStatusController` prüft die Berechtigung; `EmploymentStatusStore` speichert Status und Zeiträume. `EmploymentStatus` fasst ausgenommene Tage und das Bezugsdatum für Erinnerungen zusammen. |
| Einstellungen | `ConfigController` nimmt persönliche Einstellungen entgegen; `lib/Db/HrPreferencesStore.php` liest und speichert HR-E-Mail- und Warnungseinstellungen. Gruppenregeln liegen bei `SettingsController`, `templates/settings-hr.php` und `js/hr-settings.js`. |
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

### Einstellungen für HR

Der gesamte Bereich **Arbeitszeitnachweis** liegt unter **Profil → Einstellungen**, im Abschnitt **Persönlich**. Er erscheint ausschließlich für Konten mit HR-Zuständigkeit. Dort verwaltet HR die Zuordnung von HR- und Mitarbeitergruppen, Prioritäten, Pausen- und Zeitregeln, Erinnerungen sowie die Prüfung besonderer Tage. Ein Administratorkonto erhält ohne zusätzliche HR-Zuständigkeit keinen Zugriff. Der frühere Eintrag unter **Administration** entfällt.

`lib/Settings/HrSettings.php` lädt das Formular; `HrSection.php` benennt den Menüeintrag. `templates/settings-hr.php` und `js/hr-settings.js` bilden die Oberfläche. `SettingsController` prüft die HR-Rolle auch bei jedem Speichervorgang. Die bisherigen Einstellungen und Browseradressen für das Speichern bleiben erhalten. Die Zeiterfassung darf die Prüfung besonderer Tage weiterhin lesen.

Die HR-Rolle ergibt sich aus den unter `hr_access_rules` gespeicherten HR-Gruppen. Mindestens eine vorhandene HR-Gruppe bleibt beim Speichern zugeordnet. Eine Mitgliedschaft in dieser Gruppe wird in der Nextcloud-Benutzerverwaltung vergeben. Sind bei einer neuen Installation noch keine HR-Gruppen hinterlegt oder wurden alle zugeordneten Gruppen außerhalb der App gelöscht, richtet die für Nextcloud verantwortliche Person zunächst eine vorhandene Gruppe samt HR-Konto ein. Anschließend wird diese Gruppe über den Nextcloud-Verwaltungsbefehl `occ config:app:set timesheet hr_access_rules` einer Regel zugeordnet. Jede Regel benötigt eine eindeutige `id`, `hrGroups`, `userGroups` und eine `priority`; die übrigen Werte erhalten die bestehenden Voreinstellungen. Bereits vorhandene Regeln werden vorher gesichert und gezielt ergänzt. Der Befehl ersetzt den gesamten gespeicherten Regelwert. Ein zusätzlicher Administratorzugang zum Formular ist nicht vorgesehen.

### Persönliche Pausenanzeige

Der Schalter **Pause: Minuten – Stunden** speichert seine Auswahl automatisch im angemeldeten Nextcloud-Konto. Die Auswahl bleibt nach Neuladen und erneutem Anmelden erhalten und gilt für die eigene Zeiterfassung sowie die HR-Ansicht. Beim Betrachten fremder Einträge gilt die Anzeigeeinstellung der betrachtenden Person. Gespeicherte Pausenwerte und Berechnungen bleiben unverändert.

### Freelancer-Modus, Beschäftigungsstatus und HR-Filter

Unter **Pause: Minuten – Stunden** liegt bei normalen Mitarbeiterkonten **Freelancer-Modus: Aus – Ein**. Die Einstellung gilt ausschließlich für das eigene Konto und bleibt nach Neuladen und Anmeldung erhalten. HR- und Administratorkonten können den eigenen Freelancer-Modus nicht ändern. Der Anfangswert ist **Aus**. In der HR-Einzelansicht kann zuständiges HR den Beschäftigungsstatus auf **Aktiv** oder **Inaktiv** setzen. Bestehende Personen gelten zunächst als aktiv. Der Freelancer-Modus wird dort nur angezeigt. Der Beschäftigungsstatus verändert weder das Nextcloud-Konto noch dessen Anmeldung oder Gruppen.

**Filter** rechts über der HR-Tabelle bietet **Aktive Mitarbeitende**, **Inaktive Mitarbeitende**, **Alle Mitarbeitenden** und **Freelancer** sowie eine Suche im vollständigen angezeigten Namen. Suche und Auswahl wirken gemeinsam und bleiben auf die bestehende HR-Zuständigkeit begrenzt. Freelancer können aktiv oder inaktiv sein. Unter **Alle** kennzeichnen Hintergrundfarbe und Beschriftung die Freelancer. Summen beziehen sich auf die sichtbaren Personen. Nach einer Einzelansicht bleibt die Auswahl erhalten; ein vollständiges Neuladen beginnt wieder mit aktiven Personen und leerer Suche.

Freelancer erhalten keine Erinnerungen an fehlende oder unvollständige Zeiten; diese Hinweise entfallen auch für HR. Plus- und Minusstunden bleiben davon unberührt. Über inaktive Personen werden keine Warnmails verschickt. Inaktive HR-Konten erhalten ebenfalls keine Warnmails. Aktives HR im Freelancer-Modus erhält weiterhin Hinweise über andere zugeordnete Mitarbeitende. Die Liste blendet Fehlzeitwarnungen für aktuelle Freelancer und Inaktive aus; andere Hinweise und Stundenstände bleiben sichtbar.

Jede Umschaltung gilt ab dem aktuellen Berliner Kalendertag. Beim Einschalten beginnt die Ausnahme einschließlich dieses Tages; nach Ausschalten beziehungsweise Reaktivieren ist der Tag wieder regulär. Gespeicherte Freelancer- und Inaktiv-Zeiträume bleiben für die tägliche Prüfung dauerhaft ausgenommen. Frühere Lücken außerhalb dieser Zeiträume bleiben prüfbar. Die ältere Meldung **seit längerem kein Eintrag** erhält nach Rückkehr eine neue volle Frist, gerechnet ab dem späteren Datum aus letztem Eintrag und Ende der Ausnahme. Versandfristen werden nicht zurückgesetzt. Zeiteinträge und Salden bleiben unverändert.

Die gemeinsame Kontoeinstellung `employment_status` enthält beide Merkmale und getrennte Zeitraumlisten; eine Datenbanktabelle ist nicht erforderlich. `EmploymentStatusStore` verwendet eine gemeinsame Versionsprüfung und eine Schreibsperre, damit gleichzeitige Änderungen einander nicht überschreiben. Die geschützten Aufrufe heißen `PUT /api/preferences/freelancer-mode` für das eigene Konto und `PUT /api/hr/employees/{userId}/activity` für zuständiges HR. Die Pausenanzeige bleibt getrennt. Fehler werden angezeigt; ein unbekannter Status schaltet Erinnerungen nicht versehentlich wieder ein. Die Tour behält ihre acht Schritte.

### Update-Hinweise

Nach einem Update erscheint beim nächsten App-Aufruf eine kurze Änderungsliste. Sie ist für Mitarbeiter- und HR-Konten bestimmt. Administratorkonten erhalten keine Hinweise, auch bei zusätzlicher HR-Zuordnung.

Das Popup öffnet sich einmal je Konto und Version. Nach dem Schließen bleibt die Änderungsliste über die Glocke erreichbar. Dort stehen die Versionsmeldung und der kurze Hinweis **Änderungsliste öffnen**. Ein Klick öffnet die vollständige Liste im Popup. Über **Alle Benachrichtigungen löschen** wird der Update-Hinweis zusammen mit den anderen Glockenhinweisen entfernt. Spätere Updates erhalten einen neuen Hinweis. Mehrere verpasste Versionen erscheinen zusammengefasst. Neue Konten sehen nur die aktuelle Version. Eine Einführungstour wird erst nach dem Schließen des Update-Popups angeboten.

Versionsnummern werden für einen neuen, geprüften Stand von Hand erhöht. Eine Textkorrektur bei unveränderter Version löst keinen neuen Hinweis aus.

#### Neues Update vorbereiten und aktivieren

1. In `appinfo/info.xml` die Versionsnummer erhöhen, beispielsweise auf `<version>1.3.1</version>`.
2. In `appinfo/release-notes.json` einen neuen Block mit `"version": "1.3.1"` und den zugehörigen Änderungen ergänzen. Frühere Versionsblöcke bleiben erhalten.
3. Neue englische Textschlüssel in den vorhandenen Sprachdateien übersetzen und in `tests/updates/translation-coverage.json` ergänzen. Bisherige Einträge bleiben erhalten. Deutsche Texte stehen in `l10n/de.json` und `l10n/de_DE.json`; die gleichnamigen JS-Dateien enthalten dieselben Übersetzungen für den Browser. JSON- und JS-Dateien werden gemeinsam gepflegt.
4. Die geänderten Funktionen ausprobieren. Anschließend im Hauptordner des Repositorys prüfen:

   ```powershell
   npm run test:javascript
   npm run check:javascript
   ```

   Bei PHP-Änderungen kommen die passenden PHP-Prüfungen aus Abschnitt 5 hinzu. Versionsnummer und Änderungsliste werden auf Übereinstimmung geprüft.

5. Die neue Version in der laufenden lokalen Docker-Demo aktivieren. Voraussetzung ist, dass deren Timesheet-Ordner mit dem geänderten Repository verbunden ist:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ upgrade --no-interaction
   ```

   Danach die installierte App-Version und den Zustand der Demo prüfen:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ config:app:get timesheet installed_version
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ status
   ```

   Die erste Ausgabe nennt im Beispiel die App-Version `1.3.1`. Die zweite beschreibt den Zustand von Nextcloud und zeigt `maintenance: false` und `needsDbUpgrade: false`.

6. Den Arbeitszeitnachweis mit einem Mitarbeiter- oder HR-Konto erneut öffnen. Der neue Hinweis erscheint einmal je Konto; Administratorkonten bleiben ausgeschlossen.

Die Befehle verwenden den Namen des aktuellen Demo-Containers. Bei einer anderen Installation gilt deren eigener Container- beziehungsweise Serverzugang. Für `1.4.0` gilt derselbe Ablauf mit entsprechend angepasster Versionsnummer.

Zum erneuten Lesen öffnet `http://localhost:8133/apps/timesheet/?update-notes=1` die aktuelle Liste. Dieser Aufruf erhöht keine Version und erzeugt keine neue Ankündigung. Ein Commit, Push oder Merge allein aktualisiert keine installierte App.

`UpdateNotesService` erstellt den Glockenhinweis beim App-Aufruf. `UpdateNotesStore` speichert angezeigte und entfernte Versionen getrennt im Benutzerkonto. Neu angelegte Konten werden bei der Kontenerstellung vorgemerkt. Ältere Konten ohne Lesestand erhalten auch die Änderungen übersprungener Versionen. `UpdateNotesNotifier` verarbeitet die Anzeige und die Löschfunktionen der Nextcloud-Glocke. Dafür entsteht keine neue Datenbanktabelle. Die Aufrufe `POST /api/update-notes` und `POST /api/update-notes/shown` gelten ausschließlich für das angemeldete Konto. Administratorkonten sind dort ebenfalls ausgeschlossen.

Für Push-Mitteilungen und Benachrichtigungs-Sammelmails gelten die bestehenden Nextcloud-Einstellungen. Die App verschickt dafür keine eigenen E-Mails. Ein Fehler beim Laden oder Speichern der Hinweise blockiert die Zeiterfassung nicht.

Der Probelauf verwendet eine getrennte Nextcloud-Testinstanz. `tests/updates/integration.php` startet nur mit `UPDATE_TEST_ENVIRONMENT=1`, der Datenbank `timesheet_updates_test` und einem gesetzten `UPDATE_TEST_PASSWORD`. Er legt künstliche Konten an und prüft die native Glocke. Die normale Demo und produktive Konten gehören nicht in diesen Testlauf.

### Geführte Tour

Das Fragezeichen im Kreis zwischen Suche und Glocke öffnet das Menü **Hilfe und Tour** für Mitarbeitende ohne HR- oder Administrationsrechte. Es enthält **Tourguide**, **Fragen an HR** und **Verbesserungswünsche**. Für HR- und Administratorkonten werden weder Symbol noch Einladung angezeigt; auch ein direkter Abschlussaufruf ist dort gesperrt. `js/timesheet-tour.js` enthält Ablauf und Erklärungen; `js/timesheet-tour-header.js` verbindet das Symbol mit der Nextcloud-Kopfzeile und verwaltet das aufklappbare Menü samt Tastaturbedienung und Mail-Links. Die Darstellung liegt in `css/timesheet-tour.css`. Fehlt die erwartete Kopfzeile, erscheint der Zugang innerhalb von Timesheet.

`js/timesheet-header.js` ergänzt innerhalb der App Maus-Hinweise für Kontakte und das Profilmenü. Verwendet werden die bereits übersetzten Nextcloud-Beschriftungen. Vorhandene Hinweise bleiben erhalten; auch später geladene Menüs werden berücksichtigt. Diese Ergänzung gilt für alle Konten und ist unabhängig von der Tour. Nextcloud-Dateien werden nicht verändert.

Die Tour wird nach dem Laden der App angeboten. **Später** verschiebt die Einladung bis zum nächsten Aufruf. Ein Abschluss wird je Konto gespeichert. `TourController` nimmt den Abschluss entgegen; `lib/Db/TourProgressStore.php` verwendet dafür ausschließlich `tour_employee_version`. Bereits abgeschlossene Mitarbeitertouren bleiben abgeschlossen. Die frühere HR-Erweiterung ist entfernt. Erhält ein Konto HR-Rechte, entfällt nach dem Neuladen der Tourzugang. Der Menüpunkt **Tourguide** startet die acht Schritte für Mitarbeitende erneut. Die Einladung beim ersten Besuch öffnet die Tour weiterhin direkt über **Tutorial**.

Die acht Schritte behandeln Export, Arbeitszeit, Bundesland, Kommentare, Warnungen, Profil, allgemeine Nextcloud-Hilfe und das Toursymbol. Beim Export erhalten die Schaltfläche und die geöffnete Monats-/Jahresauswahl eine gemeinsame, durchgehende Hervorhebung. Der Arbeitszeitschritt hebt das Feld **Tägliche Arbeitszeit** hervor und verweist auf den individuell nach Unternehmensrichtlinien wählbaren Arbeitsbeginn. Dieser Hinweis verändert keine Eingaben oder Zeitregeln. Der Bundeslandschritt erklärt den Bezug zu den Feiertagen in der Spalte **Status**. Der Kommentarschritt zeigt die Abwesenheitsbegriffe in der Kontosprache, ohne eine HR-Freigabe zu beschreiben.

Anschließend hebt der Schritt **Warnungen** das Warnungsfeld mit seiner Spaltenüberschrift hervor. Er nennt Pausenabweichungen und fehlende Arbeitszeitnachweise und weist auf deren zeitnahe Korrektur hin. Die bestehende Warnungsprüfung wird dadurch nicht verändert. Der letzte Schritt trägt die Überschrift **Hilfe und Tour**. Sein Text nennt Fragen und Verbesserungsvorschläge an HR sowie den Neustart über das Toursymbol.

**Fragen an HR** und **Verbesserungswünsche** öffnen über `mailto:` eine neue Nachricht im eingestellten Mailprogramm. Beide verwenden `karriere@xiller.com`. Der Betreff lautet **Frage zum Arbeitszeitnachweis** beziehungsweise **Feedback für Arbeitszeitnachweis**. Diese Betreffzeilen bleiben unabhängig von der Kontosprache auf Deutsch. Der Nachrichtentext ist leer. Der Versand erfolgt im Mailprogramm; die App speichert oder versendet diese Nachrichten nicht.

Nach jedem Klick erscheint zuerst die Rückmeldung **Standard-Mailprogramm wird aufgerufen.**. Darunter steht der bedingte Hinweis **Kein Mailprogramm geöffnet?**. Er erklärt die erforderliche Auswahl eines Standard-Mailprogramms. Empfänger und Betreff erscheinen ausschließlich in der vom Mailprogramm geöffneten Nachricht. Der Browser meldet keinen verlässlichen Erfolg oder Fehler des externen Mailprogramms zurück; ein fehlendes Programm wird deshalb nicht automatisch behauptet. Der Hinweis bleibt bei einem Fokuswechsel erhalten und lässt sich schließen. Er wird beim erneuten Öffnen des Menüs oder beim Tourstart entfernt. Es gibt keine Erkennung über Zeitverzögerung oder Fensterfokus.

Erklärungen verwenden die vorhandene Mitarbeiteransicht und öffnen bei Bedarf Menüs. Zeiteinträge und Arbeitszeiteinstellungen werden nicht verändert; Downloads werden nicht gestartet. Die Tour ruft keine HR-Daten ab. Nach ihrem Ende wird die zuvor geöffnete Ansicht wiederhergestellt. **Hilfe & Datenschutz** führt zur allgemeinen Nextcloud-Hilfe.

Die Kontosprache bestimmt die Tourtexte. Änderungen an Erklärungen werden in allen zugehörigen Sprachdateien gepflegt. Die Vollständigkeitsprüfung unter `tests/tour/` kontrolliert Texte, Platzhalter und die Übereinstimmung von JSON- und JS-Dateien. Sie ersetzt keine sprachliche Prüfung durch Muttersprachler. Zusätzliche Tourprüfungen laufen mit `npm run test:tour`; der getrennte PHP-Aufruf lautet `php tests/tour/progress.php`.

### Erinnerungen für einzelne offene Tage

Unter **Profil → Einstellungen → Arbeitszeitnachweis** enthält jede Gruppenregel den Bereich **Erinnerungen an fehlende Zeiten**. Die Funktion ist nach der Installation ausgeschaltet. Einstellbar sind die Wartefrist von 1 bis 365 Kalendertagen (Voreinstellung 14), die erwarteten Wochentage (Voreinstellung Montag bis Freitag) und der Prüfstart. Beim ersten Einschalten wird das aktuelle Datum vorgeschlagen; ein rückwirkender Beginn ist möglich.

Die tägliche Prüfung findet auch einzelne ältere Lücken, wenn danach bereits weitere Zeiten eingetragen wurden. Fehlende Start- oder Endzeiten werden ebenfalls gemeldet. Bei Tagen ohne beide Zeitangaben können festgelegte Abwesenheitskommentare eine Erinnerung verhindern. HR-Hinweis und Tour zeigen die Begriffe in der Kontosprache: auf Deutsch **Urlaub** und **Stundenausgleich**, auf Englisch **Vacation** und **Time off in lieu**. Die Bezeichnungen aller unterstützten Sprachen stehen gemeinsam in `lib/Service/absence-comment-terms.json`. `AbsenceCommentCatalog` stellt sie für Anzeige und Prüfung bereit. Die Prüfung erkennt die Begriffe aller dieser Sprachen, damit ein Sprachwechsel oder eine andere HR-Sprache bereits gespeicherte Einträge nicht ungültig macht. Für unbekannte Kontosprachen erscheinen die englischen Begriffe. Groß-/Kleinschreibung und Leerzeichen am Anfang oder Ende werden ignoriert. Andere Kommentare, Zusätze oder Satzzeichen genügen nicht. Bei nur einer Zeitangabe bleibt die Erinnerung auch mit einem erlaubten Kommentar bestehen. Die Prüfung verändert keine gespeicherten Kommentare.

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
php tests/settings/hr-settings.php
php tests/employment/status-store.php
php tests/employment/controller-status.php
php tests/updates/backend.php
```

`tests/reminders/integration.php` prüft den gesamten Ablauf einschließlich Versand in einer eigenen Nextcloud-Testinstanz. Er erzeugt Testkonten und Einträge und ersetzt dort die Gruppenregeln. Deshalb startet er ausschließlich mit `REMINDER_TEST_ENVIRONMENT=1`, der Datenbank `timesheet_reminders_test`, einem gesetzten `REMINDER_TEST_PASSWORD` und Mailpit als Versandziel. Er gehört nicht in die normale Demo oder eine produktive Installation. Der vorhandene PHPUnit-Aufbau bleibt davon getrennt.

`tests/employment/integration.php` erweitert denselben geschützten Testlauf um Freelancer, Inaktiv-Zeiten, Rückkehrfristen und beide Mailwege. Dabei entstehen ausschließlich künstliche `employment-*`-Konten. Die JavaScript-Prüfung erfasst außerdem Filter, Namenssuche, Summen sowie das Verhalten bei Speicherfehlern und gleichzeitigen Änderungen.

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

Ein Merge allein installiert keine App. Für den Livebetrieb aktualisiert die zuständige Nextcloud-Administration das geprüfte App-Paket zentral auf der Firmeninstanz. Eine interne Installation ist auch ohne Veröffentlichung im App Store möglich. Bei Verteilung über den App Store wird zuerst die neue Version veröffentlicht und anschließend unter [Apps in der Firmen-Nextcloud](https://docs.nextcloud.com/server/33/admin_manual/apps_management.html#managing-apps) aktualisiert. Mitarbeitende verwenden danach die aktualisierte App im Browser; ein eigener Download ist nicht erforderlich. Der genaue Installationsweg der Firmeninstanz ist noch festzulegen.

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
| `templates/` | Structure of the app page and HR settings page. |
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
| Update notes | `UpdateNotesService` connects the release notes and account state. `UpdateNotesNotifier` integrates with the notification bell. The popup lives in `js/timesheet-update-notes.js`. |
| Freelancer and activity status | `js/timesheet-employment-status.js` handles both controls. `EmploymentStatusController` checks access; `EmploymentStatusStore` saves flags and periods. `EmploymentStatus` combines excluded dates and the reminder reference date. |
| Settings | `ConfigController` receives personal settings; `lib/Db/HrPreferencesStore.php` reads and saves HR email and warning preferences. Group rules belong to `SettingsController`, `templates/settings-hr.php` and `js/hr-settings.js`. |
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

### HR settings

The complete **Timesheet** section is available under **Profile → Settings**, in the **Personal** section. It appears only for accounts with HR responsibilities. HR manages HR and employee group assignments, priorities, break and working-time rules, reminders, and the special-days check there. An administrator account without an HR role has no access. The previous entry under **Administration** is removed.

`lib/Settings/HrSettings.php` loads the form; `HrSection.php` names the menu section. `templates/settings-hr.php` and `js/hr-settings.js` provide the interface. `SettingsController` checks the HR role for every write as well. Existing settings and save URLs are retained. Time recording can still read the special-days check.

The HR role comes from the HR groups stored in `hr_access_rules`. Saving requires at least one existing HR group to remain assigned. Group membership is managed in Nextcloud's user administration. If a new installation has no HR group assigned yet, or all assigned groups have been deleted outside the app, the person responsible for Nextcloud first sets up an existing group with an HR account. That group is then assigned to a rule with Nextcloud's `occ config:app:set timesheet hr_access_rules` administration command. Each rule needs a unique `id`, `hrGroups`, `userGroups` and `priority`; other values use the existing defaults. Existing rules are backed up first and updated selectively. The command replaces the entire stored rule value. There is no additional administrator bypass for the form.

### Personal break display

The **Break: Minutes – Hours** switch saves its selection automatically in the signed-in Nextcloud account. The selection survives reloads and signing in again and applies to both personal entries and the HR view. When viewing another employee, the viewer’s display preference applies. Stored break values and calculations remain unchanged.

### Freelancer mode, activity status and HR filters

**Freelancer mode: Off – On** appears below **Break: Minutes – Hours** for ordinary employee accounts. It changes only the signed-in account, defaults to **Off**, and survives reloads and signing in again. HR and administrator accounts cannot change their own freelancer mode. Responsible HR can set an assigned employee to **Active** or **Inactive** in the individual view. Existing employees default to active. Freelancer mode is read-only there. Activity status does not change the Nextcloud account, login access or groups.

**Filter**, above the right side of the HR table, offers **Active employees**, **Inactive employees**, **All employees**, **Freelancers**, and a search across full display names. Search and selection apply together, within the existing HR responsibility. Freelancers may be active or inactive. Under **All**, both a background tint and a badge identify freelancers. Totals follow the visible people. Filters survive a visit to the individual view; a full page reload resets them to active employees and an empty search.

Freelancers receive no missing or incomplete time reminders, and those notices are also excluded from HR summaries. Positive and negative balance warnings still apply. No warning emails concern inactive employees. Inactive HR accounts receive no warning emails either. Active HR in freelancer mode still receives notices about other assigned employees. The list hides missing-time warnings for current freelancers and inactive employees while retaining other warnings and balances.

Each change takes effect on the current Berlin calendar date. Enabling starts an exemption including that day; disabling or reactivation makes that day regular again. Stored freelancer and inactive periods remain permanently excluded from daily checks. Older gaps outside those periods remain eligible. After return, the legacy **no entry for some time** reminder starts a new full delay from the later of the last entry and the exemption end. Delivery cooldowns are preserved. Entries and balances remain unchanged.

The shared per-account preference `employment_status` holds both flags and their separate period histories; no database table is required. `EmploymentStatusStore` uses a shared revision check and write lock to prevent concurrent updates from overwriting each other. Protected endpoints are `PUT /api/preferences/freelancer-mode` for the signed-in account and `PUT /api/hr/employees/{userId}/activity` for responsible HR. The break display remains separate. Errors are visible, and unreadable status never silently re-enables reminders. The tour retains its eight steps.

### Update notes

After an update, a short list of changes appears on the next app visit. Employee and HR accounts receive these notices. Administrator accounts are excluded, including accounts that also have HR access.

The popup opens once per account and version. Closing it leaves the notes available through the notification bell. The bell shows the version announcement and a short **Open the update notes** message. Selecting it opens the complete list in the popup. **Dismiss all notifications** removes the update notice along with the account's other bell notifications. Later updates create a new notice. Several missed versions appear together. New accounts see only the current version. The introduction is offered after the update popup closes.

Version numbers are increased manually for a new, verified release. Correcting text without changing the version does not trigger a new notice.

#### Prepare and activate a new update

1. Increase the version in `appinfo/info.xml`, for example to `<version>1.3.1</version>`.
2. Add a new block with `"version": "1.3.1"` and the corresponding changes to `appinfo/release-notes.json`. Keep earlier version blocks.
3. Translate new English message keys in the existing language files and add them to `tests/updates/translation-coverage.json`. Keep existing entries. German wording lives in `l10n/de.json` and `l10n/de_DE.json`; the matching JS files contain the same browser translations. Maintain JSON and JS files together.
4. Try the changed features in the app. Then run these checks from the repository root:

   ```powershell
   npm run test:javascript
   npm run check:javascript
   ```

   PHP changes also require the relevant PHP checks from section 5. Check that the version number matches the release notes.

5. Activate the new version in the running local Docker demo. Its Timesheet directory must be connected to the changed repository:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ upgrade --no-interaction
   ```

   Then check the installed app version and the demo status:

   ```powershell
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ config:app:get timesheet installed_version
   docker exec --user www-data timesheet-handover-nextcloud-1 php /var/www/html/occ status
   ```

   The first command reports app version `1.3.1` in this example. The second describes the Nextcloud status and shows `maintenance: false` and `needsDbUpgrade: false`.

6. Reopen Timesheet with an employee or HR account. The new notice appears once per account; administrator accounts remain excluded.

These commands use the current demo container name. Other installations use their own container or server access. The same procedure applies to `1.4.0` with the version number changed accordingly.

To read the current notes again, open `http://localhost:8133/apps/timesheet/?update-notes=1`. This does not increase the version or create a new announcement. A commit, push or merge alone does not update an installed app.

`UpdateNotesService` creates the bell notice when the app opens. `UpdateNotesStore` keeps displayed and dismissed versions separate in account preferences. Newly created accounts receive a marker at account creation. Older accounts without saved progress also receive notes from skipped releases. `UpdateNotesNotifier` handles display and dismissal in the native Nextcloud bell. No database table is required. `POST /api/update-notes` and `POST /api/update-notes/shown` apply only to the signed-in account. Administrator accounts are excluded from both calls.

Existing Nextcloud settings govern device push and notification digest emails. The app does not send separate emails for update notes. Loading or saving failures do not block time entry.

Verification uses a separate Nextcloud test instance. `tests/updates/integration.php` runs only with `UPDATE_TEST_ENVIRONMENT=1`, database `timesheet_updates_test`, and a configured `UPDATE_TEST_PASSWORD`. It creates synthetic accounts and checks the native bell. It does not belong in the normal demo or a production installation.

### Guided tour

The question mark in a circle between search and notifications opens the **Help and tour** menu for employees without HR or administrator privileges. It contains **Tour guide**, **Questions for HR** and **Suggestions for improvement**. HR and administrator accounts receive neither the button nor an invitation, and direct completion requests are rejected. `js/timesheet-tour.js` contains its flow and explanations; `js/timesheet-tour-header.js` connects the button to the Nextcloud header and manages the dropdown, keyboard controls and email links. Styling lives in `css/timesheet-tour.css`. A visible entry point inside Timesheet is used if the expected header is unavailable.

`js/timesheet-header.js` adds hover hints to the contacts and profile menus within the app. It reuses Nextcloud's translated labels, preserves existing hints and handles menus rendered later. This addition applies to every account independently of the tour. Nextcloud files are not changed.

The tour is offered after the app has loaded. **Later** defers the invitation until the next visit. Completion is stored per account. `TourController` accepts completion; `lib/Db/TourProgressStore.php` uses only `tour_employee_version`. Completed employee tours remain completed. The former HR extension has been removed. When an account receives HR privileges, the tour entry point disappears after reloading. Selecting **Tour guide** repeats its eight employee steps. The first-visit invitation still opens the tour directly through **Tutorial**.

The eight steps cover export, working time, federal state, comments, warnings, profile, general Nextcloud help and the tour icon. The export button and its open month/year choices receive one continuous highlight. The working-time step highlights **Daily working time** and explains that starting work can be chosen individually in accordance with company guidelines. This explanation does not change inputs or time rules. The federal-state step explains its effect on public holidays in the **Status** column. The comments step shows absence terms in the account language without describing HR approval.

The following warnings step highlights the warning cell and its column heading. It explains that break deviations and missing timesheets require prompt correction. Existing warning detection remains unchanged. The final step is titled **Help and tour**. Its text mentions questions and suggestions for HR and restarting the guide using the tour icon.

**Questions for HR** and **Suggestions for improvement** use `mailto:` to open a new message in the configured email program. Both use `karriere@xiller.com`. The respective subjects are **Frage zum Arbeitszeitnachweis** and **Feedback für Arbeitszeitnachweis**. These subject lines remain in German regardless of account language. The message body is empty. Sending takes place in the email program; the app neither stores nor sends these messages.

After each click, **Opening the default mail app…** confirms the invocation attempt. Conditional help titled **No mail app opened?** explains that a default mail app is required. The recipient and subject appear only in the message opened by the mail app. The browser provides no reliable success or failure callback for the external mail app, so the interface does not claim to detect a missing application. Focus changes do not dismiss the help; it can be closed explicitly and is removed when the menu reopens or a tour starts. No timeout or focus-based detection is used.

Explanations use the existing employee view and open menus when needed. Time entries and working-time settings are not changed, and downloads are not started. The tour does not request HR data. It restores the previous view when it ends. **Help & privacy** leads to general Nextcloud help.

The account language determines the tour text. Changes to explanations are maintained in all corresponding language files. Coverage checks under `tests/tour/` verify messages, placeholders and matching JSON/JS catalogs. They do not replace linguistic review by native speakers. Tour checks run with `npm run test:tour`; the separate PHP command is `php tests/tour/progress.php`.

### Reminders for individual open dates

Under **Profile → Settings → Timesheet**, each group rule has a **Missing time entry reminders** section. The feature is disabled after installation. Settings cover a delay from 1 to 365 calendar days (default 14), expected weekdays (default Monday to Friday), and the date from which entries are checked. The current date is suggested on first enablement; an earlier start can be chosen.

The daily check finds individual older gaps even when later dates already have entries. A missing start or end time also triggers a reminder. When both times are absent, fixed absence comments can prevent a reminder. HR guidance and the tour show these terms in the account language: **Vacation** and **Time off in lieu** in English, **Urlaub** and **Stundenausgleich** in German. Terms for every supported language are maintained in `lib/Service/absence-comment-terms.json`. `AbsenceCommentCatalog` supplies both display and detection. Detection accepts the listed terms from all these languages, so changing language or viewing entries in another HR language does not invalidate saved comments. Unknown account languages use the English terms. Letter case and surrounding spaces are ignored. Other comments, additional words or punctuation do not qualify. An entry with only one time remains incomplete even with an accepted comment. Detection never rewrites saved comments.

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
php tests/settings/hr-settings.php
php tests/employment/status-store.php
php tests/employment/controller-status.php
php tests/updates/backend.php
```

`tests/reminders/integration.php` checks the full workflow and delivery in a dedicated Nextcloud test instance. It creates synthetic accounts and entries and replaces that instance's group rules. It therefore runs only with `REMINDER_TEST_ENVIRONMENT=1`, database `timesheet_reminders_test`, a configured `REMINDER_TEST_PASSWORD`, and Mailpit as the mail destination. It does not belong in the normal demo or a production installation. The existing PHPUnit setup remains separate.

`tests/employment/integration.php` extends that same guarded runner with freelancer and inactive periods, return delays, and both email paths. It creates only synthetic `employment-*` accounts. JavaScript checks also cover filters, name search, totals, save failures and concurrent changes.

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

A merge alone does not install an app. For live use, the responsible Nextcloud administrator updates the verified app package centrally on the company instance. Internal installation is possible without an App Store release. When distributing through the App Store, the new version is published first and then updated under [Apps in the company Nextcloud](https://docs.nextcloud.com/server/33/admin_manual/apps_management.html#managing-apps). Employees then use the updated app in their browser; individual downloads are not required. The company's exact installation procedure remains to be established.

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
