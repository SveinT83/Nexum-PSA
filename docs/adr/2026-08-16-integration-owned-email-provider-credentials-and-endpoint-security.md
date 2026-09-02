# ADR: Integration-Owned Email Provider Credentials And Endpoint Security

Status: Superseded
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Superseded by: `2026-09-01-email-account-owned-imap-smtp-configuration.md`

> Historical decision only. It must not be used to design, configure, or operate Mail. Email now
> owns password-based IMAP/SMTP settings and credentials on each account. The provider-first UI,
> staging, credential-version, migration-preview, cutover, and provider-owned runtime described
> below are retired and disabled.
Related ADR: `2026-08-11-email-owned-mail-client-domain.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-integration-provider-credentials-endpoint-security.md`

## Context

Email currently stores encrypted IMAP and SMTP usernames and secrets directly on
`email_accounts`. Runtime callers decrypt those fields independently, provider hosts are accepted as
ordinary account form values, and some cross-domain send paths can fall back to unrelated system
mailer configuration. This is the legacy compatibility path acknowledged by the approved Mail RFC
and Email-ownership ADR, not the finished provider boundary.

The finished client needs reusable Integration-owned connection and credential lifecycle records,
one strict provider endpoint policy, safe staged migration, and execution-time credential resolution.
The boundary must prevent secrets and private network targets from leaking through queues, logs,
sessions, audit, errors, or generic Integration controls. It must also preserve the existing Email
account as the owner of mailbox behavior, authorization, state, and provider operations.

## Decision

### Ownership And Binding

Integration owns one `integrations` root with `type=email_provider` for each provider connection. A
one-to-one `integration_email_provider_connections` record owns normalized IMAP/SMTP endpoints,
transport policy, endpoint trust classification, configuration version, and the versions last
verified successfully. Separate `integration_email_provider_credential_versions` rows own encrypted
usernames and secrets.

Email keeps `email_accounts` as the mailbox-domain record and references an Integration provider
connection through `provider_integration_id`. The source is explicit:

- `legacy` resolves only the existing encrypted Email-account fields;
- `integration` resolves only the bound active, verified Integration credential and connection;
- there is no implicit preference, merge, or fallback between the two sources.

Changing an endpoint or username creates a new connection/binding and requires mailbox
re-baselining. A secret-only rotation creates another credential version on the same connection.
Integration connection administration never grants mailbox content access.

### Endpoint And Transport Security Floor

Provider endpoints are stored as normalized hostnames or IP literals, never as URLs. Parsing rejects
schemes, paths, query/fragment/user-info syntax, control characters, wildcards, IPv6 zone IDs, and
non-canonical values. Internationalized hostnames are converted to normalized ASCII before storage
and comparison.

The standard transport matrix is fixed:

| Protocol | Port | Required transport |
| --- | ---: | --- |
| IMAP | 993 | Implicit TLS |
| IMAP | 143 | STARTTLS, required before authentication |
| SMTP | 465 | Implicit TLS |
| SMTP | 587 | STARTTLS, required before authentication |

Any other port requires a named installation allowlist entry. Certificate and hostname verification
remain enabled, self-signed certificates are rejected, TLS 1.2 is the minimum, and there is no
opportunistic downgrade. IMAP must use a real STARTTLS path on port 143. SMTP requires TLS before
authentication on port 587.

Every new network connection resolves the original normalized host under bounded DNS answer and
CNAME limits. The complete answer set is evaluated; a mixed allowed/denied set is denied. Public
connections reject private, loopback, link-local, metadata, unspecified, multicast, documentation,
benchmark, reserved, and carrier-grade NAT ranges. An intentionally private target requires the
distinct `integration.email_private_endpoint_manage` permission, a reason, and membership in a named
installation-controlled CIDR allowlist. Always-denied ranges remain denied in that mode.

The approved address is pinned for the socket connection. TLS SNI and peer-name verification retain
the original hostname, so DNS cannot be changed between authorization and connection and pinning
cannot disable hostname validation. Redirects and provider-controlled alternate endpoints are not
followed implicitly.

### Credential Lifecycle

Credential versions use a monotonic per-connection version and one of these states:
`staged`, `active`, `retired`, `revoked`, or `destroyed`.

- Create and rotate write only a staged encrypted version.
- `Verify` is the only lifecycle action that may call the provider. It uses the exact connection and
  credential versions being verified and stores only sanitized success/failure state.
- Activation locks the connection and exact verified version, activates it, retires the previous
  active row, and destroys the previous ciphertext.
- Revocation destroys ciphertext for a staged or active version and prevents later runtime use. It
  records local revocation only; Nexum does not claim the credential was revoked at the provider.
- Destroyed ciphertext is not recoverable through the application. Historical lifecycle and event
  rows remain append-only and secret-free.

Runtime services receive an immutable, redacted, non-serializable DTO. They re-resolve it when a
queued job handles rather than carrying a secret or decrypted endpoint identity in the payload.
Dispatch followed by revocation therefore fails closed. Secret rotation may let already queued work
use the current active version only when the account binding and endpoint configuration are
unchanged; endpoint or binding changes make that work stale.

### Legacy Migration

Legacy migration is an explicit, durable workflow with run and item records:

1. `Preview` is read-only and records exact authorized account scope and legacy fingerprints.
2. `Stage` locks each source account, verifies that fingerprint, decrypts the legacy values only in
   process, and re-encrypts them into a staged Integration connection/version. It performs no DNS,
   provider call, deduplication, mailbox mutation, or source switch.
3. `Verify` is a separate explicit provider operation under the endpoint security policy.
4. Cutover preview proves the exact verified configuration/credential versions, account readiness,
   and absence of unresolved provider operations.
5. Cutover apply changes only the Email-account source and provider reference under lock. It does not
   call a provider or change mailbox state.
6. Rollback is available only inside the declared window after sync/send work is paused and drained,
   while legacy ciphertext is intact and no later rotation, revocation, purge, or binding change has
   occurred.
7. Legacy secret purge is not part of this slice's automatic rollout. It requires a later named human
   review, verified backup/recovery evidence, and a separately explicit operation.

The migration never silently combines connections merely because hosts or usernames appear equal.
It is retry-safe, bounded, and fail-closed on stale/corrupt source data.

### Authorization, Audit, And Presentation

`integration.email_provider_manage` is seeded for Admin and Superuser.
`integration.email_private_endpoint_manage` is seeded for Superuser only.

Provider preview, stage, verification, cutover, and rollback also require
`email.mailbox_sync_manage`. Binding or rebinding an Email account additionally requires
`email.account_manage`. Private endpoint approval requires the distinct private-endpoint permission
and reason.

`integration_email_provider_events` is append-only and stores actor, safe event/reason code, version
references, and timestamps. It never stores hostname, resolved address, username, ciphertext,
plaintext, provider response, or raw error. The Integration UI has a dedicated multi-record Email
Providers surface. The generic Integration enable/disable control cannot mutate these connections.
Email account pages may show only provider label, readiness, source, and capabilities, not endpoint
or credential values. No public API is introduced for provider credentials.

### Runtime Boundary

Every IMAP/SMTP call path resolves through the same provider-runtime boundary, including mailbox
polling, health, reconciliation, remote operations, drafts, Sent append, composer readiness, default
account selection, and cross-domain Email send callers. Commercial and other callers cannot decrypt
Email-account secrets directly or fall back silently to the system mailer. An unavailable or revoked
bound provider produces an honest blocked/failed state before network authentication or send.

## Rationale

- One Integration owner prevents each Email or cross-domain caller from inventing credential,
  rotation, and endpoint rules.
- Exact source selection and staged migration preserve a real rollback boundary and expose partial
  rollout honestly.
- IP pinning plus retained TLS peer verification closes DNS rebinding without weakening certificate
  validation.
- Separate configuration and credential versions make queued-work freshness and rotation behavior
  deterministic.
- Destroying superseded ciphertext limits exposure while retaining secret-free lifecycle evidence.
- A dedicated multi-record UI reflects real provider connections and avoids overloading the generic
  one-toggle Integration model.

## Consequences

Positive:

- Email provider secrets gain explicit version, verification, rotation, revocation, and destruction
  semantics.
- Public and approved-private endpoints use one auditable SSRF and TLS floor.
- Jobs and cross-domain senders can no longer serialize credentials or bypass the Email provider
  boundary.
- Legacy accounts can move independently with preview, verification, cutover, and rollback.

Negative:

- Provider calls require a resolved/pinned transport adapter rather than passing a hostname directly
  to third-party libraries.
- Operations must stage and verify credentials before activation or migration cutover.
- Compatibility code remains until every account passes migration and a later human-reviewed purge.
- Endpoint or username changes are deliberately heavier because they require a new binding and
  mailbox re-baseline.

## Alternatives Considered

- **Keep credentials encrypted on `email_accounts`.** Rejected because lifecycle, endpoint policy,
  OAuth/provider expansion, and shared governance would remain duplicated inside Email.
- **Copy credentials in one migration and switch every account.** Rejected because migrations cannot
  verify providers, silent moves hide failures, and there would be no safe per-account rollback.
- **Let runtime prefer Integration and fall back to legacy.** Rejected because failures or revoked
  credentials could silently use an unintended identity.
- **Resolve a host once at save time.** Rejected because DNS answers change and authorization must
  bind every connection attempt.
- **Permit any private target for Superuser.** Rejected because broad private-network access would
  turn mail configuration into an SSRF primitive; approved CIDRs, reason, and always-denied ranges
  remain mandatory.
- **Store credentials in queue payloads for consistency.** Rejected because revocation must take
  effect before execution and serialized secrets create additional disclosure surfaces.

## Follow-Up

- Implement the related Feature Slice in stages 6A-6C with additive migrations `112000`-`117000`.
- Keep new Integration connections dormant until explicit verification and Email-account cutover.
- Complete provider-library STARTTLS/IP-pinning integration and all runtime call-site regressions
  before allowing one account to use `source=integration`.
- Run one controlled Dev account through preview, stage, verify, cutover, health, poll, send, and
  rollback only during named human review `HR-2026-08-16-006`.
- Define the later legacy-ciphertext purge operation only after backup, recovery, and rollback-window
  evidence is explicitly approved.
