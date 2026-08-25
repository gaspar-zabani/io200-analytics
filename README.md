# IO200 Analytics 1.0.0

IO200 Analytics is a lightweight analytics add-on for the self-hosted IO200 photo platform. It records selected photo interactions in the site's own database and presents them in an IO200 Admin-authenticated dashboard. It does not modify IO200 core files or send analytics to an external service.

This external-testing build has an English-only interface.

## What IO200 Analytics does

- Tracks lightbox photo views, basket additions/removals, single-photo downloads, and completed album/batch downloads.
- Reports photo views, visits/sessions, basket activity, and downloaded photos for selectable periods.
- Shows the 20 latest image views, 20 most-viewed images, 20 most-downloaded images, and 20 latest visits.
- Derives readable album/page context from collected page paths.

## Requirements

- A self-hosted IO200 installation and access to IO200 Admin.
- Permission to upload to `/storage/custom/` and edit IO200 Code Injection.
- PHP with `mysqli`, JSON, and session support.
- MySQL or MariaDB with InnoDB, `utf8mb4`, and JSON column support.
- A modern browser and an IO200 version with the current photo markup and download hooks.

There are no package-manager dependencies, build steps, external services, or separate database credentials.

## Installation

1. Extract the release ZIP and upload its `io200-analytics` directory to `/storage/custom/io200-analytics/`.
2. Sign in to IO200 Admin.
3. Open `/storage/custom/io200-analytics/install.php` and run the installer.
4. In **IO200 Admin → Settings → Code Injection**, add:

   ```html
   <script src="/storage/custom/io200-analytics/analytics.js"></script>
   ```

5. Save the settings, visit the public photo site, and exercise a few photo actions.
6. Open `/storage/custom/io200-analytics/dashboard.php` while still signed in.

The installer creates `ioa_events` or adds the supported `is_admin` column to an older IOA table. Reopening a current installation does not recreate or clear existing data.

## Dashboard access

The dashboard requires a valid IO200 Admin `refreshtoken`. IOA uses IO200's existing authentication service and has no separate accounts or passwords.

Unauthenticated visitors see a short public product introduction and login, project-placeholder, and feedback links. They never receive analytics data. The installer and uninstaller use the same authentication check; database-changing forms also require a server-side session CSRF token.

## Events and data collected

| Event | Trigger | Stored data where applicable |
| --- | --- | --- |
| `photo_view` | A new image becomes current in the lightbox | Photo ID, image URL, page path, session ID |
| `basket_add` | A photo becomes selected in the basket | Photo ID, image URL, page path, session ID |
| `basket_remove` | A photo becomes unselected | Photo ID, image URL, page path, session ID |
| `photo_download` | IO200 calls its single-photo download hook | Photo ID, download URL, page path, session ID |
| `batch_download` | IO200 completes an album/batch download | Valid photo IDs and URLs in JSON, page path, session ID |

Events also receive a server-side timestamp and client-supplied `is_admin` value. A visit groups events sharing a non-empty random ID held in browser `sessionStorage`; it represents one browser-tab session, not a unique person.

The public `collect.php` endpoint accepts validated events from `analytics.js` and is intentionally not protected by Admin login.

## Privacy limitations

- IOA does not intentionally collect IP addresses, user agents, names, email addresses, fingerprints, cookies, or persistent visitor IDs.
- It does collect event types, timestamps, paths, photo IDs, image/download URLs, per-tab session IDs, batch photo data, and a client-supplied traffic flag.
- Data remains in the site's `ioa_events` table and is not transmitted to an external analytics provider by this code.
- IOA has no consent management, retention schedule, anonymization, or automatic expiry.
- Browser-originated events and the admin flag cannot be independently verified by the collector.

Site operators are responsible for their privacy notice and applicable legal requirements.

## Uninstallation

1. While signed in, open `/storage/custom/io200-analytics/uninstall.php`.
2. Keep `ioa_events` for a future installation or permanently delete it by typing `DELETE`.
3. Remove the IOA script tag from **IO200 Admin → Settings → Code Injection**.
4. Delete `/storage/custom/io200-analytics/`.

Only the explicitly owned `ioa_events` table can be dropped. The uninstaller does not remove its own files, edit Code Injection, or modify IO200 core data.

## Feedback / bug reports

Email [ioa@jesperalvermark.se](mailto:ioa@jesperalvermark.se). Useful reports include IO200, PHP, database, and browser versions; reproduction steps; and relevant server-log messages. Do not send private visitor data or database exports through ordinary email.

## Current limitations

- The interface is English-only and has no language selector.
- Tracking depends on current IO200 DOM selectors, lightbox URL matching, and download-hook names.
- Admin/excluded-traffic classification is client supplied.
- Sessions represent browser-tab activity, not people or exact time on site.
- Breadcrumbs are path transformations, not authoritative IO200 metadata.
- Photos are primarily identified by numeric IDs and stored URLs.
- There is no general migration system, retention cleanup, export, automated test suite, release automation, or updater.
- The public project/release link is currently a placeholder.
- No software license has been selected; this remains a blocker for a general public release.

## Intended release package

```text
io200-analytics/
├── analytics.js
├── collect.php
├── dashboard.php
├── install.php
├── uninstall.php
├── localization.php
├── lang/
│   └── en.php
├── README.md
├── CHANGELOG.md
└── ROADMAP.md
```

Do not distribute `References/`, `.git/`, `.gitignore`, editor/system metadata, logs, exports, test data, temporary files, ZIPs, or copied IO200 source. Nothing under `References/` is part of IO200 Analytics.
