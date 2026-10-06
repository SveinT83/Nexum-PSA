# Feature Slice: Knowledge Publication Read-Back And Authority

Status: Done On Dev
Date: 2026-09-04
Parent: `docs/rfc/2026-09-04-knowledge-revision-approval-publication.md`
Owner: Svein / Codex

## Goal

Complete publication only after exact local and required BookStack read-back while preserving
repository authority.

## User-Visible Behavior

Successful local-only publication becomes published immediately after read-back. BookStack-backed
publication remains visibly pending or failed until the provider proves the exact revision.

## Scope

Atomic article projection, render/read-back verification, portal notification, queue dispatch,
BookStack success/failure callbacks, retryable failure, repository source version, and AI overwrite
denial.

## Out Of Scope

Replacing the BookStack worker or enabling automatic clean inbound synchronization.

## Data Touched

Articles, revisions, revision events, documentation requests, BookStack sync state, and existing queue
jobs.

## Permissions

Human `knowledge.publish`; protected actor receives only internal persistence/publication capability.

## Tests

Local success, rendering/read-back failure, dispatch/provider failure, exact BookStack success,
repository sync/version identity, and portal notification regression.

## Documentation

Knowledge and BookStack operational documentation plus human review.

## Done Criteria

- [x] Local publication applies and reads back the exact approved snapshot atomically.
- [x] BookStack-backed publication remains pending until exact provider read-back succeeds.
- [x] Provider failure is visible and retryable against the same revision without duplicate publication.
- [x] Portal notification occurs only after verified publication.
- [x] Repository-owned articles retain repository source/version authority and reject AI overwrite.
- [x] Focused local, BookStack, repository, and portal verification passes on Dev.
