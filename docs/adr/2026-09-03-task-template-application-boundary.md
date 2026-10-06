# ADR: Task Template Application Boundary

Status: Accepted
Implementation: Done On Dev (2026-09-03)
Date: 2026-09-03
Decision Makers: Svein / Codex

## Context

Task templates must support manual, scheduled, Signal Rule, Ticket Rule, and RMM Alert Rule use
without duplicating Task graph creation in every caller. The user also requires ordinary direct
editing rather than a versioned publishing or approval lifecycle.

## Decision

Task owns one mutable template definition and one atomic application action. A template group may
contain one or many items, nesting, checklists, and dependencies. Application copies current values
into new Tasks and records one idempotent generation run. Existing Tasks are never updated when the
template changes.

Caller domains retain their own matching, authorization, lifecycle, and audit. They pass an
authorized owner/context and stable idempotency identity to Task; they do not build template Tasks
themselves.

Ticket therefore owns recurring Ticket occurrence creation. When its schedule references a Task
Template, Ticket creates the occurrence and then calls Task's application action with that Ticket
as owner. Ticket never clones Task rows from the recurring parent.

## Rationale

One Task-owned action preserves Task permissions, Work Context, completion, time, and dependency
rules. Mutable templates keep administration simple. Generated Tasks provide the historical record
of actual work without a second template release system.

## Consequences

- Template edits affect future applications immediately.
- Existing Tasks remain independent snapshots.
- Group creation is transactional and dependency mappings are deterministic.
- Generation runs prevent duplicate automation while remaining operational evidence, not versions.
- Caller domains must fail closed when a template is missing, inactive, or context-incompatible.

## Alternatives Considered

- Immutable published versions were rejected as unnecessary administration.
- Per-domain Task construction was rejected because it duplicates graph and permission behavior.
- Rewriting existing Tasks after template edits was rejected because it changes active work
  unexpectedly.

## Follow-Up

Implement the five ordered Feature Slices, update Knowledge documentation, and complete human review
`HR-2026-09-03-001` before merge, migration, deployment, or schedule activation.
