# Booking API Planning Baseline

Date: 2026-10-27. Runtime: /var/Projects/tdPSA, branch Dev.
Status: Planning only; RFC approval pending.

## Source And Ownership

Read remote AGENTS.md, team/module standards, TODO/human review, RFC/ADR/slice
processes, approved July Booking RFC and July 28 slice. Inspected all Booking routes,
controllers, actions, models, notifications, forms/views, tests, README and Knowledge
source. Inspected Calendar StoreCalendarEvent/CheckAvailability, native route
permissions/AdminAccess/TechAccess and Integration route/catalog/adjacent API patterns.
Boost tools are not exposed in this session; source and actual Artisan were used.
Booking paths had no pre-existing git modifications at inspection. Large unrelated
shared Dev changes and active Knowledge contributor work remain preserved.

## Runtime Discovery

php artisan route:list --path=booking --json returns 13 browser rows and no Booking
API operations. Classify 7 read / 3 write / 3 action, excluding HEAD. Matrix JSON
retains actual routes/middleware/actions and maps all rows to 17 proposed operations.
No thanks-page private request lookup exists or is proposed.

## Executed Verification

With umask 0002 and HOME=/tmp:

    php artisan test app/Modules/Booking/Tests/Feature/BookingModuleTest.php app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php

PASS: 37 tests / 315 assertions; no failures/skips; duration 48.94 seconds.
15 Booking and 22 Calendar tests. Booking notifications are mocked; test cases use
RefreshDatabase and the project's isolated test configuration. No live customer
send, token, booking or Calendar record was created by planning.
Full Laravel suite and future API/concurrency/schema/HTTPS result tests were not
run: new API code does not exist. This baseline does not certify future parity.

## GitHub And Review State

Live search repo:SveinT83/Nexum-PSA booking is:open found zero matches.
Historical search found #184 routing and unrelated Calendar #135. No GitHub artifact
was created/changed/closed. HR-2026-07-28-001 remains Reviewed by Svein, 2026-08-25;
that native routing review does not accept a new API contract. The future API
human-review checklist HR-2026-10-27-BOOKING-API is Pending/planned.

## Implementation Boundaries

Native staff permission checks are not hardcoded Admin-only. Confirmation currently
checks status/free time before its transaction; decline lacks an enclosing state
transaction. These source-level race risks require approved regression/native proof;
no live exploit or race was executed. Calendar creation permits ordinary overlaps.
FindBookingSlots can ensure a personal calendar while calculating availability, so
do not claim every existing GET is write-free. Notification sent timestamps only
prove submission; queue/provider acceptance and inbox delivery remain distinct.

## Next Action

Svein Tore approves the concrete Draft RFC. Codex then implements the four sequential
slices on authoritative Dev, verifies parity/read-back/handbook and maintains named
manual review and Main/production/activation gates separately. Planning requires no
BookStack publication, migration or runtime change.
