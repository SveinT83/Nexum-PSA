Booking provides online appointment requests for Commercial services.

## Booking Services

An admin creates a booking service setting from an existing orderable Commercial service. A setting
must be active, have a valid technician configuration, and be backed by an orderable service with
status `Active` or `published` before it appears on `/booking`.

Each setting controls:

- public name and description,
- duration,
- slot interval,
- minimum notice,
- booking horizon,
- optional daily public opening window,
- company or technician-profile working hours,
- fixed, automatic, or customer-choice technician routing,
- eligible technicians for automatic and customer-choice routing,
- location and customer instructions.

Company working hours are the default. A service opening window such as 10:00-15:00 narrows the
selected working-hours policy; it does not make unavailable hours bookable.

Routing modes behave as follows:

- **Fixed technician** uses one configured technician and preserves the original Booking workflow.
- **Automatic assignment** combines the free times of active eligible technicians. Customers see
  times only, never the eligible technician names or identifiers.
- **Customer chooses technician** shows only active technicians configured for that service and
  calculates availability for the selected person.

## Public Requests

Customers choose an available slot calculated from the selected working-hours policy, optional
service opening window, and Calendar busy events. Automatic routing stores one concrete eligible
free technician internally when the request is accepted. Customer-choice routing stores the
technician selected on the public page. Requests also store the selected slot, customer details,
request source metadata, and a timeline event. The customer receives an email confirming that the
request was received.

## Staff Confirmation

Admins review requests in `Admin -> Booking`. Confirming a request rechecks availability before
creating a busy Calendar event. The Calendar event is linked back to the Booking request for audit.
If the first automatic technician has become busy, Nexum can use another configured eligible free
technician. Fixed and customer-selected requests do not silently change technician. If no permitted
technician remains free, confirmation is blocked and no Calendar event is created. The customer
receives an email confirmation only after the event is created.

Admins can also decline a request with an optional reason. The decline is recorded on the request
timeline and the customer receives an email update.

## Permissions

- `booking.view` opens Booking admin pages.
- `booking.manage` creates and edits booking service settings.
- `booking.request_review` confirms or declines booking requests.

No additional permission is introduced by technician routing. Existing Booking configuration and
review permissions continue to be enforced.

## Boundaries

Booking does not expose direct auto-reservation, payment, ServiceVisit execution, Resource
scheduling, Customer Portal booking history, round-robin assignment, or skill-based routing. Pending
requests do not reserve Calendar capacity before staff confirmation.
