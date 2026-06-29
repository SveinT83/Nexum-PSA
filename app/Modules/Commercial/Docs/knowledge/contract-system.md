The contract system controls how client agreements are assembled from services, prices, SLA rules, rates, and legal terms.

## Purpose

Contracts are the agreement boundary between Nexum and a client. They collect the commercial and operational terms that apply to a client relationship, including selected services, negotiated prices, SLA expectations, DPA/legal content, and service-specific terms.

The contract must not depend directly on mutable service defaults after it has been prepared or sent. Service data is used as a template, then copied into contract snapshots where the agreement can be reviewed and adjusted.

## Core Flow

1. A technician creates a contract for a client.
2. The contract receives a default SLA.
3. Services are added as contract lines.
4. Each contract line copies service pricing, rates, and SLA defaults.
5. Legal terms, DPA content, SLA text, and general clauses are collected from the selected services.
6. The generated snapshots can be reviewed and edited before the contract is sent.
7. The public contract view exposes the agreed services, SLA, rates, and legal content for acceptance.
8. Technicians can download a PDF artifact from the contract show page once the contract is ready, sent, or won.

## Contract List Workflow

The contracts index is an operational list for finding the next agreement to review or update.

The page keeps navigation in the compact page header and places list actions next to the table itself. `New Contract` belongs in the contract list card header so the create action stays attached to the list it affects.

The search card supports:

- Free-text search across contract id, client name, status, and description.
- Secondary filters behind the funnel button for status, client, and contract period.
- Sortable table headings for id, client, status, start date, end date, monthly price, and yearly profit.

Rows are clickable and open the contract detail view. The client name remains a direct link to the client record.

## Contract PDF

The contract show action card exposes `Download PDF` through `tech.contracts.pdf`. The export renders a PDF-specific Blade view with internal CSS through Dompdf, so it does not depend on external CDN assets. The PDF includes contract metadata, client identity, service lines, negotiated time rates, SLA snapshots, terms, DPA/legal snapshots, and acceptance details for won contracts.

PDF export is available when the contract is ready for sending, or when it has already been sent as a quote, sent as a binding contract, or won. Incomplete drafts keep the PDF button disabled.

## Service Snapshots

When a service is added to a contract, the contract line stores the negotiated service state instead of relying on the live service catalog.

Contract lines may include:

- Service name and SKU.
- Unit price, quantity, billing interval, discount, and setup fee.
- Time rates copied from the service rate defaults.
- SLA selection copied from the service default or inherited from the contract default.
- SLA snapshot when the line uses a specific SLA.

This protects existing contracts from later changes to services, rates, or SLA templates.

## SLA Rules

Contracts have a default SLA. Services may also define a default SLA.

When a service is added to a contract:

- If the service has a default SLA, the contract line uses that SLA.
- If the service has no SLA, the contract line uses the contract default SLA.
- The contract line can be manually changed before the contract is sent.

Ticket SLA resolution should later prefer the contract line SLA first, then the contract default SLA, then client/system defaults.

## Time Rates

Time rates are managed in the Sales workspace and can be attached to services. When a service is added to a contract, selected service rates are copied into the contract line.

The copied rates can be edited or disabled for that contract line. Ticket cost and timebank logic should later use these active contract line rates before falling back to global non-contract rates.

## Contract Timebanks

Services can define included contract time with `timebank_enabled`, `timebank_minutes`, and
`timebank_interval`. When that service is attached to a won or approved contract, the Client profile
Contracts tab shows the current timebank period, included time, used time, remaining time, and any
overuse for the contract line.

The balance is conservative. It includes settled Ticket time allocations, pending Ticket time entries
for the same contract line and period, and quick Client timebank registrations.

Quick Client timebank registration is available from the Client `Time` tab for small no-ticket/no-task
help, such as counter or phone support where creating a Ticket would add unnecessary overhead. Each
quick entry is stored in `client_contract_time_consumptions` with client, contract, contract line,
technician, work date, minutes, selected time rate snapshot, note, period snapshots, and overuse
snapshot. This keeps the entry auditable without creating fake Tickets.

Quick registration is controlled by Commercial policy settings stored in `common_settings` under
`commercial/client_timebank_quick_policy`. The current defaults allow quick registration up to 120
minutes while included time remains, require a note, and block direct overuse. Overuse registration
requires both the setting and the `commercial.timebank.overconsume` permission.

When Economy `Generate orders` runs, quick entries that created overuse become draft order lines with
line type `quick_timebank_overuse`. Only the overused minutes caused by that quick entry are billed,
using the rate snapshot selected when the entry was registered.

Quick entries can be corrected from the Client `Time` tab until they are included on an Economy order
line. The correction can update work date, minutes, note, and selected time rate snapshot. Once
ordered, the Economy line must be handled first so billing history stays consistent, and ordered
entries are hidden from the Client time usage list.

## Terms And Legal Snapshots

Services can have attached legal terms. These terms are grouped by type:

- General terms.
- DPA.
- Legal/GDPR.
- SLA.
- General.

When services are added to a contract, empty contract snapshot fields are generated automatically from the service terms. Existing manually edited snapshots are not overwritten automatically.

Use `Refresh from Services` on the contract terms page to regenerate snapshots from the current service terms.

Term names are included as headings above the term body so merged contract text remains readable.

## ISO 27001 Direction

The contract system should strive to support contract wording and operational commitments aligned with ISO 27001 information security principles.

This does not mean generated contracts are automatically ISO 27001 compliant. The system provides structure, snapshots, traceability, and room for standardized clauses. The actual legal and operational text must still be reviewed and maintained as a controlled term library.

Future ISO-oriented contract templates should address:

- Information security responsibilities for Nexum and the client.
- Confidentiality and acceptable use.
- Access control and authorized users.
- Logging, auditability, and evidence handling.
- Incident reporting and response expectations.
- Change management and maintenance windows.
- Backup, restore, and continuity expectations.
- Third-party dependencies and customer-owned suppliers.
- GDPR/DPA obligations and data processing limits.
- Secure deletion, return of data, and offboarding.
- SLA limitations where Nexum depends on third parties.

The goal is that contracts generated by Nexum consistently expose these obligations and make service-specific exceptions explicit.

## Operational Notes

- Existing contracts keep their snapshots unless explicitly refreshed.
- Sent contracts should be treated as locked agreement artifacts.
- Contract terms should be reviewed before sending quotes or binding contracts.
- Service terms should be maintained as reusable standard clauses rather than copied manually into every contract.
- Quick Client timebank entries are auditable consumption records. Economy order generation includes
  quick overuse as draft order lines, but final approval/export stays in the normal Economy workflow.
