# TN Game OS 5.157.0

## Start-Time-Aware Prep Priority

- Shares date-and-start-time ordering across the private prep overview, prep-priority sort, adventure-date sort, and Next adventure banner.
- Prioritizes earlier starts within the same day before comparing remaining preparation.
- Preserves incomplete/launch-ready ranking and upcoming/past/unscheduled date groups.
- Uses the existing 10:00 AM default when a start-time value is missing or invalid.
- Adds behavioral regression coverage for schedule ordering, tie-breaks, date groups, and empty libraries.
- Does not change day-based upcoming eligibility, start adventures, make requests, add storage, or expose private plans.
