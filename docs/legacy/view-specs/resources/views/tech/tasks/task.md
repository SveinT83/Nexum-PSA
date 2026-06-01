# tech.tasks.* — General Documentation

**Date:** 2025-10-29
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.5 hours
**Controller path:** `App\\Http\\Controllers\\Tech\\Tasks\\*`

---

## 1) Purpose

The **Task system** provides a modular and universal mechanism for managing internal work items linked to Tickets, Orders, or other modules. Each Task represents a smaller, actionable unit of work that can be tracked, delegated, and time-logged independently, while automatically contributing to the parent entity's time and workflow.

The system ensures predictability and auditability while reducing manual handling and enabling workflow-based automation.

---

## 2) Core Concepts

### 2.1 Universal Scope

* Tasks can belong to any entity type (Ticket, Order, Lead, etc.).
* Each task stores `module_type` and `module_id` fields to maintain linkage.
* In v1, only internal staff (technicians, sales, admins) can view and manage tasks.

### 2.2 Hierarchy

* Tasks can be nested through `parent_id`.
* Child tasks marked **Requires parent done** remain *Blocked* until the parent is completed.
* Completion logic can be enforced by Workflow (e.g., “All subtasks must be done before ticket closure”).

### 2.3 Assignment & Ownership

* Tasks can be assigned to any technician or sales agent.
* The Ticket/Order owner remains responsible for the overall case.
* Reassignments are logged via the Audit layer.

### 2.4 Status Model

| Status          | Description                                     |
| --------------- | ----------------------------------------------- |
| **Open**        | Created, not yet started.                       |
| **In Progress** | Task actively being worked on.                  |
| **Blocked**     | Awaiting parent completion or manually flagged. |
| **Done**        | Completed; triggers automatic time log.         |
| **Canceled**    | Abandoned; no time added.                       |

### 2.5 Priority & SLA

* **Priority:** Tasks **inherit** priority from their parent Ticket. It is **read-only** in the UI and not editable per task.
* **SLA:** All SLA targets and timers (first response / resolve) are **ticket-only**. Tasks may use a local `due_at` deadline for planning; this has **no** SLA effect.

---

## 3) Data Model

### 3.1 tasks (main table)

| Field                  | Type          | Description                                |
| ---------------------- | ------------- | ------------------------------------------ |
| id                     | uuid          | Primary key                                |
| module_type            | string        | Ticket / Order / Lead / etc.               |
| module_id              | bigint        | Reference to parent entity                 |
| title                  | string        | Short title of the task                    |
| description            | text          | Detailed note or instruction               |
| assigned_to            | user_id       | Responsible technician/agent               |
| parent_id              | uuid/null     | Optional link to another task              |
| requires_parent_done   | bool          | If true, blocks until parent is done       |
| estimated_minutes      | int           | Preset expected duration                   |
| time_logged_minutes    | int/null      | Manual override time                       |
| due_at                 | datetime/null | Optional due date/time                     |
| status                 | enum          | Open, In Progress, Blocked, Done, Canceled |
| created_by             | user_id       | Creator of task                            |
| created_at, updated_at | timestamps    |                                            |

**Indexes:** `(module_type, module_id)`, `parent_id`, `assigned_to`, `status`

**Priority (derived):** tasks **do not store** a `priority` column. The UI shows the **ticket's** priority (inherited, read-only).

### 3.2 time_logs (existing shared model)

* Each completed task automatically logs time into `time_logs`.
* Links back to both Task and its parent Ticket/Order.
* Manual overrides possible.

### 3.3 task_template_groups / task_template_items

Used for reusing predefined sets of tasks (not runtime grouping).

| Table                | Purpose                                          |
| -------------------- | ------------------------------------------------ |
| task_template_groups | Define template collections of related tasks     |
| task_template_items  | Store individual template tasks with order/index |

Templates can be applied to any Ticket/Order to prefill new tasks.

---

## 4) Logic & Behavior

### 4.1 Parent and Blocking

* If `requires_parent_done = 1` and the parent status != Done → status = *Blocked*.
* When the parent transitions to *Done*, children automatically unlock (status → Open).
* Workflow can enforce that all required tasks must be Done before a Ticket closes.

### 4.2 Time Tracking

* A task may define an `estimated_minutes` value.
* On completion (status → Done), if no manual `time_logged_minutes` exists, the estimated value is logged.
* Time entries flow upward to the Ticket for SLA and billing reports.

### 4.3 Templates

* Templates contain reusable task structures for repeated work (e.g., onboarding, site setup).
* Applying a template copies items into new `tasks` records under a Ticket or Order.
* Tasks are created one-by-one; no runtime group container is stored.
* **Note:** Templates cannot change task priority; priority always follows the ticket.

### 4.4 SLA (ticket-only)

* SLA targets and timers live on the **Ticket**. Tasks never start/pause/breach SLA by themselves.
* `due_at` is a local task deadline for planning; it has no SLA effect.

---

## 5) Views & Components

All task-related UI elements reuse the same form logic, available both as standalone pages and modal dialogs.

| View                | URL                                              | Purpose                                              |
| ------------------- | ------------------------------------------------ | ---------------------------------------------------- |
| `tech.tasks.index`  | `/tech/tasks`                                    | List all open tasks grouped by module.               |
| `tech.tasks.show`   | `/tech/tasks/{id}`                               | Display details, time log, and related subtasks.     |
| `tech.tasks.create` | `/tech/tasks/create`                             | Form to create a new task manually or from template. |
| `tech.tasks._form`  | Partial component shared between page and modal. |                                                      |

**Modal behavior:** The same form component can open from Tickets, Orders, or Task Templates. Context (`module_type`, `module_id`) is injected dynamically. Upon save, the modal emits `task:created` to update the parent view in real-time.

---

## 6) Workflow & Integration

* Ticket Workflows can check whether linked tasks are completed before allowing Close/Resolve.
* Rules may automatically create predefined tasks when a Ticket is categorized or queued.
* Blocking logic is enforced at both Task and Workflow level.

---

## 7) Reporting & Audit

* All task actions (create, edit, delete, status change) are logged via Audit.
* Time logs are aggregated into parent Ticket/Order reports.
* Reports support filtering by Technician, Ticket, Client, Queue, and Category.

---

## 8) Roles & Permissions

| Permission    | Description                                      |
| ------------- | ------------------------------------------------ |
| `task.view`   | View tasks related to accessible Tickets/Orders. |
| `task.create` | Create new tasks manually or via modal.          |
| `task.edit`   | Edit existing tasks or update status.            |
| `task.delete` | Delete tasks (restricted by role).               |
| `task.admin`  | Manage task templates and system settings.       |

**Default roles:**

* **Technician:** `task.view`, `task.create`, `task.edit`
* **Tech.Admin:** All task permissions
* **Sales.Admin:** Same, limited to Sales context

---

## 9) UX & Layout Guidelines

* Uses standard PSA layout: top bar, main section, right slim panel.
* In Ticket/Order view, a "Related Tasks" widget lists active tasks with status chips.
* Context chips in the form (e.g., *Ticket TD-1234*, *Parent Task: Configure backup*).
* **Priority badge is inherited from the Ticket and read-only.**
* Tasks may set a **local** `due_at` for planning; SLA remains ticket-level.
* All actions use modals for quick task creation and status updates.
* Consistent icons: check-circle (done), pause-circle (blocked), clock (in progress), plus (create).

---

## 10) Audit & Consistency

* All operations write immutable audit records.
* Prevent duplicate submissions via idempotency token per form submission.
* Parent context must always be valid; if parent Ticket/Order is deleted, child tasks are archived or cascaded to soft-delete.

---

## 11) Future Extensions

* Calendar integration (Google/Microsoft sync via API).
* Cross-ticket dependencies.
* Customer-visible checklists or approval tasks.
* SLA-based task notifications.

---

**Summary:**
The Task module provides a clean, reusable foundation for internal work tracking across tdPSA. It integrates tightly with Tickets, Orders, and Workflow, automates time logging, supports templates for repeatable work, and maintains full traceability through the audit system — while keeping priority inherited and SLA strictly ticket-scoped.
