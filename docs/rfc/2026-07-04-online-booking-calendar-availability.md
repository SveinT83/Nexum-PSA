# RFC: Online Booking With Calendar Availability

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

GitHub Discussion #167 defines online booking tied to services, Calendar availability, technician
availability, and resources. Nexum has Calendar, Commercial services, Notifications, Customer Portal
planning, and public intake planning, but no customer-facing booking workflow.

This is Level 3 work because it affects public routes, Calendar availability, service catalogue
publication, routing, notifications, and potentially ServiceVisit/Ticket/Sales creation.

## Goals

- Let customers request or reserve appointment windows for published services.
- Calculate availability from Calendar, work hours, conflicts, service duration, technician skills,
  departments, service areas, and resources when available.
- Support request-only, staff-confirmed, and direct reservation modes.
- Create module-approved target records such as Sales lead, Ticket, ServiceVisit, or Calendar hold.
- Send customer confirmations and reminders through Notification.
- Prepare for Customer Portal booking history and change requests.

## Non-Goals

- Do not bypass staff review unless a booking type explicitly allows direct reservation.
- Do not replace Calendar ownership of events or availability.
- Do not implement ServiceVisit execution in this RFC.
- Do not implement payment collection as part of booking.
- Do not expose direct booking for services that are not published and configured.

## Current Behavior

Calendar can manage events and availability-related data. Commercial services exist, but customers
cannot book online and the system does not calculate bookable public slots.

## Proposed Change

Add booking as a configured public workflow that consumes Commercial services and Calendar
availability.

Booking owns:

- booking form/workflow configuration,
- public booking requests,
- booking state,
- customer confirmation/change request records,
- routing to approved target modules.

Calendar owns:

- event creation/update,
- availability checks,
- conflict behavior,
- recurring event rules.

Commercial owns:

- published/bookable service metadata,
- duration defaults,
- service questions and acknowledgements where applicable.

## Impact Analysis

- **Architecture:** likely new singular `Booking` module unless merged into Intake by explicit ADR.
- **Calendar:** availability APIs, conflict checks, event holds, and scheduling handoff.
- **Commercial:** service publication and booking metadata.
- **CustomerPortal:** authenticated booking history and change requests later.
- **Notification:** confirmations, reminders, cancellation/reschedule notices.
- **Ticket/Sales/ServiceVisit:** optional target creation depending on workflow.
- **Resource:** resource availability once Resource scheduling exists.
- **Security:** public throttling, validation, anti-spam, and no exposure of private calendar data.

## Data And Migration Plan

Add booking configuration and booking request tables. Store requested slots separately from
confirmed Calendar events. Direct reservation mode must create Calendar records through Calendar
actions and keep source booking links for audit.

## Testing Plan

- Availability calculation tests for working hours, conflicts, and inactive services.
- Public booking request validation tests.
- Staff-confirmation workflow tests.
- Direct reservation tests only after explicit mode is implemented.
- Notification idempotency tests.
- Authorization tests for admin configuration and portal booking history.

## Documentation Plan

- Add Booking module docs and Knowledge pages.
- Update Calendar and Commercial docs for booking metadata.
- Update Customer Portal docs when portal booking history is implemented.

## Decisions

- Booking lives in a dedicated singular `Booking` module. Intake remains responsible for
  configurable inquiry forms and file-based submissions.
- The first implemented workflow is staff-confirmed booking. Customers request a concrete slot
  calculated from Calendar availability, and staff confirms it before a Calendar event is created.
- Direct reservation is not exposed in UI or public routes until its conflict, cancellation, and
  permission behavior is implemented as a separate approved decision.

## Approval

Approved by Svein Tore on 2026-07-04 in chat with the instruction to implement the next RFC fully.
