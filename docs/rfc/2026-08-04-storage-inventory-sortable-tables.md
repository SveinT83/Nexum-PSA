# RFC: Storage Inventory Sortable Tables

Status: Implemented
Date: 2026-08-04
Owner: Svein / Codex

## Context

Storage now has a complete supplier-order, shipment, receiving, picking, and inventory workflow.
The Purchase Orders queue gained safe server-side sorting as a follow-up, but the remaining Inventory
lists and read-only history tables still use fixed ordering and plain table headings. Users must scan
or filter manually even when a visible column is the natural way to organize the current work.

This is a Level 2 change because it adds consistent user-visible table behavior across several
Storage screens. It remains inside the Storage module and does not change inventory quantities,
purchase lifecycles, permissions, APIs, integrations, or database structure.

## Goals

- Make every meaningful read-only Inventory list and history-table heading sortable.
- Use one compact, accessible visual pattern with an active ascending or descending indicator.
- Preserve active search, filters, and pagination when a user changes sorting.
- Preserve each page's existing default order until the user selects a sortable heading.
- Use strict server-side allowlists and normalized directions for paginated database queries.
- Keep missing dates and missing optional names at the end in both directions.
- Keep aggregate and calculated sorting aligned with the values shown in the UI.
- Use stable tie-breakers so pagination cannot shuffle equal values between requests.
- Give separate tables on one detail page independent sort parameters.

## Non-Goals

- Sorting interactive form-line tables where row order, indexed inputs, or control-slip order is part
  of the workflow.
- Sorting the printable receiving control slip.
- Adding client-side table libraries, drag-and-drop ordering, saved views, or user preferences.
- Adding database columns, indexes, migrations, API fields, permissions, or new routes.
- Rolling the shared behavior out to non-Storage modules in this change.
- Changing the business meaning of stock, reservation, receipt, shipment, or purchase-order values.

## Current Behavior

- Purchase Orders supports allowlisted sorting on its eight visible data columns.
- Inventory Items defaults to most recently updated.
- Picking defaults to ready stock first, then SKU and reservation age.
- Receiving defaults to expected date with missing dates last.
- Warehouse settings defaults to warehouse name.
- Item movements, box contents/events, order lines, shipment allocations, and receipt history use
  fixed relation or collection order.
- Most headings are plain text and do not expose sorting state to assistive technology.

## Proposed Change

### Shared Header Pattern

Add one reusable Blade table-header component that renders:

- A real link for keyboard and assistive-technology access.
- `scope="col"` on every sortable heading.
- `aria-sort` only on the active heading.
- A compact neutral icon for an inactive heading and an explicit direction icon for the active one.
- An action label that describes the direction the next click will apply.
- Optional right alignment for numeric columns and an optional fragment for detail-table return.

The component receives an already allowlisted current state and a sanitized query array. It does not
decide which database columns are safe.

### Paginated Operational Queues

Add allowlisted SQL sorting to:

- Inventory Items: item/SKU, warehouse, supplier, box, on-hand, reserved, available, and reorder
  status. The action column is not sortable.
- Picking List: item, Ticket, client, location, reserved, on-hand, and operational pick status. The
  action column is not sortable.
- Purchase Orders: retain and refactor the existing eight-column sorting through the shared header.
- Receiving: order, supplier, destination, expected date, shipments, received, and outstanding. The
  action column is not sortable.

Every query validates the sort key and direction, preserves its existing default order for missing
or invalid sort input, keeps optional values last, and adds deterministic secondary ordering.

### Admin And Detail Tables

Add independently scoped sorting to these already-loaded, read-only tables:

- Inventory Settings warehouse list.
- Item Movement History.
- Box Contents and Box Events.
- Purchase Order Lines.
- Shipment Allocation Lines.
- Receipt History.

A small Storage-owned collection sorter normalizes allowed columns, direction, null placement,
numeric/date/string comparison, and stable tie-breakers. It is used only for already-loaded detail
collections; paginated queues continue to sort in SQL.

### Excluded Tables

Purchase-order edit lines, shipment-registration allocation/tracking inputs, goods-receiving inputs,
and the printed control slip remain in their intentional workflow order and do not show misleading
sorting controls.

## Impact Analysis

- **Storage:** query classes, Tech/Admin controllers, read-only views, one Storage support utility,
  and Storage feature/unit tests.
- **Shared UI:** one reusable Blade table-header component under the existing global component
  system. No existing caller changes outside Storage.
- **Permissions:** unchanged; current route permissions continue to protect every page.
- **Routes/API:** no new route or API contract.
- **Data:** read-only ordering only; no writes or schema changes.
- **Integrations/queues/scheduler:** no impact.
- **UI:** compact linked headers and full-page GET navigation using existing responsive tables.
- **Documentation:** Storage Knowledge, TODO, Feature Slices, human review, and public-safe website
  handoff.

## Data And Migration Plan

No migration, seed, backfill, queue restart, scheduler change, or frontend build is required.
Deployment requires the code, `php artisan optimize:clear`, and a Storage Knowledge sync.

## Testing Plan

- Unit-test collection sorting for strings, numbers, dates, missing values, direction normalization,
  allowlist fallback, and stable ties.
- Feature-test every sortable operational-queue column in both directions.
- Verify search, filter, sort, and pagination parameters remain together and page resets on a new
  sort.
- Feature-test each Admin/detail table independently so one table's sort parameters do not reorder
  another table.
- Verify aggregate/reorder/pick-status values sort exactly as displayed.
- Verify action and interactive form columns remain non-sortable.
- Compile Blade, run PHP/Pint/diff checks, execute all sort expressions against the configured Dev
  database, run the complete Storage test set, and run the full Laravel suite when practical.
- Keep named desktop/mobile/keyboard human review open until explicitly completed.

## Documentation Plan

- Add sorting guidance to Storage inventory, picking, and purchase/receiving Knowledge articles.
- Record the two implementation slices and update `docs/TODO.md`.
- Create a new `docs/human-review.md` entry covering all Inventory list/detail tables.
- Add a public-safe, do-not-publish website handoff until human review is complete.

## Open Questions

None. Existing default order remains authoritative, and only read-only data tables are sortable.

## Implementation Result

Implemented on Dev on 2026-08-04 across all eleven approved read-only table surfaces. The four
paginated queues use explicit SQL allowlists, and the seven Admin/detail surfaces use the
Storage-owned typed collection sorter. Existing default orders, permissions, filters, and workflow
table order remain unchanged.

Verification completed with 95 Storage tests and 1,233 assertions, including all 60 operational
queue column/direction expressions against the configured Dev MySQL database. The complete Laravel
suite passes with 1,027 tests and 8,558 assertions. PHP syntax, targeted Pint, Blade compilation,
and diff/whitespace checks pass. Manual desktop, mobile, keyboard, and data-order verification
remains tracked as `HR-2026-08-04-002` with status `Pending`.

## Approval

Svein Tore approved the complete Inventory sorting rollout in the Codex conversation on 2026-08-04.
