# DataExchange Module

The DataExchange module owns reusable import/export profiles, Livewire builder configuration, run
history, generated files, import previews, schedules, delivery attempts, audit events, and the safe
source registry contract.

Domain modules own the data they expose. They must register source metadata instead of letting users
write raw SQL. Security secrets and technical credentials are blocked permanently by the source
registry.

Implemented v1 surfaces:

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
