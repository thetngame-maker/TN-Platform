# TN Game ↔ TN Social Studio integration contract

The WordPress application and Social Studio are separate services. Integration is API-based and starts read-only/simple before becoming bi-directional.

## Phase 1 — launcher

Content Studio exposes **Social Studio** as the primary social-management destination and opens `https://studio.thetngame.com`.

No social API credentials are stored in WordPress.

## Phase 2 — content handoff

WordPress can send a TN Game object into Social Studio as a content idea.

Initial payload shape:

```json
{
  "source": "tn_game",
  "source_type": "place",
  "source_id": 12345,
  "title": "Foster Falls",
  "canonical_url": "https://thetngame.com/...",
  "town": "Sequatchie, TN",
  "category": "waterfall",
  "summary": "...",
  "featured_image_url": "https://thetngame.com/wp-content/uploads/...",
  "gallery": [],
  "suggested_campaign": "Waterfall Wednesday"
}
```

Supported `source_type` values initially:

- `place`
- `trail`
- `top_sight`
- `event`
- `game`

## Phase 3 — permission records

Creator-derived assets use a permission object rather than a generic media upload.

```json
{
  "creator": {
    "platform": "instagram",
    "handle": "examplecreator",
    "profile_url": "..."
  },
  "source_post_url": "...",
  "status": "requested",
  "requested_at": "...",
  "approved_at": null,
  "expires_at": null,
  "required_credit": "Photo: @examplecreator",
  "notes": "..."
}
```

Permission states:

- `not_contacted`
- `requested`
- `approved`
- `declined`
- `expired`
- `revoked`

The composer must refuse creator-media publishing unless the associated permission state is `approved` and not expired/revoked.

## Phase 4 — publishing feedback

Social Studio returns publication information to TN Game Content Studio:

- network
- social account
- scheduled time
- publish status
- public post URL
- failure/retry state
- campaign
- TN source object

This allows the WordPress Content Studio overview to show social activity without becoming the publishing engine.

## Authentication

Do not reuse WordPress database sessions or share passwords between services.

Initial server-to-server integration should use a scoped secret/token over HTTPS. Later, replace/extend this with an OAuth-style service authorization if needed.

Scopes should be explicit, for example:

- `tn_content:read`
- `tn_content:handoff`
- `social_status:read`
- `creator_permissions:read`

## Safety rules

- Never expose platform access tokens to WordPress/front-end JavaScript.
- Never ingest/download third-party creator media merely because it was discovered.
- Discovery records may retain metadata/links needed for review, but media reuse/download belongs behind the permission workflow.
- Preserve source URL, creator identity, permission evidence and required attribution through the publishing record.

## Future Content Studio tabs

The intended TN Game Content Studio surface becomes:

- Overview
- Local Discovery
- Town Scanner
- Changes Inbox
- Town Monitoring
- **Social Studio**
- Social Discovery
- Creator Permissions
- Campaigns
- Usage / Reliability

BrightBean remains the primary calendar, composer, inbox, approvals, publishing and analytics application behind **Social Studio**.
