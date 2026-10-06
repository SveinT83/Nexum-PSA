# Workday Slice 04 Verification

Date: 2026-10-02
Status: Done On Dev, default-off. Complete pilot and human review remain pending.
Owner: Codex. Intended reviewer: Svein Tore.
Review entry: HR-2026-10-01-WORKDAY.
Authoritative checkout: /var/Projects/tdPSA, branch Dev; uncommitted working-copy changes.

## Delivered

Employees can discover and attribute their own Task/Ticket time and explicitly selected Calendar
evidence within a saved actual workday. The browser and API call the same versioned mutation.
Eight actual hours with two source hours remains eight, with two attributed and six unallocated.

Task entries retain recorded, estimated or unknown provenance. Direct Ticket entries are separate;
task_id mirrors and task_billing rows are excluded. Calendar evidence remains planned and needs
explicit employee verification, as do estimates and unknown source types. Date-only entries do
not invent intervals. Optional placements must fit actual work, exclude unpaid breaks, match
elapsed selected minutes and not overlap another numeric allocation. Concurrent activity labels
belong in the existing work description. Selected recorded intervals additionally bound placement.

Each source has a stable identity and content fingerprint. Source permissions and employee token
abilities are checked at discovery, allocation save, preview and confirmation. Changed, deleted,
cancelled, declined or inaccessible sources require explicit reconciliation. Calendar uses its
existing visible-calendar and private-detail policy. Absence projections never suggest work.

The immutable Workday snapshot stores minimal source identity/fingerprint, original basis,
selected minutes, source date, optional placement and employee acknowledgement. Source titles,
notes, descriptions and attendee lists are not copied into Workday history or mutation receipts.
Current source detail is read live under source authorization. Later staleness is separate from
the unchanged last confirmed snapshot.

All numeric attribution stays inside actual minutes and source capacity. Queryable reservation
rows mirror each immutable revision. The current draft and last confirmed revision reserve the
larger per-source amount for each workday, so pending corrections cannot release confirmed
capacity for another day. Mutations reuse the employee row lock and one database transaction.
Task/Ticket/Calendar source records and all billing/timebank data remain unchanged.

Discovery is paginated with explicit complete/partial/unavailable status. Time discovery exposes
at most 500 entries, per_page at most 50. Calendar limits ordinary events to 200, candidate series
to 100 and final results to 500, honoring the existing recurrence expander and reporting technical
iteration truncation. Calendar total is an observed count, potentially a lower bound.
Missing source evidence never proves missing work.

## Files Changed

New source adapters:
- app/Modules/Task/Queries/OwnWorkdayTime.php
- app/Modules/Ticket/Queries/OwnWorkdayTime.php
- app/Modules/Calendar/Queries/WorkdayEvidence.php

New Workday files:
- Queries/SourceEvidence.php and Actions/ReconcileSources.php.
- Controllers/Tech/SourceController.php and Views/Tech/sources.blade.php.
- Resources/Api/V1/SourceOpenApi.php.
- Tests/Feature/SourceReconciliationTest.php.
- Docs/knowledge/source-reconciliation.md.

Updated Workday files:
- Actions/MutateWorkday.php and Queries/ReadWorkday.php.
- routes.php; Views/Tech/show.blade.php and snapshot.blade.php.
- Resources/Api/V1/WorkdayOpenApi.php.
- Existing Workday SOP/API Knowledge articles.

Shared changes:
- app/Http/Middleware/EnforceTechRoutePermission.php and config/breadcrumbs.php.
- Task, Ticket and Report Knowledge guidance.
- Migration below; generated storage/api-docs/api-docs.json.
- TODO, Slice 04/index, implementation plan, RFC, ADR and human-review status.
- Slice 08 inventory explicitly includes the new reservation table.

Unrelated shared Dev work was preserved. No commit, push, Main change or external provider write.

## Verification

Broad affected-module run: **269 passed / 2220 assertions**.
Final source suite after adding real bearer and generated-contract checks:
**22 passed / 203 assertions**.
Together: **271 distinct passing tests / 2236 latest assertions**.

~~~bash
umask 0002
HOME=/tmp php artisan test \
  app/Modules/Workday/Tests/Feature/SourceReconciliationTest.php \
  app/Modules/Workday/Tests/Feature/ManualWorkdayTest.php \
  app/Modules/Workday/Tests/Feature/AbsenceTest.php \
  app/Modules/Task/Tests/Feature/TaskModuleTest.php \
  app/Modules/Ticket/Tests/Feature/TicketModuleTest.php \
  app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php \
  app/Modules/Report/Tests/Feature/WorklogOpenApiTest.php \
  app/Modules/Commercial/Tests/Feature/CommercialWorklogTest.php \
  app/Modules/Integration/Tests/Feature/AiCoordinatorGovernanceTest.php
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature/SourceReconciliationTest.php
~~~

New coverage includes source/billing exclusion, unchanged actual/source values, own-entry and
foreign Superuser boundaries, token source abilities, real bearer authentication, estimates/
unknown acknowledgement, optional interval constraints, splitting/merging/removal, excluded
breaks, cross-day capacity with a pending correction, idempotent retry and stale version,
source edits/deletion after preview, calendar selection/recurrence/cancellation/privacy,
permission removal, pagination/truncation, draft omission versus explicit clearing, browser
HTTP read/write/clear and generated OpenAPI route/schema/scope parity.

Initial checks found a fixture comparing a non-refreshed model with its database defaults,
and absent machine-readable scope metadata in the new OpenAPI annotations. Both were fixed;
the final source suite is green.

The harness is isolated SQLite :memory: with synthetic fixtures. Cross-day races use deterministic
stale/competing requests; this slice reuses the worker lock previously checked on MySQL in Slice 02.
This does not establish simultaneous browser acceptance. No full application test suite was run.

Targeted Pint, PHP syntax and scoped whitespace/readability checks passed. The generated public
contract has both new routes, allocation inputs, provenance, reconciliation and completeness
schemas. Trusted Dev HTTPS /docs read-back verifies publication. Anonymous source API denial
is not authenticated UI evidence; the authenticated browser evidence above is Laravel HTTP tests.
No real employee browser interaction or real external MCP write was claimed.

Evidence directory on Dev: /tmp/workday-slice04-pwde5hkn/.
Includes baseline copies, test logs, published-openapi.json, contract checks and sanitized runtime
read-back. No credentials or real employee fixtures were retained.

## Dev Migration And Deployment

Applied only on Dev after passing relevant tests:
2026_10_02_160000_create_workday_source_allocations.

This adds workday_source_allocations with a restrictive revision foreign key and source/revision
index. There is no foreign key into source domains. Schema rollback refuses retained allocation
data. Existing source records need no migration or backfill. The table is empty in live Dev.

php artisan optimize:clear and php artisan l5-swagger:generate ran on Dev. No asset build, queue
reload or scheduler change is required for this slice. No new permissions or token abilities were
introduced; the existing Workday and source-domain grants are enforced together.

Both activation switches remain off, effective_enabled=false and settings version=0. All Workday
and absence tables remain empty. Retention cleanup must later remove allocation rows before their
revisions; it remains Slice 08 and must be implemented before normal rollout.

## Review And Next Work

HR-2026-10-01-WORKDAY remains Pending. It blocks merge/promotion to Main, production migration,
deployment and activation; approved Dev-only work may continue.

Svein explicitly requested continuing the approved slices and being asked for manual verification
only when the complete pilot actually needs it. Do not request another partial review now.
The eventual checklist includes mixed-source totals, stale/revoked source handling, correction,
private source access, keyboard/mobile use and actual employee MCP read-back. No human approval
has been recorded.

Next: Slice 05, confirmed-time oversight through dedicated reusable permissions. Slices 05-09
remain Ready in sequence. Profile reminders, explicit Task conversion, retention and real employee
MCP acceptance remain later slices. Future Tripletex transfer remains deferred.

Rollback is feature-off while preserving confirmed/source data. No production activation or public
website announcement is appropriate for this incomplete disabled rollout.
