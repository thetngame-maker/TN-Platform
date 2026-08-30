# TN Game OS 5.160.0

## Snapshot-Safe Notes Saving

- Captures submitted notes and distinguishes them from edits made while the save is pending.
- Returns the sanitized saved notes only to the authenticated owner making a notes update.
- Updates the private print view from confirmed notes and preserves any newer editor text as unsaved.
- Refreshes the editor and character count from normalized notes when no newer edits need preserving.
- Falls back to the submitted snapshot for compatibility with older server responses.
- Distinguishes an unchanged stored value from a failed notes write before returning success.
- Adds client race/failure tests and server-side ownership, nonce, sanitization, and response-scope tests.
- Adds no automatic save, extra request, browser storage, or public output.
