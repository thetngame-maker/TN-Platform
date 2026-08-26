# TN Game OS 5.104.0 — Nearby XP v1

## Reward every open

- Awards a signed-in Explorer a 5 XP daily open bonus on the first TN Game app visit of the day.
- Uses a durable ledger to prevent duplicate daily awards.
- Falls back to native TN Game XP when GamiPress is unavailable.

## Nearby discoveries

- Adds a compact Nearby XP card across the app shell.
- Checks the Universal Map only after the Explorer taps **Check nearby**.
- Awards 10 XP once per nearby mapped discovery within 0.25 miles.
- Adds the nearby object to the canonical Explorer discovery ledger and Tennessee completion.
- Uses coordinates only for the immediate distance calculation and does not store them.

## Mobile experience

- Keeps the Nearby XP card inline with page content so it does not cover Explore/Map/Play/Trips/Profile navigation.
- Uses a two-row, thumb-friendly layout around 390px.
- Supports dismissal for the current browser session without hiding a newly earned daily bonus.
