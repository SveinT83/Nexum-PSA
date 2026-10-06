The simple work plan belongs to Profile and Calendar. The employee pilot is enabled on Dev;
production activation and the named human review remain pending.

## Use the plan when recording work

**My workdays** opens with a navigable month calendar and a proportional hourly timeline.
Today is selected initially. The first free period of up to one hour from the plan is selected;
saved work and explicit absence are excluded. Click time or a saved block to register/edit exact
minutes. The first **Save interval** creates the private record; opening a date does not create
or confirm time. Weekly hours and dated Calendar exceptions continue to own the plan.

Previously saved profile hours without dated Calendar projections are read for today/future
dates only. The application does not invent historical effective dates or write a Calendar
while reading. New Workday records use the profile timezone; existing records retain their
saved timezone. Profile/Calendar own the plan, and Workday owns actual recorded time.
The same remaining planned intervals support reminders.

## Normal working hours

When enabled, open Profile > Work plan (/tech/profile/work-plan). Set a timezone and
enabled weekdays with start/end times. An end earlier than the start means the next day.
Save applies the normal plan from today. Prior dated Calendar projections are preserved;
repeated changes on the same date replace that date's projection.

The dedicated save changes only user_profiles.working_hours and timezone. It does not
change the account name, email, roles, password or security state. Existing self/admin
profile editing also projects weekly hours while the feature is enabled.

Preferences > Calendar display start/end and timezone control presentation. Saving
preferences never overwrites weekly hours, Calendar timezone or availability rules.

## Existing Calendar rules

The read screen previews unmapped rules and a different Calendar timezone. Saving a plan
requires acknowledging preserved unmapped rules. They remain Calendar exceptions and
take precedence over profile-derived windows on their applicable weekday/date. No
historical rule is silently reclassified or deleted. Empty/default profiles do not prove
that legacy preferences may be discarded; a missing profile previews the legacy preference
values when available.

Only rules tagged calendar_default or user_profile_work_plan are maintained by projection.
Do not bulk backfill or delete unowned rules. Investigate a conflict with the employee and
use an explicitly authorized Calendar maintenance action if its old exception must change.

## Education and dated exceptions

Add an Education, Work or Other block, either once or weekly until a selected date within
one year. Phone-duty availability and meeting-booking availability are separate choices.
Calendar expands the recurrence; edit/cancel one occurrence without altering other dates.
Cancel the entire series explicitly when it no longer applies. API clients may also amend
the full series explicitly; changing recurrence frequency requires cancel/recreate.

These blocks do not establish actual work, paid education, sickness or other absence.
Phone-duty metadata does not sign users in or out of a telephone provider queue.
Use **My absences** for explicit full/partial-day absence when enabled.

Local clock gaps and repeated times during timezone changes are rejected, including later
occurrences in a requested series. Ordinary recurring start/end clock times survive DST.
Busy blocks affect Calendar Find Time and Booking through existing Calendar conflict checks.

## Employee API

Personal, active internal human identity is required. Portal-only, disabled and system actors
are denied. Ownership still applies to wildcard tokens. Token abilities never replace Calendar
create/update/delete permissions.

| Operation | Endpoint | Ability |
| --- | --- | --- |
| Read own plan/preview/revision | GET /api/v1/users/me/work-plan | users.work-plan.read |
| Replace own weekly hours | PATCH /api/v1/users/me/work-plan | users.work-plan.update |
| List own block masters, 30 per page | GET /api/v1/calendar/work-plan/blocks | calendar.work-plan.read |
| Create a block | POST /api/v1/calendar/work-plan/blocks | calendar.work-plan.write |
| Amend a block/occurrence | PATCH /api/v1/calendar/work-plan/blocks/{event} | calendar.work-plan.write |
| Cancel an occurrence/series | DELETE /api/v1/calendar/work-plan/blocks/{event} | calendar.work-plan.write |

Read the current plan first; send its revision, timezone and all seven working_hours entries.
Each day requires enabled/start/end. A stale revision returns 409. Unknown account/identity
fields are rejected. Review and acknowledge calendar_conflicts if present.

Block creation requires a request_id UUID, title, activity, timezone, starts_at/ends_at as
local YYYY-MM-DDTHH:mm, phone_duty_available, blocks_booking and recurrence_frequency.
Weekly recurrence also requires recurrence_ends_at as YYYY-MM-DD. Reusing a create UUID
with identical data returns the persisted block; changed data returns 409.

Amend/cancel requires the current version and scope=event or series. An occurrence requires
its original occurrence_starts_at with an explicit numeric UTC offset. An occurrence amend
creates one replacement and a linked exception; read back the returned ID/version. Repeated
stale mutations return 409 rather than create another replacement. Use Calendar's scoped event
read API for occurrence expansion; the plan-block index returns bounded master records.

Read responses use persisted IDs, versions and UTC instants; do not submit UTC response strings
as local form timestamps without conversion. OpenAPI is available through the installation's
existing API documentation. Generic Calendar edit/delete routes reject plan-owned blocks;
use the dedicated plan workflow.

## Operations and review

No schema migration, backfill, new queue job or scheduler is required for this slice.
Deployment requires the six documented routes and generated OpenAPI. The Dev pilot is active;
production activation awaits its review and rollout gates. Do not widen existing token grants.
Regenerate OpenAPI with php artisan l5-swagger:generate and refresh cached configuration/routes
under the normal deployment process. Use umask 0002 for commands which render views.

HR-2026-10-01-WORKDAY remains open before Main/production. Review different weekday hours,
direct entry suggestions, legacy conflicts, education recurrence, occurrence edits, timezone
behavior, mobile/keyboard use and read/write identity. Actual time, absence, oversight, reminders
and the API are implemented on Dev. Destructive retention remains disabled; MCP/LiteLLM tooling
and Tripletex transfer are future work. This page is staged Knowledge documentation; synchronize
it to BookStack only with truthful availability/release status.
