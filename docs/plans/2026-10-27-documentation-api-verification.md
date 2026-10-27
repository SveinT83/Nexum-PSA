# Documentation API: Dev verification and human review

Date: 2026-10-27. Implementation owner: Codex. Named reviewer/release owner: Svein Tore.
Status: Done On Dev / Reviewed by Svein Tore / Merged to Main in PR #329.
All five sequential slices and affected verification pass; production is unchanged.
Main merge c6249f422f74abe333a9b0c95d460adeb7193b68 is independently verified.
The merged tree equals the tested candidate. No production deployment was performed.

## Delivered behavior

All 30 current browser routes (28 staff and 2 portal) map to official APIs. The
contract grew from 16 to 33 HTTP operations, with 40 typed Swagger schemas.
Coverage: 2026-10-27-documentation-api-coverage-matrix.md and .json.

- Documents: bounded search/context filters, create, exact template selection,
  snapshot-preserving update, soft delete, portal metadata and explicit visibility.
- Categories/templates: supported reads, creation, updates and ordered field/layout
  arrays; inactive administration, category boundary and parent-cycle validation.
- Partners: canonical vendor/supplier/manufacturer search, detail, creation,
  maintenance and deactivation; Storage uses the same partner IDs.
- Carriers: canonical configuration, tracking rules, shared browser validation,
  detail/update and pure safe-link preview; shipment snapshots remain unchanged.
- Customer access: bearer and existing-session portal list/detail with current
  user/contact/account/membership and Client/Site boundaries; only rendered fields.
- Integrity: exact actor rights and token scopes, narrow edit/admin form reads,
  protected system fields, encrypted command receipts, key-conflict/replay checks,
  If-Match state conflicts, current-authority rechecks and exact record read-back.
- Shared web/API side effects: visibility audit/publication notification, visible
  document update notification and hiding after a Client/Site/internal audience change.

API paths, scopes, request examples, errors and operating steps are in the source
guide app/Modules/Documentation/Docs/knowledge/documentation-api.md.
Swagger on Dev: https://dev.nexumpsa.eu/api/documentation .
Every API declaration belongs to Documentation/routes.php. api.php is its compatibility
loader; routes/api.php only loads the module's portal branch.

## #275 boundary

https://github.com/SveinT83/Nexum-PSA/issues/275 remains open. This change provides
100% parity with today's supported browser behavior. Immutable Documentation revisions,
draft/approval/publication authority, freshness tracking and rollback still require
the separate approved design and implementation described by that Issue.
Opaque API state tokens and command receipts are not immutable document revisions.
Future publication must use Documentation-owned Actions and reuse #276 downstream
exact-revision/conflict/idempotency/read-back safeguards. No competing lifecycle,
provider/AI execution, general permissions or external connector was activated.

## Verification

The adjacent JSON contains exact final totals and the observed HTTP operation set.
A focused final batch passed 103 tests / 3305 assertions, zero failures or skips.
It includes Documentation, CustomerPortal, Storage API and production Swagger scanning.
All 33 successful HTTP operations have actual response-schema validation, with exact
runtime/Swagger method equality, referenced schemas, scopes, path parameters and errors.
The complete Documentation/Storage/CustomerPortal/Notification/Taxonomy/WorkContext
and relevant Integration regression batch passed **548 tests / 8201 assertions**,
zero failures and one existing MariaDB contract-test skip (dedicated credentials are not configured). These totals overlap the focused batch and must not be added.

The scoped manifest contains 53 files. 37 scoped PHP files pass syntax and Pint. Scoped git diff whitespace checks pass.
No non-Documentation API-catalog or API-loader content was changed. Generated Swagger
preserves every pre-existing non-Documentation operation and schema exactly. A
pre-existing Clients contact-documentation source/generated mismatch was retained
rather than included in this change. The original shared checkout had 1291 dirty paths;
unrelated contributions were preserved.

Separate native MySQL checks verified row locking against a second connection,
unique replay keys, encryption/decryption and transaction rollback. Only temporary
probe tables were used and removed. The exact additive Dev migration is installed
in batch 30; the API command table has no business/test mutations retained.

Trusted HTTPS checks cover health/login/Swagger, anonymous denial, current scoped
staff reads and portal denial without membership. Published Swagger matches the
exact local generated-file hash and exposes 33 Documentation operations. Temporary
read-only test tokens were removed. Current Dev collections are empty; positive
write/detail/portal scenarios are proven in isolated SQLite tests, while native
locking/encryption and deployed HTTPS are separate verification layers. No real
customer publication, provider execution or inbox receipt is claimed.

The optional all-Integration batch was stopped after 699 observed passing cases
with no reported errors because unrelated TripletexCustomerNumberTest rebuilds
its isolated database for 37 tests. It was replaced by the exact final affected
batch; this report does not claim an all-Integration or full-repository suite pass.

## Handbook and coordination

Five authoritative Knowledge sources were published locally through the supported
repository publisher and read back as internal published revisions. Native automatic
sync remains pending_push; that flag is not publication proof.

Actual BookStack pages 503, 504, 547, 423 and 449 were updated and independently read
back. The complete API guide is embedded in Documentation Overview 503; the existing
connector search/create validation defect is already tracked in TODO, so no duplicate
or placeholder page was created. All existing page inheritance was preserved.
The staff-internal Editor role has existing book/chapter/page view rights; no
BookStack or OpenWebUI permissions were changed. Employee assistant retrieval is
untested and is separate from actual handbook content read-back. Requested sections
are exact; unrelated source drift in existing handbook content was preserved.

RFC: rfc/2026-10-27-documentation-full-api-parity.md.
ADR: adr/2026-10-27-documentation-api-authority-and-revision-boundary.md.
Five sequential Feature Slices: feature-slices/2026-10-27-documentation-full-api-parity.md.
TODO and human-review state are reconciled in the same session.
One public-safe website editorial handoff was written and read back with an explicit
"Ikke publiser" gate for human review, Main promotion and production verification.

## Human review and separate release authorization

**HR-2026-10-27-DOCUMENTATION-API is a human-review checklist entry, status Reviewed.**
Svein Tore explicitly accepted this completed handoff with "Godkjenner" on 2026-10-27.
The human-review gate is satisfied. The original checks remain below as the review
scope, without inventing individually witnessed execution. Main promotion and
production deployment require separate authorization.

- [ ] Compare web and API document filters, explicit multi-template create, edit,
      captured fields and soft deletion for internal/client/site documents.
- [ ] Create and maintain a controlled supplier/manufacturer, deactivate/reactivate,
      and confirm Storage references the same ID with no stock/order side effects.
- [ ] Maintain a carrier and preview safe tracking; confirm historical shipment
      snapshots retain the original configuration.
- [ ] Publish/hide one controlled client document and inspect portal, audit and
      intended recipient notifications. Verify a normal visible-content update.
- [ ] Change its audience and confirm it stays hidden until explicitly republished.
      Check a site-specific and a client-wide membership through web and API.
- [ ] Verify narrow edit/admin actors, read-only scopes, revoked access, repeated
      keys and stale If-Match; check the handbook and Swagger on the target.
- [ ] Report any defect against this same checklist, then explicitly confirm review.

## Future deployment and recovery

Svein Tore owns commit/promotion and production authorization. Before a future
release, confirm this acceptance and review all other relevant unreviewed entries; package
only the scoped manifest rather than promoting the shared dirty Dev tree.

Required additive migration:
`php artisan migrate --path=database/migrations/2026_10_27_010000_create_documentation_api_commands.php --force`.

Regenerate Swagger and refresh normal application caches/opcache with the established
deployment procedure. No frontend build, package update, business backfill,
scheduler/worker activation or new permission grants are required.
Retain APP_KEY and encrypted receipts on rollback; prefer restoring compatible code
to dropping populated command tables. Singleton API serialization favors integrity;
high-volume production throughput has not been measured.

## Approval Record (2026-10-27)

Reviewer: Svein Tore. Direct response: "Godkjenner". HR is Reviewed; Main and
production remain unchanged and require separate authorization. This turn updates
documentation/status only; application tests were not rerun because runtime code
and the previously verified behavior were unchanged.

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
