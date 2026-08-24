# Roadmap

IO200 Analytics 1.0.0 provides the current collection, dashboard, visit, installation, and safe data-removal baseline. The items below are possible post-1.0 work, not release commitments.

## Photo and album insights

- Add richer album/collection-level analytics if IO200 exposes a stable and safe way to resolve its metadata.
- Resolve human-readable photo titles or filenames instead of relying primarily on numeric photo IDs and stored URLs.

## Traffic quality and privacy

- Replace or supplement the client-supplied `is_admin` flag with supported automatic Admin-traffic classification if IO200 exposes a reliable server-side signal.
- Add configurable retention or cleanup controls for analytics events.
- Evaluate privacy controls appropriate to different site deployments.

## Visits and measurement periods

- Add further session/visit analysis only where it remains useful without implying unique people or exact visit duration.
- Explore an explicit measurement-period/reset or “trip meter” concept that preserves historical data while allowing administrators to establish a new reporting baseline.

## IO200 integration and maintenance

- Explore integration into IO200 Admin if IO200 provides a supported extension, listener, or UI-hook mechanism.
- Introduce explicit schema migration/version tracking if future releases add more database changes or IOA-owned tables.
- Consider localization if the dashboard needs languages beyond its current Swedish interface.
