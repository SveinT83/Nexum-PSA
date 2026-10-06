# Workday Slice 02 Verification

Date: 2026-10-02
Status: Done On Dev, default-off. Complete rollout and human review remain pending.
Reviewer: Svein Tore. Review entry: HR-2026-10-01-WORKDAY.
Authoritative checkout: /var/Projects/tdPSA, branch Dev; uncommitted working-copy changes.

## Delivered

The singular Workday module implements employee-owned list/detail/history, manual intervals and
breaks, description, draft saving, exact-version preview, explicit confirmation and corrections.
Browser and API share the same transaction/action. My Day links to the working employee surface
only when enabled; authorized admins can open the implemented activation settings.

A correction appends a draft and preserves the last confirmed snapshot until reconfirmation.
The owner and original record timezone never come from a delegated identity input. Standard
employee roles receive explicit own permissions; Admin/Superuser receive settings, with no
other-worker read/write bypass. Coordinator workload tokens cannot operate employee records.
The external NexumMCP adapter and real employee tool acceptance remain Slice 09.

Actual UTC elapsed minutes are independent of planned hours, billing increments and Task/Ticket
records. Break treatment is explicit. Overnight work, clock changes, own adjacent dates and
pending-correction reservations are validated. Workday writes have no Task/Ticket/billing effects.

## Implementation And Data

New files are under app/Modules/Workday:
- Actions/MutateWorkday.php: serialization, revisions, receipts, confirmation and correction.
- Queries/ReadWorkday.php and Models: owner-scoped snapshots/history.
- Support: access, time normalization and default-off settings.
- Controllers/Tech and Controllers/Admin; Views/Tech and Views/Admin.
- routes.php, Resources/Api/V1/WorkdayOpenApi.php, feature tests and two Knowledge articles.

Shared changes: explicit API loader, view namespace, tech permission map, ability catalog,
PermissionSeeder/RoleSeeder, My Day link, admin settings navigation, breadcrumbs and repository
Knowledge module registration. Existing unrelated shared-file changes were preserved.

Two additive migrations were applied only on Dev:
1. 2026_10_02_120000_create_workday_tables
2. 2026_10_02_120100_deploy_workday_permissions

Storage:
- workdays: unique employee/work_date, original timezone, optimistic version, current/confirmed
  revision pointers, expiry.
- workday_revisions: immutable snapshots, author, origin, correction reason and version.
- workday_previews: exact revision/version, expiry and consumption; no copied description.
- workday_mutation_receipts: actor, hashed request key/body, operation, exact persisted response
  and expiry. Receipts with Workday data expire with their work date.
- One common_settings row, workday/manual_workflow, initially disabled; retention fixed to 3.

The work-date deadline is midnight after the third anniversary in the original timezone
(leap-day anniversaries clamp to the valid calendar date). Corrections never move the deadline.
Expired records/receipts are not served. Automatic cleanup of owned rows/copies is Slice 08;
default-off activation is required until that delivery and review. User foreign keys retain
history rather than cascade-delete it with account removal. Schema rollback refuses retained data.

## Verification

Final focused Dev test run: **74 tests passed, 596 assertions**.

```bash
umask 0002
HOME=/tmp php artisan test \
  app/Modules/Workday/Tests/Feature/ManualWorkdayTest.php \
  app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php \
  app/Modules/UserManagement/Tests/Feature/UserPreferencesTest.php \
  app/Modules/Warroom/Tests/Feature/WarroomMyDayTest.php \
  app/Modules/Vault/Tests/Feature/VaultPermissionDeploymentTest.php \
  app/Modules/Integration/Tests/Feature/AiCoordinatorGovernanceTest.php \
  tests/Feature/LivewireFrontendRuntimeTest.php
```

The 26 Workday tests cover browser HTTP and API save/read/history/preview/confirm/correction,
real personal bearer authentication, scopes and permissions, foreign-owner and system/coordinator
denials, migration grants and preserved revocations, stale UI/API versions, exact idempotent replay,
expired previews, future-work confirmation rejection, clock changes, overlaps and billing isolation.

The tests use the existing isolated SQLite :memory: harness. They exercise conflicting browser/API
requests deterministically, not simultaneous browser processes. A separate read-only MySQL probe
confirmed that two independent connections serialize on the existing employee row used by the
action: the second lock timed out while the first held it; both transactions rolled back and no
business rows were written. Full concurrent employee/browser UX remains in human review.

Route inventory: 18 UI/API routes, of which nine are API operations. Generated OpenAPI succeeded
and all nine operations were read back from trusted Dev HTTPS /docs (HTTP 200). The unauthenticated
API returns 401; that is authentication smoke evidence, not a logged-in browser acceptance test.
Targeted Pint, PHP syntax and whitespace checks passed.

Live Dev schema/read-back after migrations: all four new tables exist and contain zero rows;
settings version 0, saved enabled=false, deployment_enabled=false, effective_enabled=false.
Explicit role grants match the agreed mapping. New module directories/files are readable by
the web process (group-readable directories, files 0644). No employee time fixtures were retained.

Evidence: /tmp/workday-slice02-66zgnjsr/ on Dev, including test logs, baseline copies,
published-openapi.json and mysql-lock-probe.json. No credentials were saved there.

## Deploy, Rollback And Remaining Review

The exact two migrations, optimize:clear and l5-swagger:generate were run on Dev. No new queue,
scheduler or asset-build step is required for this manual slice. Use umask 0002 for Artisan
commands which render views. Keep WORKDAY_ENABLED=false and the Workday installation choice off.

Future promotion must include the migrations and generated API contract, refresh the normal
deployment caches and verify web-process readability. Svein owns commits, Main promotion and
production deployment; none was performed here. Unrelated pending migrations were not run.

HR-2026-10-01-WORKDAY remains Pending and blocks Main promotion/merge, production migrations,
deployment and activation. It does not block approved Dev development/test migrations.
Svein must review manual entry, breaks, overnight dates, correction/history, stale-browser UX,
desktop/mobile and keyboard behavior when the complete Dev pilot is ready. Real MCP execution,
cross-employee oversight, reminders/delivery and retention rehearsal remain their later slices.
Passing tests does not complete any manual checklist.

Rollback is to disable the feature and retain data. Do not delete revisions or apply a destructive
down migration. Source records and unrelated account/security changes must remain intact.

Next: Slice 03, simple absence with consistent private Calendar projection. Slices 03-09 remain
Ready under the original approval. No holiday application/balance/rota or Tripletex runtime was
added. No website publication handoff is produced for this partial, disabled rollout.
