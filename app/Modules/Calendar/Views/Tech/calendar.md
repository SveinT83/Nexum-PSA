## Purpose

The Calendar module gives technicians a shared planning surface for work appointments, meetings,
availability, absence, shifts, and future scheduling workflows.

Calendar is its own tdPSA domain. A technician's personal calendar is one calendar type, but the
same module also supports shared, team, company, absence, shift, resource, system, and external
calendar containers.

## Opening the calendar

Technicians open the calendar from the Work navigation.

When a technician opens the calendar for the first time, tdPSA creates a personal work calendar for
that user if one does not already exist. The default personal calendar uses the user's timezone when
available, then the configured system calendar timezone.

## Views

The technician calendar supports these views:

- Month view for broad planning.
- Week view for near-term planning.
- Day view for focused scheduling.
- List view for scanning upcoming events.

The selected view can be changed from the calendar page. The user's preferred default view can be
stored in My Settings.

## Creating events

Use Add Event to create a calendar item.

Important event fields:

- Calendar: the calendar that owns the event.
- Title: the visible event name.
- Location: optional physical location.
- Meeting URL: optional online meeting link.
- Start and end: the event time range.
- Timezone: the timezone used for the event.
- Visibility: public, private, confidential, or calendar default.
- Transparency: busy, free, tentative, out of office, or working elsewhere.
- Participants: optional attendee email addresses.
- Recurrence: none, daily, weekly, or monthly.

Clicking a day or time area in the calendar pre-fills the event date and time before the event is
saved.

## Editing events

Click an event to open the event modal.

Users with edit access can update event details, including title, description, location, meeting URL,
time range, timezone, visibility, and transparency.

Users without detail access to a private event will only see a masked Busy block and cannot edit the
event from the modal.

## Deleting and cancelling events

Single non-recurring events can be deleted from the event modal.

For recurring events, technicians can choose:

- This occurrence: cancels only the selected occurrence.
- Entire series: removes the full recurring series.

Cancelling one occurrence stores an exception. The original recurring series remains active for
other dates.

## Participants

Participants can be entered as email addresses separated by commas, semicolons, or new lines.

The current implementation records participants on the calendar event. Invitation email delivery and
external attendee response handling are planned for later integration work.

## Find Time

The Find Time panel searches available slots based on working availability and busy calendar events.

Private busy events still block availability, even when the event details are hidden from the viewer.

## Personal settings

My Settings lets each technician configure:

- Timezone.
- Default calendar view.
- Workday start time.
- Workday end time.

Updating workday start and end updates the technician's weekday availability rules for the personal
work calendar.

## Current boundaries

Calendar is ready to be linked from other tdPSA domains later, but Ticket, Documentation, Sales,
Task, and Asset scheduling should not be treated as active user-facing calendar flows until those
domains are explicitly connected to Calendar.
