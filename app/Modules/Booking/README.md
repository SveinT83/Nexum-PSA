# Booking Module

Booking owns public appointment requests for configured Commercial services.

## Ownership

- Booking service settings live in `booking_service_settings`.
- Public requests live in `booking_requests`.
- Booking audit/state events live in `booking_request_events`.
- Calendar remains the owner of real calendar events and availability checks.
- Commercial remains the owner of the service catalogue.

## Public Flow

Customers open `/booking`, choose an active bookable service, pick a Calendar-backed available slot,
and submit contact details. The request is stored as `requested` and an email confirmation is sent to
the customer.

## Staff Flow

Admins review requests under `/tech/admin/system/booking`. Confirmation rechecks availability,
creates a Calendar event through Calendar actions, links the event back to the booking request, and
sends a customer confirmation email. Decline records the reason and sends a decline email.

## Intentional Limits

Direct auto-reservation, payment, ServiceVisit execution, resource scheduling, and Customer Portal
booking history are intentionally not exposed here. Those need separate approved slices because they
change authorization, cancellation, and operational ownership.
