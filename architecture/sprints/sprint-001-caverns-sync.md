# Sprint 001 — The Caverns Event Sync

## Objective

Deploy and validate the existing Concert Intelligence pipeline so The Caverns shows are discovered through the Platform Core API and imported into Traveler Activities without duplicate listings.

## Production flow

```text
Tixr group page
  -> Platform Core API /v1/providers/tixr/sync
  -> Playwright discovery and event normalization
  -> TN Game OS Concert Intelligence
  -> Import Queue
  -> Traveler Activity
```

## Security prerequisite

Rotate any API key that has been shared in chat, screenshots, logs, or support messages. Store the replacement only in the API host environment and the WordPress Concert API Settings screen. Never commit it to this repository or package it inside a release ZIP.

## Phase 1 — Platform Core API

1. Deploy `services/platform-core-api` to a Node 20+ host.
2. Install production dependencies.
3. Install the Playwright Chromium runtime and required operating-system dependencies.
4. Configure the API key and service environment through the host's secret manager.
5. Start the service and verify `GET /health` returns HTTP 200 with `browser.ok: true`.
6. Test the provider endpoint with The Caverns source URL:

```bash
curl -X POST "$PLATFORM_API_URL/v1/providers/tixr/sync" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $PLATFORM_API_KEY" \
  --data '{"source_url":"https://www.tixr.com/groups/thecaverns","force":true}'
```

Expected result:

- `ok` is `true`.
- `data.discovered` is greater than zero when upcoming events exist.
- `data.events` contains normalized event objects.
- Each event includes a stable `external_id`, canonical `url`, `title`, and where available `start`, `image`, venue, price, and status.

## Phase 2 — WordPress configuration

1. Install the TN Game OS release generated from this repository.
2. Open **TN Game OS -> API Settings**.
3. Set the Platform Core API base URL. Do not append `/v1/providers/tixr/sync`; TN Game OS adds that path.
4. Enter the rotated API key and run the API connection test.
5. Open **TN Game OS -> Venues -> The Caverns** and verify:
   - Address: `555 Charlie Roberts Rd, Pelham, TN 37366`
   - Activity service tag: `concerts`
   - Primary destination/location: Pelham
6. Open **TN Game OS -> Concert Sources -> The Caverns — Tixr** and verify:
   - Provider: Tixr
   - Source URL: `https://www.tixr.com/groups/thecaverns`
   - Venue defaults: The Caverns
   - Scheduled sync: enabled
   - Automatic publishing: disabled during validation

## Phase 3 — Manual validation

1. Select **Sync this source now**.
2. Confirm the source diagnostics show a successful API request and events received.
3. Review at least three queue items, including one with an image and one with a known date/time.
4. Import one item manually.
5. Verify the resulting Traveler Activity:
   - Is assigned to Activity type `concerts`.
   - Is assigned to Pelham.
   - Uses the official event title and description.
   - Preserves the official ticket URL as the external CTA.
   - Stores the poster in the WordPress Media Library and sets it as the featured image.
   - Has booking disabled where the concert template requires an external ticket action.
6. Run the source sync a second time and import the same event again if it returns to the queue. Confirm the existing Activity is updated rather than duplicated.

## Phase 4 — Scheduled operation

After manual validation succeeds:

1. Enable automatic publishing for The Caverns source.
2. Confirm the six-hour WordPress cron event exists.
3. Trigger one cron run manually through the hosting panel or WP-CLI.
4. Confirm source diagnostics update the last-sync timestamp and counts.
5. Verify a failed API request records diagnostics and does not produce a wp-admin fatal error.

## Acceptance checklist

- [ ] API health reports browser readiness.
- [ ] Tixr sync discovers upcoming Caverns events.
- [ ] WordPress receives normalized events.
- [ ] Imported listings use `concerts` and Pelham.
- [ ] Featured images are sideloaded locally.
- [ ] Official ticket URLs are preserved.
- [ ] Second sync updates instead of duplicating.
- [ ] Cancelled and postponed statuses remain visible for editorial handling.
- [ ] Scheduled sync runs every six hours.
- [ ] Diagnostics are usable when a provider fails.
- [ ] No production secret exists in Git history or release archives.

## Rollback

1. Disable automatic publishing on the concert source.
2. Disable the source's scheduled sync.
3. Redeploy the last known-good Platform Core API archive.
4. Reinstall the last known-good TN Game OS ZIP from `dist/`.
5. Rotate the API key if a deployment exposed it.
6. Keep imported Activities in draft until the source is validated again.

## Sprint exit

Sprint 001 is complete when a real upcoming Caverns show is discovered, manually imported, re-synced without duplication, and then successfully processed by the scheduled sync path.