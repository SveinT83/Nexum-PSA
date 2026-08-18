# Feature Slice: Email Mail Supervised Smart Inbox Cleanup

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Depends on: durable Smart Inbox suggestions and remote-operation recovery/undo
Owner: Svein / Codex

## Goal

Add a supervised review workflow for safe classification and reversible mailbox cleanup, including
an explicit path from a reviewed suggestion into the existing rule builder.

## User-Visible Behavior

A technician can accept one or an exact selected batch of reversible provider Archive/Move
suggestions and see an honest result for each. Successful work appears in the normal mailbox
operation/Undo surface. `Always do this` opens a prefilled ordinary rule editor and never creates or
activates a rule merely by opening it.

## Scope

- Accept, dismiss, or correct category/tag suggestions.
- Apply only reversible move/archive cleanup through the remote-operation ledger.
- Bind cleanup to the exact reviewed source placement, folder, UID, UIDVALIDITY, and sync version;
  never follow the message into a different provider placement at apply time.
- Keep a fixed bulk snapshot of suggestion IDs and return per-item success/failure without including
  mail that arrived after confirmation or applying two cleanup suggestions to the same source.
- Offer `Always do this` only as a prefilled existing personal/Admin rule-builder draft; require the
  normal preview and explicit publish/save step.
- Preserve provider Seen and Nexum `Unread for me` unless a separately explicit read action exists.
- Add distinct Admin rule actions for provider Archive and provider Move. Keep the legacy `archive`
  action local-only for compatibility.

## Out Of Scope

- Permanent delete, send/reply, silent rule creation, automatic learning, unattended cleanup, or
  unrestricted cross-domain writes.

## Data Touched

- Existing Smart Inbox suggestions/events and the provider remote-operation ledger.
- Existing personal and Admin Email rule builders only after a user explicitly submits their normal
  form; opening `Always do this` creates no rule, version, or execution.

## Permissions

Single and batch apply repeat current active-user, suggestion ownership/state/fingerprint, exact AI
agent scope, mailbox View/Organize, placement, folder, and provider-policy checks. Admin rule runtime
rechecks the active published-by actor and mailbox Organize access before provider execution.

## Tests

- Reversible-operation allowlist and undo linkage.
- Fixed bulk snapshot, idempotency, stale/revoked items, and partial failure reporting.
- Provider/personal unread remains unchanged.
- Rule prefill stays inactive/unpublished until explicit confirmation.
- User/account isolation and current authorization on every item.

## Done Criteria

- [x] Cleanup remains supervised, reversible, bounded, and auditable.
- [x] `Always do this` never silently activates a rule.
- [x] Bulk processing has deterministic membership and honest partial results.
- [x] Focused Mail/rule/remote-operation tests pass on Dev.

## Implementation And Dev Verification

Typed `archive_mail` and `move_to_folder` proposals name an existing selectable same-account folder.
Explicit apply records one deterministic `email_remote_operation` reference, commits the suggestion
state before provider I/O, and then uses the normal provider execution/recovery path. Successful
operations inherit verified Undo; provider Seen and every user's Nexum personal unread state remain
unchanged.

Batch apply snapshots an exact unique list of at most 50 suggestion IDs. Each item reauthorizes and
returns its own stable success or failure result, so a later arrival cannot enter the batch and one
failure does not conceal the remaining results. The batch accepts cleanup effects only and reserves
each exact source placement once, so one reviewed item cannot move a message and let a later item
silently follow it into the new folder.

Analysis records server-resolved source placement, folder, path, UID, UIDVALIDITY, and sync-version
evidence. Correction cannot replace that evidence, and apply locks and compares it before recording
provider work. A moved, reimported, or otherwise changed source therefore becomes stale without a
second IMAP mutation. Immediate and persistent queue feedback show the actual provider operation
state; failed, superseded, cancelled, and pending operations are never rendered as green success.

`Always do this` returns only a prefilled existing editor. The personal path does not create a rule
until the user explicitly submits the modal. The Admin path opens the normal builder with
`is_active=0`, `stop_processing=1`, and a distinct `provider_archive` or `provider_move` action;
normal save/publication is still required. Its URL contains only a short-lived one-use opaque
prefill token rather than sender, subject, rule name, or condition data; the normal controller
rechecks the current user and mailbox before consuming it. The existing local-only `archive` rule
action is unchanged. A provider-rule failure records later actions in that rule as `not_run` while
normal rule evaluation can continue to other eligible rules. A successful cleanup rule with
`stop_processing=1` stops default Ticket routing.

Focused supervised-cleanup coverage passes **11 tests / 170 assertions**. The combined Smart Inbox
foundation, reviewed-apply, review-queue, and cleanup set passes **32 / 422**; the broader focused
Mail workstream set passes **112 / 993**. The final application-wide Dev suite passes
**1,482 / 12,749**. PHP syntax, Pint, and diff checks pass. Human review remains
`HR-2026-08-14-014` (`Pending`).

## Documentation

Update Email Knowledge, the module README, `docs/TODO.md`, and `docs/human-review.md`.
