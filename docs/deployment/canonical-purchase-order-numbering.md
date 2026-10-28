# Canonical Purchase Order Numbering Deployment And Recovery

Scope: Issue #286 / RFC 2026-10-28-canonical-purchase-order-numbering.
Dev and tested Main promotion are separate from production deployment. Complete
HR-2026-10-28-PO-NUMBERING with a named human before production rollout.

## Deploy In A Controlled Window

1. Preserve an encrypted backup of existing purchase-order IDs/numbers, supplier identity keys,
   deleted-order identities, the counter if already present, and automation-policy identity.
   Verify backup read-back without printing customer/order bodies or secrets.
2. Pause purchase-order creation/finalization paths during deployment and arrange graceful adoption
   by every web/queue worker. Do not mix old AUTO/manual-number writers with the new contract.
3. Apply only the exact reviewed additive migration:
   `php artisan migrate --path=database/migrations/2026_10_28_040000_create_storage_purchase_order_number_sequences.php --force`.
   Do not run unrelated pending migrations as part of this change.
4. Deploy the matching code, clear deployment caches through the normal approved process,
   regenerate Swagger with `php artisan l5-swagger:generate`, and gracefully reload the relevant
   workers. This guide does not authorize a production action.
5. Read back the existing unique `po_number` index, singleton row and original identity/policy
   digest. The first successful creation initializes the counter from existing IDs and bounded
   numeric legacy identities, including deleted orders. It never renumbers them.
6. Verify manual/API/import creation, unchanged edit echo, failed validation, concurrent creation
   and receiving/history on approved fixtures. Observe the resulting business row, not only HTTP
   acceptance. No supplier message, stock receipt or import replay is needed for numbering proof.

## Recovery

Missing schema, a missing singleton, exhaustion or an invalid counter fails creation with 503.
Do not insert a new zero counter, rename an existing order, use a caller number, drop the unique
index, or rewind numbering to hide the error. Compare the protected backup and committed order
identities, reconcile the highest reserved/committed number, and use a reviewed operator repair.

An unsuccessful transaction rolls back its new order, lines and counter changes together.
Database ID gaps and reserved-number gaps are valid; never promise a gap-free series.
An exact allocator replay for a finalized order returns its existing identity.

On a code rollback, retain the counter table, used high-water value and backup. Migration down
refuses to drop a used counter. An older writer must not resume until its identity behavior is
explicitly reviewed; use a forward repair when practical. Preserve all legacy/custom identities,
soft-deleted orders, supplier references, import links, receipts and audit history.

## Dev Verification Footprint

The exact migration was applied on native Dev MySQL after an encrypted preflight; original
purchase-order identities and automation policy were unchanged. Two independent processes
committed ten distinct numeric orders, then removed only those synthetic procurement rows and
master records. The used counter was deliberately retained.

The Mail authority scheduler registered the synthetic actor during this test. Its immutable
access-state record forbids deletion. User ID 9 (`numbering-286-native-20261028@example.invalid`)
therefore remains disabled, with no roles, direct permissions or API tokens; immutable Mail
evidence was preserved. Do not drop triggers or delete retained audit evidence for cosmetic cleanup.
This synthetic address is not a real recipient. No supplier/provider request was permitted.
