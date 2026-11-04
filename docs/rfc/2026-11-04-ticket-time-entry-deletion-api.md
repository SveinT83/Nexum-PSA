# RFC: Guarded Ticket Time Entry Deletion API

Status: Approved
Date: 2026-11-04
Owner: Codex; approval and promotion owner: Svein Tore
Change level: Level 3 - API, time records, authorization and financial dependencies
Implementation: Done On Dev and independently verified on the Main candidate. Scoped Main merge authorized by Svein Tore on 2026-11-04; production remains separate.

## Context

Svein requests an API update after reported duplicate Benjamin time registrations on
Ticket TD-2026-000277. The supplied incident context reports 27 entries of 24 minutes;
these production records and their present financial state have not been rechecked here.

Authoritative Dev already implements PATCH /api/v1/tickets/{ticket}/time-entries/{timeEntry}
through TicketLedgerController and UpdateTicketTimeEntry. The approved Ticket/Task parity
RFC records Main merge in PR #379. Neither the inspected browser nor API routes support
time-entry deletion. Production route availability is a separate deployment fact.

The approved parity RFC covers existing browser behavior. New deletion is an explicit
extension requiring approval, rather than an unimplemented parity operation.

## Goals

Allow an authorized caller to remove an erroneous direct Ticket time entry through the
official API, without SQL access, while retaining who deleted what and why.
Expose exact preview, conflict and persisted audit read-back so a caller can inspect
specific duplicate IDs and keep the intended original.

## Non-Goals

No automatic duplicate detection/removal, bulk deletion, Task-time correction, financial
reversal, Workday revision changes, new browser controls, prevention of repeated POSTs,
production deployment, unrelated Main changes, credential grants or gateway deployment.
The request to update the API does not itself execute cleanup on the real Ticket.

## Current Behavior

- Ticket time entries contain technician, date, minutes and rate/contract snapshots.
- TicketTimeEntryAllocation has a cascading time-entry foreign key: unguarded deletion
  could silently destroy financial evidence.
- EconomyOrderLine also uses polymorphic source references.
- GenerateOrders uses EconomyMutex; a Ticket lock alone cannot serialize billing.
- Workday revisions carry selected evidence source keys such as ticket:{entryId}.
- Task billing projections represent a different authority from actual employee time.
- TicketEvent supports before/after snapshots and metadata without a new table.

## Proposed Change

### API contract

1. GET /api/v1/tickets/{ticket}/time-entries/{timeEntry}/delete-preview returns the
   exact permitted record, eligibility/blocker codes and a short-lived signed token.
   It makes no database changes.
2. DELETE /api/v1/tickets/{ticket}/time-entries/{timeEntry} accepts reason,
   preview_token and idempotency_key. The preview binds the actor, Ticket, entry,
   current record fingerprint and expiry. The action rechecks every dependency.
3. Successful deletion returns 200 with deleted=true, exact Ticket/entry IDs and
   an audit_event_id. GET /api/v1/tickets/{ticket}/time-entry-deletions/{event}
   independently returns the permitted deletion receipt.
4. Stale/changed/protected state and reused keys with different input return 409;
   invalid input returns 422, missing rights 403, and wrong nested ownership 404.
   A same-actor/key/input retry returns its existing receipt without another event.
   A missing record without a matching authorized receipt returns 404.

### Authorization and eligible data

Require a dedicated tickets.time.delete scope, current ticket.view,
ticket.register_time and ticket.update permissions, active technician identity,
current Ticket visibility/mutation scope and existing REGISTER_TIME workflow guards.
The same rules apply to the preview and receipt; a token scope never replaces user rights.
Within that authority the actor may correct another technician's direct entry, which
is necessary for the reported Benjamin case. Keep the original user in the audit.

Deletion is limited to manual direct Ticket entries with no Task reference, both
billing_status and timebank_status pending, no allocation, no financial source/order
reference and no reference in any retained Workday revision, including historical
revisions. Any dependency blocks deletion; never cascade, detach or rewrite it.
An entry that has reached billing/timebank processing requires its owning correction
workflow and cannot be forced through this endpoint.

### Atomicity and audit

Use one Ticket-owned action inside the existing EconomyMutex and a database transaction.
Re-read and lock affected rows, revalidate nested ownership, fingerprint, authority and
dependencies, write a time_entry_deleted TicketEvent with complete relevant original
attributes, actor, reason and request identity, then delete the eligible row atomically.
The event and receipt remain available under existing Ticket audit access/retention.
A failed audit write rolls back deletion.

Coordinate lock order and retry handling with Workday source reconciliation and Economy.
Prove the race boundaries on Dev: preview is never authority to delete a changed row.
Do not claim TicketEvent is a new immutable storage system; preserve it through this
operation using current audit permissions. No general model/SQL dispatcher is exposed.

## Impact Analysis

Ticket owns routes, action, preview/receipt resources, authorization and audit.
Integration owns the explicit scope catalog and OpenAPI exposure; no grants are added.
Economy and Commercial supply dependency/mutex boundaries; existing invoices, allocations
and timebank usage remain untouched. Workday evidence and payroll totals remain untouched.
Report totals naturally exclude removed direct rows; audit retains the original snapshot.
Issue #391's proposed pooled timebanks is separate: reject all existing financial
dependencies and do not bake new pooling rules into deletion.
The Dev checkout contains substantial unrelated work; preserve it and re-read shared
files immediately before edits. Existing gateway/message incidents remain separate.

## Data And Migration Plan

No schema migration is proposed: reuse TicketEvent for deletion audit/retry receipts.
Before implementation, inventory exact financial/morph references and Workday snapshot
shapes against current code/schema. If durable uniqueness cannot be guaranteed by current
serialization, amend this RFC before introducing a migration.
No customer backfill, financial settlement, source restoration or real-data cleanup.
Code rollback removes the endpoint; it does not restore deleted records automatically.

## Testing Plan

Use isolated synthetic data on authoritative Dev with external effects faked.
Cover allowed deletion of another technician's pending manual duplicate; keep the original;
assert API/list/report read-back and exact audit receipt.
Cover missing scope/each permission, inactive actor, inaccessible/wrong Ticket, Task
projections, every financial state/reference, historical/current Workday evidence,
reason/token validation, stale/expired/wrong-actor previews, exact retries, conflicting
keys and rollback on audit failure. Verify mutex/lock interaction and concurrent billing
or Workday selection on the real Dev database without touching employee records.
Run focused Ticket ledger, OpenAPI, Report, Economy and Workday regressions.
Production incident counts are not synthetic-test assertions or evidence of cleanup.

## Documentation Plan

After approval, register one bounded Feature Slice and its human-review checklist.
Update Ticket time registration and API Management Knowledge with prerequisites,
preview/delete/read-back examples, permissions, blockers and Dev-only availability.
Publish exact reviewed source through the supported BookStack integration and verify
content and employee audience; log any concrete blocker/owner in TODO.
Document scoped deployment/OpenAPI regeneration and rollback; no frontend build or
queue/scheduler activation is expected. Gateway tool exposure and actual production
rollout require their own explicit scope and read-back.

## Open Questions

Svein Tore approved the full contract on 2026-11-04, including correction of another
technician's eligible entry under the stated existing permissions.
This draft does not request approval to delete Benjamin's real entries or deploy production.

## Approval

Svein Tore on 2026-11-04: "Godkjenner. Lag det og merge til main".
This authorizes implementation, scoped commit/push/PR/Main merge after passing Dev checks.
It does not confirm manual checks, authorize production deployment or execute real-data cleanup.
