# Feature Slice: Email Mail Personal Simple Rules

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let ordinary users create safe rules for their own personal mailboxes from `/tech/mail`, while
shared and system mailbox rule work stays in the Admin rule engine.

## User-Visible Behavior

The selected message's More menu shows Add rule when the action is valid. For a personal mailbox
owned by the signed-in user, Add rule opens a compact personal rule modal. The modal shows matched
rule execution history for the selected message and lets the owner create a simple active rule:
match sender, sender domain, subject, To, or Cc, then move matching future Inbox mail to a selected
same-account folder or Archive.

For shared or system mailboxes, users with `email.rule_manage` are redirected to the Admin Email
rules builder with the mailbox and sender condition prefilled when the account is eligible there.

## Scope

- Add `rule_kind` and `owner_id` to `email_rules` and immutable `email_rule_versions`.
- Add `personal_simple` rules under the existing Email rule/version/attempt model.
- Add a guarded personal rule creation action for owner-only personal mailbox rules.
- Add a personal rule execution pass for personal mailboxes that do not run legacy Ticket ingress.
- Execute safe rule actions through the existing provider remote-operation ledger.
- Keep Admin/API rule management scoped to admin-managed shared/system rules.
- Add the Mail workspace Add rule modal/redirect behavior.

## Out Of Scope

- Full grouped rule builder for personal mailboxes.
- Personal rules for shared mailboxes.
- Cross-domain actions, Ticket actions, Signal handoff, webhooks, AI, sending, permanent delete, or
  stop-processing from the personal modal.
- Rule retry, undo, bulk reprocessing, and conflict/reconcile UI.
- Provider label/keyword synchronization.

## Data Touched

- `email_rules`
- `email_rule_versions`
- `email_rule_accounts`
- `email_rule_execution_attempts`
- `email_remote_operations`
- `email_mailbox_placements`

## Permissions

Personal simple rules require the selected account to be a personal mailbox owned by the actor plus
effective mailbox Organize access. Shared and system mailbox Add rule redirects require
`email.rule_manage`; the Admin route keeps its existing admin/route-permission protection.

## Tests

- Livewire feature test for creating a personal simple move rule from `/tech/mail`.
- Feature test for executing a personal simple rule on a later personal Inbox message without Ticket
  ingress.
- Livewire/admin feature test for shared/system Add rule redirect and Admin builder prefill.
- Existing Admin Email rule, published version, rule API, inbound automation, and provider move tests
  remain relevant.

## Documentation

- Email README and Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `HR-2026-08-12-015`.

## Done Criteria

- Personal Add rule is visible only for owner-accessible personal mailboxes with a safe action target.
- Personal rules cannot create Tickets, emit Signals, send mail, call webhooks, or permanently delete
  provider mail.
- Personal rules run only against the owner's personal Inbox placements and use published snapshots.
- Shared/system Add rule opens Admin rule management only for users with rule-management permission.
- Focused and full Email tests pass on Dev.
