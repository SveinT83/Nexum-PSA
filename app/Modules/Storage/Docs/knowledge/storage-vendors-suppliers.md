Storage uses the Documentation-owned vendor/supplier register as master data.

Why this matters:

- Assets need a vendor/manufacturer for hardware ownership and lifecycle context.
- Storage needs a manufacturer and a supplier for catalogue and reorder work.
- Commercial costs may reference vendors.
- Future purchase orders and receiving should use the same partner records.

The shared table is `vendors`, exposed by `App\Modules\Documentation\Models\Vendor`.

Roles:

- **Vendor** means a general partner/vendor record.
- **Manufacturer** means the company that makes the product.
- **Supplier** means where we buy the product.

A record can have more than one role. For example, Dell can be both manufacturer and supplier. Dustin may be supplier only.

Storage item behavior:

- The item form lists active manufacturer records in `Vendor / Manufacturer`.
- The item form lists active supplier records in `Supplier`.
- `New vendor` opens the Documentation vendor create form in a new tab.
- `New supplier` opens the Documentation supplier create form in a new tab.
- Storage does not create vendor/supplier records inline.
- Selecting an existing record can mark it as manufacturer or supplier when needed.

Supplier line behavior:

- Each item can have a primary supplier line.
- The primary supplier line stores supplier SKU, purchase URL, currency, lead time, MOQ, pack size, and unit cost.
- Unit cost is copied from the item `Purchase Price` to avoid entering the same cost twice.

Documentation ownership:

- `/tech/documentations?cat=vendors` opens the fixed vendor register.
- `/tech/documentations?cat=suppliers` opens the fixed supplier register.
- These categories are fixed master-data views, not dynamic documentation templates.

## Full Storage API coverage (Dev, 2026-10-26)

The Storage API guide documents the complete supported browser/API workflow,
required token scopes and current user permissions, supplier lookup, inventory and
incoming quantities, purchasing/receiving, picking, import review, profiles and
settings. This update is verified on Dev; Main, production and named human review
are separate gates. See **Storage Inventory -> Storage API guide** in the handbook and Swagger on the
target installation. The separate Issue #286 numbering contract remains open.

## Documentation master-data API (Dev, 2026-10-27)

Documentation now exposes the canonical register at `/api/v1/documentation/vendors`
and the supplier collection at `/api/v1/documentation/suppliers`. Use GET for search
and detail, POST for creation, and PATCH `/vendors/{vendor}` for maintenance and
deactivation. Role filters also support manufacturers. Storage lookup uses the same IDs.

Required scopes are documentation.vendors.read/create/update with current
documentation.view/create/update rights. Writes require a stable Idempotency-Key;
read the exact record first and use its ETag in If-Match for changes. No purchase,
stock or supplier-import provenance changes are caused by maintaining this register.
See **Documentation Overview -> Documentation API guide** for the end-to-end steps.
Main/production are unchanged. HR-2026-10-27-DOCUMENTATION-API was Reviewed
by Svein Tore on 2026-10-27; promotion/deployment requires separate authorization.
