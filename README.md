# Pretix Eventlister

WordPress-Plugin zur Darstellung von pretix-Events als moderne, responsive Kartenansicht.

## Installation

1. Fuer WordPress immer die Release-Datei `pretix-eventlister-x.y.z.zip` aus dem GitHub-Release verwenden.
2. Nicht die GitHub-Datei `Source code (zip)` installieren, da diese fuer Entwickler gedacht ist.
3. Das ZIP in WordPress unter `Plugins > Installieren > Plugin hochladen` importieren.
4. Das Plugin in WordPress aktivieren.
5. Unter `Einstellungen > Pretix Eventlister` folgende Werte eintragen:
   - pretix Basis-URL
   - optional Standard-Veranstalter
   - API-Token
   - Cache-Dauer
   - optional Organizer mit HSP-Hinweis

## Nutzung

Standard-Shortcode:

```text
[pretix_events]
```

Beispiele:

```text
[pretix_events limit="6"]
[pretix_events scope="all" limit="all"]
[pretix_events style="list"]
[pretix_events organizer="mein-organizer" show_description="no"]
[pretix_events organizers="veranstalter-a,veranstalter-b"]
```

## Shortcode-Optionen

- `limit`: Anzahl der angezeigten Events oder `all`
- `scope`: `selected` oder `all`
- `organizer`: optionaler Organizer-Slug fuer diese Ausgabe
- `organizers`: mehrere Organizer-Slugs, durch Komma getrennt
- `style`: `grid` oder `list`
- `show_description`: `yes` oder `no`
- `show_organizer`: `yes` oder `no`

## HSP-Hinweis

In den Plugin-Einstellungen kannst du Organizer definieren, bei denen automatisch ein Hinweis auf jeder Event-Karte angezeigt wird, dass HSP-Events nur die Plattform bzw. Ticketinfrastruktur bereitstellt.

## Updates ueber GitHub

Das Plugin kann Updates direkt aus dem GitHub-Repository beziehen.
Sobald ein neues GitHub-Release mit passender ZIP-Datei veroeffentlicht wird, erkennt WordPress die neue Version automatisch im Plugin-Updater.
Die Installations- und Update-Routine normalisiert den Plugin-Ordner dabei auf `pretix-eventlister`, damit Aktivierungslinks stabil bleiben.

## Versionierung

Das Plugin verwendet Semantic Versioning im Schema `major.minor.patch`.
Die Release-Historie findest du in `CHANGELOG.md`.
