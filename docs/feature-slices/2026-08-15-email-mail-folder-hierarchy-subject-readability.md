# Feature Slice: Email Mail Folder Hierarchy And Subject Readability

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make Mail navigation reflect the provider's real parent/child folder hierarchy and make MIME-encoded
message subjects readable without changing stored mail identity or provider state.

## User-Visible Behavior

The Folders section in `/tech/mail` groups each mailbox's folders into expandable parent, child, and
deeper branches. Branches start collapsed. Opening or closing a branch is remembered per technician
across Livewire remounts, sessions, and devices; selecting a nested folder reopens and remembers its
ancestor path. A passively restored/deep-linked folder does not override an explicit stored collapse;
the closed ancestor is instead marked as containing the current folder until the user opens it.
Message subjects in Mail lists, selected-conversation children, readers, and
Reply/Forward presentation are decoded from common RFC 2047 encoded-word forms into safe readable
text.

## Scope

- Build a View-authorized navigation tree from existing `path`, `parent_path`, and `delimiter`
  projection data without changing the Organize-only folder manager.
- Keep selectable folders as exact existing folder filters and show a non-selectable provider parent
  only when it is needed to contain an active selectable descendant.
- Persist expansion state as one Email-owned row per technician and provider folder, keep it
  account-isolated through the folder relation, and mark a collapsed ancestor that contains the
  current folder.
- Decode complete UTF-8 and common legacy charset Q/Base64 encoded words plus conservative malformed
  or truncated compatibility cases for presentation.
- Keep Blade escaping and normalize folded/control whitespace without treating a subject as HTML.

## Out Of Scope

- Rewriting or backfilling `email_messages.subject`.
- Changing SQL search, rule matching, Ticket-number correlation, conversation identity, fingerprints,
  API payloads, or provider folder hierarchy.
- Moving folders, changing mailbox permissions, aggregating child unread counts, or adding provider
  writes, queues, scheduler work, or generic User preference/API fields.

## Data Touched

Existing accessible `email_accounts`, projected `email_folders`, and `email_messages.subject` values
are read. Migration `2026_08_15_120000_create_email_folder_navigation_preferences.php` adds one
Email-owned `email_folder_navigation_preferences` row per `(user_id, email_folder_id)` when a
technician explicitly opens or closes a branch or selects a nested folder whose ancestor path must
be remembered. Rows cascade with the user or folder and contain no message content. No provider
state, file, queue, or external service is written by this slice.

## Permissions

Folder navigation stays scoped through current Mailbox View access. Folder selection continues to
use the existing selectable-folder authorization boundary. Non-selectable containers are structural
labels and cannot become a folder filter. A preference is written only for a server-resolved folder
inside a mailbox the signed-in technician may currently View. Stored preferences never grant access
and are ignored after mailbox access is revoked. Subject formatting does not widen message access.

## Tests

- Parent, child, grandchild, sibling ordering, expansion, selection, and ancestor reveal.
- Default-collapsed state, remembered open and closed state after a new Livewire instance, independent
  state for two technicians, inaccessible-folder write rejection, unique rows, and cascade cleanup.
- Identical paths in two authorized accounts remain independent; inaccessible accounts never render.
- A referenced non-selectable container renders structurally while stale non-selectable leaves stay
  hidden and cannot be selected.
- Provider unread counts remain folder-local and are labelled as mailbox state.
- UTF-8 Q/Base64, adjacent/folded words, ISO-8859-1, plain Unicode, malformed input, control folding,
  and HTML-like subject text are safely formatted.
- Mail list, conversation child, reader, and Reply/Forward presentation use the friendly subject while
  the stored raw value and identity-sensitive behavior remain unchanged.

Automated Dev verification completed 2026-08-15:

- Folder hierarchy/persistence and subject presentation tests pass with 10 tests and 133 assertions.
- The complete Email module test directory passes with 267 tests and 2,377 assertions, including
  `EmailModuleTest` with 141 tests and 1,206 assertions and the signature-dialog regression with
  1 test and 44 assertions.
- The complete Laravel project suite passes with 1,494 tests and 12,940 assertions.
- Migration `2026_08_15_120000_create_email_folder_navigation_preferences.php` passed SQL pretend,
  ran on Dev in batch 94, and the schema/unique/cascade tests pass.
- Targeted Pint, PHP syntax, compiled Blade cache/permissions, and diff/whitespace checks pass.
- Email Knowledge sync imported one chapter/article with nothing skipped; the BookStack push was
  queued through the normal integration path.

## Documentation

Update Email Knowledge, the Email module README, `docs/TODO.md`, and `docs/human-review.md`.

## Done Criteria

- [x] Normal Mail folder navigation visibly and accessibly reflects provider hierarchy.
- [x] Expansion is durable per technician, and exact folder selection remains account-scoped and
  authorized.
- [x] Existing encoded subjects display readably without a database rewrite.
- [x] Search, rules, conversation identity, API, and stored raw subjects remain unchanged.
- [x] Focused Laravel, Blade, formatting, syntax, migration, and diff checks pass on Dev.
- [ ] Human review is recorded and remains Pending until explicitly completed by a named reviewer.
