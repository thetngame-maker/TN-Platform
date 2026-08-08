# Quest Runtime Rebuild

## Objective

Rebuild the TN Game Quest Runtime as a first-class TN Game OS module on top of the current `main` architecture.

The rebuild must preserve Developer Mode isolation and avoid reintroducing the historical collection of overlapping runtime patches.

## Delivery sequence

### Phase 1 — Runtime shell

- Register one canonical Quest Runtime module.
- Route `?tng_quest_runtime_id=<id>` deterministically.
- Render the quest title, summary, XP, duration, and checkpoint count.
- Make **Start Quest** transition into an active runtime without depending on legacy inline scripts.
- Add a visible diagnostic state when JavaScript cannot initialize.

### Phase 2 — Progress

- Persist started/completed state locally.
- Add authenticated REST synchronization.
- Restore/reset controls.
- Keep anonymous and logged-in behavior consistent.

### Phase 3 — Checkpoints

- Render ordered checkpoints.
- Lock future checkpoints until the current checkpoint is completed.
- Support manual and administrator test claims first.
- Add validation and clear failure states.

### Phase 4 — Location and map

- Add browser geolocation only after the runtime shell is stable.
- Render the route/checkpoints through one map adapter.
- Enforce checkpoint arrival radii.
- Add permission, accuracy, and unavailable-location states.

### Phase 5 — Developer tools

- Integrate with the central request-scoped Developer Manager.
- Allow administrator override only while `?tng_dev=1` is present.
- Keep the public/player runtime unchanged for logged-in administrators by default.

## Architectural rules

1. One PHP runtime module owns routing, rendering, assets, and REST registration.
2. One JavaScript entry point owns runtime state and interactions.
3. No mutation-observer bootstrap patches.
4. No multiple scripts competing for the same button or `data-*` boot flag.
5. No administrator behavior inferred solely from login state.
6. Runtime failures must render a visible diagnostic instead of silently leaving dead controls.
7. Each phase must be independently testable before the next phase is added.

## Initial acceptance checklist

- [ ] Runtime URL renders the selected `tng_quest` rather than the homepage.
- [ ] Start Quest works in Safari and Chrome.
- [ ] Start Quest works logged in and logged out.
- [ ] Developer Mode remains off by default.
- [ ] `?tng_dev=1` enables administrator testing without changing player behavior.
- [ ] No browser-console access is required to understand a failure.
