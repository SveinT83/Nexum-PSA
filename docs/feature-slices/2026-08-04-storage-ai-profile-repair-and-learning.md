# Feature Slice: AI Profile Repair And Learning

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Let AI diagnose an import discrepancy, correct the current safe proposal, create or update a
declarative profile version, regression-test it, and activate it automatically under settings so
recurring supplier changes do not require repeated human work.

## User-Visible Behavior

Authorized users see **Repair with AI** on eligible import details and Purchase Order source cards.
The action explains discrepancies, shows evidence, corrects allowed import/PO facts through normal
actions, and creates a separate profile-version candidate when the active profile should change.

Admins can choose `off`, `propose`, or `auto_activate_after_validation`. They can compare versions,
fixtures, and shadow results, activate manually, pause a degraded profile, or roll back instantly.

## Scope

- Add an explicit AI repair operation tied to one import/source/profile/policy version.
- Compare safe source snapshot, canonical import, Item resolution, validation trace, current PO, and
  active profile.
- Produce separate corrected-import and profile-candidate results with evidence and reasons.
- Convert AI profile output only into the constrained DSL; reject unknown/executable behavior.
- Create immutable child versions; never mutate the active version in place.
- Add golden-fixture replay, current-source validation, prior-critical-output comparison, resource
  limits, and configurable shadow sample count.
- Auto-activate only after every required regression/shadow gate passes and effective policy permits.
- Record source `ai_repair`, parent version, execution UUID, checksums, test metrics, activation
  actor/service identity, and reason.
- Keep previous validated versions available for rollback and imports pinned to their original
  version.
- Learn manual corrections as protected fixtures and/or deterministic supplier-SKU mappings.
- Detect template drift and create repair candidates after repeated matching failures.
- Degrade/pause only the affected supplier profile when a circuit-breaker threshold is reached.
- Rerun corrected imports through ordinary validators, Item resolver, action policy, permissions,
  lifecycle locks, and idempotency.
- Make repair preview-only for PO facts locked by shipment, cancellation, or receipt history.

## Out Of Scope

- Executable code generation or arbitrary AI tools.
- Direct profile, Item, Vendor, PO, receipt, or stock writes by the model.
- Generic Inbox AI rule/profile bootstrap.
- Automatic reconciliation of received Purchase Orders.

## Data Touched

- Storage profile versions, fixtures, shadow/test metrics, activation/rollback history, health, and
  repair attempts.
- Storage imports and allowed PO corrections through shared actions only.
- Integration execution/usage metadata through the shared boundary.
- No raw prompt/model response or receipt/stock rewrite.

## Permissions

- `storage.purchase_import_execute` for invoking repair.
- `storage.purchase_import_profile_manage` for manual activation/rollback and profile settings.
- `storage.purchase_import_resolve` plus existing PO/Item permissions for applied corrections.
- AI repair cannot widen Integration governance or Storage hard gates.

## Tests

- Repair finds a real discrepancy and rejects invented evidence.
- Candidate version is immutable, constrained, linked to parent, and leaves active version unchanged
  until activation.
- Fixture/current-source replay, critical-output regression, shadow pass/fail, concurrent activation,
  automatic activation policy, manual activation, and rollback.
- Failed candidate remains auditable and cannot create a PO.
- Manual correction becomes a fixture/mapping and prevents the same exception next time.
- Template drift degrades/trips only one profile; other suppliers continue.
- AI/provider failure leaves source/import/profile unchanged and retryable.
- Repair before history uses shared actions; shipment/receipt-locked facts remain preview-only.
- No raw AI content in mandatory logs and no receipt/stock side effect.

## Documentation

- Storage Knowledge for Repair with AI, version comparison, fixture/shadow gates, activation,
  rollback, health, and locked-history behavior.
- Integration/operations documentation for repair workload, cost, outage, and circuit breaker.
- TODO and human-review updates.

## Done Criteria

- [x] Repair with AI is implemented only on eligible imports/POs with clear evidence.
- [x] AI can create and improve profile versions without executable code or direct writes.
- [x] Passing candidates can auto-activate under policy only after configured regression/shadow validation.
- [x] Failed changes are isolated, explainable, and reversible.
- [x] Manual corrections teach deterministic behavior through protected fixtures or exact mappings.
- [x] Immutable PO/receipt history cannot be rewritten.
- [x] Profile, AI, lifecycle, concurrency, UI, and no-stock coverage is implemented on Dev.
- [x] Knowledge, operations, TODO, and the stable human-review entry are current.
- [ ] Exercise repair and profile learning against protected real emails during `HR-2026-08-04-003` before automatic activation.
