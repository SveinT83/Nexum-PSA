# Work Context Audit And Contract

Date: 2026-07-01
Parent RFC: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Discussion: GitHub Discussion #149
Status: Done

## Summary

This audit confirms that Work Context must be an explicit platform contract, not a project-wide
replacement for `client_id` in one pass.

The first supported context types are:

- `internal`: no Client selected; work belongs to the owning organization.
- `client`: Client selected; work belongs to an external customer.

NexumRelationship is not a WorkContext type. Relationship routing may share or sync a local internal
or client-scoped record later, but relationship state belongs to a separate RFC and module.

The next safe implementation step is the WorkContext foundation slice. It may add the module,
`work_contexts` table, default internal context, client context resolution, and tests. It must not
change Ticket, Task, Asset, Documentation, Knowledge, Risk, Calendar, Report, API, Commercial,
Economy, or Sales runtime behavior.

## Verification Performed

- Read GitHub Discussion #149 and the planning comment linking the RFC, ADR, and first feature
  slices.
- Read `AGENTS.md`, `docs/development/ai-team-process.md`, `docs/module-architecture.md`,
  `docs/TODO.md`, `docs/processes/rfc-process.md`, and `docs/processes/feature-slice-process.md`.
- Searched affected modules for `client_id`, `scope_type`, `owner_type`, report filters, API
  resources, customer-facing routes, and internal wording.
- Reviewed central migrations and module entry points for Ticket, Task, Asset, Documentation,
  Knowledge, Risk, Calendar, Report, Integration/API, Commercial, Economy, and Sales.
- Read a sanitized database snapshot for counts only.

## Database Snapshot

The development database currently has:

| Table | Total | Null Client | With Client | Notes |
| --- | ---: | ---: | ---: | --- |
| `tickets` | 28 | 21 | 7 | Null is currently ambiguous and must not be blindly backfilled. |
| `tasks` | 11 | 2 | 9 | Null can mean user-owned/internal, but must be resolved by Task rules. |
| `assets` | 181 | 0 | 181 | Asset currently requires a Client. |
| `documentations` | 1 | 0 | 1 | Documentation has an explicit `scope_type`. |
| `risk_assessments` | 0 | 0 | 0 | Code already treats null Client as internal. |
| `economy_orders` | 2 | 0 | 2 | Economy orders are client-only. |
| `sales_opportunities` | 2 | 0 | 2 | Sales opportunities are client-only. |
| `contracts` | 1 | 0 | 1 | Contracts are client-only. |

Legacy self-client candidates were present, including an internal Client-like record named
`00000 - Internt - Tronder data`. This RFC must leave those records untouched until a later cleanup
slice explicitly decides how to hide, block, archive, migrate, or delete them.

## Module Inventory

| Module | Current Scope Pattern | Audit Result |
| --- | --- | --- |
| Ticket | `tickets.client_id` is nullable. Store paths accept no Client, but contact/site/asset validation assumes a selected Client. SLA resolver falls back to default SLA when no Client contract exists. Reports and lists filter only by `client_id`. | High-impact adoption target. Existing null tickets are ambiguous. New internal Ticket behavior must be added in a Ticket slice with SLA, assignment, reply, asset, report, time, cost, and API tests. |
| Task | Tasks use `owner_type`/`owner_id`, nullable `client_id`, nullable `site_id`, and internal visibility. User-owned tasks already work without a Client. Ticket-owned tasks inherit Ticket client context in the form component. | Early adoption target with Ticket. WorkContext should complement owner fields and denormalized `client_id`; it must not replace task ownership. |
| Documentation | `documentations.scope_type` is explicit: `internal`, `client`, or `site`. No Client and no Site resolves to `internal` in UI and API payload helpers. | Good alignment candidate. Existing `scope_type = internal` records can map to internal context when Documentation adopts WorkContext. |
| Knowledge | Articles use `visibility` values `internal`, `client-wide`, and `public`, with `client_scope_id` only for client-wide articles. | Align vocabulary carefully. WorkContext should not weaken visibility; public articles are visibility, not ownership context. |
| Asset | `assets.client_id` is required in schema, action validation, Livewire validation, queries, views, RMM sync, and API. | Later adoption target. Needs schema and UI changes before internal assets can exist. Do not make `client_id` nullable without Asset-specific tests and integration guardrails. |
| Risk | Risk assessment actions translate `scope = internal` into `client_id = null`; list query explicitly treats `only_internal` as `whereNull(client_id)`. | Safe early alignment target after foundation. Existing null Risk records can be treated as internal by current business rule. |
| Calendar | Calendar has owner morphs, event links, participants, and settings `scope_type`; events do not have `client_id`. Client relation is currently indirect through linked records. | Needs terminology alignment before data changes. WorkContext can become link metadata or a later event field only after Calendar-specific slice design. |
| Report | Report module owns hub and permissions. Domain modules own calculations. Ticket SLA report currently includes all tickets with SLA fields and has no context filter. | Report filters must wait until adopted domains expose context. Client-safe reports must exclude internal records unless explicitly selected and permitted. |
| Integration/API | API abilities are domain-scoped, not context-scoped. Domain APIs expose `client_id`, `scope_type`, visibility, or owner fields depending on module. | Do not add generic context API filters until matching domain UI and permission behavior exist. Add ability names only with routes and tests. |
| Commercial | Contracts require `client_id`. SLA and time-rate behavior is client/commercial-first. | Out of first adoption path. Must remain client-only for this RFC unless an internal cost-reporting slice is approved. |
| Economy | Orders and lines require `client_id`. Order generation only includes billable ticket time/cost where the related ticket has `client_id`. | Guardrail is good and must be preserved. Internal work must not generate customer order lines. |
| Sales | Sales opportunities require `client_id`; public quote routes are customer-facing. | Remains client/commercial-first. Do not add internal WorkContext to Sales in this RFC. |

## Null Client Classification

Safe to treat as internal by current module rule:

- `risk_assessments.client_id = null`.
- `documentations.scope_type = internal` with null `client_id` and null `site_id`.

Safe for new records after module adoption, but not safe for historical blind backfill:

- `tickets.client_id = null`.
- `tasks.client_id = null`.

Not currently nullable or not in scope for internal records:

- `assets.client_id`.
- `contracts.client_id`.
- `economy_orders.client_id` and `economy_order_lines.client_id`.
- `sales_opportunities.client_id`.

Visibility fields that must not be confused with WorkContext:

- `articles.visibility`.
- `tasks.visibility`.
- `ticket_messages.visibility`.
- `calendar_events.visibility`.

## WorkContext Contract

The WorkContext module should own the shared contract only:

- Context type constants: `internal` and `client`.
- Validation for supported context types.
- A resolver for create/update payloads.
- Default internal context resolution.
- Client context lookup and creation.
- Compatibility helpers for existing `client_id` based modules.
- Developer documentation for module adoption.

Initial table contract:

```text
work_contexts
id
type          internal | client
client_id     nullable, set only for client contexts
name
is_default
metadata      nullable JSON
created_at
updated_at
```

Required invariants:

- Exactly one default internal context exists.
- Client contexts point to real Client records.
- A Client has at most one client context.
- Internal contexts never carry `client_id`.
- Relationship/sync state is not stored in `work_contexts`.

Resolver behavior:

- If a supported module receives no Client, Site, Contact, or Asset selection, resolve the default
  internal WorkContext and leave module `client_id` null where the module still has that column.
- If a Client is selected, resolve or create that Client's WorkContext and denormalize `client_id`
  where the module keeps `client_id`.
- If Site, Contact, or Asset is selected, validate that it belongs to the selected Client or derive
  the Client from that related record when the module already supports that behavior.
- If selected related records conflict, fail validation instead of guessing.
- Existing records must not be reclassified unless the module slice states the business rule and has
  tests.

## Migration Mechanics

The foundation slice should add only shared foundation data:

- Create `app/Modules/WorkContext`.
- Create `work_contexts`.
- Add model, constants, resolver, and default/context creation action.
- Seed or ensure one internal context for the owning organization.
- Create or resolve one client context per existing Client.
- Ensure future Client creation can resolve a client context without requiring a global migration
  every time.

Module adoption slices should add `work_context_id` one module at a time. They should backfill only
records whose current business rule is unambiguous.

## Permission Expectations

The foundation slice needs no user-facing permission.

Later module slices should decide exact permission names before implementation. Current expectation:

- Existing module permissions continue to gate normal technician/admin access.
- Internal Ticket and Task permissions may need separate view/manage abilities if client-facing or
  restricted technician surfaces are added.
- Report access must distinguish client-safe report output from internal activity reports.
- API context filters require explicit token abilities and tests before exposure.

## Report And API Safety Rules

- Client reports must not include internal context by default.
- Customer-facing routes and public quote/contract surfaces must never expose internal records.
- APIs must not infer `client_id = null` as internal for historical records unless the adopted
  module has documented and tested that rule.
- A future API `work_context_id` or `context_type` filter must be added per module, not globally.
- Economy generation must keep excluding records with no real customer Client unless an approved
  internal cost-reporting slice creates a separate non-invoiceable path.

## Next Slice Readiness

`docs/feature-slices/2026-07-01-work-context-foundation.md` is ready for implementation.

Before starting later domain adoption slices, create or update dedicated feature slices for:

- Ticket and Task internal creation.
- Internal cost/capacity reporting guardrails.
- Documentation and Knowledge alignment.
- Asset internal ownership.
- Risk and Calendar alignment.
- Report and API context filters.
- Legacy self-client cleanup or hiding.
