# Issue 286 Canonical Numbering Verification And Main Promotion

Date: 2026-10-28. Owner: Codex / Svein Tore. Main promotion explicitly authorized.
Candidate base: 84b82cad2768b4f688dd750d1506c092ef7e0e3c.
Status: Done On Dev / Scoped Main Candidate Verified / Merge Pending.

## Scope And Contributor Preservation

One canonical immutable generated numeric identity across manual/API/import paths. Existing
legacy/deleted identities and supplier identity/reference remain unchanged. Reuse TD-Jonathan's
allocator class; preserve Dev HEAD 36918edbcaae1ce7b1208ba34f84a3a1719f0e98, index digest
d032b30287c94acda453e9a1476909e74a50aeee5f127d1152b83f5d77dd197b, both original staged
Storage files and the staged root helper. No unrelated branch/index changes are promoted.
All baseline implementation/test files matched current Main, except the shared form change inside
the same number field being replaced. No unrelated shared form/controller change is included.

## Automated Verification

Six regressions failed before repair (the API test was corrected to the real PUT route and its
failure reconfirmed). Final numbering suite includes nine tests: override, action/model rename,
legacy/deleted collision, read-only forms, API, transaction rollback, allocator idempotency /
retained high-water and missing-counter fail-closed behavior.

Dev core matrix: 130 distinct tests covered in completed runs. The first post-fixture matrix
passed 128 with two stale AI cap-format assertions; the full affected AI class then passed
34 tests / 404 assertions. The requests now verify #277's existing exact 12-decimal representation;
the business amount and remaining-budget checks are unchanged. Original pre-adaptation Storage
run had 49 failures from old caller-number fixtures and those two cap assertions; these are not
being silently deferred.

Latest-Main candidate: all 296 distinct Storage feature/unit tests covered in completed runs.
Full run passed 294 and exposed only the same two stale string assertions. After updating that
test file, the entire affected 34-test AI class passed with 404 assertions. Remaining 262 tests
are unchanged from their passing full run. No unresolved failures or skips. This is a matrix of
completed runs, not a claim that the original full command exited green. No full application
suite or manual browser/device acceptance is claimed. Fresh Swagger route-to-spec test passed.

## Native Dev MySQL

Encrypted preflight read-back and existing unique po_number index passed. The exact additive
2026_10_28_040000 migration alone was applied. Two independent barrier-synchronized processes
committed ten distinct numeric orders through StorePurchaseOrder. Original purchase-order
identities and automation policy matched protected preflight after cleanup. Synthetic procurement
and master rows were removed; used counter state remains intentionally retained without rewind.

Mail authority discovered the synthetic test actor during the proof. Its immutable access record
cannot be deleted. User ID 9 remains disabled, with no roles/direct permissions/API tokens; that
Mail audit evidence is retained, not bypassed. This residue and its ownership are documented in
the deployment/recovery guide. No supplier/provider network request or inventory receipt occurred.

## Deployment And Review

Use deployment/canonical-purchase-order-numbering.md for exact migration, controlled writer/worker
adoption, Swagger/cache refresh, protected backup and forward recovery. Production is unchanged.
HR-2026-10-28-PO-NUMBERING remains Pending before production/manual create/edit/import and layout
acceptance. Existing HR-2026-10-26-STORAGE-API remains Reviewed; its production/provider gates
are not reset or broadened. Tests never mark a human review complete.

Four authoritative Storage guides were updated first. Actual BookStack/Knowledge publication and
read-back, Main merge/tree read-back, Issue completion and final status are recorded below when
verified; no local/queued publication is called a completed handbook update.
