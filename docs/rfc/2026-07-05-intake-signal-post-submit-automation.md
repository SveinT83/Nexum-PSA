# RFC: Intake Signal Post-Submit Automation

Status: Approved
Date: 2026-07-05
Owner: Codex

## Context

Intake forms now support configurable fields, layout, conditional visibility, safe file storage, and
guarded Sales routing. The next beta-critical decision is what happens after a public form is
submitted. Possible outcomes include Ticket creation, Task creation, customer portal invitation,
Sales follow-up, webhook delivery, or more than one action at the same time.

This is Level 3 work because it connects Intake, Signal, Ticket, Task, Customer Portal, Sales, queued
email/webhook behavior, and cross-domain audit.

## Goals

- Make Signal the single rule engine for post-submit Intake automation.
- Keep Intake responsible for forms, public validation, submission storage, attachments, matching,
  and legacy guarded Sales routing.
- Let one Intake submission trigger multiple Signal actions.
- Add Signal actions for Task follow-up and Customer Portal invitation.
- Ensure hidden/spam Intake submissions do not trigger automation.
- Ensure one failing action does not block the submission or other actions.

## Non-Goals

- Do not create internal Nexum PSA users directly from public Intake forms.
- Do not copy Intake uploads into Ticket, Task, Sales, or webhook payloads in this slice.
- Do not remove existing Sales target routing yet.
- Do not add a second automation builder inside Intake.

## Current Behavior

Intake stores submissions and can route a matched submission into a Sales opportunity when the form
target is `sales_lead`. Signal already stores normalized events, evaluates rules, audits executions,
creates Sales and Ticket follow-up, and queues webhooks. Intake does not currently record Signal
events after submission.

## Proposed Change

After a successful non-spam Intake submission, Intake records one Signal:

- `source_domain`: `intake`
- `signal_type`: `intake_submission_received`
- source: the `IntakeSubmission`
- subject: the `IntakeForm`
- client/contact: matched context when available
- payload: form id/slug/name, submission id/status, routing result, matched site/contact/client ids,
  visible submitted fields, normalized mapped values, and attachment metadata without storage paths.

Signal remains responsible for deciding what to do next. Rules can use existing actions
`sales_follow_up`, `ticket_follow_up`, and `webhook`, plus new `task_follow_up` and
`portal_invitation` actions.

## Impact Analysis

- **Intake:** records Signal events for successful public submissions and shows admins where to
  create after-submit automation.
- **Signal:** gains Intake-compatible actions, keeps per-action audit results, and continues later
  actions when one action fails.
- **Task:** tasks can be created from Signal using `StoreTask`.
- **Customer Portal:** portal invitations can be sent from Signal using the existing invitation
  action and default `viewer` role.
- **Ticket/Sales/Webhook:** existing Signal actions continue to work for Intake signals.
- **Security/privacy:** file bytes and storage paths remain Intake-owned and are not placed in Signal
  payloads.

## Data And Migration Plan

No new database tables or columns are required. Existing Signal source fields and JSON payloads hold
the Intake event. Existing Signal audit rows record action results. Existing Customer Portal
invitation metadata stores Signal provenance after the invitation is created.

Rollback can disable or remove Signal rules without deleting Intake submissions.

## Testing Plan

- Intake submission creates one `intake_submission_received` Signal.
- Honeypot spam submissions do not create Signal records.
- Signal can create Task follow-up from an Intake signal and remains idempotent per signal/rule.
- Signal can send Customer Portal invitation from an Intake signal when matched Client and Contact
  context exists.
- Signal structured rule form can persist the new actions.
- Existing Ticket, Sales, webhook, and payload condition tests continue to pass.

## Documentation Plan

- Update Intake Knowledge documentation.
- Update Signal README and Knowledge documentation.
- Add this RFC as the approved source of truth for post-submit automation ownership.

## Open Questions

None for this slice.

## Approval

Approved by Svein in conversation on 2026-07-05 when requesting implementation of the proposed plan.
