# TN Game OS 5.110.0 — Offline Packs

## Device-local public packs

- Adds an `/offline/` app screen for downloading and managing public Tennessee discovery packs.
- Includes Essentials, Tennessee Places, and Events packs.
- Downloads only predefined same-origin TN Game routes that explicitly return the public offline-safe header.
- Shows saved screen counts and browser storage estimates.
- Supports updating and removing each pack without changing WordPress or Explorer data.
- Makes Offline Packs discoverable from Explorer Profile while preserving the five-tab mobile navigation.

## Privacy

- Trips, Profile, Recaps, Activity, XP, uploads, and account changes remain network-only.
- The service worker rejects unknown pack identifiers and arbitrary URLs.
- No background sync, POST queue, private page caching, or gameplay mutation is introduced.
