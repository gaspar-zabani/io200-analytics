# Changelog

## 1.0.0 - 2026-08-24

Initial release of IO200 Analytics.

### Analytics collection

- Added browser-side tracking for lightbox photo views, basket additions/removals, single-photo downloads, and completed album/batch downloads.
- Added per-browser-tab random session IDs using `sessionStorage` and collection of the current page path as event context.
- Added a validated JSON collector with an event allowlist, payload limits, prepared database inserts, and generic client-facing error responses.
- Added counting of individual photos contained in batch downloads.
- Added the client-supplied `is_admin` event field and default dashboard exclusion of flagged events while retaining the underlying filtering capability.

### Dashboard

- Added IO200 Admin-authenticated KPI reporting for photo views, distinct sessions, basket additions, and downloaded photos.
- Added consistent filtering for today, rolling 7-, 30-, and 90-day periods, and all time.
- Added a unified, responsive, keyboard-accessible tab interface for recent views, most-viewed photos, most-downloaded photos, and visits.
- Added 20-item recent/ranking lists with thumbnails, photo IDs, supporting metrics, sortable most-viewed columns, and basket/download percentages.
- Added readable album/page breadcrumbs derived from stored `page_path` values.
- Added visit analytics based on existing non-empty `session_id` values, including period totals and the 20 most recent visits with activity timestamps, event counts, downloads, basket additions, and compact page-context lists.
- Added a polished login-required dashboard state linking to IO200 Admin and allowing a manual retry after login.
- Consolidated dashboard presentation into reusable KPI, panel, photo-item, metadata, and metric patterns.

### Installation and removal

- Added an IO200 Admin-authenticated, CSRF-protected installer for creating `ioa_events` and upgrading older installations with the `is_admin` column.
- Added matching login-required presentation to the installer and uninstaller.
- Added a conservative uninstall workflow with separate keep-data and permanent-delete paths.
- Restricted permanent deletion to the explicitly owned `ioa_events` table and protected it with Admin authentication, CSRF validation, a fixed action, and typed `DELETE` confirmation.
- Kept Code Injection cleanup and deletion of `/storage/custom/io200-analytics/` as explicit manual steps.

### Release documentation

- Updated installation, authentication, event, dashboard, visit, privacy, limitation, and uninstallation documentation for IO200 Analytics 1.0.0.

