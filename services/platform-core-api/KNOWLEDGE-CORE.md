# TN Platform Knowledge Core v1

Endpoints are protected by the existing `X-API-Key` header.

- `GET /v1/knowledge/stats`
- `GET|POST /v1/knowledge/entities`
- `GET|PATCH|DELETE /v1/knowledge/entities/:id`
- `GET|POST /v1/knowledge/relationships`
- `DELETE /v1/knowledge/relationships/:id`
- `GET /v1/knowledge/graph/:id?depth=1`
- `POST /v1/knowledge/seed`

The first sprint uses an atomic JSON datastore so the release can deploy without a database migration. Configure `DATA_DIR` on persistent storage. The API boundary is storage-independent, allowing PostgreSQL to replace the JSON adapter later without changing TN Studio.
