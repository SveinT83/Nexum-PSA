# Feature Slice: Email Mail Provider Drafts Direct Editing

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let technicians open real provider Drafts-folder placements from `/tech/mail`, edit them in the Mail
composer, send them through the normal SMTP path, and clean up the original provider draft copy.

## User-Visible Behavior

Imported provider Drafts placements show an `Edit draft` action instead of Reply, Forward, Ticket,
rule, spam, or AI summary actions. Opening the draft copies the provider message recipients,
subject, body, and supported stored attachments into a Mail-owned local composer draft. Sending uses
the selected mailbox's SMTP account and then best-effort deletes the original provider Drafts UID.

If provider cleanup fails after SMTP success, the send remains complete and the user receives the
same provider cleanup warning used by local/provider draft lifecycle handling.

## Scope

- Add a provider-draft composer mode keyed to the selected Drafts placement.
- Capture provider Drafts placements into `email_composer_drafts`.
- Restore provider draft recipients, Cc, subject, body, and durable supported attachments.
- Allow send from provider draft mode through the existing `SendEmailComposerMessage::handleNew`
  flow.
- Hide the original Drafts placement after confirmed provider cleanup.
- Keep provider Drafts placement editing unavailable for users without mailbox View and Send access.

## Out Of Scope

- Provider folder rename/delete.
- Concurrent draft editing locks or merge conflict UI.
- Editing provider draft copies that have no safe UID evidence.
- Automatic sending, automatic replies, or AI write actions.

## Data Touched

- Existing `email_composer_drafts` and `email_composer_draft_attachments`.
- Existing provider Drafts `email_mailbox_placements`.
- Existing outbound `email_logs` and Sent reconciliation flow after SMTP success.

## Permissions

Direct provider Drafts editing requires the user to have effective mailbox View and Send access for
the draft placement's account. Ordinary organize-only access is not enough to send a draft.

## Tests

- A provider Drafts placement opens in composer with stored To, Cc, subject, and body.
- Sending the edited provider draft uses SMTP, marks the local draft sent, deletes the provider
  Drafts copy, and hides the provider Drafts placement.

## Automated Verification

- Focused Mail regressions including this slice passed on Dev with 6 tests and 50 assertions.
- Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Dev migration, cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, no failed jobs, route registration, and git diff checks were also completed.

## Done Criteria

- A provider Drafts placement can be edited directly from `/tech/mail`.
- Sending the edited draft goes through normal SMTP and draft lifecycle services.
- The original provider draft copy is cleaned up or reported as a cleanup issue.
- Provider draft rows do not expose unrelated reply, Ticket, rule, spam, or AI read actions.
