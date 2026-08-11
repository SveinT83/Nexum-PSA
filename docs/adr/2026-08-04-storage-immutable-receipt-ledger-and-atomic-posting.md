# ADR: Storage Immutable Receipt Ledger And Atomic Posting

Status: Accepted
Date: 2026-08-04
Decision Makers: Svein Tore / Codex

## Context

Supplier orders can arrive over several deliveries and each delivery may contain only part of one
or more order lines. Confirming a delivery changes physical inventory, cached purchase-order
progress, serial or batch records, and the stock movement ledger. Retrying a request, posting two
receipts concurrently, editing a completed receipt, or deleting receipt data during rollback could
otherwise make those records disagree.

## Decision

Storage records each receiving event as an immutable `PurchaseReceipt` with immutable receipt lines
and optional serial, batch, and expiry unit links. One database transaction locks the purchase
order, order lines, affected items, shipment allocations, and identified stock units before it
posts any accepted quantity.

Each request has a globally unique idempotency token and a canonical payload hash. Reusing the token
with the same payload returns the original posted receipt; reusing it with a different payload is
rejected. Accepted quantities update the cached item and purchase-order balances and create an
immutable `Movement` containing before, delta, after, and receipt-line source references. Rejected
quantities are recorded without entering available stock.

Posted receipts are never edited or deleted. A correction creates a separate reversal receipt,
negative movements, and explicit links to the original receipt, lines, and stock units. Reversal is
conservative: it is blocked when aggregate stock, reservations, location, identified-unit balances,
or intervening unbound stock operations prevent Nexum from proving that the original goods remain
available.

Generic stock adjustments and picking must not bypass an identified-unit ledger. Until a dedicated
serial/batch picking and adjustment workflow exists, operations that cannot identify the affected
stock units are blocked for unit-tracked stock.

Schema rollback must refuse to remove operational shipment or receipt ledgers. Once receipt data
exists, corrections use reversal and schema changes use forward-fix migrations.

## Rationale

The receipt ledger and Movement rows explain why stock changed, while cached quantities keep daily
inventory and purchase-order queries efficient. Row locks serialize competing writers. Idempotency
protects browser double-clicks and integration retries. Reversal preserves the original evidence
and avoids silently rewriting stock history.

Blocking an operation when unit identity cannot be proven is safer than selecting an arbitrary
serial or batch and leaving aggregate and unit balances inconsistent.

## Consequences

- Partial deliveries remain separate auditable events and can be posted one line at a time.
- Inventory, purchase progress, shipment progress, stock units, and movements either all commit or
  all roll back.
- API and browser flows must use the same actions and idempotency rules.
- Over-delivery requires a separate permission and a recorded reason.
- Historical receipts and shipment snapshots increase retained data and cannot be cleaned up with a
  normal migration rollback.
- Unit-tracked stock requires an identified picking/adjustment follow-up before those generic
  operations can be offered honestly.

## Alternatives Considered

Updating only `qty_received` and `qty_on_hand` was rejected because it cannot explain split
deliveries, rejections, retries, serials, or corrections.

Editing or deleting a posted receipt was rejected because it destroys the evidence behind existing
stock movements.

Relying only on database unique constraints was rejected because concurrent writers also need a
deterministic lock order and payload comparison.

## Follow-Up

Implement identified serial selection and batch/expiry allocation for picking and adjustments in a
separate approved Storage slice. Keep automatic carrier polling and accounting matching outside
this receipt-ledger decision.
