# Changelog

All notable changes to this project are documented in this file.

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
