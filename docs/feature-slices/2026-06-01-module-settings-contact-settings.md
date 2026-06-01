# Feature Slice: Contact Settings

Status: Done
Date: 2026-06-01
Parent: `docs/rfc/2026-06-01-module-settings-ownership.md`
Owner: Codex

## Goal

Move beta-ready Contact form defaults and relation type choices out of hardcoded Livewire/action methods and into Contact-owned settings.

## User-Visible Behavior

Admins can configure default contact type, default contact status, default relation type, and relation type choices used by the Contact form.

## Scope

- Add Contact settings route, controller, support class, and admin view.
- Store settings in `common_settings`.
- Use settings in the Contact Livewire form and StoreContact action.
- Keep duplicate protection mandatory.
- Add tests and documentation.

## Out Of Scope

- Language settings.
- Full custom field support.
- Contact merge UI.
- Disabling duplicate protection by email or phone.

## Data Touched

- `common_settings` row with `type=contact` and `name=defaults`.
- New contacts may receive configured default type/status/relation values.

## Permissions

- `tech.admin.settings.contacts*` requires `contact.manage_settings`.

## Tests

- Contact module feature tests for route ownership, settings persistence, and default behavior.
- Admin hub test update for discoverability.

## Documentation

- Contact Knowledge documentation for settings behavior.
- TODO/audit updates.

## Done Criteria

- Settings page opens and saves.
- Settings affect Contact form relation options and create defaults.
- Tests pass.
- Documentation is updated.

## Completed

- Added `/tech/admin/settings/contacts`.
- Added `ContactSettings` support class backed by `common_settings`.
- Added Admin hub/sidebar navigation.
- Applied relation settings in the Livewire Contact form.
- Applied default contact type/status/relation in `StoreContact`.
