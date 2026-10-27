# Knowledge And Documentation API Baseline

Date: 2026-10-27. Status: Historical pre-implementation baseline. RFC was approved and implemented afterward;
current evidence is in knowledge-api-verification.md/.json.
Authoritative checkout: /var/Projects/tdPSA, branch Dev. Initial dirty paths: 1338.
Existing work was retained. No commit, push, migration, provider execution or deployment.

## Fresh Evidence
Knowledge: 37 browser rows, 20 API rows / 24 HTTP operations, HEAD excluded.
Documentation: 30 browser rows, 30 API rows / 33 HTTP operations.
PR #329 is independently read through GitHub and reports merged. Documentation's current
runtime contract/parity tests pass in the initial affected run. Production is not updated here.
Knowledge's revision and BookStack workflows (#266/#276) are implemented on Dev;
HR-2026-09-04-001 and HR-2026-09-03-003 are still Pending and retain their existing gates.
Open #265/#261/#275 remain separate future authorization/retrieval/lifecycle work.

## Initial Tests
Command: HOME=/tmp php artisan test app/Modules/Knowledge/Tests
app/Modules/Documentation/Tests/Feature/DocumentationApiContractTest.php
app/Modules/Documentation/Tests/Feature/DocumentationApiParityTest.php
Result: 74 passed, 1 failed, 1450 assertions. The failure expected three Documentation
source articles after the fourth documentation-api.md guide had been added. The correction
updates the expected count and verifies the new guide identity, module and complete content.
A final affected rerun must be recorded below before any complete-baseline claim.

## Pending Implementation
Concrete RFC, proposed ADR, sequential slices and every browser-row coverage map are adjacent.
AGENTS.md and docs/processes/rfc-process.md require RFC approval before Level 3 implementation.
The narrow source-sync regression repair is Level 1 and does not approve or implement the API.

## Final Verified Baseline
The full initial command was rerun with the same Knowledge/Documentation tests plus
app/Modules/Integration/Tests/Feature/BookStackRevisionSafetyTest.php.
Result: **81 passed, 1527 assertions, zero failures/skips**, 66.45 seconds.
The focused source-sync regression separately passed 1 test / 13 assertions.
The exact changed PHP file passes Pint and php -l. Scoped git diff --check passes.
Every one of 37 unique browser rows has a proposed API mapping; the 3 embedded
Livewire/Integration surfaces are separately recorded. All six new planning files
are valid UTF-8 and whitespace-clean. Only new planning files were copied locally
for review; their SHA-256 hashes match Dev. No local runtime verification or code
implementation was used. The existing shared Dev index and unrelated work remain.

Changed application test: Knowledge/Tests/Feature/KnowledgeArticleTest.php,
only repository_documentation_sync_includes_documentation_docs. It expects the
four actual Documentation sources and verifies the API guide's exact identity,
module and source body, preserving the other contributor changes in that file.
Application behavior, APIs, Swagger and migrations were not changed by this planning work.
This describes the historical planning checkpoint. The subsequent approved implementation and
exact Dev migration/deployment gates are recorded in knowledge-api-verification.md/.json.
