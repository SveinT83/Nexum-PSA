# RFC: Full Documentation Browser/API Parity

Status: Approved for existing-browser parity by Svein Tore's direct implementation request, 2026-10-27.
Date: 2026-10-27
Owner: Codex. Product/release owner: Svein Tore.
Change Level: 3
Human review: HR-2026-10-27-DOCUMENTATION-API (Reviewed by Svein Tore, 2026-10-27).

## Context
Svein requested "Lag 100% API støtte og som ellers naturlig følger med".
The live Dev domain has 28 staff browser routes, 2 portal routes and 16 API
HTTP operations. Storage references Documentation's canonical vendors and carrier
configuration. #275 is open, without a revision RFC or implementation in this
domain. The Ticket follow-up slice uses Knowledge revisions, not Documentation.

## Goals
Full current browser read/write/action/admin and portal parity, actor and token
authorization, strict bounded contracts, shared actions/validators, exact read-back,
replay and conflict protection, typed OpenAPI, tests and employee handbook.
API coverage means all supported workflows, not merely counting URLs.

## Non-Goals
Do not implement or close #275, add draft/approve/publish/rollback fiction, redesign
customer access, grant permissions, publish customer content, activate providers,
or commit/push/merge/deploy Main. Document the missing revision capability honestly.
Supplier/carrier deletion is unsupported in web and remains unsupported; deactivate
through existing lifecycle fields. No remote carrier booking or URL fetching.

## Current Behavior
Legacy Documentation APIs use knowledge.* token abilities but lack fresh actor
permissions. Category/template APIs already exist. Browser uses documentation
view/create/update/delete/carrier_manage/manage_templates; category administration
uses taxonomy.manage_categories. Documents have mutable template snapshots,
structured content and explicit portal visibility; portal access is membership-based.
The API silently picks the first of multiple templates, against the approved
2026-07-29 template-selection RFC. Correct that mismatch to require a choice.

## Proposed Change
Keep legacy URLs and knowledge.* scopes compatible; add Documentation-owned context,
vendor/supplier CRUD (create/read/update), carriers and safe tracking preview,
portal visibility and portal reads. Route definitions live in Documentation/routes.php
with api.php as compatibility loader. New scopes narrow current web permissions.
Use shared Vendor validation, ShippingCarrierRequest/resolver and domain actions for
portal visibility, notifications and document mutation. Scope changes hide previously
visible content until explicitly published for its new audience.
Add local encrypted durable command receipts and singleton mutex; recheck actor,
token and exact resource on replay. Optional keys on legacy writes, required keys
on new writes; If-Match checks exact current state under row lock. Operation lookup
is actor/token-bound and rechecks original authority. No sensitive request bodies
or raw exceptions in receipts. All data changes and local receipts commit together.
GET discovery is read-only, paginated and never alters browser session context.
Portal API accepts own active membership selection and fails closed for invalid,
revoked, other-client or other-site context. Its resource matches portal fields;
no template-admin data, internal actors, source provenance or hidden documents.

## Impact Analysis
Documentation API/controllers/actions/resources/support/tests/docs; Integration
ability catalog and Swagger; existing route loader; Taxonomy categories; Storage
vendor/carrier references and historical shipment snapshots; CustomerPortal audit,
notifications and membership queries; Knowledge handbook sources/publication.
No packages, UI markup, global permission grants, external execution or schedulers.
Preserve unrelated dirty Dev files and the existing Knowledge revision slice.

## Data And Migration Plan
One additive documentation_api_commands/mutex migration, no business-row backfill.
Test on SQLite and native MySQL; apply only this migration on Dev after verification.
Preserve APP_KEY and receipts during recovery. Production migration requires separate
authorization. Normal cache clear and Swagger generation; no frontend build.

## Testing Plan
Complete domain baseline then bearer permission/ability/revocation tests; vendors,
manufacturers, supplier aliases; carrier safe-host rules and pure preview; template
selection/snapshot; category boundary; document scopes; portal visibility/audit/
notifications, membership/site denial and response projection; strict fields and
pagination; encrypted replay/key conflicts/stale If-Match/rollback; exact runtime
OpenAPI operations, schemas and actual response validation. Run affected Storage,
CustomerPortal, Notification, Taxonomy, WorkContext and Integration regression tests.
Trusted HTTPS smoke/read-back and native database locking/uniqueness checks.

## Documentation Plan
Maintain route coverage matrix, verification, Feature Slices, ADR, TODO and open
human checklist. Update authoritative Knowledge sources, publish only affected
BookStack sections and read back employee scope. Separate Dev/Main/production and
OpenWebUI retrieval. Public website input remains gated "Ikke publiser".

## Sequential Feature Slices
1. Authorization, route ownership, bounded discovery and existing API corrections.
2. Vendor/supplier/manufacturer and carrier master-data actions and safe previews.
3. Durable commands and conflicts; shared portal visibility/update side effects.
4. Equivalent scoped portal API and negative access tests.
5. Exact contracts, full regression, native/HTTPS read-back and handbook.

## Open Questions
None for supported browser parity. #275's immutable revisions and publication
permissions require their own approved design and remain tracked separately.

## Approval
Svein Tore directly authorized the complete implementation in this chat on
2026-10-27. This authorizes the existing-browser parity scope above, including
necessary Dev verification. It does not mark human review complete or authorize
Main/production promotion.

## Implementation Verification

Done On Dev: all five sequential slices. Final affected suite 548 / 8201,
no failures; 1 existing dedicated-MariaDB contract skip; all 30 browser rows and 33 typed API operations covered.
40 schemas, native database, HTTPS and actual handbook read-back verified.
Human review HR-2026-10-27-DOCUMENTATION-API is Reviewed by Svein Tore;
Main/production unchanged. Separate promotion/deployment authorization is required.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Dev handoff and its human checklist
with "Godkjenner". The review gate is satisfied. Main/production actions are not
authorized by this acceptance; no individual manual scenario is claimed as witnessed.

## Verified Main Promotion (2026-10-27)

Svein Tore explicitly authorized "Merge til main". PR #329 is merged:
https://github.com/SveinT83/Nexum-PSA/pull/329
Main merge: c6249f422f74abe333a9b0c95d460adeb7193b68
Tested and merged tree: 64d7176610701a931bbf198787a711f1b17da071; exact equality independently confirmed by fresh fetch.
Isolated Main candidate: 545 passed / 8135 assertions, zero errors/failures.
One existing Storage MariaDB identity-contract test skipped for missing dedicated
test credentials. All 33 typed HTTP operations and 40 schemas verified; 37 PHP
syntax/Pint checks pass. Shared Dev code, unrelated changes and staged index preserved.
HR-2026-10-27-DOCUMENTATION-API remains Reviewed. No Main action remains.
Production deployment and provider/worker/runtime activation remain separate.
This follow-up changes documentation/status only; application tests were not rerun.
