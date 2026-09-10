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

There are no package-manager dependencies, build steps, external services, or separate database credentials.

## Installation

1. Download the [latest release](https://github.com/gaspar-zabani/io200-analytics/releases/latest), extract the ZIP, and upload its `io200-analytics` directory to `/storage/custom/io200-analytics/`.
2. Sign in to IO200 Admin.
3. Open `/storage/custom/io200-analytics/install.php` and run the installer.
4. In **IO200 Admin → Settings → Code Injection**, add:

   ```html
   <script src="/storage/custom/io200-analytics/analytics.js?v=RELEASE_VERSION"></script>
   ```

5. Save the settings, visit the public photo site, and exercise a few photo actions.
6. Open `/storage/custom/io200-analytics/dashboard.php` while still signed in.

The installer creates `ioa_events` or adds the supported `is_admin` column to an older IOA table. Reopening a current installation does not recreate or clear existing data.

Replace `RELEASE_VERSION` with the version shown for the release you installed. This query parameter prevents stale cached JavaScript during upgrades; update it whenever replacing `analytics.js`.

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

### Version and update status (Phase 1)

`version.php` is the sole installed-version source; release packaging must update
its returned SemVer string. The initial development version follows the latest
`v1.1.0-beta.3` tag and the subsequent untagged work. The changelog currently
lists only `1.0.0` and Unreleased, so tags provide the more recent release history.

`update-check.php` owns the fixed HTTPS manifest URL, currently the intentionally
inactive placeholder `https://updates.io200-analytics.invalid/manifest.json`.
Replace that constant when the production endpoint is ready. Browser parameters
cannot override it. The minimal manifest is `{"version":"1.2.0"}`; the future
format may also include `package_url`, `sha256`, and `notes_url`, all ignored in
Phase 1. No package is downloaded and no update action exists.

Comparison follows SemVer 2.0 precedence, including numeric beta identifiers and
ignoring build metadata. For example, beta.3 precedes beta.3.dev, which precedes
beta.4 and then the stable release. A newer prerelease in the fixed manifest is
eligible even for a stable installation; there is no channel selection in this
phase. The future production manifest must therefore publish the intended channel.
Equal or older remote versions mean “Up to date.”

The authenticated dashboard checks via PHP cURL with TLS verification, no
redirects, a two-second connection timeout, a four-second total timeout, and a
16 KiB response limit. Only a JSON object with a valid version is accepted.
Missing cURL, network/HTTP/TLS failures, malformed manifests, and cache failures
produce “Update status unavailable,” without affecting analytics.

A single installation/endpoint-specific JSON file in PHP's temporary directory
caches valid responses for six hours and unavailable responses for fifteen
minutes. A nonblocking file lock prevents concurrent checks. If the cache cannot
be used, no remote request is made. Temp cleanup may cause an earlier recheck;
there is no database or customer configuration change. A cache miss can delay
the dashboard by at most the configured network timeout under normal cURL operation.

Run `php tests/update-check.php` for version/manifest checks. On shared hosting,
verify cURL availability, outbound HTTPS and CA certificates, temp-directory
permissions and file locking, success/failure cache expiry, concurrent requests,
and dashboard rendering with unavailable checks. Test a controlled fixed endpoint
with valid, malformed, oversized, redirect, HTTP-error and slow responses before
configuring production. Include `version.php` and `update-check.php` in every
release package; the `tests/` directory is development-only.

```text
io200-analytics/
├── assets/
│   └── dashboard-preview.png
├── analytics.js
├── collect.php
├── dashboard.php
├── version.php
├── update-check.php
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
