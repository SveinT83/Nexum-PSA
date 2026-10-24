# Sales API Main promotion (2026-10-25)

Status: Merged To Main / Human Reviewed; production not deployed.
Human reviewer: Svein Tore. Explicit acceptance: "Godkjent. Merg til main".
Review record: HR-2026-10-24-SALES-API (Reviewed).

Base: origin/main 3c27445a1c269d8ed4839bec2c6b6111d19b4169.
Candidate: codex/sales-api-20261025 in an isolated worktree on the Dev server.
The ordinary Dev HEAD/index and unrelated dirty work are preserved.

All Sales code is the verified Dev delivery. Shared ability-catalog/API loader edits
were merged against the pre-implementation baseline so unrelated Dev changes are not
promoted. Shared TODO/human-review/handbook files receive only Sales sections.
The existing 29-line DomainApiInput validator is a necessary additional dependency:
it was already present on Dev but absent from Main. No Ticket/Task implementation is
included. An initial candidate test exposed this missing dependency; it was added and
the complete 50-test Sales rerun passed (2025 assertions, 60.80 seconds).

All 50 changed PHP files pass syntax/Pint. Generated Swagger contains all 75 Sales
operations. The 433-test Dev verification remains documented in the original report;
a fresh broad candidate regression run is recorded below when complete. No test failure
is approved as deferred. Migration is additive and has been verified on Dev; merging
source does not apply it to production.

Production deployment, migrations, runtime activation, real customer messages/provider
writes and inbox confirmation are separate and were not requested by this Main merge.
Retain command/delivery evidence and the app key on future rollout/rollback.

## Final candidate verification (2026-10-25)

PR: [#311](https://github.com/SveinT83/Nexum-PSA/pull/311).
Source commit: 9a7d77988ce8cf522daaccca1e69d54ba37a5d15.
Fresh complete affected Main-candidate run: **361 tests / 4810 assertions passed**,
317.17 seconds. Includes Sales, Ticket, Storage, Client, Contact, Customer Portal,
Integration/Swagger and Tripletex customer-profile regressions. The long provider
numbering suite was already verified in the Dev 433-test run; it was not repeated.
No remaining failing verification is deferred. The final 50-test Sales rerun passed
2025 assertions separately; totals overlap and are not summed.

All 50 changed PHP files pass syntax and scoped Pint. Generated Swagger contains all
75 Sales operations. Non-Sales Swagger paths are byte-for-byte structurally identical
to the current Main document; no other domain operation was changed or removed.
The generated document is tracked and included in this PR.

BookStack approval status independently read back: pages 508/428 revision 9,
449 revision 123 and historical pointer 507 revision 3. Source docs and internal
Sales Knowledge articles were synchronized; no global unrelated documentation push.
Production rollout is still outside this request. GitHub PR #311 is the authoritative
merge-state and merge-commit record; no production availability is implied by merge.

Main implementation merge independently verified on 2026-10-25: [PR #311](https://github.com/SveinT83/Nexum-PSA/pull/311),
commit `81812c5b8989dcb3522245b87e69390fb57a2141`. GitHub and origin/main agree; the complete merge tree
equals the tested final candidate e5d4c989abc8bf64a39235d83672bcce696f0f17.
Dev HEAD/index and unrelated work are preserved. Production deployment and activation
were not performed.
