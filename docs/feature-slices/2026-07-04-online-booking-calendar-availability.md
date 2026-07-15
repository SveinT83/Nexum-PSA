# Feature Slice: Online Booking With Calendar Availability

Parent RFC: `docs/rfc/2026-07-04-online-booking-calendar-availability.md`
Status: Approved
Date: 2026-07-04
Owner: Codex

## Scope

Build the complete first Booking workflow:

- Booking-owned service settings for Commercial services.
- Public `/booking` service listing and service-specific booking request form.
- Calendar-backed slot calculation using existing personal work calendars, availability rules, and
  busy-event conflict checks.
- Staff-confirmed booking request review.
- Calendar event creation and source linking when staff confirms a request.
- Customer email notifications for received, confirmed, and declined requests.
- Admin navigation, permissions, tests, and Knowledge documentation.

## Out Of Scope

- Direct auto-reservation mode.
- Payment collection.
- ServiceVisit execution.
- Resource scheduling before the Resource module exists.
- Authenticated Customer Portal booking history.

## Acceptance Criteria

- Inactive Booking settings and non-orderable or unpublished Commercial services are not publicly
  bookable.
- Public customers can request only currently available Calendar slots.
- Submitted booking requests store contact details, selected slot, source metadata, state events,
  and customer notification status.
- Admin users can configure booking settings for services, review requests, confirm them into
  Calendar events, or decline them with a reason.
- Confirmation rechecks Calendar availability before creating an event.
- Route ownership, permissions, state transitions, notifications, and Calendar linking have Laravel
  feature tests.

## Verification

Run the Booking test suite and the full project test suite on the Dev server after syncing files and
running migrations.
