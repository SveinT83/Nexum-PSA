# Feature Slice: Data Exchange Import Runtime

Status: Done
Date: 2026-07-03
Parent: `docs/rfc/2026-07-03-data-exchange-platform.md`
Owner: Codex

## Goal

Support CSV, XLSX, and JSON imports through dry-run preview and module-approved import targets.

## User-Visible Behavior

Authorized users can upload/import a file, map columns/keys, run a validation preview, review row
errors, and commit only to approved targets.

## Scope

- CSV parser.
- XLSX parser.
- JSON parser.
- Import profile mapping.
- Dry-run preview.
- Row-level validation results.
- Commit orchestration through module-owned import target contracts.
- Audit trail.

## Out Of Scope

- Direct writes to arbitrary tables.
- FTP/SFTP pickup.
- Schedules.
- Provider-specific imports.
- Raw SQL.

## Data Touched

- Data Exchange import preview/run tables.
- Module-owned import targets when committed.
- Audit events.

## Permissions

- `data_exchange.view`
- `data_exchange.import`
- `data_exchange.approve_import`

## Tests

- Parser tests for CSV, XLSX, and JSON.
- Dry-run validation tests.
- Commit tests for approved import targets.
- Tests proving unsupported targets cannot be written.
- Authorization tests for dry-run and commit.

## Documentation

- Update Knowledge docs with import dry-run and commit rules.
- Add developer docs for module import target contracts.

## Done Criteria

- Imports can be previewed safely.
- Commits go only through approved import targets.
- Failed rows are reported clearly.
- Tests pass on the Dev server before push.
