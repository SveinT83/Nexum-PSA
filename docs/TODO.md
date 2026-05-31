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
| Beta Completion Before New Systems | Ready |  | Priority rule: finish, harden, document, test, and polish existing modules before starting new domains or large new capabilities unless approved RFC says otherwise. See `docs/BETA-COMPLETION-PLAN.md`. |
| Technician Profile & Preferences | Ready |  | Build the technician profile/settings area in the main/user menu with side-menu sections for account details, profile image, work hours, skills, notification preferences, security/2FA, and future per-technician integration URLs. Superadmin/admin must be able to manage relevant technician profile data from admin user routes. |
| Company Profile & System Branding | Ready |  | Add company profile and branding settings: company details, contact details, logo, company brand colors/theme styling, header/login/public branding, safe asset upload, and reusable values for templates/documents. Include sensible fallback branding and colors for beta. |
| Personal View Preferences | Ready |  | After branding exists, allow technicians to choose limited personal workspace display preferences such as light/dark mode, while respecting company branding and Nexum UI guidelines. |
| Profile Surface Consolidation | Ready |  | Consolidate user preferences, security settings, notification preferences, Ticket technician profile, and admin employee profile into a coherent technician profile experience with side-menu sections. UserManagement becomes real owner of technician profile, work hours, availability, and general skills; Ticket keeps only assignment settings. See `docs/rfc/2026-05-31-technician-profile-consolidation.md`. |
| Admin Navigation Coverage Audit | Ready |  | Verify that all beta-ready admin/settings routes are discoverable from Admin landing page and sidebar. Known candidates: Calendar, Nextcloud, Notification channels, 2FA settings, Ticket technician profiles, and integration-specific settings. |
| Remove Or Finish Visible Unfinished UI | Ready |  | Sweep visible screens for `coming soon`, stub, disabled, or planning-only UI. Known candidates: Asset related tickets card, legacy task spec Blade files, and disabled integration cards. |
| Knowledge Coverage Audit | Ready |  | Check active modules for missing Knowledge docs/seeders and add minimum operator/admin documentation where needed. Candidates: Asset, Calendar, Clients, Documentation, Email, Integration, Knowledge, Risk, Taxonomy, User Management, Warroom. |
| Branding/Naming Consistency Sweep | Ready |  | Replace hardcoded or inconsistent `tdPSA`/`NexumPSA`/`Nexum-PSA`/`Nexum PSA` UI strings with Company Profile/System Branding values and consistent fallbacks. Known candidates: tech layout logo/footer, login page, public contract footer, default login placeholder. |
| View Path Planning File Cleanup | Ready |  | Move old markdown/specification files out of production view paths or delete superseded planning artifacts. Known candidates: `resources/views/tech/tasks/*`, task template specs, and integration planning docs under view folders. |
| Existing Module Settings Audit | Ready |  | Review current modules and identify missing settings required for stable beta operation before adding new systems. Document gaps and add follow-up TODO/RFC items. |
| Beta UX / Functionality Gap Sweep | Ready |  | Systematically review beta screens for unfinished UI controls, missing confirmations, broken empty states, confusing workflows, and missing documentation. |
| Existing Module Test & Regression Sweep | Ready |  | Run focused tests per existing module, identify failing/fragile workflows, and add missing regression tests before expanding scope. |
| Ticket SLA v1 | Done | Codex | SLA resolution, contract SLA field, ticket show panel, index SLA risk badges, Knowledge docs. |
| Ticket Actions v1 | Done | Codex | Shared action names, guard, apply SLA action, UI gating, Knowledge docs. |
| Ticket Workflow v1 | Done | Codex | Default workflow, states, transitions, runtime validation, Ticket show actions, Knowledge docs. |
| Ticket Workflow Editor v2 | Done | Codex | Admin create/edit workflow metadata, states, transitions, and stored requirements. |
| Ticket Knowledge loop | Ready |  | Better article matching and documentation follow-up flow. |
| AI write tools | Blocked |  | Wait until Ticket Workflow/Action guards are stable enough. |
| Contract SLA UI polish | Ready |  | Structured SLA select exists; needs UX polish and show/list signals. |
| Shared Send Email Component | Ready |  | Reusable email composer UI for Ticket, Inbox, Contact cards, and future modules. |
| System Language Defaults | Ready |  | Add system language/default locale settings and wire them into Contact language later. |
| Custom Fields & Metadata Layer | Ready |  | Generic platform capability for custom fields, automation metadata, and first Ticket UI. See `docs/CUSTOM-FIELDS-METADATA-IDEA.md`. |
| Task Templates v1 | Ready |  | Admin-managed Task Templates and Task Template Sets. See `docs/TASK-TEMPLATES-PLAN.md`. |
| Service Workshop Foundation | Blocked |  | Future idea for Custom Fields, Task Templates, Ticket Types/Templates, Asset Service, and Intake Items. See `docs/SERVICE-WORKSHOP-FOUNDATION-PLAN.md`. |
| Operational Signals | Blocked |  | Draft idea for machine/service/asset/SSL notification emails that should become structured signals instead of spam or tickets. Not fully discussed. See `docs/OPERATIONAL-SIGNALS-IDEA.md`. |
| Telephony Call Intake | Blocked |  | Draft idea for provider-agnostic incoming call intake with technician token URLs, contact matching, ticket actions, notes, and time. See `docs/TELEPHONY-CALL-INTAKE-IDEA.md`. |
| Security Hardening - Dev Verification | Ready |  | Fix and test remaining codebase security hardening before deploying a separate Linux pentest copy. See `docs/SECURITY-AUDIT.md` and `docs/SECURITY-REMEDIATION-PLAN.md`. |

## Ready To Pick Up

### 1. Ticket Workflow Requirements Enforcement

**Status:** Ready  
**Owner:**  
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

### 2. Ticket Knowledge Follow-Up

**Status:** Ready  
**Owner:**  
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

### 3. Contract SLA UI Polish

**Status:** Ready  
**Owner:**  
**Domain:** Commercial  
**Goal:** Make structured SLA binding clearer in contract screens.

Initial scope:

- Show SLA policy in contract index.
- Improve contract create/edit wording around “System default SLA”.
- Add a compact SLA summary to contract show.
- Ensure active contract SLA behavior is documented.
- Add tests if views or validation change.

### 4. SLA Reporting Foundation

**Status:** Ready  
**Owner:**  
**Domain:** Ticket / Reports  
**Goal:** Start basic operational reporting for SLA.

Initial scope:

- Query counts for response overdue, resolve overdue, responded within SLA, resolved within SLA.
- Start with a simple tech/admin report page or rightbar summary.
- Use ticket timestamps already available.
- Keep business-hours calculations out of v1 unless explicitly needed.

### 5. AI Tool Hardening For Tickets

**Status:** Blocked  
**Owner:**  
**Domain:** Integration / Ticket  
**Blocked by:** Ticket Workflow v1 and stronger action guards.

Initial future scope:

- Expose safe read tools for SLA risk, my tickets, and ticket details.
- Add write tools only for explicitly allowed Ticket Actions.
- Log every AI tool execution.
- Require agent and role permission for write tools.

### 6. Shared Send Email Component

**Status:** Ready  
**Owner:**  
**Domain:** Email / Ticket / Contact  
**Goal:** Create a reusable Send Email component that can be used consistently across Ticket, Inbox, Contact cards, and other modules.

Initial discussion points:

- One shared email composer UI instead of separate ad hoc forms per module.
- Must support Ticket replies, Inbox replies/forwards/new messages, and Contact-driven email.
- Should handle recipients, CC/BCC, subject, body, attachments, templates, sender account, and queue visibility.
- Must fit existing Bootstrap UI standards and module architecture.
- Needs discussion before implementation so domain ownership, persistence, queue behavior, and template integration are clear.

Out of scope until discussed:

- Replacing all current email forms in one pass.
- AI-assisted drafting.
- Full campaign/marketing email behavior.

### 7. System Language Defaults

**Status:** Ready  
**Owner:**  
**Domain:** Contact / System  
**Goal:** Add a system language/default locale setting and use it as the default Contact language once language choices are controlled centrally.

Initial discussion points:

- Replace free-text language with configured language choices.
- Add a System language/default locale setting and use it as the default Contact language.
- Update the Contact form after System language/default locale exists.

Open questions:

- Which languages should be enabled by default for the first beta.
- Whether the setting should live under System settings or Localization settings once that area exists.

### 8. Custom Fields & Metadata Layer

**Status:** Ready  
**Owner:**  
**Domain:** Cross-domain / CustomField / Ticket  
**Goal:** Build a generic Custom Fields and Metadata foundation, with Ticket as the first consumer.

Initial scope:

- Create a generic Custom Fields core that can later support Tickets, Assets, Contacts, Clients, and other records.
- Add field definitions, options, and structured values.
- Support basic field types such as text, textarea, number, date, datetime, select, multiselect, checkbox, email, phone, and URL.
- Support stable keys for automation and lightweight integrations, such as `mspmanager_user_id` or `n8n_sync_key`.
- Include planning for purpose/visibility concepts such as UI, integration, automation, system, visible, admin-only, hidden, and API-only.
- Add Ticket custom field UI for create/edit/show.
- Bind custom fields to existing Ticket Types so selecting a Ticket Type controls which extra fields appear.
- Store values in rows suitable for validation, search, reporting, templates, and future AI/context use.
- Add tests and Knowledge documentation.

Out of scope for first pass:

- Full Ticket Template builder.
- Task Templates.
- Workflow transition/closure enforcement for custom fields.
- Asset/Contact/Client custom field UI.
- Full native integration framework or External References replacement.

Planning document:

- `docs/CUSTOM-FIELDS-METADATA-IDEA.md`

### 9. Task Templates v1

**Status:** Ready  
**Owner:**  
**Domain:** Task  
**Goal:** Add admin-managed Task Templates and Task Template Sets as reusable task recipes for future Ticket Templates and service workflows.

Planning document:

- `docs/TASK-TEMPLATES-PLAN.md`

Initial scope:

- Add Task Template model, migration, admin controller, views, and routes.
- Add Task Template Set model, migration, admin controller, views, and routes.
- Allow a set to contain multiple templates with sort order.
- Allow one simple dependency on an earlier set item.
- Support active/inactive state.
- Support title, description, checklist items, estimated minutes, required/optional, assignee mode, fixed user, and due offset.
- Add tests and Knowledge documentation when implemented.

Out of scope for first pass:

- Ticket Template integration.
- Automatic task creation.
- Workflow enforcement.
- Advanced dependency types.
- Team/role assignment.
- Recurring tasks and reminders.

### 10. Service Workshop Foundation

**Status:** Blocked  
**Owner:**  
**Domain:** Cross-domain / Ticket / Task / Asset  
**Blocked by:** Custom Fields & Metadata Layer and later Task Template/Asset readiness work.

This is a future idea, not active implementation work.

Planning document:

- `docs/SERVICE-WORKSHOP-FOUNDATION-PLAN.md`

Recommended order:

- Add Task Templates that can later be copied into real Tasks.
- Improve Asset readiness for service history and quick-create/linking.

### 11. Operational Signals

**Status:** Blocked  
**Owner:**  
**Domain:** Email / Signal / Asset / Ticket  
**Blocked by:** Further discussion and ownership decision.

This is a future idea, not active implementation work. It is not fully discussed yet.

Planning document:

- `docs/OPERATIONAL-SIGNALS-IDEA.md`

Goal:

- Treat machine, service, monitoring, SSL, asset, and operational notification emails as structured Signals instead of spam or automatic Tickets.
- Allow Email Rules to classify inbound messages as Signals.
- Match Signals to Assets, Clients, Sites, Domains, or future Services where possible.
- Add Signals to entity timelines for operational context.
- Escalate Signals to Tickets only when configured rules say action is needed.

Initial discussion points:

- Decide whether Signals should be a standalone domain or start inside Email.
- Decide how unmatched Signals should be reviewed.
- Decide whether first matching should use asset name, hostname, domain name, custom fields, or all of them.
- Decide how retention should work for low-value Info signals.
- Decide how Signal rules relate to existing Email Rules and future Intelligence work.

First version candidates:

- Signal storage.
- Email Rule action: create Signal.
- Basic matching for hostnames, asset names, device identifiers, and domain names.
- Asset and Client timeline entries.
- Simple escalation rules by severity, count, and time window.
- Dismiss/ignore behavior.
- Tests and Knowledge documentation.

### 12. Telephony Call Intake

**Status:** Blocked  
**Owner:**  
**Domain:** Telephony / Contact / Client / Ticket / Commercial  
**Blocked by:** Further design discussion and later technician preference/profile surface.

This is a future idea, not active implementation work.

Planning document:

- `docs/TELEPHONY-CALL-INTAKE-IDEA.md`

Goal:

- Add a provider-agnostic Telephony domain for incoming call intake.
- Give each technician a personal token URL that a phone provider can open when a call is answered.
- Accept flexible provider payloads through query parameters, JSON, or form body.
- Normalize caller phone numbers and match them to Contacts.
- Show a focused Call Intake view with caller, Contact, Client, Site, open Tickets, recent Tickets, and Contract/SLA context.
- Allow unknown callers to be linked to an existing Contact, created as a new Contact, and optionally tied to a new Client.
- Store call records and call notes even when no Ticket is created.
- Allow creating a Ticket from the call or adding the call note to an existing Ticket.
- Support quick time registration from the call, including time without Ticket once billing behavior is clarified.

Initial discussion points:

- How technician intake tokens should be generated, rotated, and displayed.
- Whether provider profiles are global or selectable per technician.
- How field mapping should work for different provider payload formats.
- How time without Ticket should be billed, reported, or kept internal.
- How call notes should integrate with the future technician-wide note system.
- Whether test/preview should persist `is_test` call records or only render a preview.

First finished feature candidates:

- Telephony module/domain.
- Admin Telephony settings for provider profile and field mapping.
- Technician preference/profile display of personal intake URL.
- Preview/test button.
- Token intake route.
- Call record storage with raw payload and normalized phone.
- Contact matching by normalized phone.
- Call Intake view.
- Link/create Contact and optionally Client.
- Create Ticket from call.
- Add note to existing Ticket.
- Quick time registration.
- Tests and Knowledge documentation.

### 13. Security Hardening - Dev Verification

**Status:** Ready  
**Owner:**  
**Domain:** System / UserManagement / Knowledge / Email / Ticket / Deployment  
**Goal:** Close security hardening items that can be implemented and regression-tested in dev before deploying a separate Linux copy for pentesting.

Planning documents:

- `docs/SECURITY-AUDIT.md`
- `docs/SECURITY-REMEDIATION-PLAN.md`
- `docs/SECURITY-ASSESSMENT-LIVE.md`

Dev-testable remediation scope:

- Harden Laravel Boost so `_boost/browser-logs` is not registered in production mode, even if dev dependencies are accidentally installed.
- Add explicit `config/cors.php` with strict API origins and feature tests.
- Replace raw 2FA QR SVG output with a safer rendering approach and test that raw SVG is not emitted directly.
- Sanitize Knowledge `body_html` with a robust sanitizer and add XSS regression tests.
- Replace the Email HTML regex sanitizer or render email HTML safely, then add hostile-email regression tests.
- Add Ticket attachment upload validation for max size, extension, and real MIME detection.
- Add production route regression tests for Telescope, Horizon, and Boost.
- Keep existing security header tests passing.
- Add production session configuration regression coverage if not already sufficient.

Dev verification commands:

```bash
APP_ENV=production HOME=/tmp php artisan route:list --path=telescope
APP_ENV=production HOME=/tmp php artisan route:list --path=horizon
APP_ENV=production HOME=/tmp php artisan route:list --path=_boost
HOME=/tmp php artisan test app/Modules/System/Tests/Feature/SecurityHeadersTest.php
```

Expected before marking done:

- No production-mode Telescope routes.
- No Horizon routes.
- No production-mode Boost routes.
- Security header tests pass.
- New CORS, sanitizer, QR, and upload tests pass.

Out of scope for this dev task:

- Full live pentest.
- High-speed scanners.
- Production infrastructure validation.
- TLS/SSL Labs validation.
- Queue worker validation on the future Linux copy.

Future follow-up:

- Deploy a separate Linux copy of Nexum with `composer install --no-dev --optimize-autoloader`, production env settings, and production assets.
- Run the live pentest plan from `docs/SECURITY-REMEDIATION-PLAN.md`.
- Write a dated Linux-copy report after testing.

## Recently Completed

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
