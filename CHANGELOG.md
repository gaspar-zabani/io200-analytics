# Changelog

This summary follows the milestones recorded in Git history. The project does not currently publish numbered releases.

## Unreleased

### Browser-based analytics exclusion

- Added a dashboard control that stores an exclusion preference in `localStorage`.
- Added the stored flag to browser events and persisted it as `is_admin`.
- Removed collector-side IO200 authentication detection, making exclusion explicitly browser-controlled.
- Dashboard results continue to exclude flagged traffic by default and can include it on demand.

## 2026-08-21 - Admin traffic filtering

- Added the `is_admin` event field and database column, including an installer upgrade path.
- Excluded flagged admin traffic from dashboard queries by default.
- Added a dashboard option to include admin traffic.

## 2026-08-20 - Sortable dashboard and thumbnails

- Added sortable per-photo columns with ascending and descending order.
- Improved thumbnail selection and presentation in the top-photo table.

## 2026-08-20 - Initial working prototype

- Added browser tracking for photo views, basket changes, and single/batch downloads.
- Added validated event collection and storage in `ioa_events`.
- Added the authenticated installer and initial schema.
- Added the authenticated dashboard with date filters, totals, and top-photo statistics.

