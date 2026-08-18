# Feature Slice: Email Mail AI-Reviewed Conversation Actions

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Depends on: `2026-08-14-email-mail-smart-inbox-suggestion-foundation.md`
Owner: Svein / Codex

## Goal

Let a human apply a small allowlist of editable Smart Inbox proposals through existing domain
actions, while keeping AI generation non-mutating and every authorization decision current.

## User-Visible Behavior

A technician can review and, with an explicit click, apply an existing Email category, add an
existing active tag, or create an editable internal Task from a current Smart Inbox suggestion. The
queue displays the applied reference and treats a repeated click idempotently. AI analysis itself
remains read-only.

## Scope

- Apply an existing active Email category or existing active Taxonomy tag to the conversation.
- Create an editable Task through Task's normal action and work-context rules.
- Keep the existing separately reviewed AI-summary-assisted Mail-to-Ticket action unchanged; it is
  not a Smart Inbox suggestion effect in this slice.
- Recheck source fingerprint, suggestion state, user status, mailbox access, normal target-domain
  permissions, and the selected AI agent's write/scopes at click time.
- Record one idempotent suggestion event and applied domain reference.

## Out Of Scope

- AI-created category/tag definitions, automatic apply, send/reply, provider delete/read mutation,
  rule publication, arbitrary Ticket changes, or direct model writes that bypass domain actions.

## Data Touched

- Existing `email_smart_inbox_suggestions` and append-only suggestion events.
- Existing Email conversation classifications and Taxonomy definitions.
- Task records created only through Task's normal `StoreTask` action.

The suggestion stores only the resulting classification or Task reference. It does not copy a raw
model response or create a second target-domain audit store.

## Permissions

Every click rechecks the active user, mailbox View, source fingerprint, pending state, exact
provenance-bearing AI agent, current agent/provider/policy readiness, action execution enablement,
and the exact named API scope. Category/tag application requires `email.update`, current mailbox
Organize, and active existing definitions. Task application requires `tasks.create` plus Task's
normal work-context rules. Wildcard or newly selected fallback-agent authority cannot replace the
agent recorded on the suggestion.

## Tests

- Every agent-scope/user-permission/mailbox/target-domain intersection fails closed.
- Stale, dismissed, revoked, unknown-target, and already-applied suggestions cannot write twice.
- Task creation honors Task work context and remains editable.
- No provider or outbound-message side effect occurs.

## Done Criteria

- [x] Only the documented category, tag, and Task allowlist is actionable.
- [x] Generation remains read-only and application remains an explicit human click.
- [x] Applied references and immutable events provide end-to-end provenance.
- [x] Focused Email, Task, Taxonomy, and Integration regressions pass on Dev.

## Implementation And Dev Verification

Category application is compare-and-set and never overwrites a different current human
classification. Tag application is additive and never creates a Taxonomy definition. Task creation
uses Task's guarded action, creates an editable internal Task, and does not invent an assignee or due
date. The suggestion row is locked so retries return the same applied reference instead of writing
twice.

Focused reviewed-application coverage passes **7 tests / 79 assertions**. Foundation,
reviewed-apply, and review-queue coverage passes **21 / 252**, while the full Smart Inbox set with
supervised cleanup passes **32 / 422**. Human review remains `HR-2026-08-14-013` (`Pending`).

## Documentation

Update Email Knowledge, the module README, `docs/TODO.md`, and `docs/human-review.md`.
