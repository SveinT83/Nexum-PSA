# Feature Slice: Email Mail Conversation Taxonomy Classification

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Move Nexum category and tag assignment from the temporary account/message compatibility scope to the
durable account-scoped Email conversation boundary.

## User-Visible Behavior

Category and tags selected from any message in a Mail conversation apply to the whole conversation
inside that mailbox account. Opening another message in the same conversation shows the same one
primary Email category and the same tags. A correlated copy in another personal, shared, or system
mailbox keeps its own independent classification.

Provider flags, folders, labels, keywords, Ticket classification, and the existing `TD-...`
correlation remain separate and unchanged.

## Scope

- Add Email-owned conversation-classification and classification-event records.
- Reuse active Taxonomy Email categories and Taxonomy tags.
- Resolve classification through `email_conversation_id` in the Mail list and threaded reader.
- Keep View plus Organize as the assignment guard and `taxonomy.manage_tags` as the additional guard
  for creating unknown tag definitions.
- Forward-migrate only unambiguous temporary message-classification snapshots.
- Preserve old message-classification rows/events as compatibility history.
- Record durable migration issues instead of guessing when a source has no unique conversation,
  several message classifications conflict, or an existing conversation assignment differs.
- Add account-authorized API read, replace, and clear operations with hidden-404 mailbox isolation.
- Keep legacy `tag` rules message-scoped, expose explicit `tag_message` and `tag_conversation`
  actions, and add `set_conversation_category` for active Email categories only.

## Out Of Scope

- Promoting legacy `EmailMessage::tags()` routing/history facts into conversation assignments.
- Copying Email category/tags to Ticket category/tags.
- Provider label, keyword, folder, or flag synchronization.
- Bulk classification, cross-account classification, or conversation-wide read/provider actions.
- Dropping the old message-classification tables before parity, rollback-window, and human review.
- Automatic external replies or automatic Ticket routing for later conversation arrivals.

## Data Touched

- New `email_conversation_classifications`.
- New `email_conversation_classification_events`.
- New `email_conversation_classification_migration_issues`.
- Existing `email_message_classifications`, `email_message_classification_events`, `taggables`,
  `email_conversations`, `email_mailbox_placements`, `categories`, and `tags` are read during the
  forward migration; legacy records are not deleted or rewritten.

The forward migration is `2026_08_14_110000_create_email_conversation_classifications.php`. It must
run only after `2026_08_14_105000_harden_email_conversation_identity.php` has reconciled every
unambiguous conversation split.

## Permissions

Viewing classification requires current mailbox View access. Changing category or tags requires
View plus Organize for the conversation's account. Creating an unknown tag definition additionally
requires `taxonomy.manage_tags`. A Ticket link never grants Mail classification access.

## Tests

- Assigning classification from one placement is visible on another placement in the same durable
  conversation.
- Matching conversations in different accounts remain independent.
- View-only users cannot change conversation classification.
- Only active Email categories are accepted, and unknown tag creation keeps its Taxonomy permission.
- Identical temporary message-classification snapshots migrate once with provenance.
- Conflicting or unmapped temporary snapshots are retained and reported without a guessed target.
- Legacy message-level Email rule tags and Ticket classification are not promoted.
- API read/write/clear honors token scope, mailbox View/Organize, and hidden-404 account isolation.
- Explicit conversation rule actions affect the conversation while legacy and explicit message-tag
  actions continue to affect only the matched `EmailMessage`.

## Documentation

Update Email Knowledge, the module README, `docs/TODO.md`, and `docs/human-review.md`.

## Done Criteria

- [x] One durable conversation has at most one classification row and many Taxonomy tags.
- [x] Every visible placement in the same account conversation renders the same classification.
- [x] Cross-account copies never share classification.
- [x] The forward migration preserves source history and durably reports ambiguity.
- [x] Provider and Ticket state remain unchanged.
- [x] Focused migration, Livewire, API, rule, and affected inbound-automation tests pass on Dev.
- [x] Dev migration and human-review handoff are recorded.

## Dev Verification

Migration `2026_08_14_110000_create_email_conversation_classifications.php` ran in batch 87 after
the identity reconciliation. The first attempt was rejected by MariaDB because Laravel's implicit
unique-index name exceeded 64 characters; the three newly created tables were confirmed empty,
removed, and recreated with explicit short index names. Required issue-ledger dates were also made
MariaDB-safe `datetime` fields. Dev had zero temporary message classifications, so the result is
zero migrated targets and zero migration issues; all 462 placements remain conversation-linked.

The focused classification/API/rule and migration suites pass 11 tests / 90 assertions. Inbound
Automation passes 14 tests / 81 assertions. The broad Email, cache/view, Knowledge-push, and queue
verification remains in the final workstream handoff and is not claimed by these focused results.
Human review remains `HR-2026-08-14-008` (`Pending`).
