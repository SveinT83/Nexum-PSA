# Feature Slice: Public Inquiry Forms Foundation

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-public-inquiry-forms.md`
Owner: Codex

## Goal

Deliver the first complete Intake module for configurable public inquiry forms, including safe file
upload storage and guarded Sales routing.

## User-Visible Behavior

Admins can configure public intake forms, fields, upload limits, enabled state, owner, and target.
Public users can submit active forms with optional attachments. Staff can review submissions,
download uploaded files, mark submissions reviewed, and route suitable submissions to Sales.

## Scope

- Singular `Intake` module with module-owned routes, controllers, models, views, actions, and tests.
- Public form rendering and submission handling with CSRF, throttling, honeypot, enabled-state
  checks, and server-side validation.
- Intake-owned attachment storage with safe file path, original filename, MIME, size, and checksum.
- Admin form builder for field definitions and upload field configuration.
- Submission review surface with attachment download and event history.
- Matching against Client, Site, Contact, and ClientUser context.
- Sales opportunity routing when a client is matched or auto-create client is explicitly enabled.

## Out Of Scope

- Online booking availability.
- CAPTCHA provider integration.
- Automatic Ticket creation.
- Automatic target-module attachment handoff.
- Public embeddable JavaScript widget.
- Customer Portal authenticated forms.

## Data Touched

- New tables: `intake_forms`, `intake_form_fields`, `intake_submissions`,
  `intake_submission_attachments`, and `intake_submission_events`.
- Local storage disk under `intake/submissions/{submission_id}` for uploaded files.
- Sales opportunity and activity rows when guarded routing succeeds.
- Optional Client, ClientSite, Contact, and ClientUser rows only when form settings allow creation.

## Permissions

- `intake.view`
- `intake.manage`
- `intake.submission_review`

Admin routes stay behind `auth`, `tech`, `2fa.required`, `tech.permission`, and `admin` middleware.
Public form routes keep normal web CSRF protection and use Laravel throttling.

## Tests

- Route ownership.
- Public submission with file upload and stored attachment metadata.
- Honeypot spam recording without storing files.
- Admin review workflow.
- Sales routing for a matched client submission.

## Documentation

- RFC updated and approved with first-slice file upload.
- Knowledge article added in `app/Modules/Intake/Docs/knowledge/intake-public-inquiry-forms.md`.
- Module README added in `app/Modules/Intake/README.md`.
- TODO updated with completed implementation status.

## Done Criteria

- Intake module follows module architecture.
- Public active form accepts valid submissions and rejects invalid data.
- Uploaded files remain Intake-owned and are downloadable only through protected admin routes.
- Sales routing does not create opportunities without a client.
- Permissions and admin navigation are seeded and visible.
- Relevant feature tests pass on the dev server.
