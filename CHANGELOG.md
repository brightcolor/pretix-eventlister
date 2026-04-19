# Changelog

All notable changes to this project are documented here using Semantic Versioning (`major.minor.patch`).

## [2.0.0] - 2026-04-18

- Added a new feature-rich `Layout Composer` tab in backend settings.
- Added drag-and-drop block composition for card sections (`schedule`, `body`, `partner note`, `footer`).
- Added per-block style controls:
  - visibility
  - padding and margin
  - text, background, and border color
  - border width and radius
  - font family, size, weight, line height, letter spacing, text alignment
  - shadow
- Added live style preview for the currently selected block in the composer UI.
- Added secure sanitization and persistence for composer layout/style JSON settings.
- Connected composer output to frontend rendering, including block reordering and style application per event card.

## [1.9.0] - 2026-04-18

- Moved the shortcode generator into its own dedicated backend tab.
- Redesigned the shortcode generator UI with a modern card layout, grouped controls, quick presets, and improved copy workflow.

## [1.8.0] - 2026-04-18

- Added a backend shortcode generator with comprehensive option controls and one-click copy.
- Security hardening:
  - restricted legacy install-state normalization to users with plugin activation capability
  - added basic public ICS endpoint rate limiting (per client/time window)
  - added input length bounds for public ICS slug parameters
  - locked paginated pretix follow-up requests to the same host to prevent token forwarding to foreign domains
- Kept existing tabbed admin UX and manual event override behavior intact.

## [1.7.1] - 2026-04-18

- Fixed tabbed settings save behavior: saving one tab no longer resets values from other tabs to defaults.
- Added hidden field-presence tracking so option sanitization only updates fields from the currently submitted tab.

## [1.7.0] - 2026-04-18

- Added tabbed backend settings layout (Connection, Display, Sync & Overrides, Tools) for a cleaner admin UX.
- Added visibility controls in settings:
  - disabled organizers
  - hidden events (`organizer-slug/event-slug`)
- Expanded event override editor UI in the synced Event CPT with tabs and editable fields for:
  - title, description, image
  - start date, end date, location
  - ticket shop URL
  - hide event toggle
- Manual overrides are kept on sync and now also support manual date/location/link control in frontend output.
- Optimized sync fetch behavior by skipping extra event settings API calls when manual description/image overrides are already present.

## [1.6.2] - 2026-04-18

- Fixed release ZIP packaging format: archive entries now use POSIX-style paths (`/`) so Linux/WordPress extraction creates real directories instead of backslash-named files.
- This resolves broken installs/updates caused by malformed ZIP internal paths.

## [1.6.1] - 2026-04-18

- Added robust installer/update path normalization for uploaded ZIP files to enforce the canonical plugin directory `pretix-eventlister`.
- Added legacy installation self-healing for versioned/nested plugin basenames in WordPress active plugin and update transient data.
- Improved source directory detection for nested ZIP extraction layouts to prevent `No such file or directory` and invalid plugin path issues.

## [1.6.0] - 2026-04-18

- Added manual per-event override controls in the synced Event CPT (title, description, image, location, ticket URL) with a dedicated “override active” lock.
- Synced events now preserve manual override content and no longer overwrite locked fields during API synchronization.
- Fixed `Array to string conversion` warnings for structured `location` payloads by adding robust location value resolution.
- Reworked ticket availability logic to use remaining ticket quantities instead of sold counts.
- Added per-product remaining ticket output in frontend cards and backend event preview.

## [1.5.0] - 2026-04-18

- Added per-event available ticket output based on pretix quota availability.
- Added backend settings for:
  - showing available tickets in event cards
  - configuring the low-stock threshold used for the `Wenige Tickets` hint
- Added backend event preview table with useful fetched API details (organizer, date/time, location, availability, price, status, IDs, links).

## [1.4.1] - 2026-04-18

- Fixed escaped quotation marks in German settings labels (removed literal backslashes such as `\"` in visible UI text).
- Replaced HSP-specific wording with generalized partner/platform wording in frontend and settings UI.
- Updated German language mapping for partner platform terminology.

## [1.4.0] - 2026-04-17

- Added localization bootstrap (`load_plugin_textdomain`) and a bundled German language file at `languages/pretix-eventlister-de_DE.php`.
- Switched plugin metadata/source descriptions to English naming while preserving German interface output through the language mapping.

## [1.3.4] - 2026-04-17

- Switched GitHub documentation to English (`README.md`, `CHANGELOG.md`).
- Updated plugin author naming to lowercase `bright color` (plugin header + plugin details API output).

## [1.3.3] - 2026-04-17

- Fixed plugin details modal rendering: GitHub release notes containing escaped newlines (`\n`) are now normalized and rendered properly as Markdown/HTML.

## [1.3.2] - 2026-04-17

- Fixed WordPress update package recognition by shipping release ZIP files in root format.
- Installer now enforces target directory `pretix-eventlister` for stable install/update behavior.

## [1.3.1] - 2026-04-17

- Improved updater source directory detection for nested extraction scenarios.
- Prevents update failures with `No valid plugins were found`.

## [1.3.0] - 2026-04-17

- Introduced optional feature toggles (global settings and per shortcode/block override).
- Added Gutenberg block (`Pretix Events`) with server-side preview.
- Added frontend filters (organizer, timeframe, location, text search) and optional load more/pagination.
- Added badges (free, online, multi-day, soon) and optional availability badges.
- Added calendar actions (`.ics`, Google Calendar, Outlook).
- Added optional schema.org event markup and optional event detail modal.
- Added admin tools for API connection test and manual cache flush.
- Added optional CPT synchronization via cron.

## [1.2.16] - 2026-04-16

- Removed pretix hint/summary header elements from frontend output.
- Switched primary CTA to lowest-price ticket label (`Tickets ab ...`).
- Extended event descriptions and images via pretix event settings (`frontpage_text`, `logo_image`).
- Added missing `get_locale_preferences()` to prevent runtime errors in localized field resolution.

## [1.2.6] - 2026-04-16

- Added event countdown hint (`Starts in X days` equivalent wording for German UI).

## [1.2.5] - 2026-04-16

- Improved event image extraction robustness from pretix payloads.
- Render event descriptions as HTML.
- Added Markdown-to-HTML conversion for event descriptions.

## [1.2.4] - 2026-04-16

- Improved package normalization for WordPress updates to avoid unnecessary folder remapping.
- Excluded development-only files from release ZIP export.

## [1.2.3] - 2026-04-16

- Updated author to Bright Color and linked GitHub repository.
- Added plugin icon for WordPress plugin details and plugin list.
- Added repository and changelog links in plugin row meta.

## [1.2.2] - 2026-04-16

- Removed GitHub update notice from plugin settings page.

## [1.2.1] - 2026-04-16

- Normalized install/update package paths to fixed plugin folder `pretix-eventlister`.
- Prevented activation failures like `This plugin file does not exist` after ZIP/GitHub installs.
- Extended README installation notes for release ZIP usage.

## [1.2.0] - 2026-04-16

- Added GitHub-based update routine for WordPress.
- Wired plugin update metadata to GitHub release data.
- Added release cache for update checks.

## [1.1.1] - 2026-04-16

- Added `CHANGELOG.md`.
- Bumped versioning metadata to `1.1.1`.
- Unified asset versioning through central plugin constant.

## [1.1.0] - 2026-04-16

- Implemented modern frontend card layout with responsive behavior.
- Added support for all organizers, single organizer, or multi-organizer selection.
- Added HSP partner notice logic for selected organizers.
- Extended API handling with organizer index, pagination, and stronger normalization.

## [1.0.0] - 2026-04-16

- Initial release.
- Added pretix API integration (base URL, token, organizer handling).
- Added responsive shortcode output via `[pretix_events]`.
