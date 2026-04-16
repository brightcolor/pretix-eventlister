# Changelog

Alle relevanten Aenderungen an diesem Plugin werden hier nach Semantic Versioning (`major.minor.patch`) dokumentiert.

## [1.2.1] - 2026-04-16

- Installations- und Updatepakete werden nun auf den festen Plugin-Ordner `pretix-eventlister` normalisiert.
- Aktivierungsfehler wie `Diese Plugindatei existiert nicht` nach GitHub- oder ZIP-Installationen abgesichert.
- Installationshinweise in der README um den korrekten WordPress-Download aus dem Release erweitert.

## [1.2.0] - 2026-04-16

- GitHub-basierte Update-Routine fuer WordPress integriert.
- Plugin-Informationen fuer den WordPress-Updater auf GitHub-Releases umgestellt.
- Release-Cache fuer Update-Pruefungen hinzugefuegt.
- Dokumentation fuer Updates ueber GitHub ergaenzt.

## [1.1.1] - 2026-04-16

- `CHANGELOG.md` zum Plugin hinzugefuegt.
- Versionierung im Code auf `1.1.1` angehoben.
- Asset-Versionen auf eine zentrale Versionskonstante umgestellt.
- Release-ZIP fuer diese Version erstellt.

## [1.1.0] - 2026-04-16

- Modernes Frontend mit Hero-Bereich, Summary-Chips und hochwertiger Kartenansicht umgesetzt.
- Support fuer alle Veranstalter, einen Veranstalter oder mehrere Veranstalter hinzugefuegt.
- HSP-Hinweislogik fuer ausgewaehlte Partner-Veranstalter integriert.
- API-Abfrage um Organizer-Index, Pagination und robustere Event-Normalisierung erweitert.

## [1.0.0] - 2026-04-16

- Erstes Release des Plugins.
- pretix-Anbindung mit API-Token und Organizer-Slug umgesetzt.
- Responsive Event-Ausgabe per Shortcode `[pretix_events]` eingefuehrt.
