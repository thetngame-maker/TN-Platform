# TN Game OS 5.177.0 — Private Draft Type Review Shortcuts

- Adds count-only review shortcuts for unsaved adventure names, notes, and dates to the owner-only edit-review panel.
- Shows only shortcuts backed by a connected, enabled dirty field and cycles review within the chosen type.
- Keeps the existing all-edit review sequence available, reveals filtered plans, opens notes, and focuses the selected field without submitting it.
- Recalculates from current state after typing, saves, failures, reverts, disabled fields, disconnected fields, and restored pages.
- Never copies a name, note, date, plan title, or other private draft value into a label or attribute.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.
- VM tests cover shortcut visibility, singular and plural labels, type-specific cycling, unavailable drafts, save outcomes, restored state, private-text exclusion, and request-free navigation.
