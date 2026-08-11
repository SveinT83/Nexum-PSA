# Feature Slice: Manual And Email Supplier-Order Identity Reconciliation

Status: Implemented - Human Review Pending
Date: 2026-08-07
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Make one Purchase Order represent one supplier order regardless of whether a technician registered
it manually before the confirmation email arrived or Storage created it from a trusted email
import.

## User-Visible Behavior

Manual and email-created Purchase Orders appear in the same canonical **Purchase Orders** list with
clear provenance for manual registration, email creation, and a manual order later confirmed by the
supplier.

When a trusted confirmation has the same supplier and supplier order number as an active manually
registered PO, Storage compares the material order facts and attaches the immutable import/source
evidence to that PO when they agree. It does not create a second PO, replace the internal Nexum order
number, rewrite lines or lifecycle state, receive goods, or change stock.

If material facts disagree, or the matching PO is deleted or cancelled, the import enters
`needs_attention` with an actionable link/reason instead of creating or silently changing a PO.

## Scope

- Define the domain identity as exactly `(supplier/vendor_id, supplier external order
  number/vendor_ref)` after stable normalization.
- Remove surrounding ASCII spaces and normalize case with the active database engine while
  preserving leading zeros, punctuation, tabs/newlines, and internal spacing.
- Allow the same supplier order number at different suppliers.
- Treat a blank supplier order number as having no automatic identity match.
- Add one database-generated normalized identity key to Purchase Orders and enforce a composite
  unique constraint with the supplier across active and soft-deleted PO history.
- Preflight existing data and stop the migration before schema mutation if normalized collisions
  require human resolution.
- Derive application and database identity through the same database normalization so manual
  create/update, import processing, AI repair, retry, finalization, raw inserts, and raw updates
  cannot drift.
- Attach a trusted, materially matching import to an existing active manual PO and preserve the
  manual PO's internal number, lines, dates, warehouse, status, creator, shipments, receipts, and
  stock state.
- Send material mismatches and deleted/cancelled matches to `needs_attention`; never create a second
  PO or overwrite the candidate.
- Reject manual create or update when the normalized identity belongs to another existing,
  email-created, or soft-deleted PO.
- Show accessible source/provenance badges in the canonical Purchase Orders list.
- Keep **Supplier Order Imports** as the audit, retry, and exception queue rather than a competing
  list of ordinary Purchase Orders.

## Out Of Scope

- Fuzzy supplier or order-number matching.
- Name, address, amount, or product-description-only identity matching.
- Automatic correction of a material mismatch.
- Reopening or replacing deleted, cancelled, shipped, received, or otherwise historical POs.
- Shipment-confirmation parsing, supplier order submission, receiving, or stock mutation.
- Adding AI authority to bypass deterministic identity, lifecycle, or receipt guards.

## Data Touched

- `storage_purchase_orders`: nullable database-generated supplier-order key plus a composite
  supplier/key unique index. The former application-written hash column is retained unindexed only
  for an explicit later cleanup after human review.
- `storage_purchase_order_imports`: existing PO link, status/stage/reason context, and immutable
  source provenance.
- Existing Purchase Order metadata/source presentation and list query only.
- No Purchase Receipt, shipment, Stock Unit, Movement, reservation, or on-hand row is created or
  changed by reconciliation.

## Permissions

- Existing `storage.purchase_manage` permission remains mandatory for manual PO create/update.
- Existing supplier-import view/execute permissions continue to guard import source, trace, and
  retry/finalization actions.
- Provenance does not grant Email Inbox access; the original message link keeps its separate Email
  permission check.
- No new permission and no widening from Email or Signal read/rule access is introduced.

## Tests

- Stable normalization/hash tests cover surrounding spaces, database case handling, Unicode,
  leading zeros, punctuation, internal spacing, significant tabs/newlines, blank values, and the
  same number at different suppliers.
- Migration preflight refuses normalized collisions before schema mutation; the generated composite
  unique guard covers soft-deleted history and cannot be bypassed by raw inserts or raw updates.
- Manual-first plus a trusted materially matching email produces one PO, links/imports the source,
  and leaves the PO's internal number, lines, status, dates, warehouse, creator, receipt history,
  movements, and stock unchanged.
- Manual-first with line, quantity, Item/SKU, currency, warehouse, price, line/header total,
  freight, discount, other charge, tax, or explicit order-date disagreement enters
  `needs_attention`, links/describes the candidate, and creates no PO.
- Deleted or cancelled matching candidates enter `needs_attention` and are never resurrected or
  duplicated.
- Email-first then manual create, and a manual update onto another identity, fail with an actionable
  validation error; concurrent create/finalize attempts still produce at most one PO.
- Same supplier order number at another supplier is accepted; a blank supplier order number is not
  auto-matched.
- Existing same-source retry, changed resend, and AI-repair idempotency regressions remain green;
  ordinary edits cannot split a vendor-confirmed identity, while the guarded pre-history AI repair
  updates the PO and import atomically.
- A two-connection MariaDB contract proves generated-key insert/update enforcement and that the
  locking race-recovery read sees the committed winner beyond an older REPEATABLE READ snapshot.
- Purchase Orders provenance badges are accessible and Supplier Order Imports remains the
  audit/exception queue.
- Explicit negative assertions prove reconciliation creates no receipt, Stock Unit, Movement, or
  on-hand change.

## Documentation

- Clarify the parent RFC's shared identity and manual-first reconciliation contract.
- Update Storage Knowledge for supplier order number matching, provenance, conflict handling, and
  the canonical list/audit-queue distinction after implementation.
- Update `docs/TODO.md` and `HR-2026-08-04-003` with migration, automated verification, and named
  human checks.

## Deployment Evidence

- Dev preflight: one PO, zero nonblank supplier order numbers, zero normalized collisions.
- Batch 62 contains the preliminary `100000` migration. Forward-only `101000` ran in batch 63,
  installed the generated composite identity guard, and removed the obsolete hash unique index.
- Live MariaDB 10.6.23 two-connection verification passed raw insert/update, supplier scoping, blank,
  Unicode, REPEATABLE READ stale-read, and locking-read race cases. Its temporary table was removed.
- Focused identity/import: 27 tests / 192 assertions. Affected AI/integrity/policy/purchase: 71 / 622.
  Complete Storage: 247 / 2,528, plus one opt-in contract skip whose live equivalent passed.
  Remaining application modules: 978 / 7,619. Combined: 1,225 / 10,147.
- Cache clear, Blade cache, Pint, three protected-route HTTP smokes, six-article Storage Knowledge
  sync, Supplier Orders cron inspection, and zero-failed-jobs check passed.
- Human review remains `Pending` under `HR-2026-08-04-003`.

## Done Criteria

- [x] One database-enforced normalized identity covers manual, email-created, and historical POs.
- [x] Trusted matching confirmation attaches to an active manual PO without overwriting business
  facts or creating inventory effects.
- [x] Conflicts and historical candidates fail closed in `needs_attention` without a second PO.
- [x] Manual create/update cannot bypass the same identity boundary.
- [x] The canonical Purchase Orders list shows honest, accessible provenance.
- [x] Focused migration, action, import-pipeline, concurrency, UI, and no-stock tests pass on Dev.
- [x] Knowledge, TODO, and the pending human-review entry are current.
- [ ] A named human completes the added checks in `HR-2026-08-04-003`.
