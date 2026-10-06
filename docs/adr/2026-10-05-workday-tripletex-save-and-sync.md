# ADR: Workday Save Semantics And Automatic Tripletex Synchronization

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


Status: Accepted
Date: 2026-10-05
Decision Makers: Svein Tore (approved product direction); Codex (documented architecture)
Implementation status: The bounded time-sync pilot is implemented on Dev: explicit employee mapping, effective Save, duration editing, a working on/off switch, durable bidirectional create/update/delete reconciliation and scheduled scans. Live CRUD and import editing were verified on Svein's explicitly authorized employee; all synthetic provider time was removed. Remaining broader rollout checks are listed in the pilot verification and human-review entry.
Related: [RFC](../rfc/2026-10-05-workday-tripletex-automatic-time-sync.md),
[delivery plan](../plans/2026-10-05-workday-tripletex-delivery-plan.md).

## Context

The earlier [Workday ADR](2026-10-01-workday-time-evidence-and-billing.md) required
employee preview/confirmation and reconfirmation of corrections. Svein has explicitly
replaced that interaction: employees should record time in either system and everything
ordinary should synchronize in the background.

Tripletex's documented entry model is a date-based numeric duration with one record per
employee/date/activity/project. It does not require whole-hour units, and ordinary entry
writes do not expose editable start/end fields. Mandatory calendar intervals in Nexum
would prevent honest, automatic import of duration-only time.

## Decision

1. An explicit authorized Save records effective actual Workday time. No second employee
   confirmation, import acceptance or manager approval step is added to the Nexum workflow.
   Imported Tripletex time is effective after authorized mapping and validation, not a new
   employee attestation. Preserve the actual source and integration actor.
2. Support duration-only time plus optional Nexum clock intervals. Do not invent clock times.
   Use exact decimal conversion and verified provider precision; retain minute entry capability.
3. Allow create and update in both systems regardless of source. Stable identity and shared
   baselines govern reconciliation; import echoes must not produce loops or duplicate hours.
4. Aggregate by the provider business key. If a remote total changes and local data has not,
   replace incompatible effective intervals with one duration-only group automatically.
   Preserve the old detail in history, never as additional counted time.
5. Save and enqueue durably together. Use read-before-write, serialized group operations,
   bounded retry, ambiguous-result reconciliation and persisted remote read-back.
6. Keep payroll approval and lock authority in Tripletex. Do not automatically approve,
   complete, reopen or unapprove periods. Genuine conflicting changes/locks go to the
   scoped integration operator; routine time entry has no human synchronization gate.
7. Reuse Workday business rules, Integration connections/credentials and DataExchange
   mapping/run ownership. UserManagement, Calendar, Report, Notification, Task/Ticket
   and Commercial retain the boundaries described by the RFC.
8. Each company supplies its own connection. Our company's key never serves other customers.
   Multi-customer distribution requires provider clarification; approval of this design
   does not assert provider entitlement or authorize a public marketplace integration.
9. Preserve old confirmations as historical statements and old private drafts as private.
   Activation changes newly saved time visibility under existing authorized oversight;
   it does not expose drafts or private evidence retroactively.
10. Keep three-year Workday retention and original source ownership. Local expiry never
    propagates remote deletion. Explicit bidirectional deletion was subsequently approved by Svein on 2026-10-05.
    Preserve versioned tombstones, lock checks and complete authorized absence detection;
    missing/inaccessible records alone do not authorize deletion.

The 2026-10-01 ADR is superseded for mandatory confirmation/correction steps, confirmed-only
effective-time reads and confirmation-dependent Tripletex transfer. Its source/billing
separation, privacy, no-telemetry inference, absence/plan ownership and retention decisions
remain applicable. Automatic synchronization must not infer new time from plans or AI.

## Rationale

A single explicit save is sufficient intent for recording actual work. Separate approval
still belongs to the payroll system. A shared duration representation handles both source
systems honestly and avoids forcing employees to repair information the provider never had.
Existing domains prevent an isolated connector from taking over Workday or billing rules.

## Consequences

Employees can record and edit once. Ordinary imported changes converge automatically.
Duration changes may remove current clock placement while retaining historical detail.
Workday state, API contracts, oversight and reminders must migrate coherently; simply calling
the old confirmation action as a service account is not an acceptable implementation.

Operational responsibility increases: durable delivery, mapping, decimal precision, provider
locks, complete pagination and conflict recovery need tests and visible operator tooling.
Multi-customer authentication remains a rollout gate. The decision itself is not runtime activation; implementation evidence is tracked separately.

## Alternatives Considered

- Mandatory employee acceptance of every import: rejected by Svein.
- Restrict Nexum to whole-hour blocks: unnecessary; the provider schema supports numeric hours.
- One-way export or updates only at the source: rejected; editing must work in both systems.
- Invent clock intervals or proportionally redistribute changed external totals: rejected;
  no evidence supports those times.
- Global latest-timestamp-wins: rejected; clock order cannot safely resolve concurrent edits.
- Shared customer credential: rejected; every company owns its own connection.

## Follow-Up

Follow the four sequential slices in the delivery plan. The RFC records provider contract,
distribution and deletion gates. HR-2026-10-05-WORKDAY-TRIPLETEX remains Pending until a named
human reviews implemented behavior. It gates Main promotion and production migration,
deployment and activation, not this documentation change.

## Implemented pilot decisions — 2026-10-05

Store common durations as integer hundredths of an hour (36-second units), based on live read-back.
Use recorded revisions and the existing effective-time pointer without claiming confirmation.
DataExchange stores per-connection/user/date three-way baselines and durable intent; the provider
unique employee/date/activity/project key reconciles uncertain creates. The operator switch and
worker share a connection lock. Preserve old private drafts and source allocations; conflicting
changes become operator exceptions. See the [pilot verification](../plans/2026-10-05-tripletex-time-sync-verification.md)
for live evidence and explicit rollout limitations.
