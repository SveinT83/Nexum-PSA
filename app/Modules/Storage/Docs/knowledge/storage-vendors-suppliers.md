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
