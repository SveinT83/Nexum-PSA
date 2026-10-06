The employee absence API calls the same source action and Calendar transaction as the browser.

## Access

Use a personal employee token, not a shared administrator or coordinator workload credential.
The active human worker is resolved from authentication. Ownership applies even to Superuser.
No delegated user_id is accepted.

| Method and path | Ability | Permission |
| --- | --- | --- |
| GET /api/v1/workday-absences | workday-absences.read | workday.absence_view_own |
| GET /api/v1/workday-absences/{id} | workday-absences.read | workday.absence_view_own |
| GET /api/v1/workday-absences/{id}/history | workday-absences.read | workday.absence_view_own |
| POST /api/v1/workday-absences | workday-absences.write | workday.absence_manage_own |
| PATCH /api/v1/workday-absences/{id} | workday-absences.write | workday.absence_manage_own |
| POST /api/v1/workday-absences/{id}/cancel | workday-absences.write | workday.absence_manage_own |

The list and history are paginated, default 20 and maximum 100 records per page.
Optional list status is active or cancelled. Optional from/to filters select overlapping
UTC calendar dates, inclusive. Read all pages when a complete history is needed.

## Register, correct and cancel

Every mutation requires Idempotency-Key: 8-100 letters, digits, dots, underscores, colons or
hyphens. Retry the same operation/body/key after an uncertain response. Reusing a key for a
different request returns 409. The original receipt is replayed even after later changes;
GET returns the latest record.

Example full-day POST:

~~~json
{
  "version": 0,
  "category": "sickness",
  "mode": "full_day",
  "timezone": "Europe/Oslo",
  "start_date": "2026-10-01",
  "end_date": "2026-10-01"
}
~~~

Categories: sickness, agreed_holiday, agreed_time_off, other. Full-day dates are inclusive in
the record timezone. For mode partial, replace start_date/end_date with starts_at/ends_at,
for example 2026-10-01T10:00+02:00 and 2026-10-01T12:00+02:00. The end is excluded.
Minute-precise local ISO times and UTC Z values are also accepted. Ambiguous local times
require an offset; invalid daylight-saving gaps are rejected. Periods are bounded to
366 elapsed days. Do not mix the date and time field sets.

Read data.id, data.version and data.calendar_event_id from the saved response. A correction
PATCH sends the complete period/type with the latest version. Cancellation POST sends only
version. All changes append history; the one Calendar projection follows the source
atomically. No leave request approval, balance or billing action is performed.

## Read-back and warnings

GET detail and mutation responses include plan_impact (known planned/affected minutes,
affected/remaining UTC intervals, unknown dates), has_work_conflicts and work_conflicts.
Details about actual work additionally require workday.view_own. Impact describes this
absence against the effective plan; it is not a payroll figure or aggregate of all absence.
List responses omit these calculations.

Do not infer hours for unknown dates. Do not silently adjust actual work from an absence.
If a Workday preview has data.absence_warnings, show the neutral warning and require explicit
employee acknowledgement before adding accept_absence_conflicts=true to confirmation.
Changed overlapping absence invalidates the preview with 409.

No notes, diagnosis, attachments, identity or Calendar override fields are accepted.
Shared Calendar data has a neutral title and no absence category. Personal bearer API access
is verified independently; later LiteLLM/MCP tooling is not a prerequisite for this API.

## Errors and retention

401 requires authentication; 403 denies identity, permission or scope; 404 covers disabled,
expired, missing or foreign records. 409 requires a fresh read after a version, receipt,
cancelled-state or projection conflict. 410 indicates an expired receipt. 422 indicates
invalid input or overlapping absence.

The original absence-end deadline is shared by source, revisions and receipts; corrections
do not extend the three-year period. Expired records are not served. Bounded cleanup and restore
handling are implemented under a separate default-off switch; see the retention guide and operator
runbook before activation. Both employee Workday switches remain off.
