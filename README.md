# Pretix Eventlister

WordPress-Plugin zur Darstellung von pretix-Events als moderne, responsive Kartenansicht.

## Installation

1. Den Ordner `pretix-eventlister` in `wp-content/plugins/` kopieren.
2. Das Plugin in WordPress aktivieren.
3. Unter `Einstellungen > Pretix Eventlister` folgende Werte eintragen:
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

## Versionierung

Das Plugin verwendet Semantic Versioning im Schema `major.minor.patch`.
Die Release-Historie findest du in `CHANGELOG.md`.
