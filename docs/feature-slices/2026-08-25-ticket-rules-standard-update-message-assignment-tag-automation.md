# Feature Slice: Ticket Rules Standard Update, Message, Assignment, And Tag Automation

Status: Done
Date: 2026-08-25
Approved by: Svein
Approved on: 2026-08-25
Level: 3
Parent: ../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md (Slice 3)
Owner: Svein / Codex
Related ADR: ../adr/2026-08-25-ticket-rule-versioning-authority-execution-boundary.md
Related issue: GitHub #231
Human review: [HR-2026-08-25-013](../human-review.md#hr-2026-08-25-013-ticket-rules-triggers-actions-and-audited-execution) (Pending)

## Goal

Add guarded standard Ticket update, message, Queue/Owner assignment, and Taxonomy tag automation to
the verified Slice 2 execution boundary, including relevant typed events, ordered Then/Else actions,
canonical mutation Actions, and explicit SLA/assignment precedence.

## User-Visible Behavior

After an administrator publishes a valid rule and the capability is explicitly enabled on Dev,
Ticket Rules can react to supported field changes, customer/public/internal messages, Queue/Owner
assignment changes, and tag additions/removals. Matching Then actions or non-matching Else actions run
in configured order, and the caller receives the final refreshed Ticket before the operation returns.

Rules may set supported Ticket fields, route to a Queue, assign or unassign an eligible Owner, rerun
the Assignment Engine, add or remove tags, add an internal system note, or use the existing explicit
Signal handoff. Every actual change is visible in internal execution evidence. Customer-visible
messages are not an action in this slice.

## Scope

- Add typed trigger support for ticket.updated, ticket.field_changed, ticket.message_added,
  ticket.tags_changed, and ticket.assignment_changed.
- Filter trigger relevance before condition evaluation. Standard-field triggers declare one or more
  allowed changed fields; message triggers declare customer reply, public update, internal note, and
  approved source filters; tag triggers declare added/removed IDs; assignment triggers distinguish
  Queue routing, assigned Owner, changed Owner, and unassigned.
- Emit normalized events only from authoritative Ticket Actions after stable before/after state.
  Do not use an Eloquent observer as the automation authority.
- Update UpdateTicketFields and the supported API/UI/integration callers to emit one normalized event
  per completed mutation. Specialized field/assignment relevance must not duplicate the root event.
- Keep raw message bodies out of the event envelope and logs. Store message type/source, identifiers,
  safe bounded summaries or fingerprints, and the ordinary Ticket record link.
- Consolidate production message entry points through AddTicketMessage or an equivalent authoritative
  boundary, including Tech, Portal, Email, API, and integration paths.
- Add one audited Ticket-owned tag mutation Action used by UI, API, integrations, and rules. Replace
  direct production pivot writes in scope with this Action and emit one added/removed delta event.
- Add a guarded Owner assignment Action for explicit eligible assignment and unassignment. Continue
  to use TicketAssignmentEngine for the explicit rerun-assignment action.
- Extend the Ticket action registry with typed providers for:
  - supported standard Ticket fields through authoritative Ticket Actions;
  - Queue routing as the Ticket routing group;
  - explicit eligible Owner assignment and unassignment;
  - explicit Assignment Engine rerun;
  - add and remove active Taxonomy tags;
  - add an internal system note through AddTicketMessage without technician impersonation; and
  - the existing emit_signal handoff.
- Each provider declares input schema, target lookup, actor permission, phase, changed fields,
  idempotency/retry behavior, safe audit projection, and after-commit behavior.
- Execute configured Then or Else positions in order with Slice 2 savepoint, no-change, failure,
  not_run, last-successful-writer, and stop-processing semantics.
- Preserve current creation ordering. A successful explicit Queue or Owner rule action suppresses
  fallback assignment; only an explicit rerun-assignment action reassesses it.
- Preserve SLA precedence: successful Ticket Rule SLA decision, then active Contract SLA, then default
  SLA. Record the source and any later successful overwrite.
- Reauthorize each action as ticket_rule_automation through TicketActionGuard and the target domain.
  Inactive targets, ineligible Owners, cross-context records, missing permissions, and incompatible
  state fail closed and are audited.
- Add per-trigger and per-action capability gates that remain default-off until migration,
  compatibility, permission, and Dev verification pass.
- Keep all new developer-owned labels, validation, operator output, logs, and help in English.

## Out Of Scope

- Workflow selection, exact Workflow transition, Workflow switching, pause/resume, or status actions.
- Ticket Custom Field registration, triggers, conditions, values, or actions.
- Timers, SLA-warning polling, schedules, customer email/reply actions, merge/delete, billing, time,
  commercial approval, stock, purchase, or unrestricted external actions.
- A first-class Ticket team, team membership, or team permission boundary. Queue is routing and Owner
  is individual assignment.
- Admin builder and execution-history UI; Slice 6 exposes only complete, verified controls.
- Language files, translation keys, Norwegian copy, or partial localization.
- Main promotion, production migration, production capability activation, or deployment.

## Data Touched

- Published Ticket Rule definitions gain only registry-approved trigger filters, grouped conditions,
  ordered Then actions, and ordered Else actions; each publish creates a new immutable version.
- Slice 2 events/executions gain safe standard-field, message metadata, tag delta, Queue, Owner, and
  action result projections.
- Tickets and existing related records may be changed only through authoritative Actions during
  explicitly enabled Dev execution.
- Ticket messages may receive an internal system note with the protected automation actor and
  internal-only visibility.
- Taxonomy tag pivots change only through the new audited Ticket tag Action.
- Existing Ticket assignment history and Ticket history receive truthful before/after evidence.
- No raw message bodies, secrets, attachments, tokens, or unrestricted payloads are copied into
  execution records.

## Permissions

- Publishing still requires ticket.rule_publish and the registry-declared publication authority for
  every enabled action in both Then and Else.
- Runtime uses only the protected system actor's code-owned permissions and rechecks ordinary Ticket,
  assignment, message, Taxonomy, and Signal guards.
- Rule management, preview, and log access do not grant action execution authority.
- The initiating user/API token remains a request ceiling and causation identity; it is not
  impersonated and cannot widen the system actor.
- Add new action-specific permissions additively only when no existing domain permission accurately
  represents the guarded operation. Grant only Admin/Superuser publication authority and the
  protected actor's narrow runtime authority, with read-back and no broad role sync.
- Internal notes remain internal; no customer role can view rule definitions or execution evidence.

## Tests

- Trigger relevance tests cover every supported standard field, unrelated changes, multiple fields,
  source filters, message types, tag deltas, Queue changes, Owner changes, and no-op suppression.
- Entry-point parity covers Tech UI, Customer Portal, inbound Email, API, Intake, Relationship,
  Telephony, Signal, scheduled creation, and integrations without duplicate events or executions.
- Grouped ALL/ANY, Then/Else, ordered actions, last-successful-writer, successful Stop, branch
  rollback, later-rule continuation, and derived FIFO events are covered.
- Standard action tests prove UpdateTicketFields or the approved authoritative Action is called,
  target and work context are validated, no direct unsafe model patch is used, and exact changes are
  audited.
- Assignment tests cover eligible/ineligible Owner, unassign, Queue routing, explicit rerun,
  Workflow eligibility where already applicable, rule assignment suppression of fallback, ordinary
  fallback, and concurrent assignment changes.
- SLA tests cover rule, Contract, default precedence, multiple rules, no change, and source evidence.
- Message tests cover internal/customer/public/source filters, raw-body non-retention, internal system
  note visibility, idempotency, and no customer-message action.
- Tag tests cover add/remove/idempotent no change, inactive/missing/cross-context targets, replacement
  of direct production pivot writes in scope, and tag-change cycles.
- Loop tests cover field self-change, assignment cycles, message-note cycles, tag A-to-B-to-A,
  no-change suppression, budgets, and concurrent updates.
- Permission tests prove publish/runtime separation, fixed actor authority, action guard denial,
  customer non-disclosure, and no initiator privilege lending.
- Existing Ticket, Email, Signal, Portal, Taxonomy, Integration, assignment, and SLA suites pass.
- Pint, focused Laravel tests, git diff --check, and authenticated Dev smoke checks pass.

## Documentation

- Update Ticket Rules/Assignment Knowledge with update/message/assignment/tag triggers and actions,
  Then/Else order, precedence, actor authority, and troubleshooting during implementation.
- Update Ticket technical operations, Taxonomy integration, API/integration results, and permission
  documentation.
- Update docs/TODO.md and the stable human-review entry at implementation handoff.
- Do not publish website copy until verified user-visible implementation, human review, and release
  status permit it; any later handoff remains do not publish until then.

## Implementation Evidence

Slice 3 is implemented on authoritative Dev while v2 authority and every trigger/action capability
remain default-off.

- The schema-2 registry, canonical mutation, action-executor, publication, and typed-condition group
  passes 37 tests / 579 assertions.
- Authoritative field, message, tag, Queue, Owner, assignment-engine, SLA, and Signal boundaries
  suppress no-ops, retain safe source provenance, and return coordinator-compatible changes.
- A message that also claims an unassigned Ticket and a status change that also claims Owner each
  produce one root run with the combined changed fields; specialized relevance does not double-run.
- Queue remains the routing group and Owner remains one eligible individual. No team schema or
  permission boundary was added.
- Raw message bodies, internal-note text, secrets, and arbitrary executable inputs are excluded from
  durable event/action evidence. English-only copy is centralized in the typed registries.
- Cross-module and release-wide evidence remains recorded under pending human-review entry
  `HR-2026-08-25-013`; this slice does not authorize runtime activation or Main/production work.

## Dependencies And Rollback

- Depends on completed and verified Slices 1 and 2, including accepted compatibility parity,
  savepoint/lock contracts, actor read-back, audit immutability, and loop budgets.
- Slice 4 consumes the same canonical field/assignment event boundaries; Slice 5 consumes the same
  registry and audit projection contracts.
- Disable each new trigger/action capability independently for rollback while preserving published
  versions and execution evidence.
- Rollback must not direct-patch Tickets, detach history, delete completed audits, or erase original
  authorized mutations. Use forward fixes for executed data.
- Additive schema down is permitted only before evidence exists and must otherwise refuse.
- No Main or production rollback/activation work is authorized by this slice.

## Done Criteria

- [x] Slices 1 and 2 are complete and their contracts are verified on Dev.
- [x] Authoritative field, message, tag, Queue, and Owner mutations emit one normalized event only
  after a stable actual change.
- [x] Trigger relevance prevents unrelated/no-op evaluation.
- [x] Then/Else and ordered standard actions execute through canonical Actions with exact audit.
- [x] Explicit rule Queue/Owner decisions and Assignment Engine fallback follow the approved
  precedence; Queue remains routing and Owner remains individual assignment.
- [x] SLA precedence remains rule, Contract, then default and is regression-tested.
- [x] Direct production tag/message/assignment mutations in scope are consolidated or explicitly
  audited as unresolved blockers.
- [x] Loop, idempotency, branch rollback, permission, privacy, and cross-module tests pass.
- [x] Every new capability remains default-off until its approved Dev verification passes.
- [x] English-only copy is used; no language files or partial localization are added.
- [x] Documentation and the human-review checklist are complete at implementation handoff.
- [x] No Main or production migration, activation, promotion, or deployment occurs.
