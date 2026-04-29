# Changelog

All notable changes to this project are documented in this file.

## 0.3.2 - 2026-04-29

- Added repository `.gitignore` to ignore local artifacts and old zip bundles by default.
- Kept latest release package tracked while preventing new historical zip clutter.

## 0.3.1 - 2026-04-29

- Added `.lumos_linked_hover` class to generated links and configurable hover settings (color + style) in admin.
- Added "Exclude from URLs" input in keyword mappings to skip linking on selected URLs.
- Applied exclude rules to both scan-time and runtime (frontend) linking logic.

## 0.3.0 - 2026-04-29

- Improved frontend auto-linker reliability for builder-rendered content (Elementor timing and mutation handling).
- Added admin report table "Pages where linked" with URL and keyword-links count.
- Added pagination for the linked-pages report (10 rows per page).

## 0.2.9 - 2026-04-29

- Added frontend browser-rendered auto-linking fallback for Elementor/DOM-rendered content.
- Added `frontend-autolink.js` to inject keyword links from rendered text nodes while skipping existing links and code blocks.
- Keeps mapping case-sensitivity behavior in frontend runtime linking.

## 0.2.8 - 2026-04-29

- Fixed scan workflow to persist per-keyword scan summary after "Run scan now".
- Added per-keyword result table: pages count, page keyword links, posts count, post keyword links.
- Added scan aggregation that counts how many posts/pages contain each mapped keyword link.

## 0.2.7 - 2026-04-29

- Added "Linked pages" count per keyword mapping in management table.
- Added linked-pages metric to Active Table interactive view.

## 0.2.6 - 2026-04-29

- Fixed admin menu icon to use the provided custom `assets/icon.svg` instead of fallback glyph icon.

## 0.2.5 - 2026-04-29

- Added "Check for updates" action link on Plugins page for Lumos Linker.
- Added manual update-check handler that refreshes plugin update transients and shows a success notice.

## 0.2.4 - 2026-04-29

- Fixed admin menu icon sizing by switching to WordPress-friendly 20x20 SVG data URI icon.

## 0.2.3 - 2026-04-29

- Renamed admin and plugin display name to Lumos Linker.
- Added custom admin menu icon from `assets/icon.svg`.

## 0.2.2 - 2026-04-29

- Fixed add-row UX so existing input values stay intact when adding new rows.
- Added case-sensitive keyword matching option for mapping saves.
- Kept management table always visible (no collapsed details wrapper).
- Improved scan reliability with JSON-to-option fallback storage and scan result count.
- Expanded table stats with source-page count per keyword mapping.

## 0.2.1 - 2026-04-29

- Added Active Table interactive grid view in admin mappings section.
- Kept management table for delete actions and keyword click-stat modal.
- Improved admin usability by showing quick grid + detailed controls together.

## 0.2.0 - 2026-04-29

- Improved admin UI with multi-row keyword/link input for faster bulk mapping.
- Enforced validation: if a keyword is entered, target URL is mandatory.
- Added mapping table click metrics and modal details per keyword.
- Added click tracking with source page breakdown (where clicks came from).
- Moved plugin data storage to JSON files in uploads (`mappings.json`, `click-stats.json`) to avoid database bloat.

## 0.1.1 - 2026-04-29

- Added built-in GitHub Releases updater for WordPress plugin updates.
- Added `Update URI` metadata for GitHub-based update source.
- Renamed distributable plugin folder to `lumos-linked`.
- Added project `README.md` with setup and update instructions.

## 0.1 - 2026-04-29

- Initial release of Lumos-linked.
- Added keyword mapping admin UI.
- Added full scan for published posts/pages.
- Added automatic link insertion on post/page update.
