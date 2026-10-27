Availability: the Intake API implementation is verified on Dev before a scoped Main
promotion. Production must deploy the code and additive command migration before using
the new endpoints. A Main merge does not activate provider actions or confirm a release.

## Prerequisites And Access

Use the installation's trusted HTTPS base URL. Internal API calls require an active
internal account and a bearer token created in API management. Token abilities limit
the account's existing permissions; they do not grant new human rights.

| API ability | Native permission | Purpose |
| --- | --- | --- |
| intake.read | intake.view | Workspace, forms, submissions and audit |
| intake.forms.write | intake.manage | Create/edit forms and publication state |
| intake.submissions.review | intake.submission_review | Review, outcomes and existing-record links |
| intake.submissions.route | intake.submission_review | Sales, Ticket and Task handoff |
| intake.attachments.read | intake.view | Private file downloads |

Linking and reading related Client, Contact, Ticket, Task or Sales references also
requires the corresponding native domain read access. A narrow form manager can read
its configuration context and exact saved form without a broad Intake workspace grant.
A narrow reviewer can read the submission needed for its action.

Public form schema and submission do not require an employee token. They expose only
published form content and generic acceptance. Draft, paused and archived forms are
not publicly available. Legacy active forms remain compatible.

## Configure A Form

In the browser open Admin > System > Intake. Use New form, edit the fields and settings,
then save. The API uses the same form validation, field normalization and persistence.

1. Read GET /api/v1/intake/forms/context for valid types, scopes, routing modes and defaults.
2. Read GET /api/v1/intake/lookups/owners, /clients or /services as needed.
3. POST /api/v1/intake/forms with the complete form and ordered fields array.
4. GET /api/v1/intake/forms/{form} using the slug. Keep the response ETag.
5. PUT that URL with the complete replacement definition, an Idempotency-Key and
   If-Match containing the current ETag.
6. POST /api/v1/intake/forms/{form}/toggle with the same required headers to publish
   or pause the form. Read the returned state; a repeated key does not toggle again.

Fields support text, textarea, email, phone, URL, select, multiselect, checkbox, consent
and file. The ordered array controls placement. Widths are 12, 6, 4 and 3; existing
invalid-width browser values retain their full-width normalization. Conditions use
earlier fields, all/any matching and the documented visibility operators. Options and
MIME types use newline-separated options_text and allowed_mime_types_text inputs.
Field IDs must belong to the same form; omitted IDs are matched by existing keys.
Removed fields do not rewrite historical submission snapshots. Extension metadata survives.

Required create/update fields are name, status, target_type, max_files,
max_file_size_kb and at least one active field in fields. Automatic Task routing
requires an active form owner. Client, Service and Campaign scopes require their
matching selector/key. Do not supply unsupported keys or arbitrary stored metadata.

## Public Submission

1. GET /api/v1/public/intake/forms/{form} for the published schema and upload rules.
2. Send JSON fields or multipart fields plus files[field_key][] to
   POST /api/v1/public/intake/forms/{form}/submissions.
3. Supply a high-entropy Idempotency-Key of 32 to 200 characters and reuse it only
   for the same form, field values and file bytes.
4. Treat HTTP 202 and accepted=true as received. They do not prove matching,
   routing, Signal follow-up, email or inbox delivery.

The public response contains only accepted and the configured success message.
Honeypot submissions receive the same generic acknowledgement, store no files and
trigger no Signal. Hidden conditional values and files are ignored server-side.
HTML and API share per-IP read and submit rate limits; changing transport does not
bypass the budget. Browser CSRF protection remains enabled.

A key reused with different data returns 409. Validation returns 422. Storage that
cannot be confirmed returns 503 with a generic message: contact support before retrying;
do not invent another key to bypass an uncertain attempt. No public submission lookup,
internal receipt, matching result, target ID or attachment download is exposed.

## Process A Submission

1. GET /api/v1/intake/context and /api/v1/intake/submissions. Supported filters are
   q, status, form_id, client_id, from, to, page and per_page. Page size is at most 100.
2. GET /api/v1/intake/submissions/{submission} and keep its ETag. The revision includes
   current form/routing policy so a changed form invalidates a stale preview.
3. Inspect /events and /attachments; download only through the nested
   /attachments/{attachment} URL with intake.attachments.read.
4. POST /api/v1/intake/previews with operation, submission_id (or form_slug for a
   form edit), and data. Preview reports intended effects without performing writes.
5. Execute one of the following POST actions with Idempotency-Key and current If-Match:
   reviewed; outcome; link-existing; route-sales; route-ticket; route-task.
6. Read the exact returned state and GET /api/v1/intake/commands/{operation_id} when
   reconciliation is needed. That receipt belongs to the exact actor and token.
   Fresh original permissions are required on every replay/read.

Outcome accepts spam, duplicate, rejected or archived, an optional reason and an
optional duplicate_of_submission_id referring to another submission. A routed
submission may only be marked reviewed or archived. Linking supports client, contact,
ticket, task and sales_opportunity with an exact reference; discover allowed targets
through lookups/link-clients, link-contacts, link-tickets, link-tasks and link-sales.

Direct routing uses the existing native workflow, preserves one target and keeps files
in Intake. Ticket defaults, rules, assignment and SLA remain Ticket-owned; Task creator
selection uses the reviewer/form owner. Contact uses its canonical record and internal
legacy bridge. Do not substitute a Contact person ID for a Ticket ClientUser contact ID.

Sales customer auto-creation uses the existing Clients number authority. With active
Tripletex customer sync it uses a persisted request key and provider numbering, never
a locally invented number. A public contact address is not an invoice address.
New API provider customer creation defaults off through
INTAKE_API_PROVIDER_CUSTOMER_CREATION_ENABLED. Existing provider/automation switches
remain authoritative. A disabled or uncertain attempt stays reviewable: link/create
the Client through its native workflow and reconcile before retrying.

## Results And Troubleshooting

- 201: a form was created. 200: the requested local operation completed or routing was
  skipped; inspect state and routing_result rather than assuming a target exists.
- 202 with state=unknown on an internal command: reconcile the existing attempt.
  Do not generate another key to repeat uncertain provider or notification effects.
- 401: missing, deleted or expired token. 403: current native right or token scope denied.
  Four review actions now consistently require intake.submission_review on web and API.
- 404: missing form/record/file or unavailable public form. Nested file ownership is enforced.
- 409: conflicting key/data or another Intake workflow is running. Retry the same key
  only after reading current state.
- 412: stale If-Match. Reload and review the changed state before a new command.
- 422: invalid fields, scope, reference or outcome. 428: missing required request headers.
- 429: rate limit. 503: additive command migration/dependency or confirmation is missing.

Direct routing runs before the single non-spam intake_submission_received Signal.
Signal remains the follow-up automation owner. Staff can inspect a failed Signal-recording
event; receipt/queue acceptance is not delivery proof. Do not copy Intake uploads into
target records or broaden account/BookStack permissions to make an integration work.

The generated Swagger contains all 23 HTTP operations and their typed requests,
responses, errors, headers and pagination. No delete/purge, public status lookup,
second rule engine or new booking workflow is introduced.
