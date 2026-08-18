# Feature Slice: Email Mail Historical Import And UID Re-Baseline

Status: Done / Human Review Pending
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Program index: `docs/plans/2026-08-16-email-mail-completion-slice-index.md`
Owner: Svein / Codex
Human review: `HR-2026-08-16-001`

## Goal

Add one explicit, permissioned mailbox-maintenance workflow that can import a bounded historical
window without moving the live forward-only cursor, and can safely establish a new UIDVALIDITY
namespace after the provider invalidates a folder's UID identity.

Automatic polling must remain forward-only. Unread state is never an import cursor, and neither
maintenance action may mutate provider messages, flags, folders, drafts, Sent state, or personal
unread state.

## User-Visible Behavior

An authorized operator can open **Mailbox maintenance** for one Email account and:

- preview a historical import for selected enabled/selectable folders and a date window of at most
  31 days;
- see sanitized provider counts, exact folder/UIDVALIDITY scope, effective cap, already-present
  count, estimated new count, and blockers before confirming;
- start an immutable, progress-visible import with a default total cap of 100 messages and a hard
  total cap of 500 messages per run;
- request safe cancellation between batches and see imported, already-present, skipped, failed, and
  remaining item counts without raw headers, addresses, subjects, filenames, credentials, or body
  content in maintenance logs;
- preview and explicitly apply a UID re-baseline for one failed folder after entering a reason and
  confirming the old and newly observed UIDVALIDITY/UIDNEXT values.

An expired preview, changed provider snapshot, unresolved provider mutation, overlapping poll/import,
or ambiguous UID namespace fails closed with a useful sanitized blocker. Re-baseline defaults to the
current provider `UIDNEXT` and imports no history; the operator must run the separate historical
import workflow for any older mail.

## Exact Contract

### Permission And Authorization

- Add the narrow web permission `email.mailbox_sync_manage`.
- Every preview, start, cancel, and re-baseline action requires `email.account_manage` **and**
  `email.mailbox_sync_manage`, an active account, and an account/folder eligible for provider sync.
- The permission is not part of the default Tech role. Admin/Superuser seeding must be explicit and
  covered by role tests.
- Account administration still grants no message-content access. Maintenance previews, progress,
  audit, and errors expose only bounded operational metadata. Reading imported content continues to
  require normal mailbox View access.
- The operation reauthorizes the requesting user before queue dispatch and again at execution. A
  disabled user, revoked permission, inactive account, or changed account policy cancels or blocks
  remaining work without deleting completed evidence.
- This slice adds no public automation API. The domain actions and queries must be controller-neutral
  so a later explicitly scoped API cannot bypass the same authorization and locks.

### Web Routes

All routes remain module-owned under `app/Modules/Email/routes.php`:

- `GET /tech/admin/settings/email/accounts/{account}/mailbox-maintenance`
  (`tech.admin.settings.email.accounts.mailbox-maintenance`)
- `POST /tech/admin/settings/email/accounts/{account}/historical-import/preview`
  (`tech.admin.settings.email.accounts.historical-import.preview`)
- `POST /tech/admin/settings/email/accounts/{account}/historical-import`
  (`tech.admin.settings.email.accounts.historical-import.start`)
- `POST /tech/admin/settings/email/accounts/{account}/historical-import/{run}/cancel`
  (`tech.admin.settings.email.accounts.historical-import.cancel`)
- `POST /tech/admin/settings/email/accounts/{account}/folders/{folder}/cursor-rebaseline/preview`
  (`tech.admin.settings.email.accounts.cursor-rebaseline.preview`)
- `POST /tech/admin/settings/email/accounts/{account}/folders/{folder}/cursor-rebaseline`
  (`tech.admin.settings.email.accounts.cursor-rebaseline.apply`)

Nested binding must prove the run/folder belongs to the selected account. Cross-account, expired,
revoked, or inaccessible identifiers return a non-enumerating denial.

### Migrations And Tables

Use these additive migrations in this order:

1. `2026_08_16_100000_add_email_uidvalidity_namespaces.php`
2. `2026_08_16_101000_create_email_historical_import_runs_and_items.php`
3. `2026_08_16_102000_create_email_cursor_rebaseline_runs.php`

The UIDVALIDITY migration introduces an explicit folder namespace identity. It creates additive
namespace records for every current folder baseline, links current placements to the matching
namespace where the evidence is deterministic, and reports rather than guesses zero/ambiguous legacy
UIDVALIDITY. When provider UIDVALIDITY has changed, a re-baseline creates a new namespace and
supersedes the old namespace. A documented cursor/state recovery with the same positive
UIDVALIDITY reuses that immutable namespace and resets only the forward live high-water; splitting
one unchanged provider identity into two generations is forbidden. Existing placements are never
relabelled merely because their numeric UIDs match.

The implementation owns these records:

- `email_folder_uid_namespaces`: account, folder, monotonically increasing generation,
  UIDVALIDITY, observed UIDNEXT/live start, status, established/superseded timestamps, and sanitized
  provenance. Namespace statuses are exactly `active`, `superseded`, and `legacy_unknown`.
  `email_folders.active_uid_namespace_id` points to the one current proven namespace, while
  `email_mailbox_placements.uid_namespace_id` binds a placement to its proven namespace. The
  migration links only deterministic non-zero matches; legacy zero/ambiguous evidence remains
  nullable/`legacy_unknown` and non-actionable instead of being guessed.
- `email_historical_import_runs`: account, requester, immutable normalized folder/date/cap scope,
  preview/provider snapshot fingerprint, idempotency key, status, progress counters, timestamps,
  cancellation request, and sanitized terminal error.
- `email_historical_import_items`: run, folder, UID namespace, UID, stable item status, resulting
  message/placement references where applicable, attempt timestamps, and sanitized error code.
- `email_cursor_rebaseline_runs`: account, folder, requester/reason, idempotency key, preview
  fingerprint, old/new namespace and UIDVALIDITY/UIDNEXT/live-start evidence, status, blockers,
  timestamps, and sanitized terminal error.

Historical run statuses are exactly `previewed`, `queued`, `running`, `cancelling`, `completed`,
`partial`, `failed`, `cancelled`, and `stale`. Item statuses are exactly `pending`, `imported`,
`already_present`, `skipped_out_of_scope`, `skipped_stale`, and `failed`. Re-baseline statuses are
exactly `previewed`, `completed`, `blocked`, `stale`, and `failed`.

Run/item uniqueness must make repeated confirmation and queue delivery idempotent. Audit rows retain
identifiers, counts, timestamps, hashes/fingerprints, status, actor, and safe error codes; they do not
retain message content or provider secrets.

### Historical Import Rules

- A request selects one account, one or more currently enabled/selectable folders, an inclusive UTC
  date range no longer than 31 days, and a requested total cap.
- The default total cap is 100 and the server-enforced hard total cap is 500. A smaller configured
  installation/account cap wins. Processing uses bounded batches no larger than 50 items.
- Preview reads provider metadata without changing `email_folders.live_start_uid`, account live
  cursors, provider flags, or local personal state. The confirmed run is bound to an expiring preview
  fingerprint and exact UIDVALIDITY namespace.
- The import enumerates stable provider UIDs inside the selected date window and cap, processes them
  in deterministic folder-path and ascending provider-UID order, and rechecks UIDVALIDITY plus the
  provider snapshot at every batch boundary. This metadata-only preview does not claim global
  chronological order across folders.
- Placement identity remains account + folder + UID namespace/UIDVALIDITY + UID. Existing placement
  uniqueness is authoritative; a rerun records `already_present` rather than duplicating a message,
  placement, attachment, conversation, or Sent/Draft reconciliation record.
- Historical Inbox mail is stored as historical cache only. It does not execute inbound rules,
  Ticket/lead routing, Signal handoff, notifications, Smart Inbox analysis, automatic AI, or provider
  cleanup. Sent, Drafts, Trash, Junk, Archive, and custom folders likewise never enter Inbox
  automation.
- Imported history does not become `unread for me`, acknowledge a user, or derive personal state from
  provider `Seen`. Explicit backlog handover is owned by the later unread-baseline slice.
- Bodies/raw source/attachments use the existing private-storage and placement authorization
  boundaries. This slice adds no new preview, inline execution, indexing, extraction, download, or AI
  authority; the later attachment-quarantine slice applies to imported content too.
- Cancellation stops before the next batch. Already committed items and their immutable evidence are
  retained; there is no destructive rollback of successfully projected provider mail.

### UID Re-Baseline Rules

- Re-baseline is available only for a folder with a recorded UIDVALIDITY/state failure or an explicit
  operator recovery reason. It is not a general `skip history` convenience button.
- Preview compares the old namespace, current provider UIDVALIDITY/UIDNEXT, local placement counts,
  last live cursor, import activity, and unresolved remote-operation/Draft/Sent/reconciliation state.
- Any pending or ambiguous provider mutation for the folder, active poll/import/refresh lock,
  unstable start/end provider state, missing UIDVALIDITY/UIDNEXT, unchanged validity without a
  documented recovery condition, or changed preview fingerprint blocks before local mutation.
- Apply records the old namespace as superseded, creates/selects the exact new namespace, and sets
  the folder's live forward-only start to the current provider `UIDNEXT`. It clears only the matching
  UIDVALIDITY sync blocker after the new baseline is durably committed.
- Placements in the superseded namespace retain provenance and are never rewritten to the new
  numeric UID namespace. They cannot be used for provider mutations unless separately reconciled to
  exact current provider evidence.
- Apply performs no message fetch/import, delete, move, flag/read mutation, rule replay, personal read
  change, Ticket/Signal action, or provider write. A historical import against the new namespace is a
  separate explicit action.

### Concurrency And Operations

- Poll, folder refresh, historical import, re-baseline, provider inventory, Draft targeted refresh,
  and remote mutations share account/folder overlap locks. No two cursor-owning operations may run
  concurrently for the same account/folder.
- Runs execute on the isolated Email queue in bounded jobs and remain observable after worker restart.
  Queue redelivery resumes from durable item state and never blindly re-fetches completed items.
- The external scheduler and Email queue worker must be verified on Dev before the slice is called
  operational. `schedule:list` alone is insufficient.
- Provider/network failures back off and remain retryable within a bounded attempt policy. Changed
  identity/state becomes `stale`, not an automatic retry with a wider scope.

## Out Of Scope

- Inferring historical backlog from unread flags or importing an unrestricted mailbox.
- Automatic import after a UIDVALIDITY reset.
- Backlog handover to personal unread state.
- Cross-account canonical-message merging or canonical read-path cutover.
- Provider writes, permanent deletion, rule reprocessing, Ticket capture, Signal emission, AI, search
  indexing, or external BookStack publication.
- Delegation/break-glass content access, attachment malware scanning, and production enablement.

## Data Touched

- New UID namespace, historical import run/item, and cursor re-baseline records described above.
- Existing `email_accounts`, `email_folders`, `email_mailbox_placements`, `email_messages`,
  `email_conversations`, raw/attachment private storage, and provider-read services.
- Permission/role seeders, Email module routes/controllers/actions/queries/jobs/views/tests, Email
  README/Knowledge, TODO, and human-review records.
- Read-only checks of remote operations, provider inventory/reconciliation, Drafts, and Sent state as
  blockers.

## Permissions

`email.mailbox_sync_manage` is mandatory in addition to `email.account_manage`. Normal mailbox View,
Organize, and Send grants remain unchanged and continue to govern content and provider actions.
Maintenance never converts account-management authority into content access.

## Tests

Implemented verification on authoritative Dev (isolated test database; no live provider/import or
re-baseline action) is green:

- focused historical-import, UID-namespace and cursor-rebaseline coverage: **21 tests / 167
  assertions**;
- adjacent polling, Draft, remote-operation, Undo, Sent and attachment coverage: **68 / 465**;
- inbound automation plus durable conversation-query coverage: **22 / 194**;
- complete `EmailModuleTest`: **141 / 1,227**;
- Pint on 41 owned/shared files, PHP lint, Blade cache, compiled-view group-write and diff checks.

These automated results complete the implementation gate but do not replace the pending Dev
migration, controlled provider exercise, scheduler/worker verification or named human review.

- Permission and role matrix for maintenance view, preview, start, cancel, and re-baseline; disabled
  actor, inactive account, cross-account binding, and content non-disclosure cases.
- 31-day window, default 100 cap, hard 500 cap, smaller configured cap, maximum batch 50, invalid
  folder/date/cap, expired preview, and changed preview fingerprint.
- Oldest-first import, already-present idempotency, duplicate queue delivery, partial failure/resume,
  cancellation, worker restart, account/folder overlap locks, and two concurrent confirmations.
- Inbox historical import creates no rules, Tickets, Signals, notifications, Smart Inbox suggestions,
  AI calls, provider operations, provider `Seen`, personal unread change, or live-cursor movement.
- Non-Inbox historical import preserves role-specific projection and never runs Inbox automation.
- UIDVALIDITY changes before/during a batch fail stale without widening or continuing the import.
- Namespace migration backfills deterministic rows, reports ambiguous/zero validity, and never
  relabels old placements across a UID reset.
- Re-baseline preview/apply, required reason, stable snapshot, new forward-only `UIDNEXT` baseline,
  superseded placement protection, unresolved-operation blocker, overlap blocker, unchanged-state
  recovery rule, idempotent repeated confirmation, and no provider/message/user-state mutation.
- Focused Email tests, inbound automation regressions, Notification regressions, permission seed
  tests, migration pretend on a clean schema, migration execution on Dev, and the complete Email
  directory before handoff.

## Documentation

Update Email README and Knowledge with operator instructions, safe caps, progress/error meanings,
permissions, queue/scheduler prerequisites, rollback/stop procedure, and the distinction between live
polling, historical import, re-baseline, and later backlog handover. Update this slice, TODO, the
completion index, and `HR-2026-08-16-001` with exact verification evidence.

## Deployment And Rollback

The migrations are additive and perform no provider call or import. Before the first Dev run, take a
database backup, verify the Email worker and scheduler, confirm storage permissions, and use one
non-production account/folder with a small preview/cap. No production import or re-baseline is
authorized by this slice document.

Stop order is: hide/disable maintenance controls, stop new Email maintenance dispatch, let or safely
cancel the current batch, stop the Email worker if required, preserve all run/item/namespace evidence,
and restore the prior folder read path. Do not drop audit tables or relabel placements as the first
rollback action.

## Done Criteria

- [x] The three additive migrations pass clean-schema and rollback-safety automated coverage. The
  populated-Dev migration and before/after inspection remain in human review.
- [x] Permission seeding and every web route fail closed without both required permissions and exact
  nested account scope.
- [x] Preview and execution enforce 31 days, default 100, hard 500, configured lower cap, and batches
  of at most 50.
- [x] Historical import is durable, idempotent, cancellable between batches, visible, and cannot move
  the live cursor or trigger provider/PSA/personal-unread side effects.
- [x] UID re-baseline creates and supersedes only when UIDVALIDITY changes; documented same-validity
  recovery reuses the immutable namespace, moves only the live high-water, blocks
  unresolved/overlapping work, and imports nothing automatically.
- [ ] Exact focused, affected-module, lint/format and view checks pass; populated-Dev migration,
  authenticated browser, real queue/scheduler/storage-runtime and controlled provider checks remain
  in `HR-2026-08-16-001`.
- [x] Email developer and Knowledge documentation contain the operator/runbook contract.
- [x] `HR-2026-08-16-001` contains exact automated evidence and remains `Pending` until Svein or
  another named human explicitly completes every applicable check.
