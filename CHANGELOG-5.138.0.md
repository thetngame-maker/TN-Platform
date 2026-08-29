# TN Game OS 5.138.0

## Offline Adventure Storage Guard

- Checks browser storage headroom before opening a temporary Adventure Pack staging cache.
- Reserves at least 8 MB or 2% of the available browser quota for a safe replacement.
- Refuses low-space downloads before changing or deleting any working pack entry.
- Reports whether previous public stop screens remain available on the device.
- Suggests removing another offline pack when a first download lacks safe storage.
- Adds no automatic deletion, background download, private caching, or queued gameplay writes.
