# Feature Slice: Email Mail Inbound Attachment Recovery And Download

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let an authorized technician see and safely download attachments belonging to the exact selected Mail
placement, and recover the bounded attachment metadata/files that earlier private-storage permission
failures prevented from being persisted.

## User-Visible Behavior

- Stored attachments on a selected received message are shown as download controls with a friendly
  filename and size. Inline parts may be downloaded but are not previewed inline by this slice.
- Downloads work for active authorized Inbox, Sent, Archive, and Ticket-linked placements rather than
  depending on the legacy Inbox-only route.
- Revoked, inactive, hidden, cross-account, mismatched, missing, or unsafe attachment requests return a
  hidden not-found response and never disclose another mailbox or local path.
- Missing historical files are recovered by a bounded operator process; the browser request never
  performs an IMAP refetch or replays inbound automation.

## Scope

- Add one Mail-owned placement/message-bound download route in the Email module.
- Require current Mailbox View access and an active exact placement whose message owns the attachment.
- Force download with a sanitized filename, `nosniff`, private/no-store caching, and an Email-private
  storage path allowlist.
- Make attachments in the selected conversation reader actionable without introducing inline preview.
- Add a bounded, idempotent recovery path that prefers existing local evidence and may use exact
  UID/UIDVALIDITY read-only provider evidence as a fallback.
- Before any provider fallback, accept the exact legacy persister directory
  `email/attachments/{account_id}/{imap_uid}` only when its direct regular-file count equals the
  preserved nonzero message counter and every file passes the current attachment policy.
- Reuse the inbound attachment persister, recompute `attachments_count`, and record honest per-message
  success/failure without dispatching rules, Tickets, notifications, or provider mutations.
- Preserve complete RFC822 evidence for future raw snapshots when the provider library exposes it
  safely.

## Out Of Scope

- Inline document/image preview, virus-scanner selection, OCR/indexing, attachment forwarding, or
  exposing raw `.eml` evidence.
- Unbounded historical import, cursor rebaseline, unread-based catch-up, provider writes, or replaying
  inbound rules.
- Weakening the legacy Inbox download contract or granting mailbox access through attachment IDs.
- Deleting existing files or retrying the unrelated failed queue job blindly.

## Data, Storage, And Operations

The recovery may add missing `email_attachments` rows/files and correct their owning message's derived
attachment count. Dev's bounded scope was 19 messages: logged persistence failures identified 16
messages / 28 MIME parts, while three additional rows had only a nonzero attachment counter.

The `email/attachments` and `email/raw/2` directory/ACL contract was normalized non-destructively to
`www-data:www-data`, directories `2770`, and group-rwx access/default ACLs. Readiness then reported
`safe=true` and `received_at_schema_safe`. Before recovery, Dev had zero attachment rows, target
counter sum 6, 36 existing attachment files, 23 remote-operation rows, and two rule-attempt rows.

Under the Email worker lock, local snapshot apply recovered 13 messages / 24 parts. Exact provider
fallback was then limited to messages `4`, `5`, `10`, `456`, `478`, and `479`; it recovered one, one,
and two parts for `456`, `478`, and `479`. Messages `4`, `5`, and `10` returned
`provider_message_missing`, so their zero rows and counter 2 each were initially preserved instead
of being guessed. This first phase produced 28 rows across 16 messages and did not authorize a broad
provider search.

The follow-up recovered the three remaining counter-only messages from the historical persister path
`email/attachments/{account_id}/{imap_uid}`. The exact account-and-UID directory for each of messages
`4`, `5`, and `10` contained exactly two direct files, matching its preserved counter of two. The
recovery rejects a count mismatch, nested/outside-root file, symlink, empty/oversized content, or MIME
type denied by the current attachment policy before persisting anything, and exact legacy evidence
short-circuits the provider path rather than performing a mailbox search.

The first controlled legacy apply recovered all 6 rows/files while each message counter remained two.
A second live apply returned `existing_rows_complete` for all three messages without changing rows,
files, or counters. All six resulting referenced files pass stored-size and SHA-1 integrity checks.
The bounded 19-message target is now fully resolved at **34 attachment rows and counter sum 34**.

The original legacy source files and the duplicate account-2 legacy copies were not deleted or
repurposed. They remain in the preserved pre-existing unreferenced-file inventory for separate
provenance, checksum, retention, and safe-deletion review. Recovery does not treat successful metadata
reconstruction as authorization to purge source or duplicate files.

The broader private-storage inventory is not yet owner/root-complete. All 61 directories are
`www-data` mode `2770`, have group-rwx access/default ACLs, and contain no symlinks. Of 938 legacy
files, 859 are `0660`, while 79 remain `www-data`-owned `0644`; the SSH project user cannot chmod
files owned by the companion runtime. Root/operator must change only those 79 modes to `0660`
without content, ownership, move, or deletion and then repeat inventory plus PHP-FPM/queue
dual-runtime smoke under `HR-2026-08-15-003`.

The original controlled side-effect window created zero remote operations/attempts, rule attempts,
outbound logs, Ticket-domain tickets/messages/events/attachments, notifications, or queued jobs.
Repeating all 13 local recoveries and provider recovery for `456`, `478`, and `479` returned unchanged
results with no duplicate row/file. The follow-up for `4`, `5`, and `10` used only exact local legacy
directories and performed no provider search.

## Permissions And Security

Mailbox View is checked against the placement's active account at request time. Attachment IDs alone
never authorize a download. The enclosing global Email permission middleware returns 403 when that
global ceiling is absent; once inside the Mail route, revoked grants, inactive accounts, local hidden
placements, message/attachment mismatch, missing storage, and path traversal fail as hidden 404s.
Existing Inbox and API permission contracts remain unchanged.

## Verification

- Authorized exact-placement downloads work for representative Inbox, Sent/Archive, and Ticket-linked
  messages and return safe headers/content.
- A missing global Email permission returns 403. Cross-account IDs, revoked/absent mailbox grants,
  inactive accounts, hidden placements, mismatched ownership, missing files, and unsafe paths return
  404 without metadata leakage.
- Reader links bind the exact selected placement and attachment.
- Recovery is bounded, idempotent, handles partial failures, repairs counts, and never dispatches
  inbound rules or provider mutations.
- Snapshot-first recovery and exact non-mutating provider-read fallback are tested; repeated runs
  create no duplicate attachment rows/files.
- Exact legacy account-and-UID directories recover only when count, containment, regular-file,
  symlink, size, and MIME policy checks all pass, and they never trigger provider fallback.
- Both the project worker user and PHP-FPM group can traverse/read/write the repaired Dev directories.

Current automated evidence: the focused `EmailAttachmentAccessRecoveryTest` passes **15 tests / 110
assertions**, including exact legacy-directory recovery and proof that the provider is not contacted.
The earlier adjacent exact provider-read package passed 47 / 321, the broad Email module/inbound
package 155 / 1,308, and the complete Email test directory 347 / 3,030 before this narrow follow-up.
Pint, PHP syntax, and diff checks pass for the follow-up. Controlled Dev recovery, integrity, and
idempotency checks produced the exact filesystem/database results above. Browser, authorization, and
dual-runtime verification remain Pending under the human-review entry.

## Documentation

Update the Email Knowledge article, Email module README, `docs/TODO.md`, and `docs/human-review.md`.
Human review is tracked as `HR-2026-08-15-006` and remains Pending until a named reviewer completes it.

## Done Criteria

- [x] Placement-bound download and hidden mailbox-context denial are implemented and tested.
- [x] The known 16-message / 28-part persistence-failure set is recovered from exact evidence.
- [x] The three additional counter-only messages are recovered from exact count-matched legacy
  account/UID directories without provider search; all 19 targets now have 34 rows/counter 34.
- [x] Storage ACLs, final paths/files/modes, unchanged side-effect ledgers, and idempotent reruns are
  recorded.
- [x] Documentation is updated and `HR-2026-08-15-006` remains Pending for named human review.
