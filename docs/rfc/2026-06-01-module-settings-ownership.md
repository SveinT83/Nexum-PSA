# RFC: Module Settings Ownership

Status: Approved
Date: 2026-06-01
Owner: Codex

## Context

The beta settings audit found that several active modules have hardcoded behavior or unclear settings ownership. Existing settings surfaces are now easier to find from Admin, but modules such as Asset, Contact, Knowledge, Risk, Task, Warroom, and Report still need explicit settings ownership before beta can be considered clean.

## Goals

- Keep settings owned by the domain that owns the behavior.
- Add only settings that are backed by working behavior.
- Store lightweight beta settings in `common_settings` when a dedicated schema would be premature.
- Expose module settings from the Admin hub and shared Admin sidebar.
- Protect settings routes with the module's `*.manage_settings` permission when that permission exists.
- Add tests for route ownership, persistence, and the behavior each setting controls.

## Non-Goals

- Build a generic settings framework for every future module.
- Add visible settings for unfinished behavior.
- Redesign every module's settings in one pass.
- Add database tables unless a setting needs relational structure.

## Current Behavior

- Asset has hardcoded manual asset type and IP mode defaults.
- Asset has an `asset.manage_settings` permission but no settings route.
- The Admin hub does not expose Asset settings.
- Several other modules still require ownership decisions before settings can be implemented safely.

## Proposed Change

Implement settings module by module as small feature slices.

The first approved slice is Asset Settings:

- Add `/tech/admin/settings/assets`.
- Store settings in `common_settings` with `type=asset` and `name=defaults`.
- Allow admins to choose enabled asset types, default asset type, default IP mode, and default manual asset status.
- Use those settings in the Livewire asset form and plain HTTP fallback actions.
- Keep settings limited to behavior that works immediately.

## Impact Analysis

- Asset: adds settings controller, support class, admin view, route, and form/default behavior.
- System/Admin navigation: adds Asset settings link.
- Permissions: maps `tech.admin.settings.assets*` to `asset.manage_settings`.
- Data: uses `common_settings`; no migration required.
- UI: adds one Bootstrap admin settings page.
- Integrations: RMM import behavior is unchanged.
- Docs: TODO/audit and Knowledge documentation must reflect the new settings surface.

## Data And Migration Plan

No schema migration is required.

Existing installs will get defaults from code until an admin saves settings. Saving writes one `common_settings` row:

- `type`: `asset`
- `name`: `defaults`
- `json`: normalized settings payload

Rollback is safe by deleting that row; code falls back to defaults.

## Testing Plan

- Feature test that the Asset settings route is owned by the Asset module.
- Feature test that an admin can open and save Asset settings.
- Feature test that plain HTTP asset creation uses configured defaults.
- Existing Asset module tests must keep passing.

## Documentation Plan

- Add Asset Knowledge documentation for Asset settings.
- Update `docs/TODO.md` and the module settings audit as work is completed.

## Open Questions

The remaining modules need their own settings slices: Contact, Knowledge, Risk, Task, Warroom, and Report.

## Approval

Approved by Svein in conversation on 2026-06-01 with the instruction to implement existing function settings.
