Supplier Orders lets Nexum follow one supplier-order lifecycle from an incoming confirmation, through
the canonical Purchase Order, to partial or complete goods receiving. Registering an order in Nexum
does not place or send an order to the supplier.

## Find, Filter, And Sort Supplier Orders

Open **Storage > Supplier Orders** for the shared operational list:

- An unlinked supplier confirmation appears as one incoming import row.
- A registered Purchase Order appears as one canonical row. A linked import is shown as provenance
  and an audit link inside that row, never as a duplicate work item.
- An order that can receive goods shows its progress, outstanding quantity, control slip, and
  authorized **Receive** action in the same row.

Outstanding quantities from ordered and partially received Purchase Orders also appear as
**Incoming** on the related Inventory item. Inventory shows **On order** while that quantity is
positive. Incoming quantity is planning information only; it becomes on-hand and available stock
only when an authorized user posts the physical goods receipt.

Use **All**, **Needs attention**, **Incoming**, **Purchase Orders**, **Receiving**, or **Completed**
to focus the list without changing records. Search covers order/import identity, supplier, reason,
and tracking data. The detailed filters cover exact status, supplier, destination, expected dates,
tracking number, import stage, and extraction method.

Click **Order**, **Supplier**, **Status**, **Expected / activity**, **Progress**, or **Outstanding** to
sort the current view. Click the same heading again to reverse the direction. Filters and search
remain active, and missing expected dates stay at the end. Older bookmarked Purchase Orders,
Supplier Order Imports, and Receiving index URLs render this same list while preserving their
existing permission boundaries.

## The Same Order Detail For Manual And Email Orders

A manually registered order and an order created or confirmed from email use the same Purchase Order
detail. Order Details, Order Lines, Shipments, tracking identifiers, Receipt History, and lifecycle
actions always come from the canonical Purchase Order.

An email-based order adds one **Email Copy** card at the bottom of the page, after Shipments and
Receipt History, for users who may view supplier imports. It shows the sanitized subject, sender,
recipients, received time, and message body. The original Inbox message can be opened only when it
still exists and the user also has Inbox access.

SPF, DKIM, DMARC, and authentication alignment are retained internally for trusted processing but
are not shown on the order or supplier-import detail pages. Extraction details, profile versions,
policy snapshots, checksums, and AI repair controls remain on the separate supplier-import audit
page for authorized reviewers.

On a purchase-order detail page, Order Lines, each shipment's Allocation Lines, and Receipt History
have sortable data headings. Their choices are independent, so sorting receipt history does not
reorder order lines or shipment contents. Missing optional dates and names stay at the end, while
receipt dates initially remain newest first.

Sorting is deliberately unavailable in purchase-order edit lines, shipment-registration inputs,
goods-receiving inputs, and the printed control slip. Those rows keep their workflow order so input
names and the physical receiving checklist cannot be rearranged accidentally.

## Permissions

- **View purchase orders** allows a user to read orders, shipments, tracking, receipts, and control
  slips.
- **Manage purchase orders** allows creating and editing orders, registering shipments, appending
  tracking identifiers, changing shipment status, cancelling outstanding line quantities, and
  closing or cancelling an order.
- **Receive purchase orders** allows posting accepted and rejected quantities from a delivery.
- **Receive overage** is a separate elevated permission. It is required when accepted plus rejected
  quantity exceeds the outstanding ordered quantity, and the receiver must enter a reason.
- **Reverse purchase receipts** allows an eligible posted receipt to be reversed with a reason.

## Register An Order

1. Open **Storage > Supplier Orders** and choose **Register Order**.
2. Select the supplier used for the placed order.
3. Enter the supplier order reference, expected date, currency, and delivery location. The order
   date defaults to today and may be changed when the supplier order was placed on another date.
4. Add the ordered Storage items and quantities. The supplier SKU and unit cost are prefilled from
   the item's supplier record when available and may be corrected for this order.
5. Save the order.

Nexum snapshots supplier, item, SKU, price, currency, and delivery details on the order. Later
changes to catalogue or supplier master data do not rewrite historical order or receipt context.

Only draft or placed `ordered` purchase orders may be edited. New or replacement references must
use an active supplier, active destination warehouse, and an active orderable Storage item. An
unchanged historical reference remains readable when the related master record is later disabled.
A received quantity is never changed by editing the order; it is derived from immutable receipt
lines.

The supplier order number is unique only together with the selected supplier. Nexum rejects an
active or soft-deleted duplicate, including case/surrounding-space variants, and points the user to
the existing order. If a trusted supplier confirmation later arrives for a compatible manual order,
it confirms and links that order instead of creating another. After confirmation, ordinary editing
cannot change the supplier identity; a mismatch is held for review and never changes stock.

## Shipments And Tracking

One purchase order may have several shipments. Each shipment can allocate all or part of one or
more order lines, so split deliveries can be followed independently.

Choose a carrier from the Documentation carrier register, enter the shipment reference and dates,
then allocate quantities. A shipment may contain several tracking identifiers. Tracking entries are
append-only so a carrier handoff or additional parcel does not erase the earlier audit trail.

The carrier configuration is snapshotted on the tracking entry. Clicking a tracking number uses the
verified HTTPS tracking template that existed when the entry was created. Nexum otherwise uses the
carrier's safe generic tracking page, or displays the number without a link. Recipient-only and
carrier-login tracking methods are labelled honestly. Nexum does not fetch carrier pages or poll
live delivery events automatically.

Shipment status moves forward through its allowed lifecycle. A delivery event cannot be earlier
than the recorded shipment event. A cancelled shipment cannot receive goods or accept new tracking
entries. Cancelling a shipment does not cancel the purchase order or its remaining line quantities.

## Control Slip And Partial Receiving

Use the **Receiving** scope to see every order with outstanding goods. The same row exposes
**Control slip** and, for an authorized receiver, **Receive**.

Open the order and print **Control slip** when the delivery should be counted away from a computer.
The slip lists ordered, previously received, and outstanding quantities with blank fields for the
physical count.

To register the count:

1. Choose **Receive goods** and, when relevant, select the shipment that arrived.
2. Enter accepted and rejected quantities for each delivered line. Lines not in this delivery stay
   at zero.
3. For a serial-, batch-, or expiry-controlled item, identify the accepted units as required.
4. Add an optional delivery note reference and receiving note, then post the receipt.

Each delivery creates its own receipt. Accepted quantity increases on-hand stock and creates an
audited purchase-receipt movement. Rejected quantity is recorded on the receipt but does not enter
inventory. The order remains partial while another outstanding quantity can arrive.

A shipment with explicit line allocations can receive only those allocated lines and quantities.
A shipment without allocations means that its contents are unknown, but Nexum still blocks an
unscoped receipt for a line that has outstanding quantity assigned to another active shipment.
Rejected allocation quantity becomes available for a replacement shipment instead of satisfying
the order.

The idempotency token attached to the receiving form makes an exact retry return the existing
receipt instead of posting stock twice. Reusing the token with different receipt data is rejected.

## Serial, Batch, And Expiry Control

- A serial-controlled item requires one unique serial for each accepted unit.
- A batch-controlled item requires batch allocations whose combined quantity equals the accepted
  quantity.
- An expiry-controlled item requires expiry data on the applicable unit allocation.
- A new tracked item must start with zero initial quantity. Its tracking flags cannot be changed
  while aggregate stock, reservations, or positive stock-unit ledger quantities exist.
- A historical zero-quantity serial row is never reused as a batch or expiry row after a reversal.

Generic manual adjustment and generic Ticket picking are intentionally unavailable for tracked
items or items with a positive stock-unit ledger. These flows cannot identify the exact serial,
batch, or expiry unit. Use purchase receiving for inbound units and a future identified-unit
correction or picking workflow for outbound/correction work.

## Exceptions, Cancellation, And Closing

- Use **Cancel remaining quantity** on an order line when the supplier will not deliver its
  outstanding balance. Enter the cancelled quantity and reason.
- Cancel an unreceived order only after its active shipments have been cancelled. A reason is
  required, and any receipt history prevents treating the order as never received.
- Close an order when receiving is complete and no line has an outstanding quantity.
- Over-receipt is rejected unless the user has the dedicated permission and records a reason.

## Receipt Reversal

A posted receipt is never edited or deleted. An authorized user can reverse it with a reason when
the receipt is still safe to unwind. Nexum creates a linked reversal record and compensating stock
movements; the original receipt remains visible.

Reversal is blocked if stock is no longer available at the original location, a tracked unit has
been consumed or moved, or the aggregate item quantity and stock-unit ledger no longer agree. Fix
the later operational history through the appropriate identified-unit workflow rather than forcing
the reversal.

When a rejected-only receipt is reversed after a replacement shipment or line cancellation was
registered, Nexum terminalizes only the excess reopened allocation. This keeps shipment allocation
totals within the order's real outstanding quantity.

## API

Purchase-order integrations use the following dedicated abilities:

- `storage.purchase.read`
- `storage.purchase.manage`
- `storage.purchase.receive`
- `storage.purchase.receive_overage`
- `storage.purchase.reverse`

The version 1 endpoints under `/api/v1/storage/purchase-orders` use the same lifecycle actions,
validation, permissions, idempotency, and inventory posting as the browser workflow. A normal
receive token cannot over-receive unless it also has `storage.purchase.receive_overage`.

Storage read access does not imply Ticket read access. Linked Ticket identifiers, planned-line IDs,
Ticket subject/key, and Ticket-specific metadata are included only when the API token also has
`tickets.read`. The Tech page similarly requires `ticket.view` before it shows the Ticket identity
or link.

## Audit And Operating Rules

- Never use an order edit or manual stock adjustment to simulate receiving.
- Keep one receipt per physical delivery so partial and split deliveries remain traceable.
- Record rejected goods on the receipt even though they do not increase inventory.
- Append a new tracking identifier when the carrier changes instead of replacing the old one.
- Use receipt reversal only for a genuine receiving error and always record the reason.
- Lifecycle and shipment status history keys are system-owned. Metadata supplied through the UI or
  API cannot replace or manufacture those audit entries.
- Movement history, immutable receipts, reversals, actor snapshots, and timestamps are the audit
  record for every inventory effect.
