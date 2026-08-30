# TN Game OS 5.159.0

## Save-in-Progress Protection

- Prevents overlapping Saved Adventure update requests from the current library page.
- Refuses a second change with a wait-and-retry message before making another request.
- Releases the guard after success, server rejection, network failure, or response-parsing failure.
- Retains existing checkbox rollback, button recovery, form text, and confirmation behavior.
- Preserves the authenticated same-origin request and nonce checks.
- Adds no retry queue, automatic retry, storage, background task, or public output.
- This is page-local protection, not cross-tab or cross-device locking.
