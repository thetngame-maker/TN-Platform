# TN Platform Monorepo

TN Platform is the shared Destination Operating System that powers TN Studio, TN Game, and future destination experiences.

## Current baseline

- Platform Core API: **3.1.0**
- TN Game OS / TN Studio WordPress client: **5.1.0**
- Monorepo architecture: **1.0.0-alpha.1**

## Repository map

- `services/platform-core-api` — Node/Express API, browser intelligence, discovery, knowledge, graph, health, logs, metrics.
- `experiences/wordpress/tn-game-os` — WordPress client and TN Studio interface. Traveler theme core remains untouched.
- `packages/contracts` — shared, versioned platform contracts.
- `packages/platform-sdk` — extension interfaces for future services and providers.
- `architecture` — handbook, technical specification, ADRs, blueprints, and roadmap.
- `scripts` — validation and reproducible release packaging.
- `dist` — generated deployment ZIP files; not source of truth.

## Development

```bash
npm install
npm run check
npm test
npm run build
```

`npm run build` creates deployable API and WordPress ZIPs in `dist/` from the canonical source directories.

## Release policy

1. Change canonical source in this repository.
2. Update tests, contracts, documentation, and changelogs.
3. Run `npm run release`.
4. Deploy the generated API archive first.
5. Install the generated WordPress archive after API health is confirmed.

Never modify the Traveler theme core. TN Studio and TN Game functionality belongs in the plugin or platform services.
