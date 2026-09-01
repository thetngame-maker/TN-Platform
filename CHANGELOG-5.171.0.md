# TN Game OS 5.171.0 — Refresh-Required Preparation Protection

- Confirmed date saves/clears pause schedule-dependent controls until the Saved Adventures page refreshes: readiness, packing, prep focus, next action, starts, calendar exports, and printing.
- Disables controls and captures click/change events before stale handlers run; blocked checkbox changes are reverted locally without saving or updating preparation counts.
- Keeps draft review, editing, saves, and guarded refresh usable. Failed or pending schedule requests do not activate the pause.
- The existing refresh reminder explains the paused actions. Guards also remain active if optional reminder markup is missing.
- No new endpoint, autosave, storage, background request, or public data.
- VM tests cover all guarded controls, nested click targets, checkbox rollback, rejected schedules, missing markup, and continued draft saves.
