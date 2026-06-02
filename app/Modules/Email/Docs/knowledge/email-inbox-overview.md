The Email Domain owns inbound email accounts, inbound message storage, inbox triage, email rules,
email templates, account health checks, and IMAP polling.

The Tech inbox is available at `/tech/inbox`.

Admin email account settings are available under `/tech/admin/settings/email/accounts`.

## Inbox Rules

Inbox only shows messages that are not linked to a Ticket.

Messages linked to tickets have `ticket_id` set and are no longer treated as unrouted inbox work.

Technicians can mark an inbox message as spam. This:

- Tags the message with `spam`.
- Archives the message locally.
- Creates or updates an inbound email rule so future matching messages are tagged and archived.

## API

Email exposes first-slice Inbox API routes under `/api/v1/email/inbox`.

Implemented scopes:

- `email.read`: list and view unrouted inbox messages.
- `email.update`: mark inbox messages as spam and queue inbox polling.

Implemented routes:

- `GET /api/v1/email/inbox/messages`
- `GET /api/v1/email/inbox/messages/{message}`
- `POST /api/v1/email/inbox/messages/{message}/spam`
- `POST /api/v1/email/inbox/poll`

`GET /api/v1/email/inbox/messages` supports:

- `q`: search subject, from name, from email, and plain text body.
- `state`: filter by message state.
- `account_id`: filter by email account.
- `from_email`: exact sender filter.
- `per_page`: page size.

The API does not expose raw storage paths or email account secrets.

`POST /api/v1/email/inbox/poll` queues `FetchImapAccount` jobs for active accounts. It does not run
IMAP polling inside the HTTP request.

Sending email is intentionally not part of this API slice. Outbound email must wait for the shared
send-email component so tickets, inbox, contacts, and future modules use the same sending flow.
