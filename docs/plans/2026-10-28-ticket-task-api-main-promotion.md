# Ticket/Task API: scoped Main promotion

Date: 2026-10-28. Owner: Codex. Named approval and release owner: Svein Tore.
Status: approved Main candidate; automated verification in progress.

## Authority and exact scope

Svein Tore requested "Godkjenenr og merge" after the complete Ticket/Task handoff.
This authorizes the scoped Main promotion, including its commit, branch push and pull request.
It does not claim that pending manual/provider scenarios were performed or authorize production
deployment, runtime-switch activation, real sends, stock/billing writes or unrelated Dev work.
HR-2026-10-20-TICKET-TASK-API remains Pending for those operational checks and production release.

The shared authoritative Dev checkout remains on Dev at
36918edbcaae1ce7b1208ba34f84a3a1719f0e98. An isolated promotion worktree starts from
origin/main 81d1eb2a59ceabc0b36d4d65721cad6f7658f5ca. The durable handoff manifest
2026-10-20-ticket-task-api-files.json selects the reviewed source; its hashes and the
current Dev snapshot distinguish later edits. No dirty Dev checkout is committed wholesale.
The isolated candidate retains newer Main Markdown, Relationship, Storage/Economy and
other-domain API work. Native Ticket/Task browser route sets are unchanged; full guarded
API blocks are inserted into the module routes.php files and loaded once by the bootstrap.

## Reconciliation decisions

- Add only missing tickets.*, tasks.* and portal.tickets.* abilities to the current catalog.
- Keep Main's EnforceTechRoutePermission and all unrelated API/bootstrap registrations.
- Exclude the later shared-Dev widget-order regression method from TicketModuleTest; its
  native view change is outside the API handoff. Keep existing Main tests and the reviewed
  customer Work Context fixture correction. The broad runners had already loaded the extra
  method; results will distinguish that variant from the final focused test-file verification.
- Keep the existing bounded Relationship compatibility guard.
- Resolve the DocumentationRequest conflict through the reviewed shared transactional action,
  preserving the native request, event and workflow trigger as well as Main Markdown changes.
- Include DomainApiAuditResource as a necessary bounded read-back/schema dependency, absent
  from the old manifest but referenced by Ticket settings/assignment history. It exposes only
  identities, changed field names and fingerprints, without original content.
- Retain the full reviewed shared read-back schema superset, with existing schema names.

## Coverage and current verification

The target inventory is 130 browser rows and 215 API route rows / 217 HTTP operations:
114 GET, 62 POST, 22 PUT, 7 PATCH and 12 DELETE (103 writes/actions). The existing parity
tests check every actual method/path, scopes, current domain permissions, input fields,
OpenAPI references, responses, multipart files and binary downloads.

The final route audit found one real omission from the Oct20 inventory: the browser serves a
static Task guide as Markdown. GET /api/v1/tasks/docs now serves the exact same bytes through a
shared TaskDocumentation reader, with tasks.read plus current task.view rights and byte-range
support. Two new regressions failed with HTTP 404 before this fix, then passed alongside the
five existing Task read-back tests (7 tests / 71 assertions) on Dev and Main.

The audit now links all 130 browser rows and 63 Livewire public methods to explicit API route
names, scopes, current actor permissions, tests and applicable independent read-back routes.
The inventory regression rejects new unclassified or unmapped routes/component methods. See
[full coverage](2026-10-28-ticket-task-api-coverage.md) and the expanded JSON matrix.

Fourteen existing write operations were missing Swagger 409 Conflict responses for actor-bound
retry keys or stale rule drafts. These contracts are now explicit; no mutation behavior changed.
The contract regression checks retry-key operations for that response. The in-memory scanner
uses the same attribute analyser and development-only exclusions as deployed L5 Swagger.
The broad run's two documentation scans reached the artificial 180-second test limit; they are
rechecked with the established 600-second bound and all previous assertions retained.

Current final verification (unique cases, latest focused results replace overlapping earlier cases):

- Authoritative Dev: **582 tests / 47,313 assertions**, all resolved and passing.
- Isolated current-Main candidate: **581 tests / 47,309 assertions**, all resolved and passing.
- The one-case difference is the later Dev-only widget regression excluded from promotion.
- All 177 changed PHP files pass syntax checks. No migration/package/frontend build is added.
- The 600-second contract runs each pass 2 tests / 41,576 assertions. Production no-dev
  sentinel scans pass on both roots. Earlier 180-second scan interruptions are resolved,
  with no assertion removed. The remaining application-wide test suite was not run.

The final generated Swagger and trusted HTTPS Dev read-back are recorded alongside this
verification; production availability remains unverified by this promotion. The original Oct20
acceptance evidence remains historical and is not substituted for this candidate's tests.

## Open operational checks and documentation

The named human-review checklist retains same-account browser/API comparison, real-database
concurrency, complex cross-client merge and allocated billing/stock reconciliation, actual
provider outcomes, Portal membership revocation and the external scheduler runner. Related
existing Task-template, Ticket-Rules, Knowledge-publication, Relationship/CustomField and
Markdown review entries retain their own status; this merge approves no unrelated checklist.

Knowledge source availability notes distinguish Main source delivery from production.
BookStack exact-source publication, actual article read-back and employee access remain a
separate follow-up owned by Codex after Svein provides/approves the supported publication
route and target availability. Do not bulk-push unrelated pending Knowledge articles.
The public website handoff remains "Do not publish" until approved production verification.

## Deployment prerequisites (not executed by this promotion)

No new migration, Composer package or frontend build is introduced by this scoped delivery.
The installation's existing domain tables, activity_log schema/configuration, provider and
membership grants and existing required migrations must already be correct. On a separately
authorized deployment use the normal pipeline and optimize:clear, l5-swagger:generate and
queue:restart, then independently read back served Swagger and affected HTTP routes.
Verify the OS/Plesk/systemd once-per-minute schedule:run runner separately. Existing Rules/AI
feature switches, provider permissions and token grants are not widened by this merge.

## Trusted HTTPS Dev read-back

The actual Swagger UI (/api/documentation) and JSON (/docs) both returned HTTP 200.
All 217 Ticket/Task method/path pairs are present, including taskReadDocumentation.
Served JSON SHA-256 equals the generated Dev file: 78ff0019c53f21ee91a5a7afb9cd64b834f529ce4ca4daa0bfe12fe02402ed3c.
Unauthenticated /api/v1/tasks/docs with Accept: text/markdown returns JSON HTTP 401.
Authenticated success, exact source bytes, byte ranges and current-role/scope denials are
verified with isolated synthetic HTTP feature tests; no real token grants or provider actions
were changed. These are Dev observations, not production deployment evidence.
