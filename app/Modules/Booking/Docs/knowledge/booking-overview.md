# Booking Overview

Booking provides online appointment requests for Commercial services.

## Booking Services

An admin creates a booking service setting from an existing orderable Commercial service. A setting
must be active, assigned to a technician, and backed by an orderable service with status `Active` or
`published` before it appears on `/booking`.

Each setting controls:

- public name and description,
- assigned technician,
- duration,
- slot interval,
- minimum notice,
- booking horizon,
- location and customer instructions.

## Public Requests

Customers choose an available slot calculated from the assigned technician's Calendar work rules and
busy events. Requests store the selected slot, customer details, request source metadata, and a
timeline event. The customer receives an email confirming that the request was received.

## Staff Confirmation

Admins review requests in `Admin -> Booking`. Confirming a request rechecks availability before
creating a busy Calendar event. The Calendar event is linked back to the Booking request for audit.
The customer receives an email confirmation after the event is created.

Admins can also decline a request with an optional reason. The decline is recorded on the request
timeline and the customer receives an email update.

## Permissions

- `booking.view` opens Booking admin pages.
- `booking.manage` creates and edits booking service settings.
- `booking.request_review` confirms or declines booking requests.

## Boundaries

Booking does not expose direct auto-reservation, payment, ServiceVisit execution, Resource
scheduling, or Customer Portal booking history. Those are separate future decisions.
