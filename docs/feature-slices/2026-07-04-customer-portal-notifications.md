# Feature Slice: Customer Portal Notifications

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Owner: Codex

## Goal

Deliver customer-facing portal notifications for all implemented customer portal workflows.

## User-Visible Behavior

Portal users can see unread counts, open a notification center, mark notifications read, and control
email/in-app preferences for portal notification types. Existing implemented portal workflows notify
the relevant customer scope when records become visible or materially change.

## Scope

- Customer portal notification center and header badge.
- Portal-safe notification delivery using the existing Laravel notifications table.
- Ticket, Documentation, Knowledge, Economy, Sales quote, and Commercial contract portal events.
- Email and in-app delivery preferences for portal notification types.
- Tests for scope isolation, notification center behavior, and cross-module event delivery.
- Knowledge and TODO documentation updates.

## Out Of Scope

- SMS, web push, native app behavior, and full PWA installation work because those are covered by
  separate Draft RFCs.
- New customer-visible domains that are not already implemented in the portal.

## Data Touched

- Existing `notifications` table.
- Existing `notification_settings` table.
- CustomerPortal, Notification, Ticket, Documentation, Knowledge, Economy, Sales, and Commercial
  module files.

## Permissions

Portal routes remain protected by `auth` and `EnsureCustomerPortalAccess`. Notification reads and
open actions are limited to notifications owned by the authenticated portal user and created by the
customer portal notification class.

## Tests

- Portal notification recipient scope and site/client isolation.
- Portal notification center list, open, read-all, and preferences.
- Ticket reply/status events.
- Documentation, Economy, Sales quote, and Commercial contract publication/status events.
- Knowledge client-wide published article notifications.

## Documentation

- CustomerPortal Knowledge overview.
- Notification Knowledge docs.
- TODO status row.

## Done Criteria

- Portal users receive notifications only for records visible to their active membership scope.
- Portal users can manage and read notifications without entering internal technician routes.
- Implemented portal modules emit notifications on customer-visible events.
- Feature tests pass on the Dev server.
