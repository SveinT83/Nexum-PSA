# Feature Slice: CloudFactory Legal Documents And Portal Ordering

Status: Ready For Human Review
Date: 2026-07-22
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Synchronize and preserve provider legal documents, combine them with approved Nexum terms, and let
authorized customer administrators perform contract-covered licence changes with explicit evidence.

## User-Visible Behavior

CloudFactory Services show read-only Provider terms with issuer, version, status, source link, and
last check time. Additional Nexum terms are selected from the legal library without inline editing.
The customer portal exposes Licences only to client-level Customer admins and only lists exact
CloudFactory variants already covered by an active won contract.

Every portal issue, quantity change, and renewal change requires an explicit confirmation and records
the current legal versions and commercial context.

## Scope

Immutable term versions, CloudFactory extraction and monthly catalogue synchronization, offer and
Service links, contract term-version snapshots, legal acceptance events, English Service UI, legal
library read-only enforcement, portal routes and forms, contract/action gates, and automated tests.

## Out Of Scope

Inventing provider legal content, accepting Microsoft MCA on the customer's behalf, provider-specific
document endpoints not present in the verified API contract, public anonymous licence ordering, and
automatic acceptance of materially changed terms.

## Data Touched

Terms, term versions, Service-term links, CloudFactory offer-term links, contract term snapshots,
legal acceptance events, contracts, contract items, CloudFactory operations, and portal audit context.

## Permissions

Integration synchronization owns provider records. Commercial users maintain Nexum-owned legal
library records. Only client-level Customer admins may access portal licence ordering.

## Done Criteria

- [x] Existing Nexum terms are backfilled to immutable version 1.
- [x] Provider changes create new versions and never overwrite an accepted version.
- [x] Missing provider documents remain stored and visibly marked not returned.
- [x] Provider terms are read-only on Service and in the legal library.
- [x] Additional Nexum terms remain selectable from the approved library.
- [x] Contract send captures exact document versions alongside existing text snapshots.
- [x] Portal contract acceptance records version-aware evidence.
- [x] Portal licence issue, quantity, and renewal writes require explicit confirmation.
- [x] Portal products are restricted to exact variants on eligible accepted contracts.
- [x] Automated provider-version and portal-order tests pass.
- [ ] Human Dev review in `HR-2026-07-22-001` is complete.

## Verification

The CloudFactory feature suite includes an immutable provider-document update/removal scenario. The
Customer Portal suite includes a real routed Customer-admin order against a contracted Microsoft
variant and asserts the recorded term-version checksum, contract line, quantity, account, membership,
and submitted provider operation. The full Integration and Customer Portal suites pass with 90 tests
and 876 assertions, the affected Commercial/portal run passes with 63 tests and 686 assertions, PHP
syntax and Blade compilation pass, and migration batch 50 is applied on Dev.

A live 2026-07-22 catalogue run processed all 10,898 offers. The current catalogue contract returned
no supported product legal-document field, including for Microsoft 365 Business Premium, so the
verified Dev state is Not supplied by provider rather than invented legal content.
