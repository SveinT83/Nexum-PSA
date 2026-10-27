# Lead Intelligence API Verification

Date: 2026-10-27. Authoritative source/runtime: /var/Projects/tdPSA (Dev).
Status: Done On Dev / Merged To Main in PR #343 / Human Scenario Review Pending.
Authorization: Svein Tore, "Godkjenner og merge til main"; RFC Approved / ADR Accepted.

## Current Result

All 14 browser route rows and existing API-only evaluation/promotion have operational
API support: 17 HTTP operations / 47 typed Swagger schemas. Exact route/contract
comparison and 18 actual success responses (including promotion preview) pass.
No new deletion/cancellation/crawl/email feature is claimed.

Final domain suite: 52 tests / 474 assertions, no failures/skips, 99.86 seconds.
Prior narrower 49/458 and 2/8 are overlapping and must not be added to this final total.
Knowledge source-sync regression: 1 test / 6 assertions passes.
All 29 scoped PHP files pass syntax; scoped Pint and whitespace checks pass.

Native MySQL: exact additive migration 2026_10_27_040000_create_lead_intelligence_api_commands
applied/read back on Dev, batch 35. Independent lock contention, unique replay key,
encrypted storage/decryption and transaction rollback probes pass, leaving no probe rows.
Five authenticated trusted HTTPS reads pass, anonymous access is 401, actual Swagger
at /docs reports exactly 17 Lead Intelligence operations. Temporary tokens revoked.
API external execution stays false. No business backfill or live provider/customer
write, worker/scheduler activation or production migration/deployment occurred.

Existing native Knowledge articles 52 and 3 match the affected source content and
remain internal. Actual BookStack 496 revision 4 matches the full Overview/API guide;
449 revision 160 contains the exact scoped catalog addition. Existing page/chapter
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

## Cross-Domain And Candidate Verification

Initial broad Dev batch: 316 passed / 14 failed / 4122 assertions. Thirteen errors
came from an author-updated UserManagement exception hook calling matches() before
that method was in the class loaded by the long-lived process; one new Contact test
loaded without its ClientUser import. Both author-owned files now contain the fixes.
Fresh rerun of all 14 affected cases passes: 14 tests / 192 assertions, 303.37 seconds.
No related source was overwritten and no failure is deferred. This is not represented
as a single fresh all-green combined Dev batch.

The independent clean Main candidate, with its own verified App/Tests Composer base,
passes the full affected set: 293 tests / 2887 assertions, zero failures/skips.
After current Main Notification/documentation integration and final Lead changes,
the exact final domain + production Swagger + Knowledge source set passes:
54 tests / 483 assertions, zero failures/skips, 101.656 seconds. Sets overlap.
Main changes since the broader batch do not alter Client/Contact/Marketing/Tripletex
runtime paths; final Swagger and native/token authority are rechecked on the final tree.
The final generated 17 Lead operations and 47 schemas exactly match scoped canonical
Swagger additions; unrelated existing contracts are retained.

## Human Review And Deployment

HR-2026-10-27-LEAD-INTELLIGENCE-API remains Pending. Named RFC/Main authorization
allows tested scoped Main promotion without claiming witnessed scenario review.
The checklist still blocks production release/provider activation. Remaining manual
checks compare settings/segments, draft/queued actions, evidence, eligibility/promotion,
revocation/replay and provider reconciliation. No existing reviewed history was cleared.

Evidence: coverage matrix/contract/files, native-evidence.json, knowledge-evidence.json
and bookstack-evidence.json in this plans directory. Shared Dev HEAD/index and all five
pre-existing #280 badge views are preserved; unrelated contributor work is not packaged.

## Verified Main Promotion

PR #343: https://github.com/SveinT83/Nexum-PSA/pull/343
Merge commit: e42ecddb42a52e1b41931975e4760cf250e157cc.
Whole tree: d65bf3c88356992667dc5154c79686527fef0e3f, exactly the verified candidate tree.
Final head: 039e1659f99eb5f0bcb9bcd13bedd6efadbfaaf0; parent Main ce004cf7.
Later Intake-only Main additions, additive scope catalog and route registration were
retained; exact 17 Lead routes/guards and canonical Lead schemas were rechecked.
No Client/Contact/Marketing/Tripletex behavior changed after the affected candidate batch.
GitHub merged=true and a fresh strict SSH git fetch/read-back independently confirm it.
The follow-up changes status/docs only and requires no repeated application suite.
No Main action remains. Svein Tore owns the pending manual checklist and any separately
authorized production migration/deployment/provider pilot. API external execution stays off.
