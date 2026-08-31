# TN Game OS 5.165.0 — Unsaved Planning Notes Exit Warning

- Attach a browser exit warning only while planning notes differ from their confirmed saved baseline or a notes save is awaiting confirmation; remove it when every notes field is saved or reverted and no notes save is pending.
- Keep newer edits protected when an earlier snapshot saves, and retain warning state after failed saves.
- Include notes on hidden cards and resynchronize when the page is shown again.
- Restore Saved/Optional labels after a full revert.
- Exercise real input, save, and exit-warning handlers with executable regression tests.

No autosave, draft storage, new request, or private text in the generic browser dialog. This covers planning notes only. Browser support is best-effort, especially when a mobile browser is terminated; users must still save notes explicitly.

Browser behavior reference: https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event
