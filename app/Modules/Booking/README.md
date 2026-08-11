# Booking Module

Booking owns public appointment requests for configured Commercial services.

## Ownership

- Booking service settings live in `booking_service_settings`.
- Public requests live in `booking_requests`.
- Booking audit/state events live in `booking_request_events`.
- Calendar remains the owner of real calendar events and availability checks.
- Commercial remains the owner of the service catalogue.

## Service Availability And Routing

Each Booking service can use an optional daily public opening window. The window intersects one of
two working-hours policies:

- Company working hours from Calendar system settings. This is the default.
- Each eligible technician's working hours and timezone from UserManagement profiles.

Technician routing is configured per service:

- `fixed` uses the service's assigned technician.
- `automatic` returns the de-duplicated union of slots from active eligible technicians without
  exposing their identities publicly. A concrete free technician is stored with the request.
- `customer_choice` shows only active configured eligible technicians and calculates slots for the
  selected technician.

Booking supplies the approved weekly windows to Calendar. Calendar still owns personal calendars,
busy-event conflict detection, and the final event.

## Public Flow

Customers open `/booking`, choose an active bookable service, pick a Calendar-backed available slot,
and submit contact details. Customer-choice services also require one eligible technician. Automatic
services never include technician names or identifiers in the public slot output. The request is
stored as `requested` with one concrete assigned technician, and an email confirmation is sent to the
customer.

## Staff Flow

Admins review requests under `/tech/admin/system/booking`. Confirmation rechecks availability,
creates a Calendar event through Calendar actions, links the event back to the booking request, and
sends a customer confirmation email. If an automatically selected technician has become busy,
confirmation may choose another active eligible free technician. Fixed and customer-selected
requests keep their selected technician and are blocked when that calendar is no longer free.
Decline records the reason and sends a decline email.

Public requests remain staff-confirmed. No Calendar hold is created before confirmation, so two
pending requests may ask for the same unreserved time. The confirmation conflict check ensures only
an actually free technician receives the Calendar event.

## Intentional Limits

Direct auto-reservation, payment, ServiceVisit execution, resource scheduling, and Customer Portal
booking history are intentionally not exposed here. Skills, departments, service areas, workload
scoring, round-robin assignment, holiday-specific service hours, and multiple daily opening windows
also remain outside this slice. Those need separate approved slices because they change
authorization, cancellation, scheduling, or operational ownership.
