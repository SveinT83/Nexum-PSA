# Feature Slice: Operational Supplier Order Setup

Status: Ready
Date: 2026-08-11
Parent: `docs/rfc/2026-08-11-operational-supplier-order-automation-setup.md`
Owner: Svein / Codex

## Goal

Make Supplier Order Automation understandable and complete without exposing internal AI or runtime
controls.

## User-Visible Behavior

The administrator chooses order handling, warehouse, unknown-supplier and unknown-Item behavior,
AI use, one Storage agent, order limits, and notifications. Explanatory labels replace runtime
jargon. There is no Advanced settings panel or separate default-outcome choice.

## Scope

- Simplify the Storage policy Blade view.
- Derive the safe outcome from the selected handling mode.
- Apply the technical AI and runtime preset in the authenticated HTTP request.
- Remove the unused consensus-workload list from this controller/query path.
- Add rendering and forged-input regression coverage.
- Save and verify a new Dev policy revision without changing stock or existing pinned imports.
- Update Knowledge, TODO, human review, and handoff documentation.

## Out Of Scope

Schema removal, generic Integration settings, profile-editor redesign, import/detail redesign,
automatic receipt, production rollout, or rewriting existing imports.

## Data Touched

Storage policy and two immutable follow-up revisions on Dev; revision 9 is current after the
provider-timeout correction. Managed workload/actor rows may be reconciled
idempotently by the existing policy action. No Item, Purchase Order, shipment, receipt, Stock Unit,
Movement, or on-hand quantity is changed by the setup itself.

## Permissions

The existing Admin route and `storage.purchase_import_policy_manage` action check remain unchanged.
No permission widens.

## Tests

Focused policy/UI tests, affected AI/import tests, complete Storage suite, Blade cache compilation,
Pint, Knowledge sync, provider smoke, and human desktop/narrow-width review.

## Documentation

Parent and amended RFCs, Storage Knowledge, TODO, `docs/human-review.md`, and the public-safe website
handoff.

## Done Criteria

- [x] Ordinary fields use plain language and the overlapping outcome field is removed.
- [x] Advanced AI and technical runtime fields are absent from the ordinary form.
- [x] Forged technical fields are replaced by a safe server-owned preset.
- [x] Existing managed actor/workload, evidence, validation, and no-receipt boundaries remain.
- [x] Focused and complete Storage verification passes on Dev.
- [x] Dev policy readiness and controlled provider execution are verified.
- [x] Knowledge/TODO/human-review/handoff updates are complete.
- [ ] Named human review confirms the normal form at desktop and narrow widths.
