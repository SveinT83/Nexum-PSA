# Telephony Module

Telephony owns the first phone-call intake flow for Nexum PSA.

The v1 flow is provider-neutral. A technician gets a personal intake URL from
`/tech/telephony/profile`, and the phone provider can open that URL when the technician answers a
call:

```text
https://example.test/telephony/intake/{token}?caller=%no
```

The intake endpoint accepts GET query parameters and POST form or JSON payloads. The token in the
URL is the credential, so it must be treated like an API secret and rotated if it leaks.

## Scope

Implemented in v1:

- Personal long-lived technician intake tokens with rotation.
- Public intake route without login or 2FA.
- Provider payload recording with raw payload audit fields.
- Phone number normalization for Norwegian numbers, `00` prefixes, and E.164 values.
- Caller matching against Contact phone numbers and the legacy `client_users` bridge.
- Call deduplication by provider call ID or a short fallback fingerprint.
- Intake view with caller context, related open tickets, recent closed tickets, notes, ticket
  creation, and ticket linking.

Not implemented in v1:

- Telia-specific API integration.
- Click-to-call, transfer, SMS, or call duration enrichment.
- Provider-side token lifecycle automation.
- Background call recording import.

## Routes

- `GET|POST /telephony/intake/{token}`: provider entry point and first intake screen.
- `GET /telephony/intake/{token}/calls/{call}`: reopen a recorded call intake screen.
- `POST /telephony/intake/{token}/calls/{call}/note`: save the call note.
- `POST /telephony/intake/{token}/calls/{call}/ticket`: create a Ticket from the call.
- `POST /telephony/intake/{token}/calls/{call}/link-ticket`: link the call note to an existing
  Ticket.
- `GET /tech/telephony/profile`: technician profile surface for the personal URL.
- `POST /tech/telephony/profile/token/rotate`: rotate the technician token.

## Permissions

The technician profile surface requires `telephony.view`. Public intake routes are authorized by the
token and only allow access to calls that belong to the token owner.

## Production Setup

After deploy:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan queue:restart
```

Each technician then opens `/tech/telephony/profile`, copies the provider URL, and configures the
phone provider to substitute the caller number into `caller=%no`.
