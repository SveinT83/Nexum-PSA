# Intake API Main Promotion

Date: 2026-10-27. Scope: full current Intake browser/API operational parity.
Authorization: Svein Tore, "Godkjenner og merge til main".
Code PR: https://github.com/SveinT83/Nexum-PSA/pull/340
Merge: f8180e6efb696321f7bfa09863403ac11d840e33
Tested candidate: 1b0a931907a94ea62c59e132713b70dd175d6a38
Exact tree: 436b960755703d2ccbb4303e19cacc43c2371e05

The merged tree is byte-for-byte identical to the tested candidate. All 17 browser
rows and embedded flows map to 23 typed HTTP operations / 38 schemas, with exact
successful HTTP/schema read-back. Every pre-existing Swagger contract is preserved.

Dev affected verification: 912 tests / 48,851 assertions. Initial isolated Main:
760 / 9,773. Final Intake on both: 70 / 1,180. Refreshed Main compatibility after
Notification promotion: 247 / 7,518. Exact contract recheck: 1 / 158.
No failures or skipped tests. Scoped syntax and Pint pass.

Trusted HTTPS/native MySQL confirms active actor/token policy, draft create/read/
update, concurrent same-key single effect, encrypted replay, draft exclusion and exact
fixture/token cleanup. Only the additive Intake command migration ran on Dev.

Two Knowledge sources are published together in existing BookStack page 553 and exact
content/inherited access was read back. No grants changed. Native Dev BookStack
auto-sync is unconfigured; its future linkage is separate from this verified handbook
publication. Employee OpenWebUI retrieval is not claimed.

Main promotion is complete. Production deployment and migration were not performed.
INTAKE_API_PROVIDER_CUSTOMER_CREATION_ENABLED remains false by default. No customer
send, portal invitation, automation backlog or worker/scheduler activation occurred.

Human-review checklist HR-2026-10-27-INTAKE-API remains Pending. Svein Tore must perform
the listed form/review/routing/upload/public/permission/retry/handbook scenarios before
production deployment/release. Existing Reviewed history is retained.

See the verification plan for exact deploy/recovery commands and the Dev-only Pint
formatting incident. No unrelated dirty Dev code/formatting is in this Main release.
