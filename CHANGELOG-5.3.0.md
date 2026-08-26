# TN Game OS 5.3.0

## Added

- Milestone 4 canonical Entity Bridge for WordPress.
- Hidden `tng_entity` persistence type for platform-owned event and venue records.
- Stable canonical entity IDs shared by Review Studio queue items, Traveler activities, and venue records.
- Immutable event snapshots with version and checksum tracking.
- Source provenance using provider, external ID, source URL, and external key.
- Canonical `held_at` event-to-venue relationships with stable relationship IDs.

## Changed

- Concert publishing now triggers canonical entity creation or update after the Traveler activity is saved.
- Re-importing unchanged concert data reuses the existing entity without creating a duplicate snapshot.
- Changed concert data increments the canonical entity version and records a new snapshot.
- Release artifact renamed to `tn-game-os-v5.3.0-entity-engine.zip`.

## Compatibility

- Existing Concert Intelligence and Review Studio workflows remain authoritative.
- Existing Traveler activities continue to publish normally.
- No Traveler theme files are modified.
- Canonical entity storage is implemented as a WordPress infrastructure adapter and can later be replaced without changing importer behavior.
