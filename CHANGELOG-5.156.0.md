# TN Game OS 5.156.0

## Schedule-Safe Prep Scope

- Centralizes the rule for identifying upcoming preparation cards.
- Reuses the rule for overview metrics, preparation sorting, filtering, next-adventure selection, and launch-ready confirmation.
- Covers today, future, past, archived, unscheduled, incomplete, and already-complete plans with behavioral regression checks.
- Prevents archived or past scheduled plans from receiving launch-ready confirmation.
- Uses only owner-rendered plan state and date values.
- Creates no request, storage, background task, notification permission, automatic completion, or public output.
