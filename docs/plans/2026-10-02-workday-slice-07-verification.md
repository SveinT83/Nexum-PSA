# Workday Slice 07 Verification - Explicit Internal Task Conversion

Date: 2026-10-02. Status: Done On Dev, default-off.
Owner: Codex; product/reviewer: Svein Tore.
Human-review checklist: HR-2026-10-01-WORKDAY, Pending. It blocks Main promotion/merge,
production migration/deployment and activation. The complete pilot is not ready for manual
review; Svein requested continued sequential delivery without another partial review invitation.

## Delivered behavior

A saved workday offers **Create internal Task from saved activity**. The employee selects a saved
interval or a smaller range, reviews a title/description and sees the exact target and minutes.
Excluded breaks are deducted. The explicit create command delegates to Task-owned guards and
the existing StoreTask/RegisterTaskTimeEntry actions for a standalone employee-owned internal
Task. It is assigned to that employee, remains open, and has one non-billable actual time entry.

Source attribution replaces unattributed minutes; actual workday time does not increase.
Several ranges separated by excluded breaks reference one date-level Task time entry. One
continuous range also saves its actual start/end on the Task entry. Existing Task/Ticket time is
linked through the established Sources/allocations workflow. Unplaced/overlapping allocations,
source drift and unavailable source grants block conversion. Current and last-confirmed source
placements remain protected while a correction is pending.

The preview lasts at most 30 minutes and binds the exact revision, payload and internal target.
Creation, Task/time activity, source attribution, a new draft revision, consumed preview and
mutation receipt share one transaction under the existing employee-row lock. Same-key retries
return the original receipt; competing stale previews fail without writes. Converted ranges remain
reserved against repeated creation after allocation removal or movement to an adjacent overnight
work date. A confirmed day requires a correction reason, preserves its prior confirmation and
receives a draft. No automatic completion, day confirmation, Ticket billing or Commercial
consumption is invoked.

Preview/create/GET read-back share employee ownership and Task permissions. API requires
workday-task-conversion.write plus tasks.read/create/update; no existing token was edited.
System/coordinator credentials cannot delegate employee identity. Unknown actor, Client, Ticket,
billing and minutes fields are rejected. Task descriptions follow ordinary internal Task visibility,
which the preview states explicitly.

## Changed files and domain ownership

New:
- app/Modules/Task/Actions/CreateInternalTaskFromWork.php
- app/Modules/Workday/Actions/ConvertActivityToTask.php
- app/Modules/Workday/Controllers/Tech/TaskConversionController.php
- app/Modules/Workday/Resources/Api/V1/TaskConversionOpenApi.php
- app/Modules/Workday/Views/Tech/task-conversion-form.blade.php
- app/Modules/Workday/Views/Tech/task-conversion-preview.blade.php
- app/Modules/Workday/Tests/Feature/TaskConversionTest.php
- app/Modules/Workday/Docs/knowledge/task-conversion.md
- database/migrations/2026_10_02_210000_create_workday_task_conversion_previews.php

Updated runtime:
- Workday Actions/MutateWorkday.php, routes.php and Views/Tech/show.blade.php.
- app/Http/Middleware/EnforceTechRoutePermission.php.
- Integration Support/ApiAbilityCatalog.php.
- Generated storage/api-docs/api-docs.json.

Updated Knowledge: Workday overview/API and Task time/activity. Updated coordination: TODO,
RFC, ADR, implementation plan, Slice 07/index, Slice 08 copy inventory and human-review register.
Knowledge articles are prepared for BookStack sync at approved rollout. No external publication.

Shared Dev preflight found no competing Workday implementation. The live open Workday Issue
search returned no matches. Existing unrelated Vault, Knowledge, Email and other dirty files were
preserved. No commit, push, Main promotion, production deployment or external message was sent.

## Tests and evidence

**187 distinct tests passed / 1618 assertions**, including **20 conversion feature tests**.

Main regression: **182 tests / 1424 assertions**:

~~~bash
umask 0002
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature app/Modules/Task/Tests/Feature
~~~

API-key administration/catalog: **5 tests / 194 assertions**:

~~~bash
HOME=/tmp php artisan test app/Modules/Integration/Tests/Feature/IntegrationModuleTest.php \
  --filter='admin_can_open_api_management|admin_can_create_scoped_api_key|api_key_creation_requires_explicit_scopes|ability_catalog_has_explicit_access_metadata|broad_existing_api_keys_are_flagged'
~~~

The initial conversion run was 17 tests / 225 assertions; it overlaps the final run and is not
counted twice. A test command initially referenced a nonexistent Ticket-specific file and ran
no tests; the corrected selection uses the verified Task suites, including existing Ticket-owned
billing and completion regression coverage. No failing runtime test is deferred.

Coverage: authenticated Laravel browser form/preview/create/read-back, escaped non-ASCII
description, real employee bearer access, required scopes and permissions (including receipt
replay after revocation), foreign owner/actor/billing spoofing, system/coordinator rejection,
rollback after Task/time creation on late revision failure, same-key retry, competing previews,
source drift, unavailable target, correction history, unpaid breaks, DST/overnight minutes,
adjacent-date duplicate prevention, expired preview/retention and explicit employee acceptance.
Existing Task completion reuses the created actual entry; billing mirrors/Commercial consumption
remain empty. All existing Workday and Task tests passed.

Tests use the isolated SQLite in-memory harness. Deterministic conflicting writes are not a
claim of simultaneous browser acceptance. A fresh read-only native MySQL probe used two
independent connections: the second employee-row lock timed out while the first held it.
Both transactions rolled back, with zero business writes. This verifies the shared locking
primitive used by MutateWorkday; a full concurrent browser/MCP workflow remains human review.

Trusted Dev HTTPS /docs returned the three conversion operations with all four required scopes,
bearer security and four schemas. PHP syntax, scoped Pint, UTF-8, new-code whitespace and
web-readable file checks passed. Existing unrelated TODO trailing whitespace was left intact.
No full application suite, live logged-in browser/mobile review or real employee MCP action.

Evidence directory on Dev: /tmp/workday-slice07-pu7liz4o/.
Includes baseline hashes/status, test logs, before/after sanitized counts, native lock probe,
published API contract and implementation hashes. Temporary bootstrap/probe scripts were removed
after verification; no credentials or employee fixtures were retained.

## Dev migration and operation

Applied only on Dev:
2026_10_02_210000_create_workday_task_conversion_previews.

The new workday_task_conversion_previews table stores exact activity/target payload, original
revision/version, 30-minute preview expiry, consumed creation result and original retained_until.
Its consumed ranges support duplicate prevention even after attribution removal. It is owned by
Workday and must be purged before revisions because of its restrictive revision foreign key.
Slice 08 inventory includes this table and conversion mutation receipts. Created Task/time/activity
records remain source-domain data and are not deleted by Workday retention.

Read-back: the new table and all eleven prior Workday tables are empty; Tasks, Task time,
Ticket time, Commercial consumption and personal API-token counts remain zero. Both Workday
switches remain false, saved settings version 0. No normal employee activation or notification.

Ran optimize:clear and l5-swagger:generate with umask 0002. No asset build, scheduler change or
worker restart is required by this synchronous slice. Future rollout must apply this exact
migration, regenerate OpenAPI and refresh normal deployment caches. Existing reminder workers
still need the previously documented reload before approved activation. No unrelated pending
migration was applied.

Rollback: keep Workday off and preserve the table/receipts; the down migration refuses retained
records. Code rollback must not delete Task-owned data or prior confirmed revisions.

## Remaining work

HR-2026-10-01-WORKDAY stays Pending. When the complete pilot is ready, verify exact description
and visibility, paused intervals, preview/create/retry across tabs, confirmed-day correction,
source drift, keyboard/mobile behavior and employee MCP read-back. Passing tests is not human
approval. Main/production remain Svein's responsibility.

Next: Slice 08, three-year retention and restore handling, then Slice 09 employee MCP and complete
pilot verification. Tripletex remains future scope. No website announcement is appropriate for
this partial, disabled feature.
