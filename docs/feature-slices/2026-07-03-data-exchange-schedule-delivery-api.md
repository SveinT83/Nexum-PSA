# Feature Slice: Data Exchange Schedule, Delivery, And API

Status: Done
Date: 2026-07-03
Parent: `docs/rfc/2026-07-03-data-exchange-platform.md`
Owner: Codex

## Goal

Add scheduled runs, FTP/SFTP delivery and pickup, and API access for profiles, runs, status, triggers,
and generated files.

## User-Visible Behavior

Admins can configure schedules and delivery targets for implemented profiles. Trusted API clients can
list profiles, trigger runs, inspect status, and download generated files when scoped abilities allow
it.

## Scope

- Schedule model and due-run planner.
- Queue jobs for scheduled exports/imports.
- Delivery target references.
- FTP/SFTP delivery for exports.
- FTP/SFTP pickup for imports.
- Delivery attempt history.
- API endpoints for profile listing, run trigger, run status, file download, and import dry-run or
  commit where implemented.
- `data_exchange.*` API abilities in Integration.

## Out Of Scope

- Full Tripletex/PowerOffice API integrations.
- XML.
- Report Builder.

## Data Touched

- Data Exchange schedules.
- Data Exchange delivery targets.
- Data Exchange delivery attempts.
- Data Exchange API resources/controllers.
- Integration API ability catalog.
- Credential references owned by Integration.

## Permissions

- `data_exchange.schedule`
- `data_exchange.delivery`
- `data_exchange.run`
- `data_exchange.download`
- `data_exchange.import`
- `data_exchange.approve_import`

API scopes should mirror the implemented route behavior and must not be added before route tests
exist.

## Tests

- Scheduler due-run tests.
- Queue job tests.
- FTP/SFTP fake adapter tests.
- Delivery failure/retry/audit tests.
- API ability enforcement tests.
- API trigger/status/download tests.

## Documentation

- Update Integration API management docs.
- Update Data Exchange Knowledge docs with schedules and delivery.
- Add operational notes for queue/scheduler requirements.

## Done Criteria

- Scheduled runs execute through queues.
- Delivery attempts are auditable.
- API clients can safely discover and trigger implemented profiles.
- Tests pass on the Dev server before push.
