# Feature Slice: Email Mail Conversation Identity Hardening

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Harden the durable account-scoped conversation projection before conversation-level Taxonomy data is
assigned, so normal nested RFC replies stay together and reused identifiers do not silently merge
unrelated mail.

## User-Visible Behavior

A root message and later replies remain one Mail conversation even when a reply points to its direct
parent in `In-Reply-To` and carries the full root-to-parent chain in `References`. Reused
`Message-ID` values inside one account remain separate when conservative message evidence conflicts.
The same identifiers in different accounts always remain separate.

## Scope

- Resolve a reply through uniquely matched account-local referenced messages before creating a new
  conversation.
- Prefer the oldest normalized `References` identifier as the stable fallback root, then
  `In-Reply-To`, then the message's own identifier.
- Refuse to collapse conflicting same-account root messages merely because `Message-ID` matches.
- Forward-reconcile existing placements where one unambiguous referenced conversation is available.
- Keep provider placement, read/flag/folder state, message content, and `TD-...` correlation intact.
- Move placement-bound Ticket conversation pointers only when the target is unambiguous and no
  competing primary relationship exists; durably report conflicts instead of guessing.
- Refresh affected conversation aggregates and remove only empty, unreferenced projection shells.
- Preserve projection shells referenced by durable Smart Inbox suggestions so suggestion/event audit
  evidence cannot cascade away during normal identity reconciliation.

## Out Of Scope

- Cross-account conversation merging.
- Subject-only merging without RFC/header evidence.
- Rewriting canonical Email messages or Ticket evidence.
- Automatic routing of later messages to Tickets.
- Conversation classification, which follows in the dedicated Taxonomy slice.

## Data Touched

- Existing `email_conversations`, `email_mailbox_placements`, and compatible
  `email_ticket_conversation_links`.
- New `email_conversation_correlation_issues` for unresolved reconciliation evidence.
- No provider, message-body, per-user read, category/tag, Ticket evidence, or outbound state changes.

## Permissions

This is an internal Email projection correction. It does not add permissions or widen account
visibility. UI/API queries continue to authorize every placement through `MailboxAccess`.

## Tests

- A root, direct reply, and nested reply project into one account conversation.
- Two incompatible same-account root messages that reuse a Message-ID remain separate.
- The same Message-ID in different accounts remains separate.
- Existing split nested replies reconcile to the root conversation.
- Conflicting referenced conversations or competing Ticket primaries are retained and reported.
- Conversation counters refresh and selected-placement actions remain unchanged.

## Documentation

Update Email Knowledge, module README, TODO, and the human-review register.

## Done Criteria

- [x] Nested RFC threads no longer split at the second reply.
- [x] Reused identifiers do not override conservative conflict evidence.
- [x] Existing unambiguous split placements are forward-reconciled on Dev.
- [x] Ambiguities remain unchanged and have a durable issue record.
- [x] Focused identity, combined classification, and affected inbound-automation tests pass before
  the Taxonomy migration runs.

## Dev Verification

Migration `2026_08_14_105000_harden_email_conversation_identity.php` ran in batch 86 after its
required issue-ledger dates were made MariaDB-safe `datetime` fields. The reconciler reduced 141
conversation projections to 139 by moving the two known unambiguous split placements and deleting
only their empty, unreferenced shells. All 462 placements remain linked, no empty conversation
remains, and no ambiguity issue was needed for the current Dev data.

The focused identity suite passes 7 tests / 50 assertions. The combined conversation-classification
suites pass 11 tests / 90 assertions, and Inbound Automation passes 14 tests / 81 assertions. The
broad focused Mail workstream set passes 112 tests / 993 assertions. Human review remains
`HR-2026-08-14-007` (`Pending`).
