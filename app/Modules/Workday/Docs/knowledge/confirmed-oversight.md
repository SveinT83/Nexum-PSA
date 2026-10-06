Authorized viewers can review effective recorded and legacy employee-confirmed work across this Nexum installation. New saves and validated Tripletex imports are effective immediately; private drafts remain excluded.

## Open and filter the report

When Workday is enabled, open **Reports > Confirmed workdays**. Filter by employee name and
inclusive work dates. The date range is at most 93 days; the default is the current Oslo calendar
month through today. Each record retains its original work date and timezone, including overnight
work. Filters match employee names, not private drafts or source notes.

The report shows confirmed days, actual minutes, attributed/unallocated minutes and work
descriptions. Totals cover all matching confirmed days, including other pages. Unallocated work
is valid. Source hours and billing totals are not added to the actual total.

Open a date for working intervals, breaks, allocations and confirmation metadata. **Confirmed
revision history** shows recorded and legacy confirmed versions, excluding private drafts. A correction in progress leaves the
last confirmation visible until the employee confirms the replacement. The report does not reveal
whether a private correction currently exists.

## Rights and privacy

The dedicated permission is workday.view_all. It is granted explicitly to Superuser through the
deployment migration and new-role bootstrap. Admin, Tech and other roles do not receive it by
default. A future HR-style role can be explicitly granted the same permission using the existing
role administration; no HR module or manager approval workflow is required.

Report hub navigation also uses report.view. That navigation right cannot substitute for
workday.view_all. The Workday report checks its domain gate before any legacy Superuser report
discovery shortcut. Explicit revocation hides it from discovery and denies list/detail/history.
Broad role reseeding preserves later explicit grant/revocation decisions.

Oversight allows no edits or confirmations on another person's behalf. Own-record APIs still
enforce ownership. Drafts, private correction reasons, raw suggestions, absence categories and
absence warnings are omitted. No leave or sickness classification is included in this report.
The report is a confirmed work record, not a live presence or productivity ranking.

Source titles and links are available on detail/history only when the viewer has current source
permission. API clients also need the corresponding source read ability. Calendar sharing and
private-detail restrictions still apply; oversight never impersonates the employee. Denied or
missing source details appear as Source unavailable, without source identifiers, notes, attendees
or descriptions. Changed sources are marked without rewriting the confirmed workday.

## API

All three operations require workdays.read-all and workday.view_all:

| Method and path | Result |
| --- | --- |
| GET /api/v1/workdays/overview | Filtered latest confirmations and full-filter totals |
| GET /api/v1/workdays/overview/{id} | Latest confirmed revision of one retained day |
| GET /api/v1/workdays/overview/{id}/history | Confirmed revisions only |

Use a personal active human viewer identity. Shared coordinator/workload credentials and system
actors cannot use this identified overview. Existing API keys are not changed by deployment;
select the new ability explicitly when configuring a suitable personal token. Existing wildcard
tokens keep their original meaning but still need the explicit Workday permission.

List filters are from, to, worker_id, worker, page and per_page. worker is a literal employee-name
substring; worker_id selects one exact employee in this installation. Both filters intersect.
Unknown filters (including company_id, tenant overrides, include=drafts) are rejected.
History accepts page and per_page only. Page size defaults to 20 and cannot exceed 100.

meta returns total, returned_count, page, per_page, last_page, next_page, status and truncated.
Complete means the first page contains the entire result; later or incomplete pages are partial.
No silent result ceiling is applied. totals covers all matching days, never only the visible page.
Pagination is a current view, not a frozen export; refresh after employee changes.

The confirmed projection includes public day/revision UUIDs, employee ID/name, original timezone,
confirmation time, actual intervals/breaks, descriptions and minimal allocation facts.
There is no current private revision/version, private correction reason or source fingerprint.
Lists do not read linked source detail (source.status=not_loaded). Detail/history check current
Task/Ticket/Calendar permissions and tasks.read, tickets.read or calendar.read token abilities.
Source status is current, stale or unavailable, independently of unchanged confirmed facts.

401 means unauthenticated; 403 means a missing permission/scope or ineligible identity; 404 means
disabled, expired, missing or never-confirmed data; 422 means invalid filters.
Responses are private/no-store. Retention remains three years, and expired days are excluded.

The feature remains default-off while the full pilot is prepared. Human review, Main/production
promotion remain separate rollout gates. LiteLLM/MCP tooling is deferred and does not block the API contract.
