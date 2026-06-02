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

The Calendar UI is available at `/tech/calendar`.

Admin settings are available at `/tech/admin/settings/calendar`.

## Visibility

Calendar visibility is privacy-aware.

Private and confidential events may be visible as busy blocks while hiding details from users who do
not have access to private details.

APIs and UI code must use the Calendar visibility services and overlay query rather than building
their own privacy rules.

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
