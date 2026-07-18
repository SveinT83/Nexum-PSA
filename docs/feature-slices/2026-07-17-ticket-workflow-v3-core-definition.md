# Feature Slice: Ticket Workflow v3 Core Definition And Evaluator

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Deliver published workflow versions, workflow-specific states, grouped requirements, simulation,
and a compatibility migration for existing workflows and Tickets.

## User-Visible Behavior

Admins build readable all/any requirement groups and publish a validated immutable workflow.
Tickets show their real operational state while existing global statuses continue to work.

## Scope

Definition/version models, first-class state references, requirement provider registry/evaluator,
legacy conversion, publish/simulate admin UI, API resources/controllers, and migration report.

## Out Of Scope

Cross-domain mutations, internal escalation, senior review, and commercial fulfilment.

## Data Touched

Workflow/state/transition tables, new definition and history tables, Ticket workflow state fields,
module provider registration, admin views, Ticket routes, and API routes.

## Permissions

Existing Ticket settings permission plus new workflow publish and migrate permissions. Simulation
requires Ticket view and workflow settings access.

## Tests

All/any evaluation, provider validation/failure, publish immutability, compatible backfill,
ambiguous mapping, version pinning, API parity, permissions, and Blade rendering.

## Documentation

ADR, Ticket lifecycle/admin Knowledge, API documentation, TODO, and human review.

## Done Criteria

- [x] Existing workflows convert without behavior loss.
- [x] Published states may share one global status.
- [x] Builder and API can create, validate, publish, read, and simulate definitions.
- [x] Focused tests and Dev migration pass.
