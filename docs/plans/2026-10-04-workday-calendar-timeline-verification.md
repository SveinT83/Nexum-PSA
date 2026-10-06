# Workday calendar timeline verification

Date: 2026-10-04. Owner: Codex. Requested by Svein.
Authoritative checkout: /var/Projects/tdPSA, branch Dev.
Status: implemented and tested on Dev; HR-2026-10-01-WORKDAY In Review.
This replaces the picker-only direct-entry UI rejected by Svein.

## Delivered behavior

- Navigable month calendar at the top, today selected in the profile/record timezone.
  Month browsing, day arrows and Today do not save or create work.
- A continuous hourly grid below, with one block per saved work interval.
  Position and height are proportional to exact elapsed minutes: 45 minutes = 72 pixels,
  60 minutes = 96 pixels, 90 minutes = 144 pixels. Longer blocks cross hour lines.
- Initial selection is the first free period of up to one hour from the work plan.
  Saving 09:00-10:00 selects 10:00-11:00 next. A 09:00-09:45 entry leaves a selection
  beginning 09:45. Short gaps stay shorter; no suggestion overlaps recorded or reserved time.
- Click a free region or saved block to register/edit start, end and activity at minute precision.
  Pending selection is separate from saved entries; adding/editing preserves other intervals.
  Save day details does not add the pending default selection.
- Immediate overlap feedback disables Save interval. Existing server validation independently
  rejects overlaps, including adjacent workdates and concurrent/stale writes.
- Unknown schedules have no invented default start. Absence, overnight time, explicit DST
  occurrences, plan/record timezone, existing source allocations and confirmation are preserved.
  A confirmed day requires Start correction before editing.
- A registered-interval list supports very short blocks. Unsaved selection edits require a
  discard choice before selecting another block; leaving with edits warns.
- All changes use existing Workday persistence/actions/API. No new domain, route, permission,
  schema migration, API operation, token grant or build dependency.

## Files changed

- app/Modules/Workday/Queries/WorkdayTimeline.php (new calendar/position/free-time query)
- app/Modules/Workday/Queries/WorkdayEditor.php
- app/Modules/Workday/Controllers/Tech/WorkdayController.php
- app/Modules/Workday/Views/Tech/date-navigation.blade.php
- app/Modules/Workday/Views/Tech/day-content.blade.php
- app/Modules/Workday/Views/Tech/timeline-editor.blade.php (new)
- app/Modules/Workday/Views/Tech/timeline-script.blade.php (new; existing Alpine runtime)
- app/Modules/Workday/Tests/Feature/WorkdayEntryTest.php
- app/Modules/Workday/Tests/Feature/WorkdayTimelineTest.php (new, seven regressions)
- app/Modules/Workday/Tests/Unit/workday-timeline.test.cjs (new, six state/interaction tests)
- app/Modules/Workday/Docs/knowledge/workday.md
- app/Modules/UserManagement/Docs/knowledge/work-plan.md
- docs/TODO.md; docs/human-review.md
- docs/rfc/2026-10-01-daily-workday-confirmation.md
- docs/feature-slices/2026-10-01-workday-02-manual-days-and-api.md
- docs/plans/2026-10-01-workday-implementation-plan.md
- docs/plans/2026-10-03-workday-dev-pilot-review.md
- this verification

The working tree already contained unrelated work. A hash manifest established the baseline;
no unrelated code was reset, reverted or committed.

## Verification

On authoritative Dev, with umask 0002:

```sh
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature/WorkdayTimelineTest.php \
  app/Modules/Workday/Tests/Feature/WorkdayEntryTest.php \
  app/Modules/Workday/Tests/Feature/ManualWorkdayTest.php \
  app/Modules/Workday/Tests/Feature/RetentionTest.php
node --test app/Modules/Workday/Tests/Unit/workday-timeline.test.cjs
```

- Laravel: **60 passed / 467 assertions**, 50.52 seconds.
- JavaScript: **6 passed**.
- After final accessibility/edit-label refinements, Timeline + Entry rechecked:
  **15 passed / 133 assertions**, 36.54 seconds (subset of the 60, not additional tests).
- Tests cover exact-minute editing, proportional position/height, preserved neighboring
  intervals, overlap rejection without a revision change, no persistent validation input,
  first-free selection, full/partial occupancy, adjacent dates, confirmed corrections, DST,
  calendar navigation, permissions, API contract and retention regressions.

## Browser evidence and limits

A synthetic fixture was exported from the passing Laravel test's actual Blade partials and
loaded through a temporary loopback-only server/SSH tunnel. It used Bootstrap and the existing
Livewire Alpine bundle. Calendar links and form posts were intercepted so the fixture could
not write to Nexum. No real employee or production data was exported.

Verified in the browser:
- 45-minute and 90-minute blocks are single proportional blocks on the grid.
- Clicking the 45-minute block loads its existing values.
- Extending it to 11:15, over the 11:00 block, shows an overlap error and disables Save interval.
- Editing it to 09:15-10:00 produces a form payload with that exact change and the untouched
  11:00-12:30 interval. Server persistence/read-back is covered by the Laravel test.
- Off-hours controls are hidden from keyboard/accessibility navigation until Show full day.
- Final fixture render had no captured browser warnings/errors.

This is browser verification of the real rendered components with synthetic data, not a claim
of authenticated end-to-end Dev browser verification or human approval. The app's live login
was not bypassed. Mobile/device behavior and the full existing pilot checklist still require
Svein's manual review.

## Runtime and remaining gate

Dev compiled views are refreshed with php artisan view:clear. No migration, asset build,
scheduler change or queue restart is needed for these read/UI changes.
No employee records, notification preferences, tokens or production settings were changed.
Employee Dev pilot remains enabled; destructive retention remains disabled.
No commit/push/Main/production action.

HR-2026-10-01-WORKDAY remains In Review and blocks Main promotion and production migration,
deployment and activation. Recheck calendar navigation, 09:00-10:00 then 10:00-11:00 selection,
45-minute and 90-minute blocks, click-to-edit, overlap rejection and explicit confirmation.
Other existing pilot checks (permissions, source/Task flows, notifications, retention/restore)
remain unchecked. MCP/LiteLLM tooling and Tripletex remain deferred.
