# Feature Slice: Booking Hours And Technician Routing

Status: Done
Date: 2026-07-28
Parent: `docs/rfc/2026-07-04-online-booking-calendar-availability.md` and GitHub issue #184
Owner: Codex

## Goal

Complete the approved Booking follow-up so public availability can be limited by service hours and
routed to a fixed technician, an automatic eligible pool, or a customer-selected technician.

## User-Visible Behavior

- Admins can define an optional daily public opening window for each Booking service.
- Availability follows company work hours by default or the selected technicians' profile hours.
- Fixed, automatic, and customer-choice technician routing modes are supported.
- Automatic routing exposes times but not technician identities on the public page.
- Customer-choice routing exposes only configured active eligible technicians.
- Booking create and edit use the shared Page Header Back action.
- Spam protection configuration is explained inside an advanced section.

## Scope

- Extend Booking service settings with routing mode, work-hours source, opening window, and eligible
  technicians.
- Calculate a de-duplicated union of available slots for automatic routing.
- Persist a concrete technician on every accepted request and recheck Calendar conflicts during
  staff confirmation.
- Allow automatic requests to move to another eligible available technician during confirmation.
- Update admin/public views, tests, module docs, Knowledge docs, and human-review tracking.

## Out Of Scope

- Direct reservation or Calendar holds before staff confirmation.
- Customer Portal booking history, cancellation, or rescheduling.
- Skills, departments, service areas, resources, workload scoring, or round-robin policy.
- Multiple opening windows per weekday or holiday-specific service schedules.

## Data Touched

- `booking_service_settings`
- `booking_service_setting_user`
- `booking_requests.assigned_user_id` and request metadata
- Calendar free/busy queries and personal calendars
- Booking service settings and public booking views

## Permissions

- Existing `booking.view` continues to protect Booking admin pages.
- Existing `booking.manage` continues to protect service configuration.
- Existing `booking.request_review` continues to protect confirmation and decline actions.
- No new permission is required because no new authority boundary is introduced.

## Tests

- Booking feature tests cover opening windows, company and technician hours, all routing modes,
  public identity protection, request assignment, confirmation recheck/rerouting, validation, and
  Page Header behavior.
- Calendar feature tests cover explicit weekly availability windows with conflict exclusion.
- Migration, PHP syntax, Blade compilation, and relevant feature suites run on Dev.

## Documentation

- Update Booking README and Knowledge documentation.
- Update Calendar Knowledge documentation for the explicit-window availability contract.
- Add a pending entry to `docs/human-review.md`.

## Verification

- Booking and Calendar feature suites pass with 31 tests and 171 assertions.
- The complete Knowledge article feature suite passes with 39 tests and 355 assertions.
- Migration `2026_07_28_120000_add_routing_and_opening_hours_to_booking_service_settings` ran on
  Dev in batch 53.
- Pint, PHP syntax, `git diff --check`, Blade compilation, and the public `/booking` HTTPS smoke
  test pass.
- Repository Knowledge synchronization processed one Booking chapter/article and one Calendar
  chapter/article without skips.

## Done Criteria

- All GitHub issue #184 acceptance criteria are implemented.
- Existing fixed-technician settings keep working after migration.
- Public automatic mode does not render eligible technician identities.
- Relevant Booking and Calendar tests pass on Dev.
- Deployment and human-review steps are documented.
