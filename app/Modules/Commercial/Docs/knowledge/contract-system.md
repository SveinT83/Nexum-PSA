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
7. The shared customer-document projection exposes agreed services, customer-visible rates, support
   expectations, and legal content for acceptance without exposing internal implementation fields.
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

The contract show action card exposes `Download PDF` through `tech.contracts.pdf`. The export renders
the same customer-document projection as the web surfaces in a PDF-specific Blade view with internal
CSS through Dompdf, so it does not depend on external CDN assets.

PDF export is available when the contract is ready for sending, or when it has already been sent as a quote, sent as a binding contract, or won. Incomplete drafts keep the PDF button disabled.

The PDF uses Norwegian document types, statuses, dates, party labels, approval text, and attachment
metadata. Its service table contains only `Tjeneste`, `Kort beskrivelse`, `Omfang`,
`Enhetspris`, `Fakturering`, and `Sum`. Internal SKUs, per-line SLA labels, and operational rate
links do not belong in that table. Customer-visible rates are collected once below the services, and
support expectations appear once under `Support og responstid`.

Every PDF page identifies the contract and customer and includes `Side X av Y`. Numbered,
versioned, and dated terms or attachments begin on a new page so the main commercial summary and
legal appendices remain easy to review.

## Customer Portal

The Customer Portal can show customer-safe contract summaries for a Client. Contracts are visible in
the portal when their `approval_status` is `sent_quote`, `sent_contract`, `approved`, or `won`.

Portal contract pages show:

- Contract status and period.
- The same six-column customer service presentation as the public view and PDF.
- Separate monthly, annual, one-time, and supported legacy quarterly totals.
- Zero-value components as `Inkludert`.
- One deduplicated section for explicitly customer-visible rates when any exist.
- One common `Support og responstid` section.
- Customer-facing terms and numbered legal snapshots when they exist.

Portal users can accept `sent_quote` and `sent_contract` contracts. Acceptance marks the contract as
`won`, stores the existing accepted name/IP/user-agent fields, and binds the contract to the
authenticated portal account, membership, and Contact through:

- `portal_accepted_account_id`
- `portal_accepted_membership_id`
- `portal_accepted_contact_id`

The portal acceptance also writes a CustomerPortal audit event.

Portal contract pages do not show internal margin/profit calculations, technician workflow details,
draft/negotiation/lost contracts, other-client contracts, or internal Commercial settings. Commercial
contracts are currently client-level records, so site-scoped portal memberships do not see contract
summaries until Commercial owns a site-level contract split.

## Service Snapshots

When a service is added to a contract, the contract line stores the negotiated service state instead of relying on the live service catalog.

Contract lines may include:

- Service name and SKU.
- Plain-text customer description and customer unit labels.
- Unit price, quantity, billing interval, discount, setup fee, and currency.
- Time rates copied from the service rate defaults.
- SLA selection copied from the service default or inherited from the contract default.
- SLA snapshot when the line uses a specific SLA.

This protects existing contracts from later changes to services, rates, or SLA templates.

## Customer Document And Pricing Snapshot

`ContractPricing` is the only Commercial calculation boundary for customer line totals and cadence
summaries. It uses Brick Math decimal values, explicit half-up rounding, and integer minor-unit output
instead of binary floating-point arithmetic. The same result drives the editor, web views, API,
captured customer document, and PDF. Sale currency is snapshotted separately from cost currency. The
customer Contract boundary is NOK-only and re-reads authoritative Service/offer currency on save.
CloudFactory sale-price/markup projection that can reach a Contract also uses Brick decimal arithmetic
and returns a canonical two-decimal string. Reconciliation of a won Contract locks the authoritative
parent Contract before it re-resolves and locks a Contract-owned line, so an integration update cannot
race an approval or mutate a line resolved outside the accepted Contract boundary.

Recurring and non-recurring commitments are not mixed into one ambiguous amount:

- Monthly lines contribute to the monthly total.
- Annual lines contribute to the annual total.
- One-time lines and setup fees contribute to the one-time total.
- Existing quarterly lines remain a separate supported legacy total.
- A line with no customer charge is presented as `Inkludert`.

Editable drafts are projected from their current Contract-owned rows. When a draft quote or agreement
is sent or manually approved, Nexum captures
`contracts.customer_document_snapshot`. Sent and approved customer surfaces prefer that immutable
snapshot. Null means no JSON evidence. Every non-null value is treated as immutable evidence; an
empty, scalar, malformed, or unsupported-schema value fails closed and is not replaced with a live
projection.

The v1 reader validates the entire envelope: document metadata, four date entries, supplier/customer
identity, approval, the exact ordered Norwegian six-column definition, at least one line with typed
amount structures, all four cadence totals, typed optional rates/support, and at least one complete
appendix. Minor, decimal, and display representations must describe the same value and the
`Inkludert` flag/display must match a zero line and zero setup fee. A `schema_version` marker alone is
not a valid v1 document. Dates must use the Norwegian document formats, rate identities must remain
deduplicated, appendix numbers must be sequential, and the English legacy marker `Unversioned` is not
accepted as a stored v1 appendix version. Every object has an exact allowed key set, and customer lines,
rates, and appendices must remain JSON lists; an unknown internal or future field is not silently
returned as customer-safe v1. Integer and boolean evidence fields must also retain their real JSON
types rather than numeric strings. Invalid evidence is rejected instead of normalized.

Livewire can retain a rejected public value while showing validation. An unknown cadence or negative
price therefore shows the normal field error, `—` in the line preview, and no invalid aggregate
panel; it does not save the value or turn preview rendering into a 500 response.

Every legacy `sent_quote`, `sent_contract`, `approved`, or `won` row without complete customer JSON is
blocked from customer delivery, acceptance, PDF, portal, public view, capture, and detail API. Tech may
open a clearly marked read-only reconstruction aid; current live values are not historical proof. A
named technician may use the explicit attestation form only after comparing every field with original
sent/accepted evidence. The action freezes a complete v1 snapshot once and records actor, timestamp,
control note, source status, original document type, and SHA-256 in approval metadata. The GET preview
uses a stable evidence fingerprint that the POST action recalculates; any intervening source change
forces a full reload and new review. Contract lines and the deduplicated rate collection have a
deterministic order so database relation order cannot create a false stale result. `sent_quote` and
`sent_contract` supply their known type, while an
ambiguous `approved` or `won` row shows no reconstructed document until the technician selects the
type proven by the original. Non-null evidence is never replaced. In the paginated admin API list, the
blocked row has null customer document/pricing plus explicit readiness metadata, so it does not abort
otherwise valid rows. A historical row without its captured secure token does not silently receive a
new public link or resend path. Resend also validates the customer's billing email inside the locked
boundary before it changes a CC address; a missing/invalid recipient returns an error without a
provider call or false success. Every new quote/agreement send rotates the bearer token under the same
Contract lock, so a link from an older draft or customer cannot open the new snapshot. Resend preserves
the current sent token. Manual approval of an editable, unsent Contract clears any dormant token;
approval of an already sent document preserves its active link.

Descriptions and customer unit labels are copied from the Service into a Contract line while the
contract is editable. The scope column selects the snapshotted singular or plural label from the
quantity. Missing descriptions on legacy rows fall back to the snapshotted Contract line name, never
to the current Service description.

`end_date` is the operational agreement end. `binding_end_date` is a boundary inside that
agreement, so both the Tech UI and Commercial API require the binding date to be on or before the
agreement end when both are present. Domain capture/send readiness enforces the same rule for
pre-existing or non-HTTP paths.

## SLA Rules

Contracts have a default SLA. Services may also define a default SLA.

When a service is added to a contract:

- If the service has a default SLA, the contract line uses that SLA.
- If the service has no SLA, the contract line uses the contract default SLA.
- The contract line can be manually changed before the contract is sent.

Ticket SLA resolution should later prefer the contract line SLA first, then the contract default SLA, then client/system defaults.

## Time Rates

Time rates are managed in the Sales workspace and can be attached to services. When a service is added to a contract, selected service rates are copied into the contract line.

The copied rates can be edited or disabled for that contract line. Customer visibility is a separate,
explicit flag copied into the Contract rate snapshot. Customer documents show only explicitly visible
rates and deduplicate exact equivalent values by normalized name, type, amount, currency, and unit.
Names are never used as an implicit visibility filter.

Ticket cost and timebank logic use active operational Contract line rates before falling back to
global non-contract rates. Customer visibility does not change operational rate eligibility.

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

When services are added to a contract, empty contract snapshot fields are previewed from the service
terms. Existing manually edited snapshots are not overwritten automatically.

Opening the terms page is read-only. `GET` may preview generated values in empty fields, but never
persists text or review metadata. Use the CSRF-protected `Refresh from Services` POST action to replace
generated snapshots, or the normal POST save action to persist reviewed/manual wording. Removing a
pre-metadata source never makes prior wording implicitly manual or current.

Term names are included as headings above the term body so merged contract text remains readable.

Customer documents present SLA/support material once under `Support og responstid`. Numbered legal
terms and other attachments are rendered separately with their stored version and date. Internal
per-line SLA relationships remain available to technicians and ticket resolution.

Saving reviewed terms records `approval_metadata.customer_document_terms` metadata version 2. Its
`source_fingerprint` identifies the exact source term/version set, its `snapshot_fingerprint`
identifies the exact full Contract text, and `source_snapshot_checksums` records the generated
per-field source text alongside reviewer identity and time. Both source and exact text must still
match at capture.

If a technician changes generated wording, Nexum creates a Contract-owned immutable term snapshot
with the exact text, `origin=contract`, and version `Versjon 1 (kontraktsspesifikk)`. It does not attach
a catalogue version label to text that differs from that catalogue version.

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

- Existing contracts keep their snapshots unless an editable draft is explicitly refreshed.
- Sent contracts should be treated as locked agreement artifacts.
- Customer-document snapshots are evidence and must not be regenerated from current catalogues.
- Unsupported non-null customer snapshots require investigation; deleting or replacing them to make a
  page render is not an approved recovery.
- Contract terms should be reviewed before sending quotes or binding contracts. GET is preview-only;
  persistence requires explicit POST. A removed or changed source is a hard stop, not permission to
  refresh a historical Contract from current Services.
- Supplier and customer legal names and organization numbers are required before capture. Missing
  identity must be supplied from authoritative human-reviewed data, never guessed.
- Historical rate visibility must be classified explicitly before production. Unknown does not mean
  public, and names/codes do not decide visibility.
- Service terms should be maintained as reusable standard clauses rather than copied manually into every contract.
- A draft billing mismatch must be corrected through the explicit draft-only Service interval action;
  accepted lines are never synchronized.
- The first customer-document migration backfills editable descriptions in bounded, idempotent chunks
  and refuses `down()` while customer snapshots, line wording/units, Service customer units, or visible
  rate classifications remain. The later sale-currency migration preflights source/offer currencies
  before DDL and refuses rollback of unsupported stored currencies. Export and verify protected data
  before a separately approved rollback.
- Quick Client timebank entries are auditable consumption records. Economy order generation includes
  quick overuse as draft order lines, but final approval/export stays in the normal Economy workflow.
