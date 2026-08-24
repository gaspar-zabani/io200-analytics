# IO200 Analytics 1.0.0

IO200 Analytics is a small, self-contained analytics add-on for an IO200 photo site. It records selected photo interactions in a dedicated database table and presents them in an administrator-only dashboard without modifying IO200 core files.

## Current v1.0 features

- Tracks lightbox photo views, basket additions and removals, single-photo downloads, and completed album/batch downloads.
- Stores the current page path, relevant photo and image/download data, a browser-tab session ID, and a client-supplied traffic-exclusion flag with each event.
- Shows period-filtered KPI totals for photo views, sessions, basket additions, and downloaded photos.
- Provides unified tabs for recent views, most-viewed photos, most-downloaded photos, and visits.
- Derives readable album/page breadcrumbs from collected page paths.
- Uses a consistent 20-item limit for photo rankings, recent views, and recent visits.
- Reuses IO200 administrator authentication for the dashboard, installer, and uninstaller.
- Includes authenticated installation and a conservative, confirmation-protected database removal workflow.

## Requirements and dependencies

- An IO200 installation with the directory layout expected by the relative PHP includes.
- PHP with `mysqli`, JSON support, sessions, and IO200's `AuthenticationService` and `ErrorInfo` classes.
- MySQL or MariaDB with InnoDB, `utf8mb4`, and a JSON column type.
- A modern browser with `fetch`, `MutationObserver`, `Map`, `crypto.randomUUID`, `sessionStorage`, and `localStorage`.
- IO200's expected photo markup (`.photo-wrapper[data-photoid]`, `a.js-lightbox`, and `.gslide.current img`) for view and basket tracking.
- IO200's `MyApp.hooks.onPhotoDownload` and `MyApp.hooks.onFinishedAlbumDownload` callbacks for download tracking.

There are no external packages, build steps, frameworks, or separate application credentials.

## Installation

1. Copy the repository files to `/storage/custom/io200-analytics/` in the IO200 installation.
2. Sign in to IO200 Admin.
3. Open `/storage/custom/io200-analytics/install.php` and run the installer.
4. Add this script reference under **IO200 → Settings → Code Injection**:

   ```html
   <script src="/storage/custom/io200-analytics/analytics.js"></script>
   ```

5. Open `/storage/custom/io200-analytics/dashboard.php` while still signed in to IO200 Admin.

The installer creates the dedicated `ioa_events` table. It can also add the `is_admin` column to an older IO200 Analytics table that does not yet have it. Reopening an already-current installation does not recreate its data.

## Authentication and dashboard access

`install.php`, `dashboard.php`, and `uninstall.php` validate IO200's existing `refreshtoken` through IO200's authentication service. They do not maintain separate IO200 Analytics accounts or passwords.

Users without a valid IO200 Admin login see a login-required page with links to open `/admin/` in a new tab and retry the current page. There is no automatic redirect. Installer and uninstaller database-changing POST requests also require a server-side session CSRF token.

The event collector is a public POST endpoint used by the site-facing tracking script; it validates the accepted event types and payload sizes but does not require the Admin login.

## Events collected

`analytics.js` and `collect.php` currently support:

| Event | Trigger | Relevant stored data |
| --- | --- | --- |
| `photo_view` | A new image becomes current in the IO200 lightbox | Photo ID when URL mapping succeeds, image URL, page path, session ID |
| `basket_add` | A photo changes to selected in the basket | Photo ID, image URL, page path, session ID |
| `basket_remove` | A photo changes from selected to unselected | Photo ID, image URL, page path, session ID |
| `photo_download` | IO200 calls its single-photo download hook | Photo ID, download URL, page path, session ID |
| `batch_download` | IO200 calls its completed album-download hook | Valid photo IDs and photo URLs in JSON batch data, page path, session ID |

The database assigns `created_at` when the collector inserts an event. Batch download totals count the valid photo IDs contained in each batch, not merely the number of batch events.

## Dashboard

The dashboard shows four KPI cards:

- `Bildvisningar`: all matching `photo_view` events.
- `Sessioner`: distinct non-empty `session_id` values among matching events.
- `Tillagda i basket`: matching `basket_add` events.
- `Nedladdade bilder`: single-photo downloads plus the valid photo IDs in batch downloads.

Events marked with `is_admin = 1` are excluded by default through shared query filtering. The v1.0 dashboard intentionally does not expose the earlier experimental browser/admin traffic controls. The underlying field and query-parameter capability remain, but the flag is client supplied and is not authenticated Admin-session detection.

### Period filtering

All dashboard summaries, rankings, recent lists, and visit data use the selected period:

- `Idag`: from the database server's current local calendar day start through now.
- `7 dagar`, `30 dagar`, and `90 dagar`: rolling periods measured back from the database server's current time.
- `All tid`: no date restriction.

The default period is 30 days.

### Photo analytics tabs

- **Senaste visningarna** shows the 20 newest `photo_view` events with thumbnail, photo ID, timestamp, and album/page breadcrumb.
- **Mest visade** shows up to 20 photos with views, distinct sessions, basket additions, downloads, and view-based percentages. Views are the default sort, and the displayed metric columns can be sorted in either direction.
- **Mest nedladdade** shows the 20 photos with the most downloads, with views, sessions, basket additions, and percentages as supporting metrics.

The tab headers preview the leading item for each photo category. A stored image URL is reused as a thumbnail when available. Breadcrumbs are generated from `page_path`, for example `/hh50/extrabilder` becomes `HH50 → Extrabilder`; v1.0 does not resolve authoritative album names from IO200.

### Visit (`Besök`) analytics

A visit is all matching events that share the same existing, non-empty `session_id`. The browser creates this random ID in `sessionStorage`, so it normally belongs to one browser tab/session. It is not a unique person or a durable visitor identity.

The tab preview summarizes visits, photo views, downloads, and basket additions in the selected period. The list shows the 20 visits with the newest matching activity, including:

- first and latest recorded activity within the selected period;
- photo views and distinct page-path contexts;
- basket additions and downloads when greater than zero;
- up to three readable album/page contexts, followed by an additional-context count when needed.

No duration is calculated. Events without a non-empty `session_id` remain included in applicable overall KPIs but cannot be assigned to a visit, so visit photo-view totals can be lower than the main photo-view KPI.

## Uninstallation

Open `/storage/custom/io200-analytics/uninstall.php` while authenticated in IO200 Admin. Two paths are available:

1. **Keep analytics data:** leave the database unchanged, manually remove the Code Injection script reference, and manually delete the plugin directory. Keeping `ioa_events` allows its analytics history to be reused by a later installation.
2. **Permanently delete analytics data:** submit the authenticated, CSRF-protected form after typing `DELETE`. The uninstaller can drop only the explicitly named IO200 Analytics table `ioa_events`.

In both cases, final cleanup remains manual:

1. Remove the IO200 Analytics script tag from IO200 Code Injection.
2. Delete `/storage/custom/io200-analytics/`.

`uninstall.php` does not delete its own PHP/JavaScript files, remove the containing directory, edit Code Injection, or modify IO200 core tables.

## Data and privacy considerations

- IO200 Analytics does not intentionally collect IP addresses, user-agent strings, names, email addresses, fingerprints, cookies, or persistent visitor IDs.
- It does collect page paths, photo IDs, image/download URLs, interaction types, server-side timestamps, session IDs, batch photo data, and the client-supplied `is_admin` flag.
- Session IDs are stored in `sessionStorage`; the browser-exclusion flag, when already configured, is read from `localStorage`.
- Events are stored in the site's IO200 database in `ioa_events` and are not sent to an external analytics service by this code.
- v1.0 does not provide consent management, a retention schedule, anonymization, or automatic data expiry. Site operators are responsible for their own legal and privacy requirements.

## Known limitations

- Tracking depends on current IO200 DOM selectors, lightbox URL matching, and global download-hook names.
- The collector accepts browser-originated events and does not independently authenticate or verify them.
- Admin/excluded-traffic classification is client supplied; there is no supported IO200 server-side Admin-session signal in this integration.
- Sessions identify a browser-tab session, not a person. They are not merged across tabs or visits and do not support exact time-on-site claims.
- Missing or empty historical session IDs cannot participate in visit analytics.
- Breadcrumbs are readable transformations of paths, not authoritative IO200 album or collection metadata.
- Photo analytics primarily identify photos by numeric IO200 ID and stored URLs; titles and filenames are not resolved.
- Installation supports initial table creation and the existing `is_admin` upgrade only; there is no general migration/version table.
- There is no built-in retention cleanup, export, automated test suite, or release automation.

