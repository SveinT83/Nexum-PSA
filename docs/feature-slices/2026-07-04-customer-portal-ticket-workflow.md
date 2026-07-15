# Feature Slice: Customer Portal Ticket Workflow

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Owner: Codex

## Goal

Expose a safe customer-facing Ticket workflow inside the authenticated Customer Portal.

## Scope

- Add explicit Ticket portal visibility fields.
- Keep existing Tickets hidden from the portal until a technician publishes them.
- Add Ticket Settings for whether manually created client tickets default to Unpublished or Published.
- Let technicians override the visibility when manually creating a ticket.
- Make tickets created from the portal visible automatically.
- Let portal users list, create, view, and reply to tickets inside their active Client/Site scope.
- Show only public ticket messages and customer-safe status labels.
- Allow public ticket attachments to be downloaded from portal routes only when attached to public messages.
- Add a one-way technician action to publish a ticket from the portal-hidden state.
- Disable customer replies and Nexum relationship escalation while a client ticket is Unpublished.
- Add tests for hidden-by-default behavior, scope enforcement, portal ticket creation, portal replies,
  internal note hiding, technician publishing, and manual ticket visibility defaults.

## Out Of Scope

- Customer-side ticket closure.
- Customer-visible time, cost, margin, assignment, workflow internals, internal notes, and internal attachments.
- Customer-managed portal invitations.
- Portal notifications and push/PWA behavior.
- Quote, contract, documentation, knowledge, order, invoice, and payment surfaces.

## Done Criteria

- Portal ticket routes are owned by the Ticket module.
- Ticket portal visibility is explicit and hidden by default for existing internal tickets.
- Site-scoped memberships cannot see Client-wide or other-Site tickets.
- Client-scoped memberships can see visible tickets for the Client.
- Portal-created tickets are visible to the creating portal scope.
- Portal replies create public customer messages without sending a customer-reply email back to the customer.
- Technician UI can publish an Unpublished ticket; Published tickets cannot be unpublished from the normal ticket page.
- Unpublished client tickets do not allow customer replies, outbound customer email, portal notifications, or Nexum relationship escalation.
- Narrow tests pass on the Dev server.
