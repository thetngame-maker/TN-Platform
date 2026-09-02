# TN Game OS 5.173.0 — Guided Schedule Refresh Draft Handoff

- Adds a generic Review remaining edit action to the persistent schedule-refresh reminder whenever unsaved private fields prevent refresh.
- Reuses the existing private unsaved-edit reviewer to reveal and focus the next editable field without submitting, copying, or exposing its contents.
- Blocked schedule actions focus the review action when a draft is reviewable, the reminder when only an in-flight save remains, or the refresh button when the page is ready.
- Hides the review action after drafts resolve and keeps refresh guarded until all dirty fields and pending updates are clear.
- Guidance contains no adventure name, notes, date, or other private draft data.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.
- VM tests cover reviewable drafts, pending-only states, hidden/disabled fields, button handoff, clean refresh, missing optional markup, and request-free navigation.
