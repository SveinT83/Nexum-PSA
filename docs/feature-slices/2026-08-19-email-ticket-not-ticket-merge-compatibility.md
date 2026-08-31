# Feature Slice: Email/Ticket Not-Ticket and Merge Compatibility

Status: Done On Dev / Human Review Pending
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 15)
Review ID: `HR-2026-08-16-015`

2026-09-01 completion: Dev now stores account-scoped durable conversation suppression and checks it
before every Ticket correlation/default-create path. Mail and Ticket actions require current ordinary
Organize access, never mutate provider state, and replace the former broad sender+subject rule.
Ticket merge now freezes a server-verifiable preview, locks all selected Tickets in one transaction,
reauthorizes affected Mail links, deduplicates links, lets `primary` and privacy-preserving `internal`
win conflicts, canonicalizes pending correlation evidence, and retains retired `TD-...` keys as
aliases. Migration `2026_08_31_130000` is applied on Dev. Automated verification passes; controlled
browser/provider review remains Pending under `HR-2026-08-16-015`.

## Purpose

This slice handles the boundaries of Ticket correlation: marking conversations as "Not a Ticket" (suppression) and ensuring compatibility when Tickets or Conversations are merged.

## Scope

- **Conversation-Scoped Suppression:** Users can mark a conversation as "Not a Ticket" to stop future incoming messages from auto-routing to any ticket.
- **Merge Compatibility:** Handling Ticket merges (moving all conversations from source to target ticket) and potentially Conversation merges (rare but possible).
- **No Provider Deletion:** Suppression or unlinking never deletes the message from the provider mailbox.
- **Retired-Key Aliases:** Ensuring that if a Ticket key changes or is merged, the old evidence still points to the correct (new) target.
- **Target Reauthorization:** Every link change must be authorized against the current user's mailbox and ticket access.

## Technical Design

### Suppression
- `email_conversation_suppressions` table or a column in `email_conversations`.
- `InboundEmailCorrelationService` checks suppression before auto-routing.

### Merge
- Transactional update of `email_ticket_conversation_links` when a Ticket is merged.
- Recording the merge in the audit trail (metadata).

## Implemented On Dev

1. `email_conversation_ticket_suppressions` stores one reversible, account-scoped decision per durable
   conversation without copying content or recipient values.
2. `SuppressEmailConversationTicketCorrelation` reauthorizes ordinary Organize access, tags current
   messages locally, unlinks the matching Ticket relationship, and leaves provider placement/read
   state unchanged.
3. `ticket_key_aliases` preserves correlation from a merged Ticket key to the surviving Ticket.
4. `MergeTickets::handleMany` validates the browser snapshot after row locking and performs all
   selected merges atomically, including Email-link deduplication and conflict canonicalization.
5. Focused tests cover future-message suppression, provider-state preservation, strongest role and
   audience resolution, retired-key correlation, and stale-preview rejection.

## Boundary & Risks

- **Boundary:** Focused on suppression and merge; closed-ticket behavior is Slice 20.
- **Risk:** Partial merges leaving dangling links.
- **Mitigation:** Use strict database transactions for all link transfers.
