Availability: implemented and verified on Dev; source merged to Main in PR #379 on 2026-10-28. Production deployment and exact BookStack article/access read-back are not verified for this delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks.
Pending. These operations have focused Dev tests; production availability is not claimed.

## Prerequisites and API access

Use the active technician's own API identity. In **Admin > System > Integrations > API Management**,
select only the required capabilities below. Existing tokens and user permissions were not changed.
The same user must have the ordinary browser domain rights, including workflow and object checks.
Use a Ticket's TD-... key in {ticket}; use returned numeric IDs for messages, files and ledger rows.

| Operation | Token ability | User permission |
|---|---|---|
| Read complete conversation/file metadata | tickets.messages.read | ticket.view |
| Download a Ticket file | tickets.attachments.download | ticket.view |
| Add an internal note | tickets.notes.create | ticket.note_internal AND ticket.reply_customer |
| Select an existing solution | tickets.solutions.mark | ticket.view plus workflow/solution policy |
| Reply to the customer with files | tickets.reply_customer | ticket.reply_customer |
| Correct time | tickets.time.update | ticket.register_time |
| Suggest invoice text | tickets.time.suggest | ticket.register_time |
| Correct/release reservations | tickets.costs.update / tickets.costs.release | ticket.update |
| Preview/pick reserved stock | tickets.costs.pick | ticket.update AND storage.pick |

## Read messages and attachments

1. GET /api/v1/tickets/{ticket}/conversation returns ordered message content with attachment
   metadata. Follow links.next until null. Per_page defaults to 25 and cannot exceed 100.
2. GET /api/v1/tickets/{ticket}/conversation/{message} reads one exact message. This does not mark
   it read. The older /messages GET remains metadata-only under tickets.read.
3. GET /api/v1/tickets/{ticket}/attachments lists current-message attachments. GET the exact
   /attachments/{attachment} resource to inspect the filename, content type, size and download URL.
4. GET the returned /attachments/{attachment}/download URL using the separate download scope.
   Foreign Ticket IDs, deleted parent messages and missing file content return 404. Internal storage
   paths/disks are not returned. Keep files within the account's existing permitted audience.

## Create a note or customer reply

1. POST /api/v1/tickets/{ticket}/internal-notes with body and a stable idempotency_key (at most
   100 characters). Optional notify_user_id must identify an active internal user. Optional
   mark_as_solution obeys Ticket solution policy. The API fixes type/visibility to internal_note/internal.
2. Send attachments as multipart uploads: no more than five files, each no larger than 20 MiB.
   POST /api/v1/tickets/{ticket}/messages supports the same attachment limits for customer replies,
   alongside existing reply_intent, reply_contact_id and cc validation. The Ticket must already be
   published to the customer; the selected contact must belong to its Client and have an email address.
3. Reuse the same idempotency_key and identical payload/file bytes when retrying an uncertain
   response. A new write returns 201/created:true; an identical replay returns 200/created:false.
   Changed body, recipient, solution choice or files conflict with 409. Deleted keys stay reserved.
4. Read the exact returned message through /conversation/{message}. A persisted reply or queued
   SendTicketReplyEmail is not proof that the recipient's inbox received it. Verify delivery separately.
5. POST /api/v1/tickets/{ticket}/messages/{message}/solution selects an eligible technician reply
   or policy-permitted internal note. Initial seed notes and foreign messages cannot be selected.
   Repeating the same selection does not duplicate solution workflow/events.

## Correct time and request an invoice-text suggestion

1. GET /api/v1/tickets/{ticket}/time-rate-options with tickets.time.read to select a current key.
2. PATCH /api/v1/tickets/{ticket}/time-entries/{timeEntry} with work_date, minutes (1-1440), rate_key,
   invoice_text (at most 2000 characters), and optional note. The server resolves the rate snapshot;
   a caller cannot supply its own price. Read the exact row using the time read API afterward.
3. POST /api/v1/tickets/{ticket}/time-entries/invoice-text-suggestion accepts optional existing_text
   and rate_key. It uses the configured Ticket agent and returns data.text. It does not register time
   or approve billing. Missing/failed agents return 422; no agent is enabled by this API operation.

## Edit, release or pick reserved stock

1. PATCH /api/v1/tickets/{ticket}/cost-entries/{costEntry} with quantity and optional invoice_text/note.
   Only the existing reservation lifecycle is supported. Quantity zero releases the reservation.
2. DELETE the same resource with tickets.costs.release to release pending reserved stock. This
   retains the cost row as released/cancelled, releases Storage and restores converted planned scope.
   Repeating the same successful release does not release stock twice.
3. GET /api/v1/tickets/{ticket}/cost-entries/{costEntry}/pick-preview with tickets.costs.pick.
   Inspect allowed, reason, quantity and on_hand. An allowed preview returns an opaque preview_token
   bound to the actor and current cost/stock snapshot; it expires after ten minutes.
4. POST /api/v1/tickets/{ticket}/cost-entries/{costEntry}/pick with that preview_token. Changed stock,
   changed cost or an expired/foreign token requires a fresh preview (422). Successful picking uses
   the existing stock movement, reservation fulfillment, Economy and workflow actions. Repeating a
   successful pick returns its persisted state without consuming stock again.
5. Read /cost-entries/{costEntry} with tickets.costs.read and verify status, quantity, billing_status
   and reservation ID. Picking and invoice completion are distinct states; a pick does not prove
   that an external invoice has been issued or paid.

## Troubleshooting and operational prerequisites

401 means authentication is required; 403 means missing scope/active user rights; 404 means an
unknown or foreign nested record; 409 means a conflicting retry identity; 422 means validation,
workflow, stock, solution policy or AI availability needs correction. Do not retry a write with a
new key until the existing result has been reconciled. Notification delivery and configured AI
providers retain their ordinary queue/provider prerequisites. No new runtime gate is activated.
No schema migration/frontend build is needed. Promotion and served OpenAPI regeneration belong to
Svein's separately authorized deployment. The employee handbook publication is pending configuration.
