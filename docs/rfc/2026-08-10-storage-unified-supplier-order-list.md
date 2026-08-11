# RFC: Storage Unified Supplier Orders List

Status: Implemented
Date: 2026-08-10
Owner: Svein / Codex

## Context

Storage currently exposes three separate list pages for one operational lifecycle:

- Supplier Order Imports is the intake, audit, and exception queue.
- Purchase Orders is the canonical supplier-order register.
- Receiving repeats the subset of Purchase Orders that still has goods outstanding.

The separation makes users move between lists to understand one supplier order. It also makes a
supplier confirmation appear to be a separate work item after it has already been attached to a
Purchase Order.

This is a Level 2 workflow and UI change. It changes list presentation and navigation inside Storage,
but it does not change import, Purchase Order, shipment, receipt, inventory, permission, or API
semantics.

## Goals

- Provide one compact **Supplier Orders** list for supplier-order imports, Purchase Orders, and
  receiving work.
- Render an unlinked Supplier Order Import as one incoming row.
- Render a Purchase Order as one canonical row, including linked import provenance without adding a
  duplicate import row.
- Use the same canonical Purchase Order detail for manual and email-created orders. An email-created
  order adds only a readable email copy; item lines, shipments, tracking, receipts, and lifecycle
  actions continue to come from the Purchase Order.
- Make open receiving work visible through status, quantities, filters, and row actions.
- Preserve direct access to the import audit/detail page, Purchase Order detail page, receiving form,
  and printable control slip.
- Keep the list useful for users whose permissions expose only imports, only receiving work, or the
  full procurement workflow.

## Non-Goals

- Merging import, Purchase Order, shipment, or receipt database records.
- Changing supplier-order identity, idempotency, source-evidence, or no-stock-impact guarantees.
- Posting inventory from an import or from the list.
- Changing receipt posting, reversal, shipment, or Purchase Order lifecycle rules.
- Widening permissions or adding a new permission.
- Removing legacy index URLs that may exist in bookmarks or documentation.
- Changing Storage APIs, queue workers, scheduler jobs, or supplier automation.

## Proposed Change

### Canonical Work Surface

The shared Storage navigation exposes one **Supplier Orders** entry instead of separate Purchase
Orders, Supplier Order Imports, and Receiving entries.

The existing index routes remain available and permission-protected:

- /tech/storage/purchase-orders
- /tech/storage/supplier-order-imports
- /tech/storage/receiving

Each route renders the same Supplier Orders list. The data included in the list remains bounded by
the permissions already required by that route and by the user's existing permissions.

### One Row Per Operational Order

The list has two row types:

1. An unlinked import row for a Supplier Order Import that has no visible active Purchase Order.
2. A Purchase Order row for every visible Purchase Order.

When an import links to a Purchase Order, the import is shown as provenance and an audit link inside
the Purchase Order row. It is not emitted as another list row. This preserves one operational row
without hiding the immutable import trace.

### Canonical Purchase Order Detail

Manual and email-created Purchase Orders use the same detail page and the same Order Details, Order
Lines, Shipments/tracking, Receipt History, and lifecycle actions. These sections always render from
the canonical Purchase Order records rather than from a second import-specific order presentation.

When a visible Purchase Order has an email source and the viewer already has
`storage.purchase_import_view`, the page adds one **Email Copy** card with:

- subject;
- sender and recipients;
- received time;
- the immutable sanitized email body; and
- an optional link to the original Inbox message when it still exists and the viewer also has
  `email.inbox_view`.

The Email Copy is the final main-content section after Shipments and Receipt History, keeping the
operational order, shipment, tracking, and receiving workflow ahead of its source evidence.

The operational Purchase Order page does not show SPF, DKIM, DMARC, authentication alignment,
profile versions, policy snapshots/checksums, extraction internals, or AI repair controls. Profile,
policy, extraction, and repair details remain in the permission-protected Supplier Order Import
workflow. Authentication evidence remains persisted and enforced internally, but is not presented
on the Purchase Order or Supplier Order Import detail pages. No permission is widened by this
presentation change.

### Receiving In The Same Row

A Purchase Order row shows ordered, received, cancelled, and outstanding quantities. When the order
is in an allowed receiving state and has outstanding quantity, the row exposes:

- a printable control-slip action to users who may view the Purchase Order; and
- a Receive action only to users with storage.purchase_receive.

Receiving remains an explicit form submission. The list never posts a receipt or changes stock.

### Filters And Sorting

The shared list supports bounded search and allowlisted filters for:

- all work;
- needs attention;
- incoming imports;
- Purchase Orders;
- receiving;
- completed work;
- exact underlying status;
- supplier;
- destination warehouse;
- expected date;
- tracking number;
- import stage and extraction method.

Sorting remains server-side and allowlisted. Default ordering is most recently updated first with a
stable row tie-breaker.

### Permission Boundary

No new permission is introduced.

- storage.purchase_view exposes Purchase Order rows.
- storage.purchase_import_view exposes unlinked import rows and linked import audit links.
- storage.purchase_receive exposes receiving actions and, when used without Purchase Order view,
  only the open receiving subset already available through the legacy Receiving route.
- Mutation permissions remain unchanged and are enforced by their existing routes.

The shared navigation chooses an index route the current user is already authorized to open.

## Impact Analysis

### Modules And Files

- Storage: shared query, controllers, Blade list, navigation, tests, and Knowledge docs.
- Platform UI: centralized Storage breadcrumbs.
- No other module behavior changes.

### Risks

- A linked import could appear twice unless the query excludes imports that already have a visible
  active Purchase Order.
- A permission-limited user could see rows outside the old route scope unless the query includes
  explicit source flags.
- Mixed row types could make sorting unstable unless the query uses normalized columns and a stable
  tie-breaker.
- Receiving could look automatic unless actions remain explicit and the list explains the
  no-stock-impact boundary for imports.
- A technical source card could make email-created orders look like a separate workflow or expose
  governance details that are irrelevant during ordering, shipment tracking, and receiving.

### Mitigations

- Build the list as one normalized server-side query.
- Keep import and Purchase Order detail routes unchanged.
- Apply permission flags before building each query branch.
- Render receiving controls only when lifecycle, outstanding quantity, and permission all allow it.
- Add feature coverage for row deduplication, permission-limited routes, filters, sorting, and action
  visibility.
- Keep profile, policy, extraction, and repair evidence on the import audit page, retain trusted
  authentication evidence internally without presenting it, and render only a sanitized,
  permission-protected Email Copy card as an optional addition to the canonical Purchase Order.

## Data, Deployment, And Rollback

No migration, seeder, queue restart, scheduler change, or frontend build is required.

Deployment requires normal cache clearing and focused route/view verification. Rollback restores the
three index templates and navigation items; no data rollback is needed.

## Documentation And Human Review

- Update Storage Purchase Orders/Receiving and Supplier Order Automation Knowledge sources.
- Update the existing Storage human-review entries so the manual checks use the unified list.
- Human review must confirm one-row deduplication, permission-limited visibility, filters, receiving
  actions, mobile table behavior, identical manual/email order structure, a readable email copy, and
  unchanged detail workflows.

## Approval

Approved by Svein Tore in the Codex task on 2026-08-10 with the explicit product direction that
Supplier Order Imports, Purchase Orders, and Receiving must be in one and the same list. Reversible
technical defaults in this RFC preserve existing records, routes, permissions, and mutation
boundaries while implementing that requested result.

The detail refinement was explicitly approved in the same task on 2026-08-10: manual and email-based
orders should look almost identical; email-based orders add the email copy, while the operational
page should focus on the order header, item lines, freight/shipment details, and tracking rather than
SPF, DKIM, or similar technical evidence.

The same task later clarified that Email Copy should appear completely at the bottom of the Purchase
Order detail, after Shipments and Receipt History.
