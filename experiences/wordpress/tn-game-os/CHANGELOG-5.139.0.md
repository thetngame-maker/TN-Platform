# TN Game OS 5.139.0

## Release-Stable Offline Adventures

- Stores Saved Adventure public stop screens in a release-stable device cache.
- Preserves complete Adventure Packs across normal TN Game plugin and service-worker upgrades.
- Migrates legacy versioned Adventure Pack caches locally before old app caches are removed.
- Skips duplicate cached responses and removes abandoned staging caches during activation.
- Keeps the existing explicit update, public-safety, storage-headroom, and rollback protections.
- Adds no network refresh, account data, private caching, or queued gameplay writes during migration.
