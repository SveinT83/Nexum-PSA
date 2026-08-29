# ADR: RMM Alert Pre-Routing Domain Boundary

Status: Accepted
Date: 2026-08-25
Decision Makers: Svein / Codex

## Context

Issue #226 requires RMM-specific decisions before an alert becomes a Ticket, Task, or Signal.
Nexum already has Signal Rules for normalized cross-domain events and Ticket Rules for Ticket-owned
behavior. Asset alerts currently keep only one mutable row per fingerprint, and Tactical and N-able
ingestion duplicate lifecycle code.

The architecture must preserve provider context and recurrence evidence without turning Signal or
Ticket Rules into RMM parsers, and without allowing a new rule engine to bypass target-domain
guards.

## Decision

Create an Integration-owned, provider-neutral RMM Alert Rules boundary immediately after normalized
AssetAlert ingestion and before optional cross-domain routing.

- Tactical and N-able jobs call one explicit observation Action; no Eloquent observer or hidden
  global model hook starts automation.
- One mutable `AssetAlert` remains the current fingerprint state. Immutable occurrence rows record
  each new activation and resolved-to-active reopening.
- RMM rules are their own typed definitions and executor. They do not share the Signal or Ticket
  rule runtime.
- Rule evaluation runs after the occurrence transaction commits and inside the existing RMM sync
  job, so this slice does not depend on a new queue or scheduler. Rule failures are audited and do
  not roll back the normalized alert.
- Ordered side effects call authoritative target-domain Actions using a protected RMM automation
  system actor. Ticket creation uses `StoreTicket`; Task creation uses `StoreTask`; Ticket reopen
  uses an exact guarded Workflow transition; Signal handoff uses `RecordSignal`.
- `StoreTicket` allocates its public key through a locked per-year sequence row in the same outer
  transaction. Parallel RMM creators therefore serialize key allocation without a bounded retry
  race.
- Durable work links provide provenance and idempotency across occurrences without adding
  RMM-specific columns to Ticket, Task, or Signal tables.
- Rule executions freeze the rule key, revision, condition result, action snapshot/result, target
  links, and bounded error evidence.

Rules match immutable occurrence context. Target writes lock and verify the current Asset ownership,
and existing-work reuse is scoped by fingerprint plus the same Asset/Client/Site snapshot. Reused
Ticket/Task targets are also locked and checked against their current ownership.

Stored routing references are revalidated and locked inside the action transaction immediately
before they are consumed. A Queue, Type, Priority, Category, owner, assignee, or reopen status that
became inactive after rule save fails closed and permits the normal lower-rule fallback policy.
Signal webhook actions use a durable row with a database-unique action key. The row is committed with
the RMM/Signal action, an after-commit wake-up lowers latency, and an every-minute dispatcher recovers
stranded `pending` rows. A locked opaque claim permits only one HTTP start. Ambiguous or abandoned
attempts become terminal `unresolved` evidence and are not automatically replayed.

Action failures and worker interruption after an execution starts are terminal and are never
automatically replayed. Lower rules continue after an ordinary action failure. Only an interruption
before the first immutable execution exists may retry on a later active heartbeat. Controlled retry
is a future decision, not an implicit runtime behavior. Every claim has an unguessable processing
token so a stale worker cannot adopt a newer worker's lease. Successful N-able polls also sweep
resolved alerts with unfinished occurrences after the bounded stale interval.

## Rationale

RMM alerts have lifecycle and provider facts that must be interpreted before a cross-domain Signal
or work item exists. Putting those facts in Signal Rules would require every alert to become a
Signal first and would make ignore/pre-routing decisions indirect. Putting them in Ticket Rules is
too late because no Ticket may be created. A direct job-to-Task bridge cannot support alternate
actions, auditable ignore, or controlled reopen.

The explicit observation Action gives Tactical and N-able one lifecycle contract and is testable.
An Eloquent observer would also run for maintenance scripts, migrations, factories, and unrelated
model writes, and cannot express provider observation boundaries honestly.

A separate runtime follows the Ticket Rules decision not to create a generic automation engine.
Reusing UI concepts and target Actions is safe; sharing condition/action authority is not.

## Consequences

Positive:

- RMM routing has one provider-neutral, auditable entry point.
- Reopened alerts gain real occurrence history.
- Ticket/Task/Signal domain rules and permissions remain authoritative.
- No new RMM worker is required; the existing queue and per-minute scheduler also operate the
  Signal webhook outbox dispatcher.
- Work-item deduplication and reopening use durable provenance rather than text matching.

Negative:

- Nexum gains another domain-specific rule engine to maintain.
- Synchronous actions add bounded work to an RMM sync job.
- Existing alerts cannot receive trustworthy historical occurrences retroactively.
- Reopen can fail when a Ticket Workflow has no unique valid transition to the configured status;
  that failure is intentional and requires an Admin rule or Workflow correction.
- Terminal no-replay semantics favor duplicate prevention and immutable evidence over automatic
  recovery after an execution has begun.
- Signal webhook `failed` and `unresolved` outcomes require operator reconciliation; blind resend is
  rejected because receipt may have occurred before the local outcome was persisted.

## Alternatives Considered

- **Direct Alert-to-Task bridge:** rejected because it cannot express Ticket, Signal, ignore, reopen,
  or future remediation choices.
- **Convert every RMM alert to a Signal first:** rejected because provider-specific pre-routing and
  ignore decisions belong before optional Signal emission.
- **Run all logic in Ticket Rules:** rejected because the decision occurs before a Ticket exists.
- **Generic shared rule runtime:** rejected because RMM, Signal, Email, and Ticket have different
  authorities, data, guards, and retry semantics.
- **Eloquent AssetAlert observer:** rejected because it is an implicit and over-broad automation
  trigger.
- **New queued rule-processing worker:** deferred because Dev/production queue availability is an
  unnecessary new dependency for the event-driven MVP.

## Follow-Up

- The three approved Feature Slices are implemented on Dev; human review
  `HR-2026-08-25-014` remains Pending.
- Add recurrence thresholds only after occurrence history has enough verified data.
- Treat notifications, resolution actions, RMM scripts, direct RMM webhooks, and AI remediation as
  separately approved action types with their own risk and authority analysis.
- Review synchronous runtime duration after real rule volume exists; queueing is a later ADR only if
  operational evidence justifies it.
