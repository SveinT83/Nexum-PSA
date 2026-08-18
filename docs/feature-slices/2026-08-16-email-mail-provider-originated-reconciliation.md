# Feature Slice: Email Mail Provider-Originated Reconciliation

Status: Done / Human Review And Dev Operations Pending
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-007`

## Goal

Complete the read-only provider-to-Nexum synchronization leg for every active, enabled mailbox
folder. Provider folder existence, placements, message flags, moves, copies, and expunges remain
provider-authoritative. Nexum personal unread, opened receipts, rules, PSA links, collaboration state,
and audited local workflows remain independent Nexum facts.

The reconciliation runtime must be resumable, bounded, observable, and safe after missed IDLE hints,
worker restarts, large mailboxes, partial provider responses, and ambiguous local operations. This
slice performs no provider mutation.

## Dependencies

Implementation was built against the final code contracts from both prerequisite completion slices:

1. canonical message/placement cutover (`HR-2026-08-16-005`), because imports and placement reads
   use its final self-map and placement-scoped projection contract;
2. Integration-owned provider credentials and endpoint security (`HR-2026-08-16-006`), because every
   queued provider read resolves the current active credential and pinned secure endpoint at
   execution time.

Their human reviews and shared Dev migrations remain independent pending gates. The implementation
re-read and integrated the final `EmailAccount`, provider-runtime, `ImapClient`, polling,
`StoreInboundMessage`, folder/placement, scheduler, and mailbox-maintenance contracts. Order 7 does
not claim that the order-5 or order-6 operational review has passed.

## Authority And Read-Only Boundary

- Provider state wins for provider folders, placement existence, and provider flags only after a
  complete stable reconciliation cycle.
- Provider `Seen` never changes **Unread for me**, opened-by receipts, or another user's state.
- Reconciliation never sends, appends, moves, copies, deletes, flags, creates/renames/deletes folders,
  or retries a provider mutation.
- Canonical message identity alone is neither authorization nor move evidence. Every decision remains
  account-, placement-, active UID-namespace-, and exact provider-ID scoped.
- Ticket, Signal, Notification, AI, Smart Inbox, and personal rules receive no implicit side effects
  from historical or ambiguous observations.

Inbound storage has separate authority switches:

- `run_inbound_rules`, controlling local rule evaluation; and
- `allow_provider_mutation`, controlling any provider write.

Every reconciliation import sets `allow_provider_mutation=false`. A new live Inbox occurrence is
born hidden and does not run rules until its artifacts, canonical self-map, exact placement, stable
account-wide provider evidence, and new-delivery classification have all passed. A provider move,
copy, duplicate, weak identity, or ambiguous global scope suppresses or fails automation without
creating Ticket, Signal, AI, rule, or external-notification side effects.

## Additive Data Model

The additive implementation uses migrations `118000` through `118400`. It does not reuse the
immutable provider-deletion inventory tables as a mutable/resumable cursor.

### `email_provider_reconciliation_runs`

- account and nullable requester IDs, provider and trigger (`scheduled`, `idle`, `manual`, `catchup`);
- status (`queued`, `running`, `waiting_for_imports`, `completed`,
  `completed_with_conflicts`, `partial`, `stale`, `blocked`, `failed`, `cancelling`, `cancelled`);
- phase (`discover_start`, `discover_local`, `scan`, `imports`, `finalize`, `discover_end`,
  `summary`);
- unique idempotency key, start/end folder-scope hashes, configured folder/UID/time caps;
- frozen Integration provider binding/runtime evidence and one-account active-slot ownership;
- bounded local-folder discovery cursor/hash/count, cancellation intent, and a monotonic
  automation-scope-unsafe attestation;
- bounded folder/item final-summary high-water marks, cursors, counters, and seal state;
- folder, batch, observed, import, flag-change, missing, move, conflict, and error counts;
- start, progress, retry, and finish timestamps plus a sanitized failure code.

### `email_provider_reconciliation_folders`

- run, account, nullable local-folder, and active UID-namespace IDs;
- bounded folder path as operational metadata;
- discovery state (`existing`, `new_after_baseline`, `local_only`);
- status (`pending`, `scanning`, `waiting_for_imports`, `complete`, `missing_candidate`,
  `missing_confirmed`, `stale`, `blocked`, `failed`, `cancelled`);
- expected, start, and end UIDVALIDITY; start/end UIDNEXT, EXISTS, and HIGHESTMODSEQ;
- frozen `scan_through_uid`, resumable `next_uid`, rolling inventory hash and counters;
- bounded placement-baseline/end/projection snapshots, post-import NOMODSEQ verification state, and
  a bounded sealed item-summary cursor;
- safe reason code and progress timestamps;
- byte-exact unique run plus folder path.

### `email_provider_reconciliation_items`

Persist only imports, deviations, and durable observations needed for projection or recovery, not
one row for every unchanged known UID:

- run, folder, namespace, and UID;
- kind (`observation`, `import`, `move_candidate`, `absence_candidate`, `operation_conflict`);
- status (`pending`, `running`, `waiting_for_baseline`, `projected`, `already_present`, `confirmed_move`,
  `confirmed_missing`, `conflict`, `stale`, `failed`, `cancelled`);
- nullable source, target, and result placement IDs;
- frozen provider flags/custom-keyword evidence, placement versions, conservative identity hash,
  attempts, safe error and timestamps;
- bounded historical viewer-baseline and live-Inbox automation substates, claims, cursors, and
  terminal reason codes;
- unique run/folder/namespace/UID/kind;
- optional link to the existing immutable provider-placement finding.

### Placement observation

Placements carry nullable `last_provider_reconciliation_run_id`, observed sync version, frozen strong
identity hash, and observation timestamp. Reconciliation-created occurrences are committed as
hidden/store-pending before file persistence and become active only after artifact/canonical/scope
attestation. Exact hidden reconciliation-pending crash rows may be resumed and repaired, but every
access path still requires an active, non-missing placement. Unrelated PREEXISTING active
occurrences are create-only/immutable at the Store boundary. Do not overwrite immutable remote-
operation or provider-deletion evidence.

### Inbound external-delivery outbox

`notification_inbound_external_deliveries` is a payload-free, one-row-per-canonical-notification
outbox for requested Mail, Web Push, and Nextcloud Talk delivery. It freezes only safe channel and
mail-provider binding facts. Delivery reauthorizes the current recipient, source, notification type,
preferences, mailbox/Ticket access, and exact mail binding before any content-bearing external
channel runs. Suppression is terminal; an uncertain or abandoned external attempt becomes
`unresolved` and is never blindly replayed.

## Reconciliation Algorithm

1. Start discovery creates a stable, sorted scope of all selectable and sync-enabled provider
   folders and freezes each active UID namespace and start tuple.
2. Local-only folder materialization uses a frozen high-water and durable cursor in pages of at most
   100 before remote scanning may proceed. Remote folder discovery is capped at 500.
3. Folder jobs scan numeric UID ranges in provider batches of at most 500 UIDs and about ten seconds
   of provider work per job.
4. Use metadata-only `UID FETCH (UID FLAGS [MODSEQ])`. Existing placements are marked observed and
   provider flags are updated only when changed, with a `sync_version` increment.
5. Unknown UIDs become durable import items and are fetched exactly with bounded `BODY.PEEK`.
   Normal-size messages use one exact full raw literal whose byte length must match `RFC822.SIZE`
   before parse/store. Oversize messages deliberately fetch and persist only bounded header plus
   provider metadata; their body is never requested. Ordinary content is committed hidden until its
   exact Store/canonical/placement contract is accepted. Draft and Sent imports never retry
   APPEND/send.
6. A folder without mailbox-local MODSEQ support waits for its imports, then completes two matching
   bounded UID+FLAGS inventories. The first pass refreshes durable deviation evidence; the second
   pass verifies its exact hash/count before projection.
7. Finalization waits until every import and required historical viewer-baseline item is terminal.
8. Negative evidence is applied only when start/end folder scope and the complete
   UIDVALIDITY/UIDNEXT/EXISTS/HIGHESTMODSEQ tuple are stable, observed counts agree, the namespace is
   still active, and local placement baseline/sync versions did not change.
9. An unstable or partial folder becomes `stale`; positive exact observations may be retained, but
   no absence is applied.
10. A missing provider folder requires two complete stable discovery cycles separated by at least one
   normal interval. Only then is its projection disabled and eligible placements hidden.
11. UIDVALIDITY change blocks the folder and hands control to the explicit cursor re-baseline flow.
   UIDs are never reused across namespaces.
12. A provider rename is not inferred from UIDVALIDITY alone. A newly observed folder is projected,
    while retirement of the old folder follows stable absence evidence. Existing Nexum-initiated
    rename evidence remains authoritative for its own operation reconciliation.
13. The first complete account cycle baselines existing folders without importing their historical
    contents. A folder appearing after that baseline is new provider state and may be synchronized
    resumably. Its existing contents stay hidden until the bounded per-viewer historical read
    baseline completes and never run Inbox automation.
14. Provider copy keeps source and target placements. Provider move may transfer personal state only
    after one exact same-account identity and authoritative target namespace/UID tuple plus confirmed
    source absence. Collision or drift rolls the projection back and leaves the source visible.
15. Live Inbox automation waits for complete account-wide correlation. Confirmed moves, copies, and
    same-cycle duplicates are suppressed. Weak, ambiguous, failed, or drifting evidence fails closed.
    Only a strong zero-peer delivery becomes pending for the normal rule pipeline, always without
    provider-mutation authority.
16. Folder and run publication use sealed summaries in database pages of at most 100. Cancellation
    and recovery drain claims in bounded pages and never publish a partial summary as complete.

## Local Operation Conflict Rules

- Without an unresolved local operation, complete provider state wins.
- If exact provider state proves an unresolved operation's intended result, the existing operation
  may be completed through its read-only reconciliation path.
- Contradictory provider state updates the projection and marks the local operation stale,
  superseded, or ambiguous with a visible safe code. It never invokes retry.
- Move evidence without exact target path and target UID remains ambiguous. Message-ID correlation is
  not sufficient.
- A missing placement with an unresolved operation remains visible as a sync conflict and cannot be
  organized further until that operation is explicitly reconciled, retried, or cancelled.

Stable confirmed absence reuses an extracted `EmailProviderAbsenceProjector` from the current
deletion reconciler: retain tombstone/audit, hide the placement, restore exact reappearance, and
soft-delete cache only when no active placement survives. Permanent cleanup/grace policy remains the
separate default-off lifecycle feature.

## Runtime Components

Jobs:

- `DispatchEmailProviderReconciliation`
- `ReconcileEmailProviderAccount`
- `ReconcileEmailProviderFolderBatch`
- `ImportEmailProviderReconciliationItem`
- `ProjectEmailProviderHistoricalReadBaseline`
- `ProcessEmailProviderReconciliationAutomation`
- `FinalizeEmailProviderReconciliation`
- `TransitionEmailProviderReconciliationCancellation`
- `DispatchEmailProviderIdleListeners`
- short-lived `ListenForEmailProviderChanges`

Actions:

- `StartEmailProviderReconciliation`
- `CancelEmailProviderReconciliation`

Services include a coordinator, bounded metadata scanner, importer/Store adapter, finalizer,
folder/placement/conversation projectors, automation correlator, cancellation transition,
read-only remote-operation observer, binding/policy authorization, placement snapshotter, and the
extracted absence projector.

Every provider job uses the shared account provider lock, but only for its bounded operation. Run,
folder, item, and placement claims use one run-first database lock order plus exact phase/cursor/
attempt compare-and-swap gates. One active run per account is enforced by an active-slot database
constraint, durable idempotency, and unique jobs. Queue payloads contain IDs and safe generations
only; credentials and endpoint data are re-resolved in `handle()`.

Cancellation is intentionally two-stage. The HTTP action commits only an idempotent actor/timestamp
intent and queues `TransitionEmailProviderReconciliationCancellation`. That job takes the same
account provider lease as in-flight reads, then changes the run to `cancelling` and wakes bounded
finalization. This lets already authorized hidden Store persistence finish without publishing it,
while every later claim/projection denies the intent. Scheduled/finalizer recovery can complete the
transition if the first queue dispatch is lost.

IDLE is only a latency hint. It must not hold the provider-operation lock while waiting, and its
opaque hints only enqueue normal locked reconciliation. Scheduled reconciliation remains the
correctness fallback for flag changes, expunges, lost/duplicate/out-of-order hints, and reconnects.

## Schedule And Operations

- The Laravel scheduler dispatches due reconciliation every minute. Each all-account job processes
  one ordered page of at most 50 accounts and serializes its `afterAccountId` cursor into a successor
  job, so a large account table does not restart at ID zero or starve later accounts.
- Default full reconciliation due interval is five minutes, while each job remains UID/time bounded
  and resumes from durable progress.
- `email:reconcile-provider {--account=} {--async}` uses the same dispatcher.
- Disabled accounts or paused/revoked credentials stop new claims. One account failure never prevents
  later account dispatch.
- The Notification scheduler dispatches one page of at most 100 pending/abandoned inbound external
  outbox rows every minute on the `notifications` queue.

The documented Dev runtime previously had only an external hard-coded `email:poll --account=1` cron
and no observed `schedule:run`. Order 7 is implemented and automatically verified, but it is not
operationally complete until an operator applies the pending migrations on a backed-up Dev database,
installs/verifies the Laravel scheduler runner or equivalent explicit all-account commands, and
verifies the required Email/default/notification workers plus optional `email-idle` worker.

## Authorization And UI

The existing mailbox-maintenance page exposes:

- `POST .../accounts/{account}/provider-reconciliation`
- `POST .../accounts/{account}/provider-reconciliation/{run}/cancel`

Both require an active human user, `email.account_manage`, `email.mailbox_sync_manage`, exact nested
account binding, and a sync-eligible account through `EmailMailboxMaintenanceAuthorization`.

It displays the latest completed run, current status/phase/age, folder and UID progress, import/stale/
conflict/blocked counts, and the re-baseline link for a UIDVALIDITY block. It shows only stable codes
and bounded folder operational metadata, never subject, participants, snippets, body, raw source,
attachment filenames, or credentials. No new public API is included.

## Tests

Automated coverage includes:

- Seen/Answered/Flagged/Deleted/Draft/custom flag changes with no header/body/provider write;
- provider Seen independence from personal unread and opened-by;
- all-account chunked dispatch, one-account failure, unique jobs and shared locks;
- resumable UID cursor, redelivery, worker restart and runtime bounds;
- stable versus drifting inventory and no hide on partial/count mismatch;
- external move, copy, Trash, expunge, reappearance, folder create/rename/delete and two-cycle absence;
- UIDVALIDITY change and explicit re-baseline recovery;
- pending/ambiguous flag, move and folder operations with no blind replay;
- live Inbox, post-baseline folder, Draft and Sent imports;
- rule execution with `allow_provider_mutation=false` and an explicit no-`deleteByUid` regression;
- no canonical-only authorization/move decision and no cross-account identity match;
- personal/shared/system isolation and maintenance permission/non-disclosure;
- IDLE hint loss/duplication/reordering plus scheduled catch-up;
- credential disable, rotate, revoke, or binding change between dispatch and execution;
- negative assertions for send, APPEND, STORE, MOVE/COPY, folder writes, Ticket, Signal, AI, and
  personal-state mutation;
- regression against polling, historical import, UID namespaces, remote recovery/undo, provider
  deletion, Draft, Sent, unread and attachment recovery.

## Automated Verification

The final focused Order-7 SQLite matrix passes **255 tests / 2,225 assertions**, including the
standalone durable-fanout contract (**34 / 468**) and the rolling unread-schema compatibility
contract (**4 / 26**). The existing disposable MariaDB guard/path/Integration matrix passes
**3 / 434**, and the final `118500` contract passes **3 / 163** using Laravel's `mysql` driver
against a real private MariaDB server. These are focused results, not a claim that the complete
repository suite is clean. Exact scopes are recorded in `HR-2026-08-16-007`; passing automation
does not complete any manual checkbox or authorize a provider run, migration, scheduler change,
worker restart, or deployment.

## Deploy And Human Review

Deploy uses additive migrations `2026_08_16_118000` through `118500`, permission-preserving code,
`umask 0002`, cache/view rebuild, and restart of all long-lived Email/default/notification workers.
The external scheduler, required Email/notification workers, and optional IDLE process are explicit
operator tasks; no deployment automatically starts provider reconciliation or enables destructive
cleanup.

Connectivity to the authoritative Dev/Plesk MySQL endpoint is restored. A sanitized, read-only
`php artisan migrate:status` check completed successfully. The last check reported the exact 20
Order-1-through-7 migrations `100000` through `118500` as Pending. No migration was applied. Shared
Dev migration and rollback smoke, authenticated browser/provider checks,
scheduler/worker/queue/backlog validation,
deployment, and named review remain operator-gated under `HR-2026-08-16-007`. SQLite and disposable
private-socket MariaDB automation do not replace those checks.

`HR-2026-08-16-007` remains Pending until a named reviewer verifies on one controlled mailbox:
external flags, move/copy/Trash, folder lifecycle, Draft/Sent, unchanged personal unread, visible
ambiguous-operation behavior, UIDVALIDITY block/re-baseline, IDLE disconnect plus scheduled catch-up,
all-account coverage, worker/backlog health, sanitized logs, and zero reconciliation-created provider
mutations.

## Done Criteria

- [x] Orders 5 and 6 implementation contracts and their shared call sites were re-read and integrated;
  their separate human-review/deploy gates remain pending.
- [x] Additive run/folder/item/placement/outbox schema, coherence guards, indexes, bounded cursors, and
  evidence-preserving rollback refusal are implemented and automatically verified.
- [x] Every enabled provider folder has a bounded resumable read-only reconciliation path, including
  mailbox-local NOMODSEQ verification and crash recovery.
- [x] Stable provider changes project locally without conflating provider state with personal unread,
  replaying provider writes, or trusting weak/ambiguous identity.
- [x] Partial, conflicting, stale, cancelled, and ambiguous evidence stays visible and cannot produce
  a destructive inference, blind retry, or duplicate automation/external delivery.
- [x] IDLE is an optional hint; the bounded scheduled all-account dispatcher remains the correctness
  path.
- [x] Focused SQLite and actual MariaDB contract evidence is documented under `HR-2026-08-16-007`.
- [ ] Apply the pending additive migrations only after a backed-up authoritative
  Dev migration preflight, and explicit operator approval; verify rollback refusal against retained
  evidence.
- [ ] Verify the scheduler plus Email/default/notification workers, optional IDLE supervision,
  backlog/failed-job health, group-writable compiled views, and controlled provider behavior on Dev.
- [ ] A named human reviewer completes every unchecked manual check under `HR-2026-08-16-007` and
  explicitly changes that entry from `Pending` only after the results are recorded.
