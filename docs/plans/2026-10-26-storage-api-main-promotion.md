# Storage Main promotion, 2026-10-26

Status: Reviewed by Svein Tore; isolated Main candidate verified, ready to merge.
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
