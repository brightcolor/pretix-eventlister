# Changelog

Alle relevanten Aenderungen an diesem Plugin werden hier nach Semantic Versioning (`major.minor.patch`) dokumentiert.

## [1.2.7] - 2026-04-16

- Release-Paket fuer WordPress neu gebaut, um Installationsprobleme durch Archiv-Metadaten zu vermeiden.

## [1.2.6] - 2026-04-16

- Hinweis auf Eventkarten hinzugefuegt, in wie vielen Tagen ein Event beginnt.

## [1.2.5] - 2026-04-16

- Eventbilder robuster aus pretix-Datenfeldern aufgeloest.
- Eventbeschreibungen werden jetzt als HTML ausgegeben statt auf reinen Text reduziert.
- Markdown in Eventbeschreibungen wird vor der Ausgabe in HTML umgewandelt.

## [1.2.4] - 2026-04-16

- Paket-Normalisierung fuer WordPress-Updates deutlich abgesichert, damit bereits korrekt benannte Plugin-Ordner nicht mehr unnoetig umgebogen werden.
- Release-ZIP um `export-ignore` bereinigt, damit Entwicklungsdateien nicht mehr im Installationspaket landen.

## [1.2.3] - 2026-04-16

- Author auf Bright Color mit GitHub-Repository-Link umgestellt.
- Plugin-Icon fuer WordPress-Plugin-Details und die Plugin-Uebersicht hinzugefuegt.
- GitHub-Repository und Changelog in der Plugin-Uebersicht verlinkt.

## [1.2.2] - 2026-04-16

- Hinweis zu GitHub-Updates von der Plugin-Einstellungsseite entfernt.

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
