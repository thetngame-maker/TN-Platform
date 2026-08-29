# TN Game OS 5.145.0

## Offline Adventure Integrity Check

- Audits each cached launcher entry before exposing it as an offline link.
- Requires a same-origin URL, successful response, public-safety header, HTML content type, and readable non-empty body.
- Hides questionable entries while leaving healthy stops in the same pack available.
- Marks affected packs and the device-library summary with a repair-needed state.
- Directs the Explorer to reconnect and explicitly refresh the affected Saved Adventure pack.
- Performs no automatic deletion, download, private-route request, or server-side activity write.
