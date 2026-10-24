# Sales API Main promotion (2026-10-25)

Status: Human Reviewed; candidate verified, Main merge requested.
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
