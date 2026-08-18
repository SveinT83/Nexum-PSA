# Feature Slice: Fail-Safe Mail Retention Purge

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Replace the legacy age-only Email purge with one bounded, auditable cache-retention operation that
deletes only expired local payloads whose provider, reconciliation, and Ticket-protection state is
definitively safe.

## User-Visible Behavior

Administrators see a read-only retention preview in **Email Sync & Cache Settings**. The preview
shows the current cutoff, expired-message count, eligible orphan count, protected count, and a
reason breakdown. It does not expose a manual destructive action.

The scheduled retention job preserves provider-backed mail, unresolved provider work, and Ticket
evidence. A storage failure remains visible as a durable failed attempt and leaves the message row
available for a later retry.

## Scope

- Centralize message purge eligibility and preview logic.
- Require an expired timestamp and no mailbox placement before local message content can be purged.
- Protect messages with pending, running, failed, or ambiguous remote operations.
- Protect scalar Ticket links, first-class Email/Ticket conversation links, and captured Ticket
  message/event evidence.
- Protect unresolved provider/Sent reconciliation and known Email ambiguity-review records.
- Honor an explicit Email legal-hold marker if a compatible marker is present.
- Delete local attachment files and raw EML before force-deleting an eligible orphan message.
- Persist one sanitized purge run and per-message attempt ledger without subject, address, body,
  attachment name, raw path, or provider secret content.
- Add the read-only Admin preview, focused regression tests, lifecycle documentation, TODO status,
  and a pending human-review entry.

## Out Of Scope

- Provider-side deletion, Trash/expunge commands, or a manual purge button.
- Legal-hold authoring/release, DSAR/export/erasure workflows, backup expiry enforcement, or account
  offboarding. Those need separately scoped lifecycle slices.
- Search-index or AI-derived-artifact cleanup where no such durable artifact store exists yet.
- Deleting Ticket-owned snapshots, links, events, or attachments.
- Treating a provider-deleted flag as sufficient proof that a placement is safe to remove. Provider
  disappearance confirmation and placement cleanup remain a separate reconciliation slice.

## Data Touched

- `email_messages` and `email_attachments` for definitively eligible orphan cleanup.
- Local Email raw-message and attachment storage paths.
- New `email_retention_purge_runs` and `email_retention_purge_attempts` audit tables.
- Existing placement, remote-operation, Sent-reconciliation, conversation-review, Ticket-link, and
  Ticket evidence tables are read as protection sources only.
- Email Admin configuration view, module README, Email Knowledge, TODO, and human-review register.

## Permissions

The existing `email.account_manage` guard controls the Admin settings/preview page. The scheduled
job has no user-facing route. This slice adds no mailbox-content permission and no destructive UI.

## Tests

- Preserve expired mail with an active provider placement.
- Preserve expired mail with Ticket-owned evidence.
- Preserve expired mail with pending or failed provider operations.
- Purge an expired, unplaced, unprotected orphan and its local files.
- Record storage deletion failure, preserve the database evidence, and safely retry.
- Show accurate eligible/protected counts and reason breakdown on the Admin preview.

## Documentation

- Update `app/Modules/Email/README-email-system.md`.
- Update `app/Modules/Email/Docs/knowledge/email-inbox-overview.md` for BookStack sync.
- Update `docs/TODO.md`.
- Add a pending entry to `docs/human-review.md`.

## Done Criteria

- [x] The forward migration and audit models are implemented.
- [x] One central service drives both preview and execution eligibility.
- [x] The job fails closed, records sanitized outcomes, and is idempotent across retries.
- [x] The Admin preview is read-only and accurately explains protected messages.
- [x] Focused tests and formatting checks pass on authoritative Dev.
- [x] README, Knowledge, TODO, and human-review tracking are updated.
