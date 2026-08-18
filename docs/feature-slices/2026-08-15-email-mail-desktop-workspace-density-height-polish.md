# Feature Slice: Email Mail Desktop Workspace Density And Height Polish

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Use the available desktop height for the Mail conversation list and reader, reduce list noise, and
restore an easy-to-find Smart Inbox trigger without moving Smart results ahead of the selected mail.

## User-Visible Behavior

- On the two-column desktop workspace, the conversation list and reading pane have the same height and
  extend to the available viewport bottom before their own content needs to scroll.
- Conversation rows are slightly denser while retaining sender, subject, preview, account/folder
  context, classification, attachments, flags, drafts, and conversation counts.
- The list shows only the current technician's personal **Unread** badge. Provider mailbox-read state
  no longer adds a second unread badge to parent or expanded child rows.
- The Smart Inbox trigger returns above the conversation reader. Opening it reveals the current useful
  Smart results after the complete selected conversation, where the result content already lives.
- Smart results remain closed by default for a new or revisited selection. Mobile and stacked tablet
  layout keep their current natural document flow.

## Scope

- Separate the Smart Inbox trigger from its result region while keeping both in the existing scoped
  Livewire component and retaining current eligibility and action-time checks.
- Add accessible expanded/controlled state and focus/scroll handling between the trigger and result.
- Remove provider mailbox-unread badges only from the center conversation navigation.
- Tighten desktop conversation-row spacing without reducing native controls below the existing touch
  target contract.
- Make both desktop Mail panes flex columns inside one bounded viewport-height grid, with the list and
  reader regions consuming the remaining pane height and scrolling independently.
- Add focused rendering, behavior, ordering, and responsive-contract regressions.

## Out Of Scope

- Provider Seen/Unseen authority, folder unread counts, mailbox-unread filters/actions, personal
  unread persistence, conversation queries, pagination, or provider writes.
- Moving Smart result content ahead of the mail body, changing suggestion eligibility, calling AI
  automatically, or changing durable suggestion/event data.
- Redesigning the mobile workspace, shared authenticated shell, left Mail folder hierarchy, right-bar
  cards, composer workflow, or application-wide layout.

## Data Touched

No database, provider, filesystem, queue, scheduler, API, route, permission, or migration state is
touched. The Smart disclosure state is ephemeral Livewire UI state for the selected component only.

## Permissions

No permission changes. Existing Mailbox View, Organize, Send, AI governance, recorded-agent, target,
and action-time authorization remain authoritative.

## Tests

- The Smart trigger renders before the reader and points to one result region that is visible only when
  opened; result content remains after the selected conversation body and resets closed with a new
  component selection.
- Unavailable Smart surfaces render neither trigger nor result, and forged actions remain covered by
  existing authorization regressions.
- Parent and expanded-child list rows show only personal **Unread** state, while provider unread
  filters/actions and detailed reader state remain available.
- Desktop markup/CSS has equal-height stretch panes and one fill/scroll region per list and reader;
  the stacked breakpoint removes the fixed viewport height and preserves natural mobile flow.
- Existing conversation pagination, exact child selection, Smart Inbox, composer, and Mail module
  regressions remain green.

## Documentation

Update the Email Knowledge article, Email module README, `docs/TODO.md`, and
`docs/human-review.md`. Human review uses `HR-2026-08-15-007` and must remain open until a named
reviewer verifies desktop height, scroll, density, Smart placement, and unchanged mobile behavior.

## Verification

- Focused Smart Inbox, conversation-query, hierarchy, and readability coverage passes **20 tests /
  337 assertions**.
- `EmailModuleTest` plus supervised Smart Inbox cleanup passes **153 / 1,408**.
- The complete Email test directory passes **349 / 3,066**.
- Focused Pint, PHP syntax, Blade cache, compiled-view group-write, and tracked/untracked whitespace
  checks pass. Email Knowledge sync processed one chapter and one article with nothing skipped; no
  external BookStack push was queued in this implementation turn.

## Done Criteria

- [x] Smart trigger and result placement match the approved split layout and are accessible.
- [x] Center-list unread presentation is personal-only without changing provider authority.
- [x] Desktop panes share and use the available height; mobile remains naturally stacked.
- [x] Focused and adjacent automated verification, Blade cache, formatting, syntax, and diff checks
  pass.
- [x] Knowledge/developer docs and Pending human review are updated with exact verification evidence.
