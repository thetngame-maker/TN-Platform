# Color Clash v0.2.1 Deal Fix

This patch replaces the Sprint 2 controller with a Roku-safe implementation.

- Removes the runtime `Randomize(0)` call used during dealing.
- Seeds the random number generator safely with `Rnd(0)` during initialization.
- Builds player hands as explicit arrays.
- Shows a visible `DEALING...` state before deck creation.
- Preserves legal plays, drawing, bots, action cards, counts, and winner detection.
