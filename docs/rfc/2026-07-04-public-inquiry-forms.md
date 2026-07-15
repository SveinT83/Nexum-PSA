# RFC: Public Inquiry Forms

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

GitHub Discussion #166 defines configurable public inquiry forms. Nexum already has Sales leads,
Tickets, Contacts, Clients, Notification, Commercial services, Customer Portal planning, and public
quote/contract surfaces. What is missing is a safe public form/intake layer that can support many
forms for different purposes without hardcoding one contact form.

This is Level 3 work because it introduces public routes, spam controls, configurable fields,
routing, duplicate matching, audit behavior, and cross-module target creation.

## Goals

- Create a configurable public form/intake capability for many form types and scopes.
- Support public URLs and embeddable website forms.
- Let admins define fields, validation, consent, routing, and enabled state.
- Route submissions to module-approved targets such as Sales lead, Ticket, booking request,
  feedback, or future targets.
- Store source, consent, raw submission, normalized fields, routing result, and audit history.
- Support controlled file upload fields and store uploaded files with the intake submission before
  any target handoff.
- Add duplicate matching for Client, Site, Contact, and lead context.
- Protect public endpoints with throttling, spam controls, validation, and safe file handling.

## Non-Goals

- Do not create arbitrary records in any module without an approved target contract.
- Do not replace Customer Portal authentication.
- Do not auto-create noisy Tickets by default.
- Do not build online booking availability logic in this RFC.
- Do not expose unfinished target types in admin UI.
- Do not automatically attach uploaded public files to Ticket or Sales records unless an approved
  routing target explicitly supports that handoff.

## Current Behavior

Nexum can receive emails, create Tickets, manage Contacts/Clients, and operate Sales leads. There is
no reusable public form builder. Existing public surfaces are domain-specific and not a general
intake path.

## Proposed Change

Create a singular public intake capability under a new singular `Intake` module.

The module owns:

- form definitions,
- form fields and validation rules,
- safe upload field configuration and stored submission attachments,
- public form rendering,
- submission records,
- spam/throttle policy,
- routing rules,
- submission audit,
- target contract orchestration.

Target modules own:

- approved target types,
- validation beyond intake fields,
- create/update behavior,
- duplicate handling decisions specific to that domain.

First implementation should support a conservative Sales lead target and optional staff-reviewed
Ticket handoff. Booking-specific behavior should wait for the Online Booking RFC.

Uploaded files must be stored on Intake submission attachment records first. Each upload must have
server-side limits for count, size, filename normalization, MIME metadata, checksum, and disk/path.
Target modules can read the attachment metadata during review, but Intake must not silently create
public Ticket attachments or outbound Sales attachments without a target contract that owns that
behavior.

The first spam controls are Laravel throttling, a honeypot field, enabled/disabled form checks, and
server-side validation. External CAPTCHA providers are intentionally deferred until a provider
decision is approved.

## Impact Analysis

- **Architecture:** new singular `Intake` module with module-owned routes and views.
- **Routes:** public routes in `app/Modules/Intake/routes.php`; admin routes for form management.
- **Database:** form definitions, fields, submissions, submission attachments, routing results,
  spam metadata, and audit.
- **Sales/Ticket:** target contracts for Sales lead creation and staff-reviewed Ticket handoff.
- **Contact/Client/Site:** duplicate matching and optional association. New Contact creation should
  use the Contact module action so the legacy `client_users` bridge stays consistent.
- **Commercial:** optional service selection from published services.
- **Notification:** submission confirmation and internal notifications.
- **Security:** throttling, honeypot spam controls, upload count/size limits, validation, and hidden
  internal target data. Normal rendered public forms keep CSRF protection.
- **Customer Portal:** later portal-authenticated forms may reuse definitions with authenticated
  identity.

## Data And Migration Plan

Add intake tables without modifying existing Sales or Ticket tables in the first slice. Store raw
submission data separately from normalized target records. Store uploaded files in an intake-owned
attachment table on the local disk with safe filenames and checksum metadata. Target creation must
record the source submission id for audit and rollback review.

Rollback should leave submissions intact or explicitly archive them; deleting target records is not
automatic.

## Testing Plan

- Feature tests for public form rendering, validation, throttling, disabled form behavior, and
  submission persistence.
- Tests for duplicate matching candidates.
- Tests for Sales lead target creation.
- Tests that disabled or unimplemented target types are not available.
- Authorization tests for admin form management.
- File upload validation tests for enabled upload fields, disabled upload fields, count limits, size
  limits, safe filenames, and stored attachment metadata.

## Documentation Plan

- Add module README and Knowledge documentation for public forms.
- Document form ownership, spam controls, target routing, and privacy behavior.
- Update Sales/Ticket docs when their target contracts are implemented.

## Resolved Decisions

- Module name: `Intake`.
- First spam controls: Laravel throttle, honeypot, enabled-state checks, and server-side validation.
  No external CAPTCHA provider in the first implementation.
- File uploads are included in the first implementation and remain Intake-owned until an approved
  target handoff explicitly consumes them.

## Approval

Approved by Svein in conversation on 2026-07-04, with file upload included in the first
implementation.
