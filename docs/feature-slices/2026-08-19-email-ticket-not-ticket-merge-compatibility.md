# Feature Slice: Email/Ticket Not-Ticket and Merge Compatibility

Status: In Progress
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 15)
Review ID: `HR-2026-08-16-015`

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

## Implementation Plan

1. **Update `EmailConversation`:** Add `is_ticket_suppressed` flag.
2. **Backend Action:** `SuppressTicketCorrelation` action.
3. **Merge Service Update:** Ensure `TicketMergeService` (if exists) or a new action updates Email links.
4. **Verification:** Test merging tickets with multiple linked conversations.

## Boundary & Risks

- **Boundary:** Focused on suppression and merge; closed-ticket behavior is Slice 20.
- **Risk:** Partial merges leaving dangling links.
- **Mitigation:** Use strict database transactions for all link transfers.
