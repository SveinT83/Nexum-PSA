# Feature Slice: Data Exchange Export Runtime

Status: Done
Date: 2026-07-03
Parent: `docs/rfc/2026-07-03-data-exchange-platform.md`
Owner: Codex

## Goal

Generate CSV, XLSX, and JSON exports from Data Exchange profiles and store generated files with
history, download authorization, audit, and retention metadata.

## User-Visible Behavior

Authorized users can run an export profile manually, see run status, download the generated file,
and review prior runs.

## Scope

- Export run service.
- CSV generation.
- XLSX generation.
- JSON generation.
- Stored generated files.
- Manual download.
- Run status and error reporting.
- Audit events.
- Retention metadata and cleanup hooks.

## Out Of Scope

- Import runtime.
- Schedules.
- FTP/SFTP delivery.
- API endpoints beyond what this slice explicitly needs.
- Economy button integration.

## Data Touched

- Data Exchange runs.
- Data Exchange files.
- Data Exchange audit events.
- Protected file storage.

## Permissions

- `data_exchange.view`
- `data_exchange.run`
- `data_exchange.download`

## Tests

- Unit tests for CSV, XLSX, and JSON output.
- Feature tests for manual run and download.
- Authorization tests for downloading generated files.
- Failure/audit tests.
- Retention metadata tests.

## Documentation

- Update Knowledge docs with export runs and generated files.
- Add operational notes for storage and retention.

## Done Criteria

- Manual export produces downloadable CSV, XLSX, and JSON files.
- Files are not public without authorization.
- Run history and audit are visible.
- Tests pass on the Dev server before push.
