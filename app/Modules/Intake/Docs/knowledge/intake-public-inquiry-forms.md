Intake provides configurable public inquiry forms for requests that arrive before a user has a
Customer Portal account.

Admins manage Intake from the Admin area. Each form has a public URL, lifecycle status, purpose,
language, scope, field list, upload limits, owner, target, and routing policy. New forms start with
an empty field list so admins can add only the fields they need. Form settings are collapsible and
open by default for new forms, while field rows are edited one expanded row at a time. A form must be
published before public users can submit it. Legacy `active` forms remain readable and are normalized
to `published` by migration.

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

Form scope can be global, Client, Service, Sales, Ticket, or Campaign. Client-scoped forms pin
matching to the selected Client before general email, organization-number, website, or exact-name
matching is attempted.

## Submission Flow

Public submissions are stored as Intake submissions first. The submission stores the raw field
payload, normalized mapped fields, form snapshot, visible field snapshot, source URL, referrer, IP
address, user agent, routing result, and event history. The snapshots preserve what the submitter saw
even if an admin later changes the form.

After a successful non-spam submission, Intake applies the form routing mode and then records one
Signal event with source domain `intake` and signal type `intake_submission_received`. The Signal
payload includes the form scope, routing mode, target record if one was created, matched context,
visible field values, and attachment metadata. Signal rules can add follow-up work such as portal
invitations, extra tasks, Sales follow-up, or webhook delivery. Multiple Signal actions may run for
the same submission.

Spam submissions do not create Signal automation events.

The first spam controls are:

- Laravel route throttling.
- A hidden honeypot field.
- Published-form checks.
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

- Configured Client scope.
- ClientUser email.
- Contact email.
- Client billing email.
- Organization number.
- Website host.
- Exact client name.

Routing modes are:

- Manual review: store the submission for staff processing.
- Auto-route known clients: create the target only when a Client match exists.
- Auto-route every valid submission: create the target for every non-spam submission; Sales may
  auto-create a Client only when the form explicitly allows it.

Supported direct targets are:

- Review only.
- Sales lead.
- Ticket.
- Task.

Sales routing creates a Sales opportunity only when a Client is available unless the form explicitly
allows client auto-creation. Optional contact creation uses the Contact module workflow so Contact
records and the legacy ClientUser bridge stay aligned. Ticket routing uses Ticket creation rules,
default queue/type/priority, assignment, SLA, and work context. Task routing uses Task creation
defaults and requires a reviewing user or form owner as creator.

Uploaded files stay in Intake. Target records receive attachment names and metadata in their
description/metadata, but file contents are not silently copied into Sales, Ticket, Task, or Signal.

## Staff Review

Staff can open each submission, download Intake-owned files, inspect normalized and raw values, and
see event history. Review actions support:

- Mark reviewed.
- Route to Sales.
- Route to Ticket.
- Route to Task.
- Link an existing Client, Contact, Ticket, Task, or Sales opportunity.
- Close as spam, duplicate, rejected, or archived with an optional reason.

## Permissions

- `intake.view` opens Intake admin and submission review.
- `intake.manage` creates, edits, publishes, pauses, and archives forms.
- `intake.submission_review` marks submissions reviewed, closes review outcomes, links existing
  records, and routes submissions to Sales, Ticket, or Task.

Admin routes remain protected by the normal tech/admin middleware and route-permission enforcement.
