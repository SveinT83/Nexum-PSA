Ticket communication is integrated with the Email domain. Tickets can receive inbound email, link inbound replies, send customer replies, send internal technician notifications, and keep outbound email status visible on the ticket.

## Inbound Email To Ticket

Inbound email can become a ticket through Email Rules or default inbound policy.

The current behavior supports:

- Linking to an existing ticket when the subject contains a ticket key such as `TD-2026-000001`.
- Linking to an existing ticket by `In-Reply-To` or `References` headers when they match previous outbound ticket reply `Message-ID` logs, whether the provider stores the IDs bare or inside angle brackets.
- Creating a new ticket from unmatched inbound email.
- Creating tickets for known active client contacts by default.
- Creating Lead tickets for unknown senders unless the Email message was archived or tagged as not-ticket/noise first.
- Inheriting Email tags onto created or linked tickets.
- Copying inbound Email attachments to ticket-owned attachment records.

The main action for new inbound tickets is `CreateTicketFromInboundEmail`. Existing ticket linking is handled by `LinkInboundEmailToTicket`.

## Default Inbound Policy

Inbound processing runs after explicit Email Rules and subject/header linking.

Known active client contacts can create customer-context tickets automatically. Unknown senders become Lead tickets by default. Messages that were archived or tagged as not-ticket/noise should stay out of Ticket.

This keeps normal customer requests from being missed while still allowing Email Rules to suppress noise.

## Mark As Not Ticket

Tickets that should not have become tickets can be returned to Inbox with `Mark as not ticket`.

This action:

- Moves the ticket context back toward Inbox handling.
- Tags related email so matching messages do not automatically become tickets again.
- Helps build operational rules for messages that are useful but not ticket-worthy.

Future Operational Signals should extend this idea for machine-generated emails that should be logged, not treated as spam or work tickets.

## Customer Replies

Technicians can send customer replies from the ticket show page.

Customer replies:

- Are saved as ticket messages.
- Are queued for outbound email after database commit.
- Use the Email account marked as default for the `tickets` scope.
- Render the `tickets/ticket_reply` email template.
- Can target the ticket contact or another active contact for the same client.
- Can include CC recipients.
- Can include attachments.
- Stamp `first_responded_at` the first time a technician sends a public reply.

The CC field accepts manually entered email addresses and shows contact suggestions. Suggestions are
ordered with active contacts from the same client first, grouped by site where available, then global
Contact domain entries. Clicking a suggestion appends that email to the CC field without removing
manually entered addresses.

The queued job is `SendTicketReplyEmail`.

## Internal Notes

Internal notes are visible to technicians and can be used for internal coordination.

Internal notes can optionally notify a selected technician by email. The notification job is `SendTicketInternalNotificationEmail`.

Internal notes may also satisfy workflow requirements when a transition requires an internal note.

## Outbound Status

Ticket show displays the latest outbound email status for each customer reply.

Failures are logged through the Email domain. The send job records missing contact email, missing default account, missing template, and SMTP failure states.

This makes email delivery visible without forcing technicians to inspect queue logs.

## Attachments

Ticket messages can have attachments.

Attachments can come from:

- Manual upload on a ticket message.
- Inbound Email attachments copied when the email is linked to the ticket.

Customer reply attachments are sent with outbound SMTP. Ticket-owned attachment records are stored through the Ticket domain and downloaded through ticket attachment routes.

## Email Template

Ticket replies use the Email template:

- Scope: `tickets`
- Key: `ticket_reply`

The template receives variables such as ticket key, ticket subject, contact name, message body, and technician name.

## Implementation References

Important files:

- `app/Modules/Ticket/Actions/CreateTicketFromInboundEmail.php`
- `app/Modules/Ticket/Actions/LinkInboundEmailToTicket.php`
- `app/Modules/Ticket/Actions/AddTicketMessage.php`
- `app/Modules/Ticket/Actions/StoreTicketAttachment.php`
- `app/Modules/Ticket/Actions/MarkTicketAsNotTicket.php`
- `app/Modules/Ticket/Jobs/SendTicketReplyEmail.php`
- `app/Modules/Ticket/Jobs/SendTicketInternalNotificationEmail.php`
- `app/Modules/Ticket/Actions/UpdateDefaultTicketEmailAccount.php`
