# TN Platform Kernel MVP

Version: 0.1.0  
Platform release: 1.0.0-alpha.2

## Included

- Validated `platform.yaml` manifest
- Kernel lifecycle management
- Dependency-injection container
- Service registry with topological startup ordering
- Event bus with typed and wildcard subscriptions
- Standard `PlatformService` contract
- Expanded declarative Platform SDK
- TN CLI commands: `info`, `doctor`, `test`, `build`, and `create service`
- Automated tests for manifest parsing, lifecycle order, events, and CLI root discovery

## Lifecycle

`created → configuring → configured → starting → running → stopping → stopped`

Service states:

`registered → configured → starting → running → stopping → stopped`

Failures transition a service or Kernel to `failed` and publish a failure event.

## CLI

```bash
npm run tn -- info
npm run tn -- doctor
npm run tn -- test
npm run tn -- build
npm run tn -- create service media
```

## Current boundary

This MVP supplies an in-process runtime. Distributed events, durable workflow execution, remote service discovery, IAM, and Studio UI integration remain later milestones.
