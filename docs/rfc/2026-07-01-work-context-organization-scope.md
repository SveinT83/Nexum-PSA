# RFC: Work Context And Organization Scope

Status: Approved
Date: 2026-07-01
Owner: Codex

## Context

GitHub Discussion #149 defines a product direction where Nexum can manage work for the owning
organization itself, not only work tied to external Clients.

This also prepares Discussion #150. Nexum-to-Nexum relationships need a clean distinction between:

- work that belongs to our own organization,
- work that belongs to an external Client,
- work that is shared with another Nexum installation through a relationship channel.

The relationship channel is not itself the work context. A remote Nexum provider can receive an
internal ticket from us, but in their system the same work appears under us as their Client. That
means Work Context must stay simple and explicit, while relationship/sync behavior is owned by a
separate RFC.

Today the implemented behavior is mixed:

- Internal tickets are currently handled by registering the owning organization as a fake Client,
  such as `000000 - Tronder Data`.
- Ticket records can also have `client_id = null`, but that can mean internal, unrouted inbound
  email, legacy data, or another local state depending on the flow.
- Documentation has `scope_type` values such as `internal`, `client`, and `site`.
- Risk assessments treat `client_id = null` as internal.
- Task records use `owner_type` and `owner_id`, with denormalized `client_id` for reporting.
- Asset records currently require a Client.
- Calendar has owner and settings scope fields.
- Report, API, Commercial, Economy, Sales, and future Nexum-to-Nexum work all depend on this
  distinction being reliable.

## Goals

- Make "no Client selected" mean internal work for new records where the module supports internal
  work.
- Stop requiring a fake Client for new internal tickets, tasks, documentation, assets, risks,
  calendar events, and reporting scope.
- Preserve existing client-scoped behavior for external customer work.
- Keep existing legacy self-client records as historical data instead of migrating them in this RFC.
- Support both internal Tasks and internal Tickets:
  - Tasks are planned internal work, recurring work, checklists, and project steps.
  - Tickets are internal requests, incidents, deviations, cases, and project/case containers.
  - Ticket + Tasks can be used as a project/case structure.
- Ensure internal work is not invoiceable customer work, while still allowing internal cost and
  capacity reporting.
- Define a shared context contract that modules can adopt one slice at a time.
- Keep module ownership intact: Ticket owns ticket behavior, Asset owns asset behavior, and so on.
- Prepare for NexumRelationship routing without making relationship sync part of WorkContext.

## Non-Goals

- Do not implement Nexum-to-Nexum sync in this RFC. That is covered by
  `docs/rfc/2026-07-01-nexum-relationship-and-vendor-provider-routing.md`.
- Do not migrate existing records from the legacy self-client to internal context.
- Do not delete or hide the legacy self-client in this RFC.
- Do not make Commercial, Economy, or Sales invoice internal work.
- Do not replace existing Client, Site, Contact, Contract, Ticket, Task, Asset, Risk, Documentation,
  Knowledge, Calendar, Report, or Vendor ownership.
- Do not expose UI controls for relationship sync until the relationship behavior exists.

## Current Behavior

Nexum is already beyond a pure Client-only model, but the concepts are inconsistent:

- The owning organization can be represented as a normal Client today. This works operationally, but
  it pollutes client lists, reporting, contracts, SLA assumptions, and future sync design.
- Tickets can be created without a Client in some flows, especially unrouted inbound cases.
- Ticket SLA, reply email, contacts, sites, assets, assignment rules, time, and reports still use
  Client assumptions in several places.
- Documentation and Risk already expose internal versus client scope, but through local patterns.
- Task supports polymorphic ownership and standalone user-owned work, but API and reporting still
  expose `client_id` as an important filter.
- Assets require `client_id`, which prevents clean registration of company-owned infrastructure.
- Calendar has owner and settings scopes, but no shared work-context vocabulary.
- Client reports and Economy order preparation are intentionally client-safe and must not include
  internal work unless a later approved slice defines a specific internal cost report.

## Proposed Change

Introduce a singular `WorkContext` module as the platform owner for shared context definitions,
resolution helpers, compatibility support, and tests.

The `WorkContext` module owns:

- Context type constants and validation.
- A resolver that maps a record request to internal or client context.
- Compatibility helpers for existing `client_id` based records.
- A default internal context for the owning organization.
- Client context lookup for each external Client.
- Developer documentation for how modules should adopt context.

The first context contract is intentionally small:

```text
internal  = no Client selected; work belongs to our own organization
client    = Client selected; work belongs to an external customer
```

Nexum-to-Nexum relationships are separate from this contract. A record can be `internal` and still
be escalated to an upstream provider through a NexumRelationship. A record can be `client` and still
be synced back to that Client's Nexum installation through a NexumRelationship.

Domain modules own their own use of context:

- Ticket decides ticket creation, workflow, SLA, assignment, customer communication, and reports.
- Task decides owner behavior, planned work, and denormalized reporting fields.
- Documentation and Knowledge decide article/document visibility and sync eligibility.
- Asset decides ownership, filters, RMM sync behavior, and client asset safety.
- Risk decides assessment scope and reporting.
- Calendar decides event ownership and visibility.
- Report owns shared report navigation while domain modules own calculations.
- Integration owns API scope catalog and API key behavior.
- Commercial and Economy own billing/order preparation guardrails.

Add a `work_contexts` table with a small, explicit contract:

```text
id
type          internal | client
client_id     nullable, set only for client contexts
name
is_default
metadata      nullable JSON
timestamps
```

Add `work_context_id` to each module only when that module is adopted by an approved feature slice.
Existing `client_id` fields remain during migration where they are already part of behavior,
reporting, API responses, or compatibility. For client-scoped records, the module may denormalize
`client_id` from the selected WorkContext. For internal records, `client_id` stays null and
`work_context_id` points to the internal context.

New records:

- If no Client is selected, supported modules store internal context.
- If a Client is selected, supported modules store client context.
- The legacy self-client may remain selectable until a later cleanup slice, but new UI should guide
  users toward real internal context instead of the fake Client.

Existing records:

- Do not migrate legacy self-client records in this RFC.
- Do not blindly treat every `client_id = null` record as internal.
- Only mark legacy null records as internal in modules where current behavior already documents that
  meaning, such as Risk.

Recommended implementation order:

1. Work Context audit and contract documentation.
2. WorkContext module foundation and default contexts.
3. Ticket and Task internal creation behavior.
4. Internal cost reporting guardrails.
5. Documentation/Knowledge context alignment.
6. Asset internal ownership.
7. Risk and Calendar alignment.
8. Report filters, dashboards, and API output alignment.
9. Legacy self-client cleanup or hiding after new internal flows are stable.

## Impact Analysis

- **Architecture:** new singular `WorkContext` module must follow `app/Modules/WorkContext`.
- **Database:** new `work_contexts` table and later module-specific `work_context_id` columns.
- **Ticket:** creation, edit, index filters, SLA/no-SLA rules, customer replies, assignment rules,
  merge suggestions, API resources, and reports need explicit internal/client behavior.
- **Task:** existing owner model should be kept; WorkContext should complement owner/reporting
  fields, not replace them.
- **Documentation/Knowledge:** existing internal/client scope must align with the shared context
  without weakening visibility rules.
- **Asset:** current required Client behavior must be updated before internal assets can be created.
- **Risk:** existing internal/client behavior can become an early alignment target.
- **Calendar:** event ownership and system settings need terminology alignment before broader use.
- **Report:** client-safe reports must exclude internal context unless explicitly selected.
- **Commercial/Economy/Sales:** internal work must not become invoiceable, but internal effort and
  cost reporting can be built as a separate safe report path.
- **Integration/API:** API payloads and filters need context support only after matching domain UI
  and permission behavior exist.
- **Permissions:** internal work may need separate view/manage abilities from client-scoped work.
- **UI:** create/edit forms should make "Internal" clear when no Client is selected.
- **NexumRelationship:** relationship routing consumes WorkContext but is not owned by it.
- **Documentation:** Knowledge and module docs must explain internal versus client context for each
  adopted module.

## Data And Migration Plan

Phase 1 creates no data changes; it audits assumptions and finalizes the adoption contract.

Phase 2 adds `work_contexts`:

- Seed exactly one default internal context for the owning organization.
- Backfill one client context per existing Client, and create future client contexts when new
  Clients are created.
- Keep context type values in code constants rather than free-form UI input.
- Add indexes for `type` and `client_id`.

Module adoption phases:

- Add nullable `work_context_id` to the module table.
- Backfill records with real external `client_id` to the matching client context.
- Leave legacy self-client records untouched unless a later approved cleanup slice says otherwise.
- Leave ambiguous `client_id = null` records untouched unless module rules prove they are already
  internal by business rule.
- Update create/update paths to set explicit context for new records.
- Keep old routes and API behavior compatible until replacement behavior is documented and tested.

Rollback:

- Existing `client_id` fields remain during migration.
- Module slices must be reversible independently where Laravel migration constraints allow it.
- No slice may remove `client_id`, delete the legacy self-client, or change billing/reporting
  behavior without a later approved RFC update.

## Testing Plan

- Unit tests for WorkContext type validation, resolver behavior, and client/internal context lookup.
- Migration tests or feature tests proving default internal context and client contexts are created.
- Module feature tests for each adopted module:
  - no Client selected creates internal context,
  - Client selected creates client context,
  - internal records do not require Client/Site/Contact when that module supports internal work,
  - client-scoped records keep existing behavior,
  - client reports exclude internal records,
  - customer-facing routes and API responses do not expose internal records,
  - internal time/cost is not invoiceable,
  - permissions distinguish internal and client-scoped work where needed.
- Regression tests around Ticket SLA, replies, time/cost, and reporting before Ticket adoption is
  marked done.
- API ability tests before exposing context filters or payload fields through APIs.

## Documentation Plan

- Add WorkContext developer documentation when the module foundation is implemented.
- Update affected module README files and Knowledge docs as each module adopts context.
- Update Integration API management documentation when API payloads or filters change.
- Update Report documentation when filters and client-safe report behavior change.
- Link this RFC to the NexumRelationship RFC because relationship routing depends on reliable
  internal/client context.

## Feature Slices

- `docs/feature-slices/2026-07-01-work-context-audit-and-contract.md`
  - First slice. Audits current assumptions and produces the implementation contract.
- `docs/feature-slices/2026-07-01-work-context-foundation.md`
  - Creates the WorkContext module foundation after the audit and RFC approval.
- `docs/feature-slices/2026-07-02-work-context-ticket-task-internal-creation.md`
  - Adopts Work Context for new Ticket and Task records and preserves Economy billing guardrails.
- `docs/feature-slices/2026-07-02-work-context-documentation-risk-calendar-alignment.md`
  - Aligns Documentation, Knowledge vocabulary, Risk, and Calendar with the shared context
    contract.
- `docs/feature-slices/2026-07-02-work-context-asset-internal-ownership.md`
  - Allows internal company-owned Assets while keeping client/RMM asset behavior client-safe.
- `docs/feature-slices/2026-07-02-work-context-report-api-guardrails.md`
  - Adds adopted-module API filters, makes Ticket SLA reporting client-safe by default, and
    documents Commercial, Economy, and Sales as client-only under this RFC.
- Future cleanup: legacy self-client cleanup or hiding. This remains outside this RFC's completed
  behavior because the RFC explicitly does not migrate, delete, or hide historical self-client
  records.

## Open Questions

No open question blocks the first audit slice.

Non-blocking decisions for later slices:

- Which exact permissions should distinguish internal ticket/task view/manage access?
- Should internal tickets have no SLA by default, or should they support a separate internal target
  model later?
- When should the legacy self-client stop being selectable for new work?

## Approval

Approved by Svein in conversation on 2026-07-01 when starting work from GitHub Discussion #149.

The accompanying ADR is accepted in
`docs/adr/2026-07-01-own-organization-is-not-a-client.md`. The first implementation step remains the
documentation-only audit slice before any schema, UI, or module behavior changes.
