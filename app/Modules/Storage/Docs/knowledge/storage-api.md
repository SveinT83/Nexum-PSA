Availability: the full Storage API is present on Main. Canonical numbering is verified on Dev
on 2026-10-28 and its Main promotion is tracked with Issue #286. Production deployment and
the human scenario checklist remain separate gates.
Use this guide only against an installation where this API update is deployed.

## Prerequisites
Use an active internal Nexum account with the same Storage permissions required in
the browser. An API key cannot grant permissions the account does not currently hold.
An administrator creates a least-privilege token at **Admin -> Integrations -> API**.
Open **Swagger** at /api/documentation on the target installation for the exact
request fields, scopes and response contracts. Send the bearer token securely in
the Authorization header; never paste it into a support case or handbook article.

Use Accept: application/json. New writes also require a unique Idempotency-Key
header (8-200 characters). Keep that key for retries of the exact same operation.
If GET returns an ETag, send it as If-Match when changing that record to reject
a stale edit. Do not use a new key to repeat a result whose outcome is unknown.

## Register an externally placed supplier order
1. Search GET /api/v1/storage/suppliers?q=... with storage.suppliers.read.
   The account needs storage.view or storage.purchase_view. Lists return active
   suppliers by default. Check the stable ID and role/status fields; an ambiguous
   name returns multiple matches and requires an explicit selection.
2. Read the selected supplier by ID. Use the context and paginated item lookup APIs (or existing warehouse/item reads) to
   identify the delivery warehouse and existing supplier SKU. Supplier records
   remain owned by Documentation; this lookup does not create or change suppliers.
3. If needed, create the item using storage.create and the account's
   storage.item_manage permission. Supply warehouse_id, primary_vendor_id,
   supplier_sku, the item identity/prices and zero initial stock for goods that
   have not arrived. Read back the saved supplier line.
4. Register the order with POST /api/v1/storage/purchase-orders and
   storage.purchase.manage. The account also needs storage.purchase_manage.
   Supply the supplier, destination, external supplier reference, order date for
   an ordered order, currency and item lines. This records an order already
   placed outside Nexum; it does not send a purchase to the supplier.
5. Read the resulting order and item. qty_incoming is outstanding ordered
   quantity. It is separate from qty_on_hand, qty_reserved and qty_available.
   Only explicit receiving or other supported inventory actions change stock.

Nexum generates the canonical numeric po_number on successful creation. Omit po_number
from POST input; a nonempty caller-selected number returns 422. Read the returned value
and retain it as the Nexum business identity; never predict the next value. PUT may echo
the identical saved value for compatibility, but an attempted change returns 422. Existing
legacy identities and the separate external supplier reference are preserved. A numbering
503 requires operator reconciliation of the exact schema/counter, never a caller fallback.

## Inventory, boxes and picking
Use the item filters, summary, supplier lines and movement history to inspect stock.
The incoming quantity includes active placed orders and excludes cancelled or
closed quantities. Box detail includes current items and event history.
Use GET /api/v1/storage/picking with storage.picking.read to inspect reserved Ticket
costs. A keyed POST to /storage/picking/{costEntry}/pick uses the same guarded
Ticket action as **Pick** in the browser. It consumes stock, fulfils the reservation,
updates the cost and performs the existing Ticket/Economy follow-up once.
Serial-, batch- or expiry-tracked stock retains the existing refusal to use generic
picking; the API does not advertise a new tracked-unit workflow.

## Supplier Orders, shipments and receiving
GET /storage/supplier-orders exposes the unified operational queue.
GET /storage/receiving exposes the receiving queue. A caller sees only the
purchase/import branches its current user permissions and token scopes allow.
Existing purchase APIs cover order edits, shipments/tracking, line cancellation,
close/cancel, partial receipts and eligible receipt reversal. The control-slip
endpoint returns structured printable order/shipment data without a browser session.

Receipts and reversals keep their existing idempotency_token in addition to any
HTTP command key. Over-delivery requires the separate receive-overage ability and
current user permission, plus the domain's explanation. A reversal adds compensating
ledger entries; it does not delete receipt history.

## Review supplier imports
Use storage.imports.read to list and inspect imports. Source email evidence is a
sanitized supporting snapshot. Raw mailbox access and provider read state still
belong to Mail; Storage access does not grant mailbox access.

- storage.imports.resolve allows line mapping and audited manual correction.
- storage.imports.execute allows supported distinct-item creation, finalization,
  rejection and controlled retry. Existing actions retain their additional domain
  permissions and lifecycle guards.
- AI repair also requires storage.profiles.manage and the corresponding current
  user profile permission.

Check the returned import status and resulting purchase order. A retry response of
202/pending means queued processing, not a finished import, received stock or an
external supplier order. Finalizing an import does not receive goods.

## Profiles and administrator settings
Current browser administration permissions remain required. Inventory settings have
separate storage.settings.read and storage.settings.update scopes.
Policy uses storage.policy.read / storage.policy.update.
storage.profiles.read covers list/detail and export. storage.profiles.manage covers draft create/import, metadata
audit, immutable version creation, protected fixtures, fresh validation,
activation/rollback, pause/retire and portable export.

A profile must pass the same protected fixture validation before activation as in
the browser. Importing an exported profile produces an inactive draft with local
routing to configure. Validation is an explicit action and never silently activates
a profile. Ordinary policy inputs remain warehouse, selected Storage agent and
business review/fallback behavior; internal actor/workload mechanics stay managed
by Nexum.

## Controlled execution and result read-back
AI repair is default-off at the API boundary (STORAGE_API_AI_REPAIR_ENABLED).
Retry, profile activation/rollback and enabling automation through API settings
require the separate default-off STORAGE_API_AUTOMATION_ENABLED switch and explicit
confirm: true. A deployment operator must authorize activation separately.
Previews report the target and effects without changing business rows or calling AI.

Read GET /storage/operations/{operation_id} with storage.operations.read and the
original operation scopes. Results are restricted to the same actor and token and
recheck current user permissions. Keyed replay returns the retained result without
repeating stock, profile, import or provider effects. Reusing a key for another
request returns 409. An interrupted external call remains running/unknown with
reconciliation_required: true; inspect the retained evidence before a new command.
A completed API command can still contain a pending import: command completion
does not prove asynchronous work or external delivery finished.

## Troubleshooting
- 401: authenticate against the intended installation.
- 403: verify both token scopes and the account's current permissions/status.
- 404: verify the stable ID and the parent-child relationship.
- 409: reload lifecycle state, check key reuse and the API runtime switches.
- 412: fetch a fresh revision and review the changed data before retrying.
- 422: correct the named fields or the reported domain refusal.
- 428: provide the required idempotency header.
- 503: ask the operator to verify the API migration/storage and inspect operation
  state before repeating an uncertain write.

Human scenario review is tracked in HR-2026-10-26-STORAGE-API. Automated tests and
Dev availability do not constitute production deployment or manual acceptance.
