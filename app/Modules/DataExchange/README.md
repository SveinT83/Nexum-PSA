# DataExchange Module

The DataExchange module owns reusable import/export profiles, Livewire builder configuration, run
history, generated files, import previews, schedules, delivery attempts, audit events, and the safe
source registry contract.

Domain modules own the data they expose. They must register source metadata instead of letting users
write raw SQL. Security secrets and technical credentials are blocked permanently by the source
registry.

Implemented platform surfaces:

- Admin Data Exchange profile list and Livewire builder.
- Safe source registration with export callbacks and approved import targets.
- CSV, JSON, and XLSX export generation to protected local storage.
- Import dry-run and commit through module-approved targets.
- Scheduled export runs through `data-exchange:run-due`.
- Delivery attempts to configured Laravel filesystem disks. FTP/SFTP secrets stay outside Data
  Exchange and are referenced by disk/credential reference only.
- API endpoints under `/api/v1/data-exchange`.
- Default Economy Orders export profile.
- Default Clients basic import profile.

Adding a new source:

1. Create a module-owned support class that returns `DataExchangeSourceDefinition`.
2. Define only safe fields with `DataExchangeFieldDefinition`.
3. Mark importable fields explicitly.
4. Provide export/import callbacks owned by the domain module.
5. Register the source in `AppServiceProvider`.

Data Exchange never stores external passwords, private keys, API tokens, or raw SQL.

See:

- `docs/rfc/2026-07-03-data-exchange-platform.md`
- `docs/adr/2026-07-03-data-exchange-platform-ownership.md`


## Full operational API (2026-10-25)

The approved full API RFC adds 35 official operations and 31 typed schemas. Domain declarations
live in routes.php; api.php is a compatible loader. Contract inventory:
docs/plans/2026-10-25-data-exchange-api-contract.json. Integration API Management and Data Exchange
Overview explain prerequisites, exact steps, scopes and Dev/production status.

Shared SaveDataExchangeProfile / SaveDataExchangeOperations own browser/API configuration.
ExecuteDataExchangeCommand uses encrypted durable receipts and a native mutex; revisions include
all profile children. Generic export commands require preview_hash and Idempotency-Key. Import
commits require If-Match and Idempotency-Key. Profile/mapping, normalized rows and source-owned
target state bind the reviewed preview.

Apply only migration 2026_10_25_190000_create_data_exchange_command_records after native preflight.
It adds mutex, receipt and occurrence tables. Refresh Swagger/routes/config as required and
deliberately restart workers for reviewed activation. Preserve encrypted evidence and its key.

Configure DATA_EXCHANGE_DELIVERY_DISKS and DATA_EXCHANGE_CREDENTIAL_REFERENCES with approved
Integration references. DATA_EXCHANGE_API_DELIVERY_ENABLED defaults false. Existing approved
schedule delivery shares verified runtime without enabling API transport. Destination false,
exception or failed byte verification yields unknown, requiring frozen-destination reconciliation.
Unsupported scheduled import pickup is rejected consistently.

HR-2026-10-25-DATA-EXCHANGE-API is pending and blocks Main/release. No production/provider
activation or new MCP transport is included.
