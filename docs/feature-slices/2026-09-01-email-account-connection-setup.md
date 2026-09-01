# Feature Slice: Simple Email Account Connection Setup

Status: Implemented On Dev - Production Human Review Pending
Date: 2026-09-01
Parent: `../rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `../adr/2026-09-01-email-account-owned-imap-smtp-configuration.md`
Owner: Codex
Human review: `HR-2026-09-01-001`

## Goal

Replace provider-first and legacy-migration administration with one final account-owned IMAP/SMTP
setup and repair workflow.

## User-Visible Behavior

- Email Accounts always offers Add account to an authorized administrator.
- Add and Edit show ordinary incoming and outgoing server settings.
- Save and test checks authenticated IMAP and SMTP through the bounded Email worker.
- A passing check activates the account when Active was requested.
- A failure leaves the same account editable and inactive with safe protocol-specific guidance.
- Password fields are blank on edit and preserve the stored password unless a replacement is entered.
- Ordinary navigation no longer exposes Email Providers or Legacy mailbox migration.
- Existing exactly verified provider-bound accounts are promoted automatically; no hybrid warning
  or manual re-entry is required.

## Scope

- Email account controller, form, index and connection health presentation.
- Encrypted account-owned password storage.
- Background connection-check and activation job.
- Removal of provider administration routes from normal installations.
- One-way promotion of existing credentials and destruction of obsolete duplicate ciphertext.
- Tests, Knowledge, TODO and human-review updates.

## Out Of Scope

- OAuth driver setup.
- Removal of inert historical audit rows and tables.
- Sending a real message to a recipient; SMTP authentication proves write capability without
  transmitting mail.

## Data Touched

- Existing `email_accounts` endpoint, encrypted credential, binding and health columns.
- Existing queue and failed-job infrastructure.
- Historical provider rows are disabled and their credential ciphertext is destroyed after every
  bound account has been promoted successfully in one transaction.

## Permissions

- Existing Email account binding/administration permission remains required.
- Account configuration never grants mailbox content access.
- Existing per-account view/organize/send grants remain unchanged.

## Tests

- Create stores encrypted account-owned credentials and dispatches the bounded check.
- Edit preserves blank passwords and replaces entered passwords.
- Existing Integration-bound account is promoted in place without manual secret re-entry.
- One-way cutover promotes only an exactly verified binding and fails closed on incomplete evidence.
- Normal runtime and routes reject the retired provider-owned path.
- Stale queued checks cannot activate a newer configuration.
- Passing IMAP/SMTP activates only when requested; failure remains inactive and editable.
- UI contains no provider/staged/legacy-migration workflow.
- Existing Mail runtime, Ticket sending and permission tests remain green.

## Documentation

- Amend the parent RFC.
- Add the superseding ADR.
- Update Email and Integration Knowledge.
- Reconcile `docs/TODO.md` and `docs/human-review.md`.

## Done Criteria

- [x] Approved RFC amendment and accepted ADR are present.
- [x] One account-owned Add/Edit form is implemented.
- [x] Background IMAP/SMTP test and safe activation are implemented.
- [x] Provider/migration lifecycle is absent from ordinary UI.
- [x] Existing provider binding is promoted automatically and the duplicate secret store is destroyed.
- [x] Relevant Dev tests pass.
- [ ] Production browser review confirms desktop and narrow layouts.
- [x] TODO and human-review evidence are current.
