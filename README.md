# IO200 Analytics

IO200 Analytics is a small, self-contained analytics add-on for an IO200 photo site. It records interactions with photos and presents aggregate statistics without modifying the IO200 core.

## Current features

- Tracks lightbox photo views, basket additions/removals, single-photo downloads, and batch downloads.
- Assigns a browser-tab session ID with `sessionStorage`.
- Shows totals for views, sessions, basket additions, and downloaded photos.
- Shows a sortable top-20 photo table with thumbnails, per-photo counts, and basket/download rates.
- Filters the dashboard to 7, 30, or 90 days, or all time.
- Excludes flagged browser traffic from dashboard results by default, with an option to include it.
- Provides an authenticated, CSRF-protected installer that creates or updates the analytics table.
- Requires the existing IO200 administrator login for the installer and dashboard.

## Components

| File | Purpose |
| --- | --- |
| `analytics.js` | Browser-side event capture. Maps lightbox URLs to IO200 photo IDs, observes lightbox changes, connects to IO200 download hooks, and posts JSON events to the collector. |
| `collect.php` | Public POST endpoint. Validates and limits event data, then inserts it into `ioa_events` with a prepared statement. |
| `dashboard.php` | Authenticated server-rendered dashboard. Queries aggregate and per-photo statistics, applies date/admin filters, and controls the per-browser exclusion flag. |
| `install.php` | Authenticated installer. Checks the database, creates `ioa_events`, or adds the `is_admin` column to an older installation. |

All server-side components reuse IO200's configuration, database credentials, and authentication classes. Analytics data is stored in the IO200 database in a separate `ioa_events` table.

## Installation

1. Place these files in `/storage/custom/io200-analytics/` in the IO200 installation.
2. Sign in to IO200 Admin.
3. Open `/storage/custom/io200-analytics/install.php` and run the installer.
4. Add the following in **IO200 -> Settings -> Code Injection**:

   ```html
   <script src="/storage/custom/io200-analytics/analytics.js"></script>
   ```

5. Open `/storage/custom/io200-analytics/dashboard.php` while signed in as an IO200 administrator.

The installer is safe to revisit: it detects an existing table and can add the current `is_admin` column when needed.

## Dependencies

- An IO200 installation with the directory layout assumed by the relative PHP includes.
- PHP with `mysqli`, JSON support, sessions, and the IO200 `AuthenticationService`/`ErrorInfo` classes.
- MySQL or MariaDB with InnoDB, `utf8mb4`, and a JSON column type.
- A modern browser with `fetch`, `MutationObserver`, `Map`, `crypto.randomUUID`, `sessionStorage`, and `localStorage`.
- IO200's current photo markup (`.photo-wrapper[data-photoid]`, `a.js-lightbox`, and `.gslide.current img`) and `MyApp.hooks` download callbacks.

There is no package manager, build step, or separate test suite in the repository.

## Development and deployment

Edit the four source files directly and test against a compatible IO200 installation and database. For deployment, copy the files to `/storage/custom/io200-analytics/`, revisit the installer after schema-affecting changes, and keep the Code Injection script tag enabled. Git history is the current release record; no automated release or migration system is present.

## Known limitations

- The browser integration depends on IO200 DOM selectors, URL matching, and global hook names that may change between IO200 versions.
- Browser exclusion is stored only in that browser's `localStorage`. Its `is_admin` value is supplied by the client, so it is a convenience filter rather than authenticated traffic classification.
- Session IDs last only for the current browser tab/session and do not identify people across visits.
- The collector has no authentication, consent UI, retention policy, or automated cleanup.
- The dashboard identifies photos by numeric IO200 ID and the latest stored image URL; it has no title, filename, album, or collection metadata.
- The dashboard and installer require an IO200 admin refresh-token cookie and otherwise return or display an authentication-required response.
- Installation handles only initial table creation and the single `is_admin` upgrade; general schema versioning and rollback are not implemented.

