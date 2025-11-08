# tech.tasks.template* — General Specification (Task Template System)

**Creation date:** 2025-10-29
**URL:** `tech.tasks.template*`
**Access level:** `template.ticket.manage`
**Controller path:** `App\Http\Controllers\Tech\Tasks\Template*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.5 hours

---

## 1. Purpose

The **Task Template System** defines reusable task groups that can be applied to a Ticket. Templates standardize repetitive task structures (checklists, installation routines, maintenance procedures) to speed up ticket handling and ensure consistency.

Each template can contain one or more **tasks** and **sub-tasks**, arranged hierarchically, but **does not modify the ticket itself**. Tickets provide context only (where the tasks belong).

---

## 2. Data Model

| Field                   | Type       | Description                                                |
| ----------------------- | ---------- | ---------------------------------------------------------- |
| id                      | int        | Primary key                                                |
| name                    | string     | Template name                                              |
| description             | text       | Optional description shown in selector                     |
| is_active               | bool       | Whether the template is available for use                  |
| available_queues        | json       | Optional list of queue IDs where this template can be used |
| estimated_total_minutes | int        | Optional total time estimate across all tasks              |
| created_by              | user_id    | Reference to the technician who created the template       |
| created_at / updated_at | timestamps | Standard audit fields                                      |

**Relations:**

* `hasMany(TaskTemplateItem)` → defines tasks inside template.

---

## 3. Template Items (Tasks)

| Field            | Type          | Description                                               |
| ---------------- | ------------- | --------------------------------------------------------- |
| id               | int           | Primary key                                               |
| template_id      | FK            | Parent template                                           |
| parent_id        | FK (nullable) | For nested subtasks                                       |
| title            | string        | Task title                                                |
| description      | text          | Optional detailed description                             |
| default_duration | int           | Estimated minutes for this task                           |
| sort_order       | int           | Display order within the template                         |
| required         | bool          | Whether this task must be completed before dependent ones |

---

## 4. Behavior & Application

* Templates are applied **from a Ticket context** only (never standalone).
* When applied, all task items are **copied** into the ticket’s task list.
* Task hierarchy is preserved (parent → child).
* Estimated duration is copied to each task; ticket aggregates total time.
* No ticket metadata is altered by templates.
* Multiple templates may be applied to the same ticket (if not restricted by queue).
* Technicians can still manually add, edit, or remove tasks after applying a template.

---

## 5. UI Components

### 5.1 tech.tasks.template.index

List of templates.

* Columns: Template name, Queues, Active status, Total tasks, Last updated.
* Actions: View / Edit / Duplicate / Delete.
* Click row → opens detail/edit view.

### 5.2 tech.tasks.template.show

Displays template details.

* Shows all task items in a tree view.
* Buttons: Edit template, Add task/subtask, Duplicate template.
* Sidebar: Total time, creator, available queues.

### 5.3 tech.tasks.template.create (and edit)

Form for creating or editing a template.

* Fields: Name, Description, Queues, Active toggle, Estimated time.
* Section below: Task list (sortable hierarchy table).
* Add Task button → modal with: Title, Description, Duration, Required toggle.

---

## 6. Permissions & Access

| Role       | Permission               | Description                    |
| ---------- | ------------------------ | ------------------------------ |
| SuperAdmin | all                      | Full control                   |
| Tech.Admin | `template.ticket.manage` | Create, edit, delete templates |
| Technician | `ticket.edit`            | Can apply templates on tickets |

---

## 7. Workflow Integration

* Template system integrates only with **Task Management**, not Ticket Rules.
* Tasks generated from templates behave as standard ticket tasks.
* Workflows or SLA do **not** depend on template membership.
* All actions (create/edit/apply) are logged for audit.

---

## 8. Audit & Logging

* Log creation, modification, and deletion of templates.
* Log which template(s) were applied to which ticket.
* Store user ID and timestamp for every application event.

---

## 9. UI/UX Notes

* Use Bootstrap + sortable drag tree view for task hierarchy.
* Support inline editing of task titles/descriptions.
* Use modal confirmation before deleting templates.
* When applying a template to a ticket: show preview → confirm → apply.
* Show toast notification: “Template ‘{name}’ added to ticket successfully.”

---

## 10. Future Extensions

* Allow template grouping (e.g., by department or service type).
* Support versioning of templates (track historical edits).
* Add import/export (JSON) for sharing templates between environments.
* Allow timebank reports per template (planned vs. actual time).

---

**Summary:**
The Task Template System provides reusable task structures scoped strictly to Tickets. It enhances productivity, ensures process consistency, and integrates seamlessly into the existing tech task views without altering ticket data or workflows.
