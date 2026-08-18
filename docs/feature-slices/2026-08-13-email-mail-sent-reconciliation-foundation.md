# Feature Slice: Email Mail Sent Reconciliation Foundation

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Record outbound Mail sends as pending provider Sent reconciliation work, then confirm them when the
normal provider folder sync later imports the matching Sent placement.

## User-Visible Behavior

When a technician sends Compose, Reply, Reply All, or Forward from `/tech/mail`, SMTP success still
means the message was accepted by the outbound server. Mail now also records that the provider Sent
copy is pending. When IMAP polling later imports a Sent-folder message from the same account with the
same `Message-ID`, Mail links that provider placement to the outbound log and shows a compact
`Sent reconciled` badge on the Sent copy.

SMTP acceptance is also the user-facing send boundary. If the local raw Sent snapshot or even the
reconciliation record fails after that point, Mail keeps the successful outbound log, marks the
matching local draft sent, and shows a sanitized warning that the message was accepted and must not
be resent. Follow-up storage/reconciliation failure is never presented as a failed SMTP delivery.

Before SMTP, the existing unique outbound-log idempotency key now atomically reserves one exact RFC
`Message-ID`. Mail also attempts initial same-identity reconciliation evidence before the provider
call. Concurrent submission cannot elect a second sender. If a transport result is uncertain, that
reservation remains unresolved and blocks replay until provider Sent mail is reviewed. Normal
same-account Sent sync may later resolve it as accepted when the exact reserved Message-ID appears.
Failure to create the preliminary reconciliation evidence is `reservation_failed`, not acceptance,
and an SMTP exception cannot overwrite a concurrent exact Sent-sync confirmation.

This slice does not append messages to the provider Sent folder. It only reconciles provider copies
that arrive through normal folder synchronization.

## Scope

- Add a Mail-owned `email_sent_reconciliations` table and model.
- Record a pending reconciliation row after successful SMTP send and idempotent resend returns.
- Atomically reserve one unique outbound log and stable RFC `Message-ID` before SMTP, attempt the
  initial reconciliation row separately, and retain unresolved provider outcomes without a second
  SMTP attempt.
- Normalize `Message-ID` values so angle-bracket differences do not prevent matching.
- Reconcile pending rows when `StoreInboundMessage` creates or updates a Sent mailbox placement.
- Record reconciliation status back onto the outbound `email_logs.context_json`.
- Show a compact `Sent reconciled` badge for reconciled Sent placements in the Mail list and reader.
- Store outbound raw snapshots through the Email-owned verified private-storage writer and record a
  stable failed snapshot status/code when the file cannot be written.
- Delete a newly created raw snapshot if its reconciliation row cannot be persisted.
- Reconcile an unresolved send reservation as accepted when normal same-account Sent sync imports
  its exact reserved Message-ID.
- Catch post-SMTP reconciliation failures at the outbound action boundary, preserve the accepted
  `EmailLog`, and expose warning metadata without another SMTP call for the same persisted
  idempotency key.

## Out Of Scope

- IMAP APPEND to provider Sent after SMTP send.
- Provider Drafts folder synchronization.
- Reconciliation dashboards, retry controls, or ambiguous-match resolution UI.
- Attachment-level Sent-copy reconstruction.
- Ticket timeline projection or customer-portal evidence capture.
- Automatic replies or AI-triggered sending.

## Data Touched

- New `email_sent_reconciliations` table.
- New `EmailSentReconciliation` model.
- New `EmailSentReconciliationService`.
- Outbound `email_logs.context_json.provider_sent` status metadata.
- Sent-folder `email_mailbox_placements` imported through existing provider sync.

## Permissions

Sending still requires the existing effective mailbox Send authorization. Reconciliation is a
background Mail-owned consistency action over the same account's provider Sent folder and does not
grant mailbox read access, mailbox organize access, Ticket access, or AI/tool access.

## Tests

- SMTP success records a pending provider Sent reconciliation row.
- Provider Sent import with the same `Message-ID` marks the outbound log reconciled and shows the
  `Sent reconciled` badge in `/tech/mail`.
- Existing send, folder import, and Mail workspace tests continue to pass.
- Sent snapshot and reconciliation-record failures after SMTP keep the composer/draft in sent state,
  return an explicit `Do not resend it` warning without internal exception text, and do not call SMTP
  twice for the same persisted idempotency key.
- Reservation ownership is atomic, uses the pre-generated Message-ID, blocks concurrent/repeated
  sends, and preserves unresolved evidence when the SMTP transport result is ambiguous.
- Failed reconciliation creation leaves no orphan snapshot, and later exact Sent evidence resolves
  an unresolved reservation without another SMTP call.
- Preliminary reconciliation failure remains distinct from provider acceptance, and a racing exact
  Sent import wins over later ambiguous SMTP exception handling.

Automated Dev verification completed 2026-08-13:

- Sent-reconciliation focused Email regressions pass with 2 tests and 17 assertions.
- Full `EmailModuleTest.php` passes with 105 tests and 883 assertions.
- PHP syntax checks pass for the reconciliation model, service, migration, send action, inbound
  storage job, Mail workspace component, and Email feature test file.
- `php artisan migrate` applied `2026_08_13_110000_create_email_sent_reconciliations_table` in batch
  81 after removing the empty partial table left by the first failed MySQL FK-name attempt.
- `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default queue
  worker pass, `php artisan queue:failed`, `php artisan migrate:status`, and `git diff --check`
  pass. `git diff --check` reports only pre-existing CRLF working-copy warnings in unrelated files.
- The expanded 2026-08-15 pre-/post-SMTP boundary passes 11 focused tests / 94 assertions. Provider
  Sent append/reservation safety separately passes 4 / 16, and targeted Mail send regressions pass
  7 / 131.

## Documentation

- Email README and Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `docs/human-review.md`.

## Done Criteria

- A successful Mail send creates pending provider Sent reconciliation metadata.
- Importing the matching provider Sent placement reconciles the pending send.
- Reconciliation stays account-scoped and does not run Inbox ticket/rule automation for Sent mail.
- The Mail UI clearly marks reconciled provider Sent copies.
- A failure after SMTP acceptance remains visible as Sent follow-up work and never as an invitation
  to resend.
- A durable reservation exists before SMTP and an unresolved provider outcome cannot be replayed
  automatically or by repeating the same composer submission.
- Focused and affected Email tests pass on Dev.
