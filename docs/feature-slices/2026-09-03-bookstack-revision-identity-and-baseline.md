# Feature Slice: BookStack Revision Identity And Baseline

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-knowledge-bookstack-revision-safe-sync.md`
Owner: Codex

## Goal

Create immutable Knowledge revision identity and an honest per-article BookStack synchronization
baseline without claiming unproven pre-migration history.

## User-Visible Behavior

No standalone workflow. Later sync and review surfaces can identify the exact current, synchronized,
pending, and candidate revisions.

## Scope

Revision and sync-state tables, canonical hash service, model relations, migration baseline, stable
state constants, and idempotent revision recording.

## Out Of Scope

General Knowledge drafts, approvals, publishing, rollback, or automatic merging.

## Data Touched

New `knowledge_article_revisions` and `knowledge_book_stack_sync_states` tables. Existing articles
are read for baseline creation but their content is not changed.

## Tests

Canonical identity, unknown baseline, equal baseline establishment, move/rename identity, and
idempotent revision creation through the sync regression suite.

## Done Criteria

Migration runs on Dev with schema/count read-back; focused tests, lint, Pint, and diff checks pass.

## Dev Evidence

Migration `2026_09_03_170000_create_knowledge_article_sync_revisions` ran on Dev in batch 2. The
current Dev database has 0 BookStack source pages, and read-back therefore shows 0 revision,
sync-state, and unknown-baseline rows. This proves the migration did not infer or rewrite an
unavailable baseline.
