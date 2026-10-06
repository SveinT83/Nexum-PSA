# Feature Slice: Knowledge Revision Foundation

Status: Done On Dev
Date: 2026-09-04
Parent: `docs/rfc/2026-09-04-knowledge-revision-approval-publication.md`
Owner: Svein / Codex

## Goal

Deploy the immutable workflow schema, baselines, models, event ledger, protected actor, and locked
state machine.

## User-Visible Behavior

No published article changes during draft creation. Every revision exposes a truthful lifecycle and
provenance.

## Scope

Additive migration, baseline, immutable model guards, relationships, transition audit, permissions,
system actor, and stale-base checks.

## Out Of Scope

Review UI, Ticket integration, and BookStack completion.

## Data Touched

`articles`, `knowledge_article_revisions`, `knowledge_article_revision_events`, permission tables,
and the protected system actor when first resolved.

## Permissions

Separate draft, approval, publication, rollback, admin, and internal actor capabilities.

## Tests

Migration baseline, immutable content, valid/invalid transitions, conflict, permission grants, and
system actor identity.

## Documentation

RFC, ADR, TODO, Knowledge model documentation, and human review.

## Done Criteria

- [x] Existing published articles have exact immutable baseline revisions and published pointers.
- [x] Snapshot content and provenance cannot be changed or deleted after creation.
- [x] Lifecycle transitions are locked, audited, and stale work fails closed.
- [x] Human permissions are separated from the disabled-login system actor permissions.
- [x] Migration and focused Dev verification pass; manual checks remain in `HR-2026-09-04-001`.
