# TN Game OS 5.142.0

## Device-Local Offline Adventure Launcher

- Adds a “Saved Adventures on this device” section to the public Offline Pack manager.
- Lets an Explorer open cached public stop screens without loading the private Saved Adventures page.
- Reads only verified same-origin public URLs from device Cache Storage.
- Uses numbered pack labels and public URL slugs; private plan titles, notes, schedules, and progress are excluded.
- Builds links with safe DOM text and limits the launcher to 12 packs with 12 stops each.
- Sends no device-library, account, plan, or activity data to WordPress or another service.
