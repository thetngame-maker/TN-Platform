# TN Game OS 5.133.0

## Private Schedule Conflict Alerts

- Detects overlapping upcoming Saved Adventures from their existing dates, starts, and durations.
- Handles overnight plans by comparing complete local date-and-time windows.
- Shows the conflicting plan names on affected cards and a single library-level warning.
- Ignores archived, unscheduled, and past plans.
- Remains informational: it does not block starts, alter dates, or modify itineraries.
- Performs no server request, database write, background task, or notification.
