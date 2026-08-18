# ADR: Google Gmail API Mail Provider Driver

Status: Accepted
Date: 2026-08-16
Decision owners: Integration / Email / Security Operations
Related RFC: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Feature Slice: `docs/feature-slices/2026-08-16-email-mail-google-workspace-provider-driver.md`

## Context

Gmail's provider model is not a folder/UID mailbox. A message has a stable Gmail message/thread
identity and a set of system/user labels; one message can be visible in several label views. Drafts
have a stable draft container while replacing their contained message changes its message ID, and
sending returns a new Sent message. Incremental state is a mailbox `historyId`; an expired history
cursor returns HTTP 404 and requires explicit full synchronization.

Gmail push is a Google Cloud Pub/Sub hint whose watch expires and must be renewed. It contains only a
mailbox address/history ID and cannot replace `history.list` plus a scheduled fallback. Gmail scopes
are sensitive/restricted; broad `https://mail.google.com/` additionally enables permanent delete and
must not be requested merely because Nexum supports normal Mail operations.

## Decision

Add a provider-neutral Email driver interface with a Gmail API implementation. Integration owns
Google OAuth client/consent/token versions, Workspace/customer identity, optional domain-wide
delegation policy, Cloud Pub/Sub registration and revocation. Email owns normalized message/label/
draft/send and operation semantics. Tokens, client secrets, service-account keys, raw subscription
claims and mailbox addresses never appear in Email rows/jobs/logs/UI/API.

Provider identity is `(connection binding version, Google subject/customer identity, Gmail message
ID)` with separate Gmail thread ID and draft container ID. Labels are many-to-many provider
placements/classifications, not fabricated IMAP folders or UIDs. System labels map to normalized Mail
roles/capabilities; user labels retain immutable provider label ID separately from display name.

Incremental truth uses bounded `history.list` from a durable cursor and then exact message/label
reads. Pub/Sub push only coalesces a catch-up job after authenticating the Google-signed/OIDC push,
expected audience/project/topic/subscription and current binding. A missing/expired history cursor,
watch gap or 404 becomes an explicit bounded rebaseline run; it never advances to the notification's
history ID without applying the intervening state.

## OAuth Modes And Least Privilege

Support explicit modes:

- **Delegated user OAuth:** authorization-code + PKCE/offline refresh for the exact Google subject and
  mailbox. Request only the scopes for enabled capabilities; the full-client default is
  `gmail.modify` where its reviewed semantics suffice, with separate consent for any additional
  capability.
- **Workspace domain-wide delegation:** optional high-risk mode for reviewed shared/system mailboxes.
  It requires dedicated permission, Workspace administrator approval, exact customer/domain/service-
  account/subject allowlist and testable restriction to the configured mailbox cohort. Arbitrary
  subject impersonation or tenant-wide discovery is prohibited.

Google restricted-scope verification/security-assessment readiness is an operational activation
gate. Scope additions/reductions, subject/customer/service-account identity changes create a new
binding and migration/rebaseline. No fallback from revoked OAuth to stored IMAP credentials.

Do not request `https://mail.google.com/` in this driver baseline. Permanent delete remains order 24
and is unavailable until a separate scope expansion, capability review and human approval proves the
exact Gmail API behavior. Calendar scopes remain order 29.

## Label, Draft And Send Semantics

- Project each Gmail message once canonically and maintain its exact label set as provider state.
  Adding/removing a label never duplicates content or changes Nexum personal unread.
- Map `INBOX`, `SENT`, `DRAFT`, `TRASH`, `SPAM`, `UNREAD`, `STARRED` and other supported system labels
  explicitly. User-label hierarchy/display is presentation only; identity is label ID.
- Draft operations use the stable draft ID and current contained message ID/version. Update replaces
  the contained message; stale editors cannot overwrite current provider/draft-lock state.
- Send through `drafts.send`/reviewed exact API under the unified outbound boundary. Reconcile the new
  returned/observed Sent message ID and never assume the old draft message ID survived.
- Modify/trash/untrash/labels use exact message IDs and durable operation ledger. Thread-wide modify is
  not substituted for a message-scoped UI action because later messages and labels can differ.

## Push And Scheduled Recovery

The watch covers the whole authorized mailbox unless a reviewed label filter preserves complete
semantics. Renew at least daily and before the returned expiration. The Pub/Sub push route caps bytes,
validates OIDC issuer/signature/audience/project/topic/subscription, rejects replay as harmless and
returns quickly after durable enqueue. It fetches no Mail content synchronously and reveals no account
existence.

Duplicate, reordered or missing history IDs coalesce into a single current-cursor catch-up. Scheduled
history reconciliation remains mandatory and detects watch expiry/outage. On history 404/invalid
cursor, pause negative provider conclusions and start a bounded full-message/label rebaseline with
stable start/end history evidence.

## Migration And Rollback

An existing IMAP Gmail account may move to the API driver only through shadow preview and whole-
account parity across stable Gmail IDs/Message-ID/MIME/labels/drafts/Sent. Apply drains the old writer,
creates a new provider binding and maps UID placements to Gmail message/label identity without
duplicating canonical content or personal unread.

No dual writer exists. Rollback requires a still-current old binding and no Gmail-only write/send/
history evidence that would be discarded. Otherwise reconcile/rebaseline first. Gmail labels are not
written into IMAP folder/UID identity during rollback; any IMAP compatibility view is provider-owned
evidence, not the API driver's source of truth.

## Security Consequences

Google API/identity/Pub/Sub hosts are fixed allowlists under order 6. All requests have bounded
connect/read/overall deadlines, pages/bytes and quota/retry budgets. `Retry-After`/quota errors delay
without leaking response data or unbounded retry. OAuth/service-account/Pub/Sub material is encrypted,
redacted from Telescope and accessible only inside the provider runtime lock.

Domain-wide delegation and Pub/Sub are durable external dependencies and therefore explicitly
accepted only in this provider ADR. Installations that do not configure them use delegated OAuth plus
scheduled history polling; no hidden cloud resource is created.

## Rejected Alternatives

- Treat labels as IMAP folders/duplicate messages or invent UIDVALIDITY.
- Advance directly to a pushed history ID without applying `history.list`.
- Use Pub/Sub delivery as provider truth or omit scheduled recovery.
- Request the full-mail scope by default for permanent delete convenience.
- Treat a draft's contained message ID as stable across update/send.
- Enable unrestricted domain-wide impersonation.

## References

- [Gmail API reference](https://developers.google.com/workspace/gmail/api/reference/rest)
- [Synchronize Gmail clients](https://developers.google.com/workspace/gmail/api/guides/sync)
- [Gmail push notifications](https://developers.google.com/workspace/gmail/api/guides/push)
- [Gmail OAuth scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)
- [Gmail drafts](https://developers.google.com/workspace/gmail/api/guides/drafts)
- [Gmail labels](https://developers.google.com/workspace/gmail/api/guides/labels)

## Verification

Provider fakes plus an expendable Google mailbox must prove OAuth/domain-delegation policy, stable
message and replaceable draft identity, complete history/label projection, watch loss/renewal/404
rebaseline, send/Sent and every mutation, throttling/deadlines, migration/rollback, and zero credential/
content leakage before activation.
