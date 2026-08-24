# TN Social Studio

TN Social Studio is The TN Game's social-media management workspace, using BrightBean Studio as the upstream scheduling, publishing, inbox, approvals, analytics, and media engine.

## Upstream

- Project: `brightbeanxyz/brightbean-studio`
- Pinned upstream commit: `8fb037e07ef0da2cea4c857c9aeeb33a0c9db93e`
- License: AGPL-3.0

The upstream application is Django 5 / Python 3.12+ and is designed to run as a separate application with PostgreSQL and a background worker. It should **not** be embedded into the WordPress PHP runtime.

## TN Game architecture

Recommended production shape:

```text
TheTNGame.com (WordPress / TN Game OS)
        |
        +-- Content Studio
        |      +-- Local Discovery
        |      +-- Town Scanner
        |      +-- Changes Inbox
        |      +-- Social Studio  ---> studio.thetngame.com
        |
        +-- TN Game REST/API layer
                       |
                       +-- TN Social Studio extensions

studio.thetngame.com
        |
        +-- BrightBean Studio upstream
        +-- TN Game branding/theme overrides
        +-- TN-specific extension apps
```

## Why BrightBean is the base

BrightBean already provides the expensive foundation we would otherwise need to rebuild:

- multi-workspace/team support
- calendar and queues
- content composer and per-platform overrides
- approvals and comments
- scheduled publishing with retries
- first-party platform API integrations
- unified inbox
- analytics
- media library
- client portal
- notifications
- white-label workspace branding

TN Game should extend this foundation rather than fork every feature into WordPress.

## TN-specific extension roadmap

Keep custom functionality in separate TN modules wherever practical so upstream updates remain mergeable.

### Phase 1 — TN-branded core

- TN Game visual theme and navigation
- `studio.thetngame.com`
- primary TN Game workspace
- Facebook + Instagram connections first
- calendar, composer, media library, approvals, analytics
- WordPress Content Studio launcher

### Phase 2 — Permission + inspiration workflow

- Social Discovery inbox for discovered public posts
- creator/contact record
- permission status: `not contacted`, `requested`, `approved`, `declined`, `expired`
- outreach templates
- approval proof/audit trail
- download approved media only when permitted
- attach creator attribution requirements
- move approved content into composer/calendar

### Phase 3 — TN Game content intelligence

- import Local Discovery places into content ideas
- trail / waterfall / event content suggestions
- Content Studio trend research
- hashtag/place monitoring adapters where platform terms allow
- reusable post campaigns by town/category
- suggested captions from TN Game place data
- automatic source/credit metadata

### Phase 4 — publishing expansion

- Facebook
- Instagram
- Threads
- TikTok
- YouTube
- Pinterest
- Google Business Profile
- LinkedIn
- Bluesky / Mastodon where useful

## Integration boundaries

### WordPress remains responsible for

- TN Game public site
- Explorer/map/trips/games
- Local Discovery/Town Scanner
- user progression
- public place/trail/event content

### Social Studio remains responsible for

- social accounts and platform credentials
- drafts
- media used for social posts
- approval workflow
- publishing queues
- platform inbox
- social analytics

### Shared data

Exchange through explicit API contracts instead of direct database coupling. Initial objects:

- `place`
- `trail`
- `event`
- `top_sight`
- `content_idea`
- `approved_creator_asset`

## Deployment

BrightBean's upstream Docker stack contains:

- Django web app
- background worker
- PostgreSQL
- migration service
- persistent media volume

For production, use PostgreSQL and S3-compatible object storage. Run Social Studio as an independent service/subdomain. Do not place its database tables inside the WordPress database.

## Licensing

BrightBean Studio is AGPL-3.0. Any TN-modified network-accessible version must comply with the AGPL, including offering the corresponding source to users of the modified service. Keep license notices and upstream attribution intact.

## Upstream update strategy

1. Review BrightBean release/commit changes.
2. Update `UPSTREAM.lock` only after testing.
3. Rebase/merge TN extension branch against upstream.
4. Run migrations/tests.
5. Deploy Social Studio independently of WordPress releases.

This separation lets TN Game add substantial custom capabilities without freezing BrightBean at a permanent fork.