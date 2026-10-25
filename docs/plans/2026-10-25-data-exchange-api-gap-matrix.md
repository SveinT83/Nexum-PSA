# Data Exchange API Coverage Matrix

Date: 2026-10-25
Status: Done On Dev; DX01-DX12 implemented; human review Reviewed (Svein Tore, 2026-10-25)
Owner: Codex
Parent: [full API RFC](../rfc/2026-10-25-data-exchange-full-api-parity.md)

The initial assessment below records the before state on /var/Projects/tdPSA, branch Dev.
Existing API: six operations in app/Modules/DataExchange/api.php. This matrix specifies complete
operational parity and safe exact read-back, not a read-only slice.

| ID | Current workflow / evidence | Current API | Required complete contract |
| --- | --- | --- | --- |
| DX01 | Admin/DataExchangeController index; SourceRegistry visibleFor; source definitions | No context/metadata/dashboard | Pure permission-filtered context, source/field/filter/capability metadata and scoped counts |
| DX02 | ProfileBuilder mount; profile list; saved sources/fields/filters/mappings | Summary list only | Bounded list/detail and complete editable typed configuration/revision |
| DX03 | ProfileBuilder save, sequential writes | Missing | Shared atomic create/update, strict registry/domain validation, strong revision, current actor/token rights |
| DX04 | RunController store; RunDataExchangeExport; EconomyExportWorkflow | Trigger exists | Preview plus guarded command/replay, exact profile/options/source scope, format/checksum results |
| DX05 | RunController show/download; stored runs/files | Run detail/download exist | Scoped paginated history/file metadata/detail, audit, unchanged URLs and exact binary/checksum/error contract |
| DX06 | ImportController dryRun/show; RunDataExchangeImportDryRun | POST dry-run only | Typed upload, readable bounded preview/list/detail, row errors, source/profile/input revision binding |
| DX07 | ImportController commit; CommitDataExchangeImportPreview | POST commit exists | Locked/revalidated commit, durable replay, exact run/result identity and no duplicated target writes |
| DX08 | ScheduleController store/update; NextScheduleRun; due planner/job | Missing | List/detail/create/update/pause/reactivate, target compatibility, execution authority and unique occurrence claim |
| DX09 | DeliveryTargetController store/update | Missing | List/detail/create/update/pause, validated approved references, no arbitrary credential/disk/path exposure |
| DX10 | DeliverDataExchangeFile / delivery job and attempt records | Missing | Exact delivery preview/dispatch/status/retry/reconcile, frozen target/file, write check and destination read-back |
| DX11 | AuditEvent model; run-page audit projection | Missing general read-back | Bounded authorized audit/attempt evidence with sanitized content and historical parent/domain rights |
| DX12 | Economy canonical/custom profile guard; Client approved imports; Integration ability catalog | Partial | Preserve owner APIs/provider authority, test all matrices, complete typed Swagger, handbook/read-back and human review |

## Existing URLs To Preserve

- GET /api/v1/data-exchange/profiles
- POST /api/v1/data-exchange/profiles/{profile}/runs
- GET /api/v1/data-exchange/runs/{run}
- GET /api/v1/data-exchange/files/{file}/download
- POST /api/v1/data-exchange/imports/dry-run
- POST /api/v1/data-exchange/imports/{preview}/commit

Current token abilities: data_exchange.read/run/download/import/approve_import.
New abilities require actual matching routes, tests and catalog entries. Existing tokens gain no rights.

## Concrete Runtime Gaps To Cover In Tests

- Profile writes are not atomic across profile and configuration children.
- Non-Economy API paths have no uniform fresh-actor/source authorization guard.
- Import status is checked before the transaction without locking/reloading the preview.
- Source export fallback reads model rows without actor context; rights must be enforced before
  execution and source-specific record restrictions preserved.
- Export maps persisted fields without rechecking current registry field safety.
- Schedule planner does not claim unique due occurrences; execution currently has no actor.
- Automatic import pickup is explicitly skipped by the job, although forms accept import direction.
  Advertise unavailable and reject activation; this draft does not claim to implement pickup.
- Delivery ignores filesystem put false and does not verify destination content.
- Dashboard index initializes templates; new discovery GETs must not inherit that business write.
- Existing Economy controller edits and Tripletex services are shared dependent work to preserve.

These are static observations; exploitability or exact concurrent failure has not been reproduced.
Baseline tests are separate evidence, not proof of full API coverage.

## Completion Evidence Required

Map every DX row to actual methods/schemas, current rights, shared domain action and meaningful
passing Dev tests. Add native schema/lock/uniqueness checks and trusted HTTP/OpenAPI read-back,
verified source plus BookStack content/access, and the named human-review checklist. Report
Dev/Main/production and runtime activation separately. Never treat a queued command as completion.

## Baseline Test Evidence

On authoritative Dev, 2026-10-25:
`umask 0002 && HOME=/tmp php artisan test app/Modules/DataExchange/Tests/Feature`
passed 11 tests / 52 assertions in 34.85 seconds. This verifies the existing tested surfaces,
not the missing API contract. No implementation or migration was performed.

## Completed coverage and verification

All DX01-DX12 rows are implemented through module-owned routes and the typed DataExchangeApiController.
DX01-DX02: DataExchangeQueries, SourceRegistry and explicit projections.
DX03: shared SaveDataExchangeProfile, strong revisions and browser/API validation.
DX04-DX05: DataExchangeExportWorkflow, shared RunDataExchangeExport, private checksum-verified files.
DX06-DX07: bounded DryRun/Commit actions, source-owned revision, locked durable command replay.
DX08-DX09: shared SaveDataExchangeOperations and unique schedule occurrence claims.
DX10: VerifiedDataExchangeDelivery with frozen intent, guarded retry and exact reconciliation.
DX11-DX12: current actor/token/source guards, scoped audit/command evidence, 35 typed operations and
31 schemas, regression tests, Knowledge/BookStack and native/HTTPS verification.

Final affected set: 82 tests / 1011 assertions; broader affected: 310 / 3233; API admin: 5 / 316 pass.
Sets overlap. See [the verification report](2026-10-25-data-exchange-api-verification.md).
HR-2026-10-25-DATA-EXCHANGE-API is Reviewed; Main promotion is authorized. API delivery stays disabled;
shared queue assessment and external scheduler proof precede separately authorized activation.
