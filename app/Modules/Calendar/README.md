# Calendar Module Plan

## Purpose

Calendar is a shared tdPSA platform domain for planning work, meetings, availability, absence, shifts, reminders, and future external calendar synchronization.

The module must not be treated as a UserManagement subfeature. A personal technician calendar is only one calendar type. Tickets, sales, documentation, future tasks, absence, shift planning, and integrations all need the same calendar foundation.

## Domain Ownership

Calendar owns:

- calendars and calendar membership
- events and recurring event rules
- participants and invitations
- free/busy and availability calculations
- event privacy and visibility masking
- links between calendar events and other tdPSA records
- calendar-level access control
- timezone behavior for calendars, users, and events
- sync metadata needed by future integrations

Other domains may link to Calendar, but should not store their own calendar event model.

Integration modules such as Nextcloud and Proton will later own provider-specific sync logic, API clients, credentials, remote conflict handling, and provider settings. They should read/write through Calendar actions instead of bypassing Calendar tables.

## Knowledge Documentation

Operational Knowledge pages live with the module so they can be synced to BookStack:

- `app/Modules/Calendar/Docs/legacy-view-specs/Tech/calendar.md`
- `app/Modules/Calendar/Docs/legacy-view-specs/Tech/availability-and-privacy.md`
- `app/Modules/Calendar/Docs/legacy-view-specs/Admin/calendar-settings.md`

## Main Use Cases

- Technician personal calendars.
- Shared team calendars.
- Company calendars.
- Vacation and absence calendars.
- Shift and on-call planning.
- Meeting booking with internal users, client contacts, and external guests.
- Ticket scheduling.
- Sales activity scheduling.
- Documentation review reminders.
- Future task scheduling.
- Resource calendars such as meeting rooms, vehicles, loan equipment, or shared tools.
- Free/busy lookup across users, teams, resources, and future external calendars.
- Private appointments that only show busy time to other users.

## Calendar Types

Calendar should support a `type` field rather than hard-coded behavior:

- `personal`: user-owned work calendar.
- `shared`: manually shared calendar.
- `team`: calendar owned by a team/role/group.
- `company`: company-wide calendar.
- `absence`: vacation, sick leave, medical appointments, and other absence.
- `shift`: turnus, on-call, rota, working pattern.
- `resource`: reservable resource such as meeting room, car, loaner laptop, or equipment.
- `system`: generated calendar blocks from automation.
- `external`: imported or synced remote calendar shell.

Calendar type controls defaults, not the whole permission model. Permissions and access rows still decide who can see or edit.

## Core Data Model

### calendars

Represents a calendar container.

Important fields:

- `id`
- `uuid`
- `name`
- `slug`
- `type`
- `description`
- `color`
- `timezone`
- `owner_type`, `owner_id`
- `is_active`
- `is_default`
- `is_visible_by_default`
- `visibility_default`
- `transparency_default`
- `metadata`

Owner should be polymorphic enough for user-owned, team-owned, and system-owned calendars.

### calendar_events

Represents one logical event.

Important fields:

- `id`
- `uuid`
- `calendar_id`
- `series_id`
- `title`
- `description`
- `location`
- `meeting_url`
- `starts_at`
- `ends_at`
- `timezone`
- `all_day`
- `status`: `confirmed`, `tentative`, `cancelled`
- `transparency`: `busy`, `free`, `tentative`, `out_of_office`, `working_elsewhere`
- `visibility`: `default`, `public`, `private`, `confidential`
- `priority`
- `created_by`
- `updated_by`
- `source`: `local`, `system`, `nextcloud`, `proton`, `imported`
- `external_source`
- `external_calendar_id`
- `external_event_id`
- `external_uid`
- `external_etag`
- `sync_status`
- `last_synced_at`
- `sync_hash`
- `metadata`

Private events must still block availability when transparency is busy, but details must be masked for users without private-detail access.

### calendar_event_series

Represents recurrence master data.

Important fields:

- `id`
- `uuid`
- `calendar_id`
- `timezone`
- `rrule`
- `starts_at`
- `ends_at`
- `recurrence_starts_at`
- `recurrence_ends_at`
- `max_occurrences`
- `metadata`

Recurring events should be represented as a series plus generated/expanded occurrences at query time or cached occurrences later. Avoid duplicating endless future rows as the source of truth.

### calendar_event_exceptions

Represents recurrence modifications and skipped occurrences.

Important fields:

- `id`
- `series_id`
- `original_starts_at`
- `exception_type`: `cancelled`, `modified`
- `replacement_event_id`
- `metadata`

### calendar_participants

Represents attendees, organizers, and invited people.

Important fields:

- `id`
- `event_id`
- `participant_type`: `user`, `client_contact`, `email`, `resource`, `team`
- `participant_id`
- `name`
- `email`
- `role`: `organizer`, `required`, `optional`, `resource`
- `response_status`: `needs_action`, `accepted`, `declined`, `tentative`
- `notify`
- `metadata`

This must be flexible enough for internal users, client contacts, supplier contacts, external email participants, and resources.

### calendar_event_links

Polymorphic links between events and other tdPSA records.

Important fields:

- `id`
- `event_id`
- `linkable_type`
- `linkable_id`
- `relation`: `scheduled_for`, `follow_up`, `review`, `meeting`, `deadline`, `reminder`
- `metadata`

Expected linked domains:

- Ticket
- Client
- Client contact
- Sales opportunity or quote
- Documentation article/template
- Asset
- Future Task
- Contract/SLA
- Risk item

### calendar_access

Calendar-level permissions.

Important fields:

- `id`
- `calendar_id`
- `subject_type`: `user`, `role`, `team`
- `subject_id`
- `access_level`: `owner`, `manager`, `editor`, `contributor`, `viewer`, `free_busy`
- `can_view_private_details`
- `can_share`
- `can_manage_access`

Global permissions can still override this, but calendar access must support sharing without giving broad admin permissions.

### calendar_availability_rules

Defines normal working availability.

Important fields:

- `id`
- `calendar_id`
- `user_id`
- `timezone`
- `weekday`
- `starts_at_local`
- `ends_at_local`
- `effective_from`
- `effective_until`
- `metadata`

This is not the same as events. Availability rules say when someone normally can be booked. Events say what is already planned.

### calendar_availability_overrides

Defines exceptions to normal availability.

Important fields:

- `id`
- `calendar_id`
- `user_id`
- `date`
- `starts_at_local`
- `ends_at_local`
- `availability_type`: `available`, `unavailable`, `out_of_office`
- `reason`
- `metadata`

Useful for holiday, sickness, special workdays, and temporary schedule changes.

### calendar_settings

Global calendar defaults.

User-specific timezone, default calendar view, and workday defaults are owned by the
UserManagement module in `user_preferences`. Calendar reads those preferences and uses them when
rendering calendar views and synchronizing personal availability defaults.

Settings needed:

- system default timezone
- default workday start/end
- default week start day
- default calendar view
- default event duration
- default visibility
- default transparency
- whether users can create private events
- whether users can see other calendars by default
- free/busy masking behavior

User-level settings should override global defaults:

- timezone
- work hours
- default calendar
- default view
- selected calendar overlays

## Permissions

Recommended permissions:

- `calendar.view`
- `calendar.create`
- `calendar.update`
- `calendar.delete`
- `calendar.view_all`
- `calendar.manage_all`
- `calendar.view_private`
- `calendar.manage_shared`
- `calendar.manage_access`
- `calendar.manage_settings`
- `calendar.view_free_busy`
- `calendar.book_resources`
- `calendar.manage_shift`
- `calendar.manage_absence`

Default behavior for work use:

- Admins can view and manage all calendars.
- Coordinators can view and manage calendars they are responsible for.
- Technicians can view other work calendars by default.
- Private events are masked unless the viewer has permission to see private details.
- Private busy events still block booking.
- Personal work calendars are work calendars, not fully private user data.

## Privacy Model

Event visibility should behave like this:

- `public`: title/details visible to users with calendar view access.
- `default`: inherits calendar default.
- `private`: owner and allowed managers see details; others see only busy block.
- `confidential`: stricter private mode, visible only to owner/admin/private permission.

When masked, the UI should show:

- title: `Busy`
- time
- calendar color
- transparency/busy state
- no description, participants, links, location, or metadata

## Timezone Model

Timezone must be explicit from the beginning.

Default resolution order:

1. Event timezone.
2. Calendar timezone.
3. User timezone.
4. System default timezone.
5. Fallback `Europe/Oslo`.

Store `starts_at` and `ends_at` as absolute timestamps, with timezone retained for display, recurrence, and sync correctness.

Recurring events must evaluate in their recurrence timezone, not only UTC.

## Free/Busy And Booking

Calendar should provide a free/busy service that can answer:

- Is this user free at this time?
- Which users are free for a meeting?
- Which resources are available?
- What is the next available slot for a ticket?
- Does this proposed recurring event conflict?
- Are private events blocking time without revealing details?

Busy calculation should include:

- busy/tentative/out-of-office events
- accepted or tentative participant events
- shift/availability rules
- absence overrides
- resource reservations
- external synced events

Free/busy output should avoid leaking private details.

## Work Schedule And Turnus

Turnus should be planned as a first-class Calendar use case, but may later deserve its own module if the business rules grow.

Recommended split:

- Calendar stores and displays shift blocks, availability, and on-call events.
- A future Shift/Rota module may generate those events from templates, rotations, rules, and staffing requirements.

Calendar must therefore support:

- shift calendar type
- recurring shift patterns
- on-call blocks
- unavailable/absence overlays
- team/resource calendars
- conflict checks between shifts, absence, and booked work

## UI Plan

Tech UI:

- full calendar page
- day/week/month/list views
- multi-calendar overlay
- calendar filter sidebar
- quick event create
- full event create/edit modal
- drag/drop move and resize
- recurring event editor
- participant management
- private/public toggle
- busy/free/tentative/out-of-office selector
- linked records panel
- availability/free-busy panel

Admin UI:

- global calendar settings
- timezone defaults
- calendar type management if needed
- shared calendar management
- access and sharing management
- resource calendar management
- absence/shift policy defaults

Right sidebar integrations:

- upcoming events
- availability summary
- linked ticket/sales/documentation context
- AI context should include active calendar event when relevant

## Cross-Domain Contracts

Other domains should interact with Calendar through Actions, not direct table writes.

Expected actions:

- `CreateCalendarEvent`
- `UpdateCalendarEvent`
- `CancelCalendarEvent`
- `LinkCalendarEvent`
- `InviteCalendarParticipant`
- `CheckAvailability`
- `FindAvailableSlots`
- `CreateFollowUpEvent`
- `CreateReviewReminder`
- `CreateTicketScheduleBlock`

Expected read queries:

- `CalendarEventQuery`
- `CalendarOverlayQuery`
- `FreeBusyQuery`
- `UpcomingEventsQuery`
- `LinkedRecordEventsQuery`

Expected domain events:

- `CalendarEventCreated`
- `CalendarEventUpdated`
- `CalendarEventCancelled`
- `CalendarEventLinked`
- `CalendarParticipantResponded`
- `CalendarAvailabilityChanged`

## Integration Readiness

Nextcloud and Proton should come later through Integration, but Calendar must be ready for them.

Calendar should store:

- external source
- external calendar ID
- external event ID
- external UID
- etag/version
- sync hash
- sync status
- last synced time

Future sync behavior:

- local-only events
- remote-only imported events
- bidirectional synced events
- conflict state
- deleted remote event handling
- deleted local event handling
- private event mapping
- recurrence mapping
- participant RSVP mapping

Provider-specific quirks belong in Integration, not Calendar.

## MVP Implementation Order

Even though the module should be designed fully, implementation should land in controlled slices:

1. Module skeleton, permissions, settings, docs.
2. Calendars, access, personal default calendars.
3. Events, participants, linked records.
4. Full calendar UI with day/week/month/list.
5. Private/busy masking and policy checks.
6. Availability/free-busy service.
7. Recurrence and recurrence exceptions.
8. Shared/team/resource calendars.
9. Ticket/documentation/sales linking.
10. Absence and shift calendar support.
11. Integration sync contracts.

## Decisions

- Calendar should be its own `Calendar` module.
- Calendar is a work calendar system first.
- Personal calendars are work calendars owned by users.
- Shared, team, absence, shift, resource, and system calendars must be supported.
- Private events are allowed, but only details are private. Busy time remains visible for scheduling.
- Timezone support is mandatory from the start.
- Recurrence support is mandatory from the start.
- External integration metadata should be present early, but provider sync logic belongs in Integration later.
