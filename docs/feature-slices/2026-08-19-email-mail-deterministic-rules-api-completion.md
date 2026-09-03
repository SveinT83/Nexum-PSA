# Feature Slice: Email Deterministic Rules API Completion

Status: Superseded By Completed Order 10 Implementation
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 10)
Review ID: `HR-2026-08-16-010`

2026-09-03 completion: this historical safety-repair slice is retained as the audit trail, but its
remaining work is now implemented by
`docs/feature-slices/2026-08-16-email-mail-deterministic-rules-api-completion.md`. The durable draft,
publication, bounded reprocess, per-action idempotency, retry/full-rerun, permissions and API work is
on Dev. Human review remains open; this status does not claim production verification.

2026-08-21 audit: the then-current Undo scaffold changed local folder state without a provider
operation or the complete immutable execution/reversal contract. It had no focused completion tests
and could not be represented as API/Undo parity.

## 2026-08-24 Beta-Critical Safety Repair

The unsafe scaffold is replaced by the smallest complete contract that can be enabled before the
full dependency-gated slice:

- A completed `EmailRuleExecutionAttempt` is immutable. Rule execution records `failed` for the
  first failed action and `not_run` for every later action, using stable reason codes rather than raw
  exceptions.
- Admin rule execution detail and Undo eligibility are exposed through account-scoped REST routes
  using `email.rules.read`; applying Undo additionally requires `email.rules.execute`, current
  `email.rule_manage`, and current Mailbox Organize access.
- Undo is available only when the whole successful attempt is represented by exactly one allowlisted
  provider Archive or Move result. The immutable action position/type/target, account, placement,
  operation type, acknowledged result, and source remote-operation ID must all agree.
- The inverse is delegated to the existing verified `UndoEmailRemoteOperation` contract. It rechecks
  the 15-minute window, current mailbox authority, exact local/provider evidence, later operations,
  and provider state; repeated requests return the same uniquely linked inverse.
- Mixed successful effects, compatibility local Archive, tags, cross-domain actions, missing ledger
  evidence, mismatched targets, stale state, and ambiguous outcomes fail closed before any inverse or
  local compensation is created. The execution attempt is never rewritten as `reverted`.
- Execution API output contains only action identity/status, stable reason codes, and opaque operation
  IDs. It omits condition/message content, before/target evidence, folder paths, and raw exception or
  provider messages.

No migration, database data change, queue/scheduler change, provider call, cron change, or runtime
activation was performed for this repair. Focused SQLite verification passes 5 tests / 65 assertions;
adjacent Email Undo, supervised cleanup, inbound automation, and Integration coverage passes 91 /
875; targeted existing Rules API/runtime coverage passes 3 / 36.

This safety repair does not complete the full Order 10 target in
`docs/feature-slices/2026-08-16-email-mail-deterministic-rules-api-completion.md`. Separate durable
draft editing, bounded selection previews, reprocess run/item/action ledgers, retry/full-rerun
coordination, and complete API/OpenAPI parity remain dependency-gated by the unfinished Orders 8-9.
The slice and human review therefore remain open.

## Purpose

This slice completes the Email Rules API by implementing full versioning, drafting, and preview capabilities, along with Signal loop protection and execution-time precedence.

## Scope

- **Drafting & Versioning:** Rules can be drafted, previewed, and published with version history.
- **Precedence:** Explicit ordering of rule execution.
- **Signal Loop Protection:** Preventing infinite loops between Email rules and Signal (external triggers).
- **Execution Tracking:** Immutable record of rule attempts, successes, and failures.
- **Undo Parity:** Capability to undo rule effects where possible (e.g., move back, un-tag).

## Technical Design

### Rule Versioning
- New table `email_rule_versions` or columns in `email_rules` for state management (Draft/Published/Retired).
- Rule "snapshots" when executing to ensure deterministic behavior even if the rule changes during a batch.

### Loop Protection
- Inject a "Signal-Depth" or "Correlation-Trace" into rule execution context.
- Limit max depth to 3-5 steps.

### Execution Record
- `email_rule_executions` table to track every time a rule hits a message/conversation.

## Implementation Plan

1. **Database Migration:** Update `email_rules` and create `email_rule_executions`.
2. **Backend Logic:** Update `EmailRule` model and execution service.
3. **API/Controller:** Complete Admin Rules API for drafting and publishing.
4. **Signal Integration:** Implement depth tracking.

## Boundary & Risks

- **Boundary:** Focused on deterministic execution; no AI-based rule suggestions here.
- **Risk:** Complex undo logic for destructive or external actions (replies).
- **Mitigation:** Offer Undo only through an allowlisted, exact, provider-verified inverse; reject
  local-only or mixed compensation.
