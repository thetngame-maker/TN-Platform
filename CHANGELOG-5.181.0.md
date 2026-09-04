# TN Game OS 5.181.0 — Dismissible Draft Resolution Confirmation

- Adds an explicit Dismiss action to the private completion confirmation.
- Keeps confirmation visible until the owner dismisses it or begins another edit cycle.
- Keyboard or pointer dismissal changes no field, submits no form, and sends no request.
- Later draft activity can produce a fresh completion confirmation after resolution.
- Keeps the generic message and action out of print and exposes no private draft values.
- Adds no timer, autosave, storage, endpoint, request, background work, or public data.
- VM tests cover display, dismissal, clean-page stability, repeated edit cycles, save and revert resolution, missing optional summary markup, private-text exclusion, and request-free interaction.
