# Feature Slice: Intake Final Routing And Review

Status: Done
Date: 2026-08-11
Parent: `docs/rfc/2026-07-04-public-inquiry-forms.md`
Owner: Codex

## Goal

Finish Intake public inquiry forms as production-ready functionality, not a beta-only foundation,
by completing publication state, explicit routing policy, direct target-module handoff, and staff
review outcomes.

## User-Visible Behavior

Admins can publish, pause, draft, or archive public forms. Each form has a purpose, language,
scope, target, and routing mode. Public submissions keep an immutable form and field snapshot.
Staff can route submissions to Sales, Ticket, or Task, link an existing Client, Contact, Ticket,
Task, or Sales opportunity, and close submissions as reviewed, spam, duplicate, rejected, or
archived with event history.

## Scope

- Intake form lifecycle states and backward-compatible handling of legacy `active` records.
- Form purpose, language, scope, and routing policy metadata.
- Scoped Client matching for client-specific forms.
- Direct Ticket and Task routing through their module-owned creation actions.
- Automatic routing only when the configured routing mode permits it.
- Manual review actions for duplicate, spam, rejected, archived, and existing-target linking.
- Submission payload snapshots for historical auditability.
- Admin UI, Knowledge, README, tests, and human-review tracking.

## Out Of Scope

- CAPTCHA provider integration.
- Public embeddable JavaScript widget.
- Copying Intake-owned files into target modules.
- Creating Customer Portal users directly from public submissions.
- Online booking availability, which remains owned by Calendar/Booking work.

## Data Touched

- Existing `intake_forms.status` values are normalized from legacy `active` to `published`.
- Intake form metadata stores purpose, language, scope, and routing mode.
- Intake submissions store raw field payload, normalized payload, form snapshot, field snapshot,
  routing results, and target links.
- Ticket, Task, and Sales rows are created only through their owning module workflows.

## Permissions

- `intake.view`
- `intake.manage`
- `intake.submission_review`

No new permission is introduced; completed review/routing actions remain behind the existing
admin and Intake review permissions.

## Tests

- Form settings persist lifecycle, target, scope, and routing policy.
- Submissions store form and field snapshots.
- Scoped forms match their configured Client.
- Automatic Ticket routing honors the known-client policy.
- Manual Ticket and Task routing uses target-module actions and keeps files Intake-owned.
- Review outcomes and existing-target linking persist status, targets, and event history.
- Legacy Sales routing remains functional with the explicit routing policy.

## Documentation

- Intake Knowledge article.
- Intake module README.
- TODO status row.
- Human review register.

## Done Criteria

- Public forms have final lifecycle states and safe legacy compatibility.
- A submission can be fully processed without external ad hoc steps.
- Target creation uses Sales, Ticket, and Task module contracts.
- Staff review outcomes are auditable and visible.
- Attachments stay Intake-owned until a separate approved handoff consumes them.
- Relevant tests pass on the development server.
