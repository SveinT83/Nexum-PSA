# Asset Settings

Asset Settings controls the defaults used when technicians manually register assets.

Open the settings from:

`Admin -> Assets -> Asset settings`

The route is:

`/tech/admin/settings/assets`

Access requires the `asset.manage_settings` permission.

## Manual Asset Defaults

Admins can configure:

- Enabled asset types.
- Default asset type.
- Default IP mode.
- Default manual asset status.

These settings are applied when a technician creates an asset from the manual Asset form or from the plain HTTP fallback route.

RMM imports keep their integration-owned behavior. Do not use Asset Settings to control N-able RMM, Tactical RMM, UniFi, or Omada sync behavior unless that behavior is explicitly implemented.

## Enabled Asset Types

Enabled asset types control which current system asset types are available in the manual Asset form.

The current `assets.type` database column is constrained to the existing system values. Because of that, Asset Settings can enable or disable existing values, but it cannot create new asset type values yet.

Available system values are:

- Server
- PC
- Laptop
- Switch
- Access Point
- Firewall
- Mobile
- Other

If custom asset types are needed later, that must be handled as a planned schema and workflow change.

## Storage

The settings are stored in `common_settings`:

- `type`: `asset`
- `name`: `defaults`
- `json`: normalized settings payload

If no row exists, Nexum PSA uses safe defaults:

- All current asset types enabled.
- Default asset type: `Other`.
- Default IP mode: `DHCP`.
- Default manual status: `Unknown`.
