# Asset Module

The Asset module owns asset management for tdPSA. It is the singular domain module for user-facing "Assets" functionality.

## Scope

The module covers:

- Global asset listing.
- Client-scoped asset listing.
- Manual asset creation and editing.
- Internal company-owned assets without a Client.
- Asset detail pages.
- Asset documentation view.
- Asset API list and detail endpoints.
- Asset alert display and sync triggers.
- Asset list cards rendered inside Client and Site screens.

## Architecture

Routes, controllers, views, Livewire components, actions, queries, and tests live under:

`app/Modules/Asset`

The module follows the tdPSA domain architecture:

- Routes are module-local.
- Controllers are module-local.
- Views are module-local.
- Livewire views are module-local.
- Query and persistence logic is separated from controllers.

## Route Compatibility

The implementation moved into `Asset`, but public contracts remain stable:

- `tech.assets.*`
- `tech.clients.assets.index`
- `api.v1.assets.*`

The old client asset URL shape `/tech/clients/assets/{client?}` is still accepted as `tech.clients.assets.legacy`. New named links should use `tech.clients.assets.index`, which resolves to `/tech/clients/{client}/assets`.

## Livewire Compatibility

Blade templates keep the established aliases:

- `tech.assets.asset-form`
- `tech.work.assets.asset-alerts`
- `tech.work.assets.client-alerts-summary`
- `tech.work.assets.alerts.alert-sync-processor`

These aliases are registered in `AppServiceProvider` and point to classes inside the Asset module.

## Model Namespace

The Eloquent models are not moved yet:

- `App\Models\Tech\Work\Assets\Asset`
- `App\Models\Tech\Work\Assets\AssetAlert`

This protects existing RMM polymorphic links. The `client_rmm_links.linkable_type` column can contain the old Asset class string, so moving the model namespace needs a separate morph-map or data migration plan.

## Primary Files

- `routes.php`: Tech web routes and Asset API routes. The application API entry file loads this same route file with an API context.
- `Controllers/Tech/AssetController.php`: Tech UI entry points.
- `Controllers/Api/V1/AssetController.php`: API entry points.
- `Resources/Api/V1/AssetResource.php`: API response shape.
- `Queries/AssetQuery.php`: UI filter and pagination query.
- `Actions/StoreAsset.php`: Plain HTTP create fallback.
- `Actions/UpdateAsset.php`: Plain HTTP update fallback.
- `Support/AssetWorkContextPayload.php`: Client/internal Work Context validation and payload
  normalization.
- `Livewire/Tech/AssetForm.php`: Create/edit form.
- `Livewire/Tech/AssetAlerts.php`: Asset detail alert widget.
- `Livewire/Tech/ClientAlertsSummary.php`: Client/global alert summary.
- `Livewire/Tech/Alerts/AlertSyncProcessor.php`: Hidden layout-level alert sync bridge.
- `Views/Tech`: Tech pages and Markdown docs.
- `Views/components/tech/assets/list-card.blade.php`: Anonymous Blade component used by Client views.

## Tests

Feature tests live in:

`app/Modules/Asset/Tests/Feature`

Run module tests with:

`php artisan test --testsuite=Modules`

## Future Work

Before moving the Asset models into the module, add one of these safeguards:

- A Laravel morph map that preserves the stored polymorphic type.
- A migration that updates all existing `client_rmm_links.linkable_type` values.
- Compatibility code for both old and new class names during a transition period.
