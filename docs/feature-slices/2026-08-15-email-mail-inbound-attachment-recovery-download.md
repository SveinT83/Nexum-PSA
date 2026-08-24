# Feature Slice: Email Mail Inbound Attachment Recovery And Download

Status: Rework Needed / Partial Recovery
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
attachment count. The restored Dev database baseline had zero attachment rows and only counter sum
6. Recovery froze and previewed one exact 19-message-ID scope before any apply.

The `email/attachments` and `email/raw/2` directory/ACL contract was normalized non-destructively to
`www-data:www-data`, directories `2770`, and group-rwx access/default ACLs. Readiness then reported
`safe=true` and `received_at_schema_safe`.

Under the Email worker lock, local snapshot evidence recovered 13 messages / 24 parts. The same
bounded local apply recovered the three counter-only messages from the historical persister path
`email/attachments/{account_id}/{imap_uid}`. The exact account-and-UID directory for each of messages
`4`, `5`, and `10` contained exactly two direct files, matching its preserved counter of two. The
recovery rejects a count mismatch, nested/outside-root file, symlink, empty/oversized content, or MIME
type denied by the current attachment policy before persisting anything, and exact legacy evidence
short-circuits the provider path rather than performing a mailbox search.

Together, local raw and exact legacy evidence restored **30 rows/files across 16 messages**. The
idempotent rerun returned unchanged and created no duplicate row or file. The four remaining expected
parts belong to messages `456`, `478`, and `479`. Their exact provider-recovery calls stopped at the
fail-closed provider resolver with `dns_answer_set_denied`; no failed call changed the database,
filesystem, or provider. No broad search or alternate endpoint was attempted, and the four parts
remain honestly unresolved rather than fabricated.

The original legacy source files and the duplicate account-2 legacy copies were not deleted or
repurposed. They remain in the preserved pre-existing unreferenced-file inventory for separate
provenance, checksum, retention, and safe-deletion review. Recovery does not treat successful metadata
reconstruction as authorization to purge source or duplicate files.

The current read-only private-storage inventory contains 969 files: `sent_pending` 322 (0 referenced /
322 unreferenced), `raw` 547 (462 / 85), and `attachments` 100 (30 / 70), for 492 referenced and 477
unreferenced. It reports 28 missing raw references, 79 non-private files, 15 duplicate unreferenced
checksum+size groups, and zero unsafe or unreadable files. These results authorize no deletion.

The controlled side-effect window created no provider mutation. Local rerun was unchanged, and the
three blocked provider-recovery calls created no database row, file, provider operation, or remote
mailbox change. Original/duplicate/unreferenced evidence remains preserved.

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
Pint, PHP syntax, and diff checks pass for the earlier follow-up. Controlled post-restore preflight,
local apply, idempotency, fail-closed provider-resolution, and inventory checks produced the exact
filesystem/database results above. Browser, authorization, safe provider recovery, and dual-runtime
verification remain open under the human-review entry.

## Documentation

Update the Email Knowledge article, Email module README, `docs/TODO.md`, and `docs/human-review.md`.
Human review `HR-2026-08-15-006` is Rework Needed until safe recovery of the four blocked parts is
resolved or explicitly deferred and a named reviewer completes the remaining checks.

## Done Criteria

- [x] Placement-bound download and hidden mailbox-context denial are implemented and tested.
- [x] The exact 19-ID preflight and local apply recovered 30 rows/files across 16 messages; rerun is
  idempotent and unchanged.
- [ ] Recover or explicitly defer the four remaining parts for `456`, `478`, and `479` through a
  separately reviewed endpoint-resolution path; `dns_answer_set_denied` must remain fail closed.
- [x] Current inventory, unchanged failure side effects, and the no-deletion boundary are recorded.
- [ ] Complete browser/access/provider recovery checks and named human review under
  `HR-2026-08-15-006`, which is Rework Needed.
