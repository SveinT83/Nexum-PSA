# RFC: Automatic Workday And Tripletex Time Synchronization

## Sync setting requirement - 2026-10-05

Svein requested a time-registration synchronization on/off setting below the existing account
settings. The completed integration must persist this choice with optimistic version checks
and operator authorization. Off pauses both outgoing writes and incoming changes, including
already queued jobs, while keeping locally saved time, credentials, mappings, baselines and
pending work. Re-enabling resumes within the configured date/mapping scope without duplicates.
Workers must re-read the setting before each external mutation and before applying imports;
changing a token does not implicitly turn synchronization on.

The setting must be connected to the implemented and tested two-way runtime before it appears
as an activation control. A successful company identity check alone is insufficient for activation.
This request is approved implementation scope, not permission to place synthetic hours in an
unspecified employee's production payroll history.

## Single-account amendment - 2026-10-05

Svein Tore requested one Tripletex account per Nexum installation after saving a token and
reporting a green company check. This replaces the earlier multi-connection setup surface.
Keep the current encrypted credential, company binding and identity receipt. Once configured,
show only that connection's settings; reject additional accounts, including simultaneous first
setup requests. Other integration providers retain their existing behavior.
Changing the API token for the same company remains supported. The existing protection against
rebinding a verified account remains in force; do not silently repoint future time mappings.
A database-generated unique slot enforces this independently of browser tabs or application workers.
An upgrade with multiple existing accounts must stop without selecting or deleting data.
This amendment does not activate time synchronization or establish provider write capability.

## Implementation and deletion approval - 2026-10-05

Svein Tore explicitly authorized implementation and automatic deletion synchronization in both
directions. Create, update and delete are now in scope. Deletion of a mapped group propagates
only after baseline/concurrency and lock checks; removing one local interval updates the
remaining aggregate, deleting the last one removes the linked provider row. Preserve an
audited tombstone so retries/restores do not resurrect deleted time. Never treat an incomplete
list, inaccessible record, 403 or retention expiry as deletion. An external deletion requires
a complete authorized rescan plus identity/absence verification; delete-versus-edit conflicts
remain operator exceptions. Provider period approval/locks are never bypassed.
Implementation: The bounded time-sync pilot is implemented on Dev: explicit employee mapping, effective Save, duration editing, a working on/off switch, durable bidirectional create/update/delete reconciliation and scheduled scans. Live CRUD and import editing were verified on Svein's explicitly authorized employee; all synthetic provider time was removed. Remaining broader rollout checks are listed in the pilot verification and human-review entry. Product approval does
not authorize Main or production actions. HR-2026-10-05-WORKDAY-TRIPLETEX is In Review; only the initial save/company check has human confirmation.
The earlier documentation-only and undecided-deletion statements below are historical and
superseded by this explicit approval.


Status: Approved
Date: 2026-10-05
Owner: Svein Tore (product); Codex (documentation)
Change level: Level 3 - time workflow, external integration, data and permissions
Delivery status: The bounded time-sync pilot is implemented on Dev: explicit employee mapping, effective Save, duration editing, a working on/off switch, durable bidirectional create/update/delete reconciliation and scheduled scans. Live CRUD and import editing were verified on Svein's explicitly authorized employee; all synthetic provider time was removed. Remaining broader rollout checks are listed in the pilot verification and human-review entry.
Related: [ADR](../adr/2026-10-05-workday-tripletex-save-and-sync.md),
[delivery plan](../plans/2026-10-05-workday-tripletex-delivery-plan.md),
[TODO](../TODO.md), [human review](../human-review.md#hr-2026-10-05-workday-tripletex---automatic-time-synchronization).
Historical idea: [Discussion #211](https://github.com/SveinT83/Nexum-PSA/discussions/211).
This approved decision supersedes that idea's separate daily-confirmation proposal.

## Context

Employees must be able to record time once in either Workday or Tripletex, then edit it
in either system regardless of origin. Saving is sufficient. Requiring a second review,
confirmation, submission or import-acceptance action defeats the purpose.

Svein approved automatic background synchronization and adapting the Workday representation
to Tripletex on 2026-10-05, then requested documentation. This RFC records that decision.
It replaces the confirmation-dependent target in the
[earlier Workday RFC](2026-10-01-daily-workday-confirmation.md). It does not claim that
the existing code already implements the new workflow.

## Goals

- Explicit employee Save creates or updates effective actual time without separate confirmation.
- Automatically import, create and update time in both directions, including Tripletex-origin time.
- Support date, employee, activity, duration and an optional mapped project as the common model.
- Retain optional Nexum clock intervals where they still describe the current duration correctly.
- Keep everyday registration simple; retries and reconciliation happen in the background.
- Give integration operators actionable exceptions, without routine employee confirmation.
- Use each company's own connection and credentials; never reuse our company's key for customers.
- Keep payroll approval in Tripletex and prevent duplicate time and accidental billing changes.
- Preserve domain API parity, attribution, revision history and existing privacy boundaries.

## Non-Goals

Customer, product, invoice, order, payment and payroll-run synchronization; creating projects
or employees in Tripletex; absence/leave synchronization; holiday/flex/overtime entitlement
engines; public marketplace publication; an MCP server; deriving time from telemetry or AI.
Existing Workday absence and plans remain usable. Later integration areas require their own scope.

## Current Behavior

Authoritative Dev was inspected on 2026-10-05. Workday currently saves private draft revisions,
requires a preview and employee confirmation, and uses explicit correction/reconfirmation.
The model preserves immutable revisions and distinguishes current and confirmed versions.
The current calendar supports whole-minute clock intervals, not duration-only actual entries.

The separate date-entry API follow-up is Done On Dev: the recorded evidence reports 35 API
operations and 73 tests / 907 assertions. This evidence belongs to the old workflow, not this RFC.
HR-2026-10-01-WORKDAY remains In Review; prior UI approval and remaining checks are preserved.
No implemented Workday/Tripletex connector was found in the inspected repository scope.

Existing DataExchange ownership and Integration credential responsibilities remain applicable.
The accounting-framework RFC filename in the index is missing from this checkout; its title is
not evidence of an approved implementation or prerequisite. This RFC does not depend on it.
The GitHub open-Issue inventory and all returned Discussion titles were read; Discussion #211 is the
existing Workday idea and was read in full. No GitHub item was posted or changed.

## Proposed Change

### Registration and user experience

For the new workflow, Save is the employee's explicit registration action. It validates and
persists time locally, appends history and schedules synchronization atomically. It is not a
payroll approval. There is no Confirm day, Accept import or Start correction prerequisite.
The same rules apply to the Workday UI and employee-authorized API, and Workday remains usable
without Tripletex configured. Opening a page, selecting a suggested interval and closing a
modal must never save. Plans, estimates and AI suggestions remain unrecorded until explicit Save.

New saves become effective in existing authorized work-time oversight. This changes the old
private-draft boundary and must be described to users before activation. Existing private
drafts are never silently exposed or exported as part of migration.

The common record has date, employee, mapped activity, duration and optional project/comment.
Clock times are optional Nexum detail, not mandatory import data. Work - unspecified remains valid.
Use English UI labels and existing Bootstrap components. Show duration-only records in an
accessible day list with no fabricated position on the clock timeline.

A normal save returns promptly with Saved / Sync pending; Synced requires external read-back.
Brief offline/provider failures do not discard locally saved work. Persistent exceptions appear
in the integration operator's queue and as an honest, non-blocking status on affected records.

### Provider contract and precision

The official OpenAPI checked on 2026-10-05 documents:
- GET/POST /timesheet/entry and GET/PUT/DELETE /timesheet/entry/{id}.
- One entry per employee/date/activity/project combination.
- A numeric hours field; fractional hours are supported by the schema, not only whole hours.
- Read-only timeClocks and locked fields; normal entry writes have no start/end fields.
- Monthly status reads and separate complete/approve/reopen/unapprove operations.
- Required date bounds and pagination for entry lists.

45 minutes maps to 0.75 hours and 90 minutes to 1.5 hours. Do not round Nexum to whole hours.
Live own-employee read-back established hundredth-hour storage: one minute became 0.02 hours.
The pilot stores integer units of 0.01 hour (36 seconds), rounding clock input once and retaining
original clock provenance where necessary. CRUD and expected-version checks passed live; real
locked-period/project and large-volume behavior still require rollout verification.

Outgoing actual time follows existing included/excluded break rules. Do not infer overtime or
change wage types. Split overnight durations by the agreed company reporting-date boundary,
with explicit timezone/DST handling verified before activation.

### Mapping and first connection

An administrator selects the company's own connection, verifies the returned company identity,
and maps allowed employees and activities explicitly. Optional project mapping uses existing
Tripletex project IDs; no automatic project/customer creation. Do not match employees by name
alone or copy one connection into another company's scope.

Setup includes selected employees, an initial date boundary and an operator preview of existing
records/collisions. Historical imports are an administrator activation choice, not an employee
per-entry confirmation task. Never backfill all history by default or reimport expired Workday data.
Mapping gaps are operator exceptions; employees continue to save their time.

Both local saves and Tripletex imports carry source identity. A background connector is an audited,
narrowly scoped integration actor, not a falsely attributed employee confirmation or a reusable
superadmin identity. The actor can affect only mapped work-time records in that connection.

### Aggregation and automatic inbound changes

Maintain stable local-to-external IDs, company/connection scope, versions and a last synchronized
baseline. Several Nexum intervals in the same provider key form one external entry; mappings
preserve their membership so neither direction duplicates the total.

If an external duration changes while local data is unchanged, apply the new duration automatically.
Where an aggregate represented several intervals, replace the effective group with one duration-only
representation, preserving the original intervals in revision history. Do not retain old intervals
as additional actual time, distribute minutes arbitrarily, invent new times, or ask the employee
to confirm a normal import. If the new duration still matches preserved intervals, retain them.

An ordinary mapped Tripletex-origin record is effective immediately after validated import and
can be edited in Nexum. A change to its date/activity/project also follows the same logical record.
Stable IDs handle regrouping; partial failures during a provider-key move must remain recoverable.
Original Task/Ticket time is not rewritten. Invalidated source allocations require an operator
exception rather than silently reducing or duplicating source-domain time.

### Reconciliation, retries and conflicts

Use a durable local outbox with per-connection/per-record serialization. Refresh remote state before
writing; compare both sides to their common baseline rather than comparing wall-clock timestamps.
If only one side changed, synchronize it. Identical changes converge without a new write.
Disjoint field changes may merge only where all domain and grouping invariants remain valid.

A conflicting edit to the same field/group, delete-versus-edit or uncertain identity becomes an
operator exception. Preserve both versions and stop only the affected group. Do not impose a
routine employee review queue or silently apply last-write-wins. Resolving an exception requires
a scoped, audited decision and read-back; it does not grant ordinary cross-employee editing.

Use bounded retries/backoff and rate-limit handling. An unknown create outcome must be reconciled
by ID or the unique business key before retrying; provider idempotency support is not assumed.
Do not mark a missing page, a partial list, a 403 or an expired credential as remote deletion.
A complete, authorized bounded rescan is required for absence detection.

Start with scheduled bounded polling plus outbound jobs. A five-minute inbound interval is a
technical starting default, subject to provider limits and measured load. Include rotating scans
from the configured start date so older edits inside retained scope are eventually detected.
Record per-window completion; never advertise globally current data after scanning only recent days.
Webhooks are optional later optimization until actual entry-event support is verified.

### Approval, locks and deletions

Synchronization must not call monthly complete/approve/reopen/unapprove operations.
Read external status and obey entry locks. A pending local edit may remain recorded as a requested
change, but the UI must show that Tripletex still holds the prior value until the lock is resolved.
A new lock between preflight and write is handled as an exception, not a successful delivery.

Create, update and deletion synchronization are approved by Svein on 2026-10-05.
A versioned explicit local removal propagates to the linked provider record; removing only part
of an aggregate updates its remaining duration. External deletion propagates only after a complete
authorized scan, direct identity/absence verification and an unchanged local baseline. Keep scoped
tombstones until their original retention deadline so retries do not recreate removed time.
Delete-versus-edit conflicts and locked records are operator exceptions. Retention expiry never
deletes Tripletex records. The implemented provider DELETE helper passes the expected version;
the Workday tombstone/reconciliation layer is still to be implemented.

### Connection and credentials

Use each company's own stored credential reference, isolated session cache and verified Tripletex
company identity. Do not place raw tokens in data mappings, jobs, audit payloads, source code or logs.
Our key has been reported available by Svein; its type, permissions and live access were not tested.

For an internal single-company connection, Tripletex documents a JWT refresh token exchanged at
POST /token/session/:createFromRefreshToken. Session authentication is separate from the stored key.
Keep test and production credentials/endpoints separate and retain TLS verification.

Tripletex's published guidance distinguishes internal use from an integration offered to multiple
customers. Customer-owned keys alone do not establish permission for multi-customer distribution.
Owner: Svein Tore, with Tripletex. Record the accepted authentication/distribution model before
customer rollout. Do not silently substitute a public integration or share our company's credential.

## Impact Analysis

| Area | Required impact |
| --- | --- |
| Workday | Effective saved time, duration-only entries, revision/correction rules, totals, allocations, retention |
| Integration | Company connection, credential/session lifecycle, provider adapter and capability checks |
| DataExchange | Mapping, jobs/outbox, checkpoints, run history, conflict handling and delivery receipts |
| UserManagement | Explicit employee-ID mapping; existing identity and company scope remain authoritative |
| Calendar | Optional clock display; never fabricate placement of duration-only entries |
| Report | Effective saved/imported time replaces confirmed-only reads after deliberate cutover |
| Notification | Remove confirm-day reminders in the new mode; retain preferences and appropriate missing-time reminders |
| Task/Ticket/Commercial | Preserve actual/billed separation and source ownership; prevent double counting |
| API/Knowledge | Save semantics, source attribution, statuses, duration precision and compatibility documentation |

Reuse module routes/controllers/views; no new generic route files or duplicate time domain.
Existing own-time permissions remain required. Integration setup, sync operation and conflict
resolution need explicit scoped rights. Ordinary oversight does not grant other-worker edits,
credential access or raw private evidence. Exact permission names are determined during design.

## Data And Migration Plan

Use additive schema changes for stable registration identity, duration-only values, effective
revision/provenance, mapping/baseline, outbox and sync receipts. This is a storage requirement,
not a claim that these columns/tables already exist. Inspect exact schema before writing migrations.

Preserve immutable historical confirmations as historical employee statements. Never relabel an
import or a save as a historical human confirmation. Keep pre-cutover drafts private and unexported;
the employee may choose to save them through the new normal flow. Existing confirmed time becomes
eligible only within the administrator's reviewed initial sync range.

A separately controlled workflow cutover must update UI, API, reports, reminders and consumers
together. Version/deprecate old draft/preview/confirm contracts explicitly; no silent API semantic
change for existing consumers. Determine compatibility in the first delivery slice.

Before activation: backup/restore rehearsal, reviewed additive migrations, build/cache refresh if
needed, worker reload, verified external minute scheduler runner and isolated connection checks.
No deploy commands are executed by this documentation change. Exact commands belong to verified
implementation handoff. Rollback pauses synchronization and preserves effective time and receipts;
old code must not be restored while ignoring newly created duration-only records.

Retain the agreed three-year Workday period, including owned baselines, imported descriptions and
diagnostic copies. Retries do not extend it. Do not purge provider data, resurrect expired records,
or retain full time content indefinitely as a duplicate-prevention workaround.

## Testing Plan

- UI/API saves and edits need no confirmation; ordinary imports become effective automatically.
- 45/90-minute, minute fractions, decimal round trips, breaks, overnight/DST and date-only import.
- Create in each system, edit in either, restart/retry and converge to one linked external record.
- Aggregate two intervals; change the Tripletex total; get one duration-only effective group.
- Source allocation consistency, old draft privacy, existing confirmations and report/API cutover.
- Concurrent edits, stale versions, provider locks, permission loss, timeouts after accepted writes.
- Partial pagination, older edits, external missing records and delete-versus-edit exceptions.
- Tenant/connection isolation, wrong-company fail-closed behavior, employee/role/API scope denials.
- Session renewal, revoked keys, rate limiting, stopped workers/scheduler and bounded catch-up.
- No approval, payroll, invoice or Task/Ticket billing mutation; retention never removes remote time.
- Relevant Dev Laravel tests plus a controlled provider sandbox pilot with external read-back.
  Automated results do not replace the named human-review checklist.

## Documentation Plan

Maintain this RFC, the ADR, delivery slices, TODO and HR-2026-10-05-WORKDAY-TRIPLETEX together.
Mark earlier Workday workflow decisions as superseded in scope without deleting history.
Current Knowledge instructions continue describing shipped behavior; add a clear planned-change
pointer now, and replace instructions/screens/API examples when implementation actually lands.
Prepare connection, mapping, conflict, recovery and precision guidance with the implementation.
No BookStack publication or public website claim is authorized by this planning documentation.

## Open Questions

The core product behavior is settled. Track these implementation/rollout gates:
1. Svein/Tripletex: confirm distribution/authentication model before customer rollout.
2. Implementer: prove precision, required activity/project configuration, concurrency, lock and
   company-access behavior with provider contract tests before live writes.
3. Implementer: verify deletion versions/locks and both-direction tombstone recovery; deletion policy is approved.
4. Implementer: inspect backward-compatible storage/API cutover and record actual migration steps.

These gates do not reintroduce employee confirmation. No key is needed in chat.

## Approval

Svein Tore approved the immediately preceding automatic save-and-sync proposal on 2026-10-05:
"Godkjent. Da kan vi lage dokumentasjon :)"
Approval initially covered documentation. Svein subsequently explicitly authorized implementation
and bidirectional deletion on 2026-10-05. Main/production actions and provider entitlement are not inferred.
Technical delivery defaults and unresolved gates are explicitly identified above.

## Sources

Checked 2026-10-05:
- [Tripletex OpenAPI](https://tripletex.no/v2/openapi.json): TimesheetEntry, timesheet/entry and month paths.
- [Tripletex API browser](https://tripletex.no/v2-docs/).
- [Authentication and tokens](https://developer.tripletex.no/docs/documentation/authentication-and-tokens/).
- [Production access and distribution](https://developer.tripletex.no/getting-started/).
- Authoritative Dev Workday actions/models/Knowledge, TODO and dated API verification.
Public schema/documentation reads only; no authenticated Tripletex transaction was attempted.

## Current implementation evidence

See [connection verification](../plans/2026-10-05-tripletex-connection-verification.md):
22 tests / 66 assertions pass with a simulated provider. Setup permission migration applied on Dev;
no live provider verification or Workday synchronization completion is claimed.
