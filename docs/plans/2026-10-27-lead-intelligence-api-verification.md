# Lead Intelligence API Verification

Date: 2026-10-27. Authoritative source/runtime: /var/Projects/tdPSA (Dev).
Status: domain implementation verified; broader regressions and Main promotion in progress.
Authorization: Svein Tore, "Godkjenner og merge til main"; RFC Approved / ADR Accepted.

## Current Result

All 14 browser route rows and existing API-only evaluation/promotion have operational
API support: 17 HTTP operations / 47 typed Swagger schemas. Exact route/contract
comparison and 18 actual success responses (including promotion preview) pass.
No new deletion/cancellation/crawl/email feature is claimed.

Final domain suite: 51 tests / 466 assertions, no failures/skips, 100.13 seconds.
Prior narrower 49/458 and 2/8 are overlapping and must not be added to this final total.
Knowledge source-sync regression: 1 test / 6 assertions passes.
Twenty-nine scoped PHP files pass Pint/syntax before final guard/test formatting;
final full lint checks are repeated before packaging.

Native MySQL: exact additive migration 2026_10_27_040000_create_lead_intelligence_api_commands
applied/read back on Dev, batch 35. Independent lock contention, unique replay key,
encrypted storage/decryption and transaction rollback probes pass, leaving no probe rows.
Five authenticated trusted HTTPS reads pass, anonymous access is 401, actual Swagger
at /docs reports exactly 17 Lead Intelligence operations. Temporary tokens revoked.
API external execution stays false. No business backfill or live provider/customer
write, worker/scheduler activation or production migration/deployment occurred.

Existing native Knowledge articles 52 and 3 match the affected source content and
remain internal. Actual BookStack 496 revision 3 matches the full Overview/API guide;
449 revision 153 contains the exact scoped catalog addition. Existing page/chapter
inheritance and parent access were read; no permissions changed. Automatic native
pending_push is not external publication proof; actual BookStack was read separately.
Employee OpenWebUI retrieval and real provider execution remain untested/separate.

## Behavior And Recovery

Native rights and token abilities intersect, including fresh actor/token checks after
command-lock waits. Queued API jobs retain a durable bearer identity and recheck it
before execution/promotion. AI/queue uncertainty has a frozen encrypted outcome and
is not blindly retried. Cross-key promotion uses one shared mutex; Client creation
uses Clients/CreateClientRecord with canonical Tripletex authority and stable UUID.
Blank organization number never implies an invoice email from a generic/contact email.
Suppression, do_not_email, Marketing exclusions and Contact bridge remain covered.

Writes now require Idempotency-Key (except pure dry_run). Existing callers must add it.
Use exact GET ETag as If-Match on edits. Preserve APP_KEY and receipt/intent data in
recovery, disable API external execution and reconcile unknown attempts. Domain-wide
locking favors integrity; high-volume production performance is not measured.

## Verification In Progress

The broader Dev suite includes Marketing, Contact, Clients, customer-number/profile
and production-mode Swagger. It reported two ClientsProviderApiTest failures while
continuing; both pass in isolation (2/25) and after ClientsApiParityTest (20/152).
Their complete failure details and same-class sequencing are still under investigation.
No failure is approved for deferral. An isolated Main candidate is being tested with
its own Composer metadata, independently proven to autoload App from that candidate.
Do not treat these in-progress checks as passing or claim Main merge yet.

## Human Review And Deployment

HR-2026-10-27-LEAD-INTELLIGENCE-API remains Pending. Named RFC/Main authorization
allows tested scoped Main promotion without claiming witnessed scenario review.
The checklist still blocks production release/provider activation. Remaining manual
checks compare settings/segments, draft/queued actions, evidence, eligibility/promotion,
revocation/replay and provider reconciliation. No existing reviewed history was cleared.

Evidence: coverage matrix/contract/files, native-evidence.json, knowledge-evidence.json
and bookstack-evidence.json in this plans directory. Shared Dev HEAD/index and all five
pre-existing #280 badge views are preserved; unrelated contributor work is not packaged.
