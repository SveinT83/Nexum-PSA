The Email Domain owns inbound email accounts, inbound message storage, inbox triage, email rules,
email templates, account health checks, and IMAP polling.

The Tech inbox is available at `/tech/inbox`.

Admin email account settings are available under `/tech/admin/settings/email/accounts`.

## Outbound Defaults And Templates

Email accounts can be marked as default senders for scoped workflows. Current scopes are `tickets`,
`sales`, `marketing`, and `alerts`. The `marketing` scope is used by the Marketing domain as the
default campaign sender, while each future campaign can still override the sender account.

Email templates are owned by the Email domain and managed from the Templates hub. Templates support
the `marketing` scope so campaign emails can reuse the shared renderer instead of storing separate
template copies in Marketing.

Ticket workflows use the same Email template system. The default
`tickets/ticket_status_update` template is available for customer-facing workflow status changes
and receives Ticket key, subject, contact, previous status, current status, the configured customer
message, and technician name. Workflow administrators choose an active Ticket template on each
transition. Delivery is queued only after a successful transition, and only when the Ticket is
Published. Missing contact/account/template data and SMTP failures are recorded in Email logs
without rolling back the Ticket transition.

The renderer injects company branding variables such as `company_name`, `company_logo_url`,
`brand_primary`, `brand_secondary`, `brand_accent`, `support_email`, and `website`. HTML bodies are
wrapped in a simple brand-aware email layout unless the template already contains a complete HTML
document. Plain text output remains separate and readable.

The default template seeder creates a `marketing/marketing_campaign_default` template with branded
HTML, plaintext fallback, clear recipient/company placeholders, and an `unsubscribe_url`
placeholder. Campaign-specific marketing copy is edited directly in each campaign email snapshot;
the default template does not use ambiguous campaign heading, intro, body, or call-to-action
variables.

## Inbox Rules

Inbox only shows messages that are not linked to a Ticket.

Messages linked to tickets have `ticket_id` set and are no longer treated as unrouted inbox work.

Before inbound ticket rules run, Email classifies common machine responses and vendor notifications
into the Signal domain. Hard bounces, soft bounces, automatic replies, out-of-office replies,
unsubscribe requests, and recognized vendor update notices such as QNAP firmware/security messages
are recorded as Signal records, archived locally, and skipped by normal ticket routing. Signal rules
can then suppress marketing email, tag contacts or clients, create follow-up tickets, emit derived
signals, or call webhooks.

Email Rules can also use an explicit `emit_signal` action for selected inbound messages. This is
opt-in and should be used only when the email itself is a useful operational event, such as a vendor
notice, monitoring alert, or security notification. Email remains responsible for parsing, tagging,
archiving, linking replies, and deciding whether a normal message becomes a ticket. Signal owns the
cross-module follow-up after the explicit handoff has created a normalized Signal record.

Email rules have an explicit routing phase. `normal` is the default and preserves the existing
order: deterministic/machine/AI classification runs first, followed by Email rules and ordinary
Ticket routing. `preclassification` is opt-in for narrow, deterministic handoffs that must run before
the generic classifier. A matching preclassification rule can stop later classification and Ticket
routing; nonmatching messages continue through the unchanged normal flow.

After inbound classification and routing completes, Email calls the Notification-owned inbound
alert dispatcher. Notification decides whether the linked Ticket owner or explicit inbox/triage
subscribers receive Customer reply on my Tickets or New inbound Email alerts. That dispatcher is
idempotent by EmailMessage and user, so repeated rule processing does not create duplicate
notifications.

Email remains responsible for message storage, state, spam/archive behavior, rule execution, and
Ticket routing. Notification owns channel preferences, Web Push payloads, device delivery,
canonical notification read state, and source read synchronization.

## Trusted Sender Authentication

A visible `From` address is untrusted input and is never sufficient evidence for automatic supplier
processing. Admins must configure both exact trusted `Authentication-Results` authserv IDs and
exact trusted receiving hops in Email Configuration. The first `Received` header must name one of
those hosts after `by`; an authserv ID alone is never trusted. Configure only receiving
infrastructure that removes untrusted inbound `Authentication-Results` headers before adding its
own result.

Both lists may be left empty to disable trusted sender authentication. A missing or empty list on
either side fails closed: ordinary messages continue through the existing Email and Ticket flow,
while supplier-order automation receives an empty, unauthenticated trust snapshot.

Email parses only bounded `Authentication-Results` values after that paired trust boundary. It
emits a compact `trusted_auth` snapshot with these canonical keys:

- `authentication_passed`, `aligned`, and `authserv_id`.
- `authenticated_supplier_identity` and `authenticated_supplier_domain`.
- Canonical `spf`, `dkim`, and `dmarc` results.

Raw headers are not copied into Signal. A trusted receiver may still report authentication that is
failed or not aligned; those facts remain useful for review and shadow processing. Active automatic
supplier-order gates must require both `authentication_passed=true` and `aligned=true` and repeat
their hard checks in Storage.

Technicians can mark an inbox message as spam. This:

- Tags the message with `spam`.
- Archives the message locally.
- Creates or updates an inbound email rule so future matching messages are tagged and archived.

## Inbound HTML Safety

Inbound email HTML is untrusted content.

When a message is stored, Nexum sanitizes the HTML body before it is saved in
`body_html_sanitized`. The sanitizer keeps common readable email markup such as paragraphs, links,
tables, emphasis, and images, but removes active content such as scripts, iframes, event handlers,
forms, embedded objects, and unsafe URL schemes.

Inbox views and API responses must use the sanitized body, never raw email HTML.

## Attachment Safety

Inbound attachment persistence is controlled from Email Configuration. Admins set the maximum
attachment count per message, maximum size per attachment, and an allowlist of MIME types. Filenames
are sanitized before paths are built, and each accepted attachment gets an `EmailAttachment` row
with actual size, detected MIME type, SHA-1 checksum, disk/path, inline state, and content ID.

Unsupported, oversized, excess, unreadable, or unwritable attachments are skipped and logged without
failing the stored email. Deterministic position/checksum paths make attachment persistence safe to
retry without creating duplicate rows.

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

Automatic fetching is scheduled through Laravel's scheduler. The scheduled `email.poll` job queues
account fetch jobs and records the `email_last_poll_run` heartbeat when it starts a real poll cycle.
The Email Configuration page shows active account count, ingest pause state, latest successful fetch,
account errors, database queue backlog, failed jobs, and scheduler heartbeat so operators can
distinguish account, scheduler, and queue-worker problems.

Inbound storage is idempotent by `account_id`, mailbox, and IMAP UID. Polling checks soft-deleted
messages too, because the database unique key still reserves those UIDs. `StoreInboundMessage`
also recovers duplicate-key races between workers: active duplicates skip storage and can safely
re-run inbound rules, while soft-deleted duplicates are ignored so locally hidden messages are not
re-imported.

The first automatic poll stores the current INBOX `UIDVALIDITY` and a forward-only `UIDNEXT - 1`
baseline without importing messages that already existed. Later polls fetch the oldest bounded batch
after the greater of that baseline and the highest stored UID, regardless of Seen state. This drains
bursts larger than one batch without turning historical unread mail into Nexum tickets. Already-stored
and soft-deleted UIDs are removed before selection, fetch jobs are serialized per account, and the
database unique key remains the final race boundary. If `UIDVALIDITY` changes, automatic ingest stops
and records an account error until an operator explicitly re-baselines the mailbox; it never guesses
that reused UIDs are new mail.

Nexum captures header evidence from the original Webklex raw header block. Folded values are
unfolded, but repeated `Received` and `Authentication-Results` fields keep their top-to-bottom order
so the configured first receiving hop can be verified. Missing, malformed, or untrusted header
evidence never establishes sender trust and must lead to review rather than an automatic write.

Sending email is intentionally not part of this API slice. Outbound email must wait for the shared
send-email component so tickets, inbox, contacts, and future modules use the same sending flow.
