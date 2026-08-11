# Local Discovery

Local Discovery is the review-first Google Maps discovery pipeline for TN Game OS.

## Runtime configuration

The module reuses the existing WordPress options:

- `tng_maps_apify_token`
- `tng_maps_apify_actor` (defaults to `pro100chok~google-maps-scraper`)

Google Places enrichment reuses the TN Game OS `google_places_key` setting.

## Workflow

1. Search Google Maps from Content Studio → Local Discovery.
2. Review enriched candidates.
3. Add useful places to the discovery queue.
4. Choose/confirm the TN Game service.
5. Create a draft Activity.
6. TN Game OS assigns a matching destination when one exists and connects the Google Place ID.
7. If Google Places is configured, the new draft is refreshed through the official Google Places provider.
8. Editorial review remains required before publishing.

## Deployment

Deploy a known repository commit rather than editing production files directly. The production MU-plugin prototype should be disabled/renamed after the repository module is deployed to avoid duplicate admin hooks.
