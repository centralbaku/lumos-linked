# Changelog

All notable changes to this project are documented in this file.

## 0.4.8 - 2026-04-30

- Fixed `exclude_target_url_page` persistence so edit mode and link exclusion behavior stay correct after save/reload.
- Added auto-enable logic: when Target URL is also listed in Exclude from URLs, target-page exclusion is enabled automatically (add + edit).
- Added UI polish for mapping actions with rounded icon-style Edit/Delete buttons.
- Added add-form auto-check helper that enables "Exclude from targeted URL page for rows in this save" when row values match.

## 0.4.7 - 2026-04-30

- Added a `site_transient_update_plugins` sanitization step to remove stale Lumos Linker update entries at read time.
- Prevents repeated same-version update prompts even when WordPress serves cached plugin update transient data.

## 0.4.6 - 2026-04-30

- Fixed stale WordPress update notices by clearing Lumos Linker update responses when installed version is already up to date.
- Prevents repeated "update available" prompts for the same release number after successful update.

## 0.4.5 - 2026-04-30

- Fixed frontend asset cache-busting by updating enqueued script/style versions from stale `0.4.2` to current release version.
- Prevents browsers/CDN from serving old JS that still generated legacy redirect-style tracking URLs.

## 0.4.4 - 2026-04-30

- Added a new Dashboard action to migrate legacy redirect-style tracked links on demand.
- Added migration support for both `post_content` and Elementor `_elementor_data` content.
- Added admin notice with migrated posts/pages count after migration.

## 0.4.3 - 2026-04-30

- Switched internal link tracking to SEO-safe links: generated anchors now keep direct destination URLs in `href`.
- Added background click tracking via frontend `sendBeacon`/AJAX endpoint instead of redirect-style tracking URLs.
- Kept backward compatibility for existing redirect-style links and updated reports to count both legacy and new link formats.

## 0.4.2 - 2026-04-30

- Added new mapping checkbox to exclude linking on each mapping's own target URL page.
- Applied target-page exclusion to both scan-time PHP linking and frontend runtime auto-linking.
- Added management table and edit modal support for the new "Exclude target URL page" option.

## 0.4.1 - 2026-04-30

- Added Edit button for keyword mappings with modal editor.
- Added editable Exclude from URLs in mapping edit flow (supports multiple values).
- Added Exclude from URLs column to mappings table and Active Table view.

## 0.4.0 - 2026-04-30

- Improved "Exclude from URLs" to reliably support multiple values per keyword.
- Added textarea input with guidance for comma/newline-separated exclude patterns.
- Expanded parser to accept comma, newline, semicolon, or pipe delimiters.

## 0.3.9 - 2026-04-30

- Fixed Elara hover animation behavior with stronger span-based underline animation selectors and timing.
- Improved animation compatibility with theme styles by forcing no text-decoration on Elara links.

## 0.3.8 - 2026-04-30

- Improved Dashboard UI with cleaner card layout and expanded key metrics.
- Added dashboard stats: total keyword links, avg links per page, top keyword by clicks, and last scan time.

## 0.3.7 - 2026-04-30

- Enhanced "Pages where linked" report to show keyword breakdown per page.
- Added per-page detail with keyword names and how many times each keyword is linked.

## 0.3.6 - 2026-04-30

- Added base `.lumos_link` class to all generated Lumos links (scan and runtime).
- Added link color setting so users can style default Lumos link color from Settings.
- Kept hover color/style controls and applied combined styling through plugin settings.

## 0.3.5 - 2026-04-30

- Split admin into 3 subpages: Dashboard, Links, Settings.
- Added simple 3-column Dashboard statistics cards.
- Moved mapping management to Links page and hover controls to Settings page.
- Updated admin action redirects so add/delete/scan/settings notices return to the correct subpage.

## 0.3.4 - 2026-04-29

- Added Elara-style animated underline hover option inspired by Codrops LineHoverStyles.
- Added `elara` hover style selection in settings and applied to both scan-time and runtime links.
- Updated runtime link rendering to wrap keyword text in `<span>` for animation compatibility.

## 0.3.3 - 2026-04-29

- Fixed "Check for updates" flow to force-refresh GitHub release cache before running plugin update checks.
- Added plugin cache cleanup call during manual check to ensure fresh update metadata in WordPress.

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
