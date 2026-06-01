# tdPSA Development TODO

This file is the shared coordination list for tdPSA development. Use it to delegate work across contributors and keep implementation, tests, and Knowledge/BookStack documentation moving together.

## Working Rules

- Pick one item, add your name or initials under `Owner`, and keep the status updated.
- Keep domain code inside `app/Modules/{Domain}` and follow `AGENTS.md`, `module-architecture.md`, and `ui-guidelines.md`.
- Every completed or materially updated domain feature must update Knowledge documentation and mark it for BookStack sync.
- Add or update tests for behavior changes before marking an item done.
- Do not rewrite unrelated dirty files. Work with the current branch state.

## Status Legend

- `Ready`: can be picked up now.
- `In Progress`: someone is actively working on it.
- `Blocked`: needs a decision or prerequisite.
- `Done`: implemented, tested, and documented.

## Active Workstreams

| Area | Status | Owner | Notes |
| --- | --- | --- | --- |
| Beta completion | In Progress | Svein / Codex | Finish and harden existing modules before starting large new domains. |
| Technician profile consolidation | In Progress | Codex | UserManagement owns `user_profiles`; Ticket now owns only Ticket Assignment Settings. |
| Ticket assignment settings split | Done | Codex | Legacy Ticket technician profile tables migrated into explicit assignment settings. |
| Ticket SLA v1 | Done | Codex | SLA resolution, contract SLA field, ticket show panel, index SLA risk badges, Knowledge docs. |
| Ticket Actions v1 | Done | Codex | Shared action names, guard, apply SLA action, UI gating, Knowledge docs. |
| Ticket Workflow v1 | Done | Codex | Default workflow, states, transitions, runtime validation, Ticket show actions, Knowledge docs. |
| Ticket Workflow Editor v2 | Done | Codex | Admin create/edit workflow metadata, states, transitions, and stored requirements. |
| Ticket Knowledge loop | Done | Codex | Ticket show creates documentation follow-up events and Ticket settings lists the latest requests. |
| AI write tools | Blocked |  | Wait until Ticket Workflow/Action guards are stable enough. |
| Contract SLA UI polish | Done | Codex | Contract index, form wording, show summary, tests, and Knowledge docs updated. |
| Storage barcode scanning | Ready |  | Storage must support barcode scanners from PC and mobile workflows. |

## Ready To Pick Up

### 1. Technician Profile Completion

**Status:** Done
**Owner:** Codex
**Domain:** UserManagement / Ticket
**Goal:** Finish the unified profile cleanup and remove remaining ambiguity.

Initial scope:

- Keep `/tech/profile` as the canonical technician profile shell.
- Keep UserManagement as owner of name, email, phone numbers, timezone, work hours, availability notes, and profile notes.
- Keep Ticket Assignment Settings limited to assignable state, capacity, ticket category matching, ticket tag matching, and ticket assignment notes.
- Confirm production deploy path after the legacy Ticket profile table cleanup:
  - `php artisan optimize:clear`
  - `php artisan migrate --force`
  - `php artisan user-profiles:backfill`
- Update Knowledge documentation after final UI polish.
- Profile image/avatar upload.
- Personal light/dark/system theme preference after branding.

Future scope:

- Decide whether category/tag matching is ticket-only or should become general skills.

### 2. Company Profile And Branding

**Status:** Done  
**Owner:** Codex  
**Domain:** System / UserManagement / UI  
**Goal:** Add company profile and branding defaults for Nexum PSA.

Initial scope:

- Company name and organization details.
- Logo/header branding.
- Brand colors stored in settings.
- Bootstrap-compatible theme variables.
- Prepare personal light/dark mode after global branding exists.

### 3. Ticket Workflow Requirements Enforcement

**Status:** Done
**Owner:** Codex
**Domain:** Ticket
**Goal:** Enforce the requirements already stored on workflow transitions.

Initial scope:

- Enforce `requires_note` before a transition can run.
- Enforce `requires_resolution` before resolve/close style transitions.
- Enforce `requires_knowledge_update` once documentation request tracking exists.
- Surface blocked reasons in Ticket show.
- Add tests and update Knowledge documentation.
- Update Knowledge page under `Nexum PSA -> Ticket`.

Out of scope for first pass:

- Large drag-and-drop workflow builder.
- Complex timers.
- AI write-tool execution.

### 4. Ticket Knowledge Follow-Up

**Status:** Done
**Owner:** Codex
**Domain:** Ticket / Knowledge
**Goal:** Make missing documentation visible from ticket work.

Initial scope:

- Add a lightweight “Documentation needed” action or event on Ticket show.
- Store a traceable request that points to ticket, category, client, and reason.
- Show pending documentation requests in Knowledge or Ticket settings.
- Keep articles/manual creation separate for now.
- Add tests and Knowledge documentation.

Future scope:

- KI-assisted article draft from ticket context.
- Workflow requirement: cannot close some categories without documentation update.

### 5. Contract SLA UI Polish

**Status:** Done
**Owner:** Codex
**Domain:** Commercial  
**Goal:** Make structured SLA binding clearer in contract screens.

Initial scope:

- Show SLA policy in contract index.
- Improve contract create/edit wording around “System default SLA”.
- Add a compact SLA summary to contract show.
- Ensure active contract SLA behavior is documented.
- Add tests if views or validation change.

### 6. SLA Reporting Foundation

**Status:** Ready  
**Owner:**  
**Domain:** Ticket / Reports  
**Goal:** Start basic operational reporting for SLA.

Initial scope:

- Query counts for response overdue, resolve overdue, responded within SLA, resolved within SLA.
- Start with a simple tech/admin report page or rightbar summary.
- Use ticket timestamps already available.
- Keep business-hours calculations out of first pass unless explicitly needed.

### 7. Storage Barcode Scanning

**Status:** Ready
**Owner:**
**Domain:** Storage
**Goal:** Support barcode-driven storage workflows from both desktop and mobile.

Initial scope:

- PC barcode scanners that behave like keyboard input.
- Mobile camera scanning for warehouse and technician workflows.
- Barcode lookup for storage items, boxes, reservations, picking, and stock adjustments.
- Settings for barcode formats and duplicate handling.
- Manual search fallback when barcode scanning is not available.

### 8. AI Tool Hardening For Tickets

**Status:** Blocked  
**Owner:**  
**Domain:** Integration / Ticket  
**Blocked by:** Ticket Workflow v1 and stronger action guards.

Initial future scope:

- Expose safe read tools for SLA risk, my tickets, and ticket details.
- Add write tools only for explicitly allowed Ticket Actions.
- Log every AI tool execution.
- Require agent and role permission for write tools.

## Recently Completed

### Ticket Assignment Settings Split

- `user_profiles` now owns timezone, working hours, availability notes, and profile notes.
- Ticket assignment settings own only assignment-specific fields.
- Legacy `ticket_technician_profiles` data is migrated to `ticket_assignment_settings`.
- Legacy ticket technician profile tables are dropped after migration.
- Ticket assignment scoring reads assignment settings plus UserManagement profile data.

### Ticket SLA v1

- Tickets store `sla_id`, `sla_source`, `sla_source_id`, `sla_snapshot`, `first_response_due_at`, and `resolve_due_at`.
- SLA resolution order: Ticket Rule, active Contract, Default SLA.
- Ticket Rules can set SLA.
- Contracts can store structured `sla_id`.
- Ticket show displays SLA details.
- Ticket index displays SLA risk badges and supports `SLA risk` sorting.
- Knowledge article: `Ticket SLA - v1`.

### Ticket Actions v1

- Shared action definitions in `TicketAction`.
- Basic action gate in `TicketActionGuard`.
- Controller guards mutable ticket operations.
- `ApplyTicketSla` backend action exists for future Workflow/KI.
- Knowledge article: `Ticket Actions - v1`.

### Ticket Workflow v1

- Workflow, state, and transition tables/models exist.
- Default workflow is generated from active ticket statuses.
- New tickets use the active global default workflow.
- Ticket show displays available workflow transitions.
- Status changes are validated against workflow transitions.
- Blocked transitions write ticket events.
- Knowledge article: `Ticket Workflow - v1`.

### Ticket Workflow Editor v2

- Admin workflow index links to create/edit.
- Workflow form persists metadata, active/default flags, states, and transitions.
- Transition requirements are stored for later enforcement.
- Tests cover create and edit flows.
