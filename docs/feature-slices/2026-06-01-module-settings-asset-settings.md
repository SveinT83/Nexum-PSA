# Feature Slice: Asset Settings

Status: Done
Date: 2026-06-01
Parent: `docs/rfc/2026-06-01-module-settings-ownership.md`
Owner: Codex

## Goal

Add a beta-ready Asset settings surface for behavior that already exists or can be completed safely in one slice.

## User-Visible Behavior

Admins can open Asset settings from the Admin hub/sidebar and configure manual asset defaults. The create/edit asset form uses those settings immediately.

## Scope

- Add Asset settings route, controller, view, and support class.
- Store settings in `common_settings`.
- Configure enabled asset types, default asset type, default IP mode, and default manual asset status.
- Use settings in Livewire asset form and plain HTTP fallback create/update validation.
- Add Admin navigation links.
- Add tests and documentation.

## Out Of Scope

- RMM sync behavior changes.
- Alert policy settings.
- Custom database-backed asset types beyond the current `assets.type` enum.
- Asset related-ticket implementation.

## Data Touched

- `common_settings` row with `type=asset` and `name=defaults`.
- New manually created assets may receive configured defaults.

## Permissions

- `tech.admin.settings.assets*` requires `asset.manage_settings`.

## Tests

- Asset module feature tests for route ownership, settings persistence, and asset creation defaults.
- Admin hub test update for discoverability.

## Documentation

- Asset Knowledge documentation for settings behavior.
- `docs/TODO.md` and audit updates.

## Done Criteria

- Settings page opens and saves.
- Settings affect manual asset creation.
- Tests pass.
- Documentation is updated.

## Completed

- Added `/tech/admin/settings/assets`.
- Added `AssetSettings` support class backed by `common_settings`.
- Added Admin hub/sidebar navigation for Assets.
- Applied settings in the Livewire Asset form and HTTP fallback create/update actions.
- Added Knowledge documentation under the Asset module.
