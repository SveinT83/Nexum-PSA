# RFC: RMM Alert Rules

Status: Approved
Date: 2026-08-25
Owner: Svein / Codex

## Context

GitHub Issue #226 was originally written as a direct Alert-to-Task bridge. Svein's controlling
comment on 2026-08-25 replaces that design with an RMM-specific decision layer:

`RMM Alert -> RMM Alert Rules -> Action(s) -> Ticket, Task, Signal, or ignore`.

Nexum already has Ticket Rules for a Ticket after it is created and Signal Rules for normalized
cross-domain events. RMM alerts carry provider, Asset, Client, fingerprint, severity, and lifecycle
context that must be evaluated before Nexum decides whether a work item or Signal should exist.
This is Level 3 work because it adds data, background automation, an Admin workflow, permissions,
and Integration/Asset/Ticket/Task/Signal boundaries.

## Goals

- Add one provider-neutral RMM Alert Rules layer after Tactical or N-able data is normalized into an
  `AssetAlert` occurrence.
- Evaluate future alert activations and reopenings against ordered, enabled rules.
- Support MVP conditions for subject/title contains, normalized severity, Asset, Client, exact
  fingerprint, and RMM provider.
- Support ordered MVP actions: create Ticket, create Task, reopen Ticket, emit Signal, and ignore.
- Reuse an open work item for the same fingerprint instead of creating duplicate work.
- Preserve immutable occurrence, condition, action, target-link, and failure evidence.
- Keep Ticket Rules and Signal Rules authoritative after their respective domain records exist.
- Provide a compact Admin rule builder and execution history protected by `integration.rmm_manage`.

## Non-Goals

- Do not implement a direct hardcoded Alert-to-Task bridge.
- Do not replace Ticket Rules, Signal Rules, or their execution engines.
- Do not add a generic shared automation runtime.
- Do not run RMM scripts, auto-remediation, AI remediation, provider writes, or direct RMM webhook
  actions. An emitted Signal may still use its existing Signal Rule webhook action.
- Do not add recurrence thresholds such as N failures in M days in this MVP.
- Do not infer historical occurrences from existing mutable `asset_alerts` rows.
- Do not automatically close Tickets or complete Tasks when an alert resolves.
- Do not expose actions that are not implemented and tested.

## Behavior Before This RFC

`integrations:rmm-alert-sync` dispatches Tactical and N-able alert sync jobs. Both jobs normalize failures into one
mutable `asset_alerts` row per unique fingerprint and move that row between `active` and `resolved`.
The row has no severity, provider context, occurrence ledger, rule execution, or durable work link.
A reopened alert overwrites the same row, so repeated activations are not auditable. The existing
`AssetAlertTriggered` notification class is not called by production alert ingestion.

## Proposed Change

### Domain Boundary

The Integration module owns RMM Alert Rules because it owns provider ingestion and the pre-routing
decision. Asset remains the normalized alert and Asset/Client context owner. Ticket, Task, and
Signal own every mutation after the rule chooses an action.

Both provider jobs call one explicit `RecordRmmAlertObservation` action. It updates the current
`AssetAlert`, creates one immutable occurrence only for a new activation or resolved-to-active
reopening, and invokes the rule processor after the occurrence transaction commits. A routine
15-minute refresh of an already-active alert does not create another occurrence or rerun rules.
Tactical resolves from an explicit passing observation. N-able resolves when a fingerprint is
absent from a successful failing-check response. Provider or HTTP failure never resolves an active
alert.

### Rule Definition And Conditions

Rules are ordered by `priority`, then ID. Each rule has a stable UUID key, revision, enabled flag,
optional stop-after-success flag, conditions, ordered actions, and author metadata. MVP conditions
use implicit AND and are optional individually:

- case-insensitive `subject_contains` against the normalized alert title;
- one or more normalized severities: `info`, `warning`, or `critical`;
- one Asset ID;
- one Client ID resolved through the Asset;
- exact fingerprint;
- provider/integration type.

A rule must contain at least one condition so an accidental empty form cannot route every alert.
Existing provider data without an explicit severity normalizes to `warning`; the provider's raw
severity token is retained only in minimized provider context when present.

Asset and Client conditions use the immutable occurrence snapshot; Site is frozen for routing and
reuse safety but is not an exposed MVP condition. Immediately before a target write, the current
Asset row is locked and compared with that snapshot. Ownership drift fails closed. Existing-work
reuse requires both the fingerprint and the same Asset/Client/Site context. The linked Ticket or
Task is also locked and checked against its current ownership before reuse, so either Asset or
target reassignment cannot cross customer boundaries.

Configured Queue, Ticket Type, Priority, Category, owner, assignee, and reopen-status references
are valid only while they remain active and eligible. The executor revalidates and locks each
reference immediately before a new target write. A reference disabled after rule save fails that
rule closed, while the next lower-priority fallback rule may continue.

### Ordered Actions

`create_ticket` first finds the newest non-closed Ticket already linked to the fingerprint. If one
exists, it adds one idempotent internal RMM update instead of creating a duplicate. Otherwise it
calls `StoreTicket` with channel `rmm`, Asset/Client/Site context, configured routing values, and the
protected RMM automation actor. Ticket Rules continue through the normal `StoreTicket` path. The
RMM action suppresses synchronous Ticket owner notifications for creation, internal updates, and
Workflow reopen so an external message cannot escape before the durable RMM work link commits;
explicit RMM notification is a future action type.

All Ticket creation, including parallel RMM actions, allocates `TD-...` keys through one locked
per-year sequence row. The allocation and Ticket insert remain in the enclosing transaction, so a
burst cannot lose an otherwise valid RMM action to a `ticket_key` uniqueness race.

`create_task` reuses an unresolved Task linked to the fingerprint and records the new occurrence as
Task activity. Otherwise it calls `StoreTask` with RMM source metadata and configured routing.

`reopen_ticket` finds the newest linked Ticket. An already-open Ticket receives an idempotent RMM
update. A closed Ticket moves only through `TransitionTicketWorkflow::handleToStatus` to the exact
configured active Ticket status. Missing or ambiguous Workflow transitions fail closed and are
audited; status columns are never patched directly.

`emit_signal` records one Signal per occurrence/rule/action using source domain `rmm`, Asset/Client
context, normalized severity, and minimized provenance. Normal Signal Rules then run. RMM Rules do
not consume the derived Signal, which prevents a direct loop. A webhook action commits one durable
delivery row with a database-unique Signal action key in the same transaction. Rollback therefore
retains neither the row nor an external request. After commit, an immediate queue wake-up is best
effort and the every-minute `signal.webhook.dispatch` job recovers rows still in `pending`.

The delivery worker claims a row under lock with an opaque token. Only `pending` may start HTTP.
Success becomes `delivered`, a non-success response becomes `failed`, and a transport exception or
abandoned `running` claim becomes `unresolved`. `failed` and `unresolved` are never blindly replayed
because the receiver may already have accepted the request.

`ignore` records the decision and stops remaining actions and lower-priority RMM rules for the
occurrence. An ignore action is the only action allowed in its rule.

A failed action stops later actions in that rule. Other rules continue unless ignore or a
successfully completed rule with `stop_processing` stops the root evaluation.

### Authority, Audit, And Idempotency

Unattended actions use one protected, disabled-login `rmm_alert_rule_automation` system actor with
only `ticket.create`, `ticket.note_internal`, `ticket.reopen`, `task.create`, and
`task.source_update`. The last permission is system-only and records internal source activity when
a Task is reused. The human rule author is retained as audit
metadata but is never impersonated at runtime.

Every activation has one occurrence row. Every evaluated rule stores its revision and immutable
definition snapshot, condition results, ordered action results, status, timing, and bounded error.
Every created or reused Ticket, Task, or Signal has a work-link row keyed by occurrence, rule key,
and action position. The link and target mutation share a transaction where the target domain
permits it.

An action failure is terminal for that occurrence: later actions in the same rule are `not_run`,
lower rules continue, and the occurrence becomes `completed_with_failures`. Once an immutable
execution exists, the occurrence is never automatically retried or fully rerun. An interrupted
execution is terminalized on a later active heartbeat or resolved observation without replay. A
lease check prevents a stale worker from continuing target actions after terminal recovery or a
new claim. Only a structural interruption before the first execution exists may retry on a later
active heartbeat. The MVP has no retry/full-rerun UI.

Execution evidence stores IDs and bounded operational text, not credentials, authentication
headers, raw provider responses, or unrestricted message output.

### Admin Experience

Add a shared RMM Alert Rules page under Admin > System > Integrations and link it from both Tactical
and N-able settings. The Bootstrap UI provides a dense rule list, create/edit form, typed selectors,
ordered action rows, enable/disable, priority, stop behavior, and recent execution history. Only the
conditions and actions implemented by this RFC are selectable.

## Impact Analysis

- **Integration:** owns rule CRUD, definitions, evaluator, action coordinator, execution audit, and
  the shared RMM settings link.
- **Asset:** `AssetAlert` gains normalized severity/provider context and relationships; provider jobs
  use one observation action.
- **Ticket:** creation, internal updates, and reopening use existing authoritative Actions. Ticket
  Rules continue after new Ticket creation, and a locked yearly sequence serializes Ticket keys.
- **Task:** creation uses `StoreTask`; unresolved Tasks are reused by fingerprint.
- **Signal:** explicit handoff uses `RecordSignal`, after which Signal Rules remain authoritative.
  Signal webhook actions use a durable, uniquely keyed, claimed outbox.
- **Permissions:** existing `integration.rmm_manage` protects Admin CRUD/history. The runtime actor
  receives only `ticket.create`, `ticket.note_internal`, `ticket.reopen`, `task.create`, and the
  system-only `task.source_update` permission.
- **Queues/scheduler:** no new RMM runner is required. Rule evaluation runs in the existing RMM
  alert sync job after alert persistence. The already-required queue/scheduler runtime also runs
  `signal.webhook.dispatch` every minute to close the database-commit-to-broker gap.
- **Security/privacy:** raw provider payloads and secrets are excluded from rule and execution data.
- **UI/docs:** Integration settings, Knowledge, TODO, and human review are updated.

## Data And Migration Plan

Use additive migrations for:

- normalized `severity` and minimized `provider_context` on `asset_alerts`;
- `rmm_alert_occurrences`, including a nullable UUID processing-lease token added by the follow-up
  additive migration;
- `rmm_alert_rules`;
- `rmm_alert_rule_executions`; and
- `rmm_alert_work_items`.

The cross-domain safety additions create `ticket_key_sequences` and add `claim_token`,
`completed_at`, a dispatch index, and a unique `action_key` to `signal_webhook_deliveries`. Existing
webhook rows are preserved. Backfill prefers existing delivered evidence as canonical, marks extra
nonterminal duplicates `unresolved`, gives every remaining legacy row a synthetic unique key, and
then requires the column to be non-null.

Existing alerts receive severity `warning` but no synthetic occurrence and no automatic rule
execution. Only future new/reopened alerts enter the rule pipeline. Disabling all rules is an
immediate operational rollback. Code rollback leaves audit history intact; schema rollback is
allowed only after confirming the new tables contain no rule/audit rows and no enriched current
alerts. The migration's `down()` preflight refuses destructive rollback while such evidence exists.

## Testing Plan

- Unit coverage for every condition, implicit AND, ordering, stop, ignore, action failure, and rule
  snapshot.
- HTTP-backed provider-job coverage for new versus refreshed versus reopened versus resolved
  Tactical and N-able observations, optional provider fields, provider failure, and missing
  credentials.
- Idempotency and deduplication tests for Ticket, Task, and Signal, terminal failure/non-replay, and
  structural recovery before the first execution.
- Commit/rollback coverage for Signal webhook handoff, plus runtime revalidation of every stored
  Ticket/Task routing reference after it becomes inactive or ineligible.
- Atomic Ticket-key allocation coverage inside an outer RMM-style transaction.
- Signal webhook coverage for unique action keys, stranded `pending` recovery, duplicate jobs,
  abandoned claims, ambiguous transport, queue-publication failure, and scheduler registration.
- Ticket tests proving `StoreTicket` runs normal Ticket Rules and reopen uses a valid Workflow
  transition rather than direct status mutation.
- Permission and CRUD tests for revisioned saves, active-delete protection, and soft delete, plus
  fail-closed definition tests for empty, invalid, and unsupported condition/action fields.
- Integration/Asset/Ticket/Task/Signal focused suites, migration status/read-back, Pint, and an
  authenticated or redirect-safe HTTP smoke check on Dev.

## Documentation Plan

Add an Integration Knowledge article for RMM Alert Rules and update the Asset alert documentation,
TODO, RFC/ADR/Feature Slice indexes when applicable, and `docs/human-review.md`.

## Open Questions

None for the MVP. Recurrence windows, notifications, resolution actions, scripts, direct RMM
webhooks, and AI remediation require later approved slices.

## Approval

Approved by Svein in conversation on 2026-08-25 by instructing Codex to implement Issue #226 and
clarifying that the latest comment's RMM Alert Rules system, not the original direct bridge, is the
required product direction.
