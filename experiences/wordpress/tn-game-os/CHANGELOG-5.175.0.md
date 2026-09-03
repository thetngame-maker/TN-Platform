# TN Game OS 5.175.0 — Accessible Paused Control Explanation

- Connects each focusable refresh-paused preparation, start, calendar, and print control to the persistent schedule explanation with `aria-describedby`.
- Preserves existing description IDs, appends the shared reminder ID once, and avoids duplicates across repeated synchronization.
- Reuses the count-only private-safe reminder; no adventure name, notes, date, or hidden draft value is copied into an accessibility attribute.
- Keeps capture guards, checkbox rollback, focus guidance, and missing-reminder fallback behavior unchanged.
- A fresh server-rendered page restores ordinary control semantics after current schedule state loads.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.
- VM tests cover all paused control types, existing descriptions, repeated sync, missing message IDs, private-text exclusion, and request-free behavior.
