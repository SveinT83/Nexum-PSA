# ADR: Own Organization Is Not A Client

Status: Proposed
Date: 2026-07-01
Decision Makers: Svein Tore Ramstad / Codex

## Context

Discussion #149 and RFC `docs/rfc/2026-07-01-work-context-organization-scope.md` need one early
architecture decision: should the company running Nexum be represented as a normal Client record, or
should it have its own explicit internal work context?

The current product can represent internal work by creating a fake self-client such as
`000000 - Tronder Data`. This worked as a practical workaround, but Client records carry external
customer meaning across contracts, sites, contacts, reports, billing preparation, SLA assumptions,
sales opportunities, marketing audiences, and client-safe API data.

Internal work has different rules. It may involve company infrastructure, internal documentation,
internal risk, recurring maintenance, staff tasks, internal tickets, and operational history that
should not appear in customer reports or invoiceable workflows by default.

## Decision

The owning organization is not modeled as a normal Client for new work.

Nexum will represent own-organization work through an explicit internal WorkContext. Client remains
reserved for external customers. Modules may keep `client_id` as a compatibility and reporting field
where it already exists, but new internal work must be represented by an explicit internal context
instead of the legacy self-client.

Existing records tied to the legacy self-client are left as historical data unless a later approved
cleanup slice migrates, hides, or archives that Client.

## Rationale

Client records carry customer-facing meaning. Reusing one of them for the owning organization makes
reports, contracts, billing, SLA selection, API filters, marketing, sales, and future sync behavior
harder to reason about.

An explicit internal context gives modules a shared answer to "who or what is this work about?"
without moving module-specific behavior out of the modules. It also gives Nexum-to-Nexum
relationship work a clean foundation:

- In our system, a ticket with no Client is internal.
- If that internal ticket is escalated to an upstream provider, the provider receives it under us as
  their Client.
- If we are the provider, a linked ticket from another Nexum appears under that external Client in
  our system.

That separation keeps WorkContext about ownership/scope, while NexumRelationship handles sharing,
sync, routing, and fallback.

## Consequences

Positive:

- Client lists stay reserved for external customers.
- Client reports and customer-safe surfaces can exclude internal records by default.
- Internal tickets, tasks, assets, documentation, risks, and calendar events can have their own
  defaults and visibility rules.
- Internal effort can be cost-reported without becoming customer invoiceable work.
- Future NexumRelationship sync can build on explicit internal/client context instead of reversing a
  fake-Client workaround later.

Negative:

- More implementation work is required than continuing to use the fake self-client.
- Each module must adopt the shared context contract deliberately.
- The legacy self-client will exist during a transition period unless a later cleanup slice removes
  it.
- Existing null `client_id` records need review because null may currently mean internal, unrouted,
  personal, legacy, or unknown depending on the module.

## Alternatives Considered

- Continue using the owning organization as a Client. Rejected because it pollutes customer
  workflows, reporting, billing, contracts, and future Nexum-to-Nexum sync.
- Use nullable `client_id` everywhere with no explicit context. Rejected because null does not
  explain whether a record is internal, unrouted, personal, legacy, or missing data.
- Let each module invent its own local scope field. Rejected because Ticket, Task, Documentation,
  Asset, Risk, Calendar, Report, API, and future sync need a shared vocabulary.
- Replace all module ownership with a central context system. Rejected because domain behavior must
  stay inside each module.

## Follow-Up

- Approve or revise RFC `docs/rfc/2026-07-01-work-context-organization-scope.md`.
- Complete feature slice `docs/feature-slices/2026-07-01-work-context-audit-and-contract.md`.
- Create the WorkContext module foundation after RFC approval.
- Decide in a later cleanup slice when the legacy self-client should be hidden, blocked for new
  work, archived, or deleted.
