# Feature Slice: Email Mail Decoded Subject Search Compatibility

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

> Rework completed, 2026-08-15: Dev verification exposed and repaired an old MariaDB schema defect
> on `email_messages.received_at`. The column had an implicit `ON UPDATE CURRENT_TIMESTAMP` clause,
> so the projection backfills advanced receipt timestamps even though they did not touch
> `updated_at`. Forward migration `121200` removed that clause, recorded the 490-row repair scope,
> restored the 471 timestamps supported by deterministic evidence, and left 19 unresolved candidates
> unchanged rather than inventing dates.

## Goal

Make the readable subject shown in Mail searchable even when the historical provider subject is stored
as an RFC 2047 encoded word, without rewriting identity-bearing mail data or changing existing routing
behavior.

## User-Visible Behavior

Searching `/tech/mail`, the legacy Inbox, or the Inbox API with a decoded subject term such as a
Norwegian word finds authorized messages whose stored provider subject is MIME-encoded. Existing raw
subject, sender, and body searches continue to work. Search results, counts, folders, and pagination
remain limited to mailboxes the current technician or API caller may View.

## Scope

- Add one nullable, derived `email_messages.subject_search` compatibility projection.
- Derive the projection with the same bounded RFC 2047 presentation normalizer used by Mail.
- Backfill existing messages idempotently without intentionally rewriting their raw subject or
  related domain projections, and repair the historical receipt-timestamp side effect through a
  separate forward-only, evidence-audited operation.
- Keep the projection current when an `EmailMessage` subject is created or changed through Eloquent.
- Reuse one parenthesized Email message search scope in the Mail workspace, legacy Inbox, and Inbox API.
- Treat `%`, `_`, and the search escape character as literal user input rather than SQL wildcard
  syntax on every surface.
- Keep the current local SQL search implementation and its existing sender/body behavior.

## Out Of Scope

- Rewriting `email_messages.subject`, `email_conversations.subject`, headers, Ticket data, or provider
  evidence.
- Changing rule matching, TD/SO reference extraction, conversation identity, provider reconciliation
  fingerprints, or the API's returned `subject` value. The five Smart Inbox suggestions falsely
  staled by the receipt-timestamp defect are recovered only after their original source IDs and
  recorded-schema fingerprint match exactly.
- Selecting a final full-text search backend, adding an external index, changing the existing FULLTEXT
  definition, or sending derived mailbox content outside the installation.
- Historical IMAP import, UID cursor recovery, attachment indexing, or search-result snippets/facets.

## Data Touched

Migration `2026_08_15_121000_add_email_message_subject_search.php` adds a nullable 512-character
`subject_search` column to `email_messages` and performs the initial bounded ID-chunk backfill.
Migration `2026_08_15_121100_harden_email_message_subject_search_backfill.php` is a forward-only,
idempotent rebuild that recalculates stale as well as missing projections. Its update is a
compare-and-swap against the row's originally read raw `subject` and `subject_search`, so a concurrent,
fresher subject writer wins while an unrelated state write does not prevent projection repair.

Those migrations write only the derived projection, but Dev's historical MariaDB column definition
nevertheless advanced `received_at` implicitly. Forward migration
`2026_08_15_121200_harden_email_message_received_at.php` removes `ON UPDATE CURRENT_TIMESTAMP`, adds a
bounded audit ledger, and records the Smart Inbox fingerprint schema used by each suggestion. The
operator repair resolves a timestamp only from sane message-header evidence or a conflict-free
conversation boundary. It does not call a provider, replay rules, change Ticket data, or guess an
unresolved value. The projection remains rebuildable and non-authoritative.

## Permissions

No permission or route changes are introduced. Mail search remains downstream of existing Mailbox
View scoping for placements/messages. The derived projection never grants access and is not returned
as a new API field.

## Tests

- UTF-8 Q/Base64 and conservative truncated encoded subjects match decoded search terms.
- Raw encoded subject terms, plain subjects, sender names/addresses, and body text still match.
- New and changed Eloquent messages derive the projection while preserving the raw subject.
- Historical projection backfill is bounded and idempotent; the regression proves the hardened schema
  can no longer advance `received_at` implicitly.
- Receipt-timestamp preview/apply is bounded to the migration ledger, evidence-labelled, idempotent,
  provider-free, and leaves unresolved candidates untouched.
- False-stale Smart Inbox recovery requires exact source IDs plus the recorded fingerprint schema and
  does not reactivate a later legitimately stale suggestion.
- Mail workspace, legacy Inbox, and Inbox API use the same parenthesized search contract.
- Literal `%`, `_`, and `!` terms do not broaden a search as SQL wildcards or an escape sequence.
- Account/folder/Ticket filters, authorization, result counts, and pagination remain intact; inaccessible
  mailbox content never appears through an OR-condition.
- Thirty durable conversations represented by sixty matching placements still paginate as 25 and 5
  conversation leaders, with the newest message selected and a conversation count of two.
- API responses continue to return the stored raw `subject`, not the derived projection.

## Dev Migration And Verification State

- `2026_08_15_121000_add_email_message_subject_search.php` has run on Dev in batch 96.
- `2026_08_15_121100_harden_email_message_subject_search_backfill.php` has run on Dev in batch 97.
- `2026_08_15_121200_harden_email_message_received_at.php` has run on Dev in batch 98. MariaDB now
  reports an empty `EXTRA` value for `received_at`, so the implicit update clause is gone, and the
  repair ledger contains the frozen 490-message scope.
- The later bounded attachment recovery readiness check reports `received_at_schema_safe`, so its
  attachment-count reconciliation passed the hardened-schema gate before writing message rows.
- Preview found 471 deterministically repairable timestamps: 439 from sane header dates and 32 from
  conflict-free conversation boundaries. Apply repaired those 471 rows and left 19 unresolved
  candidates untouched for human review. It recovered exactly five suggestions that had been falsely
  staled by the defect; no later or fingerprint-mismatched stale row was reactivated.
- The post-migration subject audit found 490 messages, zero projection mismatches, and 32 rows where
  the readable projection intentionally differs from the raw encoded subject. Prior claims that all
  receipt timestamps and Smart Inbox fingerprints remained unchanged are withdrawn.
- The current search-surface regression passes with 4 tests / 58 assertions. Together with the
  adjacent conversation-query and Mail navigation/readability regressions, it passes with 13 tests /
  231 assertions.
- Projection coverage passes 9 tests / 56 assertions. Projection plus all three search surfaces pass
  13 tests / 114 assertions; with adjacent conversation-query and navigation/readability regressions,
  the package passes 22 tests / 287 assertions. The receipt-timestamp repair plus adjacent Smart
  Inbox regressions pass 36 tests / 408 assertions; the earlier combined repair/reader verification
  passed 47 tests / 578 assertions. The complete Email test directory passes 347 tests / 3,030
  assertions.

## Deploy Notes

Pause the ordinary/default and `email` queue workers before applying this slice so an old long-lived
worker cannot write through the unsafe schema or stale projection code. Deploy the compatible code
and run migrations in timestamp order: `121000`, `121100`, then `121200`. Verify that `received_at`
has no `ON UPDATE` clause and that the frozen ledger count is correct. Run
`php artisan email:repair-received-at` as a preview, review the evidence counts and unresolved rows,
then run the same command with `--apply` only when the preview is accepted. Never fabricate dates for
the unresolved set.

After the repair, run `umask 0002; HOME=/tmp php artisan optimize:clear` and
`umask 0002; HOME=/tmp php artisan view:cache`, restart or resume every paused worker, and sync the
Email Knowledge article with `php artisan knowledge:sync-docs --module=Email --push`. The migrations
and repair are forward-only because older derived values or corrupted timestamps cannot be restored
safely. No permission seed, scheduler registration, frontend build, provider call/configuration, or
external search service is required.

## Documentation

Update the Email Knowledge article, Email module README, `docs/TODO.md`, and `docs/human-review.md`.

## Done Criteria

- [x] Decoded display subjects are searchable on all three existing Email search surfaces.
- [x] Raw subject and all protected identity/routing/API contracts remain unchanged.
- [x] Existing messages are backfilled initially and future Eloquent writes keep the projection
  synchronized.
- [x] Migration, focused regressions, relevant Email tests, formatting, syntax, and diff checks pass on
  Dev.
- [x] `121200` removes the implicit `received_at` update clause and freezes all 490 repair candidates
  in an audit ledger.
- [x] The bounded repair restores all 471 evidence-supported rows, leaves 19 unresolved rows untouched,
  and reactivates only the five exactly matching false-stale suggestions.
- [x] Human review `HR-2026-08-15-004` is recorded and remains Pending until explicitly completed by a
  named reviewer.
