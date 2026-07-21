# TN Platform Core API 3.1.0 — Platform Infrastructure

Adds the first Phase 1 code sprint:

- Environment-aware configuration snapshot
- Runtime service registry
- Standard platform health contract
- Correlation IDs on every request
- Structured in-memory operational log stream
- Shared counters and gauges
- TN Studio platform endpoints

## Endpoints

- `GET /v1/platform/config`
- `GET /v1/platform/services`
- `GET /v1/platform/health`
- `GET /v1/platform/logs`
- `GET /v1/platform/metrics`

All `/v1/platform/*` endpoints require the configured API key.
