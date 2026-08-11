# Feature Slice: Storage Partial Goods Receiving And Inventory Posting

Status: Done
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md`
Owner: Svein / Codex

## Goal

Let an authorized technician confirm any accepted and rejected subset of an externally placed
purchase order, update available inventory exactly once, and retain an immutable receipt and
correction ledger.

## User-Visible Behavior

- A placed or partially received purchase order exposes a receiving checklist and printable control
  slip.
- The technician can post accepted and rejected quantities for one or several lines without changing
  unrelated lines.
- Serial, batch, and expiry details are collected when the Storage item requires them.
- Confirming receipt updates on-hand stock, order progress, shipment allocation progress, and
  immutable movement history in one transaction.
- A repeated request with the same idempotency token returns the original receipt without posting
  stock twice.
- Posted receipts are corrected by a separately authorized reversal rather than edited or deleted.
- A reversal is blocked when the received quantity or identifiable stock unit has been consumed or
  reserved.

## Scope

- Extend purchase orders and lines with supplier/item snapshots, currency, cancellation quantity,
  lifecycle audit fields, and derived outstanding quantity.
- Add purchase shipments, line allocations, multiple tracking identifiers, and immutable carrier
  snapshots while retaining the legacy `tracking_no` column.
- Add receipt headers, receipt lines, identifiable-unit allocations, reversal links, and immutable
  movement source references.
- Add actions for purchase-order creation/update, shipment registration, status derivation, atomic
  partial receipt posting, serial/batch/expiry posting, over-delivery authorization, and guarded
  reversal.
- Require every purchased item to belong to the purchase order's destination warehouse in this
  first single-location balance implementation.
- Treat an order whose active quantities are all accepted or explicitly cancelled as received.
- Keep rejected quantities outside available stock and leave them outstanding for replacement.
- Validate direct tracking links against the selected carrier's snapshotted HTTPS host allowlist.

## Out Of Scope

- Supplier checkout or sending purchase orders from Nexum.
- Carrier polling, webhooks, credentials, booking, or label generation.
- Supplier invoice matching, accounting export, and landed-cost allocation.
- Multi-warehouse balances for one Storage item.
- Partial reversal of one posted receipt.
- Automatic Asset creation from received serials.
- Mutation API parity and release hardening, which remain in the parent RFC's later slice.

## Data Touched

- `storage_purchase_orders`
- `storage_purchase_order_lines`
- `storage_purchase_shipments`
- `storage_purchase_shipment_lines`
- `storage_purchase_shipment_trackings`
- `storage_purchase_receipts`
- `storage_purchase_receipt_lines`
- `storage_purchase_receipt_units`
- `storage_purchase_receipt_reversals`
- `storage_items`
- `storage_stock_units`
- `storage_movements`
- Documentation-owned `shipping_carriers` is referenced read-only and snapshotted by Storage.

No queue, scheduler, external API, or cache-backed state is required.

## Permissions

- `storage.purchase_view` controls purchase-order and receipt visibility.
- `storage.purchase_manage` controls purchase-order and shipment mutation.
- `storage.purchase_receive` controls receipt posting.
- `storage.purchase_receive_overage` permits an explicitly reasoned over-delivery.
- `storage.purchase_reverse` controls immutable receipt reversal.
- Carrier management remains separate under `documentation.carrier_manage`.

Actions enforce data and lifecycle invariants independently of route middleware.

## Tests

Focused backend coverage is in
`app/Modules/Storage/Tests/Feature/PurchaseReceivingActionsTest.php`:

- Supplier, item, SKU, currency, and carrier snapshots.
- Destination-warehouse compatibility.
- All-cancelled lifecycle derivation.
- Shipment allocations, safe tracking resolution, and unsafe direct-link rollback.
- Partial multi-line receipts and independent rejected quantities.
- Stable idempotency without duplicate movements or stock.
- Transaction rollback after a later serial validation failure.
- Accepted-plus-rejected over-delivery guard, explicit authority, and mandatory reason.
- Batch/expiry unit posting.
- Shipment delivery timestamps cannot precede shipment timestamps.
- Historical serial rows cannot be reused by later batch or expiry receipts.
- Shipment-scoped allocations, unknown-content guards, rejected replacement allocation, line
  cancellation, and rejected-only reversal reconciliation.
- Successful reversal and blocked reversal after reservation.
- Movement source links and before/delta/after values.

Final Dev verification includes 24 receipt action tests with 206 assertions, 8 receiving workflow
tests with 92 assertions, a combined affected run of 167 tests with 1,791 assertions, and the full
Laravel suite of 1,010 tests with 8,238 assertions. The later Storage Knowledge seeder compatibility
fix also passes its focused regression and the complete 40-test Knowledge article suite.

## Documentation

- Parent RFC records the approved target behavior and ordered slices.
- Storage Knowledge documentation must be updated with receiving, discrepancies, tracking, serial
  and batch entry, idempotency, and reversal behavior.
- `docs/TODO.md` keeps the workstream In Progress until all slices are verified.
- `docs/human-review.md` must retain an open review entry through manual receiving and reversal
  verification.
- Public website handoff must remain marked do not publish until release verification is complete.

## Done Criteria

- [x] Purchase-order, shipment, tracking, receipt, identifiable-unit, and reversal schema exists.
- [x] Legacy purchase-order tracking numbers are backfilled without dropping `tracking_no`.
- [x] Domain models and relations expose the receipt ledger and shipment progress.
- [x] Purchase-order and shipment actions validate lifecycle, ownership, and snapshot rules.
- [x] Receipt posting is transactional, row-locked, idempotent, and updates stock exactly once.
- [x] Serial/batch/expiry requirements and rejected quantities are enforced.
- [x] Normal over-delivery is blocked and authorized over-delivery requires a reason.
- [x] Full receipt reversal writes negative movements and blocks consumed or reserved stock.
- [x] Focused backend tests pass on Dev.
- [x] Interactive receiving and printable control-slip UI pass route, permission, and rendering tests.
- [x] Storage and Ticket regression suites pass on Dev.
- [x] Migrations and seeders are applied to the Dev runtime database.
- [x] Knowledge, TODO, human-review, and release-handoff records are complete.
- [ ] Named human reviewer completes the open manual checks.
