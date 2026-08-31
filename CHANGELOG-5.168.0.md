# TN Game OS 5.168.0 — Private Unsaved Edit Review

- Owner-only Saved Adventures summary counts unsaved name, notes, and date fields across plans, including filtered-out cards and invalid partial date input.
- Tracks pending saves separately and follows confirmed baselines, failed saves, newer input, full reverts, and restored pages.
- Review cycles through editable drafts, reveals hidden plans, opens notes, and focuses the selected field without saving or discarding content.
- Navigation clears the current search/filter, preserves sorting and stored preferences, and invalidates older delayed focus requests.
- Summary contains counts only, disappears when clean, and is excluded from print. No autosave, draft persistence, endpoint, public exposure, or background requests.
- VM integration checks execute the real warning, review, filter, input, request, and form handlers. Existing PHP endpoint privacy/access tests remain in the release suite.
