# Feature Slice: Ticket Rules Execution Envelope, Audit, And Loop Foundation

Status: Done
Date: 2026-08-25
Approved by: Svein
Approved on: 2026-08-25
Level: 3
Parent: ../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md (Slice 2)
Owner: Svein / Codex
Related ADR: ../adr/2026-08-25-ticket-rule-versioning-authority-execution-boundary.md
Related issue: GitHub #231
Human review: [HR-2026-08-25-013](../human-review.md#hr-2026-08-25-013-ticket-rules-triggers-actions-and-audited-execution) (Pending)

## Goal

Create the default-off Ticket Rule v2 execution boundary: normalized events, frozen published
definitions, least-privilege automation identity, transaction/savepoint isolation, immutable audit,
preview, idempotency, locking, and loop budgets. Prove exact legacy ticket.created parity before any
new update trigger or action provider is enabled.

## User-Visible Behavior

Current Ticket Rules remain behaviorally unchanged while the new runtime is disabled. In isolated
tests and approved Dev verification, an administrator can preview a published compatibility rule
against an authorized Ticket and synthetic event without writes. When the v2 runtime is explicitly
enabled for approved Dev verification, callers receive the final synchronous Ticket result and an
internal automation run records success, failure, no change, or loop blocking.

No incomplete builder or execution-history page is exposed in this slice. Customer Portal users and
customer-visible messages never receive rule definitions, condition evidence, permissions, or logs.

## Scope

- Add one Ticket-owned normalized event envelope containing Ticket ID, stable event key, source
  channel/action, changed fields, minimized before/after values, initiator identity, automation actor,
  root correlation and causation, parent event/action, chain depth, occurred time, and deterministic
  event fingerprint.
- Add a root execution coordinator that freezes the ordered published rule/version set at root start
  and drains derived events in deterministic FIFO order.
- Add durable root run, normalized event, rule execution, ordered action-position result, and
  after-commit result records with stable idempotency keys and immutable completed evidence.
- Preserve ticket_rule_logs and any existing rows as read-only legacy evidence. Do not rewrite them to
  imply facts that were never captured.
- Create the protected non-login ticket_rule_automation actor through EnsureSystemActor. Store the
  initiator separately and never impersonate or borrow its permissions.
- Give the actor only code-owned capabilities required by the existing compatibility action set.
  Every execution reauthorizes through TicketActionGuard and the target domain.
- Run the original authorized Ticket mutation and synchronous rule work in one transaction with a
  per-Ticket row lock. Run each selected branch in a database savepoint.
- On branch success, retain every ordered synchronous change. On branch failure, roll back only that
  branch, record the failed position and later not_run positions, retain the original mutation and
  earlier successful branches, and continue later rules unless a root guard stops execution.
- Dispatch external effects only after commit with stable action idempotency keys and truthful queued,
  succeeded, failed, or unresolved state.
- Add grouped condition evaluation and evidence required for legacy compatibility: root and group
  ALL/ANY results, row outcomes, selected Then/Else branch, and privacy-safe values.
- Add Then/Else execution mechanics, ordered positions, last-successful-writer evidence, successful
  stop-processing semantics, and no_change results. Slice 2 enables only the compatibility action
  catalogue already represented by valid version 1 definitions.
- Add no-op suppression, visited event/rule/action fingerprints, one successful action per
  idempotency key, configurable depth/evaluated-rule/action budgets, and loop_blocked termination.
- Add retry primitives that can select only failed or not-run idempotent positions whose current
  preconditions still match. Do not expose full rerun as an ordinary operation.
- Add a no-write preview service that uses the same trigger, condition, target, permission, policy,
  ordering, collision, and loop-risk evaluation as runtime.
- Consolidate every production Ticket creation entry point behind the authoritative creation
  coordinator, including scheduled occurrences, while retaining the existing SLA and assignment
  precedence.
- Compare legacy and version-1 ticket.created selection, conditions, ordered actions, final Ticket
  state, Signal handoff, stop behavior, and caller-visible result across all production creators.
- Keep the v2 runtime behind a default-off configuration/runtime-authority gate. Tests may enable it
  explicitly; no Main or production activation is authorized.

## Out Of Scope

- Ticket update, field, message, tag, assignment, Custom Field, Workflow, status, or timer triggers.
- New standard-field, assignment, tag, Custom Field, Workflow, notification, email, webhook, billing,
  time, commercial, stock, purchase, merge, or delete action providers.
- A visible builder, preview page, execution-history page, retry button, or customer-facing audit.
- A first-class Ticket team. Queue remains routing; Owner remains individual assignment.
- Arbitrary PHP, SQL, shell, scripts, queries, or unrestricted HTTP.
- Language files, translation keys, Norwegian UI/operator copy, or partial localization. All new
  developer-owned copy is English only.
- Main promotion, production migration, production runtime activation, or production backfill.

## Data Touched

- New additive Ticket-owned tables for root runs, normalized events, rule executions, action-position
  results, and after-commit delivery results.
- Immutable references to ticket_rule_versions, Ticket, rule, correlation/causation, initiator raw ID,
  automation-actor raw ID, timing, budgets, branch, stop decision, safe changes, and failure reasons.
- Existing ticket_rules and ticket_rule_versions are read through the Slice 1 authority/version
  boundary; published snapshots are never mutated.
- Minimal additive Ticket history support for one internal automation_run summary linked to the root.
- Configuration for default-off runtime authority and bounded depth, rule, and action limits.
- Queue entries only after an approved Dev test transaction commits; preview and compatibility
  preflight never dispatch.
- No raw message body, attachment, secret, token, authentication header, supplier payload, or
  unrestricted event payload is stored.

Completed evidence is append-only. Actor identifiers are durable nullable raw IDs so later User
lifecycle changes cannot rewrite audit history.

## Permissions

- Keep ticket.manage_rules for rule administration and ticket.rule_publish from Slice 1 for publish.
- Add explicit ticket.rule_preview and ticket.rule_execution_view permissions through additive,
  read-back-verified permission changes; do not broadly synchronize roles.
- Preview requires both ticket.rule_preview and ordinary authorization to view the selected Ticket.
- Execution-log access requires ticket.rule_execution_view and work-context authorization.
- Runtime authority comes only from the protected system actor and registry-declared action
  permissions. The initiator, publisher, API ability, Admin role, and imported JSON never grant
  runtime authority.
- Do not expose actor selection or permission synchronization in UI or rule definitions.
- Retry/full-rerun permission and UI are deferred to Slice 6.

## Tests

- SQLite and authoritative Dev MariaDB contract tests prove savepoints, per-Ticket locking, branch
  rollback, root transaction survival, and fail-closed behavior when required semantics are absent.
- Frozen published-set tests prove concurrent draft edits or publishes affect only later roots.
- Event normalization tests cover deterministic fingerprints, minimization, before/after facts,
  initiator/actor separation, correlation, causation, FIFO child events, and no-op suppression.
- Idempotency tests cover duplicate event delivery, duplicate action positions, concurrent roots,
  after-commit dispatch, and retry of only failed/not-run idempotent positions.
- Loop tests cover self-change, A-to-B-to-A, repeated event fingerprints, depth, evaluated-rule and
  action budgets, and one visible loop_blocked Ticket history summary.
- Failure tests prove later positions are not_run, successful stop applies only after a completed
  branch, failed/unmatched rules do not stop later rules, and external failures remain truthful.
- Privacy tests prove completed attempts are immutable, values are redacted/truncated/fingerprinted,
  customer surfaces disclose nothing, and deleted users retain safe raw-ID evidence.
- Preview tests prove identical evaluation/planning with zero Ticket, rule counter, audit, queue,
  Signal, notification, or external mutation.
- Creation parity covers Tech UI, Customer Portal, Email, API, Intake, Relationship, Telephony,
  Signal, scheduled occurrences, Workflow/automation callers, and audits unexpected direct creators.
- SLA and assignment parity proves rule, Contract, default SLA ordering and explicit rule assignment
  versus fallback Assignment Engine behavior.
- Permission tests cover preview/log access, Ticket work context, fixed actor grants, action guard
  denial, and no authority inherited from initiator or publisher.
- Focused Slice 2 regressions cover Ticket creation, scheduled occurrences, conditions, branches,
  preview, delivery audit/reconciliation, permissions, retries, idempotency, and loop limits.
- Pint, PHP syntax lint, focused Laravel tests, the global Git diff check, and authoritative Dev
  MariaDB transaction/locking contracts pass.

## Documentation

- Document normalized events, transactions/savepoints, locks, budgets, actor authority, audit
  minimization, preview guarantees, creation ordering, and default-off activation.
- Update Ticket technical operations and permission documentation during implementation.
- Update docs/TODO.md and add a stable human-review checklist entry only during implementation
  handoff, not while preparing this slice.
- Do not create a public website handoff for the invisible foundation.

## Implementation Evidence

Slice 2 is implemented and verified on authoritative Dev while runtime authority remains disabled.

- Seven focused Laravel suites pass 37 tests / 313 assertions. Coverage includes event and grouped
  condition contracts, branch isolation, creation and scheduled-occurrence parity, preview
  no-write behavior, delivery audit/reconciliation, retry eligibility, idempotency, stale targets,
  loop budgets, and production creation-source consolidation.
- Pint passes for 46 focused files. Changed PHP files pass syntax lint, and the global Git diff check
  passes.
- Migrations 242000 and 243000 were recorded in Dev batch 7 by a concurrent task before the planned
  controlled Slice 2 migration step. They were not rerun, and the historical 242000 migration was
  left unchanged. The required reconciliation fingerprint was added through forward-only migration
  050000, which ran in batch 11 after the scoped backup
  `/tmp/nexum-issue231-backups/pre-ticket-rule-reconciliation-20260825T231826Z.sql` with SHA-256
  `e080d1e4a597f22fd1242ab91ba41e77623318ba33bf0b6b551e923bac0dc305`.
- Live schema and data read-back found `runtime_authority=legacy`, v2 configuration disabled, zero
  execution runs and after-commit deliveries, ten completed-evidence immutability triggers, and 22
  foreign keys. The protected actor has exactly `signal.action.execute` and `ticket.update`, has no
  roles, and Admin plus Superuser have both preview and execution-view grants.
- An actual MariaDB transaction contract proved failed-branch savepoint rollback, later-rule
  continuation, survival of the outer Ticket mutation, completed-evidence immutability, and cleanup
  on outer rollback.
- Two independent MariaDB connections proved the per-Ticket authority lock: the competing
  connection failed with `HY000` / `1205` lock timeout, and authority was restored to `legacy`
  afterward.
- Release hardening now gives runtime and no-write preview one shared derived-event identity and the
  exact durable loop reasons `repeated_event_fingerprint`, `depth_budget_exceeded`,
  `evaluated_rule_budget_exceeded`, and `action_budget_exceeded`. Repeated-event and depth
  blocks retain the original blocked semantic event fingerprint separately from the unique wrapper
  evidence fingerprint.
- Forward migration `2026_08_26_090000_add_ticket_rule_loop_evidence.php` adds nullable
  `loop_reason_code`, nullable `blocked_event_fingerprint`, and `tre_loop_reason_ix`. It is
  idempotent for partial DDL, verifies its postcondition, and refuses destructive down after loop
  evidence exists. It ran path-scoped in authoritative Dev batch 15; read-back confirmed both
  columns and the named index with zero loop-event rows. Runtime authority remains `legacy`, and
  its presence does not authorize runtime activation.

## Dependencies And Rollback

- Depends on completed and verified Slice 1, the Approved RFC, and the Accepted ADR.
- MariaDB savepoint and row-lock behavior must be proven before mutating v2 runtime work proceeds.
- Slices 3-6 depend on these event, actor, audit, idempotency, and transaction contracts remaining
  stable.
- Rollback is a forward disable to legacy authority. Preserve immutable versions, root runs,
  executions, action results, Ticket history, and legacy logs.
- Schema down may remove new empty tables only before evidence exists; once evidence exists it must
  refuse destructive removal. Use a reviewed forward fix for populated environments.
- No rollback may erase the original authorized Ticket mutation or completed audit evidence.

## Done Criteria

- [x] Slice 1 is complete and its compatibility evidence is accepted.
- [x] The normalized event and frozen published-set contracts are implemented and documented.
- [x] Root, event, rule, action, and after-commit records are additive, immutable after completion,
  privacy-safe, indexed, and paginated by design.
- [x] The protected system actor exists with fixed code-owned permissions and no impersonation path.
- [x] Savepoint, locking, branch rollback, FIFO events, idempotency, limits, and loop blocking pass on
  authoritative Dev MariaDB.
- [x] Preview uses runtime evaluation and authorization with no side effects.
- [x] Every production creation path uses the authoritative coordinator.
- [x] Version-1 ticket.created behavior matches the legacy engine across representative inputs and all
  production creation paths before any new trigger/action is enabled.
- [x] The v2 runtime remains default-off outside explicit tests and approved Dev verification.
- [x] English-only copy is used; no language files or partial localization are added.
- [x] Focused Slice 2 tests, Pint, PHP syntax lint, the global Git diff check, and authoritative Dev
  MariaDB contract checks pass.
- [x] Documentation and a Pending human-review checklist entry are complete at implementation handoff.
- [x] No Main or production migration, activation, promotion, or deployment occurs.
