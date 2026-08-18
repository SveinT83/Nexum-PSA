# Feature Slice: Email Mail Runtime Reliability Hardening

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-15-003`

## Goal

Make normal Mail draft, send, provider-operation, and right-bar workflows report their true state and
recover safely across the separate PHP-FPM, queue-worker, filesystem, and IMAP runtimes.

## User-Visible Behavior

- `Mailbox operations` now appears in the Mail right bar only when active or recent work exists. It
  starts collapsed, keeps compact status counts in its header, and expands to the same authorized
  retry, cancel, evidence, and Undo controls.
- `Mail signature` is also collapsed by default. Expanding it reveals the settings trigger; the
  Bootstrap modal keeps its explicit X, Cancel, and Save controls above the page footer.
- Archive and Trash choose the account's real provider special-use or shallow canonical folder, not
  a custom child whose parent happens to contain `Archive` or `Trash` in its path.
- A provider operation whose exact source UID no longer exists stops as stale without exposing the
  Webklex `no headers found` exception or blindly issuing another provider mutation. A genuine
  provider read failure remains visible for controlled manual review/retry and is not placed in an
  automatic retry loop. Connection, authorization, exact-UID, and provider-read preflights do not
  consume the five-attempt provider-mutation budget.
- Manual Save draft still completes locally first and appends to the real provider Drafts folder.
  That folder is re-inferred from current SPECIAL-USE/exact-leaf evidence and must be selectable and
  sync-enabled; a stale stored Drafts role cannot select an unsafe descendant. A tokenized durable
  reservation elects one APPEND owner. A fresh reservation blocks concurrent saves, a pre-write
  reservation may be taken over only after five minutes, and an unresolved response is reconciled
  without replaying APPEND. Mail then queues one bounded, exact Drafts-folder refresh so the
  provider-acknowledged copy can be imported and shown in the Drafts view without waiting for an
  unrelated full mailbox poll. IMAP UIDNEXT returned before APPEND is treated only as a hint; the
  imported provider UID and UIDVALIDITY remain authoritative.
- Before SMTP, Mail atomically reserves the composer idempotency key and its RFC `Message-ID` in the
  existing outbound log. Concurrent or repeated submission of the same composer cannot elect a
  second SMTP sender. If the transport outcome cannot be confirmed, the reservation stays
  unresolved and blocks resend while the technician checks provider Sent mail. A later exact
  same-account Sent import can resolve that reservation as accepted without resending.
- A failure while preparing the composer before provider delivery keeps the composer open, exposes
  no internal exception details, and says the message could not be prepared rather than claiming a
  delivery failure.
- Once SMTP accepts a message, Mail reports it as sent even if the local Sent snapshot or
  reconciliation record cannot be stored. The matching local draft is marked sent and provider-draft
  cleanup still runs. The user receives a warning that the follow-up failed and explicitly says not
  to resend; a post-SMTP storage failure is never presented as `The message could not be sent`.
  If local draft cleanup itself fails, the composer still closes as sent and warns that the local
  draft may need cleanup; the send reservation continues to block duplicate delivery.

## Scope

- Infer folder roles from provider SPECIAL-USE or the exact folder leaf, and repair old descendant
  misclassification during normal folder discovery.
- Select deterministic account-scoped Archive/Trash targets, preferring explicit SPECIAL-USE and
  then the shallowest canonical selectable folder.
- Check exact IMAP UID presence without fetching message headers before a provider mutation or
  reconciliation read.
- Record connection/authorization/source-read work as preflight evidence, promote that attempt to a
  mutation only at the provider-write boundary, and exclude preflight/reconciliation rows from the
  bounded mutation-attempt count.
- Classify a missing source as terminal stale work and keep raw provider/library exceptions out of
  every user-facing operation reason and error field.
- Preserve the provider-returned target UID after an acknowledged move when Webklex exposes it.
- Keep ambiguous Archive/Trash/Move work without immutable target folder path or target UID visible
  for review but remove manual Retry because no exact target can be proven.
- Route new private Email writes through `EmailPrivateStorage`, limited to normalized `email/*`
  paths on the established local disk. The writer creates owner-controlled directories as setgid
  group-writable, writes files as group read/write, verifies the final file, and logs only sanitized
  failure scope/reason metadata.
- Use the private writer for inbound raw MIME, inbound attachments, durable draft attachments, and
  outbound Sent snapshots. Persist a message `raw_path` only after the file write is verified.
- Reauthorize saved attachment IDs against the exact active draft and its current mailbox composer
  context before handing any file to SMTP.
- Reserve the existing unique outbound Email-log idempotency key and a stable RFC `Message-ID`
  atomically before SMTP. Attempt initial same-identity reconciliation evidence before the provider
  call, retain an unresolved reservation when the transport result is ambiguous, and never issue a
  second SMTP call for that key.
- Mark a failed preliminary reconciliation write as `reservation_failed`, never as provider
  acceptance. Accepted and later `accepted_reconciled` rows are reusable read-only results, and an
  ambiguous SMTP exception cannot overwrite a concurrent exact Sent-sync confirmation.
- Sanitize unexpected pre-provider composer failures and distinguish them from accepted or unresolved
  provider outcomes.
- Treat SMTP acceptance as the outbound send boundary. Record Sent-snapshot and reconciliation
  failures as follow-up warning metadata without throwing a false send failure or reopening the
  matching draft.
- Remove a newly written raw Sent snapshot if its reconciliation row cannot be persisted, and let a
  later exact same-account Sent import resolve an unresolved send reservation by Message-ID.
- Reserve technical provider Sent APPEND under a row lock. Repeated `append_started`/`appended` work
  is a no-op, and a failure after provider-write start remains blocked instead of risking a duplicate
  provider Sent copy.
- Queue `RefreshEmailProviderDraftFolder` after a successful manual provider Draft APPEND. The job is
  unique per draft/account/folder, shares the account-fetch overlap lock, requires an already
  initialized selectable Drafts folder, checks UIDVALIDITY, reads at most 50 new UIDs after the local
  high-water mark, matches the exact normalized Message-ID, and imports through the ordinary inbound
  storage path with Inbox automation disabled.
- Re-infer the exact Drafts role for every eligible selectable, sync-enabled candidate, prefer
  explicit SPECIAL-USE and then the shallowest deterministic canonical path, and repair the selected
  row's stale role projection.
- Reserve provider Draft APPEND with a durable owner token before connecting. Fresh reservations
  block concurrent calls; only a reservation still untouched after five minutes may be taken over.
  Promote that exact token to `append_started` immediately before APPEND, preserve it through
  autosave/attachment changes, and reconcile pending/unresolved results without a second APPEND.
- Move the existing stateful Mailbox operations surface to the right bar and make both it and Mail
  signature default-collapsed with accessible disclosure state.

## Out Of Scope

- Rewriting or silently retargeting historical remote-operation rows whose immutable target evidence
  was recorded incorrectly. Those rows require controlled cancellation/reconciliation against fresh
  provider evidence.
- Permanent provider deletion, bulk retry, or automatic external replies.
- A full provider Drafts history import, provider push transport, or reliance on optional IMAP
  APPENDUID support.
- Automatic proof or replay of an SMTP transport result that the provider did not confirm. Such an
  outcome remains unresolved and blocks duplicate submission until it is reviewed against Sent mail.
- Automatic replay of an ambiguous provider Sent APPEND after its IMAP write may have started.
- Changing the storage disk, database path format, retention ownership, or stored Mail/Ticket data.
- Owner/root normalization of legacy filesystem entries that the current SSH/queue user does not
  own. On Dev, `storage/app/private/email/raw/2` and
  `storage/app/private/email/attachments` still require that one-time operational repair.

## Data Touched

- Existing `email_folders`, `email_mailbox_placements`, `email_remote_operations`, and
  `email_remote_operation_attempts` state/evidence.
- Existing `email_composer_drafts`, provider Drafts placement projection, and the default Laravel
  queue through `RefreshEmailProviderDraftFolder`.
- Existing outbound `email_logs` reservation/acceptance metadata and `email_sent_reconciliations`
  follow-up metadata.
- Private local Email files below `storage/app/private/email` through the established `local` disk.
- Mail right-bar Blade and Livewire presentation state.

No migration or data backfill is introduced by this slice.
The atomic send reservation reuses the unique `email_logs.idempotency_key` added by existing
migration `2026_08_12_125000_add_email_log_idempotency_key.php`.

## Controlled Dev Repair

Fresh read-only provider evidence was used to repair the specific rows affected before this
hardening, without replaying the original operation:

- remote operation `23` was cancelled and its stale source placement `474` was hidden;
- the exact verified provider Trash copy at UID `30177` was projected as placement `485` in the real
  Trash folder `141`, while the incorrectly classified child folder was repaired to `custom`;
- composer draft `1` was marked sent and its exact provider Drafts UID was deleted only after its
  normalized Message-ID and send-log idempotency identity matched; and
- the provider post-check found the source UID absent, the exact copy in the real Trash folder, no
  copy in the wrong child folder, and the draft UID absent.

The controlled repair performed zero SMTP writes and zero IMAP MOVE writes. The provider Sent folder
did not contain the exact outbound Message-ID and no raw Sent snapshot remained, so no provider Sent
copy was fabricated or blindly appended. This records the truthful unresolved Sent projection
without risking duplicate delivery.

## Permissions

- Existing global Email permissions and mailbox View/Organize/Send intersections are unchanged.
- The Mailbox operations right-bar card remains limited to mailboxes the current technician may
  organize; moving the card does not widen its query or action scope.
- Draft append and targeted refresh remain bound to the draft's active account and exact discovered
  Drafts folder. The refresh performs no user-visible Inbox automation or cross-account query.
- Private storage paths are application-internal and are never exposed by this slice.

## Tests

- Folder-role inference ignores parent-path substrings, repairs legacy descendant roles, and chooses
  deterministic Archive/Trash targets.
- Missing exact source UIDs stop Seen/Flag/Move work as stale without header fetching, automatic
  replay, or raw `no headers found` output.
- Connection/read preflights remain sanitized evidence without consuming the mutation budget; the
  same attempt is promoted only when provider mutation begins.
- Provider read failures remain distinguishable from confirmed absence; move target UID evidence is
  retained.
- Ambiguous move-like rows without immutable target path/UID evidence expose no Retry.
- Restrictive-umask private writes produce setgid `0770` directories and `0660` files, reject paths
  outside `email/*`, verify writes, and do not persist `raw_path` on failure.
- A Sent-snapshot or reconciliation-record failure after SMTP leaves one accepted outbound log,
  marks the draft sent, closes the composer, shows a sanitized `Do not resend it` warning, and does
  not issue SMTP again for the same persisted idempotency key.
- Reservation tests cover concurrent ownership, a stable pre-generated Message-ID, ambiguous
  transport outcomes that remain blocked, accepted-log finalization failure, and harmless telemetry
  failure after acceptance.
- Reservation tests also distinguish pre-provider `reservation_failed` evidence, reuse
  `accepted_reconciled`, and prove that concurrent Sent confirmation wins over an SMTP exception.
- Pre-provider failures hide internal details and keep the composer open. Draft-cleanup failure after
  acceptance closes it with a truthful sent warning while the reservation prevents another send.
- Manipulated saved-attachment IDs cannot escape the exact active authorized draft/account context.
- Provider Sent append tests cover repeated accepted calls, ambiguous post-write failure, and stale
  `append_started` work without a second IMAP mutation. They also cover failed-row snapshot cleanup
  and resolving an unconfirmed send from later provider Sent evidence.
- A successful provider Draft APPEND dispatches one exact refresh; the job is bounded,
  overlap-protected, UIDVALIDITY-safe, imports only the matching Message-ID with inbound rules off,
  and leaves the draft pending when the new copy is not yet visible.
- Provider Draft tests cover one token owner, concurrent-call blocking, five-minute stale pre-write
  takeover, exact selectable/sync-enabled folder re-inference, protected state across autosave, and
  reconciliation without APPEND replay after an unresolved response.
- Right-bar tests cover location, default collapsed state, status badges, disclosure controls,
  signature expansion, modal X/Cancel, and adjacent Undo behavior.

## Documentation

- Update Email README and Email Knowledge with the runtime/send/storage/draft/right-bar behavior.
- Update the original remote recovery, retry dashboard, provider Drafts, Sent reconciliation, and
  signature slices where this work refines their implemented contract.
- Update `docs/TODO.md` with the remaining legacy ACL repair while recording the send reservation as
  completed runtime hardening.
- Track manual verification under `HR-2026-08-15-003`, while retaining the original signature and
  operation-dashboard checks under `HR-2026-08-13-019` and `HR-2026-08-13-030`.

## Automated Verification

- Integrated runtime-focused package: 74 tests / 613 assertions. This covers private storage,
  pre-/post-SMTP safety, provider Sent APPEND, tokenized provider Draft APPEND/targeted refresh,
  composer lifecycle, remote-operation recovery/preflight accounting, verified Undo, and supervised
  Smart Inbox cleanup.
- Full `EmailModuleTest.php`: 141 tests / 1,227 assertions.
- Full `InboundAutomationTest.php`: 14 tests / 81 assertions, using isolated fake Email storage so
  tests cannot write shared Dev raw MIME or attachment trees.

Targeted Pint, PHP syntax, Blade cache, and diff checks pass. Automated tests do not complete the
human review gate.

## Done Criteria

- Provider operations no longer choose a child folder by parent-name substring or surface a
  missing-UID header-fetch exception as retryable work.
- New private Email payload writes are verified and group-shareable across FPM/worker runtimes; the
  remaining legacy paths are documented as an operational prerequisite, not reported as repaired.
- One durable unique reservation is elected before SMTP. Unresolved provider outcomes block replay,
  and accepted messages never show a false send-failure prompt because local follow-up storage
  failed.
- A manually saved provider draft gets exactly one reserved provider APPEND and one bounded
  authoritative placement refresh without running Inbox automation or trusting pre-APPEND UIDNEXT as
  final identity. Concurrent/fresh or unresolved APPEND state cannot be replayed.
- Mailbox operations and Mail signature are accessible default-collapsed right-bar surfaces.
- Code, tests, and documentation for the slice are complete; named human review remains Pending and
  full cross-runtime storage readiness remains In Progress until the two legacy trees are normalized
  and checked from both FPM and queue-worker contexts.
