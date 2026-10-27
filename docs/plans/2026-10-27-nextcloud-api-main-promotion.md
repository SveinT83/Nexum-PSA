# Nextcloud API Main Promotion, 2026-10-27

Status: Verified and merged to Main in PR #338. Production unchanged.
Owner: Codex. RFC/Main authorizer and human-review owner: Svein Tore.
Authorization: Godkjenner og merge til main, 2026-10-27.

## Candidate and scope

Base: Main ce004cf7150f846a95ba99a3ea500ab183bf6a2e; Notification and Intake changes preserved.
Branch: codex/nextcloud-api-20261027.
Runtime worktree: /tmp/nexum-nextcloud-api-main-20261027.
Ordinary implementation/runtime remains /var/Projects/tdPSA on Dev.
The explicit allowlist contains only approved Nextcloud changes, new additive migration,
config, one related unit expectation and scoped catalog/Swagger/Knowledge/coordination edits.
Unrelated shared Dev changes, index, branch and production environment are excluded.

## Verification

All 50 operations have actual successful typed HTTP evidence (36 new, 14 calendar),
47 new schemas. Dev affected 373/13899; Main candidate 391/14397, no failures/skips.
Final Nextcloud/Talk after scoped privacy handling: 121/6518 on each runtime.
Final HTTP status evidence rerun: 14/4956 on each. These test sets overlap.
Native MySQL locking/unique/encryption/rollback, exact Dev migration and trusted HTTPS
pass. Synthetic inactive API object is deleted/read-back/replayed and token revoked.
Candidate App/Tests reflection resolves to the isolated checkout with its independent
vendor tree and synthetic SQLite environment. Production credentials were not copied.
Pint, syntax, scoped whitespace and original non-Nextcloud Swagger preservation checked.
23 new paths contain 36 methods; 47 new schemas; existing 14 calendar methods preserved.
Actual BookStack bodies and inherited staff scope read back; guide embedded in page 417
because ordinary connector create input is incorrectly rejected. Employee AI untested.

## Human and production gates

HR-2026-10-27-NEXTCLOUD-API remains Pending: Svein authorized Main promotion with manual
scenarios open. No checkbox becomes Reviewed from these tests or this merge.
HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC and its pilot privacy/history checks remain In Review.
The relevant existing Calendar human review is likewise not inferred complete here.
No production deployment, provider/worker/scheduler activation, wider API grant or real
provider/customer/calendar mutation is authorized by this merge. The future release owner
must perform/accept the human checks and separately authorize production rollout.
Future deploy: exact 2026_10_27_030000_create_nextcloud_api_commands.php migration,
normal optimize/cache and Swagger generation. No frontend build, package, cron or job change.
Keep APP_KEY, receipts, mappings, claims and history on rollback; reconcile unknown outcomes.

## Merge evidence

Create the scoped PR only from the tested candidate. Verify its exact head before merge.
After merge fetch origin/main and compare tested candidate tree (or scoped tested hashes
if unrelated concurrent Main commits intervened). Record actual PR/head/merge/tree read-back
and reconcile Dev/Main TODO/RFC/slices/review state. A documentation-only follow-up may
record the immutable result without changing any tested PHP behavior.

Final published-Main reconciliation: Intake landed after the 347-test combined run.
Only its published catalog/route/coordination additions are incorporated; no Intake
implementation is copied from shared Dev. All 121 Nextcloud/Talk tests and all 50 actual
HTTP/schema operations pass again against this exact combined candidate. Non-Nextcloud
Swagger values are preserved, and the PR difference remains within the task allowlist.

## Verified Main Promotion (2026-10-27)

PR #338: https://github.com/SveinT83/Nexum-PSA/pull/338.
Merge: 0f062b9450de76cabdea269c55506e17d42bcef7.
Verified head: c326587d34b896ef0dee4b021ab0877a4ba3e465.
Exact candidate/merge tree: 8ce98ceb63f831d2345373941b5d1088c379a262.
All scoped tested PHP hashes match. Current published Contact/Calendar/Notification
changes are preserved. Final affected candidate 391 tests / 14397 assertions; final
Nextcloud/Talk 121 / 6518, no failures/skips. Totals overlap. Every one of the 50 HTTP
operations has successful typed response evidence. Shared Dev HEAD/index preserved.
No Main action remains. HR-2026-10-27-NEXTCLOUD-API remains Pending; production
rollout/provider activation and employee assistant retrieval remain separate.
This follow-up changes status/evidence documentation only; application tests are not
repeated for that record. No tested PHP behavior is changed.
