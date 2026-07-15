# Intake Module

The Intake module owns configurable public inquiry forms and their submitted data.

## Ownership

Intake owns:

- Public form definitions and field definitions.
- Public form rendering and submission validation.
- Intake-owned upload storage.
- Submission records, routing results, and event history.
- Admin review and manual routing surfaces.
- A normalized Signal event after each successful non-spam submission.

Target modules own their own record creation rules. The first implemented target is guarded Sales
opportunity creation.

## Upload Handling

Uploaded files are stored as `intake_submission_attachments` on the local disk. Intake stores the
safe path, original filename, MIME type, size, and SHA1 checksum. Files are not silently copied into
Ticket, Sales, or other modules.

## Routing

Sales routing requires a matched Client unless the form explicitly allows client auto-creation.
Contact creation uses the Contact module action so the legacy `client_users` bridge remains
consistent.

## Post-Submit Automation

Intake does not own a separate after-submit rule engine. Successful non-spam submissions record an
`intake_submission_received` Signal with form, submission, matched context, visible field values, and
attachment metadata. Signal rules own follow-up actions such as Ticket creation, Task creation,
Customer Portal invitation, Sales follow-up, and webhook delivery.

Uploaded file contents and storage paths remain Intake-owned and are not copied into Signal payloads.

## Permissions

- `intake.view`
- `intake.manage`
- `intake.submission_review`
