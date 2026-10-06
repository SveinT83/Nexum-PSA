# RFC: Task Templates And Scheduled Generation

Status: Approved
Implementation: Done On Dev (all six Feature Slices, 2026-09-03)
Date: 2026-09-03
Owner: Svein / Codex
Related Issue: GitHub #227

## Context

Nexum already has a Task domain with template-group, template-item, template-checklist,
template-dependency, and recurring-template tables. The ordinary Task workflow also supports
nested tasks, explicit dependencies, checklists, assignment, due and scheduled times, estimates,
client/site context, activity, and time registration.

The current template foundation has no management UI, no authoritative application action, and no
Task scheduler. Signal Rules and RMM Alert Rules can create one Task directly, while Ticket Rules do
not yet have a template-backed Task-group action. This duplicates configuration and prevents an
administrator from maintaining one reusable definition for routine work.

GitHub Issue #227 initially describes recurring sysadmin duties. The required product direction is
broader: one simple Task Template system must support manual use, scheduled generation, and
domain-owned automation such as Signal Rules, Ticket Rules, and RMM Alert Rules.

This is Level 3 work because it adds background generation, database state, permissions, UI, and
cross-module actions.

## Goals

- Let an authorized technician create and directly edit a Task template without publishing,
  approval, or version-management steps.
- Let one template contain either one Task or a group of Tasks.
- Preserve nested parent/child structure, checklists, and explicit dependencies between generated
  Tasks.
- Apply a template manually to a supported owner, starting with Ticket and Client context.
- Generate new Tasks automatically from simple recurring schedules.
- Let Signal Rules, Ticket Rules, and RMM Alert Rules apply the same templates through one
  Task-owned application action.
- Copy template values into new Tasks so later template edits affect only future generations.
- Prevent duplicate scheduled or automated generation through durable idempotency.
- Keep enough generation evidence to show which template, trigger, owner, and Tasks participated in
  a run without retaining restorable template versions.

## Non-Goals

- No Draft, Published, review, approval, or release workflow for Task templates.
- No immutable template versions, version selection, rollback, comparison, or migration between
  template versions.
- No automatic rewriting, migration, deletion, or reconciliation of existing Tasks when a template
  changes.
- No generic shared automation engine. Signal Rules, Ticket Rules, and RMM Alert Rules keep their
  existing domain ownership and audit models.
- No replacement of Task statuses, Ticket queues/priorities, taxonomy, Task time registration, or
  Ticket billing rules.
- No workflow designer, approval steps, customer-visible activity, or automatic completion of
  Tasks.
- No automatic catch-up storm that generates every missed interval after extended downtime.
- No round-robin assignment in the first implementation. Templates and schedules may use a
  specific active assignee or remain unassigned.
- No external API or MCP expansion in the first implementation unless separately approved.

## Current Behavior

- `TaskTemplateGroup`, `TaskTemplateItem`, `TaskTemplateChecklistItem`,
  `TaskTemplateDependency`, and `TaskRecurringTemplate` models/tables exist.
- Template groups and recurring templates have no admin CRUD routes or UI.
- No Task action expands a template group into Tasks.
- No scheduled Task-template command is registered.
- `StoreTask` is the authoritative ordinary Task creation action.
- Signal Rules have a `task_follow_up` action that constructs one Task directly.
- RMM Alert Rules have a `create_task` action that creates or reuses one Task directly.
- Ticket Rules do not expose an action that applies a Task template group.
- Ticket recurrence is a separate Ticket-owned system and must not be reused as the Task scheduler.

## Proposed Change

### One Simple Template Concept

The UI calls every reusable definition a **Task template**. The existing template group remains the
storage root:

- a template containing one root item behaves as a single-Task template;
- a template containing several items behaves as a Task group;
- child items define nested Tasks;
- template dependency rows define `blocks_start` or `blocks_completion` relationships;
- checklist rows remain lightweight steps on one generated Task.

Users do not need to choose between separate “template” and “template group” record types.

### Direct Editing Semantics

An authorized user edits the current template directly and saves it immediately.

- There is no publishing or approval state.
- The template's ordinary `updated_at` and updater metadata show who last changed it and when.
- The next manual, scheduled, or rule-triggered application uses the current saved definition.
- Existing Tasks are independent snapshots and never change because the template changes.
- Generated Tasks retain `template_group_id` and `template_item_id` provenance.
- Generation evidence may retain template name and `updated_at` observed at execution, but it must
  not store a restorable historical definition or expose version-management behavior.

An inactive template cannot generate new Tasks. A referenced or previously used template is
deactivated rather than destructively deleted. An unused, unreferenced template may be deleted by
an authorized user.

### Template Contents

Each template item may define:

- title and description;
- optional parent item;
- status, queue, priority, category, and tags;
- optional specific assignee;
- estimated minutes;
- whether the Task blocks owner completion;
- checklist items;
- required dependencies on other items in the same template;
- relative due, scheduled-start, and scheduled-end offsets.

The editor validates that parent and dependency graphs contain no cycles, references belong to the
same template, required catalog rows remain active, and scheduled offsets are coherent.

Titles and descriptions may use a small documented set of context placeholders such as client
name, Ticket key, Ticket subject, Asset name, and generation date. Unsupported or unavailable
required placeholders fail the preview/application instead of being silently removed. The first
implementation must not provide arbitrary code, Blade, or unrestricted property access.

### Task-Owned Application Action

Add one Task-owned action, conceptually `ApplyTaskTemplate`, used by every caller.

The action:

1. authorizes or accepts a previously authorized protected automation actor;
2. locks and validates the active template and requested owner context;
3. validates the complete item and dependency graph;
4. resolves allowed placeholders and caller-supplied overrides;
5. creates a durable generation run;
6. creates all Tasks through Task-owned creation behavior;
7. maps template parent relationships to generated Task parent IDs;
8. maps template dependencies to generated Task dependencies;
9. copies checklists, tags, routing, estimates, dates, ownership, and source metadata;
10. records the generated Task IDs and completes the run in one transaction.

If validation or Task creation fails, no partial Task group is retained. A completed run is not
automatically replayed.

Manual UI may show a compact preview of the Tasks, relationships, assignments, and dates before the
user confirms creation. This is a creation preview, not a template approval workflow.

### Generation Runs And Idempotency

Add a Task-owned generation-run record containing:

- template ID and the observed template name/update timestamp;
- trigger type: manual, schedule, Signal Rule, Ticket Rule, RMM Alert Rule, or future internal
  caller;
- bounded source identity and source record ID where permitted;
- owner type/ID plus denormalized client/site/work-context IDs;
- actor ID or protected automation-actor identity;
- unique idempotency key;
- scheduled occurrence time when applicable;
- status, generated Task count, timing, and bounded sanitized failure reason.

Generated Tasks reference the run. The Tasks themselves preserve the actual generated titles,
descriptions, fields, checklists, hierarchy, and dependencies. The run is operational/audit
evidence, not a template version.

Each caller supplies a stable idempotency namespace. A database uniqueness constraint prevents the
same scheduled occurrence or rule action from generating the group twice.

### Recurring Schedules

The existing `task_recurring_templates` concept becomes the Task-owned schedule configuration. A
schedule references one active template and defines:

- name and active state;
- owner/target scope;
- daily, weekly, monthly, or quarterly frequency with a simple human-readable configuration;
- timezone;
- next and last run timestamps;
- optional due/scheduled-date overrides;
- optional specific assignee override;
- automatic generation or a manual **Generate now** action.

Target scope supports one explicit owner first and may support a resolved Client set such as all
active Clients, selected Clients, or Clients with a selected tag when implemented in a dedicated
slice. The schedule preview shows the resolved target count and next run before save.

A Task-owned due-schedule command runs every minute through Laravel's scheduler. It locks due rows,
creates one occurrence identity per schedule/target/due time, and calls the shared application
action. It advances `next_run_at` only through the claimed occurrence flow.

After downtime, an overdue schedule creates at most one current catch-up run per target and then
advances to the next future occurrence. It does not create an unbounded backlog of historical
Tasks. The Admin UI shows the missed/catch-up result.

The feature is not operationally healthy until an external runner invokes
`php artisan schedule:run` every minute in the target installation. Laravel `schedule:list` alone
is insufficient deployment evidence.

Task Template schedules target an active User or Client selected through a searchable lookup. They
do not target a future Ticket by numeric ID. Ticket Rules remain the event-driven option for
Tickets that do not yet exist.

### Recurring Ticket Templates

A recurring Ticket may select one active Task Template in its Schedule card. Ticket keeps
ownership of recurrence and creates the future Ticket occurrence first. It then calls the
Task-owned template application boundary so that occurrence receives a fresh Task group. The
parent recurring Ticket does not receive or clone generated Tasks, and later Task Template edits
affect only future Ticket occurrences.

### Manual Ticket And Client Use

Ticket and Client surfaces may expose **Add tasks from template** to users who can both view the
owner and apply Task templates.

- The selected Ticket or Client is passed as the Task owner/context.
- The preview lists the Tasks and relationships that will be created.
- Allowed overrides remain intentionally small: assignee, due-date anchor, and optional title
  context.
- Successful creation links directly to the generated Tasks.

Other owner domains may adopt the same action later without duplicating Task persistence.

### Signal Rules

Signal Rules keep their existing matching, ordering, execution, and audit behavior. Add a
template-backed Task action or extend `task_follow_up` with a template selector while preserving
existing rules.

The Signal action passes only authorized Signal context and a stable action idempotency key to the
Task-owned application action. Signal does not create template children or dependencies itself.

### Ticket Rules

Ticket Rules gain a typed action such as **Add tasks from template**. The current Ticket is the
owner. The action participates in the existing published Ticket Rule definition, preview,
authority, loop-budget, branch-failure, audit, and retry constraints.

Task-template mutability does not introduce a second Ticket Rule publishing model. The rule stores
the template ID and resolves the current active template only when the action executes. A missing
or inactive template fails that action closed and records the reason.

### RMM Alert Rules

RMM Alert Rules may optionally select a Task template for `create_task`. Existing single-Task rule
definitions remain compatible.

RMM continues to own alert occurrence, work-item reuse, customer-boundary checks, and action audit.
Task owns group generation. A template-backed action uses the existing immutable RMM occurrence
context and stable action identity. Reuse semantics must be explicit: an existing unresolved work
link prevents a duplicate group for the same occurrence/action; it must not guess that an unrelated
Task satisfies the whole template.

### Permissions

Add narrowly scoped Task permissions:

- `task.template.view`;
- `task.template.manage`;
- `task.template.apply`;
- `task.schedule.manage`;
- `task.schedule.run` for manual generation where separate control is required.

Protected automation actors receive only the Task permissions needed by their existing rule domain.
Rule authors are audit metadata and are not silently impersonated when the existing rule engine uses
a protected runtime actor.

### UI Direction

Use Bootstrap and shared Nexum components. Keep the ordinary flow compact:

- a template list with name, Task count, active state, last changed, and schedule/rule usage;
- one create/edit page with general fields followed by an ordered Task-item editor;
- nested indentation for parent/child Tasks;
- simple dependency selectors limited to items in the same template;
- checklist editing within each Task item;
- a schedule panel or separate schedule page with plain-language frequency fields;
- generation history showing source, owner, outcome, and created Tasks.

Do not expose technical JSON, idempotency keys, internal actor IDs, template “releases,” or controls
for unimplemented behavior in the ordinary UI.

## Impact Analysis

### Task

- Owns template/schedule CRUD, graph validation, template application, generation runs, scheduler,
  permissions, UI, documentation, and tests.
- Continues using Task-owned status, activity, dependency, checklist, time, and completion rules.

### Ticket

- Adds manual template application from a Ticket.
- Adds a typed Ticket Rule action through the existing rule authority and audit boundaries.
- Lets a recurring Ticket reference an active Task Template; each generated Ticket occurrence
  calls the Task-owned application boundary with a stable occurrence key.
- Ticket billing remains authoritative for Ticket-owned Task time and is not changed by templates.

### Client

- Adds manual template application from a Client when permissions allow.
- Supplies customer/site/work-context information without owning Task persistence.

### Signal

- Adds a template-backed Task action while preserving current direct `task_follow_up` compatibility.
- Keeps Signal rule matching and execution audit authoritative.

### Integration / RMM

- May select a template in the existing RMM `create_task` action.
- Keeps RMM occurrence, fingerprint reuse, target locking, and customer-boundary checks authoritative.

### Permissions And Roles

- Adds Task template/schedule abilities and role defaults through the normal permission seeding and
  migration process.

### Queues And Scheduler

- Adds a Task schedule planner/runner and requires the installation's external every-minute Laravel
  scheduler runner.
- Generation may run synchronously inside a claimed schedule occurrence or through a durable queued
  job, but the selected slice must preserve database idempotency and observable failure state.

### Security And Privacy

- Template placeholders use an allowlisted context projection.
- Cross-client owner/context mismatches fail closed.
- Execution errors and audit evidence exclude secrets, raw provider payloads, unrestricted Signal
  payloads, and customer content not required for Task creation.

### Documentation

- Update Task domain and Knowledge documentation.
- Update Signal Rules, Ticket Rules, RMM Alert Rules, Ticket, and Client Knowledge where the action
  becomes available.
- Document the external scheduler-runner deployment requirement.

## Data And Migration Plan

Use additive migrations and preserve current Task data.

Expected changes:

- add updater metadata and any missing date-offset/tag fields to template tables;
- add a Task-owned generation-runs table with a unique idempotency key;
- add nullable generation-run provenance to Tasks;
- extend recurring-template schedule fields only where the existing interval/config/next-run model
  is insufficient;
- add permission catalog and role-default changes;
- add indexes for due active schedules and generation history.
- add a nullable Task Template reference to Ticket schedules. Application validation and
  the active-template relationship prevent an unavailable template from generating future Tasks;
  Tickets, schedules, generated Tasks, and generation evidence remain intact.

No migration updates existing Tasks from current template definitions. Existing template rows remain
directly editable and become available through the new UI after migration. Rollback must not delete
generated Tasks. A destructive schema rollback is refused or documented as unsafe when generation
evidence exists.

## Delivery Slices After Approval

The approved RFC should be implemented as bounded Feature Slices:

1. Template data completion, graph validation, application action, generation runs, and tests.
2. Simple Task Template admin UI, permissions, direct editing, usage guards, and Knowledge docs.
3. Recurring schedules, idempotent due processing, generation history, scheduler evidence, and
   operational documentation.
4. Manual Ticket and Client template application with preview and permission tests.
5. Signal Rule, Ticket Rule, and RMM Alert Rule template actions with compatibility, audit,
   idempotency, and cross-module regression tests.
6. Searchable User/Client schedule targets and Task Template application for recurring Ticket
   occurrences.

Each slice must be independently testable and must not expose UI controls before its behavior is
implemented.

## Testing Plan

- Template CRUD, permissions, direct save, active/inactive behavior, and referenced-delete guards.
- Single-item and multi-item template application.
- Nested Task creation, checklist copying, tag/default copying, and dependency mapping.
- Parent/dependency cycle rejection and inactive-reference rejection.
- Placeholder allowlist, missing context, and cross-client fail-closed behavior.
- Transaction rollback with no partial Task group.
- Generated Tasks remaining unchanged after template edits.
- Next applications using the updated template immediately.
- Manual Ticket and Client previews/application permissions.
- Schedule calculation across timezone/DST, due locking, duplicate dispatch, overlapping runners,
  catch-up limits, inactive templates, failed runs, and **Generate now**.
- Signal Rule, Ticket Rule, and RMM Alert Rule compatibility, authorization, audit, idempotency, and
  action-failure behavior.
- Searchable User/Client owner selection, rejection of Ticket schedule owners, recurring Ticket
  template persistence, and duplicate-safe Task generation per Ticket occurrence.
- Existing Task time, completion, Ticket billing, Signal, Ticket Rule, RMM, and scheduler regression
  suites.
- Authenticated Bootstrap desktop and narrow-width browser review.
- Dev migration, scheduler registration, queue behavior where used, and HTTP smoke checks.

## Documentation Plan

- Update `app/Modules/Task/Docs/task-domain-plan.md`.
- Replace the “planned” wording in Task template Knowledge documentation when each slice ships.
- Add operator documentation for recurring schedules and external `schedule:run` verification.
- Update Signal, Ticket Rule, RMM Alert Rule, Ticket, and Client Knowledge for delivered actions.
- Register a human-review checklist before handing off the first Level 3 implementation slice.
- Reconcile GitHub Issue #227, `docs/TODO.md`, Feature Slice status, and human review in each work
  session.

## Open Questions

None for the RFC draft. The agreed defaults are:

- templates are directly editable and not versioned;
- no template approval or publishing flow exists;
- current saved content is used for future runs;
- existing Tasks never migrate when a template changes;
- overdue schedules produce at most one catch-up run per target before advancing;
- the first delivery uses specific/unassigned assignment rather than round-robin.

## Approval

Approved by Svein in conversation on 2026-09-03. The approval retains the simple mutable-template
direction: no template versions, publishing, approval, rollback, or migration of existing Tasks.
Svein explicitly approved the follow-up behavior on 2026-09-03: no numeric owner IDs in the UI,
Task schedules target searchable Users or Clients rather than future Tickets, and recurring Ticket
templates may select a Task Template for every generated Ticket occurrence.
