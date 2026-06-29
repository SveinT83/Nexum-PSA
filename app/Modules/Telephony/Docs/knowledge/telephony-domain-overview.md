# Telephony Domain Overview

Telephony gives technicians a fast call-intake surface when the phone system opens Nexum PSA during
an answered call.

## v1 Intake Flow

Each technician has a personal intake URL. The phone provider opens that URL with the caller number
when a call is answered:

```text
/telephony/intake/{token}?caller=%no
```

The route is public because the provider/browser handoff cannot depend on Nexum login or 2FA. The
token is therefore the credential. A technician can rotate it from the Telephony profile screen.

The intake endpoint records a `telephony_calls` row and then shows a compact working screen for the
technician. The screen includes caller number, matched Contact, Client, Site, related open tickets,
recent closed tickets, call note, ticket creation, and ticket linking.

## Caller Matching

Phone numbers are normalized before lookup. Supported examples:

- `99999999` becomes `+4799999999`
- `004799999999` becomes `+4799999999`
- `+4799999999` stays `+4799999999`

Telephony first matches canonical Contact phone numbers. During the Contact migration period, it
also uses the linked or legacy `client_users` row so Ticket creation can still populate
`tickets.contact_id`.

## Deduplication

If the provider sends a call ID, Telephony stores a provider call key and updates the existing call
when the same provider/call ID is received again.

If no provider call ID exists, Telephony uses a fallback fingerprint based on technician, caller
number, and a short time bucket. This keeps page reloads or duplicate provider opens from creating
multiple records for the same call.

## Ticket Linkage

Creating a ticket from a call uses the Ticket domain's `StoreTicket` action with channel `phone`.
The created ticket receives the matched Client, Site, legacy ClientUser contact bridge, owner, and
call metadata.

Linking a call to an existing ticket can copy the call note into an internal ticket message and sets
the call status to `linked`.

## Operational Notes

Provider POSTs to the initial intake endpoint are allowed without CSRF so form or JSON webhooks can
record the call. Actions inside the rendered intake page still use normal CSRF-protected forms.

The v1 implementation is intentionally provider-neutral. Telia API actions, click-to-call, call
transfer, SMS, and automatic duration enrichment are separate follow-up slices.
