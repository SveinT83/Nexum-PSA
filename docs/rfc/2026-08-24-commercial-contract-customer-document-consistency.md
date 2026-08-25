# RFC: Commercial Contract Customer Document Consistency

Status: Implemented
Date: 2026-08-24
Owner: Codex

## Context

Commercial contract lines are snapshots, but customer surfaces currently render those rows directly.
The Tech preview, public acceptance page, Customer Portal, API, and PDF calculate or format money
independently. The current PDF also exposes per-line SLA and rate implementation details, uses English
labels, and only totals monthly lines.

The requested beta-completion work must make the customer document understandable while preserving
internal SLA and rate relationships. Accepted commercial state must remain independent of later Service,
SLA, rate, or company-catalogue changes.

## Goals

- Use one decimal-safe Commercial calculation for line amounts and contract cadence totals.
- Use the same customer-document projection in Tech preview, public view, Customer Portal, API,
  customer-document snapshot, and PDF.
- Snapshot a plain-text customer description on every new or edited draft contract line.
- Keep internal per-line SLA and rate links while removing them from the customer service table.
- Show only explicitly customer-visible rates once in a deduplicated section below the services.
- Present monthly, annual, one-time, and supported legacy quarterly totals separately.
- Render zero-value included lines as `Inkludert`.
- Make dates, statuses, labels, parties, approval details, attachments, and PDF page metadata Norwegian.
- Preserve already sent or approved contract economics and snapshots.
- Correct EDR through Service billing data, never through a name or product-code exception in a view.

## Non-Goals

- Production deployment or production data correction.
- Removing internal SLA, rate, SKU, cost, or integration fields.
- Replacing Sales quote presentation or moving Commercial ownership into Sales.
- Adding arbitrary recurrence schedules, proration, VAT calculation, invoice generation, or a rich-text
  contract editor.
- Guessing which production Service is EDR when Dev has no matching catalogue record.

## Current Behavior

- `ContractItem::line_total`, `Contracts::total_monthly_amount`, Livewire, SQL sorting, and views use
  duplicated float arithmetic.
- Customer views show Description, SLA, Rates, Qty, Unit Price, and Total, including internal fallback
  labels and SKU values.
- Service `short_description` exists, but contract lines do not snapshot it.
- Time rates have no explicit customer-visibility field.
- Only the monthly contract total is exposed consistently.
- `end_date` is used by active/expired queries and timebank eligibility as the actual agreement end.
  `binding_end_date` is a binding boundary inside that agreement.
- Dev contains no Service whose name or customer description identifies EDR, so no exact Dev catalogue
  row can be corrected without inventing identity.

## Proposed Change

Add a Commercial minor-unit calculator that parses decimal strings and performs all sale-price,
discount, setup-fee, and cadence aggregation with integers. It returns minor units and canonical
two-decimal strings; Norwegian formatting is a presentation operation on those exact values.

Add a Commercial customer-document projector that consumes contract snapshot rows, not mutable Service
defaults. It provides:

- the six approved customer table columns;
- plain-text description fallback from contract-line description to the snapshotted line name;
- customer scope formatting from quantity and the snapshotted unit;
- billing labels and separate cadence summaries;
- one deduplicated customer-visible rate collection;
- one common `Support og responstid` section;
- Norwegian document type, dates, status, parties, acceptance details, and numbered attachments.

Add `contract_items.customer_description` plus singular/plural unit snapshots,
`services.customer_unit_singular`, `services.customer_unit_plural`,
`time_rates.is_customer_visible`, `contract_item_time_rates.is_customer_visible`, and
`contracts.customer_document_snapshot`. `contract_items.price_currency` is a separate sale-currency
snapshot and never reuses `cost_currency`. Customer Contract prices currently support NOK only;
authoritative Service/offer currency is re-read on save and non-NOK input fails closed instead of
being labelled as kroner. Draft line creation copies the Service short description,
unit labels, billing cycle, rate visibility, and other existing defaults. Technicians can edit the
description, unit labels, and customer-rate visibility before sending.

Sending a quote or agreement, or manually approving an unsnapshotted contract, captures the complete
customer document and pricing projection. Sent/approved customer surfaces resolve that immutable JSON
snapshot. Editable drafts are projected live. Legacy records never read later catalogue text, billing,
rate visibility, prices, SLA, or terms.

Term review uses `approval_metadata.customer_document_terms` metadata version 2. The review binds both
the selected source term/version set (`source_fingerprint`) and the exact aggregate Contract wording
(`snapshot_fingerprint`), with per-field `source_snapshot_checksums` plus reviewer identity and time.
Manually changed wording is captured verbatim as a Contract-owned term snapshot labelled
`Versjon 1 (kontraktsspesifikk)`; it is never presented as a catalogue version whose text differs.

Opening the terms page is read-only: `GET` may preview generated text in empty fields but never saves
text or reviewer metadata. Explicit CSRF-protected `POST` refresh replaces generated snapshots and
records review; explicit `POST` save records manual wording. Line changes preserve existing manual SLA
wording and make the reviewed fingerprint stale until one of those explicit actions is completed.
Pre-metadata source removal is fail-closed rather than being interpreted as manual wording.

Every `sent_quote`, `sent_contract`, `approved`, or `won` legacy row without a complete customer JSON
snapshot is blocked from customer delivery, acceptance, capture, PDF, portal, public view, and detail
API projection. Current Company Profile, Client, line, rate, or CloudFactory values cannot prove the
parties and economics at send/accept time. Tech may show a clearly marked read-only reconstruction aid.
A named technician may freeze it only through the explicit attestation action after comparing every
party, amount, period, rate, support clause, and appendix with the original sent/accepted evidence. The
action binds a stable preview fingerprint and the original document type, then records technician,
time, note, source status, type, and snapshot SHA-256 in approval metadata. A changed reconstruction is
rejected until it is reloaded and reviewed. `approved` and `won` statuses do not prove whether the
original was an offer or agreement, so they show no reconstructed document before explicit type
selection. The action can never replace non-null evidence. API collections isolate a blocked row with
a null document/pricing and explicit readiness metadata; one legacy row does not abort the rest of the
page. A missing captured secure token remains a hard stop for public access and resend.
Each new send transition rotates its bearer token under the parent Contract lock. Resend keeps the
current token, while manual approval of an editable unsent Contract clears any dormant older token.

A null `customer_document_snapshot` means no captured JSON exists. Every non-null value is immutable
evidence: empty, scalar, malformed, or unsupported-schema values fail closed and are never replaced by
a live projection. The version-1 reader validates the complete envelope, not only `schema_version`:
document metadata, all four date entries, both legal parties, approval, the exact six columns, at
least one fully priced line, all four cadence totals, typed optional rate/support sections, and at
least one complete appendix must agree with the v1 contract. Exact allowed keys and list shapes prevent
unknown internal or future fields from leaking through a document that only resembles v1.

Invalid price or cadence input retained by Livewire remains a normal field-validation response. The
line preview shows `—` and suppresses invalid aggregate totals instead of persisting the value or
turning the preview render into an HTTP 500.

Rates are visible only when their explicit snapshot flag is true. Deduplication uses normalized name,
rate type, amount in minor units, currency, and unit. Similar names with different commercial values
remain separate. Empty rate sections are omitted. Historical rates with unknown visibility require
human classification before production; names, codes, and operational use are never inference rules.

The internal editor compares a draft line's billing interval with the current Service interval and
offers a narrow `Bruk fakturering fra tjenesten` action. This changes only the draft line interval.
The existing Service editor/API remains the authoritative way to change the Service definition itself.
No accepted line is synchronized.

Because `end_date` is the actual agreement end, validation requires
`binding_end_date <= end_date` when both dates exist. The same invariant lives in Contract domain
readiness, so pre-existing invalid drafts and direct capture/send paths cannot bypass HTTP validation.
The UI and API use Norwegian validation messages.

## Impact Analysis

- Module ownership: Commercial. System Company Profile and Client identity are read-only inputs.
- Data: eight additive nullable/default-safe columns and controlled draft-only/cache backfill.
- API: Commercial contract resources gain cadence totals and the shared customer-document projection;
  Time Rate resources/validation gain explicit customer visibility.
- UI: internal contract editor, Tech contract preview, public contract, and Customer Portal.
- PDF: Dompdf template/controller, fixed per-page reference/footer, page numbers, and attachment breaks.
- Permissions: existing Commercial management and customer/public access remain unchanged.
- Integrations: integration-managed Services continue to own their existing price/billing sources.
  A non-NOK CloudFactory sale-price update is blocked as a currency conflict. Won-Contract
  reconciliation runs in a transaction and locks the authoritative parent Contract before resolving
  and locking its Contract-owned line.
- Queue/scheduler: no change.

## Data And Migration Plan

- Add the columns without rewriting existing approved contract prices, billing, descriptions, rates, or
  legal snapshots.
- Default new rate visibility to false. Mark only platform-owned seeded public rate codes explicitly in
  the global catalogue; do not infer visibility from names. Historical unknown visibility must be
  classified by a human before production, without automatic accepted-history backfill.
- Copy catalogue descriptions and explicit rate visibility only into editable draft/negotiation lines.
  Non-editable legacy line descriptions fall back to their already snapshotted line name.
- Do not create or rename an EDR Service. Dev has no safely identifiable matching row. Before any
  production correction, an operator must identify the exact Service and authoritative cadence, then
  use the normal Service workflow and the narrow draft-line interval action.
- Production readiness requires authoritative supplier and customer legal names/organization numbers.
  Missing identity is a hard stop; it must never be guessed from display names or broad registry data.
- Run `2026_08_24_160000_add_contract_customer_document_snapshot_fields.php` before
  `2026_08_24_180000_add_contract_item_sale_currency_snapshot.php`. The latter preflights linked
  Service/offer sale currencies before DDL and stops if any are not NOK.
- The `160000` migration uses a bounded, idempotent draft-description backfill and its `down()` refuses
  to drop non-null customer snapshots, customer wording/unit data, or visible-rate classifications.
  The `180000` rollback refuses unsupported stored currencies. Export, verify, and deliberately clear
  protected data only under a separately approved rollback.

## Testing Plan

- Unit tests for decimal parsing, discounts, setup fees, included lines, and cadence totals.
- Feature tests for description fallback/snapshot/plain text, explicit rate visibility/deduplication,
  draft billing correction, immutable sent snapshots, Norwegian date validation, and existing records.
- Feature tests for legacy type selection, stable attestation fingerprints, stale-preview refusal,
  missing-token refusal, isolated API/portal rows, and parent-first CloudFactory locking.
- API/UI/PDF parity assertions using the exact EDR example total of `3 879,68 kr`.
- Customer-surface assertions that SLA/rate columns and internal labels are absent.
- Render a mixed-cadence PDF, extract text, render every page to PNG, and inspect wrapping/page breaks.

## Documentation Plan

Update Commercial Contract, Service Catalogue, Time Rates, SLA inheritance, and API Knowledge
documentation, `docs/TODO.md`, the two Feature Slices, and `docs/human-review.md`.
