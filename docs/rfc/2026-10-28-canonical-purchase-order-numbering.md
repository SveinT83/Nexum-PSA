# RFC: Canonical Immutable Purchase Order Numbers

Status: Approved implementation scope
Date: 2026-10-28
Owner: Codex / Svein Tore
Issue: #286
Level: 3
Authorization: Svein's direct instruction "Fix alt og merge til main" for the audited Issues,
including the Issue author's preferred canonical generated-number behavior. The alternative
user-controlled internal-reference product is not selected.

## Context And Baseline

The current shared StorePurchaseOrder action accepts caller po_number, the staged
AllocatePurchaseOrderNumber uses a requested value or database ID, UpdatePurchaseOrder overwrites
the field, and FinalizeImportedPurchaseOrder generates an independent AUTO-year-import number.
The create/edit form is still editable and describes an internal reference. Existing uniqueness
does not make the generated-ID approach safe when a legacy custom number already equals a future ID.

TD-Jonathan's allocator class and Store wiring are retained as the common entry. Preserve the
current staged/index snapshot and unrelated Storage API work. Apply the canonical contract on top;
do not remove the staged root helper artifact or rewrite another contributor's index.

## Full Target

Every new manual/API/import order receives one Nexum-generated numeric business number. The
canonical number is distinct from the database ID and supplier's order number (vendor_ref).
Ordinary callers cannot choose it. Create shows a read-only assigned-on-save field; Edit shows
the authoritative saved number read-only. After creation, ordinary actions/API/model saves cannot
renumber it. Existing legacy values, IDs, soft-deleted identities, supplier identity hashes and
work/history links are retained exactly; no backfill renumbers an existing order.

## Allocation And Concurrency

One additive Storage-owned singleton counter row serializes allocation on the same authoritative
database as purchase orders. On first use it reads only existing IDs/numbers, including soft
deletes, and initializes above the relevant existing numeric high-water mark. Bounded integer
validation ignores noncanonical free-form legacy references safely; generated strings cannot
collide with them. Existing unique po_number index remains the final guard. Explicit exact-number
existence checks and bounded duplicate recovery handle a pre-existing/legacy writer race.
Exhaustion or missing schema fails safely; it never falls back to a caller value.

The counter is locked before shared import/order master-data locks. StorePurchaseOrder and
FinalizeImportedPurchaseOrder use the same transaction wrapper; a nested store reuses that lock.
Reserve/advance, pending order identity, final identity, lines and domain work share the outer
transaction. Failure rolls back the new order/counter work; gaps in database IDs are harmless.
The internal temporary UUID identity must never survive a committed successful creation.

The allocator is idempotent for an already-finalized order and cannot rename one. Model-level
immutability complements the locked Update action. Only a freshly created pending order may use
the allocator's initial-assignment path; a retrieved legacy/pending row is not a general repair API.
Direct reviewed database maintenance remains outside ordinary UI/API operations.

## UI, API And Imports

- Create rejects a nonempty supplied po_number with a safe validation error.
- Update permits a compatibility echo of the identical saved number; an attempted change is
  rejected under the locked current order. The update never assigns po_number from request data.
- The UI field has no editable/submitted name and ignores flashed attempted number values.
- Response OpenAPI marks po_number read-only; creation input does not advertise an editable number.
  Any retained update echo is explicitly documented as unchanged-only compatibility.
- Supplier import removes its AUTO generator and calls the same shared Store/allocator path.
  Supplier vendor_ref and existing confirmation/dedup/reconciliation rules are unchanged.
- Existing permissions, supplier/client/site ownership, operational-history/currency locks,
  receiving, reservations, automation/runtime gates and external delivery behavior remain.

## Data, Deployment And Rollback

Add only storage_purchase_order_number_sequences with singleton identity, bounded last_number and
initialization timestamp. Do not change existing PO rows or supplier dedup keys. Apply the exact
migration alone on Dev after an encrypted identity/counter preflight and read-back.
Use normal deployment cache/Swagger refresh and graceful worker adoption for Main/production;
no production deployment is part of this implementation authorization.
Keep the counter/evidence on code rollback; dropping a used counter is refused. Preserve existing
orders and generated-number high-water state. No supplier message, receipt, inventory movement,
queue replay or provider activation is authorized by numbering work.

## Verification

Prove failing regressions first for caller override, action/model rename, editable form and legacy
numeric collision. Then verify creation/edit/API, unchanged echo, rollback/counter atomicity,
soft-deleted/legacy preservation, supplier import/dedup/finalization and relevant Storage flows.
Use independent native MySQL processes for concurrent synthetic creation and exact cleanup/read-back;
do not rewind reserved numbers or print customer/order bodies. Run relevant tests on Dev and an
exact isolated latest-Main candidate before the authorized scoped merge.

## Documentation And Human Review

Update purchase-order/receiving, supplier automation and API source guides; publish/read back only
affected actual handbook sections with access preserved. Track
HR-2026-10-28-PO-NUMBERING as Pending before production/schema rollout and manual create/edit/import
review. Automated tests never mark that checklist Reviewed. Record Dev/Main/production separately.

## Slices

1. Canonical allocator/counter and locked immutability with failing/passing regression evidence.
2. Manual/API/import surfaces, typed contracts, existing workflow/receiving/import regressions.
3. Native concurrency/preflight, actual handbook, exact latest-Main tests and scoped merge/read-back.
All three are required; do not close #286 after a partial allocator change.
