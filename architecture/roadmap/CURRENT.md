# TN Platform Current Delivery Roadmap

Last reviewed: 2026-09-03

This file reconciles the original architecture milestones with the TN Game OS 5.101–5.178 release train. It records delivery sequence and launch gates; it does not assign future version numbers before a candidate is scoped and validated.

## Delivered foundations

1. **Platform Core and TN Studio foundation** — service registry, health, metrics, logs, knowledge, relationships, graph, discovery, and Studio workspaces.
2. **Review Studio** — editorial queue, review workspace, warnings, comparisons, publishing actions, and bulk operations.
3. **Entity Engine** — canonical entity identity, lifecycle, snapshots, relationships, API contracts, and the Review Studio bridge.
4. **Player experience foundation** — Adventure AI, Universal Map, Explorer Profile, Nearby XP, community activity, recaps, production smoke checks, offline packs, Saved Adventures, launch/resume/finish handoffs, and private-safe sharing.
5. **Saved Adventure planning foundation** — organization, scheduling, calendar export, readiness and packing, offline launch, prep priority, verified owner-only writes, and unsaved-edit protection.

## Current release train

6. **Private Saved Adventure editing and recovery** — finish the owner-only editing workflow with count-only review, safe schedule refresh, keyboard and assistive-technology guidance, and failure-safe manual recovery. TN Game OS 5.178 adds guided progress within each type-specific review cycle without autosave, new storage, new requests, or private-value disclosure.

## Remaining launch gates

7. **Native runtime acceptance** — verify the Quest Runtime and Saved Adventures in Safari and Chrome, logged in and logged out where supported, including start, resume, completion, checkpoint progression, GPS permission/failure states, and Developer Mode isolation. VM integration tests support this gate but do not satisfy native browser coverage.
8. **Staging, deployment, and rollback certification** — activate the exact release archive in staging; smoke-test review, publishing, persistence, player flows, offline behavior, deployment health, and rollback. A Cloudways operation acceptance confirms the deployment request, not final application completion.
9. **Offline and device reliability certification** — exercise installation, pack refresh/removal, quota or storage failures, stale or corrupt packs, reconnect behavior, and mobile termination across supported devices.
10. **Privacy, security, and accessibility review** — audit owner and nonce enforcement, private response fields, public sharing boundaries, keyboard and screen-reader operation, focus management, contrast, reduced motion, and failure messaging.
11. **Canonical content and editorial certification** — prove that imports resolve to stable entities without duplication, snapshots and relationships remain consistent, Traveler projections stay linked, and existing queue records remain reviewable.
12. **Launch operations and observability** — define supported environments, production health thresholds, alerting and response ownership, backup/restore drills, release notes, runbooks, and go/no-go evidence.

## Release rule

Every candidate must pass the complete local milestone suite, be published exactly to the feature branch, pass GitHub PHP/release validation, receive explicit deployment approval, promote only plugin runtime files to production, and record the GitHub deployment result plus the Cloudways operation. Validation pull request #79 must never be merged.
