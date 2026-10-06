# Feature Slice: Knowledge Review And Publication UI

Status: Done On Dev
Date: 2026-09-04
Parent: `docs/rfc/2026-09-04-knowledge-revision-approval-publication.md`
Owner: Svein / Codex

## Goal

Replace direct article editing with proposal, diff, preview, approval, publication, and rollback.

## User-Visible Behavior

Technicians save a revision without changing the published page. Reviewers see full preview and a
concise diff before approving. Publishers explicitly publish an approved revision. Historical
published revisions can be proposed as a new rollback revision.

## Scope

Tech routes, controllers, Livewire editor, review page, revision history, state actions, and API
proposal behavior.

## Out Of Scope

Role/team audiences from Issue #265.

## Data Touched

Knowledge article and revision workflow tables.

## Permissions

`knowledge.manage_drafts`, `knowledge.approve`, `knowledge.publish`, and `knowledge.rollback`.

## Tests

Manual/API proposal, preview/diff, approval/rejection, stale denial, publication, and rollback.

## Documentation

Knowledge overview and module README.

## Done Criteria

- No ordinary edit mutates the published row before publication.
- [x] Review exposes concise before/after differences and a complete rendered preview.
- [x] Approval, rejection, publication, retry, and rollback enforce their distinct permissions.
- [x] API article writes create proposals and return revision identity without replacing published content.
- [x] Rollback creates a new proposal and preserves immutable history.
- [x] Focused Dev tests, routes, Blade compilation, and Pint pass.
