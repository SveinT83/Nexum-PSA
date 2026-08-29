## Current State And Occurrences

An RMM alert has two related forms in Nexum:

- `AssetAlert` is the mutable current state for one unique fingerprint; and
- `RmmAlertOccurrence` is immutable activation evidence used by RMM Alert Rules.

The first failing Tactical or N-able observation creates occurrence sequence 1 with event type
`triggered`. A passing observation resolves that occurrence. If the same fingerprint fails later,
Nexum reopens the current `AssetAlert` and creates the next occurrence with event type `reopened`.

Routine failing heartbeats update `last_seen_at`, title, severity, and minimized provider context.
They do not create another occurrence or duplicate work. Resolution also does not run routing.

## Ownership Snapshot

Each occurrence freezes the alert's provider, fingerprint, severity, title, Asset, Client, and Site
context at activation time. Rule matching uses that snapshot rather than a later mutable Asset
relationship.

Before creating or updating a Ticket, Task, or Signal, Nexum locks the current Asset and compares
its ownership with the snapshot. A mismatch fails closed. Reuse of open work also requires the same
fingerprint and the same Asset/Client/Site context.

This prevents an Asset transferred between Clients or Sites from updating the previous Client's
work item.

Configured routing references are also rechecked under lock immediately before new work is created.
If a Queue, Type, Priority, Category, technician, or reopen status became inactive after rule save,
the action fails closed and the next eligible fallback rule may continue.

## Severity And Provider Context

Portable severities are `info`, `warning`, and `critical`. Unknown or missing provider values become
`warning`. N-able optional severity/check-type fields and Tactical check metadata are retained only
as bounded allow-listed context.

Raw provider payloads, credentials, headers, and unrestricted stdout/stderr are not stored in the
occurrence or rule-execution audit.

## Recovery Behavior

Resolving an RMM alert does not close a Ticket or complete a Task. Those records remain controlled
by their own domains and workflows.

A later recurrence can update an open scoped Ticket or Task. A configured reopen action can move a
closed Ticket only through an allowed Ticket Workflow transition.

An RMM-emitted Signal may continue through normal Signal Rules. A Signal webhook delivery row is
committed in the same transaction and a rollback preserves neither the row nor an external request.
An immediate post-commit wake-up lowers latency; `signal.webhook.dispatch` recovers rows still
`pending` every minute. Only a locked claim may start HTTP. An abandoned or transport-ambiguous
attempt becomes `unresolved` and is not automatically replayed.

Once rule execution starts, the occurrence is not automatically replayed. An interrupted execution
is recorded as a terminal failure on a later active heartbeat. Only an interruption before any rule
execution exists may be retried.

## Related Administration

RMM routing is configured under **Admin > System > Integrations > RMM Alert Rules** and requires
`integration.rmm_manage`. See the Integration Knowledge article **RMM Alert Rules** for conditions,
actions, execution statuses, and operational checks.
