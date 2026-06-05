# RFC: Client Deletion And Retention Policy

Status: Draft
Date: 2026-06-05
Owner: Codex

## Context

Production import work is creating Clients, Sites, and Contacts quickly. Technicians need a way to
remove obvious unwanted Clients from the Client edit view, especially contractless Clients created
during import cleanup.

Deleting Clients is a data-retention decision, not only a UI action. Clients can be referenced by
Tickets, Tasks, Contacts, Sites, Assets, Contracts, Risk, Documentation, integrations, and future
reporting. Some Clients should only be deactivated, some can be archived through soft delete, and a
small subset can be physically deleted when they have no important history.

The current `Client` model does not use `SoftDeletes`.

## Goals

- Add a clear Client removal action from the Client edit/settings view.
- Prevent accidental loss of historical Client records.
- Keep Clients with work history for at least the configured retention period.
- Make retention and hard-delete behavior configurable from settings.
- Show technicians why a Client cannot be deleted and what safer action is available.
- Support production import cleanup without forcing database surgery.

## Non-Goals

- Bulk deletion of Clients.
- Restoring archived Clients from a dedicated recycle-bin UI in the first slice.
- Deleting linked Tickets, Tasks, Contracts, Assets, Contacts, Sites, or integration records as part
  of the first implementation.
- Changing API deletion behavior before the UI policy is proven.

## Current Behavior

- Client edit/settings can update core Client details, status, RMM mapping, and Custom Fields.
- Client index can filter Clients without contracts.
- There is no Client delete/archive route.
- `Client` does not currently use `SoftDeletes`.
- `client.delete` permission exists in the permission map, but no Client delete workflow is
  implemented.

## Proposed Change

Implement a settings-backed Client removal policy.

Default policy:

- `minimum_retention_years`: `3`
- `allow_soft_delete`: `true`
- `allow_hard_delete`: `false`
- `hard_delete_import_only`: `true`
- `block_active_contracts`: `true`
- `block_open_tickets`: `true`
- `block_open_tasks`: `true`
- `block_recent_ticket_history`: `true`
- `recent_ticket_retention_years`: `3`

Removal behavior:

1. The Client edit view shows a `Danger Zone` card only to users allowed by `client.delete`.
2. If the Client has any active/won/sent Contract, open Ticket, open Task, or Ticket newer than the
   configured retention period, the UI offers `Deactivate Client` and explains what blocks deletion.
3. If policy allows soft delete and the Client has no blocking records, the UI offers
   `Archive Client`.
4. If policy allows hard delete and the Client has no blocking records and no important history, the
   UI offers `Delete Permanently`.
5. `Delete Permanently` is off by default and should be used only for import cleanup or clearly
   disposable Clients.

Recommended first slice:

- Add `SoftDeletes` to `clients`.
- Add `ClientRemovalPolicy` support class.
- Add Client removal settings in Client/Admin settings.
- Add deactivate/archive actions to Client edit view.
- Do not implement hard delete until soft-delete behavior is validated in production.

## Impact Analysis

Affected modules:

- Clients: model, edit view, controller/routes, Knowledge docs, tests.
- Commercial: Contract references block deletion.
- Ticket: open and recent Tickets block deletion.
- Task: open Tasks block deletion.
- Contact/Site/Asset/Integration: referenced data must be preserved when a Client is archived.

Permissions:

- Use existing `client.delete` for archive/delete actions.
- Deactivation remains part of Client edit/update for users who can edit Clients.

Routes:

- Add Client-owned routes under `app/Modules/Clients/routes.php`, for example:
  - `PATCH /tech/clients/{client}/deactivate`
  - `DELETE /tech/clients/{client}/archive`
  - optional later: `DELETE /tech/clients/{client}/force-delete`

Data:

- Add nullable `deleted_at` to `clients`.
- Existing foreign keys and references remain intact.
- Hard delete must be blocked when child/reference records exist unless a future RFC explicitly
  approves cascading behavior.

Integrations:

- RMM links should be retained on soft-deleted Clients.
- API list endpoints should continue to exclude soft-deleted Clients by default.

Queues:

- No queue changes expected.

UI:

- Client edit/settings gets a `Danger Zone` card.
- Client index should not show archived Clients by default. A later filter can expose archived
  Clients if restore UI is implemented.

Docs:

- Update Client Knowledge documentation with deactivate/archive/delete rules.

## Data And Migration Plan

- Migration: add `deleted_at` to `clients`.
- Model: add `SoftDeletes` to `App\Models\Clients\Client`.
- No destructive data migration.
- Rollback: remove `deleted_at` only if no archived Clients exist, or restore archived rows before
  rollback.
- Deploy order: code and migration can deploy together. Run `php artisan migrate --force`.

## Testing Plan

- Feature test: Client edit shows `Danger Zone` for delete-capable users.
- Feature test: contractless Client with no work history can be archived.
- Feature test: active/won Contract blocks archive/hard delete.
- Feature test: open Ticket blocks archive/hard delete.
- Feature test: Ticket newer than retention period blocks hard delete.
- Feature test: open Task blocks archive/hard delete.
- Feature test: deactivation remains available when deletion is blocked.
- Feature test: archived Clients disappear from default Client index.
- Unit/service tests for `ClientRemovalPolicy` blocker reasons.

## Documentation Plan

- Update `app/Modules/Clients/Docs/knowledge/client-domain-overview.md`.
- Add deploy note that this introduces a Client soft-delete migration.
- Add Client retention policy to admin/settings documentation if a general settings page is added.

## Open Questions

Should the first implementation include hard delete at all, or should first slice ship only
Deactivate + Archive with hard delete documented as a later, explicitly enabled slice?

## Approval

Pending approval by Svein.
