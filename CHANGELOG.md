# Changelog

All notable changes to this project are documented here using Semantic Versioning (`major.minor.patch`).

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
