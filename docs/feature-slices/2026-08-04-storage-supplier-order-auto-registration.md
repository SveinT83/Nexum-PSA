# Feature Slice: Supplier Order Auto-Registration And Source Card

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Apply layered deterministic policy to create a draft or register an externally placed supplier
order as `ordered`, using existing Storage actions, a configured actor, full idempotency, and a
Source Email & Automation card.

## User-Visible Behavior

A trusted deterministic import can quietly create a Purchase Order when configured conditions pass.
Other imports remain in review with explicit blocking reasons. The resulting PO shows an immutable
source-email/automation card, original Inbox link when permitted, import trace, and safe manual
repair actions.

Users may edit allowed PO facts manually. Existing shipment, cancellation, and receipt locks remain
unchanged.

## Scope

- Implement the effective-policy intersection and advanced ordered decision rules.
- Support `review`, `create_draft`, and deterministic `register_ordered` outcomes.
- Implement source-trust, profile health, evidence, arithmetic, supplier, external-order uniqueness,
  warehouse, currency, amount/line/quantity/new-master-data caps, Item resolution, and actor hard
  gates.
- Generate a unique internal Nexum PO number separately from the supplier external reference.
- Use explicit order date or the configured source-received fallback with provenance.
- Finalize only through `StorePurchaseOrder` and related Storage actions in a transaction.
- Lock the import and recover the existing PO after Signal/queue/manual retry or worker crash.
- Implement same-order same-hash duplicate no-op and changed-resend conflict behavior.
- Preserve normalized freight, discount, charges, delivery, and source totals in the import/PO source
  presentation without posting accounting or landed cost.
- Add the compact **Source Email & Automation** card to PO detail with sanitized snapshot, trace,
  method, profile/policy versions, decisions, and manual actions.
- Require Email permission for the original Inbox deep link; retain safe snapshot when unavailable.
- Add manual reprocess/preview and safe correction through ordinary lifecycle guards.
- Keep normal successful imports silent unless digest/notification settings later enable output.

## Out Of Scope

- AI extraction or profile repair.
- Supplier-order submission.
- Automatic changed-resend reconciliation after immutable history.
- Receipt, shipment-status inference, Stock Unit, Movement, or on-hand mutation.
- Supplier invoice/accounting semantics.

## Data Touched

- Storage imports, policy revisions, normalized source snapshots, lines, attempts, and PO link.
- Existing Purchase Orders and lines through shared actions only.
- Optional source-commercial/charge representation approved during schema analysis.
- No receipt, Stock Unit, Movement, or qty-on-hand rows.

## Permissions

- `storage.purchase_import_execute` for manual retry/reprocess/finalize.
- `storage.purchase_import_resolve` for safe corrections.
- `storage.purchase_import_view` for source/trace visibility.
- `storage.purchase_manage` remains mandatory for final PO creation.
- Original Email access requires the appropriate Email permission.
- Automated creation uses one configured active least-privilege User; never first admin or rule
  creator by inference.

## Tests

- Policy inheritance can narrow but never widen higher-layer restrictions.
- Every hard gate blocks despite a high extraction score.
- Review, draft, and ordered outcomes with exact deterministic profiles/mappings.
- Internal PO number versus supplier reference, order-date fallback/provenance, and source totals.
- Same source retry, same order/same hash, changed resend, concurrent jobs, and crash after PO write.
- Missing/disabled/unauthorized automation actor fails closed.
- Source card sanitization, Inbox permission, retention fallback, responsive rendering, and audit
  links.
- Manual correction allowed before history and blocked after shipment/cancellation/receipt locks.
- Explicit negative assertions for receipt, stock-unit, movement, and on-hand changes.

## Documentation

- Storage Knowledge for auto-registration modes, hard gates, source card, duplicate/resend behavior,
  and manual correction.
- Email/Signal trace documentation where needed.
- Deployment runbook for actor, queue, shadow-to-active activation, and rollback.
- TODO and human-review updates.

## Done Criteria

- [x] Deterministic safe imports can create exactly one draft or ordered PO under policy.
- [x] Unsafe imports remain exceptions with actionable reason codes.
- [x] Every created PO displays immutable source and automation provenance.
- [x] Manual and retry paths reuse Storage invariants and domain idempotency.
- [x] No email path can receive stock or rewrite immutable history.
- [x] Focused Storage, Email-permission, concurrency, and no-stock coverage is implemented on Dev.
- [x] Knowledge, TODO, deployment instructions, and the stable human-review entry are current.
- [ ] Complete Dev migration/seeding/runtime deployment, real-email shadow rollout, and named human review before enabling active registration.
