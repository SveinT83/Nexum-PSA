# Feature Slice: Data Exchange Foundation

Status: Done
Date: 2026-07-03
Parent: `docs/rfc/2026-07-03-data-exchange-platform.md`
Owner: Codex

## Goal

Create the Data Exchange module foundation and safe ownership contracts before any provider-specific
profile is built.

## User-Visible Behavior

Admins can open a Data Exchange admin area and see the first profile/run/file shell. No unfinished
buttons should be visible for behavior that is not implemented yet.

## Scope

- Create `app/Modules/DataExchange`.
- Add module routes, controllers, views, tests, and module documentation.
- Add permissions for view/manage/run/download/import/schedule/delivery where applicable.
- Add the safe data source registry contract.
- Add core profile, run, generated file, and audit models/tables needed by later slices.
- Add protected generated-file storage conventions.
- Add Admin navigation only for implemented pages.
- Add initial Knowledge documentation stub only when the UI is usable.

## Out Of Scope

- Livewire Data Builder.
- CSV/XLSX/JSON generation.
- Import parsing or commits.
- FTP/SFTP delivery.
- Data Exchange API endpoints.
- Economy Orders export button.
- Tripletex or PowerOffice profiles.
- Raw SQL.

## Data Touched

- New Data Exchange tables only.
- Permission seeders.
- Admin navigation.
- No existing domain data is migrated.

## Permissions

Foundation should introduce the permission vocabulary, but routes must only require permissions for
behavior that exists.

Expected permission family:

- `data_exchange.view`
- `data_exchange.manage`
- `data_exchange.run`
- `data_exchange.download`
- `data_exchange.import`
- `data_exchange.approve_import`
- `data_exchange.schedule`
- `data_exchange.delivery`

## Tests

- Module route registration.
- Permission seeding.
- Admin route access control.
- Source registry blocks secret-like fields.
- Foundation pages do not expose unfinished controls.
- Profile/run/file models can be created with valid minimal data.

## Documentation

- RFC and ADR already exist.
- Add module README/developer notes.
- Add Knowledge documentation once the admin shell is user-visible.

## Done Criteria

- DataExchange module exists and follows module architecture.
- Core tables migrate cleanly.
- Permission seeders include Data Exchange permissions.
- Admin users can view implemented shell pages.
- No unfinished actions are visible.
- Narrow foundation tests pass on the Dev server before push.
