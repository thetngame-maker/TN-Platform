# TN Game OS 5.111.0 — Adventure AI v2

## Editable itinerary workspace

- Adds accessible move-earlier, move-later, remove, undo, and reset controls to every generated plan.
- Recalculates arrival times and total duration after every itinerary edit.
- Lets Explorers adjust the start time and choose a 10, 20, 30, 45, or 60 minute travel buffer.
- Shares and saves the edited stop order rather than the original generated order.

## Universal route preview

- Resolves stop coordinates through the existing Universal Map registry.
- Draws a lightweight, dependency-free route preview that updates with the itinerary order.
- Preserves a useful itinerary fallback when a stop does not yet have verified coordinates.

## Data safety

- Generation remains read-only and works without an external AI key.
- Server persistence still requires a signed-in Explorer and an explicit Save action.
- Saved plans contain published stop IDs, sanitized prompt/title text, and bounded timing preferences only.
