# ADR: Calendar iMIP And Out-Of-Office Domain Boundary

Status: Accepted
Date: 2026-08-16
Decision owners: Calendar / Email / Integration
Related RFC: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Feature Slice: `docs/feature-slices/2026-08-16-email-mail-calendar-invitations-out-of-office.md`

## Context

Calendar invitations arrive and leave as email, but their scheduling identity, revisions, recurrence,
participants, availability and response state belong to Calendar. Email owns the exact mailbox
source, MIME transport, provider submission and Sent/delivery reconciliation. Letting either domain
own both would create duplicate events, duplicate replies, mailbox-authority leaks and conflicting
state when an invitation is updated or cancelled.

iCalendar/iTIP/iMIP is complex and untrusted. Hand-written line parsing cannot safely cover folding,
escaping, parameters, time zones, recurrence, `METHOD`, `UID`, `SEQUENCE`, `RECURRENCE-ID`, organizer
and attendee semantics. The repository has no direct iCalendar parser dependency.

Out-of-office is also two decisions: Calendar owns the user's absence/availability window and policy;
Email owns any external reply transport. It must not become an alternate ungated auto-reply path.

## Decision

Calendar owns normalized invitation/revision/participant/response and absence policy records through
public guarded actions. Email stores the source Mail relationship and parses/renders/sends the iMIP
transport through those actions. Linking never grants Mail or Calendar access.

Adopt local `sabre/vobject` 4.5-compatible releases as the bounded RFC 5545/iTIP parser/serializer.
Pin the Composer version, verify its license/security/advisories and wrap it behind Calendar-owned
interfaces so package objects never cross module/public queue/API boundaries. Parsing has strict byte,
component, property, recurrence and wall-clock bounds; repair/forgiving mode is not automatic
authority. Original MIME remains Email evidence.

For inbound invitations, the authoritative scheduling key is organizer identity plus iCalendar `UID`
and optional `RECURRENCE-ID`; `SEQUENCE`/`DTSTAMP` order revisions. Source message/provider IDs are
provenance, not event identity. Duplicate/out-of-order/conflicting revisions remain visible and cannot
overwrite a newer accepted Calendar state.

Accept/tentative/decline/update/cancel is always an explicit Calendar decision reauthorized against
the exact Calendar/event/source. If a response is requested, Calendar creates an immutable response
intent and Email renders/sends a standards-conformant `METHOD:REPLY` through the unified outbound
boundary. Calendar status distinguishes local decision, provider acceptance, delivery pending,
delivered, bounced and unresolved; uncertainty never replays automatically.

Out-of-office policies are Calendar-owned absence windows. An external reply is submitted only via an
order-26 restricted automatic-reply scenario with all its default-off gates, loop/exclusion/rate/
cancel/emergency/reconciliation behavior. Calendar never calls SMTP/Graph/Gmail or bypasses that
state machine. Provider-native vacation settings are outside this decision.

## Trust And Privacy

Invitation sender/authentication, `ORGANIZER`, `SENT-BY`, attendees and source thread are independent
facts. A mismatch is shown and blocks automatic linking/responding until explicit review. Calendar
URLs/descriptions/locations are sanitized untrusted text; remote resources and embedded attachments
are never fetched. Non-clean attachment content is excluded.

Mail content authority and Calendar event authority are both required to show source-plus-event
context. Configuration-only users see safe parser/queue health, not attendees, subject, time or
mailbox existence. Private Calendar visibility does not become Mail access, and Mail delegation does
not become Calendar edit/respond authority.

## Consequences

- Calendar is the single scheduling truth; Email is the single email transport truth.
- One mature local parser dependency replaces partial custom parsing and adds a maintained security/
  upgrade obligation.
- RSVP and organizer sends use the same outbound idempotency/reconciliation as ordinary Mail.
- OOO cannot be enabled until restricted automatic replies are reviewed and activated.
- Provider-native Calendar APIs/settings require separate provider/Calendar slices.

## References

- [RFC 5545: iCalendar](https://www.rfc-editor.org/info/rfc5545/)
- [RFC 6047: iMIP](https://www.rfc-editor.org/info/rfc6047/)
- [sabre/vobject](https://github.com/sabre-io/vobject)
- [sabre/vobject iCalendar guide](https://sabre.io/vobject/icalendar/)

## Verification

Package fixtures and hostile/fuzzed calendars must prove folding/escaping/time zones/recurrence/
revision/organizer/attendee semantics and all bounds. End-to-end tests must prove Calendar-owned
decisions, one Email outbound/Sent outcome, access isolation and order-26 OOO gating before rollout.
