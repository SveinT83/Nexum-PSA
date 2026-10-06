# Workday And Tripletex Delivery Plan

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


Status: The bounded time-sync pilot is implemented on Dev: explicit employee mapping, effective Save, duration editing, a working on/off switch, durable bidirectional create/update/delete reconciliation and scheduled scans. Live CRUD and import editing were verified on Svein's explicitly authorized employee; all synthetic provider time was removed. Remaining broader rollout checks are listed in the pilot verification and human-review entry.
Date: 2026-10-05
Owner: Codex; product/review owner: Svein Tore
Parent: [RFC](../rfc/2026-10-05-workday-tripletex-automatic-time-sync.md)
Decision: [ADR](../adr/2026-10-05-workday-tripletex-save-and-sync.md)
Register: [TODO](../TODO.md)
Human review: HR-2026-10-05-WORKDAY-TRIPLETEX (In Review; initial save/identity check confirmed by Svein).

## Complete Target

Employees save time in either Workday or Tripletex and may edit it in either system.
Normal propagation is automatic. No separate employee confirmation or acceptance is required.
Duration is common data; clock times remain optional Nexum detail. Tripletex keeps payroll
approval and locking. Each Nexum installation has at most one Tripletex account and its own isolated credentials/mappings. Additional accounts are rejected by the UI/controller and a database constraint; retain the configured account.

The following slices implement this agreed target sequentially; they are not competing
product plans or permission to implement only one direction and call the integration finished.

## Preflight And Existing Work

Authoritative location: /var/Projects/tdPSA, branch Dev. The working copy contains unrelated
contributor changes; preserve them. Existing Workday date-entry API follow-up is Done On Dev.
The old workflow remains live on Dev; HR-2026-10-01-WORKDAY is In Review for remaining checks.

Read the latest AGENTS, TODO, module architecture, UI guidelines, affected code/schema/tests
and both review entries before implementation. Recheck GitHub scope and current work-in-progress.
Current GitHub inventory has no open Issue titled as this connector; Discussion #211 is the
historical Workday idea. No GitHub write is included in this documentation delivery.

The four slices below are the required feature-slice records inside this plan.
Svein authorized implementation and bidirectional deletion on 2026-10-05. Slice 01 has
implemented and live-tested setup, mapping and provider CRUD. The functional pilot spans
the four slices; broader rollout acceptance remains open as detailed below.
See [verification](2026-10-05-tripletex-connection-verification.md) for code and migration evidence.

## Slice 01 - Company Connection And Verified Provider Contract

Status: Pilot connection, mapping, hundredth-hour precision and live CRUD verified. Live locked-period/project checks remain.
Owner: Codex; credential/provider/distribution decision owner: Svein Tore
Parent: this plan and approved RFC.

### Goal
Establish an isolated company connection and prove the time-entry contract before enabling sync.

### User-Visible Behavior
An authorized administrator can verify company identity and configure employee/activity/project
mappings and an initial date range. Capability results are honest; employees see no extra setup.

### Scope
Inspect existing Integration/DataExchange APIs; credential reference/session lifecycle; minimum
provider capabilities; map/read previews; precision and lock contract fixtures; schema/API cutover
design. Document whether one-minute and fractional-minute values round-trip without drift.
Resolve the customer-distribution authentication gate before any multi-customer activation.

### Out Of Scope
Automatic time transfer, public integration publication, payroll approval and other accounting data.

### Data Touched
Company connection, mapping proposals and capability receipts; test fixtures only in provider sandbox.
No real employee history imported or exported by default.

### Permissions
Dedicated setup/operator rights, company isolation and existing employee identity ownership.
No broad superadmin token and no secrets in run payloads.

### Tests
Credential renewal/failure, wrong company, permissions, bounded lists, required activities/projects,
decimal precision, version conflicts, null/zero update behavior, locks and ambiguous create results.
Read back and remove only identified synthetic provider fixtures when allowed.

### Documentation
Connection setup, provider contract evidence, mapping guide, exact storage/compatibility design,
RFC open gates and TODO state.

### Done Criteria
- Verified provider behavior and precision contract, with evidence limits recorded.
- Company isolation and credential handling covered by tests.
- Explicit storage/API migration design ready; no hidden reliance on old confirmation semantics.
- No enabled sync controls before behavior exists.

## Slice 02 - Effective Save And Duration-Only Workday

Status: Implemented for the bounded pilot; complete rollout criteria below remain tracked.
Owner: Codex
Dependency: Slice 01 storage/precision and compatibility findings.

### Goal
Make explicit Save sufficient for actual time, with clock intervals optional.

### User-Visible Behavior
Save/edit in the Workday UI/API immediately records effective work. Imported-style duration-only
entries appear in the day list without invented calendar placement. No confirm/reconfirm step.

### Scope
Versioned effective saves, duration representation, optional clocks, break rules and reporting dates;
own API parity; Report oversight; reminders; source allocations; old API compatibility; old draft
privacy; unchanged non-billing and no-inference boundaries. Use existing Bootstrap components.

### Out Of Scope
Live Tripletex writes, automatic payroll approval, absence synchronization and new recipient roles.

### Data Touched
Additive Workday identity/revision/duration/provenance storage, read models and notification eligibility.
Preserve previous confirmations and private drafts; do not bulk publish or silently auto-confirm.

### Permissions
Own-time writes and existing authorized oversight remain distinct. New effective-save visibility
must be documented. Existing drafts and raw evidence remain protected.

### Tests
UI/API save/edit without confirmation; 45/90-minute and fraction handling; optional clocks;
breaks, DST/overnight, overlaps; source allocation totals; reminder/report cutover; scope denial;
old clients/drafts and rollback with newly created duration-only rows.

### Documentation
Workday Knowledge, employee API consumer guide and generated OpenAPI, new/old workflow boundaries,
migration and compatibility runbook. Only now replace the current shipped-workflow instructions.

### Done Criteria
- One explicit Save works through UI and API with persisted read-back.
- No reporting/reminder path silently still requires confirmation.
- No additional Task/Ticket/billing time or calendar interval is fabricated.
- Relevant Dev tests pass and migration/rollback limitations are documented.

## Slice 03 - Automatic Two-Way Create, Update And Delete

Status: Implemented for the bounded pilot; complete rollout criteria below remain tracked.
Owner: Codex
Dependency: Slices 01-02; provider write contract verified.

### Goal
Deliver automatic, duplicate-safe create/update/delete in both directions.

### User-Visible Behavior
Time saved in either system appears in the other and remains editable in both. Normal imports
need no employee action. A changed aggregate duration becomes duration-only automatically.

### Scope
Durable outbox, stable external IDs, group mappings, common baselines, bounded polling, older-date
reconciliation, backoff, read-back, loop prevention, regrouping, lock/status reads and scoped
operator conflict resolution. Failed mappings or groups must not stop unrelated mapped employees.

### Out Of Scope
Remote approval/reopening; invoice/payroll mutation; deletion caused by retention or incomplete scans.

### Data Touched
Mapping identities, sync baselines, outbox/jobs, checkpoints, receipts, tombstones and bounded conflict snapshots;
mapped Workday and Tripletex time only. Historical import requires reviewed connection range. The requested on/off setting belongs below account settings; disabled state must stop both incoming and outgoing processing, including queued jobs, while retaining pending work.

### Permissions
Narrow connector actor, mapped employee scope, dedicated retry/conflict rights. Employees retain
own edit rights; operator sync resolution does not become unrestricted employee editing.

### Tests
Both origins and both edit directions; multiple intervals per provider key; external duration change;
echo prevention; concurrent conflicting/disjoint edits; lock races; timeout after create; 429/403;
partial pagination; verified remote/local deletion and delete-versus-edit; date/activity/project move; source allocation consistency.

### Documentation
Sync/exception guide, scheduler/queue requirements, baseline recovery, failure/retry semantics,
integration API support where operations are exposed and bounded audit retention.

### Done Criteria
- Synthetic end-to-end changes converge in both directions with remote and local read-back.
- Retries/restarts cannot duplicate time or lose a later edit.
- Ambiguous writes, locks and conflicts are visible and recoverable.
- Synced status is based on read-back, not just queued work or an HTTP success.

## Slice 04 - Operational Recovery, Retention And Pilot

Status: Implemented for the bounded pilot; complete rollout criteria below remain tracked.
Owner: Codex; named human reviewer: Svein Tore
Dependency: Slices 01-03; activation/provider gates resolved for the selected company.

### Goal
Prove the complete employee and operator flow under realistic failures before rollout.

### User-Visible Behavior
Employees only register/edit time; operators see actionable failures and last successful coverage.
Paused connections and offline saves remain honest. Recovery does not require routine employee review.

### Scope
Complete Dev integration/regression tests, controlled provider pilot, external minute-scheduler
verification, worker recovery, audit/privacy/retention, restore replay protection, API compatibility,
setup/migration runbook, phased activation and emergency pause.

### Out Of Scope
Main commit/push/merge/deployment without Svein's explicit instruction; customer rollout with
unresolved provider distribution model; automatic removal of provider records.

### Data Touched
Reviewed activation settings and explicitly selected pilot fixtures; backup/restore evidence.
No historical mass export, role expansion or live cleanup by implication.

### Permissions
Verify own-time/oversight/operator/connection boundaries and revocation during pending jobs.

### Tests
Scheduler/worker outage and restart, token revocation, old-date edits, disconnected UI/API saves,
retention cutoff/restore, privacy, aggregation, mobile/keyboard usability and external lock flow.
Use current Dev tests and a named-human pilot with concrete local and remote records read back.

### Documentation
Knowledge/runbook, deploy and rollback commands, verification evidence and human-review checklist.
Website handoff only after implemented, verified customer-facing behavior exists; not for this plan.

### Done Criteria
- All acceptance scenarios and failure paths verified; test failures fixed or explicitly deferred.
- HR-2026-10-05-WORKDAY-TRIPLETEX reviewed by a named human before Main/production actions.
- Relevant remaining HR-2026-10-01-WORKDAY checks reconciled without deleting approval history.
- Provider/distribution/deletion limitations stated accurately; exact commands and rollback recorded.

## Current Delivery And Next Action

The [functional pilot verification](2026-10-05-tripletex-time-sync-verification.md) is the current
evidence record. Svein authorized his own Tripletex employee for Admin User. Live create, update,
delete and imported-row editing passed in both directions; all synthetic provider time was removed.
The switch was tested through its controller and persisted state. The ordinary OS scheduler
executed the connector at 21:00:07 UTC. The account was restored to paused after verification.
The final full connector/Workday suite passed 223 tests / 2021 assertions.

Request named-human review of the actual switch and ordinary UI time entry now. Keep
HR-2026-10-05-WORKDAY-TRIPLETEX In Review until Svein explicitly confirms the checks.
Automatic overnight splitting, dedicated operator/mapping recovery interfaces, volume/lease/backoff
tests, real locked-period/project tests and multi-customer distribution remain rollout work.
