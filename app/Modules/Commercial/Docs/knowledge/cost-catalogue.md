Commercial costs define reusable internal cost entries that can be attached to services and used when calculating profitability.

## Purpose

Costs represent vendor or operational expenses such as software subscriptions, infrastructure costs, or other recurring inputs. They help service and contract calculations show expected margin instead of only revenue.

## Cost List Workflow

The costs index is an operational catalogue list.

The page keeps navigation in the compact page header and places `New Cost` in the cost list card header because the create action belongs to that list.

The search card supports:

- Free-text search across cost name, source, vendor name, recurrence, and note.
- Secondary filters behind the funnel button for vendor and recurrence.
- Sortable table headings for name, cost, recurrence, vendor, and updated time.

Rows are clickable and open the cost detail view. The cost name remains a direct link for accessibility.

## Integration-Managed Costs

Costs may be created and maintained by a source integration such as Cloud Factory. The Source and
Managed badges distinguish these rows from ordinary Nexum Costs.

An integration-managed Cost:

- Uses the canonical Nexum Vendor and Service unit.
- Stores the synchronized currency and amount per Nexum commercial interval.
- Is updated by the source integration's price synchronization.
- Cannot be edited, removed, or manually attached while its source Integration is active.

Cloud Factory retains the raw commitment-term price on its source offer. The managed Cost contains
the normalized amount used for comparable Service, package, quote, and contract profitability. For
example, an annual source total billed monthly is divided into a monthly Cost.

Each Cloud Factory commitment and billing variant has its own Nexum Service and its own managed Cost.
The Service SKU identifies the variant, and the integration prevents one Service from being shared by
two offers. Manual Nexum Costs remain linked to the selected Service and are still added to its total.

The generated Service and Cost both store the same generic source Integration ID and are visibly
linked to the active Integration settings. The source Integration owns synchronized identity and
price fields while it is active. The same rule is enforced by direct web requests, the Commercial
API, and the Service pricing component.

Disabling, revoking, or deleting the source Integration preserves the ordinary Cost, Service, their
Service-Cost relation, accepted contract snapshots, and accounting history. The retained rows show
**Released to Nexum** and become editable. New contract lines then use the retained ordinary Cost
without binding to an inactive provider offer. A later Integration can take ownership through a
controlled mapping workflow while Nexum keeps the existing commercial history.

## Operational Notes

- Cost values should be entered excluding VAT unless the finance workflow explicitly states otherwise.
- Recurrence should match the vendor billing rhythm as closely as possible.
- Vendors are shared master data owned by Documentation. Use `New vendor` on the Cost form when the needed vendor is not already available in the selector.
- Costs attached to services influence profitability calculations, so update them when vendor pricing changes.

## Commercial API parity on Dev - 25 October 2026

The supported workflow now has a typed Commercial API on Dev, using shared domain actions,
current user rights, token scopes, strict fields and revision/replay checks.
See [Commercial API](https://doc.tronderdata.no/link/457) for exact operation sequences,
customer/portal identity, composition, timebank and immutable document boundaries.
This is not production availability. The additive migration and authenticated native HTTP
checks now pass on Dev. API delivery and browser durable delivery remain off by default
until controlled worker verification and deliberate activation. Svein Tore accepted the
completed delivery on 25 October 2026; HR-2026-10-25-COMMERCIAL-API is Reviewed.
Main source promotion is verified: [PR #313](https://github.com/SveinT83/Nexum-PSA/pull/313), 25 October 2026.
Production deployment and controlled delivery activation remain separate actions.
