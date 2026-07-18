# Feature Slice: Ticket Workflow Customer Status Notifications

Status: Done
Date: 2026-07-18
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Nexum PSA product owner and Codex

## Goal

Let each Ticket workflow transition optionally send a customer-safe status update after the
transition succeeds, including when an internal technician action triggered the transition.

## User-Visible Behavior

The workflow editor adds a compact **Customer update** section to every next-step transition.
Administrators may enable the update, choose Email and/or Customer portal delivery, select an
active Ticket email template, and write an optional customer-facing message.

A configured update is delivered only when:

- the Ticket transition actually succeeds,
- the Ticket is Published to the customer,
- the configured delivery channel has an eligible recipient, and
- the same workflow history transition has not already dispatched the update.

Automatic action triggers, manual next-step buttons, and Ticket API transitions use the same
runtime. Internal note content, internal workflow requirements, cost information, and internal
workflow step names are never inserted into the customer message automatically.

## Scope

- Store a versioned customer-notification policy on each workflow transition.
- Add the policy to draft validation and immutable published definitions.
- Add a default active `tickets / ticket_status_update` Email template.
- Render customer-safe Ticket and reporting-status variables through the Email domain.
- Queue Email delivery after commit through the default Ticket Email account.
- Create Customer portal database notifications without producing a second duplicate email when
  the workflow Email channel is also selected.
- Record sent, skipped, and failed attempts in Ticket events and Email logs.
- Keep a successful Ticket transition committed if delivery later fails.
- Apply the same behavior to UI, automatic transition actions, and Ticket API transition routes.
- Show customer-update policy in the existing workflow editor without expanding collapsed steps
  by default.

## Out Of Scope

- Sending from Unpublished Tickets.
- Including internal note text or internal workflow state names automatically.
- A general-purpose automation or scripting engine.
- SMS delivery; the stored channel list remains extensible for an approved future SMS slice.
- Delayed campaigns, digesting, or rate-based customer messaging.
- Making email delivery a requirement that can roll back a completed Ticket transition.
- Replacing technician-authored `ticket_reply` messages or the separate ticket-created flow.

## Data Touched

- `ticket_workflow_transitions.customer_notification` JSON policy.
- Published `ticket_workflow_versions.definition` transition snapshots.
- `email_templates` for the new default template.
- `email_logs` for Email attempt status.
- `ticket_events` for dispatch, skip, and failure audit.
- Laravel queue for after-commit status-update jobs.
- Customer portal database notifications for the portal channel.

The migration is additive and nullable. Existing transitions and published definitions remain
silent because a missing policy means disabled.

## Permissions

Existing Ticket workflow administration permissions govern policy configuration and publishing.
Existing Ticket transition permissions and workflow guards still govern movement. Workflow does
not bypass contact/client scope, Published visibility, Email account eligibility, or portal
membership scope. No new technician permission is introduced.

## Tests

- Draft/publish round trip preserves a valid notification policy.
- Invalid channels and templates are rejected during definition validation.
- Existing transitions without a policy remain silent.
- Manual, automatic-action, and API transitions dispatch the same policy once.
- Unpublished Tickets transition normally but log a skipped notification.
- Email uses the selected active Ticket template and customer-safe variables.
- Missing contact/account/template and SMTP failure are logged without reverting the transition.
- Portal-only delivery creates a portal notification without Email duplication.
- Email plus portal delivery sends one templated Email and one database portal notification.
- Repeated idempotency keys do not dispatch duplicate notifications.
- Workflow editor renders, edits, saves, and reloads the compact policy controls.

## Documentation

- Update Ticket Workflow v3 Knowledge documentation.
- Update Ticket Email Communication Knowledge documentation.
- Update Email template documentation.
- Synchronize Ticket and Email Knowledge to BookStack.
- Add the manual verification to `docs/human-review.md`.

Repository Knowledge synchronization processed two chapters and twelve Ticket/Email articles with
no skips. The synchronous BookStack push completed against the active, healthy integration with no
last error.

## Done Criteria

- [x] Policy is stored, validated, published, and pinned with the workflow version.
- [x] Workflow editor can configure channels, template, and optional customer message.
- [x] `ticket_status_update` exists and is editable in Email Templates.
- [x] Successful UI, automatic, and API transitions use one dispatch path.
- [x] Published visibility and recipient scope are enforced.
- [x] Duplicate Email/portal delivery is prevented.
- [x] Attempts are auditable and delivery failure cannot revert the transition.
- [x] Relevant Ticket, Email, Notification, API, and Livewire tests pass on Dev.
- [x] Knowledge is updated and synchronized to BookStack.
- [x] Human review entry identifies remaining browser and inbox checks.
