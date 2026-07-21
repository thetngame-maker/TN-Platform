# TN Game Concert Intelligence API v2.0.0

This release pins Playwright and its Docker image to 1.61.1, validates Chromium during the Docker build, and exposes browser/provider health details at `/health`.

Replace the files in the GitHub repository with this package and commit. Render should redeploy automatically. A successful health response reports API version 2.0.0, browser ok, and Tixr provider ok.

## v2.1.0 — Discovery Engine

Adds `POST /v1/discovery/run`, a non-importing browser inspection endpoint that captures the discovery timeline, XHR/fetch traffic, JSON and GraphQL signals, JSON-LD counts, HTML event links, scrolling diagnostics, and normalized event URLs. The Tixr sync endpoint now uses the same Discovery Engine and includes its diagnostics in the sync response.

## v3.0.0 — Platform Core first sprint

This release adds the Knowledge Core while preserving all v2.2 discovery and provider endpoints. See `KNOWLEDGE-CORE.md` for the entity, relationship, revision, search, and graph endpoints. Set `DATA_DIR` to persistent storage in production.
