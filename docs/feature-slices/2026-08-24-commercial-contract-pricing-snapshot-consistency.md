# Feature Slice: Commercial Contract Pricing And Snapshot Consistency

Status: Done
Date: 2026-08-24
Parent: `docs/rfc/2026-08-24-commercial-contract-customer-document-consistency.md`
Owner: Codex

## Goal

Create the one decimal-safe Contract pricing and customer-snapshot boundary used by every surface.

## User-Visible Behavior

- Monthly, annual, one-time, and legacy quarterly amounts are separate and exact.
- Zero-price contract components read as included.
- Three endpoint-security units at 109 kroner contribute 327 kroner to the monthly total.
- Sent and approved customer documents do not change after catalogue edits.
- A draft whose interval differs from its Service can adopt the verified Service interval without
  touching negotiated price or any approved line.

## Scope

- Add the additive Contract/rate visibility/description/document-snapshot fields.
- Implement minor-unit line and cadence calculation.
- Implement plain-text description snapshot/fallback and customer scope formatting.
- Implement explicit customer-rate visibility and value-aware deduplication.
- Implement immutable customer-document capture/resolution with non-null invalid-schema fail-closed.
- Validate the complete typed v1 envelope, exact ordered Norwegian column map, and semantically
  consistent minor/decimal/display money triplets; a schema marker alone is never trusted evidence.
- Enforce Norwegian date formats, exact rate-identity deduplication, sequential appendices, and a
  Norwegian stored version label instead of accepting the legacy English `Unversioned` marker.
- Reject unknown nested/top-level v1 fields and associative object shapes where customer lines, rates,
  and appendices must be JSON lists.
- Require real JSON integer/boolean primitives for schema version, minor units, included/approval flags,
  and appendix numbering instead of accepting numeric strings.
- Keep invalid Livewire price/cadence input as validation state without a preview rendering exception.
- Snapshot sale currency separately from cost currency and enforce the current NOK-only Contract boundary
  from authoritative Service/offer data.
- Bind legal review metadata version 2 to both source term versions and exact Contract text.
- Capture manual wording verbatim as `Versjon 1 (kontraktsspesifikk)`.
- Keep terms `GET` preview-only and require explicit POST refresh/save for persisted review.
- Isolate legacy missing-snapshot rows in API collections and require named, hash-audited manual
  attestation before any reconstructed historical document becomes customer evidence. The attestation
  binds a stable preview fingerprint and original document type; ambiguous won/approved rows show no
  reconstruction until that type is selected from original evidence.
- Use the result in Contract model accessors, internal editor, send/manual-approval actions, and API.
- Add the end/binding date rule, legal-party readiness, and Norwegian messages.
- Use bounded/idempotent migration backfill and guard rollback for every protected document/rate/unit/
  description/currency field.

## Out Of Scope

- Customer-facing Blade/PDF layout.
- Production EDR mutation or guessed Service identity.
- Automatic repair of legacy legal text, party identity, or historical rate visibility.
- Arbitrary billing schedules, VAT/invoicing, or Sales quote changes.

## Data Touched

- `contracts`
- `contract_items`
- `services`
- `time_rates`
- `contract_item_time_rates`
- New Commercial support/actions/tests and two additive migrations.

## Permissions

Existing Commercial route and API abilities remain unchanged. Only editable Contract states may alter
line descriptions, interval, rate visibility, reviewed wording, or captured customer evidence.

## Production Readiness Gates

- Resolve supplier legal name and organization number from authoritative Company Profile data.
- Resolve missing customer legal identity on legacy records from an authoritative human-approved source.
- Classify historical rate visibility explicitly; never infer it from name, code, or operational use.
- Identify the actual EDR Service and cadence before any correction. No safe EDR row was found on Dev.
- Keep legacy reconstruction read-only until a named technician verifies every field against original
  sent/accepted evidence and uses the explicit attestation action. Current live data is not proof.
- Preserve/export customer snapshot evidence before any separately approved migration rollback.

## Tests

- Exact integer/decimal line and cadence totals, including the supplied 3,879.68 monthly example.
- Included/zero, annual, one-time, setup-fee, discount, and quarterly compatibility cases.
- Description fallback, HTML removal, Norwegian text, and snapshot immutability.
- Rate visibility/deduplication and empty-section behavior.
- Metadata-v2 source/text mismatch, manual `Versjon 1 (kontraktsspesifikk)`, GET/POST boundaries, and
  removed-source fail-closed behavior.
- Empty/scalar/unknown/partial-v1 non-null customer snapshots fail closed without a live rebuild.
- Shuffled/renamed v1 columns and inconsistent minor/decimal/display or `Inkludert` evidence fail closed.
- Unknown cadence and negative price remain Livewire field errors with a safe `—` preview and no 500.
- Authoritative non-NOK Service/offer/CloudFactory values fail closed, while Contract-bound markup uses
  exact decimal arithmetic rather than float.
- CloudFactory reconciliation locks the accepted parent Contract before re-resolving and locking its
  Contract-owned line inside one transaction.
- Legacy preview fingerprints remain stable across different relationship load orders by sorting lines
  and the final deduplicated rate identities deterministically.
- Bounded migration backfill and both protected rollback guards are covered.
- Draft-only Service interval adoption and accepted-line refusal.
- Tech/API/date-validation/readiness parity and existing-contract compatibility.
- New-send bearer-token rotation, unsent manual-approval token clearing, public/resend missing-token
  refusal, resend recipient/CC integrity, and honest API/portal pagination with an isolated unavailable
  row.

Earlier focused runs and Dev migration batches 130 and 131 remain recorded as historical checkpoints
in `HR-2026-08-24-003`. Final authoritative scoped Dev verification passes 99 tests / 1,455 assertions: `ContractFinalReviewRegressionTest` 44 / 868, `ContractCustomerDocumentTest` 6 / 104, `CommercialModuleTest` 33 / 313, `CustomerPortalQuoteContractAcceptanceTest` 2 / 60, two Contract-focused `CustomerPortalCommercialEconomyTest` methods 2 / 26, `ContractPricingTest` 8 / 31, and four CloudFactory Contract-boundary tests 4 / 53. Scoped Pint, Blade compilation, and
`git diff --check` pass.

## Documentation

Update the parent RFC/ADR, Contract/Service/Rate/API Knowledge, TODO, and human review.

## Done Criteria

- One Brick Math calculator owns line, discount, setup-fee, included, and cadence totals.
- Editable lines snapshot customer descriptions, unit labels, and explicit rate visibility.
- Sending/approval captures immutable customer evidence; unsupported evidence and unreviewed legacy
  mismatches fail closed.
- Historical null-snapshot rows require named attestation; API collections expose an honest per-row
  readiness blocker without aborting unrelated rows.
- Attestation rejects a stale preview and cannot infer a won/approved document type or replace a
  non-null snapshot.
- Metadata version 2 binds exact term sources and exact reviewed text; manual wording keeps its own
  Contract version identity.
- Tech/API updates enforce date, editability, identity, and term-readiness boundaries and use the
  shared projection.
- Focused calculator, snapshot, API, date, locking, and existing-record regressions pass on Dev.
