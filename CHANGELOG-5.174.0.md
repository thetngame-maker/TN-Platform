# TN Game OS 5.174.0 — Private Refresh Resolution Progress

- Shows count-only progress in the schedule-refresh reminder for unsaved fields and saves still in progress.
- Updates the review action with the current dirty-field count and uses a generic waiting label when no field is currently reviewable.
- Sets `aria-busy` while a private field save is pending, without placing private content in attributes or announcements.
- Recomputes progress from current field values, validation, and request state after typing, saves, failures, reverts, and restored pages.
- Preserves the existing guided review, stale-action guard, explicit refresh, and best-effort exit warning behavior.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.
- VM tests cover multiple hidden drafts, invalid dates, pending saves, failures, count reductions, clean state, and private-text exclusion.
