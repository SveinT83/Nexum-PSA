# Feature Slice: Email Deterministic Rules API Completion

Status: Rework Needed
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 10)
Review ID: `HR-2026-08-16-010`

2026-08-21 audit: the current Undo scaffold changes local folder state without a provider operation
or the complete immutable execution/reversal contract. The slice has no focused completion tests and
must not be represented as API/Undo parity.

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
- **Mitigation:** Focus undo on internal state changes (folders, tags).
