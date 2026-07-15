# RFC: Data Exchange Platform

Status: Approved
Date: 2026-07-03
Owner: Codex

## Context

GitHub Discussion #163 defines a merged direction for Data Exchange in Nexum PSA. The older Economy
order export idea from Discussion #147 is now superseded as a standalone feature and becomes the
first concrete Economy use case for Data Exchange.

Nexum already has several building blocks this plan must reuse:

- Economy owns internal billing preparation orders and order lines.
- Integration owns API key management, API ability catalog conventions, and external connection
  settings.
- Report owns the shared report hub and future report export direction, while individual domains
  own their own data/query behavior.
- Domain API Foundation already defines explicit Sanctum abilities and module-owned API routes.
- Many modules already expose user-facing workflows, Knowledge docs, and domain-owned tests.

What is missing is a general platform for safe import/export, reusable profiles, generated files,
scheduled exchange, API-triggered exchange, and delivery or pickup through FTP/SFTP or integration
connectors.

The goal is not to build a one-off Economy export and replace it later. Data Exchange must be the
foundation first, and Economy/Orders export must be implemented through the same profile/runtime
model as future Tripletex, PowerOffice, n8n, Zapier, and custom workflows.

## Goals

- Create a singular `DataExchange` module that owns profiles, Data Builder configuration, import and
  export runs, generated files, audit, retention, schedules, and delivery history.
- Build a Livewire-based Data Builder for selecting approved data sources, fields, filters,
  relations, mappings, formatting, grouping, and data merging.
- Support all reasonable importable/exportable business data in Nexum through safe registered data
  sources, not through raw SQL.
- Permanently block security secrets and technical credentials from Data Exchange, including
  password hashes, remember tokens, two-factor secrets, API tokens, encrypted credentials, private
  keys, and integration secrets.
- Support CSV, XLSX, and JSON in v1.
- Store generated export files with run history, download access, audit trail, retention policy, and
  machine-readable status.
- Support manual run, scheduled run, API-triggered run, and delivery/pickup through FTP/SFTP or
  future integration connectors.
- Support import as a first-class Data Exchange capability, but only through module-approved import
  targets with validation, preview/dry-run, and commit/audit behavior.
- Expose API endpoints so trusted automation can list profiles, inspect update/run status, trigger
  runs, download generated files, and start import dry-runs/commits when permissions allow.
- Use Data Exchange profiles from module buttons, for example `Export orders` on `/tech/economy`.
- Keep Integration as the owner of credentials and external connectors.
- Keep domain modules as owners of their data sources, import targets, validation rules, and
  business behavior.

## Non-Goals

- Do not allow raw SQL in v1.
- Do not export security secrets or technical credentials, even for superadmins.
- Do not let Data Exchange write directly to arbitrary tables.
- Do not build Tripletex or PowerOffice full API integrations in this RFC.
- Do not create customer-facing invoice sending in Economy.
- Do not replace Economy order generation, Report registry, or Integration API key management.
- Do not expose UI controls for schedules, delivery targets, import commits, or provider profiles
  before the matching behavior exists and is tested.
- Do not build XML support in v1.
- Do not build a general report builder as part of this RFC. Report Builder remains separate, though
  it may later reuse Data Exchange export runtime.

## Current Behavior

Economy currently prepares internal orders and order lines. The Economy API exposes draft order
generation and order state management, but explicitly does not export accounting data, create
invoices, or send invoices to external accounting systems.

Report has a registry and discovery hub. It does not yet own shared export, saved views, scheduled
delivery, or report builder behavior.

Integration owns API key management and provider settings for existing integrations such as RMM and
BookStack. API abilities are centrally cataloged, while domain modules own their own API controllers,
resources, validation, and tests.

There is no reusable import/export profile engine. There is no shared generated-file history,
profile mapping UI, schedule model, FTP/SFTP delivery history, or API contract for export profiles.

## Proposed Change

Create a singular `DataExchange` module under:

```text
app/Modules/DataExchange
```

The module owns the Data Exchange platform:

- data source registry contract,
- profile records,
- Livewire Data Builder surfaces,
- profile mappings and formatting rules,
- export/import runtime,
- run records,
- generated files,
- import previews,
- schedule definitions,
- delivery/pickup attempts,
- audit history,
- retention rules,
- Data Exchange API routes and resources.

### Ownership Rules

`DataExchange` owns:

- Profile lifecycle.
- Data Builder configuration.
- Run lifecycle and status.
- Generated files and retention.
- Manual execution UI.
- Import dry-run/commit orchestration.
- Scheduling orchestration.
- Delivery/pickup orchestration.
- Data Exchange API surface.

Domain modules own:

- Which data sources are available.
- Which fields are exportable.
- Which relations can be followed.
- Which filters are allowed.
- Which fields are sensitive and blocked.
- Which import targets are available.
- Validation and persistence for imported data.
- Domain-specific tests and Knowledge docs.

`Integration` owns:

- API tokens and API ability catalog conventions.
- FTP/SFTP credentials.
- Webhook/integration credentials.
- Future Tripletex and PowerOffice API credentials.
- Connection health patterns for external services.

`Economy` owns:

- Order generation.
- Order/order-line models and state rules.
- Billing preparation behavior.
- The first concrete Data Exchange profile family for Economy/Orders export.

`Report` owns:

- Report discovery and report hub.
- Future report builder if separately approved.
- Report definitions and report navigation.

Data Exchange may later reuse report definitions or expose export actions from report pages, but it
does not replace Report domain ownership.

### Data Builder

The Data Builder must be Livewire-based and must not expose raw SQL in v1.

It should let authorized users build profiles from safe metadata:

- source selection,
- relation selection,
- field selection,
- field labels,
- filters,
- sorting,
- grouping where safe,
- one-row-per-record and nested/line-based outputs,
- formatting rules,
- output column/key mapping,
- default values,
- conditional values where safe,
- data merging from related records,
- preview rows,
- validation warnings.

Examples:

- Economy order header + client + company settings.
- Economy order lines + products/services + VAT fields.
- Client + contacts + sites for a CRM export.
- Asset records + site/client relation.
- Ticket data for external analysis or automation.

### Data Source Registry

Data sources must be registered by code, not discovered blindly from tables.

Each registered source should define:

- stable key,
- owning module,
- display label,
- model/query owner,
- allowed fields,
- blocked fields,
- field data types,
- labels and descriptions,
- allowed filters,
- allowed relations,
- relation cardinality,
- permission requirements,
- export support,
- import support if applicable.

The intended product scope is all reasonable business data across Nexum, but source onboarding can
be implemented in slices. The platform must make it straightforward for every module to register
safe sources without giving users raw database access.

### Import

Import must be supported by the v1 platform, but write access is restricted to module-approved import
targets.

Import profiles must support:

- uploaded file input,
- future FTP/SFTP pickup,
- API-triggered input,
- CSV, XLSX, and JSON parsing,
- mapping incoming fields to target fields,
- validation,
- dry-run preview,
- row-level success/error report,
- explicit commit,
- audit trail,
- rollback-safe failure behavior where practical.

Data Exchange does not directly insert/update arbitrary database rows. It calls import target
contracts owned by the module that owns the data.

### Export

Export profiles must support:

- manual run,
- scheduled run,
- API-triggered run,
- preview,
- CSV/XLSX/JSON output,
- stored generated files,
- download from UI,
- download from API with proper scopes,
- retention,
- delivery attempts,
- run history and audit.

### Schedules And Delivery

Scheduling and delivery should be built after profile/runtime foundation.

Supported v1 delivery/pickup direction:

- manual download,
- API retrieval,
- FTP/SFTP delivery,
- FTP/SFTP pickup for imports,
- webhook/integration handoff where the connector exists.

Credentials for delivery targets must be owned by Integration or a shared encrypted credential layer
approved by this RFC's ADR. Data Exchange profiles reference credentials; they do not store secrets
directly.

### Provider Profiles

Tripletex and PowerOffice profiles should be implemented only after the Data Exchange foundation,
export runtime, import runtime, schedule/delivery, and first Economy export profile are in place.

Provider profiles are predefined Data Exchange profiles with known mapping defaults. If a later full
API integration is enabled, it should reuse or take over the same profile settings instead of
introducing a parallel export system.

## Impact Analysis

- **Architecture:** new singular `DataExchange` module is required.
- **Database:** new tables for profiles, profile steps/mappings, runs, generated files, schedules,
  delivery targets, delivery attempts, import previews, and audit events.
- **Permissions:** new UI permissions and API abilities are required.
- **API:** new `data_exchange.*` scopes are required and must be documented in Integration API docs.
- **UI:** new Admin/Data Exchange area and module action buttons, starting with Economy.
- **Livewire:** Data Builder should use Livewire for dynamic field/relation/mapping workflows.
- **Economy:** Economy/Orders export becomes the first real Data Exchange profile family.
- **Integration:** credential and external target ownership must be reused, not duplicated.
- **Report:** future reports/export behavior should be designed to reuse Data Exchange where
  appropriate, without merging Report Builder into this RFC.
- **Queues/Scheduler:** scheduled exports/imports and delivery attempts require queue and scheduler
  coverage.
- **Storage:** generated files require protected storage paths, retention cleanup, and download
  authorization.
- **Security:** field-level blocking and permission checks are required before any broad data source
  registry is usable.
- **Documentation:** RFC, ADR, feature slices, module docs, API docs, and Knowledge docs must be
  maintained.

## Data And Migration Plan

The detailed table design belongs to the foundation feature slice, but the RFC expects these
concepts:

- `data_exchange_profiles`
- `data_exchange_profile_sources`
- `data_exchange_profile_fields`
- `data_exchange_profile_filters`
- `data_exchange_profile_mappings`
- `data_exchange_runs`
- `data_exchange_files`
- `data_exchange_schedules`
- `data_exchange_delivery_targets`
- `data_exchange_delivery_attempts`
- `data_exchange_import_previews`
- `data_exchange_audit_events`

Migration rules:

- Add Data Exchange tables without modifying existing Economy, Report, Integration, Client, Ticket,
  Asset, Storage, or Commercial data.
- Seed no active schedules by default.
- Seed no enabled provider credentials by default.
- Seed safe default profile templates only when the full source/runtime needed by that template is
  implemented.
- Generated files should be stored in a protected disk/path, not public web paths.
- Retention must be configurable with a conservative default.

Rollback:

- Disabling Data Exchange must not delete domain data.
- Generated files and run history can be retained or cleaned by explicit maintenance commands.
- Import commits must be audited by the owning module so failures can be reviewed.

## Testing Plan

Foundation:

- Module route registration tests.
- Permission tests for profile view/manage/run/download/import/schedule/delivery.
- Source registry tests proving blocked fields cannot be exported.
- Tests proving secret-like fields are permanently blocked.
- Tests proving raw SQL is not accepted by v1 profile configuration.

Data Builder:

- Livewire tests for selecting source, fields, relations, filters, mapping, and preview.
- Tests for relation merging and one-to-many line outputs.
- Tests for permission-filtered source visibility.

Export:

- Unit tests for CSV, XLSX, and JSON generation.
- Feature tests for manual runs, stored files, downloads, run history, and retention metadata.
- API tests for profile listing, triggering, status, and file download.

Import:

- Parser tests for CSV, XLSX, and JSON.
- Dry-run tests with row-level validation errors.
- Commit tests for approved import targets.
- Tests proving Data Exchange cannot write to unsupported targets.

Schedule and delivery:

- Scheduler tests for due runs.
- Queue/job tests for export/import delivery.
- FTP/SFTP delivery tests with fakes or isolated adapters.
- Failure/retry audit tests.

Economy profile:

- Feature tests for `Export orders` from `/tech/economy`.
- Tests proving Economy export uses Data Exchange profiles and stored runs.
- Tests for order header and order line output.
- Tests for manual download and API retrieval.

## Documentation Plan

- Add `DataExchange` module developer documentation.
- Add Knowledge documentation for:
  - Data Exchange overview,
  - profile builder,
  - export runs and generated files,
  - import dry-run and commit,
  - schedules and delivery targets,
  - Economy Orders export.
- Update Integration API management docs with `data_exchange.*` abilities and endpoints.
- Update Economy docs when the Economy export action is implemented.
- Update Report docs if report surfaces later expose Data Exchange-powered exports.
- Add operational notes for queue, scheduler, storage, and retention.

## Feature Slices

1. `docs/feature-slices/2026-07-03-data-exchange-foundation.md`
   - Module, permissions, source registry contract, tables, profile/run/file models, protected
     storage contract, and admin shell.
2. `docs/feature-slices/2026-07-03-data-exchange-livewire-builder.md`
   - Livewire Data Builder for source/field/filter/relation/mapping/profile setup.
3. `docs/feature-slices/2026-07-03-data-exchange-export-runtime.md`
   - CSV/XLSX/JSON export generation, stored files, downloads, audit, retention, and manual runs.
4. `docs/feature-slices/2026-07-03-data-exchange-import-runtime.md`
   - CSV/XLSX/JSON import parsing, mapping, dry-run, approved import targets, commit, and audit.
5. `docs/feature-slices/2026-07-03-data-exchange-schedule-delivery-api.md`
   - Scheduler, queues, FTP/SFTP delivery/pickup, API trigger/status/download, and integration
     credential references.
6. `docs/feature-slices/2026-07-03-data-exchange-economy-orders-profile.md`
   - First real Economy/Orders export profile, `/tech/economy` action button, download, and API
     retrieval.
7. Future slice: Tripletex file profile.
8. Future slice: PowerOffice file profile.
9. Future slice: full Tripletex API connector that can reuse/take over Data Exchange profiles.
10. Future slice: full PowerOffice API connector that can reuse/take over Data Exchange profiles.

## Open Questions

No open question blocks the first implementation slices.

Non-blocking decisions for later slices:

- Exact first complete list of module data sources after the source registry contract exists.
- Exact field mappings for Tripletex and PowerOffice once provider file/API requirements are
  confirmed.
- Whether Report Builder later becomes a Data Exchange source, a Data Exchange consumer, or both.
- Whether advanced computed fields need their own expression builder after v1.

## Approval

Approved by Svein in conversation on 2026-07-03 after clarifying:

- Data Exchange is a new `DataExchange` domain/module.
- Export and import are both required for the finished v1 platform.
- Economy/Orders export must use Data Exchange from the start.
- The Data Builder must use Livewire.
- Raw SQL is not allowed in v1.
- All reasonable business data should be available through safe registered sources.
- Security secrets and technical credentials are permanently blocked.
- Import writes only through module-approved import targets.
- v1 formats are CSV, XLSX, and JSON.
- Generated files are stored with run history, audit, retention, and download/API access.
- v1 is tech/admin only, with strict UI permissions and `data_exchange.*` API abilities.
- Svein asked Codex to save the RFC and start implementation.
