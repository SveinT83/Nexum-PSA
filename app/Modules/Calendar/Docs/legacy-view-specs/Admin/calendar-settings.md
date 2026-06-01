## Purpose

Calendar settings let administrators define global calendar behavior, create shared calendars, and
manage calendar access.

The Calendar module owns calendar records, event records, availability, recurrence, privacy masking,
and calendar-level sharing. Provider-specific synchronization, such as Nextcloud or Proton, belongs
to future integration work.

## Global settings

Administrators can configure the default calendar behavior used when a user or calendar does not
provide a more specific value.

Important settings:

- Default timezone.
- Default calendar view.
- Workday start time.
- Workday end time.
- Default slot length.
- Whether weekends are shown by default.
- Whether event privacy is enforced.

The system timezone is a fallback. Individual users and calendars can still use their own timezone.

## Calendar types

Calendars support these types:

- Personal: user-owned work calendar.
- Shared: manually shared calendar.
- Team: calendar intended for a role or group.
- Company: company-wide calendar.
- Absence: vacation, sick leave, medical appointments, and similar absence.
- Shift: turnus, on-call, or rota planning.
- Resource: reservable rooms, vehicles, loan equipment, or shared tools.
- System: generated calendar blocks from automation.
- External: remote calendar shell for future synchronization.

Calendar type controls defaults and organization. Access rows still decide who can view, edit, or
manage the calendar.

## Creating shared calendars

Administrators can create shared calendars from Calendar Settings.

Required fields:

- Name.
- Type.
- Timezone.
- Color.

Optional fields:

- Description.
- Visibility default.
- Transparency default.
- Visible by default.

Archived calendars remain in the database but stop appearing as active planning calendars.

## Access management

Access can be granted to users and roles.

Supported access levels:

- Owner.
- Manager.
- Editor.
- Contributor.
- Viewer.
- Free/busy.

Additional access flags:

- Can view private details.
- Can share.
- Can manage access.

Use free/busy access when users should know whether time is occupied without seeing event details.

## Defaults for technicians

When a technician opens Calendar, tdPSA ensures that the user has:

- A personal work calendar.
- Weekday availability rules.
- Calendar permissions needed for normal personal calendar use.

This defaulting process is safe to run multiple times and only creates missing records.

## Recurrence and exceptions

Recurring events use a series record plus expanded occurrences at query time.

If one occurrence is cancelled, tdPSA creates an exception instead of deleting the entire recurring
series. If the entire series is deleted, the series and its events are removed together.

## Current boundaries

Calendar has a generic event-link table for future scheduling from Tickets, Documentation, Sales,
Assets, Tasks, and other domains. Those domain-specific flows are not enabled until each domain is
explicitly connected to Calendar.
