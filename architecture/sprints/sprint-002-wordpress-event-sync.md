# Sprint 002 — WordPress Event Sync

## Objective

Connect the production `caverns-official` provider in Platform Core API to the existing TN Game OS Concert Intelligence queue and Traveler Activity importer.

## Architecture

```text
The Caverns official shows page
        ↓
Platform Core API
POST /v1/providers/caverns/sync
        ↓
TN Game OS Concert Platform Sync adapter
        ↓
Concert Intelligence queue
        ↓
Manual review / auto-import
        ↓
Traveler Activity + Media Library poster
```

## Implementation

The WordPress client keeps the existing Concert Intelligence workflow intact. A small provider-aware adapter:

1. Migrates the default Caverns source from `tixr` to `caverns-official`.
2. Changes the source URL to `https://www.thecaverns.com/shows`.
3. Intercepts only legacy Caverns requests aimed at `/v1/providers/tixr/sync`.
4. Replays those requests to `/v1/providers/caverns/sync` with the existing API key and timeout.
5. Returns the normal WordPress HTTP response to Concert Intelligence, preserving queueing, diagnostics, duplicate matching, image sideloading, and Traveler import behavior.

Tixr routing remains unchanged for unrelated future sources.

## Deployment order

1. Confirm Sprint 001 Platform Core API is live on Render.
2. Build the TN Game OS release ZIP from this branch.
3. Back up the current TN Game OS plugin and database.
4. Install the new plugin ZIP in WordPress.
5. Open **TN Game OS → Concert Sources** and confirm the source is renamed to **The Caverns — Official Shows**.
6. Confirm the source URL is `https://www.thecaverns.com/shows`.
7. Keep **Automatically publish new events** disabled for initial validation.
8. Click **Sync this source now**.

## Validation

### First sync

Expected:

- API request succeeds.
- Approximately 32 events are discovered based on the Sprint 001 test.
- Events appear in **Concert Import Queue**.
- Source diagnostics show a successful API response.

### Duplicate validation

Run the source sync a second time.

Expected:

- Existing queue records are updated where data changed.
- No duplicate queue records are created for the same external ID, canonical URL, or venue/date/title combination.

### Manual import validation

Import one event from the queue.

Verify:

- A Traveler Activity is created or updated.
- Activity service tag is `concerts`.
- Venue defaults point to The Caverns / Pelham.
- Ticket CTA uses the external Tixr event URL returned by the official provider.
- Poster is stored in the WordPress Media Library and set as the featured image.
- Start time, doors, status, description, and artist data are retained.

### Second import validation

Sync again and re-import the same event.

Expected:

- The existing Traveler Activity is updated.
- A duplicate Activity is not created.

## Rollback

1. Restore the previous TN Game OS plugin ZIP.
2. Disable the Caverns concert source if necessary.
3. Leave Platform Core API deployed; Sprint 001 remains independently healthy.
4. Queue records and imported Activities remain in WordPress and can be reviewed manually.

## Exit criteria

- [ ] Default Caverns source migrated to the official provider.
- [ ] First source sync queues official events.
- [ ] Second source sync produces no duplicates.
- [ ] One event imports into Traveler correctly.
- [ ] Featured image is sideloaded.
- [ ] External ticket CTA works.
- [ ] Scheduled six-hour sync succeeds.
- [ ] Auto-import remains off until editorial approval.
