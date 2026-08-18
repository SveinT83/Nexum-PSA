# Feature Slice: Email Mail Remote Operation Recovery

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-14-010`

## Goal

Make provider mailbox mutations recoverable without blindly replaying an operation whose remote
outcome is uncertain.

## User-Visible Behavior

The Mailbox operations panel shows why an operation stopped, how many provider attempts were made,
and when a safe automatic retry is due. Authorized technicians may safely retry or cancel eligible
work. A retry rechecks the original requester's current mailbox authority and the snapshotted
placement identity before touching IMAP. Ambiguous outcomes are reconciled against provider state
first and remain blocked when the provider cannot prove whether the change happened.

The panel now lives collapsed in the Mail right bar. Provider message reads first verify the exact UID
without fetching headers. If the source is no longer present, the operation stops as stale without a
blind retry or raw `no headers found` library exception. Archive and Trash target resolution uses
provider SPECIAL-USE or the exact canonical folder leaf, so a child below Archive/Trash cannot be
chosen merely because its parent path contains that role name.
An ambiguous Archive/Trash/Move row without its immutable target folder path and target UID cannot
offer manual Retry, because the reconciler has no exact target identity to prove or replay safely.

The Email API exposes the same account-scoped operation list, detail, retry, and cancel behavior.

## Scope

- Record immutable, sanitized evidence for every provider mutation and reconciliation attempt.
- Snapshot placement/folder identity when an operation is created.
- Reauthorize the requester and active account at execution and retry time.
- Supersede stale placement, UID, UIDVALIDITY, folder, and authorization work without provider writes.
- Classify transient, permanent, authorization, stale, and ambiguous outcomes.
- Retry transient failures with bounded exponential backoff and a fixed maximum attempt count.
- Reconcile ambiguous provider outcomes before any replay, including after the mutation-attempt
  budget is exhausted; reconciliation reads do not consume provider mutation attempts.
- Dispatch due retries from the scheduler through a queue job with overlap and row-lock protection.
- Route Livewire and API retry/cancel controls through shared race-safe actions.
- Refresh the durable conversation aggregate after provider Seen/Unseen changes.
- Check exact provider UID presence without header/body retrieval before message mutation and
  reconciliation fetches.
- Treat a confirmed missing source as terminal stale work; distinguish real provider read failures
  so they do not become an automatic retry loop while preserving controlled manual recovery.
- Infer special folder roles from SPECIAL-USE or the exact leaf, repair old descendant
  misclassification during discovery, and select the deterministic account-scoped Archive/Trash
  target.
- Preserve an acknowledged move's target UID when the provider/Webklex result exposes it.
- Suppress manual retry for ambiguous move-like work whose immutable target path or UID evidence is
  absent.

## Out Of Scope

- Undo or inverse provider operations.
- Automatic external email sending.
- Permanent provider message deletion.
- Bulk retry/cancel controls.
- Provider-specific push transports or a new provider abstraction.

## Data Touched

- `email_remote_operations` recovery, evidence, and retry metadata.
- New `email_remote_operation_attempts` append-only attempt evidence.
- A forward-only legacy quarantine migration classifies previously attempted failures as ambiguous
  before the first recovery retry.
- Existing mailbox placement and durable conversation projections after acknowledged/reconciled work.
- Laravel queue and scheduler registration for due retries.

No raw message body, MIME source, attachment content, provider secret, or credential is retained in
operation-attempt evidence.

## Permissions

- Read API endpoints require the `email.read` token ability and current mailbox View access.
- Retry/cancel API endpoints require `email.update` plus current mailbox Organize access.
- The Mail workspace exposes recovery controls only for currently Organize-authorized accounts.
- Execution also reauthorizes the original requester; a later operator cannot revive work after that
  authority or account activation has been revoked.

## Tests

- Attempt rows are append-only after terminal completion and evidence is sanitized.
- Transient failures receive bounded backoff and stop at the maximum attempt count.
- Changed placement version, UID, UIDVALIDITY, or folder supersedes before provider mutation.
- Disabled/revoked requesters and inactive accounts supersede before provider mutation.
- The due runner claims eligible work safely and ignores cancelled/terminal work.
- Cancel/retry races and repeated requests are idempotent.
- Ambiguous results reconcile before replay and never duplicate a provider mutation on uncertainty.
- Ambiguous moves require authoritative target-folder evidence: source-plus-target duplicates or a
  failed folder inventory remain blocked instead of being replayed or accepted as absence.
- API list/show/retry/cancel remain hidden outside account scope.
- Seen/Unseen acknowledgement refreshes the durable conversation unread aggregate.
- Missing source UIDs for Seen, Flag, and Move stop without a header fetch, mutation, retry loop, or
  raw provider exception; a true provider-read error remains separately recoverable.
- Parent-path role collisions cannot route Archive/Trash into a custom descendant, and normal folder
  discovery repairs old rows whose role was inferred from their ancestor.
- Ambiguous Archive/Trash/Move work without immutable target path/UID evidence exposes no Retry and
  performs no provider mutation.

## Documentation

- Update Email README and Email Knowledge Mail overview.
- Update `docs/TODO.md` and `docs/human-review.md` under `HR-2026-08-14-010`.

## Automated Verification

- Dedicated recovery coverage passes with 16 tests and 102 assertions.
- Adjacent verified-Undo coverage passes with 12 tests and 75 assertions, and supervised Smart Inbox
  cleanup coverage passes with 11 tests and 170 assertions.
- Dev migrations `112000` and `112500` ran in batches 89 and 90.
- PHP syntax, Pint, routes, schedule registration, Blade cache, Knowledge sync, and diff checks pass.

## Done Criteria

- Every provider attempt has durable sanitized start/finish evidence.
- Retry, cancel, and scheduler execution use row locks and current authorization.
- Stale evidence never reaches the provider.
- Ambiguous outcomes are reconciled before a retry and uncertainty never causes a blind replay.
- Automatic retries are bounded and observable.
- UI/API behavior, documentation, migrations, and focused tests are complete.
- Exact missing-provider identity and deterministic special-folder targeting are covered without
  rewriting immutable historical operation targets.
- Historical ambiguous move-like rows without exact target identity remain reviewable/cancellable
  but are not manually retryable.
- Human review remains Pending until a named reviewer completes `HR-2026-08-14-010`.
