Nexum runs as one responsive web application across desktop, tablet, and mobile.

The same routes, permissions, and business rules apply regardless of screen size. The layout adapts
for smaller screens with mobile navigation and mobile-friendly work surfaces instead of exposing a
separate mobile product.

## Installable App

The PWA metadata is registered on the main technician shell, guest/login surfaces, Customer Portal,
Booking, Intake, public quote acceptance, and public contract acceptance.

The manifest uses the active company profile for the app name and theme color where possible. If no
company branding is configured, Nexum falls back to the standard Nexum PSA name and orange theme
color.

## Offline Behavior

Nexum is online-first.

The service worker may cache static assets and show a static offline page when navigation cannot
reach the server. It must not cache private pages, API responses, customer data, submitted forms, or
write actions as durable offline data.

If the server cannot be reached, technicians and customers should reconnect before continuing work
with tickets, tasks, calendar events, portal data, booking requests, or form submissions.

## Mobile Technician Surface

`/tech/my-day` is the first dedicated mobile-friendly technician work surface. It shows the signed-in
technician's assigned tickets, assigned tasks, and calendar events for today.

My Day reads from Ticket, Task, and Calendar data but does not take ownership of those workflows.
Actions still use the existing domain routes and permissions.

## Future Scope

Push notifications, offline write queues, conflict handling, photo capture workflows, and deeper
workflow-specific responsive hardening require separate approved slices before they are exposed as
finished behavior.
