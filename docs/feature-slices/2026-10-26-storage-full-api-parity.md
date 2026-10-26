# Feature Slices: Full Storage Browser/API Parity

Status: Done On Dev - all six sequential slices verified; named human review approved by Svein Tore.
Date: 2026-10-26
Parent: docs/rfc/2026-10-26-storage-full-api-parity.md
Owner: Codex. Product/release reviewer: Svein Tore.
Matrix: docs/plans/2026-10-26-storage-api-coverage-matrix.md
Human review: HR-2026-10-26-STORAGE-API (Reviewed by Svein Tore, 2026-10-26).

## Common Contract
Each sequential slice preserves shared domain ownership and #286 contributor work.
Each has real route/HTTP and authorization evidence, typed Swagger, current docs
and exact read-back. Commit/push/Main/production are independent states.
The change has no frontend build or new ordinary-user UI.
Runtime flags are never enabled as part of implementing API coverage.

## Slice 1: Authorization, Discovery And Reference Reads
Goal/behavior: active internal user rights and explicit scopes for every operation;
safe supplier/manufacturer, warehouse, room/carrier and item reference lookup.
Scope: API ownership in routes.php, compatibility loader, safe pure context and docs.
Out of scope: supplier CRUD, permission grants, mailbox access.
Data: read canonical Vendor/Storage masters; no bootstrap writes on GET.
Permissions: existing browser rights plus explicit read abilities; narrow form owners
retain reference access without gaining inventory/settings access.
Tests: all route guards, bearer/user status/revocation, missing scope, ambiguity,
inactivity, pagination, unsupported fields and pure-read counts.
Documentation: Storage API/vendor guide and API Management.
Done criteria: supported lookup-to-item-to-order HTTP path and exact safe fields pass.

## Slice 2: Inventory, Detail And Picking
Goal/behavior: complete item fields, supplier lines, movement history, box events,
incoming quantities, all operational filters/sorts and guarded picking.
Scope: shared inventory query, explicit edit contexts, box/detail sort, Ticket picking.
Out of scope: a new tracked-unit picking engine or reservation ownership.
Data: Item/ItemVendor/Box/Movement/Reservation/TicketCostEntry and existing Economy events.
Permissions: current item/manage/stock-adjust/pick rights, separate read-only picking
scope; Ticket detail requires its own current permission and token scope.
Tests: inventory regression, incoming versus stock, complete read-back, exactly-once
pick/movement/reservation effects and tracked-unit refusal.
Documentation: inventory/item/picking sources and API guide.
Done criteria: relevant browser and HTTP stock/picking workflows pass with no bypass.

## Slice 3: Durable Commands And Revision Fencing
Goal/behavior: local API writes and encrypted durable results commit together;
same-key replay never duplicates effects and stale supplied revisions fail.
Scope: additive command/mutex migration, domain root locks, key fingerprint conflicts,
extra response-read authority on replay/lookup, unknown external/queue handoff state.
Out of scope: existing receipt token replacement, granting rights or replaying old jobs.
Data: Storage API command receipts/mutex only; no business-row backfill.
Permissions: current originating user/token rights and all rights used for the result.
Tests: keyed stock/pick replay, changed-key payload conflict, stale revision, encrypted
retention, token/actor isolation, revoked extra read rights and unknown provider replay;
native MySQL locking/uniqueness in isolated temporary probe tables.
Documentation: API guide, accepted ADR and operator deploy/rollback instructions.
Done criteria: SQLite HTTP and native primitives pass; claims/APP_KEY preservation documented.

## Slice 4: Supplier Orders, Receiving And Import Operations
Goal/behavior: one operational supplier-order queue; full purchase/shipment/receipt
workflow plus supported import review/resolution/execution.
Scope: narrow receiving context/control-slip data, current supplier identity/dedup,
mapping/distinct item creation, correction/retry/finalize/reject and AI repair.
Out of scope: placing external orders, changing provider email state or receiving on import.
Data: existing PO/Shipment/Receipt/Import/Repair ledger and unchanged domain actions.
Permissions: purchase/read/manage/receive/reverse/overage and import read/resolve/execute;
AI repair also needs profile management; external effects are default-off/confirmed.
Tests: full purchase/receipt suite, receiving-only operator, nested ownership, safe
source/audit projection, import-to-one-order, zero stock on finalize and queued status.
Documentation: purchasing/automation sources and API guide.
Done criteria: exact lifecycle, immutable receipt and import/queue outcomes pass.

## Slice 5: Administration, Profiles And Policy
Goal/behavior: complete inventory defaults, normalized business policy, profile list,
metadata audit, immutable version/fixture validation and lifecycle/export/import.
Scope: shared browser profile creation/defaults and purchase form/receiving queries;
pure settings reads; concrete policy/version preview and separate activation switch.
Out of scope: new AI setup concepts, automatic permission/provider/worker activation.
Data: existing CommonSetting/Policy/Revision/Profile/Version/Fixture/MetadataAudit tables.
Permissions: current browser administration permissions and separate read/write scopes.
Tests: defaults/revisions, metadata audit, raw JSON whitespace preservation, protected
fixture validation/activation/rollback, nested-version refusal, inactive portable import,
policy normalization, confirmation/default-off guards and non-mutating previews.
Documentation: automation/API guide, API Management, ADR and human checklist.
Done criteria: HTTP and existing browser profile/policy tests agree and pass.

## Slice 6: Contract, Regression, Runtime And Handbook Acceptance
Goal/behavior: evidence-backed complete parity with explicit environment/release status.
Scope: every one of 64 browser routes mapped to 78 API HTTP operations; exact generated
Swagger and actual successful HTTP responses checked, affected regressions,
native migration/probe and trusted HTTPS reads, employee handbook publication/read-back.
Out of scope: Main commit/push/merge, production deployment and manual review completion.
Data: documentation and additive Dev schema only; test fixtures are isolated.
Permissions: no grants; handbook retains existing employee access inheritance.
Tests: final complete Storage and affected Ticket/Economy/Documentation/Integration/
Knowledge batch, syntax/Pint, protected-file hashes, native and HTTPS evidence.
Documentation: verification report/JSON, TODO/RFC/ADR/slices/human review, API/Knowledge
and exact BookStack pages; public-safe website handoff with do-not-publish status.
Done criteria: all required automated/runtime/doc evidence is recorded; any true blocker
has an exact owner/next action. Human checklist is Reviewed following Svein Tore's explicit confirmation.


## Final Acceptance Evidence
All six slices meet their automated/runtime/documentation criteria on authoritative
Dev: 615 tests / 7717 assertions, Storage 287 / 4556, 78 HTTP operations / 132 schemas,
36 syntax/Pint checks, native MySQL probes and trusted HTTPS/Swagger read-back.
The additive Dev migration is batch 29. Eight internal Knowledge sources and seven
actual BookStack pages are verified; the complete guide is in Inventory 386.
Human review is approved. The unconfigured automatic sync and separate existing
source drift remain recorded. Main promotion is authorized and under verification;
production/provider activation remain separate gates. See docs/plans/2026-10-26-storage-api-verification.md and .json.
