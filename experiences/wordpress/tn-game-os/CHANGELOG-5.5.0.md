# TN Game OS 5.5.0

- Adds Local Discovery as a first-class TN Game OS Sources module.
- Uses the existing Apify Google Maps actor configuration (`tng_maps_apify_token`, `tng_maps_apify_actor`).
- Adds review-first Google Maps place discovery and candidate queue.
- Creates draft `st_activity` listings from approved candidates.
- Detects duplicate Activities by Google Place ID, Maps URL, and title.
- Suggests TN Game service categories from Google place categories.
- Assigns matching TN Game Destinations from detected community names.
- Connects created listings to the existing Google Places source provider and performs an official Places refresh when configured.
- Preserves manual editorial review; nothing is auto-published.
