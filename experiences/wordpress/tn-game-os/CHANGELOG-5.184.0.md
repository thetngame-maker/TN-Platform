# TN Game OS 5.184.0 — Keyboard-Safe Confirmation Dismissal

- Lets an owner press Escape inside the private completion confirmation to dismiss it.
- Reuses the Dismiss button's accessible focus and status handoff.
- Ignores unrelated keys, composition input, and Escape while the confirmation is hidden.
- Prevents the handled Escape keystroke without submitting a form or changing a field.
- Copies no adventure name, note, date, plan title, or other private draft value.
- Adds no timer, autosave, durable storage, endpoint, request, background work, or public data.
- VM tests cover Escape dismissal, non-Escape and composition guards, hidden-state safety, later edit cycles, status restoration, private-text exclusion, and request-free behavior.
