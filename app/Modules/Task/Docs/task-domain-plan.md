# Task Domain Plan

This document is the implementation plan and decision log for the Task domain.
Tasks are a shared work-management capability used by tickets, assets, clients,
storage, sales, and other modules. The Task module owns task behavior, while
other domains may attach tasks to their own records.

## Goals

- Provide one internal task system that every domain can use.
- Keep tasks lighter than tickets, but avoid hardcoding decisions that would
  prevent later approvals, customer-facing tasks, or workflow automation.
- Support repeatable work through templates with nested tasks and checklist
  items.
- Support time tracking so small tasks can be completed quickly while still
  producing useful operational reporting.
- Use existing shared concepts where they already exist, especially queues,
  priorities, categories, and tags.
- Keep module ownership clean: Task routes, controllers, views, actions, models,
  and documentation live under `app/Modules/Task`.

## Ownership Model

Every task has exactly one primary owner:

- A standalone/personal task is owned by the creator user.
- A ticket task is owned by the ticket.
- An asset task is owned by the asset.
- Other domains may become task owners later through the same polymorphic
  fields.

The primary owner is stored as `owner_type` and `owner_id`. Additional related
records are stored separately as task relations so a task can be connected to
more context without making ownership ambiguous.

Task responsibility uses these fields:

- `created_by`: the user who created the task.
- `assigned_to`: the user expected to do the work.
- `owner_type` / `owner_id`: the record or user that owns the task.

No separate `responsible_user_id` is planned for beta; ownership and assignment
are enough.

## Statuses, Queues, And Priorities

Task statuses are Task-owned and admin-managed through `task_statuses`. Status
rows include system flags so labels can be customized without breaking behavior:

- `is_open`
- `is_in_progress`
- `is_blocked`
- `is_done`
- `is_cancelled`

Queues and priorities are intentionally reused:

- `queue_id` points to `ticket_queues` for beta, but UI labels it as `Queue`.
- `priority_id` points to `ticket_priorities` for beta, but UI labels it as
  `Priority`.

The field names stay generic so these concepts can be moved to a shared domain
later without changing Task behavior or UI language.

## Nesting, Dependencies, And Checklists

Tasks support nesting from day one:

- `parent_id` links a child task to its parent task.
- A parent task can be blocked by unfinished child tasks.
- A child task can also be blocked by parent or sibling tasks when needed.

Dependencies are explicit records, not inferred only from nesting:

- `task_id`: the task that is blocked.
- `depends_on_task_id`: the task that must happen first.
- `dependency_type`: `blocks_start` or `blocks_completion`.
- `is_required`: whether the dependency is enforced.

Checklists are separate from subtasks. Checklist items are for small steps that
do not need ownership, time, queue, status, or separate discussion.

## Time Tracking

Tasks have their own time entries because ticket time entries are tied to ticket
billing rules and ticket-specific reporting.

Each task may have:

- `estimated_minutes`
- manual task time entries
- automatic estimated time when completing a task

Completion rule:

- If actual time exists, completion uses the recorded actual time.
- If no actual time exists and `estimated_minutes` is set, completing the task
  creates a task time entry with `source_type = estimated`.
- If no actual time or estimate exists, the task may still be completed, but the
  UI should make the missing time visible.

## Activity

Task activity is internal in beta. The activity stream records:

- status changes
- assignment changes
- queue/priority changes
- notes
- template application events
- dependency and completion events

Activity rows include a `visibility` field so public/customer activity can be
added later without redesigning the table.

## Templates And Recurrence

Templates are part of the first implementation because repeatable work is a core
reason for Tasks.

Template groups define a reusable task package. Template items define the nested
tasks, defaults, dependencies, and checklist items inside that package.

Template defaults may include:

- title and description
- queue and priority
- status
- estimated minutes
- default assignment
- tags and categories
- child task structure
- dependency rules
- checklist items

Template application should create a previewable task tree before records are
saved when the UI matures. The beta implementation may start with direct
application, but the data model must support a predictable preview flow.

Recurring tasks use templates rather than duplicating task definitions directly.
The recurrence record stores interval, next run time, owner context, and active
state.

## Taxonomy

Tasks use the existing global taxonomy system:

- task category: nullable `category_id` pointing to `categories`
- task tags: existing `taggables` polymorphic pivot

Templates can also define default category and tag values. These values support
sorting, filtering, Knowledge suggestions, future AI search, and campaign-style
operational grouping.

## Attachments

Tasks get their own `task_attachments` table. Attachments are internal in beta.
The schema mirrors the useful generic fields from ticket attachments without
depending on ticket messages.

## Notifications

The Task module should emit events for notification integration:

- task created
- task assigned
- status changed
- due soon
- overdue
- blocked or unblocked
- note added
- completed
- dependency completed

The first implementation may only persist activity, but events should be named
and placed so the Notification module can subscribe without changing Task core
logic.

## Reporting Fields

Tasks keep a few denormalized reporting fields for fast list views:

- `client_id`
- `site_id`
- `source_type`
- `source_id`
- `template_group_id`
- `template_item_id`

These fields make it possible to sort and report across domains without opening
every owner record.

## First Build Slice

1. Create module-owned routes, controllers, views, models, actions, and tests.
2. Move the legacy `/tech/tasks` route out of `routes/tech.php`.
3. Add migrations for core tasks, statuses, activities, relations,
   dependencies, checklists, time entries, attachments, templates, and
   recurrence.
4. Seed default task statuses and Knowledge documentation.
5. Build the first Tech UI:
   - compact PageHeader
   - Work sidebar
   - search/filter card
   - sortable task table
   - create form
   - show page with details, activity, checklist, child tasks, dependencies,
     and documentation/rightbar context
6. Add tests for default status creation, standalone task creation, nested
   tasks, dependency blocking, estimated-time completion, and route ownership.

## Deferred But Supported

These are not first UI requirements, but the schema and events should not block
them:

- customer-visible task activity
- approvals/review steps
- task workflow templates beyond status/dependency rules
- owner-domain completion blockers exposed in every module UI
- calendar rendering from scheduled task fields
- notification channel preferences per task event
