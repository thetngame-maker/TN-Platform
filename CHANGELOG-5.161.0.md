# TN Game OS 5.161.0

## Consistent Saved Adventure Names

- Uses the server-confirmed saved name across cards, private print views, Next adventure, and the prep overview.
- Preserves newer rename-field edits and warns that they are not yet saved.
- Returns a canonical title only for the authenticated owner's rename operation.
- Rejects failed name writes while accepting a verified unchanged stored value.
- Keeps notes responses and notes-write verification intact.
- Adds client race/failure tests and PHP rename ownership, response-scope, and persistence tests.
- Adds no automatic retry, extra request, browser storage, or broader sharing.
