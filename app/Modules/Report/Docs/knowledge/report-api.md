# Report API

The Report API exposes report discovery for integrations and AI agents.

The Report domain owns the shared report hub and registry. Individual domains still own their own
report calculations and report-specific filters.

## Ability

API tokens need:

- `report.read`

## Endpoints

- `GET /api/v1/reports`
- `GET /api/v1/reports/{reportKey}`

## List Reports

`GET /api/v1/reports` returns reports visible to the authenticated API user.

Supported filters:

- `domain`
- `q`

`q` searches report key, title, description, domain, and tags.

## Report Metadata

Each report entry includes:

- stable report key
- title
- description
- owning domain
- required permission
- tags
- UI route name and URL

## Scope Boundary

This API does not calculate report results yet.

Report result APIs should be added when a shared runnable report contract exists or when the owning
domain exposes its own report-result API. This keeps the Report domain decoupled from Ticket, Asset,
Commercial, and future reporting queries.

Work Context filters are exposed by adopted domain APIs instead of the Report API. Current adopted
domain list endpoints use `work_context_id` and `context_type` where the domain owns the underlying
records, including Ticket, Task, Asset, Documentation, Risk, and Calendar.

## Coordinator Worklog API

Verified maintenance target: authoritative Dev, base commit
`36918edbcaae1ce7b1208ba34f84a3a1719f0e98` plus the 2026-09-27 working-copy repair.
The generated OpenAPI includes both operations and shared Worklog response schemas; Commercial
adds two separately scoped operations and its own fact schemas.
This is not evidence of Main or production deployment.

### Access and external clients

| Operation | Sanctum ability | Actor permission | Fixed response profile |
| --- | --- | --- | --- |
| `GET /api/v1/worklog/time-entries` | `time-entries.read` | `report.view`, `ticket.view`, `task.view` | `pseudonymized` |
| `GET /api/v1/worklog/technicians` | `worklog.read` | `report.view`, `ticket.view`, `task.view` | `pseudonymized` |

Paperclip authenticates to NexumMCP separately from NexumMCP's upstream Bearer token to Nexum PSA.
A normal token that can read Tickets does not establish coordinator access. Use one explicitly
approved `coordinator_api` workload and its bound read-only token, stored in NexumMCP's encrypted
connection secret. Do not pass a workload ID in query parameters and do not relabel an external
processing chain as local-only. Never put the token in a URL, package definition, report or chat.

Admin -> Integrations -> Privacy & Coordinator owns the existing setup:

1. Review installation limits and the actual recipients/models in the Paperclip processing chain.
   Keep the approved data profile no wider than needed; these endpoints require pseudonymized even
   for the technician aggregate because persistent employee aliases are present.
2. For external processing, record approved unexpired provider governance and model policy for the
   actual provider/model and the selected `privacy_relay` or `direct_external` mode. The latter is
   separately gated. A self-hosted Paperclip service can still send data to an external model.
   One workload describes one provider/model route; downstream routing must honor that approval.
3. Create an approved workload with purpose, expiry, explicit read abilities and permitted Client/
   Work Context lists. Workload permissions cannot widen the installation maximum.
4. Create a short-lived bound token from that workload. Use only the required abilities and an
   actor with `report.view`, `ticket.view` and `task.view`. Wildcard or write-capable tokens are refused. Optional IP restrictions
   must cover the actual NexumMCP upstream egress IP. Admin currently accepts individual IPs;
   middleware can evaluate stored IP/CIDR restrictions. Rate limit is the smaller installation/
   binding value, keyed by binding and request IP in a 60-second window.
5. Keep this connection separate from an ordinary identified/business-data token. Verify allowed
   and denied reads and metadata-only audit on the exact deployed version before releasing data.

**Context boundary repaired on Dev 2026-09-27:** shared CoordinatorReadScope now applies
installation context_scope before workload lists. internal_only requires an explicit internal
Work Context and null Client on both context and record. selected_clients requires a nonempty
Client allowlist; selected_work_contexts requires a nonempty context allowlist. Missing selections
or unknown installation modes return 403/workload_context_scope_missing. Both nonempty workload
lists intersect. Missing/inconsistent source Work Contexts are excluded rather than treated as a
fallback. Report requires report.view, ticket.view and task.view together; current source domains
have no additional granular view policy on their API query. Stale Ticket/Task coordinator queries
reuse the same context predicate and retain their source permission checks.

Ordinary source APIs are not upgraded to identified coordinator exports by this change. An
internal_model workload cannot use a manually bound API token (workload_type_not_allowed).
No policy, provider or token was enabled as part of this implementation. Exact target setup and
human verification remain required; production has not received this repair.

### Date and paging contract

Always send `date_from` and `date_to` as ISO `YYYY-MM-DD`, inclusive on stored `work_date`.
Without `date_to`, today in the installation timezone is used. Without `date_from`, the resolved
end date minus six days is used. Reversed resolved dates return 422. A range of exactly
`maximum_query_days` calendar dates is permitted. This is work date, not created/updated time.

Only time-entries supports `page` (integer >= 1, default 1) and `per_page` (integer >= 1,
default min(25, maximum_page_size)). Oversized pages return 422. Technician results are one capped
array; `page` does not paginate them. There are no request filters for source, technician, Client,
contract or billable state. Workload lists enforce the implemented context restrictions. Do not
assume unknown query keys narrow the result.

Time-entry order is descending `work_date`, then descending `entry_alias`. Technician order is
ascending `technician_alias`. The order is deterministic for unchanged data. There is no frozen
snapshot or change token; inserts, edits and deletions between pages require reconciliation/restart.

All responses contain `data` and `meta`. Common metadata:

| Field | Meaning |
| --- | --- |
| `profile` | Always `pseudonymized` |
| `date_from`, `date_to` | Resolved inclusive window |
| `total` | All matching entries, or non-null technician groups, before the result limit |
| `available_total` | min(total, maximum_results), across every page of this window |
| `returned_count` | Number of rows in this response |
| `truncated` | Whether the result limit hides matching rows |
| `recovery` | null, `split_date_range`, or `policy_limit_requires_review` for a single capped day |
| `maximum_query_days`, `maximum_page_size`, `maximum_results` | Effective installation limits |

Time-entry metadata additionally includes `page`, `per_page`, `last_page` and nullable `next_page`.
`last_page` is calculated from available_total and is at least 1. Pages after it return empty data.
There is no `links.next`. A null next_page or empty page does not prove completeness when
truncated is true. The result ceiling is intentionally retained for the entire date window.

Example of a capped window (synthetic aliases, page size 1):

```json
{"data":[{"entry_alias":"ticket_entry_0123456789ab","record_alias":"ticket_0123456789ab","technician_alias":"tech_0123456789ab","client_alias":null,"work_context_alias":null,"source":"ticket","registration_basis":"recorded","work_date":"2025-09-30","minutes":15,"billable":true}],"meta":{"profile":"pseudonymized","date_from":"2025-09-01","date_to":"2025-09-30","total":245,"available_total":200,"returned_count":1,"truncated":true,"recovery":"split_date_range","maximum_query_days":31,"maximum_page_size":50,"maximum_results":200,"page":1,"per_page":1,"last_page":200,"next_page":2}}
```

### Complete extraction: 2025-09-01 through 2026-08-31

Use time-entries as the canonical extraction, then derive summaries locally:

1. Partition the requested interval into disjoint windows no longer than maximum_query_days.
   September 2025 through August 2026 are 365 inclusive calendar dates. Monthly windows fit a
   31-day policy; a stricter policy needs smaller windows. Use page 1/per_page 1 to discover
   effective limits if they are not already configured; no separate discovery API exists.
2. Read the first page and require the new total/truncated metadata. An older response without
   those fields cannot establish completeness and must be rejected by the export client.
3. If truncated, discard all rows from that parent window, split into disjoint child windows,
   and restart each child. Do not add parent rows to child rows. Never raise a policy limit
   automatically. A single truncated date cannot be split further: stop and report the exact
   blocked date/count for an administrator's explicit policy decision or a future approved API.
4. If not truncated, follow next_page until null with identical dates and per_page. Require
   stable total and limits across pages, total == available_total, and sum(returned_count) == total.
   Deduplicate by entry_alias within the same workload; duplicates or changing counts invalidate
   the window. Do not silently switch workload, installation, provider or token scope on retry.
5. Persist a manifest containing target, version, contract hash, period, workload identity reference,
   effective policy limits, page/window counts, entry counts, summed minutes, billable-flag minutes,
   retrieval time and explicit incomplete windows. Do not claim an immutable accounting snapshot;
   re-read unchanged history for reconciliation or use a future snapshot export if required.
6. Aggregate per technician from the complete entry set; active_days is the distinct set of work
   dates. The technicians endpoint omits rows with a null user_id; retain those time entries as
   unassigned when comparing totals. Do not sum overlapping summary periods.

On 2026-09-27 Dev uses UTC, 31 days, 50 rows/page and 200 results/window, but these are configuration,
not universal API constants. Dev has AI disabled, aggregate maximum, no coordinator_api workload,
and zero rows in all three time-source tables for the requested interval. This does not establish
production history or readiness. Do not manufacture approvals to obtain a successful live response.

### Row format and coverage

Time entries contain exactly: `entry_alias`, `record_alias`, nullable `technician_alias`, nullable
`client_alias`, nullable `work_context_alias`, `source` (`ticket` or `task`), `registration_basis`, `work_date`, integer
`minutes`, and boolean `billable`. No raw IDs, names, titles, notes, rate values or invoice text.
Technician rows contain `technician_alias`, `total_minutes`, `billable_minutes`, `entry_count`,
and `active_days`.

| Source | Included | Accounting meaning |
| --- | --- | --- |
| TicketTimeEntry with task_id null | Yes, when parent Ticket is visible to the implemented query | Stored Ticket minutes and billable flag |
| TaskTimeEntry | Yes, when parent Task is visible to the implemented query | Registered Task time, including completion estimates and Ticket-owned Tasks |
| TicketTimeEntry with task_id set | No | Task customer-billing projection, possibly rounded/minimum-billed; adding it doubles counts |
| ClientContractTimeConsumption (`quick_client`) | No | Separate direct contract/timebank registration owned by Commercial |
| TicketTimeEntryAllocation | No additional time | Distribution of Ticket billing basis into covered/billable amounts |
| Task estimated_minutes field, running timers, bookings, calendar and call duration | No direct inclusion | Only persisted time entries enter worklog; completing a Task can persist an estimated entry |
| Day-level payroll, attendance or external timesheets | No implemented source found in current Dev | No payroll completeness/approval claim |

`registration_basis` is `recorded` for direct Ticket and manual/ticket_time_entry Task rows,
`estimated` for Task completion-estimate rows, and `unknown` for unrecognized Task source types.
Recorded means stored registration, not proof of stopwatch measurement or payroll approval.
Technician totals include all these registered rows; derive separate basis totals from time-entries.

`billable` is the source flag, not proof of invoice, payment, approved payroll, contract coverage,
or a final billable amount. RegisterTaskTimeEntry marks Ticket-owned Task time billable and keeps
actual minutes separately from rounded Ticket billing minutes; standalone Task entries start
non-billable. The API does not reinterpret those flags. Commercial balance calculation combines
allocated Ticket time, pending Ticket time and quick consumption; it must not be added wholesale
to the actual-time worklog.

Aliases are HMAC-derived by installation key + workload + subject type + ID. The same Client alias
can be compared only inside that scope. They change with workload or installation key and are not
anonymous data. No alias -> ordinary client_id/contract_id lookup exists. Worklog contains no
contract alias or allocation linkage. Ordinary `/commercial/contracts` IDs and nested customer
records cannot safely be joined to these aliases by guessing, names, ordering or hash reconstruction.
Approved Commercial-owned pseudonymous links are now available on Dev under separate abilities;
see `app/Modules/Commercial/Docs/knowledge/commercial-worklog-api.md`. They do not expose an inverse
identity map and never grant access to ordinary identified Commercial APIs.

### Error responses

Send `Accept: application/json` and `Authorization: Bearer ...` over validated HTTPS.

- 401: missing/invalid Sanctum authentication, JSON message.
- 403: missing report.view (message), or middleware denial with message, reason_code, request_id.
- 422: Laravel message + errors keyed by field; invalid/reversed dates, query range, page/per_page.
- 429: middleware reason_code `request_rate_exceeded`; 60-second limiter window. No Retry-After
  header is promised by this middleware. Back off; repeated requests do not waive the policy.

Stable middleware/evaluator reasons currently include:
`workload_type_not_allowed`, `workload_context_scope_missing`, `workload_token_required`, `workload_token_unbound`, `workload_token_expired_or_revoked`,
`workload_token_has_broad_or_write_scope`, `required_scope_missing`, `network_not_allowed`,
`workload_model_not_approved`, `ai_disabled`, `installation_policy_expired`,
`processing_mode_not_allowed`, `data_profile_exceeds_installation_maximum`,
`workload_not_approved`, `workload_approval_expired`, `workload_purpose_missing`,
`processing_mode_exceeds_workload_policy`, `data_profile_exceeds_workload_maximum`,
`employee_identification_disabled`, `workforce_transparency_gate_incomplete`,
`external_processing_disabled`, `privacy_gateway_disabled`, `direct_external_disabled`,
`provider_governance_missing`, `provider_not_approved`, `provider_approval_expired`,
`provider_governance_incomplete`, `processing_mode_exceeds_provider_policy`,
`data_profile_exceeds_provider_maximum`, and `request_rate_exceeded`.

Authenticated requests reaching coordinator middleware are audited with sanitized metadata.
Unauthenticated 401 requests stop before that middleware and are not coordinator audit events.
Audit failures remain errors; never infer success from absence of a returned body.

### Other reports and deployment

`GET /api/v1/reports` and `GET /api/v1/reports/{reportKey}` expose metadata only. Current
`config/reports.php` registers only `ticket.sla`. Its browser page runs TicketSlaReportQuery for
SLA summary/overdue data; it has no time-row JSON/CSV export. Do not scrape its browser URL as a
history contract. Worklog already owns the cross-domain Ticket/Task read projection; reuse it.
Commercial now owns separate quick consumption and contract-allocation reads on Dev.

This implementation needs code promotion, `php artisan l5-swagger:generate`, and the normal application
cache/opcache refresh on the target. No migration, seeding, queue/scheduler job, or asset build is
introduced. No deployment or provider/workload activation was performed. Knowledge is updated in
source for later BookStack sync. Human review: HR-2026-09-27-WORKLOG.

## Confirmed Workday discovery

When enabled and explicitly authorized, the report catalog includes workday.confirmed. The
ReportVisibility domain policy applies to list, search and direct metadata lookup. Catalog access
never substitutes for workdays.read-all plus workday.view_all on the actual Workday overview API.
Own workday scope remains self-only; no draft or absence data is added to Report responses.
