# TN Game OS 5.143.0

## Device Offline Adventure Management

- Displays each downloaded pack's last successful public-screen verification date in the Offline manager.
- Adds an explicit “Remove from device” control to every device-local Adventure launcher.
- Requires confirmation and explains that the account's Saved Adventure remains intact.
- Removes only cached public screens, abandoned staging data, and the pack's device metadata.
- Refreshes the device library immediately after a successful removal, even while offline.
- Sends no removal event, plan data, account data, progress, or XP to WordPress.
