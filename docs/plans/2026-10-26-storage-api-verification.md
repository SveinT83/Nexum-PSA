# Storage API Verification And Handoff

Status: **Done On Dev. Reviewed by Svein Tore. Main promotion in progress; production unchanged.**
Date: 2026-10-26. Implementation owner: Codex. Named reviewer/release owner: Svein Tore.
Authoritative source: /var/Projects/tdPSA on Dev. Any Windows copy of this report is a reading snapshot.

## Result

Every one of the 64 current Storage browser route rows has a functional API equivalent.
The final contract has **78 HTTP operations and 132 typed schemas** (HEAD excluded),
covering inventory, supplier lookup, purchasing/shipping/receiving, picking, import
review/actions, protected profiles and inventory/policy administration. This is current
browser parity; no new supplier register, external ordering or tracked-unit picking was invented.

Canonical queries/actions are shared. Pure API reads do not create defaults. Scopes
and fresh current staff rights both apply. Narrow read/form/receiving operators keep
their existing permissions. Local writes have encrypted durable results, keyed replay,
root-locked revision checks and current result-authority checks. External claims commit
before execution and unknown outcomes require reconciliation. PAT and stateful Sanctum
token identities are handled without assuming every token is a database model.

## Verification

| Check | Result |
| --- | --- |
| Initial Storage baseline | 257 tests / 2778 assertions; 27 HTTP operations |
| Final complete affected Dev batch | **615 passed / 7717 assertions; zero failures or skips** |
| Final Storage subset | **287 passed / 4556 assertions** |
| Runtime/Swagger | Exact 78 method/path matches, unique operation IDs, declared scopes, resolved typed schemas |
| HTTP response contracts | Actual successful feature responses checked against generated Swagger schemas |
| Native database | Two independent MySQL-driver connections: mutex lock, replay uniqueness, rollback and encrypted read-back pass |
| Trusted HTTPS | 18 reads, anonymous 401 and exact Swagger file hash pass; final complete guide and own-operation unknown-ID 404 pass |
| Syntax/Pint | 36 explicitly scoped PHP files pass |
| Contributor protection | Four existing #286 files remain byte-identical |

The affected batch includes all Storage feature/unit tests, TicketModuleTest and
TicketWorkflowV3Test, all Economy/Documentation features, IntegrationModuleTest,
SwaggerProductionScanTest and KnowledgeArticleTest. It is an affected-module batch,
not a claim that every unrelated product test was run. Earlier discovered compatibility
and test-fixture failures were corrected and superseded by the final green run.

Logs/JUnit: /tmp/storage-api-release-final-20261026.log and .xml.
Native/runtime/handbook evidence and helper scripts: /tmp/storage-full-api-20261026/.
Machine-readable handoff: docs/plans/2026-10-26-storage-api-verification.json.
The full route/action/test map is docs/plans/2026-10-26-storage-api-coverage-matrix.md.

## Dev Schema And Runtime

Applied only 2026_10_26_030000_create_storage_api_commands.php on Dev, **batch 29**.
It adds storage_api_commands and storage_api_mutex; no business-row backfill.
Native probe tables were removed. Native command rows remain zero; Dev stock/order
counts remain zero as before. API business writes were tested with isolated HTTP
fixtures; the native probe verifies database primitives independently.
Temporary API tokens were removed after every HTTPS probe.

Both STORAGE_API_AI_REPAIR_ENABLED and STORAGE_API_AUTOMATION_ENABLED remain false.
No live AI/provider action, queue retry/backlog purge, worker/scheduler activation or
permission grant was performed. Queued API commands do not imply finished imports.

## Knowledge And Employee Handbook

Eight authoritative sources were published/read back as internal Dev Knowledge articles.
Actual BookStack pages **386, 391, 397, 550, 551, 423 and 449** were updated/read back.
The complete standalone API guide text is embedded in **Storage Inventory (386)**
and also served exactly over trusted Dev HTTPS at the Storage docs endpoint.
Handbook link: https://doc.tronderdata.no/link/386.

All seven pages inherit their existing chapter/book access; Viewer has current
book/chapter/page view permissions. Anonymous Inventory access redirects to login.
No permissions changed. Employee OpenWebUI retrieval is **not tested**.

The connector rejects normal create/search/rename strings. Existing body updates work,
so no placeholder page or infrastructure workaround was used. The older protected
direct credential returned 401 at the trusted hostname and was not changed.
Automatic Nexum BookStack integration is unconfigured on Dev: local pending_push is
not the publication proof. Actual connector read-back is the proof. Existing unrelated
source drift on 551/449 was preserved; only the requested API sections were changed.
The separate follow-up, owner and next action are recorded in TODO.

## Issue And Existing Work

Issue #299 is **closed/completed**, independently read back. Completion comment:
https://github.com/SveinT83/Nexum-PSA/issues/299#issuecomment-6427563741.
Issue #286 numbering/immutability work remains separate and untouched. Dev creation
uses that contributor’s existing uncommitted allocator/StorePurchaseOrder behavior.
The clean Main candidate includes the minimum existing allocator/action/request
dependency; the form change and the remaining #286 requirements stay separate. Do not promote the shared Dev tree
blindly or assume a new immutable numbering contract was delivered here.

No commit, push, Main promotion or production deployment was performed. Common Catalog,
TODO and human-review files contain other shared work: promotion must use scoped hunks
and a clean, tested candidate. Existing staged/dirty contributor work was preserved.

## Future Authorized Deployment

After explicit promotion/deployment authorization and dependency review:

1. Preserve APP_KEY, command receipts and the normal database backup/rollback evidence.
2. Apply only the exact additive Storage API migration on the approved target.
3. Clear/rebuild the installation’s normal route/config caches and run php artisan l5-swagger:generate.
4. Verify file readability, HTTPS, current user/scopes, exact Swagger and domain read-back.
5. Keep API execution switches off until separately reviewed/authorized.
No frontend build or new queue/scheduler activation is required. Do not run unrelated
pending migrations from the shared Dev tree as part of this change.

Domain-wide API serialization favors integrity; production throughput is unmeasured.
Live provider execution and production behavior have not been claimed.

## Human Review

The following is a snapshot of the authoritative checklist in docs/human-review.md.
AGENTS.md requires a named human reviewer; automated tests never mark it Reviewed.
**Svein Tore: please perform these checks before production release/activation.**

### HR-2026-10-26-STORAGE-API - Full Storage Browser/API Coverage

Status: Reviewed
Reviewer: Svein Tore
Reviewed: 2026-10-26
Approval: Godkjenner. Merg til main.
Added: 2026-10-26
Environment: Dev
Owner: Codex. Named reviewer: Svein Tore.
Related: docs/rfc/2026-10-26-storage-full-api-parity.md; Issue #299.
Gate: human scenario acceptance blocks production release/provider activation.
Main promotion requires explicit separate authorization. Automated tests do not
complete this checklist.

Scope: complete supported inventory, supplier lookup, purchasing, receiving,
picking, import review and administrator policy/profile operations through APIs.
Preserve #286 numbering contributor work and its separate unresolved criteria.

- [ ] Search an active supplier, match/create an item, register an externally
      placed order and read back supplier/order/line identities using API only.
- [ ] Compare inventory incoming/on-hand/reserved/available, supplier details,
      movement history, boxes and all relevant browser filters with API results.
- [ ] Pick reserved ordinary stock; verify one movement, reservation/cost state
      and correct Economy/Ticket effects; tracked-unit refusal remains honest.
- [ ] Partial receiving, shipment/tracking, cancellations, close and eligible
      reversal preserve immutable ledger and overage permissions.
- [ ] Review import evidence, map/create item, correct/retry/finalize/reject;
      preserve source, deduplication and actual queued versus finished state.
- [ ] Administrator profile fixture/version validation, activation, rollback,
      pause/retire/export/import and policy/default-warehouse changes match browser.
- [ ] Current user/token rights, nested ownership, stale revisions, keyed replay
      and provider-disabled/unknown outcomes behave as documented.
- [ ] Verify trusted Swagger and actual published employee handbook/access.
- [ ] Separately authorize any controlled provider scenario; default-off API
      gates and existing worker/backlog settings must not be changed implicitly.

Implementation verification (2026-10-26): Done On Dev. Final affected tests 615 / 7717
(no failures/skips), including Storage 287 / 4556. 78 HTTP operations and 132 schemas
match runtime/Swagger; actual successful response bodies are checked. 36 syntax/Pint
checks, native MySQL lock/unique/rollback/encryption and trusted HTTPS read-back pass.
Knowledge and actual handbook content/access are verified; the full guide is in
BookStack Storage Inventory 386. Employee OpenWebUI retrieval remains untested.
Source: docs/plans/2026-10-26-storage-api-verification.md and .json.

Affected browser pages: /tech/storage, /tech/storage/items/{item}/edit,
/tech/storage/boxes/{box}, /tech/storage/picking, /tech/storage/purchase-orders,
/tech/storage/receiving, /tech/storage/supplier-order-imports and the Storage
inventory, automation-policy and supplier-profile administration pages.

Dev migration 2026_10_26_030000_create_storage_api_commands.php is installed in batch 29.
Future approved deployment: apply only this exact additive migration, clear/rebuild
the installation's normal route/config caches and generate Swagger. No frontend
build, worker/scheduler activation or live provider test is required by this change.
Preserve APP_KEY/command receipts on rollback. API AI repair and automation switches
remain false. #286 numbering is a preserved separate dependency for a clean Main
candidate; unrelated dirty changes must not be included accidentally.

Human acceptance and Main merge are explicitly approved by Svein Tore, 2026-10-26.
Original scenario checkboxes retain their execution detail; no independently witnessed
manual execution or production deployment is inferred.

## Files Changed

The following PHP manifest is scoped to this task; it is not permission to commit
every other pre-existing hunk in these shared files.

- app/Modules/Storage/routes.php
- app/Modules/Storage/api.php
- app/Modules/Storage/Actions/CreateSupplierOrderProfile.php
- app/Modules/Storage/Actions/ExecuteStorageApiCommand.php
- app/Modules/Storage/Actions/StorageApiWorkflow.php
- app/Modules/Storage/Controllers/Admin/PurchaseOrderImportProfileController.php
- app/Modules/Storage/Controllers/Api/V1/StorageController.php
- app/Modules/Storage/Controllers/Api/V1/PurchaseOrderController.php
- app/Modules/Storage/Controllers/Api/V1/StorageWorkflowController.php
- app/Modules/Storage/Models/StorageApiCommand.php
- app/Modules/Storage/Queries/StorageApiQuery.php
- app/Modules/Storage/Queries/StorageIndexQuery.php
- app/Modules/Storage/Resources/Api/V1/StorageItemResource.php
- app/Modules/Storage/Resources/Api/V1/StorageBoxResource.php
- app/Modules/Storage/Resources/Api/V1/PurchaseOrderLineResource.php
- app/Modules/Storage/Resources/Api/V1/StorageApiOpenApi.php
- app/Modules/Storage/Support/StorageApiAccess.php
- app/Modules/Storage/Support/StorageApiErrors.php
- app/Modules/Storage/Support/StorageApiInput.php
- app/Modules/Storage/Support/StorageApiRevision.php
- app/Modules/Storage/Support/SupplierOrderProfileDefaults.php
- app/Modules/Storage/Tests/Feature/StorageFullApiTest.php
- app/Modules/Storage/Tests/Feature/StorageApiContractTest.php
- app/Modules/Storage/Tests/Feature/PurchaseOrderApiTest.php
- app/Modules/Storage/Tests/Feature/StorageModuleTest.php
- app/Modules/Integration/Support/ApiAbilityCatalog.php
- config/storage_api.php
- database/migrations/2026_10_26_030000_create_storage_api_commands.php
- app/Modules/Storage/Queries/PurchaseOrderApiDetailQuery.php
- app/Modules/Storage/Queries/PurchaseReceivingContextQuery.php
- app/Modules/Storage/Controllers/Tech/PurchaseReceiptController.php
- app/Modules/Storage/Queries/PurchaseOrderImportProfileIndexQuery.php
- app/Modules/Storage/Tests/Feature/PurchaseOrderTicketScopeTest.php
- app/Modules/Storage/Queries/PurchaseOrderFormQuery.php
- app/Modules/Storage/Controllers/Tech/PurchaseOrderController.php
- app/Modules/Storage/Tests/Unit/StorageApiTokenIdentityTest.php

Documentation/source files:

- docs/rfc/2026-10-26-storage-full-api-parity.md
- docs/adr/2026-10-26-storage-api-commands-and-current-authority.md
- docs/feature-slices/2026-10-26-storage-full-api-parity.md
- docs/plans/2026-10-26-storage-api-coverage-matrix.md
- docs/TODO.md
- docs/human-review.md
- app/Modules/Storage/Docs/knowledge/storage-supplier-order-automation.md
- app/Modules/Storage/Docs/knowledge/storage-purchase-orders-receiving.md
- app/Modules/Storage/Docs/knowledge/storage-inventory.md
- app/Modules/Storage/Docs/knowledge/storage-picking-list.md
- app/Modules/Storage/Docs/knowledge/storage-vendors-suppliers.md
- app/Modules/Storage/Docs/knowledge/storage-api.md
- app/Modules/Storage/Docs/knowledge/storage-item-fields.md
- app/Modules/Integration/Docs/knowledge/api-management.md

The verification Markdown/JSON themselves are additional intended artifacts.
The regenerated storage/api-docs/api-docs.json is acceptance output from the shared
Dev tree; regenerate it for a clean candidate rather than promoting unrelated routes.

Public-safe website handoff is appended/read back in the configured Content and
Visibility file under the active Windows profile, with **DO NOT PUBLISH**.
No website or social publication was performed.

## Main Promotion Update

Svein Tore approved the human-review entry and Main merge on 2026-10-26.
The preceding Dev metrics remain the original implementation checkpoint.
See plans/2026-10-26-storage-api-main-promotion.md for isolated candidate tests,
numbering dependency scope and exact merge/tree evidence. Production is unchanged.
