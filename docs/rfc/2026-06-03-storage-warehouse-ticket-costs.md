# RFC: Storage Warehouse And Ticket Costs

Status: Approved
Date: 2026-06-03
Owner: Codex

## Context

GitHub issue #62 reports that Warehouse support is missing and that Nexum should have a default
Company warehouse. GitHub issue #47 reports that technicians should be able to add a ticket cost
without mapping it to a Storage item.

Both requests touch the existing Storage and Ticket cost flow. Storage already has
`storage_warehouses`, `StoreWarehouse`, admin inventory settings, item reservations, picking, and
Knowledge documentation. Ticket already has `ticket_cost_entries`, Storage-backed cost reservation
actions, ticket activity rows, and pending billing status. The gap is not a new domain; it is missing
completion around default warehouse behavior and non-stock ticket costs.

## Goals

- Guarantee a usable default Company warehouse for clean installs and existing installs without an
  active warehouse.
- Let admins see and change the default warehouse from Storage inventory settings.
- Use the default warehouse to reduce friction when creating Storage items and boxes.
- Let technicians add ticket costs that are not backed by Storage stock.
- Keep manual ticket costs visible in the Ticket activity timeline and pending for later billing.
- Preserve the existing Storage reservation and picking behavior for stock-backed costs.
- Add tests and Knowledge documentation for both workflows.

## Non-Goals

- Build full invoicing or automatic Economy settlement in this RFC.
- Replace the existing Storage reservation and picking model.
- Add purchase order receiving, barcode scanning, stock transfers, or warehouse hierarchy changes.
- Add contract entitlement logic for whether a cost is billable, included, or ignored.
- Add client-visible cost approval workflows.

## Current Behavior

Storage has warehouses, but admins must create them manually from
`/tech/admin/settings/storage/inventory`. Items and boxes require a warehouse. There is no explicit
default warehouse setting, and clean installs can have no warehouse ready for basic inventory work.

Ticket `Add cost` only supports active Storage items. Saving a cost reserves stock, creates a
`storage_reservations` record, increments `storage_items.qty_reserved`, and creates a pending
`ticket_cost_entries` row with `status = reserved`. The picking list only includes Storage-backed
reserved entries.

`TicketCostEntry` can conceptually represent non-stock costs, but the current database migration
requires `ticket_cost_entries.storage_item_id`, so manual costs need one compatibility migration
before the UI and actions can expose that behavior.

## Proposed Change

Implement this RFC as two feature slices.

### Slice 1: Default Company Warehouse

- Add a Storage inventory support service that resolves the default warehouse.
- Store the default warehouse pointer in `common_settings` with `type = storage` and
  `name = inventory_defaults`.
- If no active warehouse exists, create an active warehouse named `Company Warehouse` with code
  `COMPANY` when Storage inventory defaults are ensured.
- If active warehouses exist but no default is configured, use the first active warehouse by name as
  the default until an admin saves a different default.
- Extend `/tech/admin/settings/storage/inventory` so admins can mark one warehouse as default.
- Use the default warehouse as the preselected warehouse in item and box creation forms.

### Slice 2: Manual Ticket Cost Entries

- Add a Ticket action for creating manual cost entries without a Storage item or reservation.
- Extend the Ticket show `Add cost` modal with two modes:
  - `Storage item`: current reservation behavior.
  - `Manual cost`: name, quantity, unit price ex VAT, currency, invoice text, and optional note.
- Store manual costs in `ticket_cost_entries` with:
  - `storage_item_id = null`
  - `storage_reservation_id = null`
  - `item_name` from the technician's input
  - `item_sku = null`
  - `unit_price_ex_vat` from the technician's input
  - `currency = NOK` for the first slice unless a broader currency setting already exists
  - `status = manual`
  - `billing_status = pending`
- Show manual costs in the existing Ticket activity timeline.
- Do not show manual costs in Storage picking because there is no stock to pick.
- Keep billing settlement as pending for future Economy work.

## Impact Analysis

- Storage:
  - Adds default warehouse resolution and admin default selection.
  - May create one default warehouse record on clean installs or installs with no active warehouse.
  - Item and box create forms get a default selection.
- Ticket:
  - Adds a manual cost action and validation.
  - Extends the Ticket show cost modal.
  - Extends activity display to distinguish manual costs from reserved Storage costs.
- Economy:
  - No immediate behavior change.
  - Future billing should read pending manual costs alongside pending Storage cost entries.
- Commercial:
  - No immediate behavior change.
  - Contract coverage and cost entitlement remain out of scope.
- Permissions:
  - Storage default management uses the existing admin Storage inventory settings route.
  - Ticket manual cost creation uses the existing ticket cost creation route and technician access
    pattern unless implementation discovers a stricter permission requirement.
- Routes:
  - No new domain route file.
  - Prefer reusing existing Storage inventory settings and Ticket cost routes.
- Data:
  - Uses existing `storage_warehouses`, `common_settings`, and `ticket_cost_entries`.
  - Adds a compatibility migration that makes `ticket_cost_entries.storage_item_id` nullable.
- Queues and scheduler:
  - No queue, scheduler, or background worker changes.
- UI:
  - Storage admin settings gains default warehouse selection.
  - Ticket show cost modal gains a manual cost mode.
- Documentation:
  - Storage and Ticket Knowledge docs must be updated.

## Data And Migration Plan

One database schema migration is required:

- Make `ticket_cost_entries.storage_item_id` nullable so manual ticket costs can exist without a
  Storage item.

Default warehouse state is stored in `common_settings`:

- `type`: `storage`
- `name`: `inventory_defaults`
- JSON payload: `{ "default_warehouse_id": 123 }`

On clean installs or installs with no active warehouse, Storage default resolution creates:

- `storage_warehouses.name = Company Warehouse`
- `storage_warehouses.code = COMPANY`
- `storage_warehouses.is_active = true`

Rollback:

- Delete the `common_settings` row to fall back to first active warehouse.
- The automatically created warehouse is ordinary data and should not be deleted automatically if
  items, boxes, movements, or reservations reference it.

Manual ticket costs use nullable Storage-link columns in `ticket_cost_entries`. Rollback requires
deleting or mapping manual entries before making `storage_item_id` required again.

## Testing Plan

- Storage feature test: admin inventory settings creates or exposes a default Company warehouse when
  none exists.
- Storage feature test: admin can set a different active warehouse as default.
- Storage feature test: item and box creation forms preselect the configured default warehouse.
- Ticket feature test: technician can add a manual cost without a Storage item.
- Ticket feature test: manual costs appear in Ticket activity and do not create Storage
  reservations or alter stock counts.
- Ticket/Storage regression test: Storage-backed costs still reserve stock and still appear in the
  picking list.
- Run:
  - `HOME=/tmp php artisan test app/Modules/Storage/Tests/Feature/StorageModuleTest.php`
  - `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php`

## Documentation Plan

- Update `app/Modules/Storage/Docs/knowledge/storage-inventory.md` with default Company warehouse
  behavior.
- Update `app/Modules/Ticket/Docs/knowledge/storage-cost-reservations.md` to describe Storage-backed
  costs versus manual costs.
- Update `docs/TODO.md` when slices are approved and completed.
- Sync updated Knowledge docs to BookStack after implementation.

## Open Questions

- Should manual ticket costs support editable currency in the first implementation, or should v1
  force `NOK` until a shared currency setting exists?

## Approval

Approved by Svein in conversation on 2026-06-03 with the instruction to implement it now.
