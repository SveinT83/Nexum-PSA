# Feature Slice: Data Exchange Economy Orders Profile

Status: Done
Date: 2026-07-03
Parent: `docs/rfc/2026-07-03-data-exchange-platform.md`
Owner: Codex

## Goal

Implement the first real Data Exchange profile family for Economy Orders and expose it from the
Economy workspace.

## User-Visible Behavior

Authorized users can export Economy Orders from `/tech/economy` through a Data Exchange profile.
They can download the file manually or let the profile delivery behavior handle it when configured.

## Scope

- Economy Orders registered as safe Data Exchange sources.
- Economy Order Lines registered as related line data.
- Client, site/contact where available, and company/system fields needed for billing exports.
- Default Economy Orders export profile template.
- `/tech/economy` export action that uses Data Exchange.
- Stored run/file history for Economy exports.
- CSV/XLSX/JSON output through the existing export runtime.
- API retrieval through Data Exchange API when implemented.

## Out Of Scope

- Tripletex-specific mapping unless its file requirements are approved in a later slice.
- PowerOffice-specific mapping unless its file requirements are approved in a later slice.
- Sending invoices.
- Creating accounting invoices through provider APIs.
- Bypassing Data Exchange with a one-off Economy export.

## Data Touched

- Data Exchange profiles/runs/files.
- Economy order/order-line read data.
- No Economy order generation changes unless a later issue requires it.

## Permissions

- `data_exchange.view`
- `data_exchange.run`
- `data_exchange.download`
- Economy view/update permissions as required by existing routes.

## Tests

- Economy source registration tests.
- Export action permission tests.
- Export output tests for order header and order lines.
- Stored file/download tests.
- Regression tests proving Economy order generation remains unchanged.

## Documentation

- Update Economy Knowledge docs with export behavior.
- Update Data Exchange docs with the Economy Orders profile.

## Done Criteria

- `/tech/economy` can run an Economy Orders export through Data Exchange.
- Generated files are stored and downloadable.
- No standalone Economy export path exists outside Data Exchange.
- Tests pass on the Dev server before push.
