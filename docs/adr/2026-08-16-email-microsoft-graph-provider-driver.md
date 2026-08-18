# ADR: Microsoft Graph Mail Provider Driver

Status: Accepted
Date: 2026-08-16
Decision owners: Integration / Email / Security Operations
Related RFC: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Feature Slice: `docs/feature-slices/2026-08-16-email-mail-microsoft-365-provider-driver.md`

## Context

The existing provider boundary is IMAP/SMTP-oriented. Microsoft 365 supplies mailbox content,
folders, drafts, send, mutations, incremental delta and webhook notifications through Microsoft
Graph, with OAuth identities and capabilities that do not map safely to a fabricated IMAP UID. Graph
message IDs can change on move unless every relevant request opts into immutable IDs. Notifications
are hints whose subscriptions expire or can report missed/removed/reauthorization lifecycle events;
delta remains the recovery truth.

Microsoft delegated and application permissions have materially different blast radius. Shared or
delegated mailbox notifications cannot safely be assumed from an ordinary delegated sharing scope;
application access must be explicitly constrained to approved mailboxes. A driver therefore requires
its own identity, scope, cursor and webhook contract rather than conditionals inside `ImapClient`.

## Decision

Add a provider-neutral Email driver interface with a Microsoft Graph implementation. Integration
owns Entra application registration references, OAuth consent/token versions, tenant/mailbox identity,
scope policy and revocation. Email owns normalized mailbox capabilities and operations. No access or
refresh token is stored on `email_accounts`, serialized into jobs, logged, broadcast or returned by
the Email UI/API.

The driver always requests `Prefer: IdType="ImmutableId"` for messages, drafts, delta and subscription
creation. Provider identity is `(connection binding version, tenant ID, mailbox immutable directory
identity, Graph immutable message ID)`; folder IDs remain provider container IDs. Internet Message-ID
and MIME hashes are correlation evidence, not the primary provider key.

Incremental truth uses paginated folder/message delta tokens. Webhook change/lifecycle notifications
contain no resource content and only enqueue a bounded delta catch-up after validating subscription,
tenant, client-state secret, route and current binding. Missed/removed/reauthorization events and
subscription expiry trigger renewal plus delta recovery. Scheduled delta remains mandatory even when
webhooks appear healthy.

## OAuth Modes And Least Privilege

Support two explicit connection modes rather than silent fallback:

- **Delegated mailbox:** current user consent with offline refresh; least scopes needed for the
  enabled capabilities, normally `Mail.ReadWrite`, separately `Mail.Send`, and OIDC/offline scopes.
  The resolved tenant/user/mailbox identity is immutable within a binding.
- **Application mailbox:** tenant-admin consent only for reviewed shared/system use. Exchange Online
  application access/RBAC must restrict the service principal to the exact approved mailbox cohort.
  Unrestricted tenant-wide application access fails readiness.

Capabilities are derived from verified consent plus policy, never from the selected mode label. Read-
only accounts do not request/send or write. Enabling Send or mailbox mutation requires a new verified
scope version and reviewed cutover. Personal Microsoft accounts are accepted only where the exact
delegated scopes/endpoints are supported and verified; no app-only emulation.

Endpoint hosts are fixed to Microsoft's approved Graph/identity allowlist and cloud environment.
National cloud selection is an explicit connection attribute; redirects cannot cross environments.
TLS, DNS/IP, deadlines, retry and secret telemetry follow order 6.

## Provider Semantics

- Enumerate the full folder hierarchy, roles and capabilities; do not collapse folders by display
  name. Delta/query pagination is bounded and cursors are opaque encrypted/private state.
- Project message flags/categories and folder placement through order 7. Provider `isRead` remains
  separate from Nexum `unread for me`.
- Read MIME/attachments only through exact authorized immutable message IDs and bounded streams.
- Draft create/update/send uses the Graph draft resource; the immutable draft message ID is retained,
  and the Sent copy is reconciled asynchronously because it may not be immediately readable.
- Move/copy/delete/flag/folder/send operations use the existing durable Email operation/submission
  ledgers with frozen binding/capability versions. Never translate uncertainty into success/retry.
- Change notification payloads are hints; current data is fetched through the driver after auth.

Permanent provider deletion is not implied by `Mail.ReadWrite`; it is exposed only as a separately
verified order-24 capability with its own reviewed exact API semantics. Calendar remains order 29.

## Migration And Rollback

Existing IMAP/SMTP and Graph projections may be shadowed for the same mailbox only during a bounded
cutover run. Preview proves mailbox ownership, folders, message identity/parity, drafts/Sent, current
operations and read/write/send capabilities. Apply creates a new provider binding generation and
re-baselines provider identity without duplicating canonical content or personal unread.

No dual writer exists. The old driver is paused/drained before Graph activation. Rollback is permitted
only while the old binding remains valid and no Graph-only provider mutation/submission/cursor
evidence would be discarded. Otherwise reconcile/complete or explicitly rebaseline; never copy Graph
IDs into IMAP UID columns or resume queued old-driver work.

## Security And Webhooks

Webhook endpoints perform validation-token handshake exactly as documented, cap request bytes/count,
validate client state in constant time and acknowledge quickly before queueing IDs. They reveal no
mailbox existence, accept no user session and never fetch content synchronously. Lifecycle and change
routes use separate rate limits/audit. A rotated/revoked binding invalidates subscriptions and queued
hints immediately.

OAuth state/PKCE/nonce and callback session are single-use, actor/provider/connection bound and short
lived. Admin consent and application mailbox scope require dedicated permissions and typed preview.
Telescope/log/request/query/model telemetry uses the credential redaction boundary from order 6.

## Consequences

- Microsoft Graph becomes a first-class driver, not an IMAP compatibility flag.
- Immutable IDs and delta tokens are mandatory driver state.
- Webhooks improve latency but never replace scheduled reconciliation.
- Application permissions remain a separately reviewed, mailbox-restricted high-risk mode.
- The driver can coexist with the provider-neutral Mail UI/API without exposing provider tokens/IDs.

## References

- [Microsoft Graph immutable Outlook IDs](https://learn.microsoft.com/en-us/graph/outlook-immutable-id)
- [Message delta query](https://learn.microsoft.com/en-us/graph/api/message-delta?view=graph-rest-1.0)
- [Outlook change notifications](https://learn.microsoft.com/en-us/graph/outlook-change-notifications-overview)
- [Change-notification lifecycle events](https://learn.microsoft.com/en-us/graph/change-notifications-lifecycle-events)
- [Microsoft Graph permissions](https://learn.microsoft.com/en-us/graph/permissions-reference)

## Verification

Provider-contract fakes plus a controlled expendable Microsoft 365 mailbox must prove delegated and
restricted application modes, immutable identity across move, folder/message delta, notification loss
and lifecycle recovery, drafts/Sent, every mutation, throttling/deadlines, token rotation/revocation,
migration/rollback and absence of content/credential leakage before the driver is enabled.
