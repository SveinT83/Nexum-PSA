# Feature Slice: Email Mail Selected Conversation List Expansion

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Expose the messages inside the selected Mail conversation directly below its center-list row so a
technician can scan and select an exact email from either the list or the threaded reader.

## User-Visible Behavior

Selecting a conversation in `/tech/mail` automatically expands that one row. Its currently
authorized emails appear as compact indented child rows in the same newest-first order as the
reader. Clicking a child selects that exact provider placement, highlights it in both navigation
surfaces, and keeps every message action scoped to that placement.

## Scope

- Expand only the selected conversation; keep all other conversation rows compact.
- Reuse the already authorized `conversationThread` result instead of querying children for every
  paginated list row.
- Show compact sender, subject, timestamp, folder, personal unread, flag, draft, and attachment
  signals. Detailed provider unread state remains in the reader rather than duplicating list noise.
- Keep the parent conversation row selected while giving the exact child a distinct current state.
- Preserve the bounded legacy-thread compatibility path and its existing Load more behavior.

## Out Of Scope

- Manually expanding several conversations at once.
- New conversation, placement, or personal-state data.
- Bulk conversation actions or automatic read acknowledgement.
- Cross-account conversation merging, provider writes, API changes, or Ticket behavior changes.

## Data Touched

Existing authorized `email_conversations`, `email_mailbox_placements`, `email_messages`, folders,
attachments, and personal read state are read. Selecting a child uses the existing placement-opened
receipt behavior, just like selecting the parent or a reader row. No new persistence model, settings,
migrations, queues, scheduler entries, provider state, or external runtime integration are added.

## Permissions

Child rows use the same selected account-scoped `conversationThread` collection as the reader.
Every placement remains filtered through current mailbox View access. Clicking a child calls the
existing `selectPlacement` boundary, which reauthorizes the exact placement before recording an
opened receipt or exposing message actions.

## Tests

- Selecting a multi-message conversation renders the parent plus indented child rows.
- Child ordering matches the reader and the exact selected child is marked current.
- Clicking an older child selects that placement and keeps the parent conversation expanded.
- Authorized same-conversation context outside the active folder filter may appear, while hidden,
  deleted, inaccessible-account, and unrelated-conversation placements never appear.
- Conversation pagination remains based on parent rows and no children are loaded for unselected
  conversations.

## Documentation

Update Email Knowledge, the Email module README, `docs/TODO.md`, and `docs/human-review.md`.

## Done Criteria

- [x] The selected conversation expands automatically in the center list.
- [x] Every visible child is a compact, keyboard-accessible exact-placement control.
- [x] Selection stays synchronized between the center list and threaded reader.
- [x] Filters, pagination, authorization, and placement-scoped actions remain unchanged.
- [x] Focused Livewire, full Email, Blade, formatting, and diff checks pass on Dev.
- [x] Human review is recorded and remains Pending until explicitly completed by a named reviewer.

## Implementation

`mail-workspace.blade.php` renders one sibling child list immediately below the selected parent row.
It reuses the same authorized, account-scoped `conversationPlacements` collection as the reading
pane, keeps separate native buttons for the parent and children, and calls the existing
`selectPlacement` action for every exact placement. No child collection is loaded for unselected
conversation rows. Legacy selection uses the same stable account-scoped conversation group key as
legacy list grouping, so an authorized child outside the current folder filter keeps its parent
expanded and resetting the bounded 50-message reader window cannot collapse a long thread.

The parent reports the active-view count separately when it differs from the full authorized
conversation count. Child controls expose sender, subject, timestamp, account, folder, the current
technician's personal unread state, flag, draft, and attachment signals without duplicating message
bodies. Provider mailbox unread remains available in the detailed reader, folder counts, filter, and
provider actions. This presentation refinement is tracked by the follow-up desktop-polish slice and
`HR-2026-08-15-007`. Selected and focus states use Bootstrap theme variables and accessible text/ARIA
instead of color alone.

## Verification

- `EmailConversationWorkspaceQueryTest.php`: 6 tests / 81 assertions pass, including full-context
  expansion, exact-placement ordering and selection, hidden-placement isolation, cross-account and
  revoked-grant isolation, durable 25-message rendering, a 55-message legacy out-of-filter
  selection, and unchanged expanded parent pagination across 30 conversations.
- Existing conversation grouping flow in `EmailModuleTest.php`: 1 test / 16 assertions passes.
- Full `EmailModuleTest.php`: 141 tests / 1,193 assertions pass on Dev.
- Targeted Pint passes for the workspace component and both affected test files; PHP syntax and
  `git diff --check` pass.
- `php artisan optimize:clear` and `php artisan view:cache` pass with every compiled Blade file still
  group-writable.
- Email Knowledge sync processed one chapter and one article with nothing skipped; its BookStack
  push was queued.
- `queue:failed` reports one pre-existing `FetchImapAccount` failure from 2026-08-15 10:46. This
  read-only UI slice dispatched no sync job. The separate cross-user private-storage permission
  blocker and its required Operations repair are recorded near the top of `docs/TODO.md`; the failed
  row was neither retried nor deleted.
- Human review `HR-2026-08-15-001` remains Pending.
