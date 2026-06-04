# RFC: Ticket Solution Policy

Status: Approved
Date: 2026-06-03
Owner: Codex

## Context

GitHub issue #67 asks for technicians to mark a reply as the solution directly from the ticket reply
form, and for workflow support where an internal note can count as the solution. This matters for
RMM and asset-driven tickets where there may be no customer contact, or where sending an RMM-related
customer email would be noisy or unwanted.

Current local Ticket changes already support:

- Customer reply intent `Send solution`, which marks a public technician reply as a solution.
- `Internal solution`, which stores an internal note with solution metadata without sending email.

The remaining decision is whether internal solution notes should always satisfy workflow
`requires_resolution`, or only when enabled by Ticket settings/workflow policy.

## Goals

- Let admins control whether internal notes may count as ticket solutions.
- Keep customer-visible solution replies available for normal customer tickets.
- Support RMM/asset tickets that can be resolved without sending customer email.
- Make the ticket reply/composer UI honest about whether an internal solution will satisfy workflow
  requirements.
- Preserve existing workflow requirements for customer response versus solution.
- Add tests and Knowledge documentation for the policy.

## Non-Goals

- Build RMM-specific ticket workflows in this RFC.
- Add per-client or per-contact communication preferences.
- Add automatic customer notifications, approval flows, or billing behavior.
- Replace the existing workflow editor.
- Change closed ticket safety rules.

## Current Behavior

Ticket workflows can require:

- Internal note.
- Public technician response.
- Selected solution response.
- Documentation follow-up.

The runtime currently checks `metadata->is_solution` for solution requirements. Public technician
replies can be marked as solution. Local pending changes also allow an internal solution note to set
the same solution metadata, but there is no admin setting that explicitly decides whether internal
solutions should be allowed as workflow solutions.

Customer replies require a reachable contact email. Internal notes do not send email.

## Proposed Change

Add a Ticket solution policy setting under Ticket Settings.

Recommended v1 setting:

- `allow_internal_solution_notes`: boolean, default `true`.

Behavior:

- When disabled:
  - Public technician replies marked as solution satisfy `requires_resolution`.
  - Internal notes can still be added.
  - Internal notes must not satisfy `requires_resolution`.
  - The reply form should not offer `Internal solution` as a workflow-satisfying option.
- When enabled:
  - The ticket composer exposes `Internal solution`.
  - Internal solution notes set `metadata->is_solution = true`.
  - Internal solution notes satisfy `requires_resolution`.
  - Workflow action triggers may treat internal solution as the existing `send_solution` action.

The setting should live in the Ticket module, likely in `common_settings` with:

- `type = ticket`
- `name = solution_policy`
- JSON payload: `{ "allow_internal_solution_notes": true|false }`

The workflow editor does not need a new transition requirement in v1. Existing
`requires_resolution` remains the single requirement. The policy controls which message types are
eligible to satisfy it.

## Impact Analysis

- Ticket settings:
  - Adds one solution policy control to the existing Ticket Settings page.
- Ticket workflow runtime:
  - Reads policy before accepting internal notes as solution messages.
  - Keeps public technician solution replies valid.
- Ticket composer:
  - Shows or hides `Internal solution` based on policy and ticket context.
  - Keeps `Send solution` for public customer replies.
- Ticket actions:
  - Internal solution metadata should only be accepted as workflow-satisfying when policy allows it.
- Permissions:
  - Uses existing Ticket settings admin access.
- Data:
  - Uses `common_settings`; no schema migration expected.
- Integrations:
  - RMM/inbound ticket creation does not change in this RFC.
- Queues:
  - No queue behavior change.
- UI:
  - Ticket Settings gains a compact policy checkbox.
  - Ticket composer updates option visibility/help text.
- Documentation:
  - Ticket admin settings and lifecycle Knowledge docs must describe the policy.

## Data And Migration Plan

No schema migration is planned.

Settings storage:

- `common_settings.type = ticket`
- `common_settings.name = solution_policy`
- `common_settings.json = {"allow_internal_solution_notes": false}`

Existing installs default to `true` so RMM and asset-driven tickets can use internal solutions immediately. Existing messages with
`metadata->is_solution = true` and `type = internal_note` remain stored, but workflow checks should
only count them when the setting is enabled.

Rollback is safe by deleting the setting row; code falls back to `false`.

## Testing Plan

- Feature test: admin can open Ticket Settings and toggle internal solution policy.
- Feature test: with policy disabled, internal notes marked as solution do not satisfy
  `requires_resolution`.
- Feature test: with policy enabled, an internal solution note satisfies `requires_resolution`.
- Feature test: customer `Send solution` replies still satisfy `requires_resolution`.
- Feature test: composer only exposes `Internal solution` when policy allows it.
- Regression test: customer reply still requires a reachable contact email.
- Run:
  - `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php`

## Documentation Plan

- Update `app/Modules/Ticket/Docs/knowledge/ticket-admin-settings.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-lifecycle-workflows.md`.
- Update `docs/TODO.md` when the RFC is approved and implemented.
- Sync Ticket Knowledge docs to BookStack after implementation.

## Open Questions

Resolved: beta should enable internal solution notes by default because RMM/asset tickets are common.

## Approval

Approved by Svein in conversation on 2026-06-03. Default internal solution policy should be enabled.
