# Feature Slice: Calendar Invitations And Out-Of-Office Workflows

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-email-calendar-imip-and-out-of-office-boundary.md`
Owners: Calendar / Email / Integration / Notification
Human Review: `HR-2026-08-16-029`

## Goal

Recognize and safely present inbound iCalendar invitations/updates/cancellations, let authorized users
accept/tentative/decline through Calendar-owned actions, and transport exact iMIP responses through
Email's unified outbound/Sent boundary. Add Calendar-owned absence policies that may request only the
reviewed restricted OOO auto-reply scenario.

## Dependencies

Orders 5-13, 20-21 and 26 must be stable. Canonical/source identity, provider reconciliation, private
invalidation, shared drafts/outbound, Ticket relationship policy, attachment quarantine and restricted
auto-reply safeguards are required. Calendar's current events/participants/access/recurrence/
availability actions are extended rather than bypassed.

## Package And Parser Boundary

Add a pinned, reviewed `sabre/vobject` 4.5-compatible Composer dependency behind Calendar interfaces
`CalendarInvitationParser` and `CalendarImipRenderer`. Do not expose vendor nodes through models,
controllers, jobs or APIs. Record package/parser policy version and safe error codes only.

Default limits: 1 MiB calendar part, 16 components, 2,000 properties, nesting depth 16, string/property
64 KiB, attendee 500, time zones 32, recurrence expansion 500 occurrences/24 months and a hard local
parse/normalize deadline. Reject cap-plus-one, invalid critical properties, external URI fetches,
binary attachments and decompression/entity expansion. Forgiving repairs are displayed as warnings
and cannot auto-link/respond without reviewed equivalence.

## Additive Data Model

Reserve migrations `2026_08_16_170000` and `171000`.

Add Calendar-owned invitation records/revisions:

- normalized organizer key, iCalendar UID, recurrence ID, method/sequence/dtstamp/status and exact
  Calendar event/series/occurrence relation;
- source Email conversation/message/placement/account references and source/parser/security hashes;
- normalized times/timezone/all-day/recurrence/organizer/attendee/response facts with private
  visibility, not raw ICS or Mail body;
- state `received`, `needs_review`, `linked`, `accepted`, `tentative`, `declined`, `cancelled`,
  `superseded`, `conflict`, `stale` or `blocked` and append-only events.

Add Calendar response intents/submissions:

- exact event/revision/source/account/attendee/organizer, response type/comment policy and immutable
  generated iMIP/body/header hash;
- actor/auth/policy/source/event/provider binding fingerprint and idempotency identity;
- states `previewed`, `approved`, `queued`, `provider_write_started`, `accepted`,
  `delivery_pending`, `delivered`, `bounced`, `unresolved`, `cancelled`, `stale` or `blocked`;
- unified Email outbound/Sent/bounce references, never duplicate SMTP/provider logs.

Add Calendar absence/OOO policy versions:

- owner/calendar/time window/timezone, availability/visibility, reviewed response template/scenario,
  audience/domain policy and order-26 activation reference;
- publication/revocation/emergency events and exact execution links; no Mail content/address copy.

Unique constraints protect organizer+UID+recurrence revision and response idempotency. Down refuses
while events/responses/absence history depend on schema. Migrations parse/send/create nothing.

## Inbound Invitation Projection

After complete safe Email persistence, identify `text/calendar` MIME parts and `.ics` attachments but
do not trust extension/MIME alone. Quarantine/security policy must permit parser use. Queue one bounded
parse by exact source fingerprint. Parser normalizes `REQUEST`, `PUBLISH`, `REPLY`, `CANCEL`,
counter/status/timezone/recurrence facts and records unsupported/conflicting components honestly.

Resolve the attendee identity only against the selected Email account's verified sender aliases and
current Calendar user/participant authority. Compare authenticated sender, `ORGANIZER`, `SENT-BY`
and source thread. Mismatch, missing UID/organizer/times, several VEVENTs, sequence collision or
newer-existing revision enters review.

An invitation card appears in the selected Mail message and authorized Calendar view with organizer,
time/timezone, location, recurrence, response status and warnings. URLs are plain/sanitized external
links under existing remote-content policy; no inline script/HTML/fetch. Hidden Calendar/Mail context
does not affect counts or reveal existence.

## Calendar Decision And iMIP Response

Accept/tentative/decline preview freezes exact source revision, current Calendar/event/participant,
availability conflicts, selected calendar, account/sender/organizer target, response MIME and access
decisions. Accepting can create/link/update the Calendar event through guarded Calendar actions;
declining may retain an internal audit/reminder according to policy. It never mutates provider Mail
read/folder state or Nexum personal unread.

If a reply is required, create one immutable response intent and order-11 outbound submission. Render
standards-conformant `METHOD:REPLY`, `UID`, `SEQUENCE`, `RECURRENCE-ID`, organizer and exact attendee
response using the parser library; preserve normal Message-ID/threading without adding unrelated
recipients. Provider response loss becomes unresolved/no replay. Sent/bounce evidence updates the
response state, not the underlying local decision.

Organizer-owned event invitations/updates/cancellations use the same explicit participant preview,
Calendar action and one Email submission per authorized recipient cohort. Recipient changes require a
new preview; no bulk/list/portal/marketing shortcut. Inbound attendee `REPLY` updates only the exact
current participant after organizer/source/revision validation and conflict review.

## Revisions, Recurrence And Cancellation

Higher valid sequence or same-sequence newer identical-author revision creates a revision and exact
diff preview. Lower/out-of-order duplicates are retained/superseded without reverting Calendar.
Conflicting same-sequence content is review-only. `CANCEL` never deletes Calendar history; authorized
apply marks the event/occurrence cancelled. Recurring-instance changes bind `RECURRENCE-ID`; absent or
ambiguous series mapping cannot edit the whole series.

Time zone, DST, all-day exclusive end, recurrence/exdate/rdate and participant status use normalized
Calendar semantics and bounded expansion. Unknown/custom zones require explicit review; never replace
them silently with server/default timezone.

## Out-Of-Office

Calendar users with manage-absence authority create a private reviewed absence/availability window.
Calendar owns dates/timezone/busy state and an optional immutable OOO policy reference. Enabling an
external reply requires all order-26 gates plus a specifically allowlisted `calendar_out_of_office`
scenario/template, Mail account authority and exact preview.

Email evaluates each eligible inbound source once against the active absence window and order-26
loop/bounce/list/sensitive/rate policy. At most one reply per external sender/conversation/window;
recipient comes from the verified original thread. No Reply All, Contact creation, Calendar detail,
private event title/location or attachment enters the response. Pause/revoke/end/emergency stop blocks
new claims; submitted outcomes reconcile normally. Provider-native vacation settings are out of scope.

## Permissions And API

Invitation visibility intersects exact Mail View and Calendar visibility. Responding requires
Calendar update/create as appropriate plus Mail View and Send for the exact account. Organizer sends
also require participant-notification permission. Config-only, break-glass-only, API broad Email/
Calendar token or Ticket relation cannot respond.

Email API exposes source invitation metadata/actions only through exact placement; Calendar API owns
event/revision/response/absence resources and calls Email's public outbound action. Both use opaque
IDs, non-enumerating binding, current reauthorization, idempotency and OpenAPI schemas.

## Tests

- RFC folding/escaping/parameters/UTF-8/time zones/DST/all-day/recurrence/exception/sequence/dtstamp/
  UID/recurrence-ID/method/organizer/sent-by/attendee fixtures across Outlook/Gmail/common clients.
- Parser byte/component/property/depth/attendee/recurrence/deadline caps, invalid/repair warnings,
  external URI/binary/no-fetch, MIME spoof and clean/quarantine policy.
- Invite/update/cancel/reply/duplicate/out-of-order/same-sequence conflict, exact series occurrence,
  local event link and no accidental delete/revert.
- Mail+Calendar permission cross-product, personal/shared/delegation/revoke/break-glass, hidden context/
  counts/API and selected-source reauthorization.
- Exact accept/tentative/decline/organizer response MIME/recipient/thread, availability preview,
  concurrent/stale/idempotent decision, provider accept/Sent/bounce/response-loss no replay.
- OOO absence boundaries/timezone, layered default-off order-26 gates, one sender/thread/window,
  loop/list/bounce/sensitive/rate/cancel/emergency and no private Calendar detail leakage.
- No provider organize/read, personal-unread/Ticket/portal/Contact/rule/Signal side effects; migration/
  down/package advisory, queue/scheduler/health, API/OpenAPI, Bootstrap/mobile/accessibility tests.

## Documentation And Operations

Update Calendar/Email/Integration/Notification Knowledge, invitation/OOO privacy and incident
runbooks, API/OpenAPI, TODO, completion index and `docs/human-review.md`. Review/pin Composer package
and advisory process; deploy schema/permissions with `umask 0002`, clear caches, rebuild group-writable
views and restart workers. No migration parses history or enables OOO. Historical invitation preview/
apply is bounded and separate.

`HR-2026-08-16-029` remains Pending until a named reviewer validates real Outlook/Gmail invitation/
update/cancel/RSVP interop, recurrence/timezones, every access boundary, delivery ambiguity and the
fully gated OOO scenario. OOO activation remains off until order-26 review/activation.

## Done Criteria

- [ ] Calendar owns normalized invitation/event/response/absence decisions and Email owns exact
  source/MIME/outbound/Sent transport with no duplicate state authority.
- [ ] Bounded standards parsing handles revisions/recurrence/timezones honestly and untrusted calendar
  content cannot fetch, inject or widen access.
- [ ] RSVP and OOO reuse unified outbound/restricted-auto-reply safety with no replay on uncertainty
  or private context leakage.
- [ ] Tests, package review, migrations, UI/API, workers/scheduler, docs/runbooks and
  `HR-2026-08-16-029` are complete while named human review/OOO activation remain Pending.
