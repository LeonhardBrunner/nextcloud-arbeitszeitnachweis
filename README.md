# Nextcloud Timesheet (XiLLeR GmbH)

<details open>
<summary>Deutsch</summary>

## Was macht die App?

Mit Timesheet erfassen Mitarbeitende ihre Arbeitszeiten direkt in Nextcloud. Sie tragen Arbeitsbeginn, Arbeitsende und Pausen ein. Die App berechnet daraus die Arbeitszeit und zeigt den Stundenstand an.

Die Personalabteilung (HR) erhält eine Übersicht über Mitarbeitende und ihre erfassten Zeiten. Arbeitszeitnachweise lassen sich als Exceldatei herunterladen und weiterverarbeiten.

Dieses Projekt baut auf [Timesheet 1.2.0 von Repa1253](https://github.com/Repa1253/timesheet/releases/tag/1.2.0) auf und wird für die XiLLeR GmbH weitergeführt. Der ursprüngliche Entwickler ist David Gun (Repa1253).

## Funktionen

### Für Mitarbeitende

- Pro Tag einen Eintrag mit Beginn, Ende, gesamter Pausendauer und Kommentar anlegen, ändern oder löschen.
- Unvollständige Einträge und reine Kommentare speichern, um Angaben später zu ergänzen.
- Erfasste Arbeitszeit, Tagesdifferenz und Stundenstände in der Monatsübersicht ansehen.
- Arbeitszeitnachweise für einen Monat oder ein Jahr als Exceldatei (XLSX) exportieren.
- Hinweise sehen, etwa bei zu kurzen Pausen oder zu langen Arbeitstagen.

So können Mitarbeitende ihre Einträge selbst pflegen und nachvollziehen, wie ihre erfassten Zeiten berechnet werden.

### Für HR

- Eine Übersicht mit Stundenständen, letzten vollständigen Einträgen und Warnhinweisen öffnen.
- Einträge von Mitarbeitenden ansehen, bearbeiten und exportieren.
- Tagesziele und die Feiertagsregion pro Person einstellen.
- Optionale E-Mail-Hinweise zu fehlenden Einträgen und überschrittenen Saldo-Schwellen erhalten.

Die Übersicht hilft HR, auffällige Einträge zu finden und die erfassten Zeiten gesammelt durchzugehen. Der Excelexport liefert Dateien für die weitere Bearbeitung; eine direkte Anbindung an eine Lohnsoftware ist nicht enthalten.

### Einstellungen und Grenzen

Administrierende ordnen HR-Gruppen den Mitarbeitergruppen zu und legen Zeitregeln und deren Prioritäten fest. Persönliche Einstellungen betreffen unter anderem Tagesziel, Feiertagsregion und HR-Benachrichtigungen.

Die App verwendet ein einheitliches Tagesziel pro Person. Für die Sollzeitberechnung zählen nur vollständige Zeiteinträge: Leere Tage und reine Kommentare erzeugen nicht automatisch Minusstunden. Pausen werden als Gesamtdauer pro Tag erfasst.

Feiertage werden für deutsche Bundesländer unterstützt. E-Mail-Hinweise gehen derzeit an HR und benötigen eingerichteten Nextcloud-Mailversand sowie laufende Hintergrundaufgaben. Die Zeitregeln zeigen Hinweise an; ein Ablauf für verbindliche HR-Freigaben ist nicht enthalten.

## Voraussetzungen

Für Version 1.2.0 ist in den [App-Metadaten](appinfo/info.xml) folgende Kompatibilität vorgesehen:

- Nextcloud 30–33
- PHP 8.2–8.5

Das sind die deklarierten Versionsgrenzen. Sie sind kein Nachweis, dass jede Kombination praktisch getestet wurde. Timesheet läuft innerhalb einer eingerichteten Nextcloud-Installation.

Für Feiertagsdaten benötigt Nextcloud ausgehenden HTTPS-Zugriff auf `get.api-feiertage.de`, sofern keine gültigen zwischengespeicherten Daten vorhanden sind.

## Installation und Weiterentwicklung

Die [technische Anleitung](docs/TECHNICAL_GUIDE.md) erklärt den Aufbau, die Werkzeuge und das Ausprobieren eigener Änderungen in einer lokalen Demo. Dort stehen auch die noch offenen Fragen zu Installation, Tests und Veröffentlichung.

## Lizenz

Die App steht unter **AGPL-3.0-or-later**. Der vollständige Lizenztext steht in [LICENSE](LICENSE).

</details>

<details>
<summary>English</summary>

## What does the app do?

Timesheet lets employees record their working hours directly in Nextcloud. They enter their start time, end time and breaks. The app calculates working time and displays their balance.

Human Resources (HR) gets an overview of employees and their recorded hours. Timesheets can be downloaded as Excel files for further processing.

This project builds on [Timesheet 1.2.0 by Repa1253](https://github.com/Repa1253/timesheet/releases/tag/1.2.0) and is being continued for XiLLeR GmbH. The original developer is David Gun (Repa1253).

## Features

### For employees

- Create, edit or delete one entry per day with a start time, end time, total break duration and comment.
- Save incomplete entries or comments on their own and add missing details later.
- View recorded working time, daily differences and balances in the monthly overview.
- Export timesheets for a month or a year as Excel files (XLSX).
- See warnings, for example about short breaks or long working days.

Employees can maintain their own entries and see how their recorded hours are calculated.

### For HR

- Open an overview of balances, last complete entries and warnings.
- View, edit and export employee entries.
- Set each person's daily target and public holiday region.
- Receive optional email notifications about missing entries and exceeded balance thresholds.

The overview helps HR find entries that need attention and review recorded hours together. Excel exports provide files for further processing; there is no direct integration with payroll software.

### Settings and limitations

Administrators assign HR groups to employee groups and configure time rules and their priorities. Personal settings include the daily target, public holiday region and HR notifications.

The app uses a uniform daily target for each person. Only complete time entries count towards target hours: empty days and comments on their own do not automatically create negative hours. Breaks are recorded as a total duration per day.

Public holidays are supported for German federal states. Email notifications currently go to HR and require configured Nextcloud mail delivery and running background jobs. Time rules display warnings; a binding HR approval workflow is not included.

## Requirements

The [app metadata](appinfo/info.xml) declares the following compatibility for version 1.2.0:

- Nextcloud 30–33
- PHP 8.2–8.5

These are declared version limits, not evidence that every combination has been tested. Timesheet runs inside a configured Nextcloud installation.

For public holiday data, Nextcloud needs outbound HTTPS access to `get.api-feiertage.de` unless valid cached data is available.

## Installation and development

The [technical guide](docs/TECHNICAL_GUIDE.md) explains the project structure, tools and how changes can be tested in a local demo. It also lists unresolved questions about installation, testing and publishing.

## License

The app is licensed under **AGPL-3.0-or-later**. See [LICENSE](LICENSE) for the full license text.

</details>
