# Relationship Module

The Relationship module owns Nexum-to-Nexum relationship configuration,
credential metadata, sync identity mapping, health state, and audit events.

## Ownership

- `NexumRelationship` links either a Client (`we_are_provider`) or a Vendor
  (`we_use_provider`) to a remote Nexum installation.
- `NexumSyncLink` stores local-to-remote identity mapping per domain.
- `NexumSyncEvent` stores append-only sync audit evidence.
- Admin routes live in `app/Modules/Relationship/routes.php`.
- Public signed API routes live in `app/Modules/Relationship/api-public.php`.

Ticket, Documentation, and Knowledge still own their domain records and business
rules. Relationship actions call those domain services instead of duplicating
their behavior.

## Admin Surface

Admins manage relationships from:

```text
/tech/admin/system/relationships
```

The admin surface can create, edit, pause, disable, and rotate relationship
secrets. It also exposes implemented push actions for eligible Documentation and
Knowledge records. Controls are not shown for unimplemented behavior.

Permissions:

- `relationships.view`
- `relationships.manage`
- `relationships.escalate`
- `relationships.sync`

## Transport

Public relationship endpoints live under:

```text
/api/v1/nexum/relationships
```

Incoming calls must include:

- `X-Nexum-Token`
- `X-Nexum-Timestamp`
- `X-Nexum-Signature`

The signature is `sha256=` plus an HMAC-SHA256 digest of
`{timestamp}.{rawJsonBody}` using the relationship webhook secret.

Outgoing calls use the relationship outbound token and webhook secret. Failures
are written to `nexum_sync_events` and reflected on the relationship health
fields.

## Implemented Endpoints

Incoming signed endpoints:

- `POST /api/v1/nexum/relationships/tickets`
- `POST /api/v1/nexum/relationships/tickets/{remoteTicketId}/messages`
- `POST /api/v1/nexum/relationships/tickets/{remoteTicketId}/status`
- `POST /api/v1/nexum/relationships/documentation`
- `POST /api/v1/nexum/relationships/knowledge/articles`

## Sync Boundaries

Allowed v1 sync behavior:

- Public ticket escalation and public replies.
- Mapped ticket status updates.
- Selected attachments when the relationship attachment policy allows size and
  content type.
- Non-internal Documentation records.
- Non-internal Knowledge articles.

Never sync internal notes, private workflow internals, assignments, time, cost,
margin, credentials, private contacts, or internal documentation by default.

## Verification

Targeted tests:

```bash
HOME=/tmp php artisan test app/Modules/Relationship/Tests/Feature/RelationshipModuleTest.php
```

Broad regression tests:

```bash
HOME=/tmp php artisan test
```
