# TN Game OS 5.166.0 — Unsaved Adventure Name Exit Warning

- Cover unsaved names and notes with a single browser exit warning, including hidden plans and saves awaiting confirmation.
- Track each name against its last successful canonical rename response; preserve newer name edits and retain the saved baseline on failure.
- Keep the warning active while any covered field remains dirty or has a pending save, and remove it after all are clean.
- Resynchronize restored pages without accumulating duplicate handlers or removing another component's warning.
- Add executable input, rename, notes, failure, retry, and mixed-field regression coverage.

No autosave, draft storage, extra request, or private text in dialogs. Browser warnings remain best-effort, especially on mobile; dates remain outside this warning's scope.
