# Asset Module Documentation

## Purpose

The Asset module tracks technical equipment connected to clients and sites. It supports manually created assets, RMM-imported assets, ownership assignment, network identifiers, alert summaries, and API access for integrations.

The module is singular by architecture rule: the domain is `Asset`, even though user-facing labels and route names still use "Assets".

## User Workflow

### List Assets

Technicians can open **Work > Assets** to view all registered assets. The list supports filtering by client, type, status, and active alerts.

Client pages and site pages also display asset cards. Those cards link back to the canonical client-scoped asset list:

`/tech/clients/{client}/assets`

### Create Assets

The create screen uses the `tech.assets.asset-form` Livewire alias. The PHP class is module-local:

`App\Modules\Asset\Livewire\Tech\AssetForm`

When a technician selects a client, the form reloads the available sites and users for that client. This prevents assigning an asset to a user or location belonging to another client.

### View and Edit Assets

The detail screen shows the asset summary, ownership, network metadata, vendor data, RMM-related information, and alert widgets. Editing uses the same Livewire form as creation.

### Alerts

The module includes Livewire components for asset-level and client-level alert summaries. Alert sync is dispatched to the existing N-able and Tactical RMM jobs.

## Routes

Tech routes live in:

`app/Modules/Asset/routes.php`

Important route names:

- `tech.assets.index`
- `tech.assets.create`
- `tech.assets.store`
- `tech.assets.show`
- `tech.assets.edit`
- `tech.assets.update`
- `tech.assets.docs`
- `tech.clients.assets.index`

The legacy URL `/tech/clients/assets/{client?}` is still accepted through `tech.clients.assets.legacy`, but named links should use `tech.clients.assets.index`.

API routes are registered from the same module route file. The application API
entry file loads `app/Modules/Asset/routes.php` with an API context so the Asset
module can keep all of its routes in `routes.php`.

Important API route names:

- `api.v1.assets.index`
- `api.v1.assets.show`

## Main Files

- Tech controller: `App\Modules\Asset\Controllers\Tech\AssetController`
- API controller: `App\Modules\Asset\Controllers\Api\V1\AssetController`
- API resource: `App\Modules\Asset\Resources\Api\V1\AssetResource`
- List query: `App\Modules\Asset\Queries\AssetQuery`
- Store action: `App\Modules\Asset\Actions\StoreAsset`
- Update action: `App\Modules\Asset\Actions\UpdateAsset`
- Livewire form: `App\Modules\Asset\Livewire\Tech\AssetForm`
- Livewire asset alerts: `App\Modules\Asset\Livewire\Tech\AssetAlerts`
- Livewire client alert summary: `App\Modules\Asset\Livewire\Tech\ClientAlertsSummary`
- Hidden alert sync bridge: `App\Modules\Asset\Livewire\Tech\Alerts\AlertSyncProcessor`
- Tech views: `app/Modules/Asset/Views/Tech`
- Livewire views: `app/Modules/Asset/Views/Livewire`
- Anonymous asset list component: `app/Modules/Asset/Views/components/tech/assets/list-card.blade.php`

## Database

The module currently uses the existing database tables:

- `assets`
- `asset_alerts`

Important `assets` fields:

- `client_id`, `site_id`, `user_id`: link the asset into the client structure.
- `vendor_id`: links the asset to the Documentation-owned vendor/manufacturer register in the shared `vendors` table.
- `type`: one of `server`, `pc`, `laptop`, `switch`, `ap`, `firewall`, `mobile`, or `other`.
- `serial_number`, `mac_address`, `hostname`: used for manual identification and RMM matching.
- `source`: identifies whether the asset was created manually or by an integration.
- `is_managed`, `status`, `last_seen_at`: reflect RMM or operational state.
- `metadata`: stores integration-specific raw details.

## Model Namespace Note

The Eloquent models intentionally remain in their existing namespace for now:

- `App\Models\Tech\Work\Assets\Asset`
- `App\Models\Tech\Work\Assets\AssetAlert`

This is deliberate. RMM links use a polymorphic `linkable_type` value in `client_rmm_links`, and existing rows may store the old class string. Moving the models before adding a morph map or data migration would risk breaking RMM asset resolution.

## Integration Notes

The module is used by:

- Clients module cards and client-scoped asset pages.
- N-able RMM sync.
- Tactical RMM sync.
- Alert sync jobs.
- Documentation-owned vendor/supplier master data.
- Documentation/template dynamic fields.
- Future Risk links through polymorphic relationship records.

Any future model namespace migration must update hard-coded `linkable_type` strings in jobs and preserve existing `client_rmm_links` rows through a morph map or migration.
