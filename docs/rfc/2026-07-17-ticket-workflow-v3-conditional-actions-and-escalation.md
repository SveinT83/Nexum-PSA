# RFC: Ticket Workflow v3 Conditional Actions, Approval Gates, And Internal Escalation

Status: Approved
Date: 2026-07-17
Owner: Nexum PSA product owner and Codex

## Context

Ticket Workflow v1 and the v2 editor provide a default workflow, status-based states,
transitions, and four enforced requirement flags. The current form is difficult to understand and
cannot express the operational rules needed for repair, sales, approval, and implementation work.

A normal customer request may begin in the default workflow and later involve an asset, planned
equipment, estimated labour, customer approval, a Sales quote, stock handling, implementation, or
senior oversight. Nexum must let an administrator describe those rules without hard-coding one
process for every company. Technicians must see what they can do, what is blocked, why it is
blocked, and the exact evidence needed to continue.

This is Level 3 work. It changes Ticket workflow and permission enforcement and coordinates data
and commands owned by Asset, Commercial, Sales, Storage, Economy, Email, Task, and UserManagement.
It requires an approved RFC, one architecture decision record, separate Feature Slices, database
migrations, cross-module tests, and human review before release.

## Goals

- Replace the fixed workflow form with an intuitive builder modelled on the Signal rule builder.
- Support grouped `all` and `any` requirements, including expressions such as
  `(customer approved OR signature uploaded OR valid contract) AND asset linked`.
- Make workflow states independent operational steps while retaining coarse Ticket statuses for
  reporting and existing integrations.
- Let every state define available, hidden, blocked, or conditional technician actions.
- Enforce the same action policy on buttons, forms, direct routes, API requests, bulk actions, and
  background actions.
- Let administrators create optional or required internal escalation paths to another workflow,
  state, queue, type, and eligible owner pool.
- Keep workflow changes manual by default. Conditions may expose or require an escalation, but the
  technician makes the actual escalation decision.
- Allow assignment and reassignment only when both ordinary permissions and workflow conditions
  permit it.
- Support a four-eyes gate where an eligible senior technician must review the current evidence
  before a junior technician can continue.
- Add reusable requirement providers for Ticket, Asset, Commercial contracts, customer messages,
  classified signature evidence, Task, Sales quotes, Storage, Economy, and user eligibility.
- Reuse the Sales quote engine and quote editor from a Ticket instead of implementing a separate
  Ticket quote system.
- Separate planned cost scope from reserved, picked, billable, or ordered cost records.
- Allow an accepted quote to unlock implementation work and allow actual delivered time and items
  to produce the existing Economy order at completion.
- Preserve an explainable, immutable audit trail for decisions, reviews, acceptances, workflow
  changes, assignments, and downstream actions.
- Migrate existing workflows and open tickets without silently changing their active process.

## Non-Goals

- Workflow does not grant permissions that a technician does not already have. It may only narrow
  ordinary permissions and domain guards.
- This RFC does not replace the Sales quote, Commercial contract, Asset, Storage reservation,
  Economy order, Email, Task, or Ticket assignment engines.
- This RFC does not make internal workflow escalation automatic by default.
- Internal workflow escalation is not the existing Nexum Relationship provider escalation. The two
  actions must have separate names, permissions, routes, and audit events.
- This RFC does not automatically send purchase orders to a vendor. An accepted quote may create or
  unlock a draft purchase need, but sending remains an explicit authorized action unless a later
  approved RFC says otherwise.
- This RFC does not redesign the complete Sales CPQ product described in
  `docs/rfc/2026-07-04-sales-quotes-cpq.md`. It defines the Ticket-origin quote integration only and
  reuses the implemented Sales engine.
- This RFC does not redesign multi-asset Ticket relationships. The first provider uses the current
  Ticket asset relationship while keeping the provider contract extensible.
- This RFC does not create a general-purpose automation platform or scripting language.
- This RFC does not infer approval from an arbitrary old email, a normal attachment, or a read
  marker.
- This RFC does not let a quote replace actual time, picked-item, cost, or Economy records.

## Current Behavior

- A Ticket stores `workflow_id`, but not a first-class current workflow state.
- A workflow state is bound one-to-one to a global `TicketStatus` through `ticket_status_id`.
  Multiple operational steps cannot share the same coarse status.
- Transitions are defined from one global status to another global status.
- State and transition requirements are four booleans: internal note, technician response,
  solution, and Knowledge follow-up. They are combined through implicit AND logic.
- The workflow runtime returns the first blocked reason and validates status transitions.
- The admin editor stores workflow metadata, states, transitions, and the fixed flags, but it does
  not provide a grouped visual condition builder or per-state action policy.
- New tickets receive the active default workflow. Ticket rules run during creation and may set
  queue, type, priority, SLA, category, tags, and Signal behavior, but they do not perform a safe
  runtime workflow conversion.
- Ticket assignment can use fixed rules and technician profile scoring, but a workflow cannot
  define an eligible owner pool or force reassessment during an internal escalation.
- Ticket cost entries represent real pending costs. Reserving a Storage item creates a reservation
  and pending cost immediately; there is no separate pre-approval planned-cost stage.
- A Sales quote belongs to a Sales Opportunity. Implemented quote versions can be sent, rendered,
  viewed through secure public or portal links, and accepted. Acceptance marks the quote accepted
  and the Opportunity won.
- Sales quote mail currently uses the Sales mail flow. Ticket replies have their own message and
  attachment flow. There is no shared action that sends the same immutable quote version as both a
  Ticket reply attachment and a secure acceptance link.
- Public and portal quote acceptance is structured, but a technician cannot classify a specific
  inbound Ticket reply as acceptance of a specific quote version.
- Contract acceptance evidence exists, but it is not exposed through a reusable workflow
  requirement provider.
- A Ticket may reference an Asset, but Asset presence and Asset fields cannot be used as grouped
  workflow requirements.
- There is no durable senior-review checkpoint tied to a workflow gate and the evidence reviewed.

## Proposed Change

### 1. Ownership And Orchestration Boundary

Ticket owns:

- workflow definitions and published versions,
- workflow-specific states and transition history,
- grouped requirement definitions and evaluation results,
- per-state action policy,
- internal workflow escalation,
- workflow review checkpoints,
- the Ticket-side links and audit events that coordinate other domains.

Each participating domain keeps ownership of its records, permissions, validation, and commands.
For example, Sales owns Opportunities, quotes, versions, acceptance, and win/loss state; Storage
owns reservations and picking; Economy owns order preparation; Commercial owns contracts; Asset
owns Assets. Ticket calls registered domain actions and reads registered domain facts rather than
duplicating their business logic.

An ADR must record this provider-and-orchestrator boundary before the first implementation slice.

### 2. Published Workflow Definitions

A workflow gains draft and published definitions. A published definition is immutable and has a
stable version number. It contains:

- workflow defaults,
- operational states,
- transitions,
- action policies,
- requirement trees,
- escalation paths,
- ownership and assignment policies,
- configured on-enter and on-exit behavior.

Each Ticket is pinned to a published workflow version and current workflow state. Publishing a new
version does not silently modify active tickets. An administrator may later run an explicit,
previewed migration of selected active tickets to a compatible version. That migration must show
state mappings, blocked tickets, proposed assignment changes, and an audit summary before applying
anything.

Draft validation prevents publishing when the initial state is missing, state keys are duplicated,
transitions point to missing states, an action or requirement provider is unavailable, an
escalation points to an inactive workflow, or no safe target state mapping exists.

### 3. Workflow-Specific States

States become operational steps such as Received, Diagnose, Cost Estimate, Awaiting Approval,
Implementation, Quality Check, Ready For Pickup, Resolved, and Closed. A state may map to a coarse
global Ticket status such as New, In Progress, Waiting, Resolved, or Closed for reporting, filters,
SLA behavior, and backward compatibility. Multiple workflow states may map to the same coarse
status.

Entering a state updates the mapped coarse status and records an immutable transition event with:

- source and target workflow/version/state,
- previous and resulting coarse status,
- actor and source route/action,
- satisfied and failed requirement snapshot,
- linked approval, quote, review, or escalation evidence,
- timestamp and idempotency key.

### 4. Signal-Style Requirements Builder

The editor presents requirement groups as plain-language cards. A group can require `All` or `At
least one`, and the root can combine groups using `All` or `At least one`. The stored definition is
a versioned condition tree so deeper nesting can be added without another data migration, while the
default UI remains a simple two-level builder.

Every row contains:

- a provider and fact, for example `Asset / is linked`,
- an operator appropriate for the fact,
- an optional comparison value,
- a stable provider schema version,
- a human-readable label and blocked explanation.

The same builder is reusable for:

- requirements to enter or leave a state,
- a specific transition,
- an action becoming available,
- an internal escalation becoming optional or required,
- assignment to another technician,
- a senior-review gate.

Evaluation returns all missing facts, not only the first failure. The Ticket UI displays a checklist
with fulfilled and missing requirements and direct links to allowed corrective actions. Provider
errors fail closed for guarded actions, return a safe temporary-unavailable explanation, and are
logged without exposing customer or credential data.

Initial fact providers include:

- **Ticket:** required standard/custom fields, category, type, queue, messages, internal note,
  technician response, solution, time, cost, and Knowledge follow-up.
- **Asset:** Asset linked, Asset status, ownership/client match, and configured required Asset
  fields.
- **Commercial:** current accepted/valid contract, contract type, terms acceptance, and validity
  dates.
- **Customer evidence:** written customer response linked to a particular request, classified
  uploaded signature, and explicit manual evidence with actor and reason.
- **Task:** all or selected required Ticket tasks completed.
- **Sales:** planned lines present, current quote version created/sent/accepted/declined/expired,
  accepted amount, and downstream implementation lines.
- **Storage:** approved lines reserved, available, picked, returned, or converted to a purchase
  need.
- **Economy:** actual costs/time ready, order generated, or no-billing closure outcome.
- **User/assignment:** current owner eligibility, actor capability, senior review, and separation of
  duties.

Example repair policy:

```text
ALL
  Group 1 - AT LEAST ONE
    Customer accepted the current request in writing
    Classified customer signature is uploaded
    Customer has a valid accepted contract covering the work
  Group 2 - ALL
    Asset is linked to the Ticket
```

### 5. Available Actions Per State

Each state has an `Available actions` section. Workflow-level defaults may be inherited and then
overridden per state. Initial action keys include:

- reply to customer and add internal note,
- assign to self, assign another technician, assign queue/team, and remove assignment,
- add planned cost, add actual/manual cost, and register time,
- create, edit, send, or mark acceptance of a quote,
- reserve, pick, return, or request ordering of an item,
- request senior review and review as senior,
- create or complete tasks,
- change workflow/internal escalation,
- resolve and close with an outcome.

An action policy can be:

- inherited,
- hidden,
- visible but administratively blocked,
- available,
- conditional through a requirement tree.

A conditional action becomes available only when its requirements pass. When the user otherwise
has permission, the recommended Ticket UI keeps the button visible but disabled, explains every
missing requirement, and offers safe shortcuts such as `Link Asset`, `Create Quote`, `Request
senior review`, or `Escalate Ticket`. Administrators may explicitly hide an action when it should
not be discoverable in that state.

The authorization pipeline is always:

1. authenticated user and organization/work-context scope,
2. ordinary permission or policy,
3. domain-owned invariant and guard,
4. workflow action policy,
5. current requirement evaluation,
6. idempotent domain command.

The UI consumes the same decision object used by controllers and actions. A hidden or disabled
button is never the enforcement boundary.

### 6. Internal Workflow Escalation

`Escalate Ticket` is a dedicated manual Ticket action. To avoid confusion, provider handoff keeps a
different label such as `Escalate to provider`.

An internal escalation path defines:

- source workflow version and state,
- availability requirements,
- whether escalation is optional or required,
- one or more allowed target workflows,
- target published version and entry state,
- target queue and Ticket type behavior,
- target owner eligibility and assignment strategy,
- fields or evidence that must be preserved or revalidated,
- actions that are blocked until a required escalation occurs.

Example: the Default workflow detects planned sales items. The administrator may expose
`Escalate Ticket` to the Sales workflow while still allowing the technician to complete the Ticket
in Default. Alternatively, the administrator may make the escalation required and block adding
costs, assignment to another technician, resolve, or close until the technician explicitly
escalates.

The escalation runs in one transaction, locks the Ticket, re-evaluates requirements, changes the
workflow/version/state, applies queue/type policy, evaluates the current owner, and records a
before/after audit event. It preserves messages, attachments, Asset link, planned lines, quotes,
time entries, cost entries, Tasks, and history.

Loop prevention rejects an escalation back to the same workflow version/state, detects repeated
cycles in configured paths, and requires a separate override permission plus reason for an
administrator to repeat a previously completed cycle.

### 7. Assignment And Eligible Owners

Workflow assignment policy may narrow eligible owners by:

- named users,
- roles or groups,
- required permissions or capabilities,
- existing Ticket assignment profile criteria,
- named inclusion or exclusion overrides.

On state entry or escalation, the policy may keep the owner when eligible, assign a fixed eligible
user, ask the existing assignment engine to select from the eligible pool, require a manual
selection, or leave the Ticket unassigned in the configured queue.

Actions that negotiate, send quotes, approve costs, review junior work, or assign another
technician may require an eligible current owner in addition to user permission. Workflow never
makes an unqualified user eligible. If no eligible owner exists, the Ticket remains safely
unassigned in the target queue, shows an operational warning, notifies the configured responsible
group, and keeps protected actions blocked.

This supports policies such as preventing a trainee from assigning an incomplete Ticket to a
senior until an Asset, category, and diagnostic note are present, and ensuring all Sales workflow
Tickets are owned by a salesperson or senior technician allowed to negotiate with the customer.

### 8. Senior Review / Four-Eyes Gate

A senior review is a first-class workflow checkpoint, not a message read receipt. The junior sees
`Request senior review`; eligible reviewers see `Review Ticket`. A review records:

- Ticket, workflow version, state, and gate key,
- requestor and optional assigned reviewer,
- reviewed evidence fingerprint and relevant record/version references,
- reviewer, decision, comment, and timestamp,
- separation-of-duty result,
- later invalidation reason and timestamp when applicable.

The reviewer must have the dedicated review permission and satisfy the gate's senior eligibility
policy. When separation of duties is enabled, the reviewer cannot be the requestor, current junior
owner, or author of the protected commercial decision.

The reviewer can approve or send the Ticket back with a required comment. Only an active approval
for the current evidence fingerprint satisfies the gate. Material changes selected by the workflow
definition, such as changing Asset, planned lines, quote version, amount, contract, required fields,
or approval evidence, invalidate the old review and explain why another review is needed.

The gate may protect one transition or action rather than freezing the entire Ticket. A junior may
still add the missing information and request a new review while the protected next step remains
blocked.

### 9. Customer Response, Signature, And Approval Evidence

A generic inbound message does not automatically approve cost. Workflow may require a customer
response after a particular request, but commercial approval must be bound to the current quote or
approval request.

When a customer accepts through the secure quote page or Customer Portal, Sales records the
structured acceptance. When the customer replies by email, an authorized technician may choose
`Mark as quote acceptance` on that specific inbound Ticket message. The action shows the exact
quote version, amount, customer, and message, requires confirmation, and calls the same Sales-owned
acceptance service used by public and portal acceptance. It records method `email_confirmed_by_staff`,
the inbound message, technician, quote version, accepted snapshot, and time.

An uploaded signature satisfies a requirement only when an authorized user classifies the
attachment as workflow evidence and records its evidence type, signer/customer, applicable request
or quote version, date, file hash, actor, and comment. Replacing or deleting the underlying file
invalidates the evidence.

### 10. Ticket-Origin Sales Quotes

Sales remains the owner of every quote. Creating a quote from a Ticket automatically creates or
reuses a linked Sales Opportunity with type `ticket_service_quote`; the Opportunity owns the Quote,
and the Ticket is the operational source/context. The Opportunity and Quote remain visible and
reportable in Sales while the Ticket can operate the allowed quote actions.

The Ticket uses the same shared quote editor component and calculation/version services as Sales.
It does not copy the form or calculation engine. Ticket buttons follow the lifecycle:

- `Create Quote`,
- `Edit Draft`,
- `Send For Approval`,
- `Waiting For Customer`,
- `Accepted`, `Declined`, or `Expired`.

Sending from a Ticket creates an immutable quote version, renders the PDF from that same version,
stores the PDF snapshot, adds a public Ticket reply, attaches the PDF, and includes the secure
acceptance link. Delivery uses the existing Ticket correspondence rules so the conversation stays
on the Ticket. A retry must not create a second quote version or duplicate acceptance request.

Acceptance through the link, portal, or classified inbound reply calls one shared Sales acceptance
service. It marks the version and Quote accepted, marks the linked Opportunity won, adds Sales and
Ticket audit events, and immediately re-evaluates the Ticket workflow. Decline, expiry, or loss may
make a configured `Close - customer did not approve` action available. That closure records a
structured outcome and does not generate an Economy order.

For a Ticket-origin quote containing a line with downstream type `implementation`, acceptance
unlocks the configured `Start implementation` transition in the same Ticket. It does not silently
switch the Ticket to another workflow. Sales-origin implementation Ticket creation remains part of
the broader Sales CPQ RFC.

### 11. Planned Cost, Storage, Implementation, And Economy

Adding a possible router or estimated setup time before approval creates a planned Ticket scope
line, not a `TicketCostEntry`, Storage reservation, pick, purchase order, or Economy order line.
Planned lines support Storage items, services, time estimates, packages, and custom lines and keep
source IDs plus price snapshots.

Creating a Ticket quote imports selected planned lines into a draft Sales quote version. Once sent,
the version is immutable. Changes produce a new version and require a new acceptance. The accepted
version becomes the approved commercial ceiling and scope.

Workflow may block reserving, picking, or ordering until the current quote, contract, signature,
or other configured approval evidence passes. After approval, explicit actions convert approved
lines idempotently into the domain-owned records:

- reserve available or orderable stock through Storage,
- pick only stock physically available under Storage guards,
- create a draft purchase need for missing equipment,
- register actual time and manual costs through current Ticket actions.

Implementation states may require tasks/checklists, handled item lines, Asset updates, registered
time, and senior quality review before resolution.

At completion, Economy uses actual picked items, actual time, and actual approved manual costs. The
accepted quote is the authorization frame, not the invoice source. If actual quantity, price, or
time exceeds the configured accepted tolerance, the workflow blocks completion or billing and
requires a revised quote or explicit reapproval. Existing order generation remains Economy-owned
and must be idempotent.

### 12. Ticket View And Admin Experience

The workflow editor uses the same interaction language as Signal Rules:

- a top-to-bottom state list that starts with one room and places `+ Add next step` directly below
  the room it follows,
- compact state cards where the operational name and mapped reporting status are configured inside
  the step,
- outgoing `Next-step buttons` inside their source step, with targets allowed to be later or earlier
  steps so rejection and rework paths are explicit,
- deferred Livewire field bindings and local dependent selectors so ordinary typing and selection
  do not redraw the editor; explicit structure changes rerender only when needed and keep their
  source step open,
- `Available actions`, `Requirements`, `Escalation paths`, and `Assignment & ownership` panels,
- `All` / `At least one` group selectors,
- readable summaries before publish,
- validation errors attached to the affected card,
- a simulation panel that evaluates a selected Ticket without changing it.

The Workflow definition API uses the same unrestricted source/target transition model as the editor;
it rejects only missing states and a transition back to the exact same state.

Ticket view shows the workflow as a compact left-to-right progress sequence in the Ticket header.
The current step is highlighted, visited and available steps are distinguishable, and chevrons
separate the steps. Hover or keyboard focus shows the evaluated satisfied and missing requirements
for that step. The workflow-decisions API returns the same ordered step data.

Available next steps and escalation choices stay in a compact right-panel action surface. Planned
scope, quote status, senior review, and customer evidence remain task-focused Ticket tools and are
not wrapped in a large Workflow card. Buttons remain grouped by user task instead of exposing every
workflow detail at once.

## Impact Analysis

- **Ticket:** workflow models/runtime/editor, Ticket current state, action registry and guard,
  planned scope, evidence, review checkpoints, escalation, assignment, state history, close
  outcomes, routes, controllers, views, API, events, and tests.
- **Sales:** Ticket-linked Opportunity type, shared quote editor, shared acceptance service,
  immutable PDF snapshot, Ticket correspondence integration, quote status events, and tests.
- **Asset:** read-only requirement facts and re-evaluation events; no transfer of Asset ownership.
- **Commercial:** accepted/valid contract and terms facts; no duplicate contract model.
- **Email:** inbound message classification and Ticket-thread delivery of quote snapshot/link.
- **Storage:** approved-line conversion into reservations, picking, returns, and draft purchase
  needs through existing guards.
- **Economy:** actual-cost readiness facts and guarded, idempotent order generation.
- **Task/Knowledge:** requirement facts and corrective-action links.
- **UserManagement:** permissions, roles/groups, senior eligibility, and assignment facts.
- **Signal/Notification:** optional domain events and operational notifications only; Signal does
  not become the synchronous enforcement engine.
- **Permissions:** new granular permissions are expected for workflow publish/migrate, internal
  escalation, review request, senior review, evidence classification, manual quote acceptance,
  and administrative override. Existing Sales, Storage, Economy, Ticket, and assignment permissions
  remain mandatory.
- **Routes/API:** all domain routes remain in their singular module route files. Read and mutation
  endpoints return the same structured action decision and blocked reasons as the UI.
- **Queues:** quote email/PDF delivery and notifications may use queues. State changes and
  acceptance must commit synchronously before jobs are dispatched after commit.
- **Security:** requirement definitions use whitelisted provider schemas, never executable code or
  arbitrary model queries. Customer-facing evidence is scoped to the Ticket client and current
  work context. Manual approvals and overrides require explicit reason and audit.
- **Performance:** Ticket view may evaluate many facts. The evaluator must batch provider reads per
  Ticket and request, avoid N+1 queries, and invalidate short-lived decision caches on relevant
  events.
- **UI:** Admin Workflow editor, Ticket view, Sales quote modal, Ticket replies, assignment,
  Storage actions, and close/resolve actions change materially.
- **Documentation:** Ticket, Sales, Storage, Economy, Commercial, Asset, Email, permissions, and
  admin Knowledge documentation require updates.

## Data And Migration Plan

Implementation uses additive migrations first. Exact names are confirmed in the ADR and Feature
Slices, but the target data includes:

- published workflow definition/version records,
- first-class state and transition references independent of global Ticket statuses,
- Ticket current workflow version and current state,
- versioned requirement/action/escalation/assignment definitions,
- Ticket state and workflow-change history,
- senior review request/decision/invalidation records,
- classified workflow evidence records linked to attachments/messages/quotes/contracts,
- planned Ticket scope lines and their downstream conversion references,
- Ticket-to-Sales Opportunity/Quote context links,
- structured Ticket close outcome and commercial no-sale evidence where needed.

Upgrade order:

1. Add new nullable tables/columns, provider contracts, and compatibility readers.
2. Convert every existing workflow into published compatibility version 1.
3. Convert each existing status-bound state into a first-class state with the same mapped status.
4. Convert the four legacy boolean requirements into an `ALL` requirement group.
5. Backfill each Ticket's current state from its workflow and current global status. Tickets with
   no unique mapping are reported and remain on the compatibility runtime until resolved.
6. Validate counts and unresolved mappings before enabling v3 per workflow.
7. Enable the new editor and runtime behind an installation setting only after the compatibility
   report is clean.
8. Keep legacy columns readable through at least one release. Remove them only through a later
   approved cleanup migration.

Existing active Tickets remain pinned to compatibility version 1. No migration changes their
workflow, queue, type, owner, status, reservation, quote, or billing state without an explicit
administrator action.

Rollback may disable the v3 runtime and return compatibility workflows to the legacy reader while
new columns remain in place. Once Tickets use workflow-specific states, planned scope, or review
evidence, destructive schema rollback is not automatic; restoration requires the documented
forward fix or data-aware downgrade command.

## Testing Plan

- Unit tests for nested `all`/`any` evaluation, operator validation, provider schema versions,
  batched facts, provider failure, and readable blocked reasons.
- Migration tests for existing workflows, four legacy requirements, open Ticket state mapping,
  ambiguous mapping reports, and retry-safe backfill.
- Feature tests proving workflow states can share a coarse Ticket status.
- Feature tests for draft validation, publish immutability, version pinning, simulation, and explicit
  active-Ticket migration.
- Permission and regression tests for every action route, direct request, API route, bulk action,
  background trigger, and hidden/blocked UI state.
- Tests proving workflow cannot grant missing Sales, Storage, Economy, assignment, review, or
  Ticket permissions.
- Tests for optional and required manual escalation, preserved data, queue/type/state changes,
  owner eligibility, no eligible owner, concurrency locking, audit, and loop prevention.
- Tests for assignment prerequisites such as Asset/category/note and for scoped assignment-engine
  selection.
- Senior-review tests for eligibility, separation of duties, approve/send-back, evidence
  fingerprint, material-change invalidation, and unrelated-change stability.
- Customer-evidence tests proving old messages and normal attachments cannot satisfy current
  commercial approval.
- Sales tests for linked Opportunity creation, shared editor behavior, immutable version/PDF,
  Ticket reply attachment/link, public/portal/email acceptance, win state, retries, and stale-version
  rejection.
- Storage tests proving planned lines do not reserve or bill and unapproved lines cannot be
  reserved, picked, or ordered.
- Economy tests for actual delivered lines, quote tolerance/reapproval, accepted completion,
  customer-declined close, and idempotent order generation.
- Cross-module organization/work-context tests to prevent evidence or records from another client
  satisfying a requirement.
- Narrow module suites after every Feature Slice, then the broad Laravel test suite and
  authenticated Dev HTTP smoke tests before release handoff.
- Manual Bootstrap UI checks on desktop and mobile for the editor, Ticket action explanations,
  quote flow, escalation, senior review, implementation, and closure outcomes.

Automated tests do not complete the human-review gate. Each implemented Feature Slice receives or
updates a stable entry in `docs/human-review.md` until a named reviewer explicitly confirms it.

## Documentation Plan

- Update Ticket module README and Knowledge articles for lifecycle workflows, admin settings,
  rules/assignment, email communication, Storage cost reservations, and technical operations.
- Add administrator documentation for published workflow versions, requirements, action policy,
  simulation, migration, and troubleshooting unavailable providers.
- Add technician documentation for blocked actions, internal escalation, quote approval, evidence,
  senior review, implementation, and close outcomes.
- Update Sales documentation for Ticket-origin Opportunities/quotes and the shared acceptance
  service.
- Update Asset, Commercial, Storage, Economy, Email, Task, UserManagement, Signal, and Notification
  documentation where their providers or actions participate.
- Document new permissions, queue/scheduler requirements, deploy commands, rollback limitations,
  and operational alerts.
- Keep `docs/TODO.md`, the RFC index, Feature Slices, the required ADR, and
  `docs/human-review.md` aligned throughout implementation.
- After implementation is verified, add a public-safe summary to the Nexum website handoff file
  with an explicit do-not-publish note until human review is complete.

## Feature Slice Plan

Implementation must not be attempted as one broad change. After approval, create and approve the
exact slice document before starting each slice:

1. **Core definition and evaluator:** ADR, published versions, workflow-specific states, grouped
   requirement schema/evaluator, compatibility migration, Signal-style editor foundation, and
   simulation.
2. **Action policy and enforcement:** shared action registry/decision, state action UI, server-side
   guards, transition history, and coarse status mapping.
3. **Internal escalation and assignment:** manual optional/required paths, queue/type/state change,
   eligible owner pools, assignment engine scoping, loop prevention, and audit.
4. **Senior review and customer evidence:** four-eyes checkpoints, invalidation, customer-response
   binding, classified signatures, and manual evidence audit.
5. **Cross-domain facts:** Asset, Commercial, Task, Knowledge, Email, user, and Ticket provider
   coverage with organization-scope tests.
6. **Ticket-origin Sales quote:** linked service Opportunity, shared editor, planned scope import,
   immutable PDF/Ticket reply/link, shared acceptance, and win/loss outcomes.
7. **Approved fulfilment and implementation:** planned-line conversion, Storage gates,
   implementation state requirements, actual-versus-approved tolerance, and Economy completion.
8. **Migration and release hardening:** active-Ticket migration UI, full documentation, broad tests,
   Dev deployment checks, performance review, and human-review completion.

Later slices may begin only when the preceding contracts they consume are stable and their human
review defects are resolved or explicitly deferred.

## Open Questions

No unresolved product question blocks RFC review. The implementation details for physical table
names, provider interfaces, and service boundaries must be recorded in the required ADR and the
first Feature Slice without changing the behavior approved here.

## Approval

Approved by Svein Tore on 2026-07-17 in the Codex product conversation. Approval explicitly
includes complete implementation, API parity for every view action, automated testing, Knowledge
documentation, and BookStack synchronization.
