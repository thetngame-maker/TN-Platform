# TN Game OS 5.144.0

## Offline Adventure Storage Summary

- Measures each device-local Adventure Pack's cached public response bodies.
- Displays a readable per-pack size and combined device-library footprint.
- Adds a dedicated “Remove all from device” action with a separate confirmation.
- Clears only release-stable Adventure Pack caches, staging remnants, and their device metadata.
- Leaves standard Offline Packs, app assets, Saved Adventures, accounts, progress, and XP intact.
- Performs measurement and cleanup locally without a network request or server activity record.
