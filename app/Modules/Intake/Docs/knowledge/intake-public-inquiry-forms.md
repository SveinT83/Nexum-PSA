Intake provides configurable public inquiry forms for requests that arrive before a user has a
Customer Portal account.

Admins manage Intake from the Admin area. Each form has a public URL, status, field list, upload
limits, owner, target, and routing policy. New forms start with an empty field list so admins can add
only the fields they need. Form settings are collapsible and open by default for new forms, while
field rows are edited one expanded row at a time. A form must be active before public users can
submit it.

Select and multi-select fields use editable option rows in the form builder. File-specific limits
are shown only for file fields. Field mapping is optional; unmapped fields remain available on the
Intake submission for staff review without routing into a target record field.

Field rows include Required and Visible on form controls at the bottom. Visible on form lets admins
hide a field from the public form without deleting its definition.

Field rows can be dragged into the preferred order, and each field can be set to full, half, third,
or quarter width. These layout controls affect visual placement on the public form only; they do not
change field keys, mapping, submission storage, or routing.

Field settings can make a field conditional. Conditions are configured on the field that should be
shown or hidden, using answers from fields above it in the form. Hidden required fields are not
required until they become visible, and hidden values are ignored during submission mapping and file
storage. The public submit button label can also be customized from the form builder.

## Submission Flow

Public submissions are stored as Intake submissions first. The submission stores the raw field
payload, normalized mapped fields, source URL, referrer, IP address, user agent, routing result, and
event history.

After a successful non-spam submission, Intake records one Signal event with source domain `intake`
and signal type `intake_submission_received`. Signal rules decide what happens next, such as
creating a Ticket, creating a Task, sending a Customer Portal invitation, creating Sales follow-up,
or queueing a webhook. Multiple Signal actions may run for the same submission.

Spam submissions do not create Signal automation events.

The first spam controls are:

- Laravel route throttling.
- A hidden honeypot field.
- Active-form checks.
- Server-side validation.

When the honeypot is filled, the submission is recorded as spam and uploaded files are not stored.

## File Uploads

Upload fields are configured per form field and can override the form default for file count, file
size, and allowed MIME types. Uploaded files are Intake-owned until a later approved target handoff
explicitly consumes them.

Each stored attachment records:

- Disk and path.
- Safe filename and original filename.
- MIME type.
- Size.
- SHA1 checksum.
- Source field metadata.

Staff can download stored files from the protected Intake submission detail page.

## Matching And Routing

Intake tries to match submissions to existing customer context by:

- ClientUser email.
- Contact email.
- Client billing email.
- Organization number.
- Website host.
- Exact client name.

Sales routing creates a Sales opportunity only when a Client is available. If no client is matched,
routing is skipped unless the form explicitly allows client auto-creation. Optional contact creation
uses the Contact module workflow so Contact records and the legacy ClientUser bridge stay aligned.

Legacy Sales routing remains available for forms with target `Sales lead`. New after-submission
automation should be configured in Signal rules so Intake does not grow a second rule engine.

## Permissions

- `intake.view` opens Intake admin and submission review.
- `intake.manage` creates, edits, and enables or disables forms.
- `intake.submission_review` marks submissions reviewed and routes them to Sales.

Admin routes remain protected by the normal tech/admin middleware and route-permission enforcement.
