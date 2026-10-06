Employees register administrative absence in **My Day > My absences**. Workday owns the
record; Calendar displays one neutral **Unavailable** block. This rollout remains disabled.

## Register or correct absence

Choose **Register absence**, an absence type, a timezone and a period. The supported types are
sickness, already agreed holiday, agreed time off in lieu, and other absence. Sickness is
registered immediately. Recording holiday or time off does not approve a request or change
an entitlement or balance.

Choose **Full calendar days** for an inclusive first and last date, or **Specific times** for
a partial period. Times use minute precision; an ambiguous daylight-saving hour needs an
explicit UTC offset. Do not enter diagnoses or medical information: this form has no notes,
attachments or medical fields.

Save and read back the registered period, version and affected planned minutes. **Save correction**
updates the same source and Calendar block and adds a history revision. The original timezone
and three-year retention deadline stay fixed. Active own absence periods cannot overlap;
correct or cancel the existing record first.

**Cancel absence** preserves history and removes the block from availability calculations.
A cancelled record cannot be reopened; create a new record if necessary.

## Work plan and actual work

Affected minutes come from the effective dated weekly plan and explicit work/education blocks.
A full calendar-day block covers that calendar day, but its affected work total only counts
known planned intervals. Partial absence affects only the overlap; the rest remains available.
Ordinary busy meetings do not reduce the work plan. Existing plan unavailability does.

Unknown hours are shown explicitly; no eight-hour standard is assumed. Legacy/default
Calendar hours without an explicitly authored effective plan are not treated as confirmed
working hours. Review **Profile > Work plan** when the plan is incomplete. Current impact
is recalculated on read and is not a historical payroll calculation.

If absence overlaps recorded actual work, the screen warns the employee. Neither registration
is changed automatically. Review and correct the appropriate record. Workday confirmation
requires explicit acknowledgement of any remaining overlap, and a changed overlapping absence
invalidates an earlier confirmation preview. Excluded breaks do not count as work overlap.

## Privacy and Calendar ownership

Own absence access uses separate permissions: **workday.absence_view_own** and
**workday.absence_manage_own**. Admin and Superuser are also limited to their own source
records. Shared Calendar views reveal availability without the absence category.
Actual-work conflict details additionally require **workday.view_own**.

Calendar users cannot edit, delete, reclassify or make recurring copies of the owned block.
The owner uses **My absences** instead. A calendar containing retained absence blocks cannot
be archived. Source updates and the Calendar projection commit together; stale changes
are rejected with a reload link.

Existing independent Calendar events are not imported, linked or interpreted as sickness.
Nextcloud sync skips these projections. Phone queue changes, external calendar export,
holiday approval/balances and Tripletex synchronization are outside this delivery.

## Retention and rollout

Source, revisions and receipts use the original absence-period end to set a three-year
deadline, at midnight after the third anniversary in the original timezone. Corrections do
not extend it. Expired own records and Calendar projections are not returned. Bounded cleanup,
including owned Calendar projections and restored copies, is implemented with a separate
default-off activation switch. See [Retention](retention.md) and the operator restore runbook.

The complete Workday pilot and human review **HR-2026-10-01-WORKDAY** remain pending.
Both installation and deployment switches must remain off until the rollout requirements
are satisfied.
