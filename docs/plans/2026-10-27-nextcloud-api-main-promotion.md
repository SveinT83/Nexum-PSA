# Nextcloud API Main Promotion, 2026-10-27

Status: Isolated candidate verified; authorized Main merge pending exact read-back.
Owner: Codex. RFC/Main authorizer and human-review owner: Svein Tore.
Authorization: Godkjenner og merge til main, 2026-10-27.

## Candidate and scope

Base: origin/main 7d37fcae6c9af68ff77787b4e4cec0ed5c99c972.
Branch: codex/nextcloud-api-20261027.
Runtime worktree: /tmp/nexum-nextcloud-api-main-20261027.
Ordinary implementation/runtime remains /var/Projects/tdPSA on Dev.
The explicit allowlist contains only approved Nextcloud changes, new additive migration,
config, one related unit expectation and scoped catalog/Swagger/Knowledge/coordination edits.
Unrelated shared Dev changes, index, branch and production environment are excluded.

## Verification

All 50 operations have actual successful typed HTTP evidence (36 new, 14 calendar),
47 new schemas. Dev affected 373/13899; Main candidate 332/8889, no failures/skips.
Final Nextcloud/Talk after scoped privacy handling: 120/6460 on each runtime.
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
