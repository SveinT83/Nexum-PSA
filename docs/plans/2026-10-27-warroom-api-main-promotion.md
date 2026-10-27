# Warroom Main Promotion

Status: Candidate Verified / Main Merge Authorized / PR Pending
Date: 2026-10-27
Owner: Codex
Authorization: Svein Tore explicitly requested "Merge til main".
Human review: HR-2026-10-27-WARROOM-API, Reviewed by Svein Tore.

## Scope And Isolation

Base: origin/main 86b82ed8625ff709c5a753b62614f22b46114b35.
Candidate: codex/warroom-api-promotion-20261027, isolated server worktree.
The authoritative dirty Dev checkout remains on Dev at 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.
All 28 verified Warroom module/source hashes match the accepted Dev snapshot.

Only Warroom, its exact additive migration, six scoped lines in ApiAbilityCatalog,
Warroom-specific coordination/evidence and generated Warroom Swagger are included.
Existing Main TODO/review entries are preserved; other domain source and API
contracts are unchanged. Main tag metadata is retained, with only Warroom added.
The existing separate Warroom-named worktree/branch was preserved, not overwritten.

## Candidate Verification

- Complete Warroom candidate suite: 31 passed / 619 assertions, no failures/skips.
- Candidate runtime, token abilities, exact actor permissions, all seven successful
  HTTP results and error response bodies match the typed OpenAPI contracts.
- 22 PHP files pass syntax checks; scoped Pint and git diff whitespace checks pass.
- Swagger: six paths / seven operations / 25 schemas; non-Warroom paths/schemas and
  existing tag metadata are unchanged.
- All external class dependencies already exist on Main; installed vendor packages
  match Main composer.lock. Autoloading was rebuilt inside the candidate.
- Test runtime is isolated SQLite :memory:. Native MySQL/HTTPS/handbook evidence
  remains the independent verified Dev evidence, not a production claim.

The 31-test Main run took 475.53 seconds on the loaded shared server. The full
application-wide suite was not rerun on the candidate. The accepted Dev evidence
includes the earlier 343-test and 189-test affected regression groups.

## Independent Review Gates Preserved

UserManagement API remains Pending under its existing separate Main authorization.
Mail stale-placement/account-save/conversation-read checks, Workday and the
Nextcloud calendar pilot retain their existing Pending/In Review statuses.
Warroom acceptance does not approve these unrelated workflows, widen access,
activate providers or permit production deployment.

## Deployment

Production is not deployed, migrated or activated by this merge.
A later authorized production release needs the exact
2026_10_27_110000_create_warroom_api_commands.php migration, optimize:clear and
l5-swagger:generate. No frontend build or queue/scheduler/provider activation.
Preserve APP_KEY and durable command receipts during recovery.

## Merge Read-Back

Pending: push the verified scoped candidate, create/attach its PR, merge with an
expected head SHA, fetch Main and compare its tree with the tested candidate.
Record the resulting PR and commit separately from production state.
