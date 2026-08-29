# TN Game OS 5.135.0

## Privacy-Safe Offline Adventure Packs

- Adds explicit download, update, status, and removal controls to Saved Adventures, including archived plans.
- Caches only the plan's same-origin, published public stop pages, up to 12 screens.
- Fetches without login cookies and requires the server's `X-TNG-Offline-Safe: 1` response before storing anything.
- Uses a one-way device cache identifier rather than the private plan identifier.
- Keeps Saved Adventures, Planning Notes, readiness, Trips, XP, profiles, photos, and all writes network-only.
- Adds no background sync, queued gameplay, automatic download, or external connection.
