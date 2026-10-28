Availability: Dev implementation verified on 2026-10-20; scoped Main merge approved by Svein Tore on 2026-10-28. Production deployment and BookStack publication are separate, unverified steps for this API delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks; Main approval does not mark them performed.

## Merge and lifecycle

1. GET /api/v1/tickets/{ticket}/merge-suggestions to inspect existing suggestions, or dismiss a pair with the documented dismissal endpoint.
2. POST /tickets/merge with target_ticket_id, source_ticket_ids, optional reason and a stable idempotency_key. The approved policy permits different Clients and requires no merge preview. All source/target and current mailbox-organize checks still apply.
3. Read the target and each source's lifecycle state. Tasks follow the target's Client/Work Context and retain actual-time history. Active stock reservations follow the target without new stock movements. Unallocated pending time detaches foreign-customer contract links while retaining its recorded price; allocated billing evidence keeps its original customer/contract.
4. Existing Sales/Knowledge/Relationship records retain their native ownership/history rules. Review complex commercial cases explicitly; merging does not approve a quote, settle billing, publish documentation or resend a prepared email.
5. Explicit deletion and Not-Ticket classification keep their own current snapshot/readiness guards. The no-preview decision applies to merge. Archived detail, conversation, files and history remain readable under their existing scopes; archived writes are rejected.

## Reply in a selected source email conversation

1. GET /api/v1/tickets/{ticket}/email-relationships using Ticket and Email read grants. Only permitted active source relationships are returned.
2. POST /email-relationships/{relationship}/reply-draft with the documented reply/reply_all selection and a stable idempotency_key. Current Ticket reply permission, publication policy, mailbox view/send rights and provider binding are checked again on replay.
3. Read the communication receipt and returned draft_public_id. Follow the returned native Mail draft, preview, send, submission and reconciliation links; native Mail scopes, lease and confirmation rules continue to apply.
4. Read back the exact submission. Accepted is different from SentReconciled, and neither establishes inbox delivery. An exact preparation retry returns the bound original draft/receipt even after send, rather than creating a second draft.

## Relationship escalation

1. GET /api/v1/tickets/{ticket}/relationship-targets with tickets.relationships.read and the native relationships.escalate permission. Choose a currently active, linked provider destination.
2. POST /relationships/escalate with the documented destination and a stable idempotency_key. The server reserves a durable receipt before contacting the provider and retains credential custody.
3. GET /relationship-escalations/{escalation} and /relationship-links. A synced link and completed receipt indicate the native result. A failed_pre_send result means the request was not successfully submitted; an unresolved/reserved result must be reconciled before resending.
4. A connection failure, unknown provider result or crash after submission must not trigger blind automatic resend. Use the original receipt/link and have the authorized Relationship operator verify the provider record and reconcile the local attempt before a new send. API responses omit provider credentials, diagnostics and arbitrary link metadata.

## Documentation follow-up and SLA

POST /api/v1/tickets/{ticket}/documentation-requests with a stable idempotency_key and optional reason, then GET the exact request. Knowledge owns review, approval, publication and BookStack read-back. completed and publication_read_back_at must be verified separately.

GET /api/v1/tickets/reports/sla requires tickets.reports.read and report.view. Select period 7, 30, 90 or all, context client, internal or all, and optional overdue limit 1-100. The report uses the same stored Ticket timestamps as the browser's Ticket SLA report.

## Customer Portal account flow

1. Authenticate through the installation's supported path as the Portal user's own active account. These operations reuse Sanctum/current Portal account, contact, membership and Site visibility; they do not introduce a login/token-issuance flow or internal technician rights.
2. GET /api/v1/portal/tickets/context with portal.tickets.read. Choose a valid membership_id and pass it as the documented selector. An invalid selected membership fails rather than falling back to another customer.
3. GET /portal/tickets/sites and /portal/tickets. POST /portal/tickets with the allowed site_id, subject, description and idempotency_key using portal.tickets.create.
4. GET the Ticket and public messages. POST a public reply with body and idempotency_key using portal.tickets.reply. Internal visibility, author, metadata and technician fields are not writable.
5. GET public file metadata and the exact nested download using portal.tickets.attachments.download. Ticket, message, attachment and current membership must all agree. Internal notes, costs, work logs and hidden Ticket data are not included.

## Troubleshooting

Use the error body and current read-back before retrying. 401 indicates missing authentication; 403 indicates a scope/current actor or membership denial; 404 protects foreign nested/private objects; 409/422 can indicate a conflicting key, stale state, unavailable provider or native policy. For an unresolved provider attempt, retain the receipt and reconcile it. Do not broaden another identity's permissions or copy administrator credentials to defeat the failure.
