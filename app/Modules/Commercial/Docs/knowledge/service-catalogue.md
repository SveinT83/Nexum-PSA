Commercial services are the reusable building blocks that packages and contracts use for pricing, SLA expectations, time rates, and legal terms.

## Purpose

The service catalogue keeps sellable and contractable work items in one place. A service can define SKU, price, billing cycle, availability, orderability, timebank settings, time rates, and attached legal terms.

The catalogue also owns the default customer description and singular/plural customer unit labels.
These fields make the Contract scope understandable without exposing an internal SKU or unit key.

## Service List Workflow

The services index is an operational catalogue list for quickly finding the right service to inspect, edit, package, or add to a contract.

The page keeps navigation in the compact page header and places `New Service` in the service list card header because the create action belongs to the list.

The search card supports:

- Free-text search across service name, SKU, short description, and status.
- Secondary filters behind the funnel button for status, billing cycle, audience, and orderable state.
- Sortable table headings for SKU, name, price, billing cycle, status, and updated time.

Rows are clickable and open the service detail view. The service name remains a direct link for accessibility.

## Contract Snapshot Defaults

Adding a Service to an editable Contract copies its commercial defaults into the Contract line,
including:

- name and price;
- billing cycle, discount/setup inputs, and sale currency separately from cost currency;
- plain-text customer description;
- singular and plural customer unit labels;
- SLA and time-rate defaults, including explicit customer-rate visibility.

The copied values belong to the Contract after that point. Editing the Service changes future
Contract lines only. Sent and accepted agreements must not read mutable catalogue values.

The customer Contract boundary currently supports NOK sale prices only. The editor re-reads the
authoritative Service or offer currency instead of trusting a browser field or stale model. A non-NOK
value is rejected before persistence and is never relabelled as kroner. Integration-owned
CloudFactory sale-price markup uses exact decimal arithmetic before its canonical two-decimal Contract
value is stored; cost/profit currency remains a separate internal concern.

If a draft Contract line has an outdated billing interval, the Contract editor can explicitly apply
the current Service interval. This narrow action changes only the editable line interval; it does not
rewrite negotiated prices or accepted history. Product-specific exceptions, including EDR, belong in
verified Service billing data and never in view-name or SKU checks.

## Operational Notes

- SKU and name should be short enough to scan in service lists and contract line pickers.
- Keep the customer description concise, plain-language, and free of internal implementation notes.
- Maintain both singular and plural customer unit labels where quantity can vary.
- Price and billing cycle should be reviewed before the service is used in a package or contract.
- Services should be archived or made inactive instead of deleted when they are already used in active agreements.
- Terms and time rates attached to a service are defaults for future contract snapshots; they do not rewrite existing accepted contract terms.
