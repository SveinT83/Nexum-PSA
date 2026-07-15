# Feature Slice: Data Exchange Livewire Builder

Status: Done
Date: 2026-07-03
Parent: `docs/rfc/2026-07-03-data-exchange-platform.md`
Owner: Codex

## Goal

Build the Livewire-based Data Builder for safe profile configuration without raw SQL.

## User-Visible Behavior

Authorized admins can create and edit profiles by choosing registered sources, relations, fields,
filters, mappings, and preview settings through a dynamic builder.

## Scope

- Livewire profile builder.
- Source selection.
- Relation selection.
- Field selection.
- Filter configuration.
- Output mapping.
- CSV/XLSX/JSON format choice.
- Data merge setup for related records.
- Preview metadata and validation warnings.

## Out Of Scope

- Actual export file generation.
- Import commit behavior.
- FTP/SFTP delivery.
- Provider-specific profiles.
- Raw SQL.

## Data Touched

- Data Exchange profile/source/field/filter/mapping tables.
- Registered source metadata from domain modules.

## Permissions

- `data_exchange.view`
- `data_exchange.manage`

## Tests

- Livewire tests for source, fields, relations, filters, and mappings.
- Tests proving blocked fields never appear in the builder.
- Tests proving users without manage permission cannot change profiles.
- Validation tests for invalid profile configuration.

## Documentation

- Update Data Exchange Knowledge docs with profile builder usage.
- Update developer docs with source registry examples.

## Done Criteria

- A valid export profile can be built without raw SQL.
- Blocked fields and unavailable relations are not selectable.
- Tests pass on the Dev server before push.
