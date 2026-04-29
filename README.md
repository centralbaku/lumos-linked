# Lumos Linker

Lumos-linked is a WordPress plugin that automatically creates internal links in published posts and pages using keyword rules defined by an administrator.

## Overview

- Add keyword-to-link mappings in WordPress admin (for example, `middle corridor` to `/route-page`).
- Run a full site scan to insert internal links across all published posts and pages.
- Automatically apply links when a post or page is updated.
- Preserve existing links by skipping text that is already inside `<a>` tags.

## Main Features

- Simple admin UI for adding and deleting keyword mappings.
- Friendly bulk input UI to add multiple keyword/link rows in one save.
- Active Table interactive grid for fast mapping overview.
- Manual control over target URLs.
- One-click scan for existing content.
- Safe processing that avoids nested anchor tags.
- Click analytics per keyword, including source pages.
- Optional "exclude on target URL page" checkbox to prevent self-page linking.

## Plugin Location

- Main plugin file: `lumos-linked/lumos-linked.php`
- Upload package: `lumos-linked-0.4.2.zip`
- Changelog file: `CHANGELOG.md`

## Data Storage

- Plugin mappings and click analytics are stored as JSON files in:
  - `wp-content/uploads/lumos-linked/mappings.json`
  - `wp-content/uploads/lumos-linked/click-stats.json`

This keeps the WordPress database cleaner while preserving analytics data.

## GitHub Release Updates

Lumos-linked now includes a GitHub updater that checks your repository releases and shows updates in WordPress admin.

- Repository: `https://github.com/centralbaku/lumos-linked`
- It reads the latest release tag (for example `v0.2`) and compares it with the plugin version.
- It downloads the `.zip` asset attached to that release.

For each new update:

1. Increase `Version` in `lumos-linked/lumos-linked.php`.
2. Build a new zip (for example `lumos-linked-0.2.zip`) that contains the `lumos-linked/` folder.
3. Create a GitHub release with tag like `v0.2`.
4. Upload that zip as a release asset.

If the repo is private, define `LUMOS_LINKED_GITHUB_TOKEN` in `wp-config.php` for API access.

## Versioning Policy

- Every code update must increment plugin version in `lumos-linked/lumos-linked.php`.
- Every code update must add a new entry in `CHANGELOG.md`.

## Author

Orkhan Hasanov

