# TN Game OS 5.112.0 — Saved Adventures

## Private itinerary library

- Adds a native `/adventures/` workspace for signed-in Explorers.
- Keeps up to 12 explicitly saved Adventure AI plans in private user metadata.
- Carries forward the existing last-plan record without a destructive migration.
- Shows stop summaries, update time, and direct reopen controls.

## Reuse and editing

- Reopens the exact saved order, start time, travel buffer, and mapped stop coordinates in Adventure AI.
- Updates an existing library entry when a reopened plan is saved again.
- Adds explicit rename and duplicate controls.
- Connects Saved Adventures to Trips and preserves the five-tab navigation context.

## Safety

- Every plan-management action requires a signed-in Explorer and a valid nonce.
- Only published stop IDs and sanitized, bounded plan metadata are retained.
- No automatic public sharing, background writes, bulk actions, or permanent deletion are introduced.
