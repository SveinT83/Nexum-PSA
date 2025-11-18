# View Specification – tech.ticket.create

**Date:** 2025-10-20
**Status:** Not started
**Difficulty:** Medium
**Estimated Time:** 3.0 hours
**Primary URL:** tech.ticket.create
**Controller Namespace:** App\Http\Controllers\Tech\Ticket\CreateController
**Corresponding Route Name:** tech.ticket.create (reused across technician contexts; prefilled by origin context)

**Access Levels (who can view/use):**

* Required: `ticket.create`
* Typical roles: Technician, TechnicianAdmin, Admin, SuperAdmin
* Not exposed to client portal (client has a distinct create view)

**Layout Template:**
Top header / Main content / Right slim rail (Bootstrap)

* The page renders inside the standard app shell (no full-bleed).
* Right slim rail hosts contextual tips and dynamic summaries.

---

## Purpose

Unified technician “Create Ticket” form used from anywhere in the tech app (dashboard, client card, user card, asset card, queue lists). Origin context preselects related fields (client/site/user/asset) but all selections remain editable unless locked by policy.

---

## IA & Grouping (fixed order)

### 1) Context

* **Client** (typeahead with debounce; search by name, customer no.)
* **Site** (dropdown; filtered by Client)
* **User** (dropdown; filtered by Client/Site)
* **Asset** (dropdown; filtered by Client/Site/User)

  * Selecting **Asset** auto-fills User → Site → Client (overridable)

### 2) Routing

* **Queue** (dropdown)
* **Service** (dropdown; from customer contracts; optional)
* **Priority** (dropdown: Low / Normal / High / Critical)

  * Rule engine may later adjust; show subtle note “May be updated by rules”
* **Assignee** (dropdown: default = current technician; options = technicians with access to selected queue)
* **Notify customer now** (checkbox)

  * If checked, send a short creation email (ID + brief description); default unchecked
  * Also controlled by `admin.settings.ticket.notifications`

### 3) Content

* **Headline** (single line)
* **Description** (multiline)

  * **Snippets**: inline suggestions while typing (e.g., standard checks, setup flows).
  * Insert-on-click/enter; non-blocking autocomplete popup.

### Footer

* **Cancel** (secondary button)
* **Create** (primary button)

  * Shows spinner “Creating…” and disables while submitting
  * On success: toast to assigned technician, and modal prompt: “Ticket #XXXXX created. Open now?” (buttons: Open / Stay)

---

## Behaviors & Rules

* **Minimal typing**: Choosing any of Client/Site/User/Asset filters/auto-fills the others where possible.
* **Filtering**: Lists always filtered to current upstream choice (e.g., Site list is only sites for Client).
* **Required fields for submit**: Client **or** Asset (which implies Client), Queue, Headline.
* **No autosave** in v1; save occurs on Create.
* **No memory of last-used values** in v1.
* **Default assignment**: Assignee preselected as current technician but editable; can also leave unset if allowed by policy.
* **Rule Engine hooks**: After creation, workflow/rules may re-route queue, adjust priority, or reassign. UI shows a transient note if changes occur.

---

## Components & Reuse (Livewire-friendly)

* **ClientPicker (Livewire)**

  * Typeahead search (min 2 chars, debounce 250ms).
  * Emits `clientSelected(clientId)`.
* **SiteDropdown (Livewire)**

  * List filtered by clientId.
  * Emits `siteSelected(siteId)`.
* **UserDropdown (Livewire)**

  * List filtered by clientId/siteId.
  * Emits `userSelected(userId)`.
* **AssetDropdown (Livewire)**

  * List filtered by clientId/siteId/userId; selecting asset triggers autofill for related entities.
  * Emits `assetSelected(assetId, userId, siteId, clientId)`.
* **QueueDropdown (Livewire)**

  * Supports permission-aware technician lists.
* **ServiceDropdown (Livewire)**

  * Populates from contracts for the selected client/site.
* **PriorityDropdown** (simple)
* **AssigneeDropdown (Livewire)**

  * Filters technicians by queue membership/capability.
* **SnippetsSuggest (Livewire)**

  * Background suggestion service keyed on headline/description tokens; insertable chips.
* **CreateAction (Livewire)**

  * Handles validation → submit → toast → modal (Open now?).

> **Reusable UI elements**: pickers/dropdowns above should be packaged for reuse in edit flows and other views.

---

## Validation

* **Client** required *unless* Asset is chosen (Asset implies Client).
* **Queue** required.
* **Headline** required (length 3–150).
* **Description** optional (length max 20k).
* **Assignee** optional (policy-dependent); default = current user.
* **Service** optional.

**Server-side** mirrors client rules; return field-level errors and a top summary alert.
**Policy gates** ensure user can create in selected queue, assign target technician, and link chosen service.

---

## Data Loading Strategy

* **Hybrid**: Lightweight initial payload + Livewire AJAX for dependent lists.
* ClientPicker uses server search; other dropdowns hydrate on-demand based on upstream selection.
* Origin context (client/site/user/asset) passed via query params and preselected on load.

---

## Right Slim Rail (Widgets – optional, no configuration)

* **Client brief**: name, customer no., SLA tag, open ticket count.
* **Recent tickets** for selected User/Asset (last 5; newest first).
* **Contract services summary** for selected Client/Site (quick read-only list).
* **Validation helper**: inline checklist of required fields turning ready/complete.

---

## Settings & Integrations

* **Notifications**: governed by `admin.settings.ticket.notifications` (default silent create; optional “Notify now”).
* **Rule Engine**: post-create hooks for queue/priority/assignee.
* **Contracts/Services**: sourced from CS module for service binding.
* **Timers**: no auto-start here (managed on tech.ticket.show per project rules).

---

## Icons (suggested, no colors)

* Client: building
* Site: map-pin
* User: user
* Asset: cpu / monitor / server (contextual)
* Queue: inbox
* Service: layers
* Priority: alert-triangle
* Assignee: users
* Snippets: file-text
* Notify: mail

---

## Telemetry & Audit

* Log origin context (where create was invoked).
* Log all initial field values and rule-engine changes after creation.
* Include who created, when, and IP/user-agent for audit trail.

---

## Test Scenarios (acceptance)

1. Create from client card with prefilled Client; select Site/User, choose Queue, Headline → Create.
2. Create by selecting Asset first; verify auto-fill of User/Site/Client; override Site; create.
3. Create with Notify customer now checked; confirm mail dispatched and toast shown.
4. Create with high priority; rule engine downscales to normal; UI shows note.

---

## Notes for Bootstrap Implementation

* Use standard grid with clear section headers (Context, Routing, Content).
* Keep labels concise; help text as muted small text under inputs.
* Disable dependent dropdowns until upstream selection exists; show inline loading spinners for AJAX hydrations.

---

## Livewire Events (suggested)

* `clientSelected`, `siteSelected`, `userSelected`, `assetSelected`
* `queueSelected`, `assigneeSelected`, `serviceSelected`
* `snippetsSuggested(tokens[])`, `snippetInserted(id)`
* `submitStarted`, `submitSucceeded(ticketId)`, `submitFailed(errors)`

---

## Out of Scope (v1)

* Autosave drafts.
* Remember last-used values.
* Internal-only fields (notes, cost account, estimates).
* Client-portal variant (will be separate view).
