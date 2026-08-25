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

Historical inbound messages may already contain the scalar Ticket pointer and exact Ticket-message
source evidence while missing Mail's durable conversation-link row. Administrators repair only this
proven compatibility gap through Mail's separate bounded preview/apply command. It requires one
named active human with both Mail maintenance permissions and `ticket.update`, blocks competing or
stale Ticket/conversation/audience evidence, and records the exact operator. The repair inserts the
missing Mail-owned primary relationship only: it does not create another Ticket message, change
Ticket events/tags/classification/timestamps, move or mark mail, run Ticket/Email rules, notify or
publish. `TD-...` correlation and ordinary Ticket access remain unchanged, and Ticket access still
does not grant access to the source mailbox. Shared-data apply remains gated by human review
`HR-2026-08-16-013`.

The queue processes no more than 25 frozen items at a time. If dispatch of the next page fails after
one page commits, the old run records a terminal continuation-dispatch failure instead of looking
active forever. Its remaining rows are not guessed or skipped: an administrator creates and reviews
a new preview, which treats committed links as already mapped and resumes only proven ready items.

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

Client tickets must be Published before a technician can use `Reply to contact`.
Unpublished client tickets are intentionally silent externally: technicians can add internal notes,
but Nexum does not queue outbound customer reply email from the normal ticket composer until the
ticket has been Published.

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
limited to other active contacts connected to the Ticket client. The Ticket contact and the currently
selected reply recipient are omitted, and changing the reply recipient updates the compact suggestion
list. Suggestions stay hidden until the CC field is focused or clicked. Clicking a suggestion appends
that email without removing manually entered addresses or adding a duplicate.

The queued job is `SendTicketReplyEmail`.

### Customer Reply API

Trusted coordinators can use `POST /api/v1/tickets/{ticket}/messages` with the dedicated
`tickets.reply_customer` ability. The authenticated user must also be active and hold the normal
`ticket.reply_customer` domain permission. The Ticket must already be Published, and the selected
reply Contact must be active, have an email address, and belong to the Ticket Client.

Every request requires a Ticket-scoped `idempotency_key`. The first successful request stores one
public, user-authored `customer_reply`, queues one `SendTicketReplyEmail`, and runs the established
portal notification, relationship sync, event, first-response, and workflow behavior. An identical
retry by the same actor returns the original message without repeating those side effects. Reusing
the key for another payload or actor, or after deleting the original message, returns HTTP 409.

`reply_intent: send_solution` marks the public technician reply with the established solution
metadata. It does not bypass workflow decisions: the coordinator still reads the allowed
transitions, moves the Ticket to Resolved, and calls the guarded close endpoint.

`external-messages` is not an alternative outbound path. It remains reserved for inbound external
synchronization, records external authorship, suppresses the normal reply email, and strips
untrusted solution or reply-intent metadata.

## Workflow Status Updates

A workflow next-step transition can optionally notify the customer after the transition succeeds.
The workflow administrator chooses Email, Customer portal, or both and may enter a short
customer-facing message. Email uses the selected active Ticket template; the default is
`tickets/ticket_status_update`.

Only Published Tickets can produce these customer updates. If a transition succeeds while the
Ticket is Unpublished, the Ticket remains in its new step but the notification is recorded as
skipped. It is not sent later merely because the Ticket becomes Published.

The public status-update message contains the previous and current reporting status plus the
configured customer message. It does not expose internal note content, internal workflow step
names, requirement details, costs, or other internal context. Updates triggered by an internal
note therefore tell the customer only the approved status information.

The queued job is `SendTicketWorkflowCustomerUpdate`. It records Email delivery in the normal
Email logs and delivery metadata on the public Ticket message. Missing contact data, Email account,
template, or SMTP service is logged without rolling back the workflow transition. Repeated API
idempotency keys do not create or queue another update. Customer portal delivery is database-only
inside this workflow path so selecting both channels cannot send two Emails.

## Nexum Relationship Public Replies

When a ticket is linked to an active Nexum relationship, public customer replies
are also sent to the remote Nexum ticket through the Relationship module after
the local database commit. The normal ticket email job still runs, so customer
email delivery remains the fallback communication path if remote API sync fails.

Tickets must be Published before they can be escalated to a Nexum relationship, and relationship
message/status sync checks the Published state before sending outbound updates. This keeps
Unpublished tickets from being pushed to an external Nexum portal.

Relationship sync sends only public reply content and selected attachments
allowed by the relationship attachment policy. It does not send internal notes,
assignment, workflow internals, time, cost, or margin data.

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

Ticket customer communication uses these Email templates:

- `tickets/ticket_reply` for a technician-authored customer reply.
- `tickets/ticket_status_update` for a workflow-generated customer status update.

The reply template receives variables such as Ticket key, Ticket subject, contact name, message
body, and technician name. The status-update template receives Ticket key, Ticket subject, contact
name, previous status, current status, optional status message, and technician name.

## Implementation References

Important files:

- `app/Modules/Ticket/Actions/CreateTicketFromInboundEmail.php`
- `app/Modules/Ticket/Actions/LinkInboundEmailToTicket.php`
- `app/Modules/Ticket/Actions/AddTicketMessage.php`
- `app/Modules/Ticket/Actions/StoreTicketAttachment.php`
- `app/Modules/Ticket/Actions/MarkTicketAsNotTicket.php`
- `app/Modules/Ticket/Jobs/SendTicketReplyEmail.php`
- `app/Modules/Ticket/Jobs/SendTicketInternalNotificationEmail.php`
- `app/Modules/Ticket/Jobs/SendTicketWorkflowCustomerUpdate.php`
- `app/Modules/Ticket/Support/TicketWorkflowCustomerNotificationPolicy.php`
- `app/Modules/Ticket/Actions/UpdateDefaultTicketEmailAccount.php`
