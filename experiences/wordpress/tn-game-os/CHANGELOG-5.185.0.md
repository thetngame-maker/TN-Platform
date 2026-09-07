# TN Game OS 5.185.0 — Discoverable Keyboard Dismissal

- Exposes Escape as the keyboard shortcut for the private confirmation's Dismiss action.
- Connects the button to concise assistive guidance explaining the scoped shortcut.
- Keeps the guidance visually hidden so the compact confirmation layout does not gain clutter.
- Uses the existing keyboard handler, focus handoff, status lifecycle, and request-free behavior.
- Copies no adventure name, note, date, plan title, or other private draft value.
- Adds no timer, autosave, durable storage, endpoint, request, background work, or public data.
- VM and structural tests cover semantic shortcut discovery, hidden guidance, unique relationships, Escape behavior, private-text exclusion, and request-free interaction.
