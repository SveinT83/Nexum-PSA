# Feature Slice: Ticket and Task actor attribution

Status: Done On Dev - implementation, migration, tests and scoped handbook verified.
Owner: Codex. Product approver/reviewer: Svein Tore.
Approved RFC: ../rfc/2026-11-12-ticket-task-actor-attribution.md.
Accepted ADR: ../adr/2026-11-12-explicit-ticket-task-actor-attribution.md.
Human-review checklist: HR-2026-11-12-TICKET-TASK-ATTRIBUTION (Pending).

## Goal and user-visible behavior

One complete slice adds compact creator/editor evidence to both technician
detail pages and the official APIs, using the same stored authority.

## Scope and data touched

Add nullable Task updater ID and both actor timestamps; preserve historical rows.
Wire explicit domain actors through Task writes, Ticket assignment/workflows,
merge reconciliation and record-owned related data. Preserve creator on edit.
No-op, read-state and technical timestamp touches retain prior attribution.

## Out of scope

Production/Main/commit/push, historical backfill, new permissions, audit-history
replacement, provider activation, queue/scheduler changes and Portal disclosure.

## Permissions

Reuse existing route/token/record/Work Context boundaries. Private Tasks stay
restricted. Actor objects expose only ID/name, without general user-directory access.

## Tests

Focused tests: create/edit via both APIs, spoofing, immutable creator, no-op/read,
unattributed writes, explicit system actor assignment, Task lifecycle/checklist,
tag-only changes, rollback, safe escaping/legacy users, actual browser response
headers and bounded actor query count. Run full affected Task/Ticket tests and
generated Swagger read-back before final handoff.

## Documentation

Four repository handbook/API sources plus the existing BookStack Ticket Overview
and Task Overview pages, with exact section read-back and unchanged audience.
Employee assistant retrieval is a separate manual check.

## Done criteria

Dev migration applied and read back; tests/contracts pass; source/BookStack docs
verified; TODO and evidence reconciled. Human acceptance remains Pending and gates
Main/production release independently of automated Dev completion.

Final evidence: 72/42534 affected rerun, 32/233 ingress; broad585 cases reconciled
with the sole legacy-fixture failure corrected and rerun. Human review remains Pending.
