# Workday direct-entry verification

Date: 2026-10-04. Authoritative checkout: /var/Projects/tdPSA, branch Dev.
Requested by Svein after testing the work-plan/profile screen.
Status: implemented and tested on Dev; HR-2026-10-01-WORKDAY In Review.
The named human recheck is still required before Main promotion and production migration,
deployment or activation. No commit, push or production action was performed.

## Behavior and ownership

- My workdays opens directly on the selected date (today initially), with native Start/End
  date-time pickers. Date / Show day changes the selected date without a write.
- Opening an unsaved date creates no Workday, receipt or Calendar. First explicit Save draft
  creates the private day through the existing versioned API/domain action.
- Saved actual work always wins over plan suggestions. Confirmation remains explicit.
- Initial suggestions and reminders share PlannedWorkday: canonical Calendar availability,
  plan blocks and retained explicit absence. Ordinary busy meetings do not subtract work.
- Existing persisted profile hours without a dated Calendar projection are used for today
  and future dates only. Unsaved defaults and historical dates do not invent a schedule.
  Existing dated rules and legacy exceptions retain precedence.
- Unknown/non-working dates keep blank Start/End; partial absence splits suggested intervals.
  No automatic breaks are inferred. Overnight End includes the following date.
- New records use the profile timezone; existing records retain their original timezone.
  Repeated local clock hours require an explicit occurrence choice; API validation is unchanged.
- UserManagement owns normal profile hours, Calendar owns dated plans/availability, and Workday
  owns actual time and confirmation. Reciprocal links connect Work plan and My workdays.
  There is no new domain, migration, permission, route or API write contract.

## Changed files

Runtime:

- app/Modules/Calendar/Queries/EffectiveWorkIntervals.php
- app/Modules/UserManagement/Views/profile/work-plan.blade.php
- app/Modules/Workday/Controllers/Tech/WorkdayController.php
- app/Modules/Workday/Queries/PlannedWorkday.php (new)
- app/Modules/Workday/Queries/WorkdayEditor.php (new)
- app/Modules/Workday/Support/ReminderEligibility.php
- app/Modules/Workday/Views/Tech/date-navigation.blade.php (new)
- app/Modules/Workday/Views/Tech/day-content.blade.php (new shared existing editor)
- app/Modules/Workday/Views/Tech/time-picker.blade.php (new)
- app/Modules/Workday/Views/Tech/index.blade.php
- app/Modules/Workday/Views/Tech/show.blade.php

Tests and documentation:

- app/Modules/Workday/Tests/Feature/WorkdayEntryTest.php (eight new regressions)
- app/Modules/Workday/Tests/Feature/ManualWorkdayTest.php (updated entry label)
- app/Modules/Workday/Docs/knowledge/workday.md
- app/Modules/UserManagement/Docs/knowledge/work-plan.md
- docs/TODO.md
- docs/human-review.md
- docs/rfc/2026-10-01-daily-workday-confirmation.md
- docs/feature-slices/2026-10-01-workday-02-manual-days-and-api.md
- docs/plans/2026-10-01-workday-implementation-plan.md
- docs/plans/2026-10-03-workday-dev-pilot-review.md
- this verification record

The shared Dev tree already contained extensive unrelated work (340 status entries at preflight).
A start-of-turn hash manifest was used to identify this turn's file changes. No unrelated
source was reverted or reset.

## Automated verification

The direct-entry regression failed before implementation because the index did not contain
date-time pickers. The initial focused run passed 64 tests / 391 assertions.

Final command, on Dev with umask 0002:

```sh
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature \
  app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php \
  app/Modules/Calendar/Tests/Feature
```

Result: **204 passed, 1981 assertions, 204.34 seconds**.
Covers direct entry without writes, first-save upsert, saved-value precedence, missing/disabled/
historical plans, legacy persisted profiles, partial/full absence, overnight work, repeated
clock-hour offsets, view-only ownership, unchanged API list, confirmation/correction,
reminders, retention validation/privacy and Calendar regressions.

Pint --test passed for all seven touched PHP implementation/test files.
git diff --check passed. No additional test suite was required after documentation updates.

## Current Dev read-back

A read-only query against the screenshot's existing Admin User profile, selecting 2026-10-05,
returned suggested 08:00-16:00 with timezone UTC. Workday count was zero both before and after,
and the profile still had no Calendar. This verifies the missing projection fallback without
creating employee data or changing the profile timezone.

The connected in-app browser has no authenticated Dev session. Trusted HTTPS returned
302 to /login for /tech/workdays. This verifies the login boundary, not the interactive
authenticated page. The attached screenshot came from the user's authenticated browser;
its session was not bypassed or copied.

## Runtime actions and remaining review

Completed on Dev with umask 0002:

- php artisan view:clear
- php artisan queue:restart (graceful restart signal for shared reminder-reader changes)

No asset build, schema migration, OpenAPI schema change or new scheduler is required.
Existing employee activation stays enabled; destructive retention stays independently disabled.

Svein should reload My workdays and verify native picker/keyboard/mobile behavior, plan
suggestions, first draft save/read-back and explicit confirmation. The current profile timezone
is UTC; changing it is a user choice in Work plan. Check repeated-hour occurrence selection
and overnight entry. Use synthetic test descriptions.

The existing full pilot checklist remains open for access, real opted-in notification delivery,
source/Task workflows and retention/restore evidence. No check was marked Reviewed and no
external notification test was sent by this change. Historical diagnostic copies and external
backup/log retention remain the previously documented activation gates.

MCP/LiteLLM adapters, Tripletex transfer, advanced holiday balances/approval and rota planning
remain deferred. No new follow-up feature or domain is required for this UX correction.
