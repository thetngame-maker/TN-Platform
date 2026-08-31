# TN Game OS 5.170.0 — Draft-Aware Schedule Refresh

- Successful date saves and clears defer their internal page refresh while any name, notes, or date field remains dirty or awaiting confirmation.
- An owner-only, non-printing reminder explains that preparation details and calendar exports need a refresh and offers a guarded manual refresh button.
- Saving or reverting remaining edits enables the button but never starts a delayed reload automatically. Manual refresh also refuses an in-flight library update.
- Failed saves do not request refresh; hidden drafts, newer input, and invalid partial dates stay protected without relying on native beforeunload support for this internal navigation.
- No new endpoint, draft storage, autosave, or background request. Other navigation continues to use the existing best-effort exit warning.
- VM regression tests exercise the real save, clear, draft, review, and refresh handlers together, including missing optional markup and failed responses.
