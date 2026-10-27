# CustomField API Verification

Date: 2026-10-27. Owner: Codex. Authoritative implementation: Dev.
Status: Done On Dev; final isolated Main candidate passes and is ready for the authorized merge.

Seven HTTP operations and 19 typed schemas cover the four definition browser routes,
all settings/types and the existing Client/Site/Ticket value workflows. Runtime
routes/scopes/native rights and test ownership are in the coverage-matrix.json.
Actual successful responses match their schemas. Trusted HTTPS verifies all seven
operations, exact definition/command read-back, deleted 404, anonymous 401 and Swagger.
All synthetic definitions, commands and temporary tokens are removed.

The isolated Main candidate feature batch passes 572 tests / 7858 assertions;
Ticket units add 17 / 465. No failures/errors/skips. Latest focused Dev checks pass
51 / 1858, including all 11 type roundtrips/clear on all three owning domains,
native/token revocation, visibility/counts, stale/repeated commands and source docs.
23 scoped PHP files pass syntax/Pint. The pre-existing module test is byte-identical.

Native MySQL proves encrypted result read-back, unique replay identity, atomic
rollback, two-connection mutex exclusion and lock release. Only the exact additive
migration 2026_10_27_050000_create_custom_field_api_commands ran on Dev (batch 34).
Probe tables are removed; business data and default-off Ticket gates are unchanged.

Final fresh Dev batch passes 676 tests / 46696 assertions, no failures/errors/skips.
It includes CustomField, Clients, full Ticket features/units, Knowledge, API management
and production Swagger generation. The first broad run was superseded because other
contributors changed UserManagement handlers and handbook sources while PHP was alive;
all such failure cases are resolved in the fresh run. No failing case is deferred.
The original isolated candidate passes independently. New upstream Notification code
is preserved and receives a final CustomField/Notification/portal/catalog regression
batch with a dedicated Blade compile directory, avoiding shared test-view collisions.

Four exact internal Knowledge sources are read back with published revisions.
Native pending_push is not BookStack publication evidence. Actual BookStack 451
contains the exact full core/API guides; 449 contains the new API-management note.
Page/chapter/book inheritance is preserved; no permissions changed. Normal chapter
creation is blocked by the existing connector name-pattern defect, so the complete
guide is embedded in the existing Client Domain Overview. No publication remains
for this scope; dedicated container/connector repair remains an independent follow-up.
Employee OpenWebUI retrieval is not tested.

Human-review checklist HR-2026-10-27-CUSTOM-FIELD-API remains Pending. Svein Tore
explicitly authorized implementation and tested Main merge with "Godkjenner og merge
til main" on 2026-10-27. This does not invent manual scenario execution or production
approval. Future deployment: exact additive migration, php artisan optimize:clear,
php artisan l5-swagger:generate. No frontend build or worker/scheduler/provider change.
Retain APP_KEY, encrypted receipts and stored values; forward-disable new writes on
failure. Key/type/options edits do not convert values or repin immutable Ticket rules.

Final rebased Main interoperability batch: 236 tests / 9264 assertions, no failures,
errors or skips. It includes all CustomField tests, Ticket Custom Field surfaces/rules
and units, upstream Notification/portal integration, API management, Knowledge contract
and production Swagger scanning. The complete batch was rerun with its own compiled
Blade directory after one shared-cache collision; no failing case is deferred.
The scoped Swagger artifact additionally passes 3 / 1481 exact CustomField/Knowledge
contract checks and preserves every other Main operation/schema. The legacy Client
generation test's artifact rewrite was discarded; only CustomField JSON is promoted.

After preserving the newly merged Intake code and coordination records, the final
latest-Main CustomField/Knowledge contract batch passes 27 tests / 2042 assertions,
no failures/errors/skips, using the dedicated Blade cache. All other Swagger paths
and schema objects remain those of the frozen upstream Main parent.
