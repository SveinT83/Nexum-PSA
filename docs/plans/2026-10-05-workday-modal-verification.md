# Workday Calendar Modal Verification

Date: 2026-10-05. Authoritative checkout: /var/Projects/tdPSA, branch Dev.
Requested by Svein Tore: actual time entry opens in a modal when clicking the calendar.
Status: implemented and verified on Dev; HR-2026-10-01-WORKDAY remains In Review.

## Behavior

- The day timeline occupies the full content width. Free-time clicks open Register time;
  saved blocks open Edit time. Time entry reopens the current/default selection.
- Bootstrap's existing runtime supplies the modal, backdrop, Escape and focus trap.
  Opening focuses Start; closing returns focus to the trigger and preserves pending input.
- Start/End support whole minutes; saved blocks retain proportional duration.
  Saving one interval preserves other intervals and breaks through the existing draft action.
- Server validation reopens the modal, preserves submitted values and focuses the error.
  The same-request privacy handling still avoids storing private input in the session.
- Confirmed/read-only records do not expose the editing modal. Confirmation and correction
  requirements, ownership, API contracts and retention remain unchanged.

## Verification evidence

- JavaScript unit tests: 8 passed, including modal lifecycle, pending input/focus,
  validation reopening, exact-minute edits, preserved payloads and overlap checks.
- WorkdayTimelineTest: 7 passed / 73 assertions.
- The four-class Laravel run covered WorkdayTimelineTest, WorkdayEntryTest,
  ManualWorkdayTest and RetentionTest: 59 passed and one UI-copy assertion failed.
  The old first-free-hour helper sentence is no longer shown inside a clicked-time modal.
  Its assertion was updated; actual first-free-hour/date assertions were retained.
- Rerun WorkdayEntryTest: 8 passed / 68 assertions. All 60 distinct tests in the
  scoped four-class set now pass across these runs; no failing verification remains.
- Actual Blade output from the synthetic SQLite test was served over a temporary,
  loopback-only SSH tunnel. Browser checks verified new/edit opening, exact input values,
  Close and Escape, focus return, retained values on reopen, intercepted form payloads
  preserving other intervals, overlap disabling and server-validation automatic reopening.
  No browser console warnings/errors were observed. No live employee data was written.
- The fixture used the existing Livewire runtime and the same Bootstrap 5.3.8 JavaScript
  as the app; its SHA-384 matched the layout's integrity attribute.
- Temporary browser tab, HTTP server and SSH tunnel were closed after verification.
- git diff --check passed for affected paths. The new Blade partial has mode 0664.
- php artisan view:clear succeeded. Trusted HTTPS /tech/workdays returns HTTP 302
  for an unauthenticated request; this does not prove an authenticated end-to-end save.

## Changed files

Runtime:
- app/Modules/Workday/Views/Tech/timeline-editor.blade.php
- app/Modules/Workday/Views/Tech/timeline-script.blade.php
- app/Modules/Workday/Views/Tech/interval-modal.blade.php (new)

Tests:
- app/Modules/Workday/Tests/Feature/WorkdayTimelineTest.php
- app/Modules/Workday/Tests/Feature/WorkdayEntryTest.php
- app/Modules/Workday/Tests/Unit/workday-timeline.test.cjs

Documentation:
- app/Modules/Workday/Docs/knowledge/workday.md
- docs/rfc/2026-10-01-daily-workday-confirmation.md
- docs/feature-slices/2026-10-01-workday-02-manual-days-and-api.md
- docs/plans/2026-10-01-workday-implementation-plan.md
- docs/plans/2026-10-03-workday-dev-pilot-review.md
- docs/TODO.md
- docs/human-review.md
- this verification note

## Runtime actions and remaining review

View cache was cleared on Dev. No migration, package install, asset build, queue restart,
scheduler change, permission grant, activation change or API change is required.
Changes remain in the authoritative working copy; no commit, push, Main or production action.

Svein should refresh My workdays and test clicking free time and saved blocks, minute edits,
close/reopen, overlap, saving and mobile/device layout in the authenticated Dev session.
HR-2026-10-01-WORKDAY is the human-review checklist: In Review, blocking Main promotion
and production deployment/activation. Other pilot checks listed there remain open.
Automated tests and the static browser fixture do not mark any human check Reviewed.
