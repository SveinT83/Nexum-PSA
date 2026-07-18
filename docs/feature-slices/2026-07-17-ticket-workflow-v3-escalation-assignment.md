# Feature Slice: Ticket Workflow v3 Internal Escalation And Assignment

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Support optional or required manual internal escalation with safe workflow/state/queue/type and
eligible-owner changes.

## User-Visible Behavior

Technicians use `Escalate Ticket`, choose an allowed target, see prerequisites, and receive an
explainable owner result. Provider escalation remains a separate action.

## Scope

Escalation definitions, transactional execution, loop protection, eligible pools, assignment
engine scoping, no-eligible-owner handling, Ticket UI, API, events, and notifications.

## Out Of Scope

Automatic workflow switching and external provider handoff.

## Data Touched

Escalation/assignment policy definitions, Ticket workflow fields/history, queue/type/owner fields,
assignment services, routes, views, API, and notification events.

## Permissions

Internal escalation, administrative override, and existing assign-self/assign-other permissions.

## Tests

Optional/required behavior, preserved relations, target validation, owner selection, no eligible
owner, prerequisites, permission denial, loop prevention, concurrency, and API parity.

## Documentation

Ticket workflow, rules/assignment, API, permissions, operations, and human review.

## Done Criteria

- [x] Escalation is manual, transactional, and fully audited.
- [x] Assignment respects ordinary permissions and configured eligibility.
- [x] Required escalation blocks only configured actions.
- [x] Focused tests pass on Dev.
