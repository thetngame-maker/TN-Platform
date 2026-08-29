# TN Game OS 5.140.0

## Offline Adventure Verification Dates

- Records a device-local verification time after a complete public-stop download succeeds.
- Displays “verified today” or a short verification date on each Saved Adventure card.
- Leaves the prior date unchanged after failed, partial, unsafe, or low-storage updates.
- Removes verification metadata with the corresponding offline pack.
- Keeps metadata in a separate release-stable cache keyed only by the opaque pack ID.
- Sends no timestamp, plan activity, private data, or browsing history to WordPress.
