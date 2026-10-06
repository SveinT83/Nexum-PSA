# Feature Slice: BookStack Three-Way Sync And Read-Back

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-knowledge-bookstack-revision-safe-sync.md`
Owner: Codex

## Goal

Replace last-writer-wins synchronization with exact-base inbound decisions and exact-revision
outbound delivery evidence.

## User-Visible Behavior

Clean inbound changes may fast-forward only under the configured policy. Divergence never silently
overwrites the Nexum article. Push reports success only after provider read-back matches the bound
published revision.

## Scope

Three-way pull, stale-job safety, provider pre-read and drift guard, independent read-back,
idempotent ambiguous-create recovery, hierarchy read-back, remote deletion detection, feedback-loop
prevention, overlap lock, disabled/unavailable preservation, and sanitized errors.

## Out Of Scope

Automatic merge, provider-side history management, and production rollout.

## Data Touched

Knowledge articles only on proven clean inbound or explicit candidate acceptance; otherwise new
revision/sync-state evidence and existing bounded Integration summaries.

## Tests

Clean inbound/outbound, simultaneous edits, disabled automatic inbound, stale push, ambiguous
response recovery, remote move/rename/deletion, integration disabled, and loop prevention.

## Done Criteria

Focused and existing BookStack regressions pass with lint, Pint, and diff checks.

## Dev Evidence

The focused revision-safety, complete existing Integration feature file, and Knowledge article
regression pass together after formatting: 97 tests / 948 assertions. Pint passes for all 17 scoped
PHP files and `git diff --check` is clean.
