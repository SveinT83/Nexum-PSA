# Feature Slice: Integration Hub Identity, Clients, And Sites

Status: Approved
Date: 2026-08-14
Parent: GitHub #217
Owner: Svein / Codex

## Goal

Provide minimal effective identity and explicitly scoped read-only Client/Site contracts.

## Routes And Behavior

- `GET /api/v1/integration-hub/identity`
- bounded list/get routes for Clients and Sites with stable ID/name filters, sort, pagination, and
  maximum page size;
- out-of-scope IDs use the same not-found envelope as absent IDs.

Every response includes envelope/contract version, correlation, installation scope, record-scope
summary, and database observation freshness.

## Data Touched

No Client/Site schema change. Workload allowlists and execution-grant claims provide explicit record
scope. The installation key is the current single-installation organization boundary.

## Permissions And Isolation

`integration-hub.identity.read` or `integration-hub.clients.read`; descriptor binding, token ability,
workload, grant, Client/Site, installation, and emergency controls are intersected before queries.

## Tests

Interactive/workload/system actor, administrator, allowlisted Client/Site, empty scope, wrong
installation/Client/Site, missing ability, revoked/expired/replayed grant, pagination/filter/sort,
existence hiding, audit, and no token/grant/profile leakage.

## Documentation

Clients, Integration, API/OpenAPI, User/Agent, security, Knowledge, and human review.

## Done Criteria

- [ ] Identity is minimal and explains effective scope.
- [ ] Client/Site filtering occurs before pagination and model retrieval.
- [ ] Existing Clients API behavior is unchanged.
