# Feature Slice: Email/Ticket Conversation Relationship Migration

Status: Rework Needed
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 13)
Review ID: `HR-2026-08-16-013`

2026-08-21 audit: the backfill scaffold calls an absent relationship, chooses an arbitrary first user
as actor, catches failures without a completion gate, and is not dispatched or covered by focused
tests. Existing durable links remain authoritative; the backfill must not run.

## Purpose

This slice implements first-class relationships between Email Conversations and Tickets, ensuring that links are durable, auditable, and independent of provider-side storage.

## Scope

- **Primary/Reference/Capture Links:** Distinguishing between an Email that *is* the ticket, an Email *referencing* a ticket, and an Email *captured* into a ticket.
- **Deterministic Backfill:** Migrating legacy Ticket-to-Mail links based on `TD-...` headers or existing relationship tables.
- **Inbox Preservation:** Linking an email to a ticket does NOT move it out of the source mailbox unless explicitly requested.
- **Audience Isolation:** Linking does not automatically promote the email recipients to ticket participants.

## Technical Design

### New Relationship Table
- `email_ticket_conversation_links` table to store many-to-many or polymorphic links between `EmailConversation` and `Ticket`.

### Link Types
- `primary`: The main conversation that spawned the ticket.
- `reference`: A conversation that mentions the ticket.
- `captured`: A conversation manually linked by a user.

## Implementation Plan

1. **Database Migration:** Create `email_ticket_conversation_links` table.
2. **Backend Logic:** `LinkEmailConversationToTicket` action.
3. **Backfill Script:** A job to migrate existing links to the new structure.
4. **Verification:** Test that linking preserves source Inbox state and correctly categorizes link types.

## Boundary & Risks

- **Boundary:** Focused on the *link* itself; the UI for multi-conversation display is Slice 18.
- **Risk:** Duplicate links or incorrect backfill for high-volume tickets.
- **Mitigation:** Use unique constraints on (conversation_id, ticket_id, link_type).
