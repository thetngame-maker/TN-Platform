# Milestone 4 — Entity Engine

## Objective

Introduce the first canonical destination entity model shared by TN Platform, TN Game OS, Review Studio, Traveler publishing, and future applications.

Milestone 4 changes the platform from reviewing importer-specific records to reviewing and publishing stable platform entities.

## Release target

- TN Game OS: `5.3.0`
- Platform Core API: `3.2.0`
- Working branch: `feature/milestone-4-entity-engine`

## Architectural rules

1. Platform entities are the canonical source of truth.
2. WordPress posts and Traveler activities are projections, not canonical records.
3. Import providers may propose entity changes but may not publish directly.
4. Every entity has a stable platform ID independent of WordPress post IDs.
5. Entity changes create immutable snapshots.
6. Relationships are first-class records rather than embedded IDs.
7. Domain services depend on contracts, not WordPress APIs.
8. Existing Concert Intelligence and Review Studio behavior must remain compatible during migration.

## First supported entity types

Milestone 4 begins with the smallest useful set:

- `event`
- `venue`
- `place`

Additional types such as trail, waterfall, lodging, restaurant, and attraction will use the same contracts after the foundation is proven.

## Canonical entity shape

```json
{
  "id": "ent_...",
  "type": "event",
  "version": 1,
  "status": "review",
  "title": "Example Event",
  "attributes": {},
  "relationships": [],
  "source_refs": [],
  "created_at": "2026-07-25T00:00:00Z",
  "updated_at": "2026-07-25T00:00:00Z"
}
```

## Lifecycle

```text
Discovered
→ Normalized
→ Review
→ Approved
→ Published
→ Verified
→ Archived
```

Milestone 4 implements the lifecycle states and guarded transitions needed by Review Studio. Later milestones may add richer editorial assignments and approvals.

## Core contracts

The initial public API must include:

- `EntityRepository`
- `EntitySnapshotRepository`
- `RelationshipRepository`
- `EntityTypeRegistry`
- `EntityNormalizer`
- `EntityValidator`
- `EntityPublisher`

## Storage

The initial implementation may use the Platform Core API knowledge store, provided that:

- storage is accessed only through repositories;
- API routes do not expose storage internals;
- records can later move to a dedicated database without changing consumers;
- WordPress IDs are stored only as external references.

## Review Studio integration

Review Studio will evolve in two compatible steps:

1. Existing `tng_concert_import` queue items continue to load.
2. The platform creates or updates a canonical `event` entity for each reviewed item.
3. Review Studio displays entity identity, version, lifecycle state, source references, and pending changes.
4. Publish/update creates a Traveler projection linked back to the entity.

No existing queue record may be lost during the migration.

## API surface

Initial routes:

```text
GET    /entities
POST   /entities
GET    /entities/:id
PATCH  /entities/:id
GET    /entities/:id/snapshots
GET    /entities/:id/relationships
POST   /entities/:id/relationships
```

All mutations must validate input and return deterministic error responses.

## Events

Milestone 4 introduces these domain events:

- `entity.discovered`
- `entity.created`
- `entity.updated`
- `entity.transitioned`
- `entity.snapshot.created`
- `relationship.created`
- `entity.published`

## Workstreams

### M4.1 — Contracts and identity

- Entity contracts and schemas
- Stable entity ID generator
- Entity type registry
- Lifecycle states and transitions

### M4.2 — Repository and snapshots

- Entity repository
- Snapshot repository
- Optimistic version checking
- Repository tests

### M4.3 — Relationships and source references

- Relationship records
- External/source references
- Venue and place linkage
- Relationship tests

### M4.4 — API

- Entity routes
- Validation and errors
- Contract tests
- Health and diagnostics integration

### M4.5 — Review Studio bridge

- Convert reviewed concerts into canonical event entities
- Show platform ID, lifecycle, and version
- Preserve existing publishing behavior
- Link Traveler projection to entity

### M4.6 — Release and staging

- Migration compatibility tests
- Full repository checks
- Release packaging
- Staging smoke test
- Rollback verification

## Acceptance criteria

1. A reviewed concert receives one stable platform entity ID.
2. Re-reviewing or re-importing the same source updates the same entity rather than creating a duplicate.
3. Every successful mutation increments the entity version and creates an immutable snapshot.
4. Venue relationships are represented as first-class relationships.
5. Review Studio exposes entity ID, version, lifecycle state, and source provenance.
6. Traveler publishing remains functional and stores a reference to the canonical entity.
7. Existing queue items remain reviewable during migration.
8. API and contract tests cover create, read, update, transition, snapshot, and relationship behavior.
9. Node checks, PHP checks, automated tests, and release packaging pass.
10. The generated TN Game OS ZIP passes staging activation, review, publishing, persistence, and rollback smoke tests.

## Explicitly out of scope

- Full trail and Top Sight migration
- Recommendation graph
- AI-generated entity content
- Public entity editing API
- Multi-destination tenancy
- Dedicated production database migration
- Removing the existing concert queue

## Definition of done

Milestone 4 is complete when Review Studio can turn an imported concert into a versioned canonical event entity, relate it to a venue/place, publish a linked Traveler projection, and safely repeat the workflow without duplication or data loss.
