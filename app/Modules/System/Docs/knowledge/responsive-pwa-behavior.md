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

## Web Push Behavior

Nexum uses one shared service worker for installation, online-first fetch behavior, the offline
fallback, and Web Push. A separate push-only service worker must not be registered.

An authenticated internal user explicitly registers each browser or installed PWA from Profile >
Notifications. Nexum never asks for notification permission during page load. iPhone and iPad
registration is available only from the installed Home Screen app.

An accepted push event always creates a visible browser notification. A notification click:

- accepts only a same-origin Nexum target;
- falls back to Profile > Notifications when the target is absent or invalid;
- focuses and navigates an existing Nexum window where supported;
- otherwise opens a new Nexum window;
- still passes through normal authentication and route authorization.

Push payloads and private application responses are not added to the service-worker cache. The
worker uses Nexum-owned icon paths and ignores untrusted icon values in a push payload.

Web Push is best effort. It does not make Nexum offline-capable and does not replace the canonical
in-app notification. The first channel-foundation slice exposes only a generic current-device
test; business-event pushes require their own approved slice and explicit user preference.

When the service worker changes, browsers may keep the previous worker until the update activates.
The current worker activates immediately, clears older Nexum PWA caches, and retains the existing
online-first navigation and static-asset behavior. After deployment, verify both notification
click handling and the offline page.

## Mobile Technician Surface

`/tech/my-day` is the first dedicated mobile-friendly technician work surface. It shows the signed-in
technician's assigned tickets, assigned tasks, and calendar events for today.

My Day reads from Ticket, Task, and Calendar data but does not take ownership of those workflows.
Actions still use the existing domain routes and permissions.

## Future Scope

Business-event Web Push, offline write queues, conflict handling, photo capture workflows, and
deeper workflow-specific responsive hardening require separate approved slices before they are
exposed as finished behavior.
