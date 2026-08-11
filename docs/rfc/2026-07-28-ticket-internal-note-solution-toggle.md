# RFC: Ticket Internal Note Solution Toggle

Status: Approved / Implemented
Date: 2026-07-28
Owner: Svein Tore / Codex
Change level: Level 2 Ticket composer and message-validation workflow
GitHub issue: #190
Related RFC: `docs/rfc/2026-06-03-ticket-solution-policy.md`
Implementation approval: Approved by Svein Tore on 2026-07-28

## Context

The Ticket Add message composer currently presents `Internal note` and `Internal solution` as two
message types. The server already normalizes `internal_solution` into an `internal_note` with
internal visibility, `send_solution` intent, and the established solution metadata. These choices
therefore describe one persisted message type through two UI labels.

Issue #190 requests one Internal note choice plus a compact `Mark as solution` switch. The existing
Ticket solution policy must remain authoritative, customer-reply `Send solution` behavior must not
change, and solution-marked internal notes must remain internal while retaining the existing
`Notify technician` option.

## Goals

- Keep only `Reply to contact` and `Internal note` in the Message type dropdown.
- Show a compact `Mark as solution` switch for Internal note when Ticket solution policy permits it.
- Keep the switch off by default and preserve it after validation errors.
- Persist both ordinary and solution-marked entries as `internal_note` with internal visibility.
- Reuse the existing solution metadata and `send_solution` workflow action for a marked note.
- Keep `Notify technician` available for both ordinary and solution-marked internal notes.
- Enforce the Ticket solution policy on the server even when a request is manipulated.
- Preserve historical messages, existing timeline Mark as solution actions, and customer solution
  replies.

## Non-Goals

- Do not change the solution-policy setting or its default.
- Do not change customer-reply intents or customer email behavior.
- Do not add a message type, database column, migration, route, permission, API endpoint, queue, or
  scheduled task.
- Do not redesign the conversation timeline or workflow editor.
- Do not retroactively rewrite existing Ticket messages or metadata.
- Do not change how a selected solution is replaced or removed through existing timeline actions.

## Current Behavior

- The composer Message type dropdown may show `Reply to contact`, `Internal note`, and
  `Internal solution`.
- `TicketController::addMessage` accepts `internal_solution`, confirms the solution policy, and
  normalizes it to `internal_note` plus `reply_intent = send_solution`.
- `AddTicketMessage` already sets `metadata.is_solution`, `solution_marked_at`, and
  `solution_marked_by` when an internal note carries the `send_solution` intent.
- `AddTicketMessage` also stores `notify_user_id` for internal notes and dispatches the existing
  internal technician notification job when one is selected.
- Internal messages never dispatch the customer-reply email job or Customer Portal notification.
- Workflow facts and action triggers already recognize the existing solution metadata.
- The Ticket solution policy hides the Internal solution option and blocks a submitted
  `internal_solution` alias when internal solution notes are disabled.
- Existing timeline actions can mark eligible internal notes as the selected solution.

## Proposed Change

### Composer UI

Remove `Internal solution` from the Message type dropdown. When `Internal note` is selected and
`allow_internal_solution_notes` is enabled, render a compact Bootstrap form switch named
`mark_as_solution` and labeled `Mark as solution`.

The switch is unchecked by default. Its help text will state that the note stays internal and does
not send customer email. The existing `Notify technician` selector remains visible whenever
Internal note is selected, independent of the switch state.

When `Reply to contact` is selected, the internal solution switch and technician notification
selector are hidden, while the existing Reply intent controls remain unchanged. The composer JavaScript
will derive this solely from the two visible message types and will continue to set the hidden
visibility field for the selected type.

Validation errors will reopen the composer and preserve `mark_as_solution`. If an old form submits
`type = internal_solution`, the render preparation may normalize that old input back to Internal
note with the switch selected instead of exposing the removed option.

### Server Validation And Normalization

Add `mark_as_solution` as a nullable boolean input to the existing Ticket message handler.

For `type = internal_note`:

- False or absent `mark_as_solution` clears message reply intent and creates an ordinary internal
  note.
- True `mark_as_solution` first checks `TicketSolutionPolicy::allowsInternalSolutionNotes()`.
- When allowed, the handler sets `reply_intent = send_solution` before calling the existing
  `AddTicketMessage` action.
- When disallowed, the handler returns a field error and does not persist a message.
- Visibility is always forced to `internal`, regardless of submitted visibility.
- `notify_user_id` continues through the same existing internal-note metadata path.

For `type = customer_reply`, `mark_as_solution` is ignored and the existing Reply intent, contact,
CC, portal, action-guard, email, notification, and workflow behavior remains unchanged.

The web handler may continue accepting the legacy `internal_solution` input alias for backward
compatibility with stale forms or existing integrations that post to the web route. The alias will
never be rendered and will be normalized through the same policy check to Internal note plus the
solution switch semantics. This preserves compatibility without keeping a second user-visible
message type.

### Wording And Existing Actions

Update the Ticket Settings explanation and no-contact composer warning to describe marking an
Internal note as the solution. Do not rename the stored policy key because its existing meaning is
still accurate and changing it would add avoidable settings migration work.

Existing historical messages and timeline `Mark as solution` actions remain unchanged. The selected
solution continues to be represented by the established message metadata.

## Impact Analysis

### Affected Areas

- Ticket show composer Blade markup and its small message-type synchronization script.
- `TicketController::addMessage` request validation and normalization.
- Ticket feature and Workflow v3 regression tests.
- Ticket Settings and Ticket lifecycle/workflow Knowledge documentation.

### Permissions And Security

- Existing Ticket route permissions and `TicketActionGuard` remain unchanged.
- Solution marking remains subject to the Ticket solution policy on every request.
- Submitted visibility is never trusted for Internal note; the server forces internal visibility.
- A marked internal note never enters the customer-reply email or Customer Portal notification
  paths.
- `notify_user_id` remains limited to active users through the existing validation rule.

### Routes, Data, Integrations, And Runtime

- No route, model, database, migration, API, integration, queue topology, scheduler, or frontend
  dependency changes.
- The existing internal notification job may still be dispatched when a technician is selected;
  this is preserved behavior, including for a solution-marked note.
- Existing message records and solution metadata remain compatible.
- No frontend build is expected because the change is inline Blade and JavaScript.

### Risks And Side Effects

- A manipulated switch must not bypass a disabled solution policy.
- Switching from Internal note to Reply to contact must not accidentally convert the public reply
  into an internal solution or suppress the chosen customer reply intent.
- Marking a note as solution must not hide or discard the selected technician notification.
- Removing the visible legacy option must not break stale form submissions or historical messages.
- Workflow solution requirements and `send_solution` action triggers must receive the same facts as
  the current Internal solution flow.

## Data And Migration Plan

No schema migration or backfill is required. Existing `internal_note` messages with solution
metadata remain authoritative. Rollback restores the old dropdown option and removes the switch;
no persisted data conversion is needed.

## Testing Plan

- Feature test: the composer contains only Reply to contact and Internal note message-type options.
- Feature test: policy-enabled Internal note shows an unchecked Mark as solution switch and keeps
  Notify technician visible.
- Feature test: an ordinary Internal note stores no solution metadata.
- Feature test: a marked Internal note stores internal visibility plus existing solution metadata,
  sends no customer email, and satisfies current workflow solution requirements/triggers.
- Feature test: a marked Internal note can retain `notify_user_id` and uses the existing internal
  notification path.
- Feature test: disabled policy hides the switch and rejects both a forced switch and the legacy
  `internal_solution` alias.
- Feature test: customer Reply intent, including Send solution, remains unchanged.
- Regression test: existing timeline Mark as solution behavior remains functional.
- Update Workflow v3 tests to exercise the new Internal note plus switch input while preserving
  pinned-version and manual-transition coverage.
- Run the focused composer/solution tests, the complete Ticket feature suites, Blade compilation,
  PHP syntax, and a Dev HTTPS Ticket-show smoke check.

## Documentation Plan

- Update `app/Modules/Ticket/Docs/knowledge/ticket-admin-settings.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-lifecycle-workflows.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-workflow-v3.md` where the technician action is
  described.
- Update `docs/TODO.md`, this RFC index, and `docs/human-review.md` after implementation.
- Sync the Ticket Knowledge articles through the repository Knowledge command.
- Add a public-safe website handoff item after implementation verification and keep it unpublished
  while human review remains open.

## Open Questions

None. Issue #190 defines the visible interaction, and the existing Ticket message action already
supports the required persisted form, notification metadata, privacy boundary, and workflow facts.

## Approval

Approved by Svein Tore on 2026-07-28.

## Implementation

Implemented on Dev on 2026-07-28. The composer now renders one Internal note type with a
policy-controlled Mark as solution switch, while `TicketController::addMessage` normalizes the
switch and the legacy `internal_solution` alias into the existing internal-note solution metadata.
Notify technician, customer Reply intents, historical messages, timeline solution actions, and
Workflow v3 behavior remain compatible. The complete Ticket feature suite passes with 155 tests and
1183 assertions. Ticket Knowledge documentation is synchronized, and manual verification remains
open under `HR-2026-07-28-005`.
