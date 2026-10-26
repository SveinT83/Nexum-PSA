# Storage Main promotion, 2026-10-26

Status: Reviewed by Svein Tore; merged to Main in PR #326; production unchanged.
Reviewer and promotion owner: Svein Tore.
Explicit approval: Godkjenner. Merg til main.
Human-review entry: HR-2026-10-26-STORAGE-API, Reviewed.

Candidate starts at origin/main c3999c68c041ae81e827b7352cbb1502932a909f.
Only the explicit Storage API manifest and Storage sections of shared files are included.
The existing AllocatePurchaseOrderNumber, StorePurchaseOrder and
SavePurchaseOrderRequest changes are included as the minimum dependency for optional
purchase-order numbers already exercised by the API tests on Dev. Existing explicit
numbers remain accepted. The contributor's form change is excluded. This does not
complete #286: immutability, canonical numbering/migration policy and remaining
acceptance are separate. The four contributor source files in shared Dev are unmodified.

Production deployment and provider activation are outside this authorization.
The exact additive storage_api_commands migration is required at a future approved
deployment; retain APP_KEY and command receipts on rollback. No frontend build or
worker/scheduler activation is introduced. Both API execution switches remain false.

## Candidate Verification

614 tests / 7654 assertions passed, no failures/errors/skips (352.54 seconds).
All 39 scoped PHP files pass syntax and Pint. Runtime and regenerated Swagger have
78 Storage HTTP operations and 132 StorageApi schemas. The generated contract changes
only Storage paths/schemas. PHP hashes are unchanged since candidate verification.
The candidate includes exactly the 57-file allowlist; unrelated shared work is excluded.
JUnit: /tmp/storage-main-candidate-20261026.xml. Log: matching .log.

The shared-Dev 615-test run additionally included the unrelated uncommitted Ticket
regression ticket_show_prioritizes_an_older_unresolved_task_over_recent_completed_tasks
(63 assertions). It is not present on Main and is deliberately excluded from this
Storage promotion; no test was skipped in the 614-test candidate run.

## Independently Verified Main Merge

PR: https://github.com/SveinT83/Nexum-PSA/pull/326
Candidate commit: f4b0da9a90a590c9a9b75fc718754e8f804b4669
Main merge: ea4d0ac96db5983103d1688aeef4cf8f87a833ca
Tested and merged tree: 3581f2fb5aaafe44cbeb688bf368b333db17b771
GitHub reports merged; a fresh origin fetch confirms exact tree equality.
No GitHub status checks or Actions runs were configured for this PR head.
Shared Dev branch remains Dev; unrelated dirty work and all four #286 files are
preserved byte-for-byte. HR-2026-10-26-STORAGE-API is Reviewed by Svein Tore.
No production deployment, provider activation, grants or external ordering occurred.
Remaining owner action: separately authorize production rollout when desired;
apply the one exact additive migration, normal caches and Swagger regeneration.
Automatic handbook sync/source drift and #286 remainder retain their existing owners
and follow-up entries. The employee handbook remains accurately labelled Dev-verified.
