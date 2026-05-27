## Purpose

Availability and privacy rules decide what time appears free or busy, and which users can see event
details.

The calendar must protect private event details while still keeping scheduling accurate.

## Availability

Availability is calculated from two kinds of records:

- Availability rules: normal working availability, such as Monday to Friday from 08:00 to 16:00.
- Calendar events: busy, tentative, out of office, and working elsewhere events that block or affect
  scheduling.

The availability finder only returns slots inside working availability rules and excludes blocked
time from calendar events.

## Busy behavior

An event's transparency controls whether it blocks time:

- Busy blocks availability.
- Tentative can be treated as occupied by scheduling logic.
- Out of office blocks availability.
- Working elsewhere can be shown separately while still affecting planning.
- Free does not block availability.

Private events still block time when their transparency is busy. Users who do not have permission to
view private details see the block as Busy without the original title, description, location, or
meeting URL.

## Privacy levels

Events support these visibility values:

- Default: follows the calendar default.
- Public: details can be shown to users with normal calendar access.
- Private: details are hidden unless the user has private-detail access.
- Confidential: reserved for stricter future handling and should be treated carefully.

## Calendar access

Calendar access can be granted to users and roles.

Supported access levels:

- Owner.
- Manager.
- Editor.
- Contributor.
- Viewer.
- Free/busy.

Access rows can also grant these capabilities:

- View private details.
- Share calendar.
- Manage calendar access.

Global admin permissions can override calendar-level access, but normal sharing should use calendar
access rows so administrators do not need to give users broader system permissions than required.

## Recurring availability impact

Recurring events are expanded when the calendar or free/busy checks are queried.

Cancelled recurring occurrences are skipped, so one cancelled occurrence does not block availability
while the rest of the series still does.

## Operational notes

When a technician says a slot looks available even though it should be busy, check these items:

- The event calendar is active.
- The event has a blocking transparency value.
- The viewer has access to the calendar or is expected to see only free/busy data.
- The event timezone and user timezone are correct.
- A recurring occurrence has not been cancelled.
- The user's working availability rules cover the requested time range.
