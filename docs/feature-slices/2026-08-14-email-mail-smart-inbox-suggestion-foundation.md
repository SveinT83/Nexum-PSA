# Feature Slice: Email Mail Durable Smart Inbox Suggestion Foundation

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Turn the current ephemeral, human-triggered Mail AI analysis into durable, reviewable suggestions
bound to one user, one mailbox account, one durable conversation, and one exact source fingerprint.

## User-Visible Behavior

A user with Mailbox View access can explicitly analyze one open conversation for Smart Inbox. The
result is saved in that user's review queue with the proposed effect, reason, confidence,
provenance, and current/stale state. Analysis alone never changes Mail, provider, Ticket, Task, or
Taxonomy data. Suggestions can be dismissed, and later conversation changes make old suggestions
stale instead of silently applying them.

## Scope

- Add durable typed suggestions plus immutable suggestion events.
- Store account, conversation, user, effect type, normalized proposal, explanation/confidence,
  source fingerprint/message IDs, governed AI execution trace, status, and applied reference.
- Reuse the existing governed, explicit Mail AI summary request; do not add background generation.
- Persist normalized results only. Raw prompts, provider responses, HTML, raw source, attachment
  names/content, secrets, and mailbox credentials are never stored in the suggestion tables.
- Add user-scoped queue/count/show/analyze/dismiss/correct API and Mail workspace surfaces.
- Recheck mailbox authorization and the fingerprint whenever a suggestion is viewed or changed.

## Out Of Scope

- Applying any write effect; that follows in the reviewed-action slice.
- Scheduled, bulk, unattended, or arrival-triggered AI analysis.
- Automatic external replies, provider mutations, rule publication, new Taxonomy definitions, or
  arbitrary Ticket/Task writes.
- Persisting or exposing raw model prompts/responses.

## Data Touched

- New `email_smart_inbox_suggestions` and `email_smart_inbox_suggestion_events`.
- Existing account, conversation, placement, message, and governed AI execution/provider/agent
  references are read for scope and provenance; source records are not mutated by analysis.

## Permissions

Generation and review require current user status, API scope where applicable, and Mailbox View for
an active suggestion account. Access is rechecked for terminal as well as pending suggestions; an
applied or dismissed row cannot remain readable after a grant is revoked or its account is disabled.
A Ticket link does not grant Mail access. Revoked suggestions remain minimal audit evidence but are
not readable through normal user endpoints.

The REST API uses `email.read` for queue/count/show/analyze and `email.update` for correction,
dismissal, and later reviewed application. Token abilities remain ceilings and every request repeats
the user, mailbox, conversation, and source-fingerprint checks.

## Tests

- AI policy and mailbox access fail closed.
- Generation performs no business or provider write beyond suggestion/event persistence.
- Raw source, HTML, attachments, filenames, and model payloads are absent from durable rows.
- Account/user isolation and hidden-404 API behavior hold.
- A changed conversation fingerprint marks prior suggestions stale.
- Dismiss/correct are idempotent and append immutable events.
- Duplicate analysis of the same source/effect is bounded and deterministic.

## Documentation

Update Email Knowledge, the module README, `docs/TODO.md`, and `docs/human-review.md`.

## Done Criteria

- [x] Suggestions are durable, typed, source-bound, user/account isolated, and auditable.
- [x] Manual analysis never applies a proposed effect.
- [x] Stale/revoked suggestions cannot be actioned.
- [x] Focused Email/Integration, API, and Livewire review-queue tests pass on Dev.
- [x] Migration, Knowledge source, TODO, and human-review handoff are recorded.

## Implementation And Dev Verification

Migration `2026_08_14_114000_create_email_smart_inbox_suggestions.php` ran in batch 92 after the
verified Undo migration in batch 91. It adds user/account/conversation-scoped durable suggestions and
append-only events; it does not generate suggestions or call a provider during migration.

`/tech/mail` now embeds a user-scoped Smart Inbox review queue for the selected conversation and
placement. The queue can run one explicit analysis, refresh stale/revoked state, show normalized
reason, confidence, provenance, and effect impact, and expose only actions valid for the current
status. The account-scoped REST endpoints provide the same queue, count, show, analyze, dismiss, and
correct boundaries; inaccessible rows use hidden-404 behavior.

The final persistence transaction repeats active-user, active-account, and mailbox View checks after
the governed AI request. Revoking access while that request is in flight therefore produces no new
suggestion or event. Provider/SDK failures are reported to users with a fixed safe message rather
than the raw exception text. Conversation identity reconciliation treats Smart Inbox rows as durable
external references, preserving suggestion and event audit evidence when placements are moved away
from an old projection shell.

Focused foundation coverage passes **10 tests / 106 assertions**. The combined Smart Inbox
foundation, reviewed-apply, review-queue, and supervised-cleanup set passes **32 / 422**, and the
broader focused Mail workstream set passes **112 / 993**. Human review remains
`HR-2026-08-14-012` (`Pending`).
