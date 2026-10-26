# Assets API Verification

Date: 2026-10-26. Authoritative implementation: Dev /var/Projects/tdPSA.
Approved scope: full existing browser/API parity, Swagger and scoped Main promotion.
Human review: HR-2026-10-26-ASSETS-API Pending. Production was not deployed and
ASSETS_API_PROVIDER_EXECUTION_ENABLED is false. No live provider command was run.

## Delivered Coverage

Matrix A01-A14 and exact contract: 17 HTTP operations / 32 typed Swagger schemas.
Includes every supported Asset web route, manual Livewire form/settings, list filters,
classification read/write, Client/Site/owner/vendor lookups, detail/docs, alert
history/checks/summary, global/client/asset alert sync and scoped provider imports.
One shared manual persistence action and shared mapping/observation implementations
preserve domain ownership. Added actor/token permission checks, strict fields, optional
strong revisions, durable encrypted replay and explicit provider preview/confirmation.
The static related-Tickets empty state and nonexistent browser deletion are not
invented workflows. Integration retains RMM routing and occurrence ownership.

## Automated And Native Evidence

- Original Asset baseline: 16 tests / 96 assertions passed.
- Two new regression tests failed before the changes: context 404 and classification
  update/read-back missing. They pass after implementation.
- Final affected Dev batch: 132 tests / 1432 assertions passed.
- Independent origin/main candidate: 132 tests / 1432 assertions passed.
- Final Dev Asset-only hardening rerun: 34 tests / 443 assertions passed.
  Final candidate Asset-only rerun also passed 34 tests / 443 assertions.
- Main API/administration focused checks: 10 tests / 304 assertions passed (overlap).
- Scoped syntax: 35 PHP files; Pint formatted/checked 28 Asset/config/migration PHP files.
  Existing shared legacy code was not broadly reformatted.
- Runtime/Swagger comparisons are bidirectional for methods, scopes, current rights,
  typed inputs/responses and all referenced schemas. Candidate artifact updates only
  Asset paths/schemas; unrelated raw members are retained. Histogram diff reflects
  the generated additions without unrelated schema behavior changes.
- Exact additive migration 2026_10_26_020000_create_asset_api_commands ran on Dev
  batch 28, without business-row backfill. Native MySQL lock timeout 1205 and replay
  unique constraint 1062 passed in rollback-only probes.
- Ten current trusted HTTPS API reads returned 200; anonymous context 401 and missing
  Asset 404. Served Swagger bytes match the Dev generated artifact and all 17/32
  methods/schemas. Counts and temporary token count were independently restored.
- Real-token tests prove receipt isolation, revoked-token refusal and exact replay.
  Provider fixtures cover both imports, client scope, transport failure and observed
  completion. Interrupted claims do not automatically repeat external work.

The earlier two provider/token test-fixture failures were corrected (explicit container
binding and fresh request guard); the complete affected batch above was rerun.
The shared test suite has five pre-existing PHPUnit PHPDoc deprecation warnings.
No known failed check is deferred. Tests use isolated SQLite; native lock/unique and
HTTPS checks use the configured Dev MySQL runtime. No full repository test suite was run.

## Handbook Read-Back

Source and native Knowledge articles assets/asset-api (32), assets/asset-settings (33)
and assets/rmm-alert-lifecycle (34) match exactly, are internal and published.
The API Management section was updated without publishing unrelated pending changes.
Actual BookStack pages 452/2, 440/6, 453/2, 501/2, 502/2, 542/2, 641/1 and 449/138
were published and read back exactly. Historical Asset API duplicates have the same
current content, preserving their IDs/URLs. Existing inherited employee Editor view
rights were verified; no permission was changed. Automatic Nexum BookStack sync remains
unconfigured/pending_push; that state alone is not publication evidence. The actual
BookStack read-back is the publication evidence. Employee OpenWebUI retrieval is untested.
Search/create plugin calls had schema validation failures; list/update/read worked.
Only lifecycle creation used the established protected personal BookStack binding.

## Release And Recovery

Main candidate is isolated from 1196 baseline dirty Dev paths; the Dev HEAD/index
were preserved. Current Main candidate validation is independent from Dev validation.
A scoped Main promotion was explicitly requested; no production deployment was requested.
Before a separately authorized target rollout: apply the exact migration, regenerate
Swagger and refresh cache/opcache with umask 0002. No frontend build, new worker or
scheduler registration is required. Provider API execution remains default-off.

Keep APP_KEY and receipts on rollback. Long provider reads/imports serialize Asset
writes; production throughput is unmeasured. Native provider acceptance and the human
checklist remain pending before production release/provider activation.

## Files Changed

- app/Jobs/Integrations/Alerts/SyncNAbleAlertsJob.php
- app/Jobs/Integrations/Alerts/SyncTacticalAlertsJob.php
- app/Jobs/Integrations/TacticalRmm/SyncTacticalAssetsJob.php
- app/Modules/Asset/Actions/ExecuteAssetCommand.php
- app/Modules/Asset/Actions/ImportNableAssets.php
- app/Modules/Asset/Actions/ImportTacticalAssets.php
- app/Modules/Asset/Actions/SaveManualAsset.php
- app/Modules/Asset/Actions/StoreAsset.php
- app/Modules/Asset/Actions/SyncAssetAlerts.php
- app/Modules/Asset/Actions/UpdateAsset.php
- app/Modules/Asset/Actions/UpdateAssetSettings.php
- app/Modules/Asset/Controllers/Admin/AssetSettingsController.php
- app/Modules/Asset/Controllers/Api/V1/AssetController.php
- app/Modules/Asset/Controllers/Api/V1/AssetProviderController.php
- app/Modules/Asset/Controllers/Api/V1/AssetWorkspaceController.php
- app/Modules/Asset/Docs/knowledge/asset-api.md
- app/Modules/Asset/Docs/knowledge/asset-settings.md
- app/Modules/Asset/Docs/knowledge/rmm-alert-lifecycle.md
- app/Modules/Asset/Livewire/Tech/Alerts/AlertSyncProcessor.php
- app/Modules/Asset/Livewire/Tech/AssetAlerts.php
- app/Modules/Asset/Livewire/Tech/AssetForm.php
- app/Modules/Asset/Models/AssetApiCommand.php
- app/Modules/Asset/Queries/AssetQuery.php
- app/Modules/Asset/README.md
- app/Modules/Asset/Resources/Api/V1/AssetApiOpenApi.php
- app/Modules/Asset/Resources/Api/V1/AssetResource.php
- app/Modules/Asset/Support/AssetApiAccess.php
- app/Modules/Asset/Support/AssetApiErrors.php
- app/Modules/Asset/Support/AssetInput.php
- app/Modules/Asset/Tests/Feature/AssetApiContractTest.php
- app/Modules/Asset/Tests/Feature/AssetApiParityTest.php
- app/Modules/Asset/Tests/Feature/AssetModuleTest.php
- app/Modules/Asset/routes.php
- app/Modules/Integration/Docs/knowledge/api-management.md
- app/Modules/Integration/Livewire/Tech/Admin/System/Integrations/NAbleRmmSync.php
- app/Modules/Integration/Livewire/Tech/Admin/System/Integrations/TacticalRmmSync.php
- app/Modules/Integration/Support/ApiAbilityCatalog.php
- app/Services/Integrations/TacticalRmm/TacticalRmmClient.php
- config/assets-api.php
- database/migrations/2026_10_26_020000_create_asset_api_commands.php
- docs/TODO.md
- docs/adr/2026-10-26-assets-api-commands-and-provider-scope.md
- docs/human-review.md
- docs/plans/2026-10-26-assets-api-contract.json
- docs/plans/2026-10-26-assets-api-gap-matrix.md
- docs/rfc/2026-10-26-assets-full-api-parity.md
