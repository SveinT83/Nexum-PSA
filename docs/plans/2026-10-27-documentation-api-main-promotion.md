# Documentation Main promotion, 2026-10-27

Status: Reviewed and merged to Main in PR #329. Production unchanged.
Owner: Codex. Human reviewer and merge authorizer: Svein Tore.
Named human acceptance: Godkjenner, 2026-10-27.
Explicit Main merge authorization: Merge til main, 2026-10-27.
Human-review entry HR-2026-10-27-DOCUMENTATION-API is Reviewed.

## Scoped Candidate

Base: origin/main 7abf301e7a4f2530378c980e6dca4a90de1f58ed.
Branch: codex/documentation-api-20261027.
Runtime worktree: /tmp/nexum-documentation-api-main-20261027.
The authoritative Dev implementation remains /var/Projects/tdPSA on Dev.
Only the 53-file Documentation allowlist plus this promotion record is packaged.
Main and shared Dev files were compared before selection; the domain-owned edited
files match the pre-implementation Main baseline. Shared API catalog, route loader,
TODO/review register and Knowledge sources include only Documentation additions.

All 33 HTTP operations and 40 typed schemas are included. Runtime PHP reflection
resolves App and Tests classes to the candidate, using an independent vendor copy
with the identical composer.lock and a synthetic isolated SQLite test environment.
No production credentials, business database, queued execution or providers are used.
Non-Documentation Swagger operations and schemas are unchanged; the generated
candidate contributes only Documentation path/schema definitions.

Related Documentation, Storage, template and portal human-review entries were
read before promotion; relevant entries are Reviewed. This scope does not promote
unreviewed unrelated work or implement the open #275 revision/publication workflow.

## Candidate Verification

Final affected suite: 545 passed / 8135 assertions, zero failures/errors.
One existing SupplierOrderIdentityMariaDbContractTest is skipped because dedicated
MariaDB identity-contract credentials are not configured. Documentation native
MySQL locking/uniqueness/encryption/rollback was independently verified on Dev.
PHP syntax and Pint pass for all 37 scoped PHP files.
The runtime contract check and successful JSON response validation cover 33 HTTP
operations and all 40 referenced schemas. JUnit: candidate-junit.xml; log: candidate-tests.log under the evidence directory.
The 54-file allowlist and all tested PHP hashes are verified; no unrelated file is staged.
Evidence directory: /tmp/nexum-documentation-main-20261027.

The all-Integration/Tripletex test work is outside this change. The uncommitted
AiToolCatalogBoundaryTest exists on shared Dev but not Main and is excluded;
unrelated missing tests are not copied into the candidate or counted as passing.
Actual final test counts and any configured optional skip remain explicit below.

## Production And Recovery

This merge does not deploy production or activate providers, workers, schedulers,
credentials or broader API grants. A future separately authorized rollout requires
2026_10_27_010000_create_documentation_api_commands.php and normal cache/Swagger
handling. No frontend build or package installation is introduced.
Retain APP_KEY and encrypted command receipts. Employee handbook publication is
verified separately from deployment and employee assistant retrieval.

## Merge And Preservation

The PR head must equal the verified commit. After merge, fetch origin/main and
compare the complete merged tree with the tested candidate tree.
The shared Dev dirty-file hashes and staged index must remain unchanged except
for this task's explicit coordination/status updates. No shared Dev commit/push,
unrelated revert, cleanup or production deployment is authorized by this promotion.

## Verified Main Promotion (2026-10-27)

Svein Tore explicitly authorized "Merge til main". PR #329 is merged:
https://github.com/SveinT83/Nexum-PSA/pull/329
Main merge: c6249f422f74abe333a9b0c95d460adeb7193b68
Tested and merged tree: 64d7176610701a931bbf198787a711f1b17da071; exact equality independently confirmed by fresh fetch.
Isolated Main candidate: 545 passed / 8135 assertions, zero errors/failures.
One existing Storage MariaDB identity-contract test skipped for missing dedicated
test credentials. All 33 typed HTTP operations and 40 schemas verified; 37 PHP
syntax/Pint checks pass. Shared Dev code, unrelated changes and staged index preserved.
HR-2026-10-27-DOCUMENTATION-API remains Reviewed. No Main action remains.
Production deployment and provider/worker/runtime activation remain separate.
This follow-up changes documentation/status only; application tests were not rerun.
