# UserManagement API Verification

Status: Verified on Dev and merged to Main in PR #353; production not deployed; human review Pending.
Owner: Codex. Named reviewer/release owner: Svein Tore. Date: 2026-10-27.

## Current verified coverage

All 49 original browser rows and embedded role-permission workflows map to concrete
operations. Contract: 56 HTTP operations, 77 dedicated schemas and seven reachable
shared Calendar/Work Plan schema types. Every successful method/path was exercised and
its actual JSON validated against Swagger on both Dev and the isolated Main candidate.

- Final Dev affected batch: 236 passed / 3120 assertions, zero failures/errors/skips.
- Final Dev affected changed-flow checks: 26 passed / 907 assertions, zero failures/skips.
- Isolated Main affected batch: 184 passed, one existing optional provider skip; 2590 assertions,
  zero errors/failures. The separate protected UserSecurity provider is not installed on Main.
- Final Main changed-flow checks: 37 passed / 975 assertions, zero failures/errors/skips.
- Final shared MFA/session policy: Dev 79 passed / 990 assertions; Main 185 passed / 2616 assertions, one existing optional provider skip. No errors/failures.
- Scoped native syntax/Pint/whitespace checks pass. Final immutable file hashes and exact
  candidate/promotion refs are recorded in the linked Main report before/after merge.

The complete current broad Dev rerun resolves its initial two test expectation failures.
A transient concurrent Warroom migration edit interrupted one later fresh test setup;
its syntax was independently valid and the exact changed-flow rerun passed. No foreign
work was rewritten or deferred. These historical failures are not outstanding blockers.

Latest Main integration (including Marketing): 90 passed / 2496 assertions; one existing optional protected-provider skip, zero errors/failures.

## Native, HTTP and handbook evidence

The exact additive migration 2026_10_27_030000_create_user_management_api_commands.php
ran on Dev in batch 42. Native MySQL probes prove encrypted exact read-back, unique
actor/token/key replay identity, rollback and two-connection mutex exclusion. Probe
tables are removed. No unrelated pending migration or business backfill was applied.

Trusted HTTPS: eleven internal reads return 200/no-store; anonymous users return 401;
public SSO discovery returns 200; published Swagger contains the new profile contract.
Temporary read-only verification keys are deleted and independently read back as absent.
No real invitation, customer test email, permission or account-setting mutation was used.

Five exact Knowledge articles match authoritative source and remain internal/published.
Actual BookStack 517/384/449 were independently read back. Guide 517 matches the complete
source including the narrow creation form; 384/449 retain unrelated prior text and have
only scoped additions. Page/chapter inheritance plus Viewer book/chapter/page-view-all
rights are verified unchanged. Native pending_push is not publication proof; employee
assistant retrieval is not tested. Existing connector search-validator/source-drift
follow-up remains separate. No sync/auth/provider runtime was enabled by this work.

## Candidate scope and recovery

The candidate contains only this API change and its minimal existing-cutover check.
Unpublished Dev AccountSecurity/UserSecurity/Vault provider/adapter implementations are
excluded. Standard Composer dependencies are reused only through private candidate
metadata, with explicit APP_BASE_PATH and controller reflection proving isolation.
Shared Dev generated vendor files are hash-verified unchanged.

Stored Swagger is a scoped AST overlay: all 56 owned operations, 77 schemas and reachable
shared types match the tested generated document; every unrelated stored operation/schema
is preserved. The API catalog adds exactly 17 abilities with no duplicate entries.

Preserve APP_KEY and committed receipts. Reconcile unknown post-commit/dispatch outcomes;
do not drop live receipts/audit tables to force retry. Avatar updates retain old files
until commit and compensate staged uploads on rollback. Queued is not inbox delivery.

After a separately authorized future production deploy, run only the exact additive
migration, regenerate Swagger with php artisan l5-swagger:generate and clear normal
caches with php artisan optimize:clear. No frontend build, queue/scheduler replacement,
role grant, protected auth/Vault cutover or provider activation is required/authorized.

HR-2026-10-27-USERMANAGEMENT-API is Pending. Svein Tore's explicit implementation and
Main merge authorization is not human scenario review. These checks plus existing SSO
review gates still apply before production release/activation. See the promotion report
for the actual final refs; no production deployment is included.

Final Relationship privacy integration on current Main: 24 passed / 916 assertions, no skips/errors/failures; both diagnostic owners are retained. Final bootstrap formatting has identical executable PHP tokens.
