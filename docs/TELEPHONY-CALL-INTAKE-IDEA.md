# Telephony Call Intake Idea

Status: Approved direction. Telephony Call Intake v1 is implemented under
`docs/rfc/2026-06-30-telephony-call-intake-v1.md`; the remaining provider, admin mapping,
unknown-caller, time-registration, and note-app items stay future scope.

This document captures the Telephony domain direction for incoming phone calls. The first finished
feature focuses on call intake when a technician answers a phone call. Later work may add
provider-specific integrations, outbound calling, call transfer, SMS, and contact sync.

## Problem

Incoming phone calls often contain important support context, but the information is easy to lose.

Today a technician may answer a call, search manually for the customer, open client and ticket screens, write notes somewhere else, and then decide whether to create a ticket. This creates friction and increases the chance that call notes, time usage, or customer context are not captured.

Many phone providers can open a URL when a call is answered. Nexum should use that capability to open a focused Call Intake view for the answering technician.

The feature should not be locked to Telia or any single provider.

## Proposed Domain

Create a future `Telephony` domain.

The first capability inside the domain should be Call Intake.

Telephony should be designed for later extension:

- Incoming call intake.
- Outbound click-to-call.
- Call transfer.
- SMS.
- Provider call logs.
- Provider contact sync.
- Missed call handling.
- Provider-specific adapters.

## Personal Technician Intake URL

Each technician should have a personal intake URL with a token.

Example:

```text
https://portal.example.com/telephony/intake/{technician-token}
```

The technician token allows Nexum to identify who answered the call even when the phone provider only sends caller information.

The URL should be visible in the technician's preference/profile view. A future technician profile view can expose the same URL and preview/test tools.

## Provider Payload Flexibility

Phone providers may send caller data in different ways.

Supported input should include:

- Query parameters.
- JSON body.
- Form body.

Examples:

```text
?phone=4799999999&call_id=abc123
```

```json
{
  "caller": "+4799999999",
  "callId": "abc123",
  "answeredAt": "2026-05-31T10:00:00+02:00"
}
```

Telephony settings should allow administrators to map provider fields:

- Caller number field.
- Call ID field.
- Direction field.
- Started at field.
- Answered at field.
- Optional display name field.
- Optional metadata fields.

## Settings

Telephony settings should provide:

- Global enable/disable.
- Default country code for phone normalization, such as `+47`.
- Accepted request method settings if needed.
- Provider profile definitions.
- Field mapping per provider profile.
- Preview/test button.
- Example generated URL.
- Instructions for copying the URL into phone provider admin panels.

The settings surface should be admin-only.

The normal Call Intake view does not need a main menu button. It is primarily opened by the phone provider URL. Admin settings can include a preview button for testing while building and configuring the feature.

## Phone Number Normalization

Incoming phone numbers must be normalized before matching.

Recommended behavior:

- `99999999` becomes `+4799999999` when default country is Norway.
- `004799999999` becomes `+4799999999`.
- `+4799999999` remains `+4799999999`.
- Unknown formats keep the raw value and store the normalization attempt.

Matching should use Contact phone records once the Contact domain phone structure is fully established.

Until then, implementation must account for existing user and contact phone fields where relevant.

## Call Record

Every intake should create or update a call record.

Suggested fields:

- Provider profile.
- Provider call ID.
- Direction.
- Raw phone number.
- Normalized phone number.
- Answered by technician.
- Matched Contact.
- Matched Client.
- Matched Site.
- Linked Ticket.
- Started at.
- Answered at.
- Ended at.
- Status.
- Notes.
- Is test flag.
- Raw payload.
- Metadata JSON.

Provider call ID should prevent duplicates when the provider opens the URL multiple times for the same call.

If no provider call ID exists, Nexum should generate a fallback fingerprint using technician, normalized phone number, and a short time window.

## Call Intake View

The Call Intake view should be optimized for fast technician work during a live call.

It should show:

- Caller phone number.
- Match status.
- Contact match if found.
- Client and Site context if found.
- Existing open tickets for the Contact.
- Existing open tickets for the Client.
- Relevant recent closed tickets.
- Contract/SLA summary for the Client.
- Contact details.
- Quick note field.
- Create Ticket from call.
- Add note to existing Ticket.
- Quick time registration.

When the caller is unknown, the view should allow:

- Search and link to existing Contact.
- Create new Contact.
- Optionally create new Client.
- Continue with call note even before matching.

## Notes

Call notes should be stored on the call record regardless of whether a Ticket is created.

If a Ticket is created, the call note should be copied into that Ticket as the initial description or first internal note, depending on the selected action.

If a note is added to an existing Ticket, the call record should still keep its own note history and link to the Ticket.

Future work may add a broader technician note system where a technician can keep a running note across views. Call Intake should be compatible with that future direction.

## Tickets

The Call Intake view should allow a technician to:

- Create a new Ticket from the call.
- Add the call note to an existing Ticket.
- Link the call to a Ticket without creating duplicate work.

Created Tickets should inherit available context:

- Contact.
- Client.
- Site.
- Caller phone number.
- Technician.
- Call note.
- Call record link.

## Time Registration

The intake should support quick time registration from the call.

Time may be linked to:

- A newly created Ticket.
- An existing Ticket.
- The Contact/Client call record without a Ticket.

Time without Ticket needs careful billing behavior. It should be stored as structured activity time and later billing can decide whether it is invoiceable, covered by contract, internal-only, or should be linked to a Ticket.

## Preview And Test Mode

Admin settings should include a preview button.

Technician preference/profile should also include a test button for the personal intake URL.

Preview should be able to open the Call Intake view with sample payload data.

Test records should be marked with `is_test = true` if they are persisted.

## Future Provider Integrations

Provider-specific integrations are intentionally out of scope for the first Call Intake feature.

Later provider work may include:

- Telia adapter.
- Outbound click-to-call.
- Transfer call.
- Sync contacts between provider and Nexum.
- Import call logs.
- SMS send/receive.
- Provider webhooks for missed calls.
- Call recording metadata.

The first design should avoid hardcoding Telia-specific field names in core domain logic.

## Relationship To Other Domains

Contact:

- Phone matching should use Contact phone records.
- Unknown callers should be linkable to existing or new Contacts.

Client:

- Matched Contacts should reveal Client and Site context.
- Unknown callers may require quick Client creation.

Ticket:

- Calls can create tickets or add notes to existing tickets.
- Call records should link to Tickets when applicable.

Commercial:

- Client contract and SLA summary should be visible.
- Time registration may later interact with billing and contract timebank decisions.

Task/Notes:

- Future technician note system should work alongside Call Intake notes.

Telephony:

- Owns call records, provider profiles, token URLs, and future provider actions.

## Initial Implementation Candidate

The v1 slice is implemented and formalized in
`docs/rfc/2026-06-30-telephony-call-intake-v1.md`. The list below remains useful as the broader
target shape; items outside the v1 RFC are future scope.

Candidate finished feature scope:

- Create Telephony module/domain.
- Add technician intake tokens.
- Add Telephony admin settings for provider profiles and field mapping.
- Add generated personal intake URL to technician preference/profile.
- Accept call payload through token URL.
- Normalize caller number.
- Create/update call record.
- Match Contact by phone.
- Show Call Intake view.
- Allow linking to existing Contact.
- Allow creating Contact and optionally Client.
- Show Contact/Client/Ticket/Contract context.
- Save call note.
- Create Ticket from call.
- Add call note to existing Ticket.
- Support quick time registration.
- Add preview/test mode.
- Add tests and Knowledge documentation.

## Open Questions

- Should technician tokens be rotated manually from the preference/profile view?
- Should provider profiles be global only, or can each technician select a provider profile?
- Should unmatched callers be retained forever or expire after a retention period?
- How should activity time without Ticket be billed or reported?
- Should a call note become ticket description or first internal note by default?
- Should call intake auto-open in a compact popup layout or full work page?
