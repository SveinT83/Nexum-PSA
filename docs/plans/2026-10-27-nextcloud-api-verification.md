# Nextcloud Full API Verification, 2026-10-27

Status: Done On Dev / isolated Main candidate verified; merge authorized, not yet recorded.
Owner: Codex. RFC/Main authorizer and human-review owner: Svein Tore.

## Verified behavior

19 browser rows plus eight embedded workflows map to 50 typed HTTP operations: 36 new
full-domain operations and 14 preserved calendar operations. All 50 actual successful
HTTP responses validate against their static Swagger schemas. 47 new concrete schemas;
request shapes, fields, native rights and token abilities are explicitly documented.
Shared native actions cover connection/default/PATCH/credential, all user/contact/group/
calendar/folder branches, folder navigation/matching and full sync/health/Talk behavior.
Canonical Contact imports retain exact scoped bridge identifiers. Typed projections and
scoped framework rendering exclude credentials, raw provider errors and debug traces.
Local effects/audit/receipt commit atomically; external intent precedes I/O. Exact replay,
stale/denied/revoked rights, deleted-resource replay and uncertain provider outcomes pass.

## Tests and native evidence

Dev affected: 373 passed / 13899 assertions. Isolated Main: 332 / 8889. Different totals
reflect unpromoted contributor suites excluded from the candidate, not missing Nextcloud
checks. Final Nextcloud/Talk suites after scoped privacy handling pass 120 / 6460 on both.
Final HTTP evidence-only reruns: 14 / 4956 on each. Totals overlap; no sum is claimed.
No failures/skips remain in these final runs. Earlier shared UserManagement handler drift
caused first-run failures; its owner repaired the current methods and the broad rerun passed.
We did not modify/package unrelated bootstrap/UserManagement code.
Scoped syntax/Pint pass. Exact additive Dev migration succeeded. Independent MySQL sessions
prove mutex exclusion and replay uniqueness; encrypted result read-back and rollback pass.
Ten trusted HTTPS reads and anonymous denial pass. A synthetic inactive internal connection
was created through the real API, read, deleted, replayed and confirmed absent; temporary
scoped bearer token revoked. Retained receipts/audit contain no real credential/customer data.
New provider execution remains false. No real provider, customer or calendar mutation.

## Handbook

Six repository Knowledge articles synced and read back internally. Actual BookStack pages
417/419/420/425 and scoped API Management 449 were updated and exact requested content read
back. Page/chapter/book inheritance and existing staff-internal Editor view rights checked;
no ACL changed. The full guide is embedded in Overview 417 because the connector rejects
ordinary create/rename strings against its pattern. Existing-page edits/read-back work.
Native pending_push is not publication proof; employee OpenWebUI retrieval remains untested.
Existing unrelated handbook/source drift is not overwritten or called reconciled.

## Promotion and recovery

Only the explicit candidate manifest enters Main. Independent vendor/autoload and synthetic
SQLite environment keep candidate tests separate from shared Dev and production credentials.
Non-Nextcloud Swagger values and original bytes are preserved; only 23 new paths (36 methods)
and 47 component schemas are added. No Dev branch/index commit or unrelated work is promoted.
Future authorized production rollout requires only the exact additive migration, generated
Swagger and normal cache handling. No new cron, frontend build, package install or worker
change. Retain APP_KEY/receipts/mappings/claims/history on rollback. HR-2026-10-27-NEXTCLOUD-API
is Pending; Svein explicitly authorized Main merge with manual scenarios pending. These and
the existing calendar pilot human-review checks still gate production release/activation.
