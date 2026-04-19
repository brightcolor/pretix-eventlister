# Pretix Eventlister

WordPress plugin for displaying pretix events in a modern, responsive event card layout.

## New in 2.0.0

- Feature-rich **Layout Composer** tab in backend settings.
- Drag-and-drop ordering of event card blocks (`schedule`, `body`, `platform_note`, `footer`).
- Per-block style controls for spacing, typography, colors, border, visibility, alignment, and shadow.
- Frontend application of custom composer layout and styles to each event card.

## Installation

1. Always use the release ZIP file `pretix-eventlister-x.y.z.zip` from GitHub Releases.
2. Do not install GitHub's `Source code (zip)` file, as it is for development only.
3. In WordPress, go to `Plugins > Add New > Upload Plugin` and upload the ZIP.
4. Activate the plugin.
5. Go to `Settings > Pretix Eventlister` and configure:
   - pretix base URL
   - optional default organizers
   - API token
   - cache TTL
   - optional partner notice organizers

Expected plugin path after installation/update:
- `wp-content/plugins/pretix-eventlister/pretix-eventlister.php`

## Usage

Default shortcode:

```text
[pretix_events]
```

Block editor alternative: insert the `Pretix Events` block (Widgets category).

If CPT sync is enabled, each synced event can be manually overridden in WordPress without being overwritten by the next pretix sync.
The admin settings page is organized in tabs, and visibility can be controlled via disabled organizers and hidden event keys.
The settings UI includes a dedicated Shortcode Generator tab to compose and copy full `[pretix_events ...]` configurations.
The settings UI also includes a dedicated `Layout Composer` tab with drag-and-drop ordering and advanced per-block design controls.

## Layout Composer (Usage Guide)

1. Go to `Settings > Pretix Eventlister > Layout Composer`.
2. Enable `Composer im Frontend aktivieren`.
3. Drag blocks from **Bausteine** into **Leinwand** and reorder them.
4. Click a block in the canvas to edit styles in **Design-Panel**.
5. Configure spacing, typography, colors, border, alignment, and shadow.
6. Use the live preview for quick visual checks.
7. Save with `Composer speichern`.
8. Open a page with `[pretix_events]` and verify the output.

Notes:
- Disable `Sichtbar` on a block to hide it in frontend cards.
- `Auf Standard zurücksetzen` restores default layout and block styles.
- Composer settings are global and work together with shortcode options.

Examples:

```text
[pretix_events limit="6"]
[pretix_events scope="all" limit="all"]
[pretix_events style="list"]
[pretix_events organizer="my-organizer" show_description="no"]
[pretix_events organizers="organizer-a,organizer-b"]
[pretix_events filters="yes" load_more="yes" page_size="12"]
```

## Shortcode Options

- `limit`: Number of events, or `all`
- `scope`: `selected` or `all`
- `organizer`: Optional organizer slug for this instance
- `organizers`: Comma-separated organizer slugs
- `style`: `default`, `grid`, `list`, `compact`
- `show_description`: `default`, `yes`, `no`
- `show_organizer`: `default`, `yes`, `no`
- `show_image`, `show_time`, `show_location`, `show_countdown`, `show_platform_notice`: `default|yes|no`
- `filters`: `default|yes|no`
- `load_more`: `default|yes|no`
- `page_size`: Number of cards shown initially and per load
- `badges`, `badges_availability`, `calendar`, `schema`, `modal`, `tilt`: `default|yes|no`
- `show_available_tickets`: `default|yes|no`

## Partner Platform Notice

In plugin settings you can define organizer slugs that show a platform-only notice on event cards, clarifying that your platform provides infrastructure only and is not the event organizer.

## GitHub Updates

The plugin can update directly from GitHub releases.
When a new release with a matching ZIP is published, WordPress detects it via the built-in update API integration.

## Versioning

This project follows Semantic Versioning (`major.minor.patch`).
See `CHANGELOG.md` for release history.

## Localization

- Source descriptions and documentation use English naming.
- German UI texts are included directly in the plugin strings where relevant.
- Low stock behavior is configurable in plugin settings (`low_ticket_threshold`).

## Maintenance Notes

- README is updated on feature releases and includes usage guidance for newly introduced capabilities.
