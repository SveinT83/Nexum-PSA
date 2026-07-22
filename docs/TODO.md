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
| CloudFactory Partner Integration | Ready for Live Validation | Svein / Codex | Core integration and the versioned legal-document/Customer-admin portal-ordering slice are implemented under approved RFC `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`. Provider documents are immutable and read-only, Nexum terms remain additive, contracts and portal writes retain versioned acceptance evidence, and monthly catalogue sync performs the legal check. Remaining gates are the existing production validation in `HR-2026-07-20-001` and the focused Dev legal/portal review in `HR-2026-07-22-001`. |
| Between Competitor Parity Audit | Ready |  | Use `docs/audits/2026-07-03-between-competitor-gap-analysis.md`, `docs/ideas/`, and the 2026-07-04 draft RFC batch as planning input for booking, field-service mobile/PWA, payments/accounting, SMS automation, departments/service areas, resources, and feedback. Do not implement Level 2/3 parity work without approved RFCs. |
| Notification SMS Channel Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-notification-sms-channel-automation.md`; first slice `docs/feature-slices/2026-07-05-notification-sms-dry-run-foundation.md` adds dry-run transactional SMS configuration, templates, audit logs, manual admin test send, and Contact phone consent guards. Production providers and workflow automation remain later slices. |
| One Responsive Nexum PWA Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-one-responsive-nexum-pwa.md`; app-wide PWA metadata, online-first service worker/offline page, mobile tech offcanvas navigation, `/tech/my-day`, UI guidelines, Knowledge docs, Dev smoke checks, and focused Dev tests are complete. Future slices: responsive hardening by workflow, push notifications, and offline queueing only after conflict/sync design. |
| Public Inquiry Forms Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-public-inquiry-forms.md`; first slice adds Intake-owned public forms, file uploads, admin review, matching, guarded Sales routing, Knowledge docs, Dev migration/seeding, and tests. |
| Intake Signal Post-Submit Automation | Done | Codex | Approved RFC `docs/rfc/2026-07-05-intake-signal-post-submit-automation.md`; Intake now emits post-submit Signal events and Signal rules can trigger Ticket, Task, Portal invitation, Sales follow-up, and webhook actions. |
| Email/Ticket Rules Signal Alignment | Done | Codex | Approved RFC `docs/rfc/2026-07-08-email-ticket-signal-rule-alignment.md`; Email Rules and Ticket creation rules can now explicitly emit Signal records, Signal-created tickets skip Ticket Signal handoff to avoid loops, Knowledge sync includes Signal docs, and focused Dev tests passed. |
| Storage Orderable Over-Reservations | Done | Codex | Approved RFC `docs/rfc/2026-07-08-storage-orderable-over-reservations.md`; GitHub issue #177 is implemented so active orderable Storage items can be reserved beyond available stock, not-orderable items keep the available-stock guard, picking remains blocked until stock is on hand, and Knowledge docs/tests were updated. |
| Ticket Storage Reservation Release | Done | Codex | Approved RFC `docs/rfc/2026-07-21-ticket-storage-reservation-release.md`; explicit removal from inside Edit cost and quantity-zero release share one audited transaction that frees reserved stock, removes Picking List work, and restores linked approved planned lines. All affected Dev suites and rendered-view checks passed; human review `HR-2026-07-21-001` remains pending. |
| Customer Portal Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-customer-portal-foundation.md` with ADR `docs/adr/2026-07-04-customer-portal-identity-separation.md`; first slice implemented with portal accounts, memberships, invitations, audit events, portal dashboard, docs, Dev migration/seeding, and tests. Portal invitations now originate from Contact/Contract workflows, while the Customer Portal admin URL is reserved for future portal settings. Customer-visible domain data remains explicit slices. |
| Customer Portal Ticket Workflow | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`; customer-visible ticket list/create/detail/reply with explicit one-way publishing, default/manual visibility control, and scope enforcement is implemented, documented, migrated, and Dev-tested. |
| Customer Portal Document Center | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-document-center.md`; explicitly published Documentation records and published public/client-wide Knowledge articles are exposed inside portal scope and Dev-tested. |
| Customer Portal Commercial/Economy Summary | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-commercial-economy-summary.md`; approved/accepted contract summaries and explicitly published economy order summaries are implemented, documented, migrated, and Dev-tested. |
| Customer Portal Quote/Contract Acceptance | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-quote-contract-acceptance.md`; existing Sales quote and Commercial contract acceptance are bound to authenticated portal identity, documented, migrated, and Dev-tested without implementing full CPQ. |
| Customer Portal Notifications | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-notifications.md`; portal notification center, customer-safe notification delivery, preferences, and implemented-domain event emitters are implemented, documented, and Dev-tested under the approved Customer Portal RFC. |
| Online Booking With Calendar Availability | Done | Codex | Approved RFC `docs/rfc/2026-07-04-online-booking-calendar-availability.md`; feature slice `docs/feature-slices/2026-07-04-online-booking-calendar-availability.md` implements Booking service settings, public Calendar-backed slot requests, staff confirmation into Calendar events, customer email notifications, admin review, docs, and tests. |
| Telephony Call Intake v1 | Done | Codex | Discussion #49 implemented with personal provider URL, public token intake, caller matching, call notes, ticket creation/linking, tests, and Knowledge docs. |
| Technician profile consolidation | Done | Codex | UserManagement owns `user_profiles`; Ticket now owns only Ticket Assignment Settings. |
| Ticket assignment settings split | Done | Codex | Legacy Ticket technician profile tables migrated into explicit assignment settings. |
| Ticket SLA v1 | Done | Codex | SLA resolution, contract SLA field, ticket show panel, index SLA risk badges, Knowledge docs. |
| Ticket Actions v1 | Done | Codex | Shared action names, guard, apply SLA action, UI gating, Knowledge docs. |
| Ticket Workflow v1 | Done | Codex | Default workflow, states, transitions, runtime validation, Ticket show actions, Knowledge docs. |
| Ticket Workflow Editor v2 | Done | Codex | Admin create/edit workflow metadata, states, transitions, and stored requirements. |
| Ticket Workflow v3 Conditional Actions And Escalation | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`; all eight 2026-07-17 Feature Slices plus the 2026-07-18 customer notification and automatic migration-placement slices are implemented. Workflow supports Signal-style grouped requirements, versioned steps, server-enforced action/API parity, escalation and eligible assignment, senior review/evidence, Ticket-origin Sales quotes, planned scope, controlled fulfilment/Economy closure, Published-only transition notifications, and requirement-based per-Ticket placement when active work is migrated to a new version. Dev migration, focused cross-module tests, Knowledge/BookStack sync, and human review are tracked under `HR-2026-07-17-001`. |
| Ticket Solution Policy | Done | Codex | Approved RFC 2026-06-03; internal solution notes are enabled by default and admin-configurable. |
| Ticket Knowledge loop | Done | Codex | Ticket show creates documentation follow-up events and Ticket settings lists the latest requests. |
| AI write tools | Blocked |  | Wait until Ticket Workflow/Action guards are stable enough. |
| Contract SLA UI polish | Done | Codex | Contract index, form wording, show summary, tests, and Knowledge docs updated. |
| Company Theme System | Done | Codex | Branding view now manages light/dark logos, shell surfaces, card headers, and button colors. |
| Reporting Domain Foundation | Done | Codex | Report module owns the hub and registry; Ticket registers the SLA report while keeping its query/detail view. |
| Module Settings Audit | Done | Codex | Audit captured settings ownership gaps, admin discoverability gaps, visible unfinished UI, and legacy planning files. |
| Admin Settings Discoverability Cleanup | Done | Codex | Existing beta-ready settings surfaces are reachable from Admin hub/sidebar and documented. |
| Visible Unfinished UI Cleanup | Done | Codex | Removed beta-visible coming-soon text from Asset/N-able and replaced old login copy/placeholders. |
| Email Health Check Honesty | Done | Codex | Queued email health checks now reuse the real IMAP/SMTP test service instead of writing unconditional OK results. |
| BookStack Scheduled Sync Honesty | Done | Codex | Scheduled BookStack pull/push jobs now mark active misconfigured integrations unhealthy instead of returning silently. |
| BookStack Knowledge API And Sync Hardening | Done | Codex | Approved RFC `docs/rfc/2026-06-16-bookstack-knowledge-api-sync-hardening.md`; implemented API parity for shelves/books/chapters/articles, BookStack pull/push/test/status endpoints, two-way sync diagnostics, tests, and docs. |
| Knowledge Documentations API | Done | Codex | Approved RFC `docs/rfc/2026-06-30-knowledge-documentations-api.md`; Documentation module exposes Knowledge-scoped API routes for Documentation records, documentation categories, and templates. |
| Commercial Settings Route Cleanup | Done | Codex | Contract settings URL now uses `/contracts`; legacy `/contacts` typo redirects to the canonical route. |
| Beta Release Hardening Sweep | Done | Codex | Removed mutating GET routes found in Commercial, made Queue/Worker setup paths environment-aware, and ran all module feature suites. |
| Asset Settings Slice | Done | Codex | Asset module now owns manual registration defaults and admin settings. |
| Contact Settings Slice | Done | Codex | Contact module now owns defaults and relation type settings. |
| Legacy Planning Files Cleanup | Done | Codex | Moved Markdown planning/spec files out of production view paths and updated runtime doc references. |
| Task Settings Slice | Done | Codex | Task module now owns manual task defaults for status, priority, and estimate. |
| Warroom Settings Slice | Done | Codex | Warroom now owns dashboard windows, list limits, and visible panels. |
| Knowledge Settings Slice | Done | Codex | Knowledge now owns manual article defaults for visibility, status, review, and priority. |
| Risk Settings Slice | Done | Codex | Risk now owns defaults for assessments, item scoring, item status, and review interval. |
| Missing Settings Ownership RFC | Done | Codex | RFC approved; Asset, Contact, Task, Warroom, Knowledge, and Risk settings slices completed. |
| Domain API Foundation | Done | Codex | Scoped Sanctum API keys now enforce Client/Site, Custom Fields, Asset, Contact, Marketing, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox, Notification, Sales, Taxonomy, Commercial, Economy, Report, and User Management API scopes. |
| Work Context / Organization Scope | Done | Codex | Discussion #149 completed through RFC `docs/rfc/2026-07-01-work-context-organization-scope.md`: foundation plus Ticket, Task, Asset, Documentation, Risk, Calendar, Report/API filters, and client-only Commercial/Economy/Sales guardrails are implemented, documented, and tested. Legacy self-client cleanup remains a separate non-goal/future cleanup item. |
| Nexum Relationship / Vendor Provider Routing | Done | Codex | Discussion #150 implemented with Relationship module, signed Nexum-to-Nexum transport, ticket escalation/public reply/status sync, selected attachment sync, documentation/Knowledge sync with conflict review, admin UI, audit logs, documentation, migration, seed updates, and tests. |
| Data Exchange Platform | Done | Codex | Approved RFC `docs/rfc/2026-07-03-data-exchange-platform.md` with ADR `docs/adr/2026-07-03-data-exchange-platform-ownership.md`; v1 profile builder, export/import runtimes, schedules, delivery attempts, API, Clients basic import, and Economy Orders export are implemented. Tripletex/PowerOffice remain future provider-profile slices. |
| Custom Fields Core | Done | Codex | Adds generic definitions/values with Client UI/API value support, Client workspace tab, and read-only definition discovery API for MSP Manager/n8n sync identifiers. |
| Client Contract Timebank Quick Consumption | Done | Codex | Approved RFC 2026-06-08; first slice adds Client Contracts tab timebank bars, audit-backed quick usage modal, permissions, and default Commercial policy. |
| Commercial Timebank Policy Admin UI | Done | Codex | Commercial settings now expose quick Client timebank policy controls backed by `common_settings`. |
| Quick Timebank Overuse Billing Integration | Done | Codex | Quick Client timebank overuse now stores rate snapshots and Economy Generate orders creates draft order lines for overused minutes. |
| Client Time Usage Tab | Done | Codex | Client profile now has a Time tab with quick registration, unified quick/ticket/task time usage history, and source-safe edit actions before Economy ordering. |
| Marketing Domain And Email Campaign Automation | Done | Codex | Approved RFC 2026-06-09 plus Marketing API RFC 2026-06-17; domain foundation, Email marketing defaults/templates, mailing lists, Marketing API surface, campaign approval, due sending, dashboard, tracking, campaign email cards, preview/test-send, snapshot auto-fill, AI email draft assist, AI campaign plan assist, campaign-level send rhythm, recipient batch throttling, new-contact schedule policy, multi-list campaign audiences with recipient deduplication, repeat/stop completion policy, suppression hardening, richer segmentation UI, WordPress content pull as AI/content context, and Sales/Leads marketing engagement context are implemented. Separate Marketing engagement/call lists remain intentionally out of scope. |
| Marketing Google And Social Integrations | Future |  | Add provider-backed Google Analytics/Search Console/Ads context and social publishing/import workflows under Integration-owned provider settings. Do not expose controls until each provider workflow is functional and tested. |
| Lead Intelligence / AI Prospecting Foundation | Done | Codex | Approved RFC 2026-06-12; first slice adds settings, segment policy, planned and executable research runs, scan ledger, source evidence, suppression entries, contact marketing eligibility, API abilities, simple admin/tech UI, guarded candidate promotion into Clients, Contacts, and Marketing list members, Run Now, configurable BRREG discovery, shallow website email discovery, AI discovery planning with editable prompt, OpenAI AI-provider web search for candidate URLs, provider-neutral web-search endpoint adapter, Laravel queued execution job, and grounded AI candidate review with editable prompt. Run Now queues an immediate run and dispatches the same Laravel queue job used by scheduled automation. It intentionally does not run deep crawling, hallucinated contact generation, or email sending. Next slice: deeper discovery and richer evidence review UI. |
| Lead Intelligence Schedule Foundation | Done | Codex | Approved RFC 2026-06-12; segments now have schedule period, run time, weekdays, run interval, lead target, token budget or unlimited-token mode, max runs, next/last run tracking, planner command, Laravel queued execution job, description-as-goal-prompt run context, AI segment draft assist, Run Now, and promotion targets through segment Marketing lists. Still no deep crawler, enrichment worker, or email sending. |
| Lead Intelligence Deeper Discovery + AI Enrichment | Future |  | Add controlled multi-page discovery, role/person extraction, AI evidence summarization UI, manual review queue for AI `review` decisions, website/contact confidence scoring, richer BRREG/company filters, and optional provider-backed search. Must keep settings, suppression, scan ledger, and dry-run/review guardrails in front of Marketing promotion. |
| Lead Intelligence AI-Led Discovery Worker | Done | Codex | Worker now uses an editable AI discovery planner, configured source adapters, BRREG, AI-provider or endpoint web-search results, company-specific homepage lookup for registry candidates with missing contact data, shallow website evidence collection, grounded AI candidate review, scan ledger checks, and guarded promotion. AI output cannot create companies, contacts, emails, roles, URLs, or facts unless source evidence exists. |
| Signal Domain Active Automation | Done | Codex | Approved RFC 2026-06-09; active Signal records, rules, execution audit, webhook delivery, UI, protected API ingest, Marketing producer integration, Email bounce/autoreply/unsubscribe/vendor classifiers, settings-controlled AI-assisted classification fallback, configurable rule/action settings, Client/Contact signal history, Sales follow-up action, and Ticket follow-up action are implemented, documented, and tested. |
| Report Builder And Scheduled Client Reporting | Post-Beta |  | Version 2 item. Build custom report builder, saved report templates, and automatic client report delivery. |
| Shared HTML Content Editor | Post-Beta |  | Version 1 item. Build a reusable WordPress-like Bootstrap editor with HTML/source mode, visual drag/drop content blocks, reusable template sections, preview, and safe output for Marketing emails, Email templates, Documentation, Knowledge, and future content surfaces. |
| Storage Barcode Scanning | Post-Beta |  | Version 1 item. Storage must support barcode scanners from PC and mobile workflows. |
| Storage Default Warehouse | Done | Codex | Approved RFC 2026-06-03; Storage now ensures a default Company warehouse and lets admins change it. |
| Ticket Manual Costs | Done | Codex | Approved RFC 2026-06-03; Ticket costs now support manual non-stock entries alongside Storage reservations. |

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
- Personal company default/light/dark/system theme preference after branding.

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

### 3. Company Theme System

**Status:** Done
**Owner:** Codex
**Domain:** System / UI
**Goal:** Finish branding as a proper theme system.

Initial scope:

- Keep Branding as its own admin view under System.
- Keep brand/action colors separate from layout surface colors.
- Add configurable header background/text, page header background/text, footer background/text, body background, content background, sidebar background/text, card background, and border color.
- Add light theme and dark theme surface sets.
- Let company default theme be `light`, `dark`, or `system`. Done.
- Let technician preference choose `company default`, `light`, `dark`, or `system`. Done.
- Update CSS variables so shell layout never depends on hardcoded brand colors.
- Add tests and Knowledge documentation.

Future scope:

- Full Bootstrap component theming beyond the current shell, card header, and primary/secondary buttons.

### 4. Reporting Domain Foundation

**Status:** Done
**Owner:** Codex
**Domain:** Report / Platform
**Goal:** Create a proper reporting system for cross-domain reports.

Why this is needed:

- `/tech/reports` started as a global placeholder, not a real report module.
- Ticket SLA reporting is currently owned by the Ticket module as a pragmatic beta step.
- Future reports need consistent navigation, permissions, filters, exports, saved views, and ownership.

Initial scope:

- Use `Report` as the domain name unless an RFC decides otherwise.
- Create a report registry where modules can register report entries.
- Let domain modules own their report data/query logic while the Report domain owns the hub, shell, navigation, permissions, filters, and export behavior.
- Move or register the Ticket SLA report through the Report domain.
- Document report ownership rules in architecture docs and Knowledge.

### 5. Module Settings Audit

**Status:** Done
**Owner:** Codex
**Domain:** Platform / All Existing Domains
**Goal:** Audit existing modules for beta-critical settings and hardcoded behavior.

Initial scope:

- Check System, User Management, Clients, Contacts, Tickets, Email, Inbox, Calendar, Notification, Knowledge, Nextcloud, Commercial, Sales, Economy, Storage, Assets, and Tasks.
- Identify behavior that is currently hardcoded but should be configurable.
- Verify settings live in the correct domain and are reachable from Admin or Profile where appropriate.
- Verify defaults exist for clean installs.
- Verify permissions protect settings routes.
- Update `docs/TODO.md` with scoped follow-up items instead of starting large unrelated fixes.
- Update Knowledge documentation when the audit changes documented behavior.

Audit output:

- `docs/audits/2026-06-01-module-settings-audit.md`

### 6. Admin Settings Discoverability Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** System / Admin Navigation
**Goal:** Make existing beta-ready settings surfaces discoverable from the Admin hub and sidebar.

Initial scope:

- Add Calendar settings to Admin landing page and admin side navigation.
- Add Notification channels to Admin landing page.
- Add Nextcloud settings to Admin landing page.
- Add integration-specific links for N-able RMM, Tactical RMM, and BookStack to Admin landing page.
- Add User roles, permissions, and two-factor settings to Admin landing page.
- Add Ticket assignment rules and technician assignment settings to Admin landing page.
- Do not add links to unfinished settings surfaces.
- Add/adjust tests for Admin hub visibility.

### 7. Visible Unfinished UI Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Existing Domains
**Goal:** Remove or implement visible beta UI that advertises unfinished behavior.

Initial scope:

- Remove or replace Asset detail "Feature coming soon" related-ticket text.
- Review N-able RMM "Fetch network equipment (Coming soon)" card and either hide it or implement useful disabled/help behavior.
- Review integration cards for buttons/toggles that expose unfinished functionality.
- Review login branding and old `tdPSA` placeholder under branding/naming cleanup.
- Add tests where behavior changes.

Completed:

- Asset detail now shows a neutral related-ticket empty state without promising unfinished behavior.
- N-able manual sync no longer exposes the network-device coming-soon action.
- Login views now use Bootstrap, current company branding defaults, and neutral email placeholder text.
- Commercial Contract/Service settings routes now render working admin hub pages instead of legacy view specifications.
- Contract create no longer renders appended legacy specification text.
- Sales lead detail now renders a working beta detail page instead of a legacy "not started" specification.
- Email account health check jobs now persist real IMAP/SMTP test results instead of unconditional OK placeholders.
- Scheduled BookStack pull/push jobs now surface missing server/token/actor configuration in integration health.
- Commercial Contract settings route now uses `/tech/admin/settings/cs/contracts`; the old `/contacts` typo redirects.
- Commercial Units creation now uses POST with CSRF instead of a GET route that created database rows.
- Commercial Cost deletion now uses DELETE instead of a GET route.
- Queue and Worker setup examples now render the current Laravel `base_path()` in the UI instead of a hardcoded development path.

### 8. Asset Settings Slice

**Status:** Done
**Owner:** Codex
**Domain:** Asset
**Goal:** Add beta-ready Asset settings for behavior that works immediately.

Completed:

- Asset settings route: `/tech/admin/settings/assets`.
- Settings storage in `common_settings` with `type=asset` and `name=defaults`.
- Admin can configure enabled asset types, default asset type, default IP mode, and default manual status.
- Manual Asset form and HTTP fallback create/update paths use the settings.
- Asset Knowledge documentation added.

### 9. Contact Settings Slice

**Status:** Done
**Owner:** Codex
**Domain:** Contact
**Goal:** Add beta-ready Contact settings for defaults and relation choices.

Completed:

- Contact settings route: `/tech/admin/settings/contacts`.
- Settings storage in `common_settings` with `type=contact` and `name=defaults`.
- Admin can configure default contact type, default status, default relation type, and enabled relation types.
- Contact form and StoreContact action use the settings.
- Duplicate protection remains mandatory.

### 10. Legacy Planning Files Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Documentation
**Goal:** Move planning/specification Markdown out of production view paths.

Initial scope:

- Move `resources/views/tech/tasks/*` planning files into `docs/` or module `Docs/`.
- Move `resources/views/tech/admin/billing/*` planning files into `docs/` or module `Docs/`.
- Move or delete obsolete `app/Modules/*/Views/**/*.md` and `*.blade.md` files.
- Keep production view paths limited to renderable Blade/PHP views.
- Verify route rendering and tests after cleanup.

Completed:

- Moved resource view specs to `docs/legacy/view-specs/resources/views`.
- Moved module view specs to `app/Modules/{Domain}/Docs/legacy-view-specs`.
- Updated runtime documentation file references in Asset, Storage, and Integration views/controllers.
- Verified no `.md` or `.blade.md` files remain under `resources/views` or module `Views` folders.
- Moved remaining task, task-template, and billing view specifications out of `resources/views`.
- Moved remaining Commercial contract and Sales lead module view specifications into module legacy docs.
- Removed empty unused runtime Blade files from Clients, Commercial, Sales, Ticket, and global admin settings paths.

### 11. Missing Settings Ownership RFC

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Existing Domains
**Goal:** Decide settings ownership for active modules that do not yet have clear settings surfaces.

Initial scope:

- Create one RFC covering Asset, Contact, Knowledge, Risk, Task, Warroom, and Report settings ownership.
- Define which settings are beta-critical versus post-beta.
- Decide admin route placement and permission names.
- Define default seed behavior for clean installs.
- Define Knowledge documentation requirements.

Progress:

- RFC created and approved: `docs/rfc/2026-06-01-module-settings-ownership.md`.
- Asset Settings slice completed.
- Contact Settings slice completed.
- Task Settings slice completed.
- Warroom Settings slice completed.
- Knowledge Settings slice completed.
- Risk Settings slice completed.

### 12. Domain API Foundation

**Status:** Done
**Owner:** Codex
**Domain:** API / Platform / All Existing Domains
**Goal:** Define and implement consistent API surfaces for domains that need external integration access.

Why this is needed:

- Nexum PSA has focused heavily on UI workflows, but external integrations, automation, mobile clients, and future AI tooling need stable APIs.
- API ownership, authentication, permissions, versioning, validation, rate limiting, and documentation must be consistent before each domain invents its own API style.

Initial scope:

- API ability naming and ownership is defined in the Integration module.
- Visible "Scopes coming soon" UI was replaced with working Sanctum abilities.
- Client/Site, Asset, Contact, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox,
  Notification, Sales, Taxonomy, Commercial, Economy, Report, User Management, and Custom Fields
  API scopes are implemented.
- Client create/update API supports `custom_fields`.
- Custom field definitions are exposed through a read-only discovery API.
- Current API routes are documented in Integration Knowledge documentation.
- OpenAPI documentation is generated for the current beta API surface.
- Representative auth, ability, validation, and route tests exist across the domain modules.

Future scope:

- Add domain APIs for future modules as they become beta-ready.
- Add richer filtering, includes, bulk operations, and webhooks when there is a concrete workflow.
- Add stricter service-token governance if external automation grows beyond scoped Sanctum tokens.

### 13. Report Builder And Scheduled Client Reporting

**Status:** Post-Beta
**Owner:**
**Domain:** Report / Client / Notification / Email
**Goal:** Let admins build reusable reports and schedule automatic delivery to clients.

Initial future scope:

- Custom report builder with selectable data sources, filters, grouping, and columns.
- Saved report templates.
- Client-specific scheduled reporting.
- Delivery through email and, later, customer portal surfaces.
- Per-client report preferences and recipient lists.
- Report preview before sending.
- Delivery history and failure tracking.
- Permissions for creating, editing, scheduling, and sending reports.

### 14. Email Branding And HTML Template Editor

**Status:** Post-Beta
**Owner:**
**Domain:** Email / System / Branding
**Goal:** Make outbound email templates brand-aware and easier to edit safely.

Initial scope:

- Add global branding variables to `EmailTemplateRenderer`, such as `company_name`, `company_logo_url`, `brand_primary`, `brand_secondary`, `brand_accent`, `support_email`, and `website`.
- Add a shared HTML email wrapper/layout so seeded templates do not each duplicate branding chrome.
- Keep plain text output clean and readable without HTML styling.
- Build an HTML email template editor with live preview.
- Preview should render with sample variables and current company branding.
- Update seeded templates to use the shared brand-aware structure.
- Document supported template variables per scope.
- Add tests for rendering, branding fallback, and preview behavior.

Future scope:

- Dedicated email-specific branding fields if web theme colors are not suitable for email clients.
- Per-client, per-language, per-queue, or per-workflow template selection.
- Safer variable validation and missing-variable warnings.

### 15. Ticket Workflow Requirements Enforcement

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

### 14. Ticket Knowledge Follow-Up

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

### 15. Contract SLA UI Polish

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

### 16. SLA Reporting Foundation

**Status:** Done
**Owner:** Codex
**Domain:** Ticket / Reports  
**Goal:** Start basic operational reporting for SLA.

Initial scope:

- Query counts for response overdue, resolve overdue, responded within SLA, resolved within SLA.
- Start with a simple tech/admin report page or rightbar summary.
- Use ticket timestamps already available.
- Keep business-hours calculations out of first pass unless explicitly needed.
- Added `/tech/reports/tickets/sla` and linked it from the Reports hub.

### 17. Storage Barcode Scanning

**Status:** Post-Beta
**Owner:**
**Domain:** Storage
**Goal:** Support barcode-driven storage workflows from both desktop and mobile.

Initial scope:

- PC barcode scanners that behave like keyboard input.
- Mobile camera scanning for warehouse and technician workflows.
- Barcode lookup for storage items, boxes, reservations, picking, and stock adjustments.
- Settings for barcode formats and duplicate handling.
- Manual search fallback when barcode scanning is not available.

### 18. AI Tool Hardening For Tickets

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
