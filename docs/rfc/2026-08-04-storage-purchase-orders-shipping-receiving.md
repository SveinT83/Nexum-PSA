# RFC: Storage Purchase Orders, Shipping Tracking, And Goods Receiving

Status: Implemented
Date: 2026-08-04
Owner: Svein / Codex

## Context

Nexum already has Storage purchase-order and purchase-order-line tables, but they are only a partial
foundation. Ticket Workflow can create an idempotent draft purchase need after customer approval. It
explicitly does not place or send an order to a supplier. There are no Storage routes, controllers,
views, API endpoints, shipment records, carrier records, receiving records, or actions that update
received quantity and stock.

A user may already have placed an order in a supplier web shop or by another external process. Nexum
must let the user register that placed order, follow one or more shipments, and receive any subset of
the ordered lines and quantities. Confirming a goods receipt must update inventory automatically and
leave an immutable audit trail.

The Documentation workspace already owns fixed Vendor and Supplier master-data registers. Shipping
carriers should follow that structured-register pattern rather than being stored as free-form
Documentation records.

This is a Level 3 change because it adds cross-module data, permissions, API contracts, lifecycle
rules, and stock-affecting transactions.

## Goals

- Register supplier orders that were placed outside Nexum.
- Preserve and convert existing Ticket-created draft purchase needs without sending vendor orders.
- Support one purchase order with many shipments and many tracking identifiers.
- Support split shipments and repeated partial receipts by line and quantity.
- Provide a printable receiving checklist/control slip showing ordered, previously received,
  outstanding, accepted now, and rejected now.
- Post a confirmed receipt atomically so accepted quantities update on-hand stock, purchase-order
  progress, stock units, and immutable movement history exactly once.
- Capture serial numbers, batch numbers, and expiry dates when the Storage item requires them.
- Add a fixed Shipping Carriers register inside Documentation with the carrier website, tracking
  page, tracking-link method, verified host rules, and lifecycle state.
- Make a tracking number on the purchase-order page resolve automatically to the best safe carrier
  link available.
- Seed a practical, maintainable set of carriers used for shipments delivered in Norway.
- Keep the complete workflow responsive in the existing Nexum PWA on desktop and mobile.
- Preserve clear ownership: Documentation owns carrier master data; Storage owns procurement,
  shipments, receipts, and inventory effects.

## Non-Goals

- Automatically submit or send purchase orders to suppliers.
- Log in to supplier web shops or automate supplier checkout.
- Supplier invoice matching, accounts payable, landed-cost allocation, or accounting export.
- Outbound customer shipping and fulfilment.
- Redesigning Storage into a new per-location stock-balance system.
- Carrier booking, label purchase, or automatic carrier API polling in the first implementation.
  The model must support later provider adapters, but credentials, polling, and webhooks require
  separate Integration slices.
- Replacing the existing Storage barcode-scanning TODO.
- Automatically creating Assets from received items. That may be a later Storage/Asset slice.

## Current Behavior

- storage_purchase_orders stores a purchase-order number, supplier, destination warehouse, free-form
  status, vendor reference, one tracking number, ordered and expected dates, notes, and actor IDs.
- storage_purchase_order_lines stores an item, ordered and cumulative received quantities, cost, tax,
  expected date, optional Ticket links, and metadata.
- RequestTicketPurchase creates a draft order line from approved Ticket scope and records that no
  vendor order was sent.
- Storage Item stores supplier data, on-hand and reserved quantities, serial/batch flags, and reorder
  information.
- Storage Movement is the audit record for stock changes and already supports source type/source ID.
- StockUnit can store serial, batch, expiry, location, status, and quantity.
- No code updates qty_received after creation.
- No purchase-order, shipment, tracking, or receiving UI/API exists.
- The single purchase-order tracking number cannot describe several parcels, carrier handoff, or
  different arrivals.
- Documentation routes Vendors and Suppliers to fixed master-data screens; all other categories are
  template-driven documentation records.

## Proposed Change

### 1. Domain Ownership

Storage remains the owner of:

- Purchase orders and lines.
- Supplier-order lifecycle.
- Shipments and shipment-line allocations.
- Tracking identifiers recorded against shipments.
- Receiving checklists, posted receipts, reversals, and stock movements.
- Storage web routes, API routes, permissions, actions, queries, views, and tests.

Documentation remains the owner of:

- Canonical Vendor/Supplier identity.
- A fixed Shipping Carriers register and carrier-profile form.
- Carrier website and tracking-link configuration.
- Carrier seed data and carrier CRUD tests.

Integration only becomes involved when a later approved slice adds carrier credentials, polling,
booking, or webhooks. Knowledge owns user documentation, not operational carrier records.

### 2. Carrier Identity And Tracking Profiles

Create a Documentation-owned shipping_carriers table and ShippingCarrier model. A carrier profile is
a transport brand or division and may optionally link to a canonical Vendor record. This keeps DHL
Express, DHL Freight, Posten, and Bring distinct where their tracking systems differ, without forcing
carrier-specific configuration into the general vendors table.

Minimum carrier fields:

- Stable code and display name.
- Optional canonical vendor_id and legal name.
- Lifecycle state: active, legacy, or inactive.
- Sort order and service tags such as parcel, express, freight, domestic, and international.
- Official website URL, support URL, and generic tracking-page URL.
- Tracking method: template, generic_page, provider_generated, api, or manual.
- Optional tracking URL template containing exactly one {tracking_number} placeholder.
- Allowed HTTPS tracking hosts.
- Link visibility: normal, recipient_only, or authenticated.
- Optional connector type for a future Integration adapter, without secrets.
- Source URL, verification state, verified date, notes, and audit actor/timestamps.

Add a fixed Shipping Carriers item to the Documentation sidebar and dedicated list, create, show, and
edit pages. It must not use DocumentationTemplate or data_json.

Tracking-link resolution uses this order:

1. A shipment-specific provider-generated/direct URL.
2. A verified carrier URL template with a URL-encoded tracking number.
3. The carrier generic tracking page, while keeping the tracking number easy to copy.
4. Plain text when no safe URL exists.

Only HTTPS links whose host matches the carrier allowlist may be rendered as clickable. Tracking
templates are administrative data and must never become server-side fetch targets. Carrier edits do
not rewrite historical shipment snapshots.

### 3. Seeded Carriers

Seed missing profiles idempotently without overwriting administrator changes. The initial curated set
is:

- Posten.
- Bring.
- PostNord.
- DHL Express.
- DHL eCommerce.
- DHL Freight.
- DHL Global Forwarding.
- UPS.
- FedEx.
- DSV.
- DB Schenker as a legacy/transition profile while separate Schenker tracking remains relevant.
- GLS.
- Helthjem.
- Instabox.
- Porterbuddy.
- Budbee as an inactive or legacy alias rather than an active Norwegian default.

Posten and Bring remain separate user-facing profiles even if a future adapter is shared. DHL
divisions remain separate because they use different shipment and tracking systems. DSV and legacy
DB Schenker tracking remain separate until the transition no longer requires it.

Seed sources and tracking methods must be verified against official carrier pages immediately before
implementation. Undocumented deep-link query parameters must not be treated as permanent contracts.

### 4. Purchase Order Registration And Lifecycle

Add a Storage Purchase Orders work queue and create/edit/show flows.

Purchase-order states:

- draft: internal need only; no external order is represented.
- ordered: the supplier order has been placed.
- partially_received: at least one accepted receipt exists and an active line remains outstanding.
- received: every active ordered quantity has been accepted or explicitly cancelled.
- closed: explicitly completed and locked for normal editing.
- cancelled: cancelled before completion.

partially_received and received are derived by domain actions, not set directly by a form.

The form records supplier, destination warehouse, internal purchase-order number, supplier reference,
order date, expected date, currency, notes, and lines. Lines use existing Storage items and snapshot
the item name, internal SKU, supplier SKU, unit cost, tax, and currency used when ordered.

Line quantities:

- qty_ordered is the confirmed order quantity.
- qty_received remains a cached aggregate of accepted posted receipt lines.
- qty_cancelled records quantities the supplier will not deliver.
- qty_outstanding is derived as ordered minus received minus cancelled, never below zero.

Existing Ticket-created draft purchase needs can be attached to or converted into a placed purchase
order only when supplier and destination warehouse are compatible. Their Ticket and planned-line
links remain intact and the conversion is idempotent.

The first implementation records an order already placed elsewhere. No button or transition may claim
that Nexum sent an order to the supplier.

### 5. Shipments And Tracking

A purchase order has many PurchaseShipment records. Each shipment stores its lifecycle status,
shipped/expected/delivered dates, notes, and a snapshot of its selected carrier profile.

A shipment may allocate expected quantities across any subset of purchase-order lines. Allocations
are optional when the supplier has not stated package contents, but they improve the receiving
defaults and outstanding view.

A shipment has one or more tracking identifiers so Nexum can represent:

- Several parcels for one purchase order.
- A master consignment plus parcel identifiers.
- Carrier handoff and a second last-mile tracking number.
- A provider-generated tracking URL that cannot be reconstructed from the number.
- Historical tracking even after a carrier is renamed or deactivated.

Shipment status is manual in the first implementation. Provider-specific automatic status retrieval
requires a later approved Integration slice.

### 6. Receiving Checklist And Partial Receipts

Add a Receive Goods action from a placed purchase order or shipment.

The receiving surface is a printable control slip and an interactive form. Each order line shows:

- Item and supplier SKU.
- Ordered quantity.
- Previously accepted quantity.
- Cancelled quantity.
- Outstanding quantity.
- Shipment-allocated quantity when known.
- Accepted now, defaulting to zero.
- Rejected/damaged now, defaulting to zero.
- Optional discrepancy note.
- Serial, batch, and expiry inputs when required.
- Destination warehouse and optional room/box context allowed by current Storage rules.

A user may post one line, several lines, or all outstanding lines. Repeated receipts remain separate
events. Receiving one line never marks unrelated lines as received.

Posting creates an immutable PurchaseReceipt header and PurchaseReceiptLine rows. It stores the
purchase order, optional shipment allocation, delivery-note reference, received time, actor,
destination, notes, and a stable idempotency token.

### 7. Atomic Stock Posting

PostPurchaseReceipt runs in one database transaction and locks the purchase order, affected lines,
items, and relevant stock units.

For every accepted quantity it:

- Revalidates outstanding quantity.
- Creates the receipt line.
- Creates serial/batch StockUnit records when required.
- Increments Item qty_on_hand.
- Increments the purchase-order-line cached qty_received.
- Creates receive Movement rows with receipt-line source type/source ID and before/delta/after values.
- Recomputes shipment and purchase-order progress.
- Clears should_order only when the existing reorder calculation no longer requires ordering.
- Leaves rejected/damaged quantity outside available stock and records the discrepancy.

The receipt status transition and idempotency token ensure that retrying or double-clicking cannot
post stock twice. Any validation or movement failure rolls back the whole receipt.

By default, accepted plus previously received cannot exceed ordered minus cancelled. An over-receipt
requires a separate, explicit permission and a mandatory reason; otherwise it is blocked. This keeps
the normal workflow safe while still allowing a deliberate supplier over-delivery policy later.

Serial-tracked items require the exact number of unique serials accepted now. Batch-tracked items
require batch allocation, and expiry-enabled items require an expiry date when the batch is posted.

### 8. Receipt Corrections

Posted receipts are never edited or deleted. A correction creates a reversal receipt and matching
negative movements linked to the original receipt.

A reversal is blocked when the received stock can no longer be safely identified or would make
on-hand quantity negative. In that case, the user must resolve consumed/reserved stock first or use a
separately authorized inventory-adjustment workflow. All reasons and actors are retained.

### 9. User Interface

Update 2026-08-10: the approved
`docs/rfc/2026-08-10-storage-unified-supplier-order-list.md` supersedes the separate index/menu
presentation below. Supplier Order Imports, Purchase Orders, and Receiving now share one Supplier
Orders list; the lifecycle, detail pages, permissions, and receiving invariants in this RFC remain
unchanged.

Extend the shared Storage workspace menu with:

- Inventory.
- Purchase Orders.
- Receiving.
- Picking List.

The Purchase Orders index is a compact operational list with search and filters for status, supplier,
warehouse, expected date, and tracking number. It shows order number, supplier, lifecycle, ordered
date, expected date, line progress, shipment signal, and outstanding quantity.

The purchase-order detail page shows:

- Compact order metadata.
- Line progress with ordered/received/cancelled/outstanding quantities.
- Shipment cards or rows with clickable tracking identifiers.
- Receipt history and discrepancy signals.
- Links back to originating Tickets where present.
- Clear actions for editing a draft/ordered order, adding a shipment, printing a control slip,
  receiving goods, cancelling outstanding quantity, and closing the order.

Actions appear only when implemented, permitted, and valid for the current lifecycle. The workflow
uses Bootstrap, existing shared components, centralized breadcrumbs, dense tables, and the same
responsive PWA URLs on desktop and mobile.

### 10. Permissions

Retain storage.purchase_manage for purchase-order and shipment mutation unless implementation
analysis proves that it is too broad. Add explicit permissions where separation is needed:

- storage.purchase_view.
- storage.purchase_receive.
- storage.purchase_receive_overage.
- storage.purchase_reverse.
- documentation.carrier_manage.

Read-only Storage users must not receive stock. Carrier management changes tracking configuration
but must not grant Storage stock permissions.

Every new route must map to the exact permission before the broad Storage view fallback. API abilities
must be added only together with working endpoints and tests.

### 11. API And Automation Boundary

After the browser workflow is stable, add Storage-owned versioned API resources for purchase-order
read/create/update, shipment/tracking management, receipt draft/post, and reversal. Use the same
actions, validation, lifecycle rules, locking, and idempotency as the browser.

Do not add carrier API credentials to Documentation records. A future carrier adapter stores secrets
through Integration and writes normalized status/events through a Storage-owned contract.

### 12. Audit And History

Purchase-order status changes, line quantity changes, shipment changes, tracking changes, receipts,
rejections, over-receipts, reversals, and carrier configuration changes must store actor and
timestamps. Stock truth remains explainable from immutable receipt lines and Movement rows, not only
from mutable aggregate columns.

## Impact Analysis

### Modules

- Storage: primary implementation owner.
- Documentation: carrier register and fixed workspace UI.
- Ticket: purchase-need conversion, source links, workflow facts, and regression tests.
- Integration: API ability catalog now; carrier connectors only in later approved slices.
- Knowledge: Storage and Documentation user guides plus BookStack sync.
- Taxonomy/menu: fixed Documentation sidebar discoverability.
- System/User Management: seeded permissions and role grants.
- Economy, Commercial, Asset, Notification, and Signal are not changed in the first implementation.

### Routes And UI

All Storage routes remain in app/Modules/Storage/routes.php and API routes in the Storage module API
file. Documentation carrier routes remain in app/Modules/Documentation/routes.php. Controllers and
views stay inside their owning modules.

### Data And Concurrency Risks

- Duplicate receipt posting could inflate stock unless idempotency and row locks are enforced.
- Partial receipts and cancellations can create negative outstanding quantity unless all mutations
  use shared domain actions.
- Existing Ticket purchase needs may already exist and must not be duplicated or orphaned.
- A single tracking number may already be stored on a purchase order and must be migrated safely.
- Carrier URLs may change, contain sensitive bearer links, or point to unsafe hosts.
- Serial numbers must be unique according to the current Storage scope.
- Reversal after stock consumption can corrupt inventory unless guarded.
- Purchase orders have a destination warehouse while Item currently stores one warehouse; the first
  implementation must require compatible warehouse ownership and must not pretend to provide a new
  multi-location balance model.

### Operational Dependencies

No queue or scheduler is required for manual tracking and receiving. A later automatic tracking
adapter would require credentials, queued polling/webhooks, retry policy, rate-limit handling,
privacy review, and provider-specific tests.

## Data And Migration Plan

1. Add carrier-profile tables and optional canonical Vendor relation under Documentation ownership.
2. Extend purchase-order headers and lines with currency, snapshots, cancellation, and lifecycle
   support without dropping existing columns.
3. Add shipment, shipment-line-allocation, and shipment-tracking tables.
4. Add receipt, receipt-line, and reversal-link tables with actor and idempotency constraints.
5. Backfill a legacy purchase-order tracking number into a default shipment/tracking record while
   retaining the old column during a compatibility window.
6. Preserve every Ticket and planned-line link.
7. Add indexes for purchase-order lifecycle, supplier/warehouse, expected date, tracking number,
   receipt idempotency, and receipt source links.
8. Seed missing carrier profiles and permissions idempotently.
9. Run migrations and seeders on Dev before feature verification.
10. Drop or stop writing the legacy tracking_no column only in a later cleanup migration after all
    reads use shipment tracking.

Rollback must not delete posted receipts or movements. Schema rollback is safe only before production
receipt data exists; after that, reversal/export and a forward-fix migration are required.

## Feature Slices

### Slice 1: Documentation Shipping Carrier Register

- Carrier schema/model, fixed Documentation CRUD, safe URL resolver configuration, seed data,
  permissions, navigation, tests, Knowledge documentation, and the ownership ADR.
- No Storage tracking UI until the carrier register is complete and tested.

### Slice 2: Purchase Order Registration And Shipment Tracking

- Purchase-order lifecycle, lines, Ticket purchase-need conversion, shipments, allocations, multiple
  tracking identifiers, safe click-through links, Storage navigation, UI, API read boundary, and
  tests.
- No receiving action is exposed in this slice.

### Slice 3: Partial Goods Receiving And Inventory Posting

- Printable/interactive control slip, receipt ledger, partial line quantities, serial/batch/expiry,
  discrepancies, atomic movements, idempotency, concurrency guards, lifecycle recomputation,
  reversals, responsive UI, and tests.

### Slice 4: API, Cross-Module Hardening, And Release Readiness

- Mutation API parity where required, Ticket workflow regression, OpenAPI/ability updates, broad
  tests, Knowledge/BookStack updates, Dev HTTP/browser checks, deployment notes, TODO status, and
  human-review entry.
- Carrier polling remains a separately approved Integration slice.

## Testing Plan

### Documentation

- Fixed Shipping Carriers routes do not use dynamic documentation templates.
- Create/edit/show/list permissions are enforced.
- URL templates require one placeholder and HTTPS allowed hosts.
- Unsafe, malformed, and mismatched tracking URLs are rejected or rendered non-clickable.
- Seeders are idempotent and preserve administrator changes.
- Active, legacy, and inactive profiles behave correctly.

### Storage

- Register a placed order with several lines.
- Convert compatible Ticket draft needs without duplicate lines.
- Block incompatible supplier/warehouse conversion.
- Add several shipments and several tracking identifiers.
- Resolve direct, template, generic, and unavailable tracking links safely.
- Receive one line partially, another line fully, and leave other lines untouched.
- Repeat partial receipts until the order derives received.
- Receive multiple lines in one receipt.
- Reject/damage quantity without increasing available stock.
- Require and validate serial, batch, and expiry data.
- Block ordinary over-receipt and require permission/reason for an allowed exception.
- Retry the same receipt idempotently without duplicate stock.
- Simulate failure mid-post and verify the transaction rolls back all aggregates and movements.
- Verify concurrent receipt attempts cannot exceed outstanding quantity.
- Reverse an eligible receipt and block an unsafe reversal.
- Preserve movement source links and before/delta/after values.
- Enforce warehouse compatibility and lifecycle guards.
- Verify responsive Blade rendering and printable control-slip output.

### Cross-Module And API

- Existing Ticket Workflow purchase-need tests remain green.
- Ticket source links and workflow facts remain correct after conversion and receipt.
- Permission route mappings prevent view-only users from receiving.
- Browser and API operations use the same actions and produce identical audit data.
- Sanctum abilities and OpenAPI documentation match implemented routes.
- Focused Documentation, Storage, Ticket, Integration, and permission suites pass on Dev.
- Run the broad Laravel suite before release handoff when practical.

Automated tests do not replace the required human-review entry for this Level 3 update.

## Documentation Plan

- Update Storage module Knowledge documentation for purchase orders, shipments, receiving, serial
  and batch capture, discrepancies, reversals, and permissions.
- Add Documentation Knowledge text for the Shipping Carriers register and safe tracking methods.
- Correct the legacy Storage specification and README claims that do not match implemented behavior.
- Update the Ticket purchase-need documentation after conversion links exist.
- Update API/OpenAPI documentation only for implemented endpoints.
- Add the approved workstream near the top of docs/TODO.md.
- Add an ADR for Storage procurement ownership and Documentation carrier-profile ownership.
- Add and maintain one stable entry in docs/human-review.md during implementation.

## Open Questions

No product decision blocks an RFC draft. The recommended defaults are:

- Register externally placed orders; do not send supplier orders.
- Block over-receipt unless a separately permitted user supplies a reason.
- Keep carrier tracking click-through/manual status in the first implementation.
- Store carrier profiles separately from free-form Documentation records and optionally link them to
  canonical Vendor records.
- Require compatible supplier and destination warehouse when converting Ticket purchase needs.

Approval of this RFC confirms these defaults. Any requested change should be added here before code
implementation begins.

## Approval

Approved by Svein Tore on 2026-08-04 in the Codex task after reviewing the recommended defaults.
Implementation is authorized through the Feature Slices defined in this RFC.
