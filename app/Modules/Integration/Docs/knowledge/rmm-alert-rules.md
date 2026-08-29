## Purpose

RMM Alert Rules decide what Nexum should do with a new or reopened Tactical RMM or N-able RMM
alert. They are a provider-neutral pre-routing layer:

```text
RMM alert -> RMM Alert Rules -> Ticket, Task, Signal, or ignore
```

They do not replace Ticket Rules or Signal Rules. Ticket Rules remain authoritative after a Ticket
is created. Signal Rules remain authoritative after an RMM rule explicitly emits a Signal.

## Access

Open **Admin > System > Integrations > RMM Alert Rules**. The same page is linked from both RMM
provider settings pages. Reading and changing this page requires `integration.rmm_manage`.

New rules are disabled by default. Disable an active rule before soft-deleting it. Deletion keeps
the immutable execution history.

## When Rules Run

Nexum keeps one mutable `AssetAlert` for the current fingerprint state and one immutable occurrence
for each activation:

- the first failing observation creates a `triggered` occurrence;
- an unchanged failing heartbeat refreshes the current alert but does not run rules again;
- Tactical resolves from an explicit passing observation;
- N-able resolves a fingerprint only when it disappears from a successful failing-check response;
- provider or HTTP failure does not resolve an active alert;
- a later failure creates a `reopened` occurrence and runs rules again.

Existing alerts receive the safe default severity `warning` during migration. They do not receive
synthetic occurrences and do not run rules until a future genuine reopen.

The scheduled command is `integrations:rmm-alert-sync` every 15 minutes. Automatic operation also
requires an external runner for `php artisan schedule:run` every minute and a queue worker for the
dispatched provider jobs and pending Signal webhook outbox rows.

## Conditions

Every configured condition in one rule must match. At least one condition is required.

Supported conditions are:

- alert subject contains, case-insensitive;
- normalized severity: `info`, `warning`, or `critical`;
- exact Asset;
- exact Client;
- exact fingerprint; and
- Tactical RMM or N-able RMM provider.

Asset, Client, and Site facts come from the immutable occurrence snapshot. Immediately before a
target write, Nexum locks and compares the current Asset. Ownership drift fails closed. Reuse of
an existing Ticket or Task is scoped to the same fingerprint and Asset/Client/Site context, so an
Asset reassignment cannot update another Client's work.

Queue, Ticket Type, Priority, Category, owner, assignee, and reopen-status selections are checked
again under a database lock when they are about to be used. If an administrator disables or
invalidates one after saving the rule, that action fails closed and a lower-priority fallback rule
may continue. Existing linked work can still be reused because those old routing selections are not
consumed during reuse.

N-able severity and check type are preserved when the provider includes those optional fields. A
missing or unknown provider severity becomes `warning` rather than an invented critical event.

## Ordered Actions

Actions run in the displayed order:

- **Create or update Ticket** creates a Ticket through `StoreTicket`, including normal Ticket
  Rules. A later occurrence reuses the newest open Ticket for the same fingerprint and ownership
  context and adds one idempotent internal update. New Ticket keys come from a locked yearly
  sequence, so parallel alert actions cannot choose the same key.
- **Create or reuse Task** creates through `StoreTask`. A later occurrence reuses an open Task in
  the same context and records internal source activity.
- **Reopen linked Ticket** uses the exact configured Ticket Workflow transition. It never directly
  patches status fields. An already-open Ticket gets an update. If no scoped linked Ticket exists,
  the action is recorded as skipped and a lower fallback rule may still run.
- **Emit Signal** records one RMM Signal and then runs ordinary Signal Rules. Any Signal webhook job
  has a database-unique action key and a delivery row committed with the enclosing transaction; a
  rollback retains neither row nor request. After commit, an immediate wake-up is best effort and
  `signal.webhook.dispatch` recovers `pending` rows every minute.
- **Ignore** records an audited decision and stops every later RMM action and rule. Ignore must be
  the only action in its rule.

A successful rule with **Stop lower rules after success** prevents broader fallback rules. A rule
that only skips because it had no target does not suppress a fallback.

RMM recovery never closes a Ticket or completes a Task automatically.

## Failure And Replay Semantics

An action failure stops later actions in that rule and marks them `not_run`. Lower rules continue
unless an earlier ignore already stopped routing. The occurrence becomes
`completed_with_failures`.

Once any immutable execution exists, Nexum does not automatically retry or fully rerun that
occurrence. This avoids executing a changed rule definition against old evidence. A worker
interruption after execution started is terminalized on a later active heartbeat and is not
replayed. A resolved N-able alert with unfinished routing is revisited after the bounded stale
interval on a later successful poll. Every worker is bound to a UUID lease, so an older worker
cannot adopt a newer claim or continue target actions after terminal recovery. Only a structural
interruption before the first execution exists may retry on a later active heartbeat.

There is intentionally no retry or full-rerun button in this version.

Signal webhook recovery is a separate downstream boundary. Only `pending` may be claimed for HTTP.
One opaque locked claim prevents duplicate jobs for the same action from sending twice. Successful
delivery becomes `delivered`, non-success HTTP becomes `failed`, and abandoned or transport-
ambiguous work becomes `unresolved`. `failed` and `unresolved` are not automatically resent because
the receiver may already have accepted the request. Direct RMM webhook actions remain out of scope.

## Audit Evidence

The compact Admin page shows occurrence identity, rule name/revision/match, terminal status or
bounded error, and linked targets. The durable database audit additionally stores:

- the immutable rule key, revision, definition snapshot, and author IDs;
- each condition result and ordered action result;
- linked Ticket, Task, or Signal IDs; and
- `not_matched`, `completed`, `ignored`, or `failed` status with bounded operator-safe errors.

Credentials, authentication headers, raw provider responses, and unrestricted check output are not
copied into the rule audit. Tactical HTTP logging also omits raw response bodies.

Unattended writes use the disabled-login `rmm_alert_rule_automation` actor with exactly:

- `ticket.create`;
- `ticket.note_internal`;
- `ticket.reopen`;
- `task.create`; and
- `task.source_update`.

`task.source_update` is used only for internal RMM activity on a reused Task. The human rule author
is retained in the rule snapshot but is never impersonated at runtime.

RMM Ticket creation, source-update notes, and Workflow reopen suppress synchronous Ticket owner
notifications. A future explicit RMM notification action requires its own approved slice; it is not
smuggled through the Ticket transaction before the durable RMM work link exists.

## Operational Troubleshooting

If a rule does not run, confirm the provider integration is active, the scheduler and queue worker
are running, and the alert created a new or reopened occurrence. Then inspect recent executions for
condition results or a bounded failure.

If a target action fails after an Asset was moved, verify the current Client/Site assignment and
create a new genuine occurrence after correcting the rule or ownership. Do not edit audit rows or
manually replay an old occurrence.

If the bounded failure follows an administrative routing change, reopen the rule and replace any
inactive Queue, Type, Priority, Category, owner, assignee, or reopen status. Do not reactivate an
obsolete target only to force an old occurrence through; execution evidence remains terminal.

For an emitted Signal webhook, inspect `signal_webhook_deliveries`. A growing `pending` cohort means
the scheduler or queue worker is unhealthy. A fresh `running` row has an active claim; an old claim
is terminalized as `unresolved`. Reconcile `failed` or `unresolved` with the receiver before any
manual resend. Never reset those states merely to force automatic replay.
