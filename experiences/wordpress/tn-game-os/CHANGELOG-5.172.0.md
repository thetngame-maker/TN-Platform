# TN Game OS 5.172.0 — Accessible Paused Action Guidance

- Schedule-dependent controls waiting for refresh remain focusable and carry `aria-disabled="true"` plus a visible paused treatment.
- Existing capture guards still block clicks and checkbox changes before stale handlers run, including events from nested button content.
- Blocked actions scroll to the refresh reminder and focus either that reminder (while drafts or a request remain) or its enabled refresh button.
- Existing in-flight disabled states are preserved; a fresh page load restores ordinary server-rendered controls.
- Guidance contains no plan title, notes, date, or other private draft data. No storage, endpoint, request, autosave, or delayed focus was added.
- VM tests cover clean/dirty/pending focus targets, nested events, missing reminder markup, checkbox rollback, and request-free blocking.
