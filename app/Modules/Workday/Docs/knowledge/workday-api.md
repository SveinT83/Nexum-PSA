The employee Workday API supports the same actual-time workflow as the browser.

## Authentication and ownership

Use a personal employee token with the required abilities and matching Workday permissions.
The server resolves the worker from the authenticated active human. No user_id or delegated
worker field is accepted. System actors, portal-only users without employee permissions and
coordinator workload-bound tokens are denied, including wildcard credentials.

The API is independent of the external gateway. LiteLLM is the intended external direction;
MCP/adapter implementation and live testing are deferred. The complete operation inventory,
retry rules and examples are in the Workday API consumer guide. Existing coordinator
worklog read credentials are not employee delegation.

## Effective Save

Use PUT /api/v1/workdays/{work_date}/save for the current workflow. Supply an Idempotency-Key,
the latest version (0 for a new date), timezone, description and either intervals/breaks or
durations. Save returns a recorded revision that is immediately effective; no confirmation
ability or preview is required. Empty intervals, or duration rows set to 0, explicitly remove time.
Duration input uses activity_id, optional project_id, hours (two decimals maximum) and comment.
Duration output uses integer units, each 0.01 hour. Clock detail is optional. Actual/unallocated
minute totals may be fractional. A time_sync object reports delivery state for mapped employees.

The confirmed response property is a compatibility name for the effective revision; inspect
its state to distinguish recorded from legacy confirmed time. Only imported revisions have
origin sync; their immutable author is a protected integration actor, not a forged confirmation.
Existing /draft, /preview, /confirm and /corrections operations retain their legacy semantics.

## Operations

| Method and path | Token ability | Permission |
| --- | --- | --- |
| GET /api/v1/workdays/{work_date}/entry | workdays.read | workday.view_own |
| GET /api/v1/workdays | workdays.read | workday.view_own |
| GET /api/v1/workdays/{id} | workdays.read | workday.view_own |
| GET /api/v1/workdays/{id}/history | workdays.read | workday.view_own |
| PUT /api/v1/workdays/{work_date}/save | workdays.write | workday.manage_own |
| PUT /api/v1/workdays/{work_date}/draft | workdays.write | workday.manage_own |
| POST /api/v1/workdays/{id}/preview | workdays.read | workday.view_own |
| POST /api/v1/workdays/{id}/confirm | workdays.confirm | workday.confirm_own |
| POST /api/v1/workdays/{id}/corrections | workdays.write | workday.manage_own |
| GET, PATCH /api/v1/workday-settings | workdays.settings | workday.manage_settings |

IDs are public UUIDs. List/history use pagination (default 20, maximum 100) and return data,
total, current_page, last_page, per_page and next_page_url. List accepts optional inclusive
from/to dates. Do not treat the first page as the complete employee history.

## Open a date before registering time

GET /api/v1/workdays/{work_date}/entry returns the same effective work-plan/free-time context
used by the calendar. It creates nothing: data.day is null and version is 0 until first save.
The response distinguishes planned_intervals, available_intervals and suggested_interval
from saved actual time in data.day.current.snapshot. Suggestions are at most one hour, with
whole-minute timestamps and explicit timezone offsets; no automatic confirmation occurs.
Partial absence and adjacent own overnight records are considered. Unknown plans remain unknown.
can_edit combines current state, employee manage permission and workdays.write scope;
requires_correction is false for the effective Save workflow; the legacy draft endpoint still requires its old correction operation. Read-only callers can still read the context.
No worker/query/body parameters are accepted. Expired dates return 404 and invalid dates 422.

Use the draft operation to add/edit/remove intervals. It replaces the complete intervals and
breaks lists: preserve other saved entries, send the current version and a fresh Idempotency-Key.
Copy only writable fields, excluding returned minutes/totals. Returned intervals are sorted;
indexes are version-specific. Overlap is rejected and touching boundaries are allowed.
The last interval cannot be removed. Read the saved response and GET the latest day afterward.
The full consumer guide provides exact-minute examples and the later MCP operation mapping.

## Safe write sequence

Send a unique **Idempotency-Key** header (8-100 characters: letters, digits, dot, underscore,
colon or hyphen) with every mutation, including preview. On a network timeout, retry the same
operation/body/key. The response is the persisted original result, even if later revisions exist.
Use GET to inspect the latest state. Reusing a key for another request returns 409.

Create a draft with version 0:

```json
{
  "version": 0,
  "timezone": "Europe/Oslo",
  "description": "Work - unspecified",
  "intervals": [{"start": "2026-10-01T08:00+02:00", "end": "2026-10-01T16:00+02:00", "description": "Support"}],
  "breaks": [{"start": "2026-10-01T12:00+02:00", "end": "2026-10-01T12:30+02:00", "included": false}]
}
```

Read data.id and data.version from the response. Later draft saves require the current version.
The timezone is fixed at first creation. Snapshot intervals are returned in UTC with actual
minute totals; work_date retains its local business date.

POST preview with `{"version": 1}`. Present data.current.snapshot, totals, work_date, timezone
and the exact version to the employee. Only after explicit confirmation, POST confirm:

```json
{"version": 1, "preview_token": "UUID-from-preview", "confirmed": true}
```

The preview expires after at most 30 minutes. A new draft, foreign token, expired preview or
stale version cannot confirm a different revision. Never automatically set confirmed=true
because the employee saved a draft or an AI tool found activity evidence.

To change a confirmed day, POST corrections with current version and a nonempty reason.
Save changes to the resulting draft using its new version, preview and confirm again.
The previous confirmed snapshot remains in data.confirmed until that final confirmation.

## Errors and settings

- 401: authentication required.
- 403: missing ability/permission, ineligible identity or coordinator credential.
- 404: feature disabled, missing/expired day or foreign owner.
- 409: version, preview or idempotency conflict; reread and explicitly review the current state.
- 410: expired mutation receipt.
- 422: invalid dates, intervals, break treatment, overlaps or unexpected input.

Settings PATCH accepts only version and enabled, with an idempotency key. Retention is fixed to
three years; it cannot be overridden by this API. enabled is the saved installation choice;
effective_enabled also requires the deployment switch. Reading or saving settings does not
override that switch. Configuration receipts retain actor, operation, resulting version and time.

POST /api/v1/workday-settings/retention-preview requires workdays.settings and workday.manage_settings; it accepts no input and returns metadata-only counts. Cleanup runs through the separately activated server command, with no HTTP purge endpoint. Tripletex synchronization remains future work. The generated OpenAPI contract documents the implemented paths.

## Absence overlap during confirmation

The separate own absence API uses /api/v1/workday-absences; see the absence API guide.
Preview and own workday reads return data.absence_warnings without revealing the category.
If warnings are present, show them and obtain explicit employee acknowledgement before adding
accept_absence_conflicts=true to confirmation. Changed overlapping absence invalidates the
preview with 409. Excluded breaks do not contribute to overlap; no actual hours are changed.

## Source discovery and attribution

GET /api/v1/workdays/{id}/sources uses workdays.read and own view permission, plus the selected
source domain read ability/permission. PUT /api/v1/workdays/{id}/allocations uses workdays.write
and own manage permission; send version and the complete allocation list with Idempotency-Key.
The draft operation also accepts optional allocations: omit to preserve, [] to clear.

Source references are revalidated at save, preview and confirmation. Changed/deleted/revoked
sources return 422 until explicitly reconciled. Current and confirmed_reconciliation status is
live; confirmed snapshots remain immutable. This API attributes existing actual minutes and
never creates additional actual or billing time. See the source-reconciliation guide and
generated WorkdaySource/WorkdayAllocationInput contracts for fields and completeness limits.

## Confirmed oversight

The separate /api/v1/workdays/overview list/detail/history requires workdays.read-all plus
workday.view_all. Own scopes remain self-only. It exposes only employee confirmations, with
bounded dates and full-filter totals; see the confirmed-oversight guide for exact fields,
source permissions and pagination. No existing token is upgraded automatically.

## Reminder API

The separate workday-reminders.read/write abilities cover own pending reminder reads, notification
preference read/replacement, and generation-checked 30-minute snooze. These operations reuse
Notification-owned preferences and the same Workday access gates. See [Reminders](reminders.md).
No existing employee or coordinator tokens automatically acquire these scopes.

## Explicit internal Task conversion

POST /api/v1/workdays/{id}/task-conversions/preview and /task-conversions share the browser action.
GET /api/v1/workdays/{id}/task-conversions/{token} reads the persisted preview or creation receipt.
All require workday-task-conversion.write plus tasks.read/create/update and the corresponding
Task permissions with workday.manage_own. See [Task conversion](task-conversion.md) for exact
payloads, explicit acceptance, source guards, correction behavior and read-back. Workday confirmation
never invokes these actions implicitly. Existing Task/Ticket time is linked through allocations.
