# ADR: Email Hold, Export, Offboarding And Restore Boundaries

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADRs:
- `2026-08-11-email-owned-mail-client-domain.md`
- `2026-08-11-email-mailbox-access-and-rule-authority.md`
- `2026-08-16-integration-owned-email-provider-credentials-and-endpoint-security.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-lifecycle-hold-export-offboarding-restore.md`

## Context

Mail is a provider-authoritative operational cache with per-user state, local drafts, rules, search,
AI artifacts, remote-operation/outbound ambiguity and optional Ticket-owned captured evidence. It is
not automatically a permanent archive or legal hold. The accepted RFC nevertheless requires scoped
legal hold, honest DSAR/export, user/account offboarding and backup restore that cannot reactivate a
credential, socket, session, queued provider mutation or pending send.

A single broad "export/delete mailbox" action would mix domain ownership, expose other mailbox users
or recipients, erase Ticket evidence, lie about backup expiry and make provider/SMTP replay likely.
Durable lifecycle decisions and encrypted temporary artifacts need a stable boundary.

## Decision

Email owns lifecycle actions only for Email-owned source occurrences, placements, local content,
drafts, personal state, rules, search and AI projections. Ticket owns deliberately captured case
evidence; Integration owns provider credentials/connections; UserManagement owns user/session/token
lifecycle; Storage/backup operations own physical backup retention. A coordinating run may invoke
guarded domain actions, but no domain silently mutates another's records.

### Legal Hold

A legal/privacy operator creates an explicit preview with exact accounts/conversations/messages,
date window, content classes, authority/reason, effective/review/release dates and whether future
arrivals are included. Apply freezes exact source IDs/fingerprints and creates immutable hold targets.
Future-arrival holds are explicit versioned monitors over one authorized account/conversation scope,
not a hidden global retention switch.

Email hold preserves the scoped local source/content and named derived artifacts from ordinary purge.
It does not claim to prevent provider deletion, preserve uncached provider history or create Ticket
evidence. Ticket holds its captured copy independently. Release is explicit/audited and resumes
normal policy; it does not delete immediately or rewrite historical events.

### Privacy/DSAR Export

Exports are previewed, itemized and generated from current locally held/authorized evidence without
provider calls. Personal-owned mailbox content, actor-derived state and participation-based shared
correspondence are separate scope classes. Shared mailbox content is never included merely because a
user once had a grant; participant-based export requires an explicit legal/privacy basis and review
because it contains third-party correspondence.

Build a deterministic manifest plus safe source files/text/attachments/provenance where available.
Missing or non-authoritative raw evidence is labelled, never fabricated. Ticket/other-domain records
come from separate child exports under their own policy.

Artifacts are encrypted at rest with libsodium secretstream using a random data key wrapped by a
versioned Email-export key-encryption key held outside the database. Download decrypts only while
streaming through an authenticated, single-use, short-lived token; no unencrypted archive is written
to public/temp storage. Expiry deletes the wrapped key first, then the artifact through an auditable
cleanup. Ordinary logs/events contain no export content, filenames, addresses or download token.

### Offboarding

User/account offboarding is a previewed plan, not automatic deletion. The coordinating action first
revokes/ends sessions, grants/delegations/break-glass, presence and actor-bound access through their
own domains, then pauses provider runtime and cancels only work proven not to have reached provider
mutation. Accepted/unresolved sends, Draft APPENDs and remote operations enter reconciliation hold,
never retry/cancel-as-if-unsent.

Shared account content remains governed by account owners/managers. Personal mailbox disposition is
an explicit choice: transfer ownership under current authority, convert to shared, retain under a
defined policy/hold, or disconnect and later purge after review. Draft/personal-rule/AI-state transfer
is separately selected and defaults to no transfer. Credential revocation/cutover stays Integration-
owned and provider deletion is never inferred.

### Restore Quarantine

After a backup restore, all Email provider connections, poll/IDLE listeners, outbound submissions,
Draft APPENDs, remote operations, auto-replies, rule external actions and actor-bound jobs are
quarantined before normal workers start. Provider bindings/credentials require current Integration
verification; account folders require explicit re-baseline/reconciliation preview; search and AI
projections are stale/rebuilt; sessions/tokens/presence are invalidated by their owners.

No pre-backup pending/accepted/unresolved action is replayed. An operator resolves each ambiguity or
marks it superseded with evidence. Runtime resume is account-scoped, fingerprinted and audited only
after schema/storage/queue/provider readiness passes.

## Rationale

- Explicit domain ownership prevents an Email purge/export from becoming a Ticket, credential or
  user-lifecycle bypass.
- Exact frozen scopes and item ledgers make legal/privacy actions reviewable and restart-safe.
- Envelope-encrypted temporary artifacts avoid public/plaintext exports and support key rotation.
- Pausing and quarantining ambiguity before provider/runtime resume prevents duplicate external
  sends/mutations after offboarding or restore.
- Honest evidence/statuses avoid claiming provider deletion or backup erasure the system cannot prove.

## Alternatives Considered

- **Treat normal backups or provider mail as legal hold.** Rejected because neither supplies scoped
  authority, release audit or guaranteed retention/access.
- **One cross-domain delete/export transaction.** Rejected because domains have different ownership,
  authorization, retention and failure semantics.
- **Generate a normal ZIP in public/local temp.** Rejected because plaintext artifacts/tokens may leak
  through filesystem, backups, web server or logs.
- **Reuse the Laravel application key without key versioning.** Rejected because rotation/recovery and
  export-specific incident revocation would be unsafe.
- **Resume queues immediately after restore.** Rejected because stale pending work may duplicate
  provider mutations/sends.

## Consequences

Positive:

- Holds, exports, offboarding and restore have honest scopes, evidence and domain boundaries.
- Temporary exports are encrypted and revocable without storing download secrets in the database.
- Restoration cannot silently reconnect or replay old provider work.

Negative:

- Operations need versioned export keys, encrypted-artifact storage, cleanup workers, recovery
  procedures and cross-domain runbooks.
- Holds consume storage and require monitoring/review/release.
- Some requests require human legal/privacy choices rather than a one-click automated result.
- Backup systems still have their own expiry; Nexum must document rather than overstate deletion.

## Follow-Up

- Implement the linked order-23 Feature Slice and keep `HR-2026-08-16-023` Pending through real key,
  export, hold, offboarding and restore drills.
- Permanent provider deletion remains the separate order-24 gated action.
- Any external export/archive/ediscovery provider needs its own data-egress/integration ADR.
