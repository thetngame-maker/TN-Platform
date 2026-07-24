# Milestone 3 — Review Studio

## Objective

Turn the concert import queue into a focused editorial workspace that can later support every TN Platform content type.

## Included in TN Game OS 5.2.0

- Dedicated Review Studio submenu inside TN Game OS.
- Visual queue cards with posters, event dates, venues, queue states, and confidence scores.
- Search and queue-state filters.
- Queue summary metrics and estimated review time.
- Platform status ribbon for API, browser, providers, WordPress media, and Traveler.
- Single-event workspace with source preview and official ticket link.
- Rule-based review warnings for missing required information.
- Incoming-versus-published comparison panel.
- Traveler publishing preview.
- Existing publish/update and ignore actions surfaced in the workspace.
- Bulk publish and bulk ignore actions.
- Responsive admin layout.

## Architecture

Review Studio is a separate TN Game OS module. It consumes the existing `concert_intelligence` service from the shared container rather than duplicating import logic. Traveler remains an output of the platform, and no Traveler theme files are changed.

## Extension points

The Review Assistant intentionally includes visible connection points for future capabilities without pretending those services exist yet:

- AI title, summary, SEO, FAQ, and taxonomy suggestions.
- Duplicate scoring and field-level change records.
- Nearby attractions, lodging, food, trails, and Top Sights.
- Content-type adapters beyond concerts.
- Draft publishing and editorial assignments.

## Acceptance criteria

1. An editor can open Review Studio and see pending concert imports as visual cards.
2. Selecting a card opens its full review workspace without opening the WordPress post editor.
3. Missing fields lower the confidence score and create warnings.
4. The editor can publish/update or ignore a single item.
5. The editor can select multiple items and publish or ignore them in one action.
6. Existing Concert Intelligence import behavior remains the source of truth.
7. PHP validation, automated tests, and release packaging complete successfully before installation.
