# Feature Slice: Integration-Owned Email Provider Credentials And Endpoint Security

Status: Done / Human Review Pending
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-integration-owned-email-provider-credentials-and-endpoint-security.md`
Owner: Svein / Codex
Human review: `HR-2026-08-16-006`

## Goal

Move the provider credential and endpoint-security boundary to Integration without silently moving
or exposing one legacy secret, weakening Email mailbox ownership, changing a provider, or creating a
fallback send path.

## User-Visible Behavior

- Admin can manage several Email provider connections in a dedicated Integration surface.
- Saving or rotating credentials creates a staged version. No connection becomes usable until an
  explicit verified activation.
- Public endpoints must pass the fixed TLS, DNS, address, and pinning policy. Approved internal
  endpoints additionally require Superuser authority, an installation-named CIDR, and a reason.
- Email account configuration shows an opaque provider label, source, capabilities, and readiness.
  It never displays or returns stored usernames, secrets, endpoints, or raw provider errors.
- Legacy accounts keep using their exact legacy source until a separate preview, stage, verify, and
  cutover succeeds. A failed Integration provider never falls back to legacy or a system mailer.

## Scope

### Stage 6A - Integration Security And Lifecycle Foundation

- Add Integration-owned provider connection, versioned credential, and append-only event records.
- Normalize and validate host/transport policy; enforce the standard port/TLS matrix and installation
  allowlists.
- Resolve every network attempt under bounded DNS/CNAME handling, deny mixed or unsafe answer sets,
  and pin one approved address while retaining hostname SNI/certificate verification.
- Implement staged create/rotation, explicit verification, activation, retirement/ciphertext
  destruction, revocation, safe audit, and redacted non-serializable runtime DTOs.
- Add the dedicated Bootstrap Admin surface and permissions without a public credential API.

### Stage 6B - Explicit Email Runtime Boundary

- Add an optional restrict-on-delete provider reference and explicit `legacy|integration` source to
  Email accounts while keeping legacy fields readable during compatibility.
- Resolve every Email IMAP/SMTP runtime call through one source-strict service. Queued work re-resolves
  current active/verified credentials in `handle` and rejects stale binding/configuration work.
- Complete polling, health, reconciliation, maintenance, default-account, composer/send readiness,
  and Commercial/cross-domain caller coverage. Remove direct decryption and silent system-mailer
  fallback at these boundaries.

### Stage 6C - Staged Legacy Migration And Rollback Readiness

- Add durable migration runs/items for read-only preview, locked fingerprint-preserving stage,
  separate provider verification, exact readiness preview, source/reference-only cutover, and guarded
  rollback.
- Migration stage decrypts/re-encrypts only in process and performs no DNS, provider call,
  deduplication, mail send, provider mutation, folder/read-state change, or source switch.
- Keep legacy ciphertext intact through the rollback window. Expose purge readiness only; actual
  legacy secret destruction requires later named review and backup/recovery proof.

## Out Of Scope

- Executing a live migration, DNS lookup, provider verification, poll, send, provider operation,
  cron/worker change, or legacy secret purge as part of implementation.
- OAuth provider drivers, Microsoft 365, Google Workspace, provider autodiscovery, arbitrary custom
  ports without installation allowlisting, certificate bypass, and plaintext secrets.
- Mailbox content access, grant changes, folder/message mutation, UID re-baselining, or endpoint/
  username changes in place.
- A public credential-management API or secret-bearing queue, log, session, event, notification, or
  audit payload.

## Data Touched

- Migration `2026_08_16_112000`: `integration_email_provider_connections`.
- Migration `2026_08_16_113000`: `integration_email_provider_credential_versions` and the
  connection's active-version reference.
- Migration `2026_08_16_114000`: append-only `integration_email_provider_events`.
- Migration `2026_08_16_115000`: nullable `email_accounts.provider_integration_id`, explicit source,
  and nullable legacy connection/credential fields.
- Migration `2026_08_16_116000`: `integration_email_provider_migration_runs`.
- Migration `2026_08_16_117000`: `integration_email_provider_migration_items`.
- `integrations` receives one root per Email provider connection; no generic toggle owns the
  provider lifecycle.
- Legacy Email account ciphertext remains intact until a later separately reviewed purge.

## Permissions

- `integration.email_provider_manage`: Admin and Superuser.
- `integration.email_private_endpoint_manage`: Superuser only; also requires a non-empty reason and a
  named installation CIDR match.
- Preview, stage, verify, cutover, and rollback require both
  `integration.email_provider_manage` and `email.mailbox_sync_manage`.
- Creating or changing an Email account binding additionally requires `email.account_manage`.
- No permission in this slice grants mailbox body, raw source, attachment, search, or conversation
  access.

## Tests

- Host parsing and normalization, URL/control/wildcard/zone rejection, IPv4/IPv6 classification,
  fixed and allowlisted ports, TLS matrix, bounded DNS/CNAME answers, mixed-set denial, private CIDR
  approval, always-denied ranges, and rebinding prevention.
- Pinned socket address with original hostname SNI/peer verification, TLS 1.2 floor, certificate and
  self-signed rejection, real IMAP STARTTLS, SMTP required STARTTLS, and authentication-after-TLS.
- Credential create/rotate/verify/activate/revoke/destroy lifecycle, exact-version locking, races,
  safe events/errors, redacted serialization, and absence from log/session/queue payloads.
- Legacy preview/stage/corrupt/stale/idempotent/cutover/rollback/purge-readiness behavior, including no
  DNS/provider/send/mutation during migration stage.
- Dispatch-then-revoke, rotate-before-handle, endpoint/binding staleness, source-strict runtime,
  health/poll/reconcile/send readiness, and no legacy/system-mailer fallback.
- Permissions and UI non-disclosure, no public API, Integration generic-toggle isolation, and
  cross-module SMTP caller regressions for Ticket, Sales, Marketing, UserManagement, CustomerPortal,
  Notification, and Commercial.
- MariaDB migration round-trip with down refusal while bindings, migration runs, or retained history
  make destructive rollback unsafe.

## Documentation

- Update Integration README and Knowledge for setup, TLS/endpoint trust, lifecycle, migration,
  rollback, and troubleshooting.
- Update Email README and Knowledge for provider binding/readiness and source-strict behavior.
- Update UserManagement permissions, the Mail completion index, TODO, and this Feature Slice.
- Add `HR-2026-08-16-006` as Pending with exact browser, permission, migration, runtime, rollback,
  queue, transport, and no-secret/no-provider-mutation checks.

## Implementation Evidence

- The order-6 focused matrix passes 42 tests / 470 assertions across endpoint and transport policy,
  diagnostic redaction, credential lifecycle, legacy migration, Admin authorization, Telescope
  remediation, and bounded health checks.
- The complete Email Feature and Unit directory passed 491 tests / 5,069 assertions at the stable
  order-6 runtime boundary. Historical-import and provider-deletion coverage also passed an explicit
  28-test / 273-assertion rerun.
- The complete Ticket module and workflow matrix passes 132 tests / 955 assertions. The affected
  Sales, Marketing, User Management, Customer Portal, and Commercial matrices add 116 passing tests.
- The strict Email-account Notification channel and password-reset path pass 10 focused tests / 111
  assertions plus 62 adjacent tests / 403 assertions. They never fall back to Laravel's system
  mailer and do not replay an ambiguous SMTP outcome.
- A disposable MariaDB 10.11 database passed the migrations `112000`-`117000` round-trip and guarded
  down-refusal contract with 1 test / 60 assertions. The generated database was validated, dropped,
  and its temporary server data removed; the shared Dev schema was not migrated.
- Targeted Pint passes 72 PHP files and syntax passes 67 production/test/migration files. Eighteen
  Email-provider routes and 15 Email-account/maintenance routes load; configuration cache round-trip,
  complete Blade compilation with zero non-group-writable files, Vite production build, and Git diff
  checks pass.
- Independent read-only security audit found no remaining order-6 P0/P1 code issue. Automated
  evidence does not authorize a provider call, account cutover, legacy-secret purge, or human review.

## Deploy And Rollback

- Deploy additive schema and code with every existing account still `source=legacy`. Do not run a
  broad migration while other Mail schema files are changing.
- After migrations and caches, stage exactly one account through read-only preview and staging. Only
  named human review may authorize its provider verification and cutover.
- Pause/drain that account's poll/send/provider work before cutover or rollback. Cutover changes only
  the account reference/source after exact verified-version and unresolved-operation checks.
- Roll back inside the declared window only while legacy ciphertext is intact and no later rotation,
  revocation, purge, or rebinding occurred. Prefer source rollback over destructive schema rollback.
- Do not purge legacy ciphertext until a later named review confirms backup/recovery evidence and
  closes the rollback window.

## Done Criteria

- [x] ADR and Feature Slice are accepted/active before implementation.
- [x] Migrations `112000`-`117000`, Integration models/services/actions/UI, Email binding/resolver, and
  staged migration workflow are implemented without live provider or shared Dev schema execution.
- [x] Every IMAP/SMTP and cross-domain runtime seam is source-strict, re-resolves at execution, and
  has no silent legacy or system-mailer fallback.
- [x] Endpoint policy, pinned transport, credential lifecycle, migration races, authorization,
  redaction, queue payloads, and affected-module behavior have focused automated coverage.
- [x] Narrow and affected cross-module tests, syntax, formatting, routes, compiled views, migration
  status/round-trip where safe, and diff checks pass on authoritative Dev.
- [x] README, Knowledge, TODO, index, permissions docs, and `HR-2026-08-16-006` are updated; human
  review remains Pending.
