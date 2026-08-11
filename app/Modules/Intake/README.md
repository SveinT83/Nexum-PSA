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

Target modules own their own record creation rules. Intake can hand off to Sales, Ticket, and Task
through the owning module actions, and can link existing Client, Contact, Ticket, Task, or Sales
records during staff review.

## Upload Handling

Uploaded files are stored as `intake_submission_attachments` on the local disk. Intake stores the
safe path, original filename, MIME type, size, and SHA1 checksum. Files are not silently copied into
Ticket, Sales, or other modules.

## Routing

Forms store purpose, language, scope, target, and routing mode in metadata. New or updated forms use
the lifecycle states `draft`, `published`, `paused`, and `archived`; legacy `active` rows remain
readable and are normalized by migration.

Routing modes are:

- Manual review.
- Auto-route known clients.
- Auto-route every valid submission.

Sales routing requires a matched Client unless the form explicitly allows client auto-creation.
Contact creation uses the Contact module action so the legacy `client_users` bridge remains
consistent. Ticket routing uses the Ticket module action, so defaults, SLA, assignment, rules, and
work context stay Ticket-owned. Task routing uses the Task module action and requires either a
reviewing user or form owner as creator.

Client-scoped forms pin matching to the configured Client. This prevents a public submission on a
client-specific form from being matched to a different Client because of an email or name collision.

## Post-Submit Automation

Intake does not own a separate after-submit rule engine. Successful non-spam submissions record an
`intake_submission_received` Signal with form, submission, matched context, visible field values, and
attachment metadata. Direct Intake routing runs first, so the Signal payload includes any target
record created during submission handling. Signal rules own extra follow-up actions such as Customer
Portal invitation, additional tasks, Sales follow-up, and webhook delivery.

Uploaded file contents and storage paths remain Intake-owned and are not copied into Signal payloads.

## Permissions

- `intake.view`
- `intake.manage`
- `intake.submission_review`
