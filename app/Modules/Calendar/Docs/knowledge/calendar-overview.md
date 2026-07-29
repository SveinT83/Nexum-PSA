The Calendar Domain owns technician calendars, shared calendars, availability, participants,
recurring event expansion, privacy masking, and calendar overlays used by Warroom and future
integrations.

Core concepts:

- **Calendar** is the container for events. Calendars may be personal, shared, resource, absence,
  shift, or future synced calendars.
- **Calendar Event** is the scheduled work or appointment.
- **Participant** stores invited people or email recipients.
- **Calendar Access** controls which users and roles can view or manage a calendar.
- **Availability Rules** describe normal working windows.
- **Availability Overrides** describe exceptions such as vacation, absence, or special working
  hours.

Approved consuming domains may supply explicit weekly availability windows when their own settings
must narrow Calendar availability. Booking uses this contract for company hours, technician-profile
hours, and service-specific public opening windows. Explicit windows replace only the normal working
window for that calculation; Calendar still requires an active personal calendar and excludes busy
single and recurring events. Consuming domains must not use this contract to bypass Calendar
conflict checks or reveal private event details.

The Calendar UI is available at `/tech/calendar`.

Admin settings are available at `/tech/admin/settings/calendar`.

## Visibility

Calendar visibility is privacy-aware.

Private and confidential events may be visible as busy blocks while hiding details from users who do
not have access to private details.

APIs and UI code must use the Calendar visibility services and overlay query rather than building
their own privacy rules.

## Ownership Metadata

Ownership indicators use the calendar container's `owner_type` and `owner_id`. Event `created_by`
is audit/creator data and never changes the owner badge, group, or future `Only mine` result.

Every visible calendar event has these stable, privacy-safe fields:

- `owner_kind`, `owner_id`, `owner_label`, `owner_initials`, and `owner_badge`
- `calendar_color`, `calendar_type`, and `calendar_type_label`
- `ownership_group`: `mine`, `people`, `team`, `shared`, `resources`, or `external`
- `is_owned_by_viewer`

Ownerless team, shared, resource, system, and external calendars fall back to the calendar name and a
stable type badge. Calendar type `company` is the company-wide/global type. Calendar types
`absence` and `shift` belong to the team ownership group, while `system` and `external` belong to
the external group.

Ownership metadata is added only after `CalendarOverlayQuery` has limited the visible calendars.
It never grants visibility or private-detail access. Private and confidential events may retain the
safe owner, color, and type signals, while title, description, location, meeting URL, participants,
and integration identifiers are hidden when the viewer lacks private-detail access. The range and
single-event APIs use the same `CalendarVisibility` decision.

## Owner badges in Calendar views

Day, week, month, and list views show a compact owner badge on every visible event. The badge uses
initials or a stable short Calendar-type label, plus a color swatch. Hover text and the accessible
name state the full owner identity and Calendar type, so users never need to rely on color alone.

Examples include a technician's initials for a personal work calendar and stable labels such as
`TEAM` or `RESOURCE` when a Calendar has no direct owner. The identity always comes from the
Calendar container, not from the person who created the event.

On narrow screens, month and week keep readable event columns and allow horizontal scrolling. Long
event titles are truncated after the owner badge and time instead of overlapping nearby content.
Day and list views keep the same badge at the title edge.

Private or confidential events still show only safe scheduling context: time, `Busy`, owner badge,
Calendar color, and Calendar type. The badge does not reveal the real event title, description,
location, participants, meeting link, or synchronization identifiers.

## Calendar types, groups, and filters

Non-personal events have a second short badge for Calendar type: `SHR` for shared, `TM` for team,
`GLB` for company/global, `ABS` for absence, `SHF` for shift, `RES` for resources, `SYS` for system,
`EXT` for external, and `CAL` for an unrecognized fallback. The complete type is available to
assistive technology and as hover text. Personal events keep only their owner badge. Color is a
supporting signal and is never the only distinction.

The sidebar groups calendars as Mine, People, Team, Shared/global, Resources, and External/system.
Only groups backed by a calendar the current user can see are offered. The number beside each filter
is the number of visible calendars in that group.

`Only mine` selects calendars owned by the signed-in user through the Calendar's `owner_type` and
`owner_id`. It does not include an event merely because the user created it, was invited to it, or
participates in it. Event creator data remains audit information. A separate creator/participant
`My events` filter and event-level responsibility are not part of this rollout.

Ownership groups may be combined. If individual calendars are also selected, both filters must
match. A filter with no matching visible calendar shows an explicit empty result. Clear ownership
filters removes only the ownership limitation. Visibility and private-detail permissions are always
applied on the server before filter results are rendered.

Ownership state is preserved when switching Calendar view, changing date, searching, sorting, using
Find Time, or opening a dense day from month view.

## Dense month and mobile use

Month and week keep readable column widths and scroll horizontally on narrow screens. The Calendar
surface is keyboard-focusable and supports touch scrolling. A month day shows at most five event
rows; `+N more` opens that filtered date in day view without triggering the event-creation panel.
Long titles truncate independently from owner/type badges and time.

Calendar events also store `work_context_id`. Current Calendar events resolve to the default
internal Work Context because event visibility and access are still controlled by calendar ownership,
participants, and Calendar Access. The Work Context field exists so overlays, reports, and
integrations can filter internal calendar work consistently as other domains adopt the contract.

## API

Calendar exposes API routes under `/api/v1` for trusted integrations and AI-assisted technician
work.

Implemented scopes:

- `calendar.read`: list visible calendars and view calendar events.
- `calendar.create`: create calendar events.
- `calendar.update`: update calendar events.
- `calendar.delete`: delete calendar events.

Implemented routes:

- `GET /api/v1/calendars`
- `GET /api/v1/calendar/events`
- `GET /api/v1/calendar/events/{event}`
- `POST /api/v1/calendar/events`
- `PUT /api/v1/calendar/events/{event}`
- `PATCH /api/v1/calendar/events/{event}`
- `DELETE /api/v1/calendar/events/{event}`

`GET /api/v1/calendar/events` uses the Calendar overlay query so visibility and recurring event
expansion match the Tech UI.

The event list supports `work_context_id` and `context_type` filters. Event responses include
`work_context_id`, `work_context_type`, and the loaded `work_context` object when available.

Common event fields:

- `calendar_id`
- `title`
- `description`
- `location`
- `meeting_url`
- `starts_at`
- `ends_at`
- `timezone`
- `all_day`
- `status`
- `transparency`
- `visibility`
- `participants`

When `calendar_id` is omitted during event creation, the API uses the authenticated user's personal
work calendar.

Calendar event create and update must use the Calendar actions so participants, recurrence defaults,
timestamps, actors, and future sync behavior stay consistent.
