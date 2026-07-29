# Ticket API Route Verification

Use this check after deploying Ticket API changes or clearing Laravel route/config caches.

## Route Registration

Run from the deployed application directory:

```bash
php artisan optimize:clear
php artisan route:list --path=api/v1/tickets --json
```

Confirm the output contains:

- `POST api/v1/tickets/{ticket}/external-messages`
- route name `api.v1.tickets.external-messages.store`
- the `tickets.update` Sanctum ability middleware

An unauthenticated request to an existing route must return an authentication or authorization
response, not a route-level 404. A request made with a token that lacks `tickets.update` must return
403.

## Authorized Internal-Note Smoke Test

Use a clearly internal test Ticket and an approved API token with `tickets.update`. Send a unique,
stable `source` and `external_id`, with `type=internal_note` and `visibility=internal`.

Confirm:

- the first request returns 201;
- an identical retry returns 200 with `created=false`;
- exactly one internal Ticket message exists;
- no customer-facing email or portal notification is sent;
- the note records the expected external source and identifier.

Do not place API tokens, customer content, or production identifiers in command history, deployment
logs, screenshots, GitHub issues, or this document.
