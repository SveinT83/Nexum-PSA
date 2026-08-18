# Feature Slice: Email Mail Provider Sent Append Support

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Finish provider Sent reconciliation by allowing Mail to append a safe RFC 822 copy to the real
provider Sent folder when the provider does not create one automatically, while keeping normal IMAP
Sent-folder sync authoritative for final reconciliation.

## User-Visible Behavior

After SMTP success, Mail stores a raw outbound snapshot for provider Sent reconciliation. The normal
`/tech/mail` workspace does not show Provider Sent reconciliation work as a dashboard, because that
technical state should not compete with the inbox workflow. Mail-owned backend code can append a
stored raw snapshot to the discovered provider Sent folder when needed and marks the reconciliation
as `appended` until normal Sent-folder sync imports and confirms the provider copy.

Before appending, Nexum checks whether a same-account Sent placement with the same normalized
`Message-ID` already exists and reconciles that placement instead of creating a duplicate.
The backend then reserves one `append_started` transition under a database lock. Repeating an
already-started or accepted append does not write to IMAP again. A failure after the provider write
started remains blocked for reconciliation; only a proven pre-write failure may be reserved again.

## Scope

- Store raw outbound snapshots for new SMTP sends.
- Add IMAP APPEND support for provider Sent using the discovered Sent folder and `\Seen`.
- Add `appended` and `append_failed` reconciliation states.
- Keep provider Sent append support in Mail-owned backend code without exposing a regular Mail
  workspace dashboard.
- Deduplicate by same-account normalized `Message-ID` before append.
- Reserve append ownership under lock and fail closed after an ambiguous provider-write start so a
  retry cannot create a second Sent copy.

## Out Of Scope

- Replacing provider Sent sync as the authoritative final state.
- Ambiguous multi-copy manual chooser UI.
- Ticket timeline projection of outbound Sent copies.
- Automatic retry scheduling for Sent append failures.

## Data Touched

- Existing `email_sent_reconciliations`.
- Existing outbound `email_logs.context_json`.
- Local disk raw snapshot paths under Mail-owned storage.
- Existing provider Sent `email_folders` and eventual Sent `email_mailbox_placements`.

## Permissions

Appending to provider Sent requires effective mailbox Send access to the reconciliation account. It
does not grant mailbox View, Organize, Ticket, rule, or AI tool access.

## Tests

- Sending from Compose records a raw provider Sent snapshot.
- The regular Mail workspace does not show Provider Sent dashboard or `Append to Sent` controls.
- The append service calls IMAP APPEND and marks the reconciliation `appended`.
- Repeating an appended/in-progress row performs no second provider write; a provider exception
  after the write begins remains blocked, while a proven pre-write failure can be retried safely.
- A raw snapshot created immediately before a failed reconciliation insert is removed, and normal
  Sent sync can resolve an unconfirmed send reservation from its exact Message-ID.
- Existing Sent import reconciliation tests continue to verify final `Sent reconciled` state.

## Automated Verification

- Focused Mail regressions including this slice passed on Dev with 6 tests and 50 assertions.
- Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Dev migration, cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, no failed jobs, route registration, and git diff checks were also completed.
- The 2026-08-15 append reservation, ambiguity, orphan cleanup, and Sent-evidence repair regressions
  pass with 4 tests / 16 assertions.

## Done Criteria

- Pending Sent reconciliation rows with raw snapshots can be appended to provider Sent.
- Existing provider Sent copies are detected before append to avoid duplicates.
- Append ownership and ambiguous failure evidence prevent a repeated provider write.
- Regular Mail users are not shown provider Sent technical reconciliation work in the inbox
  workspace.
