# TN Game OS 5.167.0 — Unsaved Adventure Date Exit Warning

- Include changed dates, partial invalid date input, and pending date saves/clears in the existing shared browser warning.
- Confirm submitted-date baselines only after successful responses, preserving newer date edits.
- Clear the date input after a successful clear only when no newer edit needs preserving.
- Release pending controls and synchronize the warning before a successful schedule reload; failure never reloads.
- Keep other unsaved edits protected and explain that cancelling a reload requires a later refresh to update preparation details.
- Add executable date/input/save/clear/warning regression tests while retaining server validation and privacy boundaries.

No autosave, extra request, or draft storage. Browser warnings are best-effort, particularly on mobile; successful schedule changes still rely on page reload for full preparation and calendar refresh.
