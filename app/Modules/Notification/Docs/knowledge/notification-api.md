The Notification API exposes the authenticated user's database notifications for trusted
integrations, mobile clients, and future AI agents.

The authenticated API returns database notifications for the current user, including customer portal
notifications if the token belongs to a portal-linked user. Portal session UI users normally manage
customer notifications through `/portal/notifications`; that route enforces CustomerPortal scope and
does not expose internal notifications.

Implemented scopes:

- `notifications.read`: list and view the authenticated user's notifications.
- `notifications.update`: mark the authenticated user's notifications as read.

Implemented routes:

- `GET /api/v1/notifications`
- `POST /api/v1/notifications/{notification}/read`
- `POST /api/v1/notifications/read-all`

`GET /api/v1/notifications` supports:

- `unread`: when true, only unread notifications are returned.
- `per_page`: page size.

Notification ownership is enforced. A user cannot read or mark another user's notifications through
the API.

Notification channel administration is not part of this API slice. Channel settings remain an Admin
UI workflow until the settings API surface is designed.
