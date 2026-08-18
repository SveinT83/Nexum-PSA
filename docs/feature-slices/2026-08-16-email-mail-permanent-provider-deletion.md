# Feature Slice: Separately Authorized Permanent Provider Deletion

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Related ADRs:
- `docs/adr/2026-08-11-email-owned-mail-client-domain.md`
- `docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
- `docs/adr/2026-08-16-integration-owned-email-provider-credentials-and-endpoint-security.md`
Owners: Email / Integration / Ticket
Human Review: `HR-2026-08-16-024`

## Goal

Add an irreversible provider-side delete only as a separately permissioned, capability-aware,
previewed and approved operation over exact Trash placements. It is disabled installation-wide and
per account by default, has no unsafe IMAP `EXPUNGE` fallback, respects legal hold/retention/Ticket
evidence and treats an uncertain provider response as unresolved rather than retryable failure.

## Dependencies And Existing Boundary

The 2026-08-16 instruction authorizes implementation of this guarded slice only. Permanent deletion
remains installation-wide and per-account disabled after deploy until its complete capability,
retention/hold/Ticket, two-person approval, controlled-provider, and named human-review gates pass.
No migration, seed, scheduler, retry, or ordinary Trash action enables it.

Orders 6-7, 13-15 and 21-23 must be stable: current provider binding/capabilities, complete
provider-originated reconciliation, Ticket relationship/capture evidence, quarantine and lifecycle/
hold policy. Existing `Trash` remains the normal reversible action. This slice does not change that
default and never converts retention cleanup into provider deletion.

Before implementation, inventory every provider driver and exact permanent-delete capability. For
standard IMAP, enable only when the server advertises UIDPLUS and the implementation can perform
exact UID `STORE \\Deleted` plus `UID EXPUNGE` in the verified folder/UIDVALIDITY. Never issue a plain
folder-wide `EXPUNGE`, emulate exact deletion by deleting other messages, or guess Gmail/Microsoft
label semantics. Provider-specific API deletion waits for orders 27-28 capability mapping.

## Layered Enablement And Permission

All must be true before a control/action exists:

- installation security setting `permanent_provider_delete_enabled`, default `false`;
- account-specific opt-in by an authorized provider/account manager, default `false`;
- verified current provider binding and exact advertised safe capability;
- dedicated `email.provider_permanent_delete` permission plus ordinary Mail View and Organize;
- current lifecycle/retention/hold/Ticket and provider-operation readiness; and
- an unexpired exact preview/approval.

Do not assign the dedicated permission to broad Technician/Admin roles by default. Break-glass,
generic account management, Mail Send, a rule/AI/system actor or API `email.update` cannot delete.

Personal owner may approve an eligible personal-message run where policy allows. Shared/system
accounts require a second distinct active approver with the same permission and current mailbox
authority. Ticket-linked/captured evidence or elevated lifecycle policy may require a separate Ticket/
legal approver. One actor cannot satisfy two-person approval through multiple sessions/tokens.

## Additive Data Model

Reserve migrations after order 23, currently `2026_08_16_160000` and `161000`.

Add `email_permanent_delete_runs`:

- opaque UUID, account, requester/approver(s), reason and typed confirmation policy;
- exact sorted placement scope, provider binding/config/capability/folder/UIDVALIDITY and lifecycle
  fingerprint, preview counts, expiry, idempotency and status;
- statuses `previewed`, `approval_pending`, `approved`, `queued`, `running`, `completed`,
  `completed_with_unresolved`, `blocked`, `stale`, `cancelled` or `failed_pre_write`;
- requested/approved/queued/started/finished timestamps and sanitized safe reason/error.

Add `email_permanent_delete_items`:

- run, exact source message/placement/account/folder/namespace/UID, provider-binding version;
- before state/hash, hold/retention/Ticket/canonical/reconciliation/remote-operation findings;
- state `eligible`, `blocked`, `claimed`, `provider_write_started`, `unresolved`,
  `confirmed_deleted`, `reappeared`, `stale`, `cancelled` or `failed_pre_write`;
- tokenized lease, immutable provider operation/idempotency identity, attempts and confirmation
  evidence/timestamps; unique run+placement and one active delete identity per placement.

Add append-only delete events/approvals. Store IDs, hashes, capabilities and safe codes only, never
subject/sender/body/filename/Message-ID/private path/provider response/credential. Down refuses while
any run/item/event or tombstone references the feature. Migration deletes/calls nothing.

## Preview And Eligibility

Preview accepts explicit selected active Trash placements only, default one/hard 25. It locks/reads
current local evidence without provider write and reports:

- account/folder and message count/size/date using only fields the actor may view;
- provider binding/capability and exact Trash-role/UID namespace;
- legal hold/retention/offboarding/restore/quarantine/search/Ticket capture/relationship state;
- unresolved remote operations, provider reconciliation, Draft/Sent/outbound ambiguity;
- whether Ticket evidence will remain and which separate domain approval is required; and
- irreversible effects, no undo and provider-specific scope caveats.

Only an active exact provider Trash placement is eligible. Inbox/Archive/custom folder must use the
normal Trash operation, reconcile, then start a new preview. A missing/untrusted Trash role, reused/
changed UIDVALIDITY, provider binding drift, canonical/source mismatch, incomplete reconciliation,
unresolved operation or restore/offboarding quarantine blocks.

Active legal hold always blocks. Retention minimum/blocking policy blocks. A Ticket relationship
without an exact durable capture blocks when its evidence would be lost. A proven captured
Ticket-owned copy may permit deletion only after explicit preview/required Ticket approval; the
Ticket copy and audience remain unchanged and do not grant Mail authority.

## Approval And Confirmation

Preview expires after five minutes. Requester types a confirmation containing the account label and
exact item count; sensitive message content is not used as the phrase. Approval binds actor/session,
run/fingerprint, scope, provider capability/binding, policy and expiry. Any drift invalidates all
approvals.

API requires a dedicated destructive ability, exact account binding, the same preview and approval
tokens, strict rate limits and cannot skip two-person/Ticket/legal approval. Reusing idempotency with
a different scope conflicts. A UI/API control disappears when current availability is false.

## Provider Execution

One Email queue job processes one item under the shared account provider lock:

1. lock/reload run/item/account/placement/folder/namespace and reauthorize policies/approvals;
2. re-resolve exact provider binding/capability and current UIDVALIDITY;
3. PEEK/search the exact UID and verify immutable Message-ID/identity where available;
4. persist `provider_write_started` before sending the exact delete command;
5. issue only the provider driver's declared exact-delete primitive;
6. verify exact absence/current stable folder namespace when a response is conclusive; and
7. persist confirmed outcome/tombstone and dispatch local lifecycle cleanup after commit.

Connection/auth/read/policy failure before step 4 is safe pre-write failure and may be retried under
bounds. Any exception/timeout/lost response after step 4 is `unresolved`; it has no automatic or
manual replay command. Order-7 reconciliation/explicit evidence checks can later confirm deletion or
reappearance. A losing worker cannot overwrite confirmed/unresolved/cancelled state.

Cancellation is allowed only before any item reaches `provider_write_started`. The operation has no
undo. A multi-item run reports each exact terminal/unresolved state and never claims all succeeded
because one did.

## Local Projection And Lifecycle

After provider-confirmed absence and stable reconciliation, retire the exact placement with a
permanent-delete reason/tombstone. Do not claim other provider labels/folders/copies disappeared
unless the driver proves their scope. Canonical sources/other placements remain according to actual
evidence.

Invoke order-23 lifecycle cleanup for unheld/unretained Email cache, raw, attachments, search, AI and
derived files with safe inventory/parity. Preserve minimal deletion provenance and separately
captured Ticket evidence. Never delete an unreferenced file merely by age/path, and never delete
Ticket content from Email cleanup.

Private invalidation publishes opaque projection versions. Notifications/audit use safe IDs/status;
no content. Provider deletion never marks another user's personal state or pretends a Ticket is
resolved/closed.

## Bounds And Operations

- Default one, hard 25 items; no folder/account/select-all bulk default and cap-plus-one denial.
- One provider write item per job, bounded connection/read/write/overall deadlines and tokenized
  claims. No unbounded retry.
- Account health shows pending approvals, unresolved deletes, confirmed backlog and capability
  status without content.
- Emergency stop disables new previews/claims but cannot cancel/replay an item after provider write.
- Scheduled reconciliation may resolve uncertainty read-only; it never dispatches deletion.

## Out Of Scope

- Empty Trash, bulk purge, plain IMAP EXPUNGE, provider retention-policy management, automatic rule/
  AI delete, deleting outside Trash, undo, legal-hold override, or Gmail/Microsoft semantics before
  their reviewed driver capabilities.
- Treating local cache cleanup or Ticket deletion as provider authority.

## Tests

- Layered default-off/account/permission/capability availability; personal/shared/system,
  two-person/Ticket/legal approvals, break-glass/rule/AI/system/API denial.
- Exact Trash role/placement/folder/UIDVALIDITY/binding/identity, Inbox/custom denial, provider without
  UIDPLUS, and assertion that plain EXPUNGE/other-UID deletion is never issued.
- Hold/retention/restore/offboarding/quarantine, uncaptured/captured Ticket evidence, canonical other
  placement, unresolved operation/reconciliation/Draft/Sent/outbound blockers.
- Preview/typed phrase/expiry/drift/rate/idempotency/different scope, approval revocation and
  non-enumerating UI/API.
- Pre-write connect/read failure retry, exact success, response-loss unresolved/no replay,
  reconciliation confirmed/reappeared, redelivery/concurrency/losing worker and partial multi-item.
- Confirmed local retirement/search/AI/raw/attachment cleanup versus hold/Ticket preservation,
  no orphan deletion and honest provider-specific scope.
- Sanitized rows/logs/notifications/private invalidations, account health, emergency stop, workers/
  scheduler and no personal read/Ticket/portal/rule/send side effects.
- Migration/down guards, provider fake/capability contract, route/cache/view and affected
  Email/Integration/Ticket/Storage tests.

## Documentation And Operations

Update Email/Integration/Ticket/Storage Knowledge, destructive-operation/incident/reconciliation
runbooks, API/OpenAPI, TODO, completion index and `docs/human-review.md`. Deploy additive schema/
permissions with `umask 0002`, keep both enablement layers off, clear caches/rebuild group-writable
views and restart workers. No migration/deploy deletes. First Dev test uses an isolated expendable
mailbox/message after provider capability and retention/Ticket/hold evidence are reviewed.

`HR-2026-08-16-024` remains Pending and the capability stays gated off until a named reviewer checks
real provider exact-delete/no-plain-EXPUNGE, approvals, every blocker, lost-response reconciliation,
local/Ticket lifecycle, UI/API, queues and sanitized audit.

## Done Criteria

- [ ] Permanent delete remains doubly default-off, separately permissioned/approved and available
  only for exact capability-proven Trash placements.
- [ ] Provider execution has one durable write boundary, no unsafe EXPUNGE or replay after uncertainty,
  and read-only reconciliation resolves outcomes honestly.
- [ ] Holds/retention/Ticket/canonical/lifecycle evidence is preserved or explicitly approved; local
  cleanup never becomes cross-domain/provider authority.
- [ ] Tests, migrations, permissions, UI/API, docs/runbooks and `HR-2026-08-16-024` are complete while
  named human review and runtime enablement remain Pending/gated off.
