# TN Game OS 5.103.0 — Explorer Profile 2.0

## Canonical Explorer snapshot

- Adds a reusable Explorer Profile 2.0 data model shared by Profile, Nearby XP, completion, and future recaps.
- Normalizes discoveries from completed games, visited Top Sights, completed trails/adventures, trip stops, and journal activity.
- Persists a canonical `_tng_discovered_objects` ledger while preserving all legacy progression data.

## Tennessee completion

- Calculates completion against the live, map-ready Universal Map collection.
- Shows mapped discoveries completed, total available, remaining discoveries, and statewide percentage.
- Breaks completion down by every populated Universal Map collection.

## Profile experience

- Adds Discoveries as a first-class Explorer stat.
- Integrates Games, Checkpoints, Top Sights, Trips, and Photos progression directly into the profile rather than injecting a duplicate block later.
- Adds clearer level titles from New Explorer through Grand Tennessee Explorer.
- Rebuilds the Profile hero, level card, stats, completion ring, and category cards for approximately 390px widths.
- Keeps legacy GamiPress achievements, ranks, XP, and progression bridges compatible.
