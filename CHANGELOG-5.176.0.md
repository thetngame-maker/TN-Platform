# TN Game OS 5.176.0 — Private Draft Type Summary

- Adds a separate count-only breakdown of unsaved adventure names, notes, and dates to the owner-only edit-review panel.
- Preserves the established total fields, affected adventures, and save-in-progress summary while making the remaining edit types easier to scan.
- Recalculates from current fields after hidden-plan edits, partial invalid dates, saves, failures, reverts, and restored-page events.
- Hides the type breakdown when no unsaved field remains, including states where a clean save is still finishing.
- Never copies a name, note, date, plan title, or other private draft value into the summary.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.
- VM tests cover singular/plural type counts, mixed hidden drafts, invalid dates, pending saves, failures, successful baselines, clean state, and private-text exclusion.
