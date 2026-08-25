# RFC: Ticket Rules Triggers, Ordered Actions, And Audited Execution

Status: Draft
Date: 2026-08-25
Owner: Svein / Codex
Related issue: GitHub #231
Related RFCs: `2026-07-08-email-ticket-signal-rule-alignment.md` and
`2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`

## Context

Ticket Rules currently provide a small creation-time classification layer. `StoreTicket` evaluates
active `on_create` rules before persistence, flat conditions use implicit AND, and matching actions
can set type, queue, priority, SLA, category, tags, or explicitly emit a Signal. The static Admin
form cannot add, remove, group, or reorder rows, and it has no trigger selector or false branch.

The current engine does not run for updates, replies, internal notes, tag changes, custom fields, or
Workflow changes. `ticket_rule_logs` exists but no runtime writes it. Rules are mutable live JSON and
have no draft/publish lifecycle or immutable execution snapshot. Most creation paths use
`StoreTicket`, but scheduled occurrences currently create `Ticket` records directly and therefore
bypass rules.

Issue #231 asks for a complete, understandable Ticket-owned automation surface:

- evaluate relevant rules for every Ticket creation path;
- evaluate only relevant rules when a Ticket changes;
- select precise event and field-change triggers;
- combine multiple conditions with AND/OR;
- execute ordered true and false actions;
- expose manually supported Ticket field, assignment, tag, custom-field, and Workflow changes as
  guarded actions;
- prevent self-reinforcing or cross-rule loops;
- show an execution log with trigger, condition, action, and change evidence; and
- preserve or deliberately migrate existing Ticket Rules.

This is Level 3 work. It changes Ticket persistence, automation, permissions, Workflow behavior,
Custom Fields, Taxonomy mutations, API/integration outcomes, and database audit state. The approved
Email/Ticket Signal alignment RFC explicitly excluded broad Ticket update and Workflow triggers until
this loop, permission, and ownership design existed.

The historical 2025 Ticket Rules view specification is design input only. It is marked
`Not completed`, uses obsolete controller locations, and cannot authorize current behavior. Its useful
principles are ordered rules, grouped conditions, ordered actions, dry-run, immutable published
versions, and execution history.

## Goals

- Keep Ticket Rules owned and enforced by the Ticket module.
- Run creation rules synchronously for UI, Customer Portal, Email, API, Intake, Relationship,
  Telephony, Signal, scheduled occurrences, Workflows, and other automated creation paths.
- Return the final rule-adjusted Ticket to the caller before the request or command completes.
- Represent triggers as typed Ticket domain events with precise changed-field filters.
- Support a readable two-level AND/OR condition tree consistent with Email Rules, Signal Rules, and
  Workflow requirements.
- Support ordered `Then` and `Else` actions with deterministic success, failure, and stop behavior.
- Route every action through an authoritative Ticket or target-domain Action and its existing
  permission, workflow, organization, and idempotency guards.
- Preserve Workflow v3 state/version history and prevent direct status/workflow field patches.
- Use one restricted, non-login Ticket Rules system actor and retain the initiating user or system
  source separately in the audit chain.
- Prevent no-op recursion, direct cycles, indirect cross-rule cycles, retry duplicates, and runaway
  action chains.
- Publish immutable rule versions and execute the version captured when a run starts.
- Store privacy-safe, immutable execution evidence and link it from Ticket history.
- Migrate existing valid rules without changing their order, matching semantics, actions, active
  state, or stop behavior.
- Deliver the work as separately approved Feature Slices with automated tests, Knowledge updates,
  and human review.

## Non-Goals

- Do not create one generic shared runtime for Email, Signal, Workflow, and Ticket rules. They may
  share UI language and small presentation components, but each domain retains its own facts,
  authorization, persistence, and execution engine.
- Do not add arbitrary PHP, shell commands, SQL, scripts, or unrestricted HTTP/webhook execution.
- Do not make customer email, public replies, Ticket merge/delete, commercial approval, billing,
  time registration, stock mutation, purchase sending, or other high-risk side effects ordinary
  Ticket Rule actions in this RFC.
- Do not let a rule grant itself, its publisher, or its system actor new permissions.
- Do not treat an API token ability, Admin role, rule ownership, or the initiating user's permission
  as an automatic runtime permission grant.
- Do not bypass Workflow v3 requirements, action policy, state mapping, review evidence, or terminal
  close guards.
- Do not infer a Ticket team model from Spatie roles, Workflow eligible-user lists, or queues. The
  product meaning of `team` must be resolved explicitly.
- Do not add timers, SLA-warning schedules, or time-based rule polling in the first implementation.
  The event registry may be extended by a later approved slice.
- Do not silently activate invalid legacy rules or rewrite existing Ticket, Workflow, Signal, or
  execution history.

## Current Behavior

- `TicketRule::TRIGGER_CREATE` is the only declared trigger.
- `TicketSettingsController` always stores `on_create` and validates a flat condition/action list.
- `TicketRuleEngine` mutates an in-memory create context. All conditions must match.
- Matching rules run by weight and ID. A successful match may stop later rule processing.
- Actions are limited to `set_ticket_type`, `set_queue`, `set_priority`, `set_sla`,
  `set_category`, `add_tag`, and `emit_signal`.
- Signal emission is skipped for Signal-created tickets, which prevents only that one known loop.
- The engine has no actor, permission check, rule version, retry key, before/after snapshot, false
  branch, action result, or general recursion guard.
- `UpdateTicketFields`, `ChangeTicketStatus`, `AddTicketMessage`, `TransitionTicketWorkflow`, inbound
  replies, Portal replies, and tag pivot updates do not invoke Ticket Rules.
- Scheduled Ticket occurrences bypass `StoreTicket` and create a Ticket directly.
- Ticket has `owner_id`, but no first-class operational team relationship.
- Custom Fields has a generic storage foundation, but Ticket is not a registered Custom Field
  target and Ticket create/edit/API flows do not manage those values.
- Email Rules already demonstrate grouped conditions, draft/publish, immutable versions, previews,
  execution attempts, and idempotent action positions. Workflow v3 demonstrates a Signal-style
  grouped builder and immutable published definitions. Ticket Rules should adopt these proven
  interaction and audit principles without sharing their runtimes.

## Proposed Change

### 1. Ticket-Owned Rule Boundary

Add a Ticket-owned rule definition, event, execution, and action boundary under
`app/Modules/Ticket`. Domain routes remain in `app/Modules/Ticket/routes.php`, controllers remain in
the Ticket module, and the Admin UI remains a Bootstrap Ticket settings surface.

Production Ticket creation and mutation paths must call authoritative Ticket Actions that emit one
normalized Ticket rule event after the mutation has a stable before/after result. An Eloquent model
observer is not authoritative because it cannot safely distinguish meaningful changes from touches,
does not cover tag pivots or messages, and could fire during migrations, backfills, tests, or direct
maintenance.

`StoreScheduledTicketOccurrence` and any other production creator that bypasses `StoreTicket` must
move behind the same creation coordinator or explicitly invoke the same protected creation contract.
Tests and data factories may still create isolated records directly when no runtime behavior is under
test.

### 2. Draft, Publish, And Immutable Versions

Each Ticket Rule has a mutable draft and at most one active immutable published version.

- Draft edits never affect runtime behavior.
- Publish revalidates the trigger, condition tree, ordered actions, Else actions, references,
  publisher authority, and automation-actor authority.
- Publishing creates a complete immutable snapshot and atomically selects it for later runs.
- Disabling a rule stops new runs but never removes versions or execution history.
- A root execution freezes the applicable published rule/version list and order. A concurrent edit
  or publish affects only later root events.
- Rule order is deterministic: weight/order first, then stable rule ID.
- `stop_processing` takes effect only after the selected branch completes successfully.

The rule summary shown before publication uses the same language as the builder:

`When <trigger> -> If <conditions> -> Then <actions> -> Else <actions> -> Continue/Stop`.

### 3. Typed Trigger Registry

Ticket owns a registry of stable trigger keys, allowed filters, and readable labels. The initial full
target includes:

- `ticket.created`;
- `ticket.updated` with optional changed-field filters;
- `ticket.field_changed` for one or more supported standard fields;
- `ticket.message_added`, filtered by customer reply, public update, internal note, or source;
- `ticket.tags_changed`, including added and removed tag IDs;
- `ticket.custom_fields_changed`, including definition IDs and before/after values;
- `ticket.assignment_changed`, including owner/unassigned and the approved team meaning;
- `ticket.workflow_changed`;
- `ticket.workflow_state_changed`; and
- `ticket.status_changed` as a composite result of the authoritative Workflow transition.

The supported standard-field catalogue includes the fields currently editable through guarded
Ticket UI/API actions, such as subject, description, type, queue, priority, SLA, category, client,
site, contact, asset, impact, urgency, owner, schedule fields, and customer-publication state. A
field is not exposed merely because a database column exists.

Every normalized event contains:

- Ticket ID and event key;
- source channel and source action;
- changed field keys;
- privacy-safe before/after values;
- initiating human, service, or upstream system identity when known;
- runtime automation actor;
- root execution, correlation, and causation IDs;
- parent event/action and chain depth; and
- occurred-at timestamp.

Trigger filters decide whether a rule is relevant before its conditions are evaluated. A priority
rule, for example, is not evaluated for an unrelated description edit. Conditions may inspect the
current Ticket, the event's before/after values, source/channel, initiating actor class, tags,
supported Custom Fields, and Workflow facts. Raw message bodies, secrets, tokens, and attachments are
not copied into execution events or logs.

Workflow transitions emit one composite Workflow/status event after the complete transition commits.
The nested low-level status write must not create a duplicate root event.

### 4. Grouped Conditions And Branches

Ticket Rules use the proven two-level group model:

- the root chooses `All groups` or `At least one group`;
- each group chooses `All conditions` or `At least one condition`; and
- each row has a typed field/fact, operator, and validated value.

This supports readable expressions such as `(critical priority OR security tag) AND Support queue`
without creating an executable expression language. Operators are whitelisted per field type.
Regex remains guarded by length and runtime limits if retained.

If the condition tree passes, ordered `Then` actions run. If it fails, ordered `Else` actions run.
An empty Else branch makes no changes. An explicitly conditionless rule is displayed and published
as `Always`; it must not be created accidentally by deleting the last row.

The engine records per-group and per-row outcomes so the execution view can explain why the branch
was selected without storing unnecessary raw content.

### 5. Typed Ordered Action Registry

Ticket owns a registry in which every action type declares:

- stable key and label;
- validated input schema and target lookup;
- required automation permission;
- permitted trigger/execution phases;
- authoritative Action/service to invoke;
- fields or domain state it may change;
- reversibility and retry/idempotency behavior; and
- whether it may enqueue an after-commit side effect.

The target action catalogue includes:

- set supported standard Ticket fields, including type, queue, priority, SLA, category, context,
  impact, and urgency;
- assign a specific eligible owner, unassign, or explicitly rerun the Assignment Engine;
- apply the approved team target after the team decision is resolved;
- add and remove Taxonomy tags through one audited Ticket tag Action;
- set or clear a registered Ticket Custom Field through the Custom Field domain;
- add an internal system note without impersonating a technician;
- select a published Workflow for a new Ticket;
- move to a specifically configured published Workflow/state through a guarded, audited Workflow
  conversion action;
- pause or resume rule-driven Workflow automation while retaining the pinned Workflow version and
  current state;
- take a valid non-terminal Workflow transition by exact transition key;
- preserve the existing explicit `emit_signal` handoff; and
- stop or continue later rules according to rule flow configuration.

Status actions must resolve an exact valid Workflow transition. They may not directly call a
low-level status patch when multiple Workflow states can share that reporting status. Workflow
selection and switching preserve version pinning, state mapping, history, evidence invalidation,
assignment policy, and ordinary action guards.

For this RFC, `stop workflow` is proposed to mean pausing rule-driven automatic Workflow movement
while preserving the Ticket's Workflow, version, current state, manual actions, and audit. It never
means deleting Workflow history or clearing fields. A different product meaning requires an RFC
revision before implementation.

Actions execute in their configured order. Later successful rules may overwrite an earlier field
write (`last successful writer wins`) unless an earlier rule stops processing. Every overwrite is
visible in the execution evidence. Creation-time fallback assignment runs only when no successful
rule action made an explicit owner/team decision; administrators may choose a separate `rerun
assignment` action when reassessment is intended.

### 6. Runtime Authority And Permissions

Unattended Ticket Rules execute as one protected, non-login `ticket_rule_automation` system actor
created through the existing `EnsureSystemActor` boundary. The initiating user or source is retained
as causation evidence but is not impersonated and does not lend permissions to the rule.

- The system actor receives only code-approved Ticket Rule action permissions.
- Rule UI and imported JSON cannot grant or synchronize actor permissions.
- Publishing requires `ticket.rules.publish` plus any action-specific publication authority.
- Runtime reauthorizes the system actor through `TicketActionGuard` and the target domain on every
  action.
- An unavailable action, inactive target, missing permission, incompatible Workflow state, or
  cross-client/work-context violation fails closed and is logged.
- High-risk action types remain absent until an approved RFC/Feature Slice adds the required policy
  and system-actor capability.

This produces the same rule result for equivalent UI, Email, API, integration, and scheduled events
without letting a low-privilege initiator gain power or a former rule author leave behind personal
authority.

### 7. Execution, Failure, And Conflict Semantics

Core Ticket actions run synchronously so the caller receives the final Ticket and the result is
visible immediately. External notifications and integration work remain queued after commit and use
stable action idempotency keys.

Each selected Then/Else branch is atomic for its synchronous Ticket-local actions:

- on success, all ordered actions and their actual changes are recorded;
- on failure, the branch's synchronous mutations roll back, the failed action is recorded, and
  later action positions are `not_run`;
- other eligible rules continue unless policy or a loop guard stops the root run; and
- `stop_processing` applies only after a successful branch, not after an unmatched or failed branch.

No-op actions record `no_change` and emit no nested change event. A successfully changed field,
message, tag, Custom Field, assignment, or Workflow state emits a child event in the same root
causation chain. The coordinator drains child events in deterministic FIFO order before returning.

Creation ordering preserves current behavior and makes it explicit:

1. validate the inbound creation command and establish defaults/source context;
2. create the stable Ticket context required for audited execution;
3. process synchronous `ticket.created` rules before returning;
4. resolve final SLA precedence from successful rule, Contract, then default policy;
5. apply explicit rule assignment or the ordinary Assignment Engine fallback;
6. persist the final created/history summary; and
7. dispatch notifications, Signal handoffs, and other external work only after commit.

The required ADR and first execution Feature Slice must settle the exact transaction/savepoint and
locking implementation while preserving these invariants. A rule failure must not erase the
original authorized user/API update; it stops or rolls back only the failed automation branch and
surfaces the failure.

### 8. Loop, Retry, And Concurrency Protection

Every root run has a durable correlation ID. Every event, rule version, branch, and action position
has a deterministic idempotency key. Protection combines:

- no event for an actual no-op;
- one execution of the same published rule version for the same normalized event fingerprint in a
  root run;
- one successful execution per action idempotency key;
- a visited event/rule/action set for direct and indirect cycles;
- configurable hard limits for event depth, evaluated rules, and action count;
- a per-Ticket lock while a root run selects state and applies a branch; and
- a frozen published rule set for the root run.

When a cycle or budget is detected, the coordinator keeps the original authorized mutation and
already completed branches, stops remaining automation in that root, records `loop_blocked`, and
adds an Admin-visible Ticket automation event. It does not silently retry the same chain.

An explicit retry may execute only failed/not-run idempotent action positions whose prerequisites and
current target state still match. A full rerun is a separate warned operation with preview; it is not
the default retry.

### 9. Execution Audit And Ticket Visibility

Use durable immutable execution records rather than the current unused minimal log as the only source
of truth:

- a root run records Ticket, event/source, initiator, automation actor, correlation/causation,
  changed-field summary, status, limits, start/end time, and duration;
- each rule execution records the immutable rule version, trigger relevance, condition outcomes,
  selected branch, ordered action snapshots/results, actual field/domain changes, stop decision,
  failure/loop reason, and duration; and
- each external after-commit result records its stable idempotency key and truthful queued,
  succeeded, failed, or unresolved state.

Completed attempts are immutable. The existing `ticket_rule_logs` table and any historical rows are
preserved as legacy evidence; they are not rewritten to imply detail that was never captured.

Ticket history receives one compact internal `automation_run` event linked to the run. It summarizes
which rules changed which fields and whether anything failed or was loop-blocked. Customer Portal and
customer-visible replies never expose internal rule definitions, conditions, permissions, or logs.

Execution logs store identifiers and safe change summaries. Sensitive free text is redacted,
truncated, or represented by a one-way fingerprint where exact content is not operationally needed.
They never store secrets, authentication headers, raw supplier/email payloads, attachment content, or
tokens. Retention follows the existing Ticket audit/history policy until a separate approved policy
defines shorter retention.

### 10. Admin Experience

The Ticket Rules UI uses Bootstrap and the same interaction language as Email Rules and Workflow v3:

- compact rule list with Draft/Published/Disabled state, order, trigger summary, Then/Else summary,
  Continue/Stop, last execution, and failure signal;
- one builder with `When`, `If`, `Then`, `Else`, `Flow`, and `Test` sections;
- add/remove/reorder controls for condition groups, condition rows, Then actions, and Else actions;
- typed target selectors rather than raw record IDs;
- readable summaries and validation attached to the affected row;
- explicit Save Draft, Preview/Test, and Publish actions;
- dry-run against an authorized Ticket and synthetic event without writes;
- preview of conditions, proposed changes, permission/policy denials, rule collisions, and loop-risk
  warnings; and
- paginated execution history with filters by rule, Ticket, event, result, and date.

Small UI-only components may be shared where they are genuinely domain-neutral. Ticket condition and
action dictionaries, validation, permissions, and execution remain Ticket-owned. No unfinished
trigger/action is shown as selectable.

Existing Ticket create/update API and integration callers receive the final refreshed Ticket after
synchronous rules. They do not need a separate admin Rule CRUD API in the first rollout. A response
may include a safe automation run ID/status in metadata where useful, but never internal conditions or
sensitive values without a separately authorized read endpoint.

## Impact Analysis

- **Ticket:** rule models/services, creation and mutation Actions, Ticket events/history, assignment,
  SLA ordering, Workflow integration, routes/controllers/Livewire/Blade views, API result timing, and
  tests.
- **Workflow v3:** exact transition/state mapping, version pinning, pause/resume semantics, action
  policy, history, evidence invalidation, assignment policy, and non-terminal automation guards.
- **Custom Field:** register Ticket as an allowed model, add guarded Ticket UI/API value handling, and
  emit value-change evidence before custom-field rule actions/triggers are enabled.
- **Taxonomy:** one audited Ticket tag mutation Action must replace direct pivot writes in production
  paths.
- **UserManagement/permissions:** protected system actor plus granular manage, publish, view-log,
  preview, and action-publication permissions. Additive permission migration is preferred over a
  destructive full-role synchronization.
- **Email/Signal:** existing inbound creation and explicit Signal handoff remain domain-owned;
  regression tests prove no duplicate ticketing or Signal loop.
- **Portal/Intake/Relationship/Telephony/Scheduled Tickets:** their create/reply/update paths must use
  the common Ticket event contract without widening caller permissions or customer visibility.
- **Integration/API:** create/update endpoints return final rule-adjusted state and keep existing token
  abilities as request ceilings.
- **Queues/notifications:** core changes are synchronous; external work is after-commit, idempotent,
  and truthfully logged. No new scheduler is required for event-driven rules.
- **Performance:** trigger relevance is indexed/filter-first, rule definitions are cached by published
  version, condition facts are batched, and histories are paginated. The loop/action budget bounds one
  root request.
- **Security/privacy:** rules cannot bypass domain guards, system actor permissions are fixed by code,
  execution evidence is internal and minimized, and preview uses the same authorization without
  side effects.
- **Documentation:** Ticket Rules/Assignment, Ticket Workflow v3, Ticket overview/operations, Custom
  Fields, permissions, API management, TODO, Feature Slices, ADR, and human review must be updated.

## Data And Migration Plan

Use additive, forward-safe migrations:

1. Add Ticket Rule draft/publish lifecycle fields and immutable `ticket_rule_versions` snapshots.
2. Add root run and per-rule execution tables with correlation, idempotency, condition/action result,
   before/after summary, actor/source, timing, and immutable-completion guards.
3. Add only the minimal Ticket Workflow pause metadata approved by this RFC; do not clear existing
   Workflow/version/state data.
4. Add Ticket Custom Field registration/value integration in its dedicated Feature Slice.
5. Add team-related schema only if the open team decision explicitly requires a first-class model.

Backfill each existing rule deterministically:

- `on_create` becomes `ticket.created`;
- the flat condition list becomes one root `ALL` group;
- existing actions retain their exact order and values;
- Else is empty;
- weight/order, active state, stop behavior, hit count, timestamps, and creator/updater are preserved;
- each valid active rule receives immutable compatibility version 1 and remains behaviorally active;
- each valid inactive rule becomes Disabled with compatibility version 1; and
- an invalid or ambiguous rule is disabled, flagged for Admin review, and never silently repaired.

During rollout, a compatibility reader can execute published version 1 with existing creation
semantics until the new coordinator is enabled. Before activation, compare rule counts, active
counts, order, normalized definitions, referenced targets, and representative dry-run results.

Preserve `ticket_rule_logs` and any rows as legacy read-only history. Do not drop legacy columns or
tables in the same release. A later cleanup requires its own verified migration after the rollback
window and human review.

Rollback disables the new coordinator and returns compatible creation rules to the legacy reader
while preserving new versions and execution evidence. Once update/workflow actions have run, schema
destruction is not an automatic rollback; use the documented forward fix or a data-aware downgrade.

Production deployment requires a database backup, additive migration and schema read-back, rule
backfill/validation report, permission read-back, cache clear, queue-worker restart when after-commit
actions are enabled, focused smoke events, and a no-loop/no-failed-execution check.

## Testing Plan

- Unit tests for typed trigger relevance, before/after facts, grouped AND/OR, operators, Always rules,
  Then/Else selection, ordered actions, last-writer-wins, Continue/Stop, and readable summaries.
- Definition tests for draft isolation, publish validation, immutable versions, disabled state,
  frozen root rule sets, missing/deleted targets, and permission loss after publication.
- Migration tests for valid active/inactive legacy rules, invalid targets, preserved order/actions,
  retry-safe backfill, compatibility reader parity, rollback guard, and retained legacy logs.
- Creation-path tests for Tech UI, Customer Portal, Email, API, Intake, Relationship, Telephony,
  Signal, scheduled occurrences, Workflow/automation callers, and direct-production-creator audits.
- Update trigger tests for each supported standard field, assignment, message/internal note, tags,
  Custom Fields, Workflow, and composite status changes, including unrelated/no-op updates that do not
  evaluate or recurse.
- Action tests proving every handler calls the authoritative Action, uses the system actor, applies
  ordinary permissions and Workflow policy, respects work context, and records exact changes.
- Assignment/SLA tests for explicit rule precedence, fallback behavior, rerun assignment, Contract SLA,
  and multiple matching rules.
- Workflow tests for exact transition keys, repeated reporting statuses, start/switch mapping,
  pause/resume, non-terminal restriction, evidence/history preservation, and action-policy denial.
- Loop tests for self-change, A-to-B-to-A cycles, message/tag/custom-field cycles, no-op suppression,
  depth/action budgets, idempotent retry, concurrent updates, and nested Workflow/status events.
- Failure tests for per-branch rollback, later actions `not_run`, later-rule continuation, external
  after-commit failure, and truthful unresolved delivery state.
- Audit/privacy tests for immutable completed attempts, Ticket-history links, customer non-disclosure,
  redaction, pagination, and log authorization.
- Email/Signal/Portal/Integration regressions proving no duplicate Tickets, Signals, customer messages,
  or permission widening.
- Bootstrap/Livewire browser review on desktop and mobile for add/remove/reorder, dependent selectors,
  validation, summaries, preview, publish, logs, keyboard focus, and touch controls.
- Narrow Ticket/Workflow/CustomField/Taxonomy/Email/Signal/Integration suites per Feature Slice, then
  the broad Laravel suite and authenticated Dev HTTP smoke checks before release handoff.

Automated tests never complete human review. Every implemented Feature Slice receives or updates a
stable `docs/human-review.md` entry until a named reviewer explicitly confirms the checks.

## Documentation Plan

- Update `app/Modules/Ticket/Docs/knowledge/ticket-rules-assignment.md` with triggers, grouped
  conditions, Then/Else actions, ordering, actor authority, Workflow behavior, loops, logs, preview,
  and troubleshooting.
- Update Ticket Workflow v3 documentation with the exact rule-driven transition/switch/pause boundary.
- Update Ticket overview and technical operations with the authoritative event/action contract.
- Update Custom Field and Taxonomy documentation when Ticket support and audited tag actions land.
- Update UserManagement/permission and Integration/API documentation for the system actor,
  publication permissions, final API results, and log access.
- Add the approved workstream near the top of `docs/TODO.md` and keep its Feature Slice/human-review
  state current.
- Add a public-safe Nexum website handoff only after verified implementation, with `do not publish`
  until human review and release status permit it.

## Feature Slice Plan

Implementation must not be attempted as one broad change. After RFC approval, prepare and approve
the exact Feature Slice before starting each step:

1. **Architecture, versions, and legacy compatibility:** accepted ADR; Ticket-owned definition
   registry; draft/publish; immutable versions; additive schema; compatibility v1 backfill; granular
   permissions; no update triggers yet.
2. **Execution envelope, audit, and loop foundation:** typed event/run/execution records; restricted
   system actor; condition evidence; idempotency; limits; locks; preview engine; legacy `ticket.created`
   parity before new action types are enabled.
3. **Standard update, message, assignment, and tag automation:** canonical action boundaries;
   field/message/tag/assignment events; Then/Else; ordered standard-field actions; audited tag changes;
   creation-path consolidation; SLA/assignment precedence.
4. **Workflow actions and composite events:** exact transitions; create-time Workflow selection;
   guarded Workflow switching; approved stop/pause semantics; composite Workflow/status event;
   Workflow/assignment regressions and loop hardening.
5. **Ticket Custom Fields and approved team semantics:** register and expose Ticket Custom Fields,
   guarded value triggers/actions, and implement the team decision without overloading roles, queues,
   or Workflow pools accidentally.
6. **Admin builder, execution history, and release hardening:** Bootstrap/Livewire UI; typed selectors;
   reorder; dry-run; publish; log/ticket-history views; Knowledge/TODO/API docs; broad tests; migration
   report; Dev smoke; human-review checklist.

Later slices may begin only when the contracts they consume are stable and verification failures are
fixed or explicitly deferred. Ticket Rule runtime activation remains default-off until compatibility
validation and the relevant human-review checks pass.

## Open Questions

1. What does `team` mean for Issue #231? The current product has queues and individual owners, but no
   first-class Ticket team. The recommended first rollout treats Queue as the routing group and Owner
   as the individual assignment, while keeping a typed team provider unavailable. If the required
   behavior is a persistent operational team independent of Queue, that model, membership,
   permissions, Workflow interaction, and migration must be approved as part of Feature Slice 5.

## Approval

Pending. Implementation must not begin until Svein explicitly resolves the team meaning above and
approves this RFC. Approval should change `Status` to `Approved`, record the approver/date here, and
authorize only the separately reviewed Feature Slices, not Main promotion or production deployment.
