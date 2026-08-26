# TN Game OS 5.108.0

## Offline Mode v1

- Adds an installable web app manifest for TN Game app routes.
- Serves a versioned, root-scoped service worker from the WordPress application.
- Precaches TN Game app-shell CSS and JavaScript with resilient partial installation.
- Caches only anonymous public Explore, Map, Play, and directory page responses.
- Keeps authenticated and private routes network-only, including Trips, Profile, Recaps, and Activity.
- Never queues POST requests, XP awards, photo uploads, or other gameplay mutations.
- Adds a branded read-only offline fallback and connection-state indicator.
- Uses stale-while-revalidate behavior for same-origin TN Game plugin assets.
- Cleans obsolete TN Game caches automatically when the plugin version changes.
