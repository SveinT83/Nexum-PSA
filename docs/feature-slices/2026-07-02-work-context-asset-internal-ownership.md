# Feature Slice: Work Context Asset Internal Ownership

Status: Done
Date: 2026-07-02
Parent: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Owner: Codex

## Goal

Allow company-owned/internal Assets without requiring a fake Client while keeping client assets and
RMM synchronization client-safe.

## User-Visible Behavior

Technicians can create an Asset with no Client selected. Such Assets are marked as internal,
cannot keep Site or Client contact ownership, and are not offered client RMM sync actions.

## Scope

- Make Asset `client_id` nullable.
- Add `assets.work_context_id`.
- Resolve Work Context from Client selection in HTTP, Livewire, and API create/update paths.
- Keep Site and User/Owner validation scoped to selected Clients.
- Add Asset API context output and filters.
- Update Asset index/detail display for internal ownership.
- Preserve RMM imports as client-scoped only.

## Out Of Scope

- No RMM sync for internal Assets.
- No migration of fake self-client Assets into internal context.
- No asset billing or depreciation changes.

## Data Touched

- `assets.client_id`
- `assets.work_context_id`
- Asset model, actions, Livewire form, API resource/controller, Tech views, tests, and docs.

## Permissions

Existing Asset UI permissions and API abilities remain unchanged.

## Tests

- Tech HTTP fallback can create internal Assets.
- API can create and filter internal Assets.
- Client-scoped Asset creation still validates Site ownership.
- API/resource payloads expose Work Context and do not build Client links for internal Assets.
- RMM imports keep setting real Client/Site context.

Verification on 2026-07-02:

- `HOME=/tmp php artisan migrate`
- `HOME=/tmp php artisan test app/Modules/Asset/Tests/Feature/AssetModuleTest.php`
- Covered again by `HOME=/tmp php artisan test` with 671 passing tests and 4955 assertions.
- Knowledge docs were synced with the final Work Context batch: 12 chapters, 39 articles, `skipped = 0`.

## Documentation

Update Asset Knowledge docs and WorkContext docs, then sync to BookStack.

## Done Criteria

- Internal Asset creation works through Tech and API paths.
- Client Asset behavior remains compatible.
- Integration sync paths continue to use real Client contexts only.
- Asset tests pass.
