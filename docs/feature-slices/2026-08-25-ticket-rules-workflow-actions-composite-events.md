# Feature Slice: Ticket Rules Workflow Actions And Composite Events

Status: Done
Date: 2026-08-25
Approved by: Svein
Approved on: 2026-08-25
Level: 3
Parent: ../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md (Slice 4)
Owner: Svein / Codex
Related ADR: ../adr/2026-08-25-ticket-rule-versioning-authority-execution-boundary.md
Related issue: GitHub #231
Human review: [HR-2026-08-25-013](../human-review.md#hr-2026-08-25-013-ticket-rules-triggers-actions-and-audited-execution) (Pending)

## Goal

Add Workflow-aware Ticket Rule triggers and guarded actions without bypassing Workflow v3 versions,
requirements, action policy, state mapping, evidence, assignment policy, history, or terminal guards.

## User-Visible Behavior

After an administrator publishes a valid rule and the capability is explicitly enabled on Dev, a new
Ticket may select a published Workflow, and an existing non-terminal Ticket may take an exact valid
transition, move through an approved Workflow conversion, or pause/resume rule-driven automatic
Workflow movement. Rules can react to one composite Workflow/status result after the complete
authoritative operation succeeds.

Rule automation never directly patches status, Workflow version, or state. Manual Workflow actions
remain available while rule-driven automation is paused, and all Workflow changes remain auditable.

## Scope

- Add ticket.workflow_changed, ticket.workflow_state_changed, and ticket.status_changed trigger
  support through the normalized event registry.
- Emit one composite Workflow/status event after the complete authoritative transition or conversion
  succeeds. Suppress a duplicate nested event from the low-level reporting-status write.
- Record before/after Workflow ID, pinned version ID, state key, reporting status, assignment result,
  source action, exact transition/conversion key, and safe evidence invalidation summary.
- Add a create-phase action that selects one active published Workflow and its initial state while
  preserving version pinning and creation ordering.
- Add an exact transition action that calls TransitionTicketWorkflow with a configured transition key.
  It must reject ambiguous status-only targets and invalid, terminal, unavailable, or policy-blocked
  transitions.
- Add a guarded Workflow conversion/switch action that uses the authoritative migration/conversion
  service with explicit source/target Workflow version, target-state mapping strategy, requirements,
  evidence/history behavior, and assignment handling.
- Define pause workflow as pausing only rule-driven automatic Workflow movement. Preserve Workflow ID,
  pinned version, current state, history, requirements, evidence, and manual actions.
- Add resume workflow as the audited inverse of that pause flag. Resume does not itself force a
  transition unless a separately configured valid action follows.
- Add only the minimal additive Ticket Workflow pause metadata approved by the RFC.
- Route automatic Workflow transition triggers through the same coordinator so a Workflow action and
  a Ticket Rule do not independently advance the same Ticket twice.
- Reauthorize each action through TicketActionGuard, Workflow action policy, target Workflow/state
  requirements, work context, and protected actor capability.
- Apply the Slice 2 savepoint, locking, idempotency, branch rollback, FIFO derived event, and loop
  budget contracts to Workflow actions.
- Preserve Workflow assignment policy. If a Workflow action changes eligibility or assignment,
  record the result once and allow Slice 3 assignment relevance without a duplicate root event.
- Publish only registry-complete actions with typed selectors for published Workflow/version,
  transition key, target state/mapping, and pause/resume mode.
- Keep every new developer-owned label, validation, log, preview, and help string English.

## Out Of Scope

- Direct ChangeTicketStatus or direct writes to status_id, workflow_id, workflow_version_id, or
  workflow_state_key as rule actions.
- Deleting Workflow history, unpinning versions, clearing evidence, or interpreting stop workflow as
  removing the Workflow.
- Terminal close/reopen, merge/delete, customer notifications, commercial approval, billing, stock,
  purchase, time registration, or arbitrary external actions.
- Ticket Custom Field triggers/actions, which belong to Slice 5.
- Editing Workflow definitions or publishing Workflows from Ticket Rules.
- A first-class Ticket team. Queue remains routing and Owner remains individual assignment.
- Incomplete Admin controls before Slice 6.
- Language files, translation keys, Norwegian copy, or partial localization.
- Main promotion, production migration, or production capability activation.

## Data Touched

- Minimal additive Ticket metadata indicating whether rule-driven Workflow automation is paused, plus
  actor/time/reason evidence as approved during implementation.
- Existing Workflow ID, pinned version, state, status, history, evidence, review, and assignment data
  only through authoritative Workflow Actions/services.
- Slice 2 normalized events and execution results receive safe composite Workflow/status,
  transition/conversion, pause/resume, and assignment projections.
- Published Ticket Rule versions receive only registry-approved Workflow trigger filters and typed
  action configuration.
- No existing Workflow definition/version is mutated by rule execution.

## Permissions

- Publish requires ticket.rule_publish plus each registry-declared Workflow action publication
  authority.
- Runtime requires the protected actor's narrow Workflow/Ticket permissions and rechecks
  TicketActionGuard, Workflow policy, requirements, work context, and target availability.
- Rule management, preview, or execution-log viewing never implies Workflow execution authority.
- No rule, JSON import, publisher, initiator, API ability, or Admin role can grant the actor a new
  permission.
- Add permissions additively with read-back and no broad role synchronization.
- Customer roles never receive Workflow rule definitions, internal state names, requirements, or logs.

## Tests

- Trigger tests prove one composite event per successful transition/conversion and no duplicate from
  nested status writes.
- Exact-transition tests cover repeated reporting statuses, valid keys, missing/deleted transitions,
  manual-disabled behavior, requirements, action policy, terminal state, and concurrent state change.
- Create-time Workflow selection tests cover published-only targets, initial state, pinned version,
  SLA/assignment ordering, and every production Ticket creator.
- Conversion/switch tests cover target mapping, requirements-based placement, blocked placement,
  evidence invalidation, history, review state, assignment policy, and full branch rollback on failure.
- Pause/resume tests prove only rule-driven automatic movement is gated; manual actions, current state,
  pinned version, history, and evidence remain intact.
- Assignment tests prove Workflow eligibility and policy interact once with Slice 3 events and do not
  produce duplicate assignment roots.
- Loop tests cover transition-to-rule-to-transition, status aliases, Workflow A-to-B-to-A, pause/resume
  cycles, no-op suppression, idempotency, budgets, and concurrent updates.
- Preview tests show exact target, requirement/policy denial, assignment consequences, proposed
  composite event, and no writes.
- Permission tests prove fixed actor authority, action-specific publish checks, work context, deleted
  user evidence, and no initiator/publisher privilege lending.
- Existing Workflow v3, Ticket action/API, assignment, Email, Signal, Portal, and Integration
  regressions pass.
- MariaDB savepoint/locking tests, Pint, focused Laravel tests, git diff --check, and authenticated Dev
  Workflow smoke checks pass.

## Documentation

- Update Ticket Workflow v3 Knowledge and developer documentation with exact transition, create-time
  selection, conversion, composite event, pause/resume, assignment, loop, and troubleshooting rules.
- Update Ticket Rules/Assignment, permissions, API/integration, and technical operations docs.
- Update docs/TODO.md and the stable human-review entry at implementation handoff.
- Do not publish website copy until verified implementation, human review, and release status permit
  it; any later handoff remains do not publish until then.

## Implementation Evidence

Slice 4 is implemented on authoritative Dev while Workflow trigger/action capabilities and v2
authority remain default-off.

- The focused Ticket Rule Workflow action contract passes 9 tests / 86 assertions.
- Focused manual/API Workflow escalation and explicit Workflow-version migration composite
  regressions pass 2 tests / 42 assertions.
- Exact selection, transition, switch, pause/resume, preview, actor/target denial, branch savepoint,
  assignment consequence, and duplicate-event behavior are covered.
- Manual, API, and rule-owned authoritative Workflow operations suppress nested low-level status
  dispatch and emit one composite result with source provenance. Queue remains routing and Owner
  remains the individual assignment.
- Pause affects only rule-driven automatic movement and preserves pinned version, state, history,
  evidence, requirements, and manual actions.
- Cross-module and release-wide evidence remains recorded under pending human-review entry
  `HR-2026-08-25-013`; this slice does not authorize runtime activation or Main/production work.

## Dependencies And Rollback

- Depends on completed and verified Slices 1-3 and the Accepted ADR transaction/event contracts.
- Exact Workflow conversion and pause metadata must be approved within this scope before migration;
  a different meaning of stop workflow requires an RFC revision.
- Disable Workflow trigger/action providers independently for rollback while preserving immutable
  versions, pause state, Workflow history, and execution evidence.
- Never rollback by clearing Workflow/version/state/history/evidence. Use a forward repair or a
  separately reviewed data-aware downgrade after any action has executed.
- Additive schema down must refuse after pause or execution evidence exists.
- No Main or production rollback/activation work is authorized.

## Done Criteria

- [x] Slices 1-3 are complete and verified on Dev.
- [x] Every rule-driven Workflow change uses an authoritative Workflow Action and exact target.
- [x] One composite Workflow/status event is emitted after success with no nested duplicate.
- [x] Create-time selection, exact transitions, conversion, and pause/resume preserve version,
  state, history, evidence, policy, requirements, and assignment invariants.
- [x] Pause means only rule-driven automatic movement is paused; manual Workflow actions remain.
- [x] Ambiguous status-only and direct low-level patches are impossible.
- [x] Preview, audit, loop, idempotency, concurrency, permission, and privacy tests pass.
- [x] New Workflow capabilities remain default-off until approved Dev verification passes.
- [x] English-only copy is used; no language files or partial localization are added.
- [x] Documentation and the human-review checklist are complete at implementation handoff.
- [x] No Main or production migration, activation, promotion, or deployment occurs.
