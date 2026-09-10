# IO200 Analytics

IO200 Analytics is a lightweight analytics add-on for the self-hosted IO200 photo platform. It records selected photo interactions in the site's own database and presents them in an IO200 Admin-authenticated dashboard. It does not modify IO200 core files or send analytics to an external service.

This external-testing build has an English-only interface.

## What IO200 Analytics does

- Tracks lightbox photo views, basket additions/removals, single-photo downloads, and completed album/batch downloads.
- Reports photo views, Visits, basket activity, and downloaded photos for selectable periods.
- Shows the 20 latest image views, 20 most-viewed images, 20 most-downloaded images, and 20 latest visits.
- Derives readable album/page context from collected page paths.

## Requirements

- A self-hosted IO200 installation and access to IO200 Admin.
- Permission to upload to `/storage/custom/` and edit IO200 Code Injection.
- PHP with `mysqli`, JSON, and session support.
- MySQL or MariaDB with InnoDB, `utf8mb4`, and JSON column support.
- A modern browser and an IO200 version with the current photo markup and download hooks.

Customer installation requires no package-manager dependencies, build steps, external services, or separate database credentials.

## Installation

1. Download the [latest release](https://github.com/gaspar-zabani/io200-analytics/releases/latest) and extract the ZIP. Upload the **contents** of its `io200-analytics` folder into `/storage/custom/io200-analytics/` (create that destination if needed). The result must be `/storage/custom/io200-analytics/install.php`, not `/storage/custom/io200-analytics/io200-analytics/install.php`.
2. Sign in to IO200 Admin.
3. Open `/storage/custom/io200-analytics/install.php`. Submit the installer action shown to create the table or apply a supported schema change; opening the page alone only checks the installation.
4. In **IO200 Admin → Settings → Code Injection**, add:

   ```html
   <script src="/storage/custom/io200-analytics/analytics.js?v=RELEASE_VERSION"></script>
   ```

5. Save the settings, visit the public photo site, and exercise a few photo actions.
6. Open `/storage/custom/io200-analytics/dashboard.php` while still signed in.

The installer checks the current application’s required event table, columns, and indexes. It offers safe additions when needed and reports incompatible existing definitions without converting or deleting data. Reopening a compatible installation does not change it.

Replace `RELEASE_VERSION` with the version shown for the release you installed. This query parameter prevents stale cached JavaScript during upgrades; update it whenever replacing `analytics.js`.

## Updating an existing installation

1. Download and extract the latest customer release ZIP.
2. Upload the **contents** of its `io200-analytics` folder into the existing `/storage/custom/io200-analytics/` directory, replacing the application files at the same paths. Do not upload an extra nested `io200-analytics` folder or run the uninstaller.
3. Sign in to IO200 Admin and open `/storage/custom/io200-analytics/install.php`. If a supported schema change is offered, submit/run the installer action to apply it. Opening the page alone does not apply changes. If it reports the installation is current, no schema action is needed.
4. In IO200 Code Injection, update the `analytics.js?v=RELEASE_VERSION` query string to the new release version, then save. This is currently required to refresh cached tracking JavaScript. Use the actual release version.
5. Open the dashboard and continue using the existing analytics data.

Application file replacement does not replace IO200's configuration or the existing `ioa_events` database table. Setup creates the required table on first installation and adds missing compatible structures on updates. If a populated table is missing event IDs, timestamps, or event types, setup stops rather than inventing historical values. Technical failure details are available in the installer’s diagnostic section.

## Dashboard access

The dashboard requires a valid IO200 Admin `refreshtoken`. IOA uses IO200's existing authentication service and has no separate accounts or passwords.

Unauthenticated visitors see a compact public product, download, and installation page with an IO200 Admin login action. They never receive analytics data. The installer and uninstaller use the same authentication check; database-changing forms also require a server-side session CSRF token.

## Events and data collected

| Event | Trigger | Stored data where applicable |
| --- | --- | --- |
| `photo_view` | A new image becomes current in the lightbox | Photo ID, image URL, page path, session ID |
| `basket_add` | A photo becomes selected in the basket | Photo ID, image URL, page path, session ID |
| `basket_remove` | A photo becomes unselected | Photo ID, image URL, page path, session ID |
| `photo_download` | IO200 calls its single-photo download hook | Photo ID, download URL, page path, session ID |
| `batch_download` | IO200 completes an album/batch download | Valid photo IDs and URLs in JSON, page path, session ID |

Events also receive a server-side timestamp and client-supplied `is_admin` value.

A Visit is a recorded activity episode. More than 30 minutes of inactivity starts a new Visit. The selected period includes Visits with at least one recorded action in that period; their full activity may extend beyond the period boundary. Visits do not represent unique people or exact time on site.

The Visits KPI counts all qualifying episodes, while Latest visits shows the most recent 20. Activity that cannot be assigned to a Visit can still contribute to ordinary photo totals.

The public `collect.php` endpoint accepts validated events from `analytics.js` and is intentionally not protected by Admin login.

The collector requires JSON POST requests from the origin configured in IO200's `WEBSITE_URL`. It validates `Origin`, falling back to a same-origin `Referer` only when `Origin` is absent; requests with neither header are rejected. Resource URLs must also be same-origin HTTP(S) URLs and are stored as root-relative paths without query strings or fragments. `WEBSITE_URL` must therefore match the scheme, host, and port visitors use for the site.

## Privacy limitations

- IOA does not intentionally collect IP addresses, user agents, names, email addresses, fingerprints, cookies, or persistent visitor IDs.
- It does collect event types, timestamps, paths, photo IDs, image/download URLs, per-tab session IDs, batch photo data, and a client-supplied traffic flag.
- Data remains in the site's `ioa_events` table and is not transmitted to an external analytics provider by this code.
- IOA has no consent management, retention schedule, anonymization, or automatic expiry.
- Browser-originated events and the admin flag cannot be independently verified by the collector.
- Origin and Referer validation is defense-in-depth, not authentication: direct scripted clients can forge both headers. Application or web-server rate limiting remains a future hardening option.

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
- Visits represent recorded activity episodes, not unique people or exact time on site.
- Breadcrumbs are path transformations, not authoritative IO200 metadata.
- Photos are primarily identified by numeric IDs and stored URLs.
- There is no general migration system, retention cleanup, export, automated test suite, release automation, or updater.

## Intended release package

The release ZIP is named `io200-analytics-<version>.zip`, using the installed
version from `version.php` (for example, `io200-analytics-1.1.0-beta.4.zip`).
The folder inside always remains `io200-analytics/`.

```text
io200-analytics/
├── analytics.js
├── collect.php
├── dashboard.php
├── install.php
├── uninstall.php
├── localization.php
├── version.php
├── update-check.php
├── lang/
│   └── en.php
├── assets/
│   └── dashboard-preview.png
├── README.md
└── LICENSE
```

The customer ZIP contains only the files above. It excludes `.git/`, `.gitignore`, `References/`, `ROADMAP.md`, `CHANGELOG.md`, `tools/`, `dist/`, `tests/`, editor/system metadata, logs, exports, test data, temporary files, and copied IO200 source.
