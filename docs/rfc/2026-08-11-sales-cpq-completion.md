# RFC: Sales CPQ Completion

Status: Approved
Date: 2026-08-11
Owner: Codex

## Context

GitHub Discussion #170 defines the final target for Sales Quotes / CPQ. Nexum already has Sales
opportunities, quote versions, quote lines, public and portal quote views, PDF, email sending,
customer questions, and acceptance. That foundation is useful but not yet the full CPQ workflow:
customer-selectable options, required acknowledgements, approval gates, accepted selection snapshots,
decline/lifecycle signals, templates, and controlled downstream conversion are missing or incomplete.

The user approved continuing with this completion work on 2026-08-11 with "Kjor pa".

## Goals

- Keep Sales as quote and opportunity owner.
- Keep Commercial, Economy, Ticket, ServiceVisit, Storage, Asset, Task, and future Project domain
  ownership intact.
- Add customer-selectable quote options, alternatives, add-ons, and required lines to existing quote
  versions.
- Store immutable accepted snapshots covering selected and declined lines, quantities, totals, public
  text, acknowledgements, and customer identity.
- Add required acknowledgement blocks that must be accepted before a quote can be accepted.
- Add settings-driven internal approval before sending when discount, margin, total value, or manual
  line risk requires it.
- Add a focused Admin Sales workflow editor and scoped API so humans and trusted automation can build
  reusable quote templates from controlled choices.
- Emit Sales activity lifecycle records for viewed, declined, approval, sent, revised, question, and
  accepted quote events.
- Prepare controlled downstream conversion plans without bypassing the owner modules.
- Keep sent quote revisions and accepted quote additions immutable and auditable.
- Let Ticket, as the owner domain, process and void Ticket-origin accepted quote delivery only through
  guarded Ticket actions.
- Keep public quote links and Customer Portal quote views aligned.

## Non-Goals

- Replacing the current Sales quote model.
- Turning Sales into the Commercial catalog or Economy invoice engine.
- Automatically creating every downstream record from acceptance.
- Exposing cost, margin, supplier, procurement, internal notes, or approval internals to customers.
- Adding arbitrary recurrence, payment collection, or invoice scheduling.
- Building a separate CPQ microservice.

## Current Behavior

Sales quotes have draft/sent/accepted versions, customer-facing text, quote lines, cadence-specific
presentation totals, secure public links, PDF, portal view, Q&A, and acceptance metadata. Optional
lines exist as a staff-side flag, but customers cannot choose add-ons or alternatives. Acceptance only
marks the version as accepted; it does not store an immutable selection snapshot. Approval state is not
enforced before sending. Decline, lifecycle, and conversion planning are incomplete.

## Proposed Change

Extend existing `sales_quote_versions` and `sales_quote_lines` with CPQ metadata and add Sales-owned
CPQ tables:

- quote option groups for required groups, add-ons, alternatives, and Good/Better/Best style choices;
- quote acknowledgements tied to a quote version or individual line;
- one immutable acceptance snapshot per accepted version;
- downstream conversion plan rows generated from the accepted snapshot.

Staff can configure draft lines as required, optional, recommended, grouped, selected by default, and
quantity-selectable within seller-defined limits. Public and portal quote pages render selectable
options and required acknowledgements. Acceptance validates required lines, option group min/max
rules, quantity bounds, and required acknowledgements before storing the snapshot.

Sending evaluates a settings-driven approval policy. Standard quotes can be sent directly. Risky
quotes enter `pending` approval. Users with `sales.quote.approve` can approve, reject, or request
changes. A rejected or change-requested quote cannot be sent until corrected.

Acceptance creates conversion-plan records for each accepted line using the line's downstream type,
source snapshot, customer-selected quantity, and accepted price snapshot. These are explicit pending
plans owned by Sales until the target domain implements its own conversion action.

Admin Quote Templates maintenance follows the Ticket Workflow pattern: a compact template list opens a
focused create/edit screen, and line/acknowledgement creation stays in separate collapsed sections.
Automation can use the Sales quote-template API with `sales.quote_templates.read` and
`sales.quote_templates.manage` abilities to read the same catalogs and manage the same templates.
Accepted-quote follow-up workflows, such as automatically creating implementation Tickets, are a
separate automation slice and are not part of the quote-template editor.

Sent quote versions are not edited after customer communication. If scope changes before acceptance,
the sent version is superseded and a new draft revision is created. Accepted quote versions are not
edited when later scope changes; Ticket-origin scope added after acceptance creates a separate
additional quote draft for only the new work.

Ticket-origin acceptance is processed by Ticket-owned actions after Sales records the immutable
snapshot. Ticket may automatically create only safe delivery records: Storage reservations for
available stock, draft purchase needs for orderable shortages, or pending Ticket costs. Ticket may
void an accepted quote only before those downstream records become irreversible, and records the
reason and reversal audit.

## Impact Analysis

- Modules: Sales primary; CustomerPortal/public quote surfaces affected; Ticket remains compatible
  through the existing Sales acceptance action.
- Permissions: add `sales.quote.approve`; existing quote edits stay behind `sales.quote_manage`; API
  template automation uses `sales.quote_templates.read` and `sales.quote_templates.manage`.
- Routes: add Sales module routes for approval, public/portal decline, focused template editing, and
  quote-template API operations.
- Data: add CPQ metadata columns and Sales-owned CPQ tables.
- UI: Tech quote editor/detail, public quote, portal quote, PDF/customer-safe quote presentation.
- Queue: existing quote email queue only; no new worker contract.
- Integrations: no external provider change.
- Risk: accepted selection data must be immutable; customer forms must not select hidden/internal
  lines; downstream conversion must not mutate other domains implicitly.
- Risk: superseded public links must not remain acceptable; accepted Ticket quotes must not be edited
  into new promises; voiding must not silently undo picked stock, placed purchase orders, shipments,
  receipts, posted billing, or other downstream facts owned by another workflow.

## Data And Migration Plan

Add one forward migration. Existing quote lines are backfilled as required, selected-by-default,
quantity-locked lines so current quote totals and acceptance behavior remain compatible. Approval
defaults are created through `EnsureSalesDefaults`.

Rollback removes the new CPQ columns and tables. Existing pre-CPQ acceptance fields remain unchanged.

## Testing Plan

- Existing quote send, public view, PDF, portal, question, revision, and acceptance tests keep passing.
- New feature tests cover option group validation, selected/default totals, required acknowledgements,
  immutable acceptance snapshots, decline lifecycle, approval-required send blocking, approval action,
  accepted conversion-plan creation, sent-quote superseding, additional Ticket quotes after
  acceptance, accepted Ticket quote voiding, and irreversible-delivery void blockers.
- Permission seeders and route-permission mapping are covered by Sales/UserManagement regression
  tests where practical.

## Documentation Plan

Update Sales, Ticket, and Integration Knowledge, TODO, the feature-slice index, and human review. Sync
Knowledge after deployment.

## Open Questions

None for this core slice. Actual downstream mutation into Economy/Commercial/Task/ServiceVisit/Storage
must be implemented by separate owner-domain slices that consume the Sales conversion plan.

## Approval

Approved by Svein Tore in conversation on 2026-08-11 by instructing Codex to continue after the #170
completion gap was identified.

## Implementation Status

Implemented on Dev on 2026-08-11 through `docs/feature-slices/2026-08-11-sales-cpq-core.md`.

Delivered behavior:

- Sales-owned CPQ option groups, line metadata, acknowledgements, acceptance snapshots, and conversion
  plan records.
- Customer-selectable public and Customer Portal quote options with live totals and acknowledgement
  validation.
- Settings-backed approval policy and `sales.quote.approve` guarded approval decisions.
- Reusable quote templates/bundles with template option groups, lines, acknowledgements, seller
  checklist, approval hints, focused Ticket Workflow-style create/edit screens, scoped API
  management, and copied template snapshots on quote versions.
- Public/portal quote view, decline, expiry, approval, template, conversion-plan, and acceptance
  lifecycle activity records.
- Sales-owned conversion-plan status/reference/note tracking without automatic writes into Economy,
  Commercial, Asset, Task, ServiceVisit, or future Project.
- Ticket-owned quote delivery processing into safe reservations, draft purchase needs, or pending
  Ticket costs.
- Sent-quote superseding, separate additional Ticket quotes after acceptance, and permissioned
  accepted Ticket quote voiding with safe reversal and audit.

The downstream mutation boundary is intentionally controlled by
`docs/adr/2026-08-11-sales-cpq-accepted-snapshot-boundary.md`: Sales records the accepted snapshot and
conversion plan, while each owner module remains responsible for creating its own records.
