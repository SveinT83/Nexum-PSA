# ADR: Email Account Owns Password-Based IMAP/SMTP Configuration

Status: Accepted
Date: 2026-09-01
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Supersedes: the password-credential ownership and provider-first Admin workflow portions of
`2026-08-11-email-owned-mail-client-domain.md`

## Context

The implemented administration required a reusable Integration provider to be created, staged,
verified and activated before an Email account could be created or repaired. Existing accounts were
shown beside a Legacy mailbox migration preview and cutover history. A wrong server, username or
password could require a replacement provider rather than editing the mailbox.

That workflow exposed implementation and migration machinery instead of solving the administrator's
normal task: configure one mailbox and prove that Nexum can authenticate to both incoming and
outgoing mail. It also made account health difficult to understand because an account and its
provider could show different active states.

## Decision

The Email account is the only ordinary administration surface for password-based IMAP/SMTP.

- Add and edit use one form under Email Accounts.
- The form owns the email address, display name, IMAP server/port/TLS/username/password, SMTP
  server/port/TLS/username/password, mailbox purpose and access grants.
- Passwords are write-only and encrypted at rest. Existing passwords are preserved when their edit
  fields are blank; entering a value replaces that protocol's password.
- Saving schedules one bounded connection check. The result reports IMAP and SMTP independently.
- A requested active account becomes active only after both checks pass. Failure leaves it inactive
  and editable so the same account can be corrected and tested again.
- TLS certificate and hostname validation, endpoint allowlisting, SSRF/rebinding protection, bounded
  connection timeouts, secret-safe logs, authorization and audit remain mandatory.
- The UI does not expose provider lifecycle, staging, credential versions, replacement providers,
  legacy migration preview or cutover history.

Integration continues to own OAuth client registration, reusable third-party connections, token
lifecycle, AI provider governance and central data-egress policy. Internal historical provider rows
may remain as rollback/audit evidence, but they are not required or selected by the ordinary
password-based Email account workflow.

## Rationale

- It matches ordinary mail-client setup and the administrator's mental model.
- One status answers whether Nexum can receive and send for the account.
- Correcting a typo or password is an edit, not a migration.
- The backend can keep security and audit controls without exposing lifecycle jargon.
- Existing Integration rows do not need destructive cleanup during the UX correction.

## Consequences

Positive:

- Account setup and repair happen in one place.
- Failed settings can be edited and tested repeatedly on the same account.
- Ordinary administrators no longer need to understand provider or migration architecture.
- Runtime activation is tied to a successful two-protocol check.

Negative:

- Password-based credentials are account-scoped and cannot be reused implicitly across mailboxes.
- Existing provider-first tests and documentation must be replaced.
- OAuth accounts will need a later driver-specific connection action inside the same account
  workflow while Integration retains token governance.
- Historical provider/migration tables may remain until a separately reviewed cleanup proves they
  are no longer needed for rollback or audit.

## Alternatives Considered

- Keep the provider-first workflow and improve explanations. Rejected because the extra object and
  lifecycle remain unnecessary for ordinary IMAP/SMTP.
- Automatically create a visible provider behind the account form. Rejected as a user concept; an
  internal adapter may exist, but it cannot become a second object the administrator must manage.
- Bulk-migrate all credentials immediately. Rejected because the administrator accepted re-entry,
  and destructive migration is unnecessary for delivering the final workflow safely.
- Remove endpoint and TLS policy with the provider UI. Rejected because simpler UX must not weaken
  transport security or allow arbitrary internal-network access.

## Follow-Up

- Implement the approved Feature Slice
  `../feature-slices/2026-09-01-email-account-connection-setup.md`.
- Deprecate the ordinary Email Providers navigation and provider/migration UI.
- Update Email and Integration Knowledge documentation.
- Keep historical provider data until cleanup has its own evidence and human review.
