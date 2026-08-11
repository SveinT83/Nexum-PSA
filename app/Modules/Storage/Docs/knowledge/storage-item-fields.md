Storage item forms are split into General, Vendor & Supplier, and Stock & Pricing. The goal is that a technician or apprentice can register an item without needing to understand purchasing jargon first.

General fields:

- **SKU** is our internal item number. It is stored uppercase and should stay stable because tickets, reservations, and future purchase orders can refer to it.
- **Name** is the product name technicians search for.
- **Warehouse** is required and tells Storage where the item is kept.
- **Box** is optional and groups the item inside a warehouse box.
- **EAN / Barcode** is used for product identification and later scan workflows.
- **Status** decides whether the item is active.
- **Short Description** is the operational customer-facing description. Ticket cost lines can use it as invoice text.
- **Long Description** is internal catalogue detail.

Vendor & Supplier fields:

- **Vendor / Manufacturer** is who makes the product, for example HP, Lenovo, Dell, Ubiquiti, or Microsoft.
- **New vendor** opens the Documentation-owned vendor form in a new tab. Create the vendor there, then return to Storage and select it.
- **Manufacturer Part No.** is the manufacturer model or part number.
- **Supplier** is where we buy the item, for example Dustin, Komplett, ALSO, or a local distributor.
- **New supplier** opens the Documentation-owned supplier form in a new tab.
- **Supplier SKU** is the supplier's item number. It can differ from our SKU and from the manufacturer part number.
- **Purchase URL** is the direct supplier product/order page.
- **Currency** is the purchase currency. Default is `NOK`.
- **Lead Time** is expected delivery time in days from ordering until the item normally arrives.
- **Supplier MOQ** means supplier minimum order quantity. If the supplier only sells packs of 5, set this to `5`.
- **Pack Size** is how many units arrive in one supplier pack, bundle, or box.

Stock & Pricing fields:

- **Initial Quantity** is only used when creating an item and creates the first audited stock
  movement. A serial-, batch-, or expiry-controlled item must start at zero and enter inventory
  through an identified receiving flow.
- **Reorder Point** is the quantity where the item should be considered for reorder.
- **Target Level** is the desired quantity after reorder.
- **MOQ** is our internal minimum order quantity for reorder suggestions. If supplier MOQ is enough, keep the same value.
- **Purchase Price** is the normal cost price excluding VAT. This value is also copied to the primary supplier line as unit cost so supplier cost is not entered twice.
- **Markup %** is the percentage used when reviewing or calculating sale price.
- **Sale Price** is the price charged onward before VAT.
- **VAT Rate** defaults from Admin > Economy settings. A specific item can override the default.
- **Require serials on withdrawal/sale** means technicians must record serial numbers when stock is consumed.
- **Track batch** means accepted receipt quantity must be assigned to one or more named batches.
- **Track expiry** means applicable accepted units must carry an expiry date during receiving.
- **Manual should-order flag** forces the item into reorder attention even when quantity rules have not triggered.

Tracking controls protect the stock-unit ledger. Nexum blocks changing serial, batch, or expiry
flags while the item has on-hand quantity, reserved quantity, an active reservation, or a positive
stock-unit quantity. Receive or consume the identified units through their operational workflows
before changing the tracking policy.

Generic manual stock adjustment is unavailable for a tracked item or an item that still has a
positive stock-unit ledger. A generic delta cannot say which serial, batch, or expiry unit changed.
Use purchase receiving for inbound identified units and the dedicated identified-unit correction
workflow when available.

Master data rule:

Vendors and suppliers are not created inline inside Storage item forms. They are created in Documentation because Assets, Storage, Commercial, and future purchase ordering all use the same `vendors` table.
