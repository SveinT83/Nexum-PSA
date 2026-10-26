# RFC: Full Storage Browser/API Parity

Status: Approved for existing-browser parity by direct implementation authorization from Svein Tore, 2026-10-26.
Date: 2026-10-26
Owner: Codex. Product, promotion and production owner: Svein Tore.
Change Level: 3
Human review: HR-2026-10-26-STORAGE-API (Reviewed by Svein Tore, 2026-10-26).

## Context
Svein requested: "Domenet: Storage trenger full API støtte. Kjør på".
Authoritative Dev is /var/Projects/tdPSA on Dev. Runtime audit found 64 Storage
browser route rows and 24 API route rows / 27 HTTP operations (HEAD excluded).
Existing API covers core inventory and purchase/receiving writes; supplier lookup,
picking, unified supplier workspace, imports, profiles and administration are missing.
Existing item responses also omit supplier details, movement history and incoming
quantities. Existing route abilities alone do not establish current staff permissions.
Baseline: 257 Storage feature/unit tests / 2778 assertions, 2026-10-26.

## Goals
Expose every supported browser read, write and action through typed, scoped API
operations with authoritative domain actions, current authorization, exact read-back,
OpenAPI/Swagger, regression tests and published employee documentation.
Keep Supplier Orders one operational workflow with email as supporting evidence.
A caller must search the canonical supplier, match/create an item and register the
externally placed order without a browser or direct database access (Issue #299).

## Non-Goals
New supplier master-data CRUD, remote supplier ordering/payment, new tracked-unit
picking, shipping-provider execution, new AI configuration concepts, or new product
workflows. Main promotion, production deployment and provider/worker activation
require separate authorization. Do not resolve #286 by silently changing numbering:
its four dirty contributor files and current manual/API/import behavior are preserved.
The open numbering/immutability acceptance criteria remain separately tracked.

## Current Behavior
Documentation owns Vendor (vendors table), supplier/manufacturer roles and carriers.
Storage owns stock, purchase orders, immutable receipts, supplier import review,
profiles and automation policy. Ticket owns reservation picking and its Economy side
effects. Email mailbox access remains independently authoritative. Existing receipt
posting/reversal tokens and lifecycle/overage guards must continue to apply.
Admin profile/policy routes require an active internal account and the corresponding
Storage permission through EnforceTechRoutePermission (AdminAccess is not a hard-coded role gate). GET helper methods currently
may create defaults: new API reads must avoid lazy policy/warehouse writes.

## Proposed Change
1. Keep all Storage route declarations in Storage/routes.php; retain api.php only
   as a compatibility loader for the existing module API discovery.
2. Retain existing URLs/scopes and add typed discovery, supplier lookup, inventory
   history/details, picking, supplier workspace/receiving, import operations,
   inventory defaults, policy and complete profile lifecycle operations.
3. Use canonical shared queries and actions. Extract shared profile persistence and
   definition defaults only where browser controller logic otherwise would duplicate.
4. Require active internal users with fresh effective browser rights plus explicit
   token abilities. Settings/profile operations retain the corresponding browser administration permission.
   Token scopes cannot grant user rights. Nested resources must belong to their parent.
   Ticket and mailbox details require their own permissions/access.
5. Add separate scopes for supplier lookup, picking, import read/resolve/execute,
   profile management and settings read/write. Existing scopes retain their purpose.
   Register abilities only alongside concrete routes and tests.
6. Use strict allowlisted payloads, bounded pagination, explicit enum/filter validation,
   safe resources and consistent 401/403/404/409/422 errors. No raw model serialization
   of source mail, provider output, authentication snapshots or internal secrets.
7. Serialize local API writes and support durable idempotency with encrypted receipts,
   current authorization on replay, request fingerprint conflicts and operation lookup.
   Preserve existing receipt idempotency. New actions require keyed execution; legacy
   clients may omit the new header. Expose revisions and enforce supplied If-Match.
8. Provider-affecting AI repair/retry and automation activation require explicit
   confirmation and default-off API runtime switches. Commit external claims before
   execution; interrupted attempts remain unknown and require reconciliation, never
   blind replay. Previews reveal exact effects/eligibility and readiness without writes.
   Tests mock provider boundaries; no operational AI requests or worker changes.
9. Map every browser route to API operations, scopes, schemas, tests and read-back.
   HTML form routes map to typed context/detail/default endpoints; docs and the control
   slip retain useful authenticated download/read equivalents.

## Supplier Lookup Contract (#299)
GET storage/suppliers and GET storage/suppliers/{supplier} reference Vendor directly.
List accepts q (name/code), is_active (default true), is_supplier (default true), page
and bounded per_page. Stable id order resolves ties; ambiguous names return all matches.
Detail returns id, name, vendor_code and role/status booleans. Unknown IDs return 404;
supplier-role filtering never silently promotes or creates a partner. Inactive records
may be inspected explicitly but existing write actions reject unusable suppliers.
No notes, terms, contact data, source provenance or unrelated Documentation records.
Ability storage.suppliers.read plus storage.view or storage.purchase_view, evaluated
against current user rights. No new supplier register or arbitrary supplier writes.

## Impact Analysis
Storage controllers/queries/actions/resources/routes/tests/docs; Integration ability
catalog and Swagger scan; Documentation reference reads; Ticket picking and Economy
order/event side effects; Email mailbox boundaries; Knowledge/BookStack publication.
No UI markup or domain ownership changes. Preserve active #286 diff. Local command
receipts/mutex are additive schema; no business-row backfill. Domain serialization
favors integrity; production throughput has not been measured.

## Data And Migration Plan
One additive Storage API command/mutex migration. Encrypt retained command results;
keep APP_KEY and receipts during rollback. Validate native DB locking/uniqueness
separately from in-memory tests. Apply only the exact migration on Dev after its tests.
No production migrations, business-data rewrite, new grants, scheduler/queue activation,
purge or replay of existing jobs. Queue-dependent operations report queued/pending,
never completed imports merely because dispatch succeeded.

## Testing Plan
Baseline and red/green tests for every gap. Test real bearer abilities and current
actor revocation/status, nested ownership, denied field leakage, malformed filters,
pagination, supplier ambiguity/inactivity, complete item fields, history/incoming,
picking inventory/reservation/Economy effects, receipt/reversal retries, import guards,
profile fixture validation/activation/rollback/export, pure settings reads, policy
validation, revisions and durable replay/conflicts/unknown provider results.
Run complete Storage plus affected Ticket/Economy/Documentation/Integration tests.
Compare runtime HTTP methods exactly with generated OpenAPI paths/operations/schemas.
Use trusted HTTPS smoke and read-back; do not bypass TLS failures.

## Documentation Plan
Update Storage Knowledge sources and API Management; sync/publish exact articles
through supported BookStack integration and read back text and employee access scope.
Record concrete publication blockers/owner in TODO if unavailable. OpenWebUI retrieval
is a separate claim. Maintain matrix, verification, TODO and human-review entries.
Website handoff only after verified implementation, with Dev-only/do-not-publish gate.

## Sequential Feature Slices
All slices use the shared tests/documentation/read-back criteria and one primary owner.
1. Authorization, route ownership, strict contracts and safe discovery/reference reads.
2. Inventory filtering/incoming, supplier/movement detail, boxes and picking.
3. Durable API command/revision boundary and existing mutation regression protection.
4. Unified Supplier Orders/receiving, contexts/control slip and import resolution/actions.
5. Inventory/policy settings and full profile/fixture/version/lifecycle API.
6. Exact Swagger coverage, native/HTTPS/regression acceptance and handbook publication.
No slice is Done until its behavior and evidence are verified; final state is in TODO.

## Open Questions
No new product decision is needed for existing-browser parity.
#286's canonical numbering redesign remains owned by its contributor; retain the
current contract and surface the separate unresolved acceptance criteria.

## Approval
Svein Tore explicitly authorized full Storage API implementation with "Kjør på",
2026-10-26. This is scoped to supported existing workflows. It does not mark human
scenario review complete or authorize Main/production/provider activation.


## Implementation Verification (2026-10-26)
All six slices are Done On Dev. The matrix covers every 64 current browser routes
and all 78 HTTP operations; 132 typed schemas and actual successful HTTP bodies
are verified. Final affected suite: 615 / 7717, Storage 287 / 4556, zero failures/skips.
Native MySQL and trusted HTTPS/Swagger checks pass. Dev migration batch 29.
Requested Knowledge/BookStack content is actually read back; the complete guide is
embedded in Storage Inventory 386 because connector create/rename validation is
defective. Existing access inheritance is preserved; OpenWebUI retrieval is separate.
Issue #299 is closed as completed. On 2026-10-26 Svein Tore explicitly accepted
HR-2026-10-26-STORAGE-API and authorized Main merge. The clean candidate includes
only the existing allocator/action/request prerequisite; the contributor's form change
and the remaining #286 acceptance stay separate. Shared Dev contributor files are
byte-identical. Production/provider activation remains unauthorized.
Current promotion evidence: docs/plans/2026-10-26-storage-api-main-promotion.md.

Main promotion completed in PR #326, ea4d0ac96db5983103d1688aeef4cf8f87a833ca.
Candidate tests: 614 / 7654; tested/merged trees match. Production remains unchanged.
