# Workday Implementation Plan

## Target transition - 2026-10-05

The [automatic Workday/Tripletex delivery plan](2026-10-05-workday-tripletex-delivery-plan.md)
now owns the next approved target: effective Save, optional clock intervals, duration-only
entries and automatic two-way create/update. Its four slices are not started.
This plan remains the historical delivery record for the current confirmation-based Dev
implementation. Its confirmation requirements are not requirements for the new target.
Existing human-review history and unrelated outstanding checks remain preserved.

Status: Approved by Svein Tore on 2026-10-01; Slices 01-09 Done On Dev; employee pilot active on 2026-10-03.
Calendar timeline with proportional minute blocks and new/edit modal implemented/tested
on 2026-10-05; human review In Review; cleanup disabled. See
[modal verification](2026-10-05-workday-modal-verification.md) and
[calendar verification](2026-10-04-workday-calendar-timeline-verification.md).
The earlier picker-only UI was rejected and is superseded.
See [direct-entry verification](2026-10-04-workday-direct-entry-verification.md) and
[pilot activation](2026-10-03-workday-dev-pilot-verification.md). Connected-browser login and
interactive human verification remain outstanding.
Calendar/modal UX approved by Svein Tore on 2026-10-05. API parity follow-up: 35 operations,
date-entry context and minute-edit workflows verified; see
[API verification](2026-10-05-workday-entry-api-verification.md).

Date: 2026-10-01
Owner: Codex; product owner and human reviewer: Svein Tore
Authoritative checkout: /var/Projects/tdPSA, branch Dev
Parent: [RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Architecture: [ADR](../adr/2026-10-01-workday-time-evidence-and-billing.md)
Register: [TODO](../TODO.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md)

## Target And Agreed Boundary

Deliver one operational workspace where employees record actual working time and breaks,
describe what they did, and confirm their own day. Own confirmation completes the Nexum workflow.
Superuser and explicitly authorized future roles see named confirmed work. No manager approval
queue is added to daily time registration.

Include a simple weekly work plan with recurring/dated exceptions, simple full/partial-day absence
and Calendar display, source-time reconciliation, employee API/MCP parity, profile notification
choices and three-year retention. The complete workflow works without AI.
Use English interface text, shared Bootstrap components, Livewire's existing single Alpine runtime,
and the same responsive UI and domain actions on desktop, mobile and API.

Keep future requirements documented: Tripletex transfer with approval in Tripletex; holiday
applications/approval, first-come-first-served rules and maximum concurrent holiday capacity;
mandatory holiday periods, annual/carryover/flex balances, advanced rota planning and actual
phone-provider queue control. None is a prerequisite or a visible stub in this delivery.
Optional AI description/grouping and additional business-action adapters remain a separately
scoped enhancement; the required employee use of external MCP/AI tools is included now.

## Verified Starting Point And Prerequisites

This planning inspection read authoritative Dev source and documents; it did not run Laravel tests
or claim production parity. All implementation sessions must recheck shared working-copy state.

| Existing component | Evidence and consequence |
| --- | --- |
| UserManagement profile | [UserProfile](../../app/Modules/UserManagement/Models/UserProfile.php) stores weekly working_hours/timezone. [Profile view](../../app/Modules/UserManagement/Views/profile/index.blade.php) already exposes weekday start/end. Reuse it. |
| Competing workday defaults | [UpdateUserPreferences](../../app/Modules/UserManagement/Actions/UpdateUserPreferences.php) writes the same workday_start/end to Monday-Friday Calendar rules. Resolve this before using reminders or declaring the weekly plan reliable. |
| Calendar | Availability rules already have effective dates; overrides and event recurrence exist. [LinkCalendarEvent](../../app/Modules/Calendar/Actions/LinkCalendarEvent.php) supports source links. Extend owner-aware editing without duplicating Calendar. |
| Task actual time | [TaskTimeEntry](../../app/Modules/Task/Models/TaskTimeEntry.php) has date/optional intervals/minutes/source_type. Ticket-owned registration may create a billing projection; Workday conversion must not enter that path implicitly. |
| Coordinator worklog | [Report API](../../app/Modules/Report/Docs/knowledge/report-api.md) documents read-only workload binding, pagination and recorded/estimated/unknown semantics. This is not an employee write/delegation mechanism. |
| Notifications | [Registry](../../app/Modules/Notification/Support/NotificationTypeRegistry.php) already controls profile choices, channels/defaults and delivery policy. Reuse it. |
| Telephony | [Existing scope](../../app/Modules/Telephony/Docs/knowledge/telephony-domain-overview.md) is call intake. A phone-duty flag is not proof of provider queue membership. |
| Workday | No module existed at planning time. Slice 02 added the singular module, day/revision/preview/receipt tables and nine API operations on Dev; see [verification](2026-10-02-workday-slice-02-verification.md). Slice 03 adds own absence, history/receipts, neutral Calendar projections and six own API operations. Slice 04 adds guarded source discovery and allocation revisions; Slice 08 implements the separately activated retention lifecycle below. |

Live GitHub open-issue searches for Workday and working-hours returned no matching issues on
2026-10-01. The worklog search returned [#288](https://github.com/SveinT83/Nexum-PSA/issues/288)
(completeness), [#289](https://github.com/SveinT83/Nexum-PSA/issues/289) (published spec/build
identification) and [#290](https://github.com/SveinT83/Nexum-PSA/issues/290) (coordinator setup).
Their production/deployment criteria remain separate from Dev repairs. Do not close them, repeat
their implementation or widen their grants as a side effect of this plan. No GitHub artifact was
created or modified by this planning task.

The existing Vault Slice 04 workstream is recorded as blocked on pending-admin onboarding.
Before coding, recheck its actual state and shared UserManagement/Integration files; do not overwrite
it or treat its blocker as permission to weaken security. This Workday planning task is complete;
runtime work starts only after the parent implementation approval and a clean coordination preflight.

Relevant existing review gates remain open:
- HR-2026-09-27-WORKLOG: source worklog/Commercial export, Main/production/external-workload gate.
- HR-2026-09-03-004: shared Notification registry/Web Push work, pending human review.
- HR-2026-09-04-003: protected user/security/Vault work, pending and not ready for its full UI review.
Read their current entries before any overlapping migration or release. Unrelated open reviews do
not require redoing unrelated work; no existing entry is marked reviewed by this plan.

## Ownership Contract

| Owner | Responsibility |
| --- | --- |
| UserManagement | User identity, normal weekly working hours, timezone, profile shell and existing administration. |
| Calendar | Effective availability, recurring plan blocks/exceptions, shared free/busy display and source-linked absence projections. |
| Workday | Daily actual intervals/breaks, private drafts, confirmation/revisions, source allocations, simple absence, domain API, confirmed detail/oversight and retention of its data. |
| Report | Shared report discovery/navigation; keep existing source-time reports distinct from confirmed Workday totals. |
| Task/Ticket/Commercial | Existing source facts and business rules; no billing or source mutation during day confirmation. |
| Notification | Event registry, profile preferences, delivery jobs and channel authorization. |
| Integration | API-key/ability catalog, current authentication/egress policy and OpenAPI entry points. |
| External LiteLLM/MCP tooling (deferred) | Later API consumer/adapter. PSA owns the complete domain API; no gateway connection is required for this delivery. |
| DataExchange | Future Tripletex mapping/run/delivery contract, outside current runtime delivery. |

## Execution Order

Execute one primary slice at a time. Every slice delivers its UI/API path and tests together.
The feature remains disabled for normal use until the complete agreed workflow is ready.

| Slice | Deliverable | Depends on | Required evidence |
| --- | --- | --- | --- |
| [01](../feature-slices/2026-10-01-workday-01-simple-work-plan.md) | Simple Work Plan And One Working-Hours Source | Approval/preflight | Plan/Calendar/Booking regression |
| [02](../feature-slices/2026-10-01-workday-02-manual-days-and-api.md) | Manual Workdays, Confirmation And Employee API | 01 | UI/API, ownership, version and total checks |
| [03](../feature-slices/2026-10-01-workday-03-absence-and-calendar.md) | Simple Absence And Consistent Calendar Display | 01-02 | Calendar consistency and privacy |
| [04](../feature-slices/2026-10-01-workday-04-source-reconciliation.md) | Existing Time And Calendar Evidence Reconciliation | 01-03 | Actual-time/billing reconciliation |
| [05](../feature-slices/2026-10-01-workday-05-confirmed-oversight.md) | Confirmed-Time Oversight And Reusable Rights | 02-04 | Role matrix and confirmed totals |
| [06](../feature-slices/2026-10-01-workday-06-profile-reminders.md) | Workday Reminders And Profile Notification Choices | 01-05 | Preference, scheduler and delivery checks |
| [07](../feature-slices/2026-10-01-workday-07-explicit-task-conversion.md) | Explicit Internal Task Conversion Without Duplicate Time | 02, 04 | One Task/time row, zero billing side effects |
| [08](../feature-slices/2026-10-01-workday-08-three-year-retention.md) | Three-Year Retention And Recovery Operations | 01-07 | Expiry/restore fixture and source preservation |
| [09](../feature-slices/2026-10-01-workday-09-mcp-and-release-verification.md) | Employee API Contract And Consumer Handoff | 01-08 | Personal bearer workflow, all 34 OpenAPI operations and consumer guide |

Dependencies explain safe prerequisites, not authorization for parallel agents. All nine slice
documents were approved for sequential implementation by Svein on 2026-10-01. Optional AI and future Tripletex work
do not hold up the core release.

## Daily Data And State Contract

Proposed logical storage; confirm conventions and migration order before creating tables:

| Record | Proposed identity/content | Invariant |
| --- | --- | --- |
| Workday day | Stable public ID, worker, work_date, timezone, current version, last confirmed revision | One day per worker/work date; worker identity is server-resolved. |
| Day revision | Day/version, draft or confirmed, author/time, interval/break/description snapshot, confirmation evidence | A confirmed revision is immutable; correction creates a new revision. |
| Source allocation | Revision, source kind/ID/revision, attributed minutes, optional placement and minimal provenance | Reuse source time once; no billing projections and no invented intervals. |
| Absence/revision | Worker, date/instant range, type, active/cancelled state, version and minimal history | Own reason visibility; an ordinary shared view exposes only unavailability. |
| Mutation receipt | Actor, action, idempotency key, payload fingerprint, target/result identity and version | Same key/same payload replays; same key/different payload conflicts. |
| Reminder receipt | Worker, work date, reminder generation, snooze/delivery state | One logical reminder with current-state authorization at delivery. |

Prefer normalized queryable identity/version fields and structured immutable snapshots for
intervals/breaks; do not copy entire Ticket/Task rows, messages or Calendar descriptions.
Use the existing Calendar link/provenance mechanism for projections rather than another calendar.
Receipt, audit and export design must support the three-year lifecycle from the first migration.

State flow: new -> draft -> preview exact version -> employee-confirmed. A correction drafts a
new revision while the previous confirmed revision remains visible; explicit confirmation replaces
the current confirmed pointer. No manager approval state exists. Source drift marks reconciliation
needed without changing a confirmed statement.

Actual totals come from confirmed working intervals and explicit break treatment. Unallocated
work is valid. Several labels may describe concurrent activity; numeric allocations cannot count
elapsed minutes more than once. Validate cross-day overlaps for the same employee.
An overnight interval stays on its explicitly selected work date and shows both actual dates.
Compute durations from UTC instants with the stored timezone; reject/clarify ambiguous local
timestamps instead of choosing a DST offset silently.

## Proposed Routes And Access

These route families define the approved contract. Slices 01-06 are implemented on Dev,
default-off; later-slice operations remain planned. New routes live in the owning
module's routes.php and use existing explicit route loaders. Do not add route files under routes/.
Preserve existing legacy api.php files unless a separate approved maintenance task changes them.

| Operation | Proposed route family | Ability/guard |
| --- | --- | --- |
| Own normal plan | GET/PATCH /api/v1/users/me/work-plan | users.work-plan.read/update; own employee only; narrow action, no roles/security fields |
| Personal plan blocks | GET/POST/PATCH/DELETE /api/v1/calendar/work-plan/blocks[/id] | calendar.work-plan.read/write plus own calendar and existing Calendar guards |
| Own days/history | GET /api/v1/workdays; GET /api/v1/workdays/{id}[/history] | workdays.read + workday.view_own + record owner |
| Own draft | PUT /api/v1/workdays/{work_date}/draft | workdays.write + workday.manage_own; version and idempotency |
| Preview/confirm/correct | POST /api/v1/workdays/{id}/preview, /confirm, /corrections | own write for draft/correction; workdays.confirm + workday.confirm_own for exact-version confirmation |
| Own absence | GET/POST /api/v1/workday-absences; PATCH /{id}; POST /{id}/cancel | workday-absences.read/write + own absence grants |
| Sources and selections | GET /api/v1/workdays/{id}/sources; selections saved with draft | Workday owner plus current source-domain authority |
| Confirmed oversight | GET /api/v1/workdays/overview[/{id}[/history]] | workdays.read-all + workday.view_all; explicit confirmed-only query |
| Internal Task conversion | POST /api/v1/workdays/{id}/task-conversions[/preview] | workday-task-conversion.write + own Workday and Task/context guards |
| Settings/retention preview | GET/PATCH /api/v1/workday-settings; POST /retention-preview | workdays.settings + workday.manage_settings; no general API purge endpoint |

Register literal routes before /{id} and constrain identifiers to prevent route ambiguity.
UI and API call the same actions. Reads are bounded, paginated and explicit about incomplete sources.
Writes return persisted identity/version/state for read-back; 401/403, 404, 409 conflict and 422
validation follow existing response conventions. A preview cannot authorize a subsequently edited
version. Transaction locking closes duplicate/concurrent confirmation and allocation races.

Employee operations require an active internal human identity. A user_id in an API payload, a
shared admin token or a service account is not employee delegation. Prefer the existing personal
Sanctum token path scoped to the employee and verify it directly through the API. LiteLLM/MCP
adapters are deferred by Svein on 2026-10-02. Existing coordinator workloads must not gain employee writes.

| Actor | Own time/absence | Other confirmed work | Other drafts/reasons | Other time edits |
| --- | --- | --- | --- | --- |
| Authorized employee | Read/write/confirm own time; read/write own absence | No | No | No |
| Superuser | Own operations with ownership enforced | Yes, through workday.view_all | No implicit access from oversight | No |
| Future HR-style role | As granted | Only explicit workday.view_all | Requires a separate future decision | No |
| Portal-only/service identity | No employee workflow | No implied access | No | No |

Use the existing reviewed role/migration path for grants. Inspect Superuser all-permissions seeding
before adding scopes: token abilities never replace object ownership or source authorization.
Do not add a cross-worker absence-reason grant or broad write permission to solve a UI convenience.
Domain settings are administrative; showing a setting never authorizes unfinished functionality.

## Work Plan, Absence And Calendar Consistency

Use profile weekly hours as normal schedule, effective-dated Calendar plan blocks/overrides as
exceptions and the explicit Workday absence register as absence authority. Conflict precedence
must be deterministic and shown when contradictory: absence blocks applicable planned work;
education changes the activity/phone duty but does not itself declare absence or actual hours.
Current generic busy Calendar events still block booking according to existing Calendar rules.

The initial Calendar projection uses the same database transaction and domain actions as absence
registration. It has a neutral title, no diagnosis, no external invitees and stable provenance.
Guard generic edit/delete/series paths so linked blocks cannot diverge from their source. Keep
source failure visible; no successful absence response may hide a failed same-database projection.
Existing calendar-only records remain untouched until an explicit authorized linking operation.

Normal work plans use existing self/admin ownership. Historical conflicts between profile defaults
and bespoke Calendar rules require a preview/report, not a global migration guessing employee intent.
The plan's authority correction is an explicit implementation proposal included in this review.

## Retention, Runtime And Deployment

Both time and absence retention are three years from work date/absence-period end. A correction
does not reset expiry. Preserve confirmed data until that cutoff, then remove owned copies/history.
Soft deletion alone is insufficient. Keep original source-domain and external records under their
own policies. Active recurring plans remain valid until ended. Include encrypted backup lifecycle
and a restore cleanup-before-reopen procedure in operations documentation.

Expected deployment tasks, to be finalized from actual implementation:
1. Review changed files/active WIP, parent approval and relevant pending human checks.
2. Snapshot affected schemas/settings and inspect the exact additive migrations; no bulk backfill.
3. Run migrations on approved Dev/test data, verify defaults off and explicit role grants.
4. Generate OpenAPI and validate route/spec parity and exact build identification.
5. Build frontend assets only if changed; refresh caches as required. Use umask 0002 for Artisan
   rendering and preserve web/PHP-FPM-readable files and group-writable compiled views.
6. Reload changed queue workers. Verify both Laravel schedule registration and the external
   OS/Plesk/systemd runner actually executing schedule:run every minute.
7. Run authenticated HTTP/browser smoke checks using trusted HTTPS and synthetic users.
8. Enable a synthetic Dev pilot; verify profile preferences, reminders, employee API and read-back. MCP is deferred.
9. Obtain named human review before Main merge/promotion, production migrations/deployment and
   production activation. Svein owns these actions; planning approval is not deployment approval.

Rollback defaults to feature off, stop write/import/reminder jobs, preserve confirmed records and
revert only owned code/config after impact review. Never drop confirmed records with a down migration.
Retention requires an explicit operational decision during rollback; disabling the UI is not an
instruction to retain personal data forever or to purge it immediately.

## Verification And Human Review

Each slice runs focused Laravel feature/unit/integration tests on authoritative Dev. Use isolated
synthetic fixtures and review test-environment configuration without exposing credentials.
Cover UserManagement, Calendar/Booking, Workday, Task/Ticket/Commercial, Report and Notification
where their contracts are affected. Broaden to the full suite before a broad release when practical;
report exact blockers and resolve relevant failures rather than calling them pre-existing and done.

Required examples:
- Eight hours plus two imported Task hours remains eight; five actual minutes with thirty billed
  contributes five. Day confirmation writes no billing records.
- Custom weekday hours survive preference edits; an education Monday is phone-duty unavailable
  without being inferred as sickness or confirmed work.
- Partial sickness, cancellation and retry preserve one neutral Calendar block and appropriate
  remaining work/reminder intervals.
- Employee, Superuser, explicitly granted synthetic HR and denied API users match the access matrix.
- UI and API correction/confirmation/retry read back the same stable identity/version.
- Expiry preview/purge/restore retains valid records and removes expired owned copies only.

[HR-2026-10-01-WORKDAY](../human-review.md) is the existing manual checklist; the Dev pilot is active and authenticated browser verification awaits login.
It blocks Main promotion/merge and production migration/deployment/activation; approved Dev-only
implementation/tests are allowed. Only Svein or another explicitly named reviewer can mark it
Reviewed. Passing tests, documentation completion or a staged deployment cannot do so.

Completion requires slice-specific evidence, updated Knowledge/SOP/API docs, route/spec read-back,
personal bearer API workflow evidence and truthful remaining human/production gates. Svein explicitly
deferred MCP on 2026-10-02 and selected LiteLLM as the external direction; no MCP/gateway connection
is a completion dependency. See the provider-independent [API consumer guide](../integrations/workday-api-consumer.md).

## Planning Deliverables And Next Action

This task creates this plan and nine Draft slice documents, links the existing RFC/ADR/TODO/index,
and adds the planned human-review checklist. It performs document/link/consistency validation only.
No application code, migrations, settings, credentials, API grants, notifications, GitHub posts,
commits, pushes or production state are changed.

The user-authorized product decisions are recorded in the parent RFC. The new detailed plan,
schedule-authority reconciliation and proposed permission/API/storage contract are ready for review.
Svein approved implementation on 2026-10-01. The parent RFC/ADR and slice statuses are
updated; shared Dev WIP was checked and Slices 01-08 passed Dev verification. Slice 09 API verification is complete; the combined pilot and human review remain.

Implementation progress (2026-10-01): [Slice 01 verification](2026-10-01-workday-slice-01-verification.md) records 75 passing tests,
default-off state and remaining human/production gates. Slices 02-03 are also Done On Dev as of 2026-10-02; Slice 09 is Done On Dev with the approved API scope.

Implementation progress (2026-10-02): [Slice 03 verification](2026-10-02-workday-slice-03-verification.md) records 121 tests / 960 assertions, own absence UI/API, neutral Calendar guards, Nextcloud exclusion and two additional Dev migrations. Slice 08 now implements default-off retention cleanup; full pilot, human review and Main/production remain separate. Both activation switches remain off.

Slice 04 delivery (2026-10-02): source reconciliation is Done On Dev, default-off. [Verification](2026-10-02-workday-slice-04-verification.md) records 271 distinct passing tests / 2236 latest assertions, two source API operations and one additive Dev migration. Source minutes stay within actual totals; original Task/Ticket/Calendar and billing data are unchanged. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending; Svein requested manual review only when the complete pilot actually needs it.

Slice 05 delivery (2026-10-02): confirmed overview, detail/history, Report discovery and reusable oversight rights are Done On Dev, default-off. [Verification](2026-10-02-workday-slice-05-verification.md) records 136 passing tests / 1392 assertions, three API reads and the Superuser-only Dev permission migration. Existing tokens were not changed. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. Human review remains Pending until the complete pilot is ready.

Slice 06 delivery (2026-10-02): personal plan-aware reminders, Profile notification choices and four own API operations are Done On Dev, default-off. [Verification](2026-10-02-workday-slice-06-verification.md) records 219 distinct tests / 2091 assertions, synthetic channel delivery, verified external scheduler and one additive Dev migration. Both switches remain off and no employee notifications were sent. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Slice 07 delivery (2026-10-02): explicit internal Task conversion is Done On Dev, default-off. [Verification](2026-10-02-workday-slice-07-verification.md) records 187 distinct tests / 1618 assertions, three API operations, transactional duplicate prevention and one additive Dev migration. No Task/time/billing fixtures or token changes were retained. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Slice 08 delivery (2026-10-02): three-year cleanup and restore operations are Done On Dev, default-off. [Verification](2026-10-02-workday-slice-08-verification.md) records 228 distinct passing tests after the reminder metadata assertion update, original-source preservation, synthetic restore rehearsal and a fresh native employee-row lock probe. Metadata-only UI/API preview is implemented. WORKDAY_RETENTION_ENABLED is independent and false; no live purge, migration, employee activation or token change occurred. Dev inventory has zero Workday roots and 105 historical diagnostic copies, so restore_ready correctly remains false pending approved cleanup. External backup rotation/archive handling remains an explicit pre-activation check. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY stays Pending until complete-pilot review.

Slice 09 scope revision and delivery (2026-10-02, approved by Svein): MCP is deferred; LiteLLM
replaces NexumMCP as the intended external direction. Complete PSA APIs are the immediate
requirement. [Verification](2026-10-02-workday-slice-09-api-verification.md) records 34 published operations with exact
scope metadata and 196 passing tests / 1912 assertions. The provider-independent API consumer
guide is delivered; no adapter, live tool connection or production activation is claimed.
HR-2026-10-01-WORKDAY remains Pending for the combined pilot and Main/production review.
