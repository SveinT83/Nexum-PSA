# Feature Slice: Intake Signal Post-Submit Automation

Status: Done
Date: 2026-07-05
Parent: docs/rfc/2026-07-05-intake-signal-post-submit-automation.md
Owner: Codex

## Goal

Send successful Intake submissions into Signal and let Signal rules run the approved follow-up
actions.

## User-Visible Behavior

Admins configure after-submission actions in Signal rules. Intake form edit pages link to a new
pre-filtered Signal rule for that form.

## Scope

- Record `intake_submission_received` Signals for non-spam submissions.
- Add Signal actions for Task follow-up and Customer Portal invitation.
- Keep Signal execution audit per action so later actions continue after failures.
- Update Knowledge and module README docs.

## Out Of Scope

- Direct internal user creation.
- Copying Intake files into Ticket, Task, Sales, or webhook payloads.
- Removing legacy Sales target routing.

## Data Touched

Uses existing Intake, Signal, Task, Customer Portal, Ticket, Sales, and webhook tables. No migration
is required.

## Permissions

No new permissions. Existing Intake and Signal permissions apply.

## Tests

Feature tests cover Intake Signal creation, spam suppression, structured Signal rule action storage,
Task follow-up, and Portal invitation.

## Documentation

RFC, Intake Knowledge, Signal Knowledge, Signal README, Intake README, and TODO were updated.

## Done Criteria

- Intake submissions produce Signals.
- Signal rules can perform all approved post-submit actions.
- Relevant tests pass.
