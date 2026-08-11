# Sources

TN Game OS Sources separates external facts from editorial content.

Providers live in `Providers/` and normalize first-party/official source data. Discovery modules may use external services to identify candidate records, but approved TN Game listings should retain stable source identifiers (for example Google Place IDs) so official providers can maintain the record after discovery.

## Local Discovery

`Local_Discovery` is the review-first Google Maps discovery pipeline. It uses Apify for discovery and the existing Google Places provider for post-approval enrichment when configured.
