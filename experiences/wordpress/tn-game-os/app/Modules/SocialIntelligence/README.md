# Social Intelligence

Private TN Game OS module for social-content inspiration, creator permissions, original content concepts, and lightweight content planning.

## Data compatibility

This module intentionally retains the standalone MVP's storage contract:

- post type: `tng_social_item`
- taxonomies: `tng_si_platform`, `tng_si_topic`, `tng_si_collection`
- post meta prefix: `_tng_si_`

Existing records created by the standalone `TN Game Social Intelligence` plugin remain available after that plugin is disabled and TN Game OS with this module is deployed.

## MVP capabilities

- private inspiration feed
- public social URL capture with platform detection
- creator, location, topic, and format tagging
- hook/pattern analysis
- original TN Game idea generation
- creator permission records
- content calendar dates and status
- filters by platform, permission state, and search

The module stores links and editorial metadata. It does not download or republish creator media.
