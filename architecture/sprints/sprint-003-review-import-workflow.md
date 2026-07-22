# Sprint 003 — Concert Review and Traveler Import

## Objective

Give editors a visual, controlled workflow for reviewing normalized concert events before creating or updating Traveler Activities.

## Delivered foundation

- Dedicated **TN Game OS → Review Queue** screen
- Poster-based event cards
- Event date/time, doors, venue, provider, status, and price preview
- Mark Reviewed action
- Review & Import action
- Ignore action
- Bulk import restricted to reviewed events
- Linked Traveler Activity shown after import
- Actual provider recorded on imported Activities
- Featured-image sideload status recorded for diagnostics

## Editorial workflow

1. Sync the Caverns official source.
2. Open **TN Game OS → Review Queue**.
3. Inspect event data and ticket link.
4. Choose one of:
   - **Mark reviewed** — approval only; no Activity created.
   - **Review & import** — approve and create/update the Activity immediately.
   - **Ignore** — remove from the open editorial queue.
5. Use **Import reviewed events** after reviewing a batch.

## Validation checklist

- [ ] New and reviewed counts match queue records.
- [ ] Review does not create an Activity.
- [ ] Review & Import creates one `st_activity` post.
- [ ] Event is assigned to the venue defaults and `concerts` service tag.
- [ ] External Tixr ticket URL is retained.
- [ ] Date, time, doors, status, and price metadata are stored.
- [ ] Poster is copied to the Media Library when available.
- [ ] Imported card links to the Traveler Activity.
- [ ] Re-import updates the same Activity.
- [ ] Bulk import processes reviewed records only.

## Rollback

Reinstall the Sprint 002 `tn-game-os-wordpress` artifact. Queue records and imported Activities are preserved because Sprint 003 adds workflow metadata without changing the underlying post types.
