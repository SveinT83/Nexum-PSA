The Sales domain owns sales opportunities, quote preparation, quote communication, and quote
acceptance.

## Quote Workflow

Technicians create a Sales opportunity, prepare a quote draft, add quote lines, and send the quote.
Sending a quote marks the quote version as `sent`, generates the customer-facing secure token link,
queues the quote email when a primary contact email exists, and records Sales activity.

Public quote links remain available for token-based customer access:

- View quote.
- Download quote PDF.
- Accept sent quote.
- Ask a question.

Accepting a quote marks the quote version and quote as accepted, marks the opportunity as won, stores
accepted name/IP/user agent, and records a `quote_accepted` Sales activity.

## Customer Portal

The Customer Portal can show existing sent and accepted quote versions for the active Client scope.
Portal quote pages show customer-safe quote content:

- Quote title and version.
- Intro, scope, assumptions, exclusions, and next-step text.
- Quote lines with quantity, unit price, and totals.
- Ex. VAT, VAT, and inc. VAT totals.
- Expiry and current status.

Portal users can accept sent, unexpired quotes. Acceptance stores the existing quote acceptance fields
and also binds the quote version to the authenticated portal account, membership, and Contact through:

- `portal_accepted_account_id`
- `portal_accepted_membership_id`
- `portal_accepted_contact_id`

Portal users can also send quote questions. Questions create unread inbound Sales activity and write a
CustomerPortal audit event.

Portal quote pages do not expose internal notes, cost price, margin, source IDs, downstream conversion
controls, or internal workflow settings. Site-scoped portal memberships do not see Sales quotes because
Sales opportunities and quote versions are currently client-level records.
