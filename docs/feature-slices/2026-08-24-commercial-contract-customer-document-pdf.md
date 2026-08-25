# Feature Slice: Commercial Contract Customer Preview And PDF

Status: Done
Date: 2026-08-24
Parent: `docs/rfc/2026-08-24-commercial-contract-customer-document-consistency.md`
Owner: Codex

## Goal

Render the shared Contract customer-document projection consistently in the public preview, Customer
Portal, Tech customer preview, and PDF.

## User-Visible Behavior

- The service table has only Service, Short description, Scope, Unit price, Billing, and Total.
- Internal SLA/rate labels and SKUs are absent from customer tables.
- Customer-visible rates appear once under `Satser for arbeid utenfor avtalt omfang`.
- One common section is titled `Support og responstid`.
- All customer labels, statuses, dates, amounts, parties, approval text, and attachments are Norwegian.
- Ambiguous historical won/approved rows show no reconstruction until the document type is verified
  from the original; missing historical secure tokens do not create public or resend access.
- Every PDF page identifies contract and customer and says `Side X av Y`.
- Terms/attachments begin on a new page and are numbered with version and date.
- Manually reviewed wording is shown exactly as stored and labelled `Versjon 1 (kontraktsspesifikk)`;
  a mismatching catalogue version is never claimed.

## Scope

- Customer table/summary partials driven only by the shared projection.
- Tech preview, public acceptance view, and Customer Portal contract detail.
- Dompdf-specific A4 template, fixed page metadata, attachment page breaks, and safe text wrapping.
- Norwegian document type mapping for Tilbud, Avtaleutkast, and Avtale.
- PDF feature/content tests and one rendered mixed-cadence visual QA artifact.

## Out Of Scope

- Internal SLA/rate editor removal.
- External CDN assets or remote PDF resources.
- Electronic-signature provider integration.
- Production deployment or document regeneration.

## Data Touched

No new tables beyond the parent pricing slice. Views/controllers consume the shared projection and
captured snapshot.

## Permissions

Existing Tech, secure public-token, and Customer Portal access boundaries remain unchanged.

## Tests

- Exact six customer columns and absence of SLA/Rates/internal labels.
- Norwegian labels and document types.
- Rate section deduplication/omission through rendered surfaces.
- UI/API/PDF amount parity.
- Existing sent/approved contracts render only from supported immutable or readiness-verified evidence.
- Legacy null-snapshot contracts remain openable as a marked internal reconstruction aid and become
  customer-renderable only after named manual attestation against original evidence. The submitted
  type and stable preview fingerprint must still match the newly resolved reconstruction.
- Manual Contract wording keeps exact text and `Versjon 1 (kontraktsspesifikk)` attachment identity.
- Invalid non-null snapshots, including a `schema_version`-only partial v1 envelope, noncanonical
  columns/money triplets/dates/rates/appendices, and unattested historical null snapshots stop instead
  of rendering live data. New snapshots show `ikke versjonert`; stored English `Unversioned` fails
  closed.
- Unknown internal/future keys and associative object shapes for customer lists fail closed rather than
  being returned as customer-safe v1.
- PDF bytes, extracted text, page count/footer, attachment transition, Unicode, and long-text wrapping.
- PNG review of every generated sample page for clipping, overlap, and broken page breaks.

The final Dev/Dompdf artifact is 27 354 bytes with SHA-256
`977d273fedcae01ddcac946b933e04063c620a7acc6e8dfe30d03132c5f5a03f` and has two A4 pages.
Visual review found no clipping or overlap, and page 2 starts `Vedlegg 1`. Rendered evidence confirms
monthly `3 879,68 kr`, EDR `327,00 kr`, and one-time `750,00 kr`; identity and `Side X av Y`
appear in the footer on both pages, and forbidden labels are absent.
Final authoritative scoped Dev verification passes 99 tests / 1,455 assertions: `ContractFinalReviewRegressionTest` 44 / 868, `ContractCustomerDocumentTest` 6 / 104, `CommercialModuleTest` 33 / 313, `CustomerPortalQuoteContractAcceptanceTest` 2 / 60, two Contract-focused `CustomerPortalCommercialEconomyTest` methods 2 / 26, `ContractPricingTest` 8 / 31, and four CloudFactory Contract-boundary tests 4 / 53. Scoped Pint, Blade compilation, and `git diff --check` pass.

## Documentation

Update Contract, SLA, and PDF Knowledge plus TODO and `HR-2026-08-24-003`.

## Done Criteria

- Tech, public, portal, API, and PDF consume the same customer-document projection.
- Customer tables contain the exact six approved columns and omit internal SLA/rate/SKU fields.
- Norwegian totals, support, parties, approval, appendices, and per-page identity render consistently.
- Customer-visible rates appear once by explicit snapshot visibility and exact-value deduplication.
- Content tests, extracted PDF text, and every-page PNG inspection pass on Dev.
