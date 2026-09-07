# TN Game OS 5.189.0 — Repeat-Safe Dismissal Handoff

- Ignores repeated dismissal events after the private edit confirmation is already hidden.
- Preserves the original library status snapshot for restoration during the next edit cycle.
- Prevents duplicate focus movement and leaves the generic dismissal result unchanged.
- Adds no field change, endpoint, request, timer, background work, or stored value.
- Copies no adventure name, note, date, plan title, or other private draft value.
- Preserves button and Escape dismissal, atomic status semantics, and later confirmations.
- VM tests cover repeated button calls, hidden Escape handling, snapshot restoration, focus stability, zero requests, and private-text exclusion.
