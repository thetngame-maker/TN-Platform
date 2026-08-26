# TN Game OS 5.114.0 — Adventure Launch Handoff

- Adds an explicit Start Adventure action to every private Saved Adventure.
- Requires confirmation before replacing a non-empty active trip.
- Replaces the current itinerary atomically instead of silently merging two plans.
- Clears only superseded route and stop-progress data while preserving the Saved Adventures library.
- Carries the selected plan title into Trips and opens Trip Builder for review before Trip Mode.
