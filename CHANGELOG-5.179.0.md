# TN Game OS 5.179.0 — Accessible Draft Review Position

- Shows a count-only explanation of the field currently selected by private guided review.
- Connects the focused name, note, or date field to the shared explanation with `aria-describedby` before focus moves.
- Preserves existing field descriptions, avoids duplicate references, and removes only the shared review reference when navigation moves or the selected field is resolved.
- Recomputes the selected position from current connected, enabled dirty fields after reviews, saves, failures, reverts, disabled fields, disconnected fields, and restored pages.
- Distinguishes page-wide and type-specific review sequences using field kinds and counts only; no adventure name, note, date, plan title, or other private draft value enters the explanation or attribute.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.
- VM tests cover page and type positions, description preservation, navigation cleanup, resolution cleanup, unavailable fields, restored state, private-text exclusion, and request-free guidance.
