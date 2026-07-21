# ADR-0001: Adopt a TN Platform Monorepo

- Status: Accepted
- Date: 2026-07-21

## Decision

TN Platform source, shared contracts, architecture documents, tests, and release tooling will live in one version-controlled monorepo. Deployable API and WordPress ZIPs are generated artifacts, not the source of truth.

## Reasons

- Keeps API and TN Studio contracts synchronized.
- Makes each sprint reproducible and testable.
- Preserves architecture and ADRs beside implementation.
- Enables automated checks and release packaging.
- Prevents independent ZIP files from drifting.

## Boundaries

The WordPress client remains a publishing and operator experience. It does not own canonical knowledge. The Traveler theme core is never modified.
