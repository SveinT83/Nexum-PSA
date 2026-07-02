# Feature Slice: Work Context Audit And Contract

Status: Done
Date: 2026-07-01
Parent: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Owner: Codex

## Goal

Make the shared Work Context direction safe to implement by auditing current Client assumptions and
writing the first implementation contract before any schema or UI changes.

## User-Visible Behavior

No user-visible behavior changes in this slice. This is a planning and safety slice that prepares
the first code slice.

## Scope

- Inventory current `client_id`, `scope_type`, `owner_type`, report, API, and visibility assumptions
  in the affected modules:
  - Ticket
  - Documentation
  - Knowledge
  - Asset
  - Task
  - Risk
  - Calendar
  - Report
  - Integration/API
  - Commercial
  - Economy
  - Sales
- Identify where the legacy self-client is used for internal work, without migrating it.
- Identify which nullable `client_id` records can safely be treated as internal and which must stay
  ambiguous until a module-specific slice handles them.
- Define the first WorkContext contract:
  - supported context types,
  - resolver behavior,
  - client compatibility behavior,
  - expected database fields,
  - permission expectations,
  - report/API safety rules.
- Confirm the migration mechanics for backfilling one context per existing Client and creating future
  client contexts.
- Confirm how NexumRelationship will consume WorkContext without adding relationship types to the
  WorkContext table.
- Produce the next ready feature slice for the WorkContext module foundation.
- Update the parent RFC if the audit finds a conflict with implemented behavior.

## Out Of Scope

- No migrations.
- No new routes, controllers, views, or UI controls.
- No changes to Ticket creation, Asset creation, Documentation visibility, Risk scope, Task owner
  behavior, Calendar events, reports, or APIs.
- No Nexum-to-Nexum relationship sync.
- No billing, Economy, or Sales behavior changes.
- No legacy self-client migration, hiding, or deletion.

## Data Touched

No production data is changed.

Documentation touched:

- The parent RFC.
- `docs/audits/2026-07-01-work-context-audit.md`.
- The next feature-slice document under `docs/feature-slices/`.
- `docs/TODO.md` if implementation readiness or blockers change.

## Permissions

No permissions are changed.

The audit must propose permission names or reuse rules for later slices, especially:

- internal Ticket view/manage behavior,
- client-safe report filtering,
- API ability handling for context filters,
- future external relationship context access.

## Tests

No Laravel behavior tests are required because this slice is documentation-only.

Verification for this slice:

- Static search of affected modules for `client_id`, `scope_type`, `owner_type`, client-safe report
  filters, API resources, customer-facing routes, and legacy self-client assumptions.
- Manual review of routes, controllers, models, requests/actions, reports, resources, and module
  docs for the first affected modules.

## Documentation

- Create a dated Work Context audit in `docs/audits/`.
- Update RFC open questions with concrete decisions or remaining blockers.
- Create the WorkContext module foundation feature slice.
- Add links from TODO or relevant planning docs as needed.

## Done Criteria

- The audit lists current behavior by module.
- The audit identifies unsafe null-Client assumptions.
- The audit identifies where new "no Client selected = internal" behavior can be introduced safely.
- The parent RFC has no unanswered question that blocks the WorkContext module foundation.
- The ADR is either accepted or explicitly left as a blocker.
- The next feature slice is ready to implement after RFC approval.
- No code behavior changes are included in this slice.
