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
- `In Review`: the proposed direction is being reviewed before implementation approval.
- `In Progress`: someone is actively working on it.
- `Blocked`: needs a decision or prerequisite.
- `Done`: implemented, tested, and documented.

## Active Workstreams

| Area | Status | Owner | Notes |
| --- | --- | --- | --- |
| Beta completion | In Progress | Svein / Codex | Finish and harden existing modules before starting large new domains. |
| Simplified And Automatic Storage Supplier Order AI | Ready for Review | Svein / Codex | The approved original RFC/ADR plus operational RFC `docs/rfc/2026-08-11-operational-supplier-order-automation-setup.md` and bootstrap RFC/slice `docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md` / `docs/feature-slices/2026-08-11-storage-automatic-ai-profile-bootstrap.md` remove manual workload/user setup and technical controls from the ordinary form. Administrators choose order handling, warehouse, Supplier/Item behavior, AI use, one Storage agent, business limits, and notifications. A trusted valid email without a profile can now use a Storage-owned candidate contract to create one active Supplier, protected reusable profile, active/orderable Items, and an editable ordered Purchase Order; retries and close first messages reuse identities, and only Receiving changes stock. Dev policy revision 11 is automatic profile-or-AI with fallback agent 5 on standard `gpt-5.5`, warehouse 2, one-sample verified activation, active Supplier/Item creation, max 250 new Items, and green readiness. A rolled-back real-provider bootstrap passed in about 21 seconds with zero receipt/inventory deltas. Focused verification passes 94 / 971; the full Storage suite passes 257 / 2,775 with one expected skipped opt-in MariaDB contract. Human review `HR-2026-08-10-003` remains In Review. |
| Storage Incoming Purchase Quantity Visibility | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-10-storage-incoming-purchase-quantity-visibility.md` adds one sortable Inventory **Incoming** quantity derived from positive outstanding lines on active ordered/partially received Purchase Orders. The row shows **On order** while incoming quantity is positive; drafts, received/closed/cancelled/deleted orders and received/cancelled line quantities do not inflate it. Storage-only users see the aggregate without gaining Purchase Order identity or actions. The reported item `IMP-18-1324745-5BA90BDA` now projects 1 incoming from `AUTO-2026-00000011`. Focused Storage verification passes 23 tests / 360 assertions; the full Storage suite passes 251 tests / 2,600 assertions with one expected skipped MariaDB contract. No migration, API, permission, queue, scheduler, or frontend build change is required. Human review `HR-2026-08-10-002` remains pending. |
| Storage Unified Supplier Orders List | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-10-storage-unified-supplier-order-list.md` consolidates Supplier Order Imports, Purchase Orders, and Receiving into one permission-aware operational list without changing data, APIs, permissions, receipt posting, or inventory effects. Manual and email-created orders now share the same canonical detail; an authorized email order adds only a sanitized Email Copy card at the bottom after Shipments and Receipt History, while item lines, shipments, tracking, receipts, and actions remain identical. Trusted Authentication, SPF, DKIM, DMARC, and alignment remain internal and are not displayed on either detail page. Focused import UI verification passes with 7 tests / 130 assertions; the full Storage suite passes with 250 tests / 2,588 assertions and one expected skipped MariaDB contract. Knowledge is updated and human review `HR-2026-08-10-001` remains pending. |
| Storage Supplier Email Purchase Order Automation | In Review | Svein / Codex | The approved Level 3 RFC, ADR, nine Feature Slices, migrations `100000`-`113000`, seeders, 17 Nexum Knowledge articles, isolated `supplier-orders` runtime, and locked inbound Email poller/worker are deployed on Dev. Exact inline-forward routing, Email rule 10, Signal rule 2, Itegra profile row 1/version row 7 (version 4), warehouse 2, and policy revision 3 remain active only in `shadow`. The first real forward produced EmailMessage 51, Signal 10, and import 2 with deterministic `shadow_complete`: one unresolved line and no Item, PO, receipt, Movement, or stock write. The original poll missed UID 1447 and stored no authentication headers; both defects are fixed with ordered raw-header parsing plus a persistent forward-only `UIDVALIDITY`/UID baseline that ignores historical unread mail and drains new bursts oldest-first. An intermediate unread-catch-up safety test unintentionally imported 240 historical messages, creating 179 Tickets, 42 Signals, 18 rule logs, and one database-only assignment notification; it sent no outbound email and produced no failed jobs. Polling was contained, corrected, live-verified, and re-enabled, but these accidental rows remain untouched pending explicit cleanup authorization. A bounded mailbox calibration on 2026-08-07 added five inactive draft profiles with six protected real fixtures for Dustin, iFixit, MyTrendyPhone, Ecoengros, and IPC-Computer. It also added a validated but inactive Itegra version 5 candidate with four real fixtures while active version 4 remained unchanged. Nine calibration imports retained 12 reviewed lines in `needs_attention` / `validate`; the guarded transaction created no Vendor, Item, PO, shipment, receipt, Stock Unit, Movement, on-hand, Email rule, Signal, Ticket, queue, or failed-job side effect. NDI is PDF-only, 3DJake lacks normalized line prices, and the available Allnet sample lacks order lines, so no passing profiles were created for those formats. A second ordinary-poll capture, broader Itegra coverage for multi-line orders, quantity above one, and freight or discount variation, verified Plesk trust boundary, least-privilege actor, Item mapping, human review `HR-2026-08-04-003`, external BookStack push, and authenticated browser QA remain open; AI also requires a governed provider smoke if enabled. The approved ninth Feature Slice is implemented on Dev with one shared manual/email PO identity: exact supplier plus supplier order number, database-enforced across soft-deleted history, manual-first vendor confirmation without overwrites or inventory effects, fail-closed material and source-projection conflicts, locked post-confirmation identity, and accessible provenance in the canonical Purchase Orders list. Migration `2026_08_07_100000_add_supplier_order_identity_to_purchase_orders.php` remains in batch 62, and forward-only migration `2026_08_07_101000_add_database_generated_supplier_order_identity_key.php` ran in batch 63. The authoritative guard is a database-generated normalized key plus composite supplier/key unique index; the old hash unique index is removed, while the obsolete nullable hash column remains unindexed for a later reviewed cleanup. Sanitized preflight found one PO, zero populated supplier order numbers, and zero collisions. A live two-connection MariaDB 10.6.23 contract verified raw insert/update rejection, supplier scoping, blank identities, Unicode-consistent normalization, and locking-read race recovery beyond an older REPEATABLE READ snapshot. Focused identity/import tests pass 27 tests / 192 assertions; affected AI/integrity/policy/purchase tests pass 71 / 622; the full Storage suite passes 247 / 2,528; and all remaining application tests pass in bounded groups with 978 / 7,619. The opt-in PHPUnit MariaDB contract is skipped without dedicated credentials, while its equivalent live Dev contract passed. Cache clearing, Blade compilation, Pint, three protected-route HTTP smoke checks, the six-article Storage Knowledge sync, bounded worker/cron check, and zero failed-jobs check pass. Authenticated browser QA and the named human checks in `HR-2026-08-04-003` remain open. |
| Storage Supplier Shipment Confirmation Email Automation | Blocked |  | Shipment-confirmation messages were retained only as future calibration corpus; no shipment-email profile, Email rule, Signal action, shipment, tracking, receipt, or inventory mutation was created. The current supplier-order profile contract handles order confirmations only. Define and approve a separate Level 3 RFC, ADR, and Feature Slices before adding shipment-confirmation routing or mutation. Shipment email processing must never imply physical receipt or update stock automatically. |
| Storage Supplier Order Legacy Identity Hash Cleanup | Planned | Svein / Codex | After `HR-2026-08-04-003` and the rollback window are complete, add a forward migration that removes the now-unindexed `supplier_order_identity_hash` column. The generated `supplier_order_identity_key` and composite supplier/key unique index are authoritative; this cleanup must not weaken or recreate that invariant. |
| Email IMAP Historical Import And Cursor Recovery | Ready |  | Add an explicit permissioned historical-mail import tool with preview/scope/caps and a visible operator action for re-baselining an account after `UIDVALIDITY` changes. Automatic polling must remain forward-only and must never infer backlog from unread state. |
| Storage Purchase Orders, Shipping, And Receiving | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md` and all four linked Feature Slices are implemented, migrated, seeded, documented, and Dev-tested. The workflow covers externally placed supplier orders, multiple shipments/tracking identifiers, the Documentation-owned carrier register, partial accepted/rejected receiving, immutable receipts/reversals, and atomic inventory movements. Human review remains `HR-2026-08-04-001`; automatic vendor ordering and live carrier polling remain out of scope. |
| Storage Inventory Sortable Tables | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-04-storage-inventory-sortable-tables.md` and both Feature Slices are implemented across all eleven read-only Storage queue, Admin, and detail/history surfaces. Sorting is allowlisted, accessible, null-last, filter-preserving, and stable while workflow forms/control slips retain their original order. The complete Storage suite passes with 95 tests / 1,233 assertions and the full Laravel suite with 1,027 / 8,558. Human review remains `HR-2026-08-04-002`; no migration, API, queue, scheduler, or frontend build is required. |
| Composer Dependency Security Advisories | In Review |  | `composer audit --locked` on Dev reported 45 advisories across 15 locked packages on 2026-07-29, including high-severity framework, mail, HTTP, PDF, spreadsheet, and test-tool findings. Plan an approved dependency-hardening change, classify runtime versus development exposure, update within supported constraints, run the complete test/build/audit suite, and document any residual risk. Do not fold a broad framework/package upgrade into an unrelated feature delivery. |
| Web Push And Inbound Email Alerts | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md` and accepted ADR `docs/adr/2026-07-23-notification-owned-web-push-channel.md`. All three feature slices are implemented on Dev: device/channel foundation, inbound Email/customer-reply delivery, and source read-sync rollout hardening. Dev has a cron-managed `email,default` queue worker plus direct `email:poll --account=1`; the full Laravel scheduler runner remains a separate Operations concern. Production enablement still requires named human browser/device and end-to-end checks in `HR-2026-07-24-001` and `HR-2026-08-11-002`. |
| One Responsive Nexum PWA Final Browser Acceptance | Blocked | Svein / Codex | GitHub Discussion #169's foundation and Notification/Web Push slices are implemented and automated source-contract tests now guard PWA metadata, viewport tags, mobile offcanvas shell, and the shared online-first service worker. Final closure is blocked until the new trusted HTTPS Dev vhost is available and the named human checks in `HR-2026-08-11-003`, `HR-2026-07-24-001`, and `HR-2026-08-11-002` pass. Use `docs/deployment/dev-https-pwa-vhost.md` for the vhost checklist. |
| AI Model Usage And Cost Telemetry | In Progress | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-27-ai-model-usage-and-cost-telemetry.md` and accepted ADR `docs/adr/2026-07-27-integration-owned-ai-model-execution-telemetry-boundary.md`. The execution-contract/usage-ledger slice is implemented and migrated on Dev. Remaining ordered slices cover direct-call-path migration and feature keys, versioned rate cards, Admin reporting/retention, and later optional budgets. Human review is `HR-2026-07-27-001`. |
| Dev Queue And Scheduler Runtime | Blocked | Operations | Supplier Order Automation now has its own isolated `/var/Projects/tdPSA` crontab runtime with a locked `supplier-orders` database worker and dedicated dispatch, heartbeat, health, retention, and digest jobs. This removes the runtime prerequisite only for the supplier-order shadow rollout. A general authoritative Dev queue/scheduler runtime for Web Push and ordinary Email/notification scheduling remains unverified, so this cross-application Operations item stays `Blocked` and must not be marked `Done` from the supplier-specific runtime alone. |
| CloudFactory Partner Integration | Ready for Live Validation | Svein / Codex | Core integration and the versioned legal-document/Customer-admin portal-ordering slice are implemented under approved RFC `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`. Provider documents are immutable and read-only, Nexum terms remain additive, contracts and portal writes retain versioned acceptance evidence, and monthly catalogue sync performs the legal check. Remaining gates are the existing production validation in `HR-2026-07-20-001` and the focused Dev legal/portal review in `HR-2026-07-22-001`. |
| Between Competitor Parity Audit | Ready |  | Use `docs/audits/2026-07-03-between-competitor-gap-analysis.md`, `docs/ideas/`, and the 2026-07-04 draft RFC batch as planning input for booking, field-service mobile/PWA, payments/accounting, SMS automation, departments/service areas, resources, and feedback. Do not implement Level 2/3 parity work without approved RFCs. |
| Notification SMS Channel Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-notification-sms-channel-automation.md`; first slice `docs/feature-slices/2026-07-05-notification-sms-dry-run-foundation.md` adds dry-run transactional SMS configuration, templates, audit logs, manual admin test send, and Contact phone consent guards. Production providers and workflow automation remain later slices. |
| One Responsive Nexum PWA Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-one-responsive-nexum-pwa.md`; app-wide PWA metadata, online-first service worker/offline page, mobile tech offcanvas navigation, `/tech/my-day`, UI guidelines, Knowledge docs, Dev smoke checks, focused Dev tests, and Notification-owned inbound Email Web Push/read-sync slices are complete. Offline write queues remain intentionally unavailable unless a future workflow gets an approved conflict/sync design. |
| Public Inquiry Forms Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-public-inquiry-forms.md`; first slice adds Intake-owned public forms, file uploads, admin review, matching, guarded Sales routing, Knowledge docs, Dev migration/seeding, and tests. |
| Intake Signal Post-Submit Automation | Done | Codex | Approved RFC `docs/rfc/2026-07-05-intake-signal-post-submit-automation.md`; Intake now emits post-submit Signal events and Signal rules can trigger Ticket, Task, Portal invitation, Sales follow-up, and webhook actions. |
| Intake Final Routing And Review | Done | Codex | Feature slice `docs/feature-slices/2026-08-11-intake-final-routing-review.md` completes Discussion #166 scope with published/paused form lifecycle, purpose/language/scope metadata, explicit routing modes, form/field snapshots, direct Sales/Ticket/Task routing, manual review outcomes, existing-record linking, Knowledge docs, migration, and focused Intake tests. Human review is `HR-2026-08-11-001`. |
| Email/Ticket Rules Signal Alignment | Done | Codex | Approved RFC `docs/rfc/2026-07-08-email-ticket-signal-rule-alignment.md`; Email Rules and Ticket creation rules can now explicitly emit Signal records, Signal-created tickets skip Ticket Signal handoff to avoid loops, Knowledge sync includes Signal docs, and focused Dev tests passed. |
| Storage Orderable Over-Reservations | Done | Codex | Approved RFC `docs/rfc/2026-07-08-storage-orderable-over-reservations.md`; GitHub issue #177 is implemented so active orderable Storage items can be reserved beyond available stock, not-orderable items keep the available-stock guard, picking remains blocked until stock is on hand, and Knowledge docs/tests were updated. |
| Ticket Storage Reservation Release | Done | Codex | Approved RFC `docs/rfc/2026-07-21-ticket-storage-reservation-release.md`; explicit removal from inside Edit cost and quantity-zero release share one audited transaction that frees reserved stock, removes Picking List work, and restores linked approved planned lines. All affected Dev suites and rendered-view checks passed; human review `HR-2026-07-21-001` remains pending. |
| Customer Portal Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-customer-portal-foundation.md` with ADR `docs/adr/2026-07-04-customer-portal-identity-separation.md`; first slice implemented with portal accounts, memberships, invitations, audit events, portal dashboard, docs, Dev migration/seeding, and tests. Portal invitations now originate from Contact/Contract workflows, while the Customer Portal admin URL is reserved for future portal settings. Customer-visible domain data remains explicit slices. |
| Customer Portal Ticket Workflow | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`; customer-visible ticket list/create/detail/reply with explicit one-way publishing, manual visibility control, and scope enforcement is implemented. Approved RFC `docs/rfc/2026-07-28-manual-client-ticket-published-default.md` completes GitHub issue #191 by making Published the clean-install fallback while preserving valid admin choices, per-Ticket overrides, internal-Ticket isolation, history, and existing notification behavior. |
| Customer Portal Document Center | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-document-center.md`; explicitly published Documentation records and published public/client-wide Knowledge articles are exposed inside portal scope and Dev-tested. |
| Customer Portal Commercial/Economy Summary | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-commercial-economy-summary.md`; approved/accepted contract summaries and explicitly published economy order summaries are implemented, documented, migrated, and Dev-tested. |
| Customer Portal Quote/Contract Acceptance | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-quote-contract-acceptance.md`; existing Sales quote and Commercial contract acceptance are bound to authenticated portal identity, documented, migrated, and Dev-tested without implementing full CPQ. |
| Customer Portal Notifications | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-notifications.md`; portal notification center, customer-safe notification delivery, preferences, and implemented-domain event emitters are implemented, documented, and Dev-tested under the approved Customer Portal RFC. |
| Online Booking With Calendar Availability | Done | Codex | Approved RFC `docs/rfc/2026-07-04-online-booking-calendar-availability.md`; foundation slice `docs/feature-slices/2026-07-04-online-booking-calendar-availability.md` implements public Calendar-backed requests and staff confirmation. Follow-up slice `docs/feature-slices/2026-07-28-booking-hours-and-technician-routing.md` completes service opening windows, company/technician hours, fixed/automatic/customer-choice routing, Page Header Back, plain-language spam protection, docs, and tests for GitHub issue #184. |
| Client Workspace Tickets Tab | Done | Codex | Approved RFC `docs/rfc/2026-07-28-client-workspace-tickets-tab.md`; GitHub issue #188 adds the permission-aware Client Tickets tab, required tab order, direct Ticket links, matching count, tests, and Knowledge documentation. Human review is `HR-2026-07-28-003`. |
| Client Summary Layout And Notes Autosave | Done | Codex | Approved RFC `docs/rfc/2026-07-28-client-summary-notes-autosave.md`; GitHub issue #189 adds the responsive Summary metadata grid and permission-protected Notes autosave with honest save states, tests, and Knowledge documentation. Human review is `HR-2026-07-28-004`. |
| Telephony Call Intake v1 | Done | Codex | Discussion #49 implemented with personal provider URL, public token intake, caller matching, call notes, ticket creation/linking, tests, and Knowledge docs. |
| Technician profile consolidation | Done | Codex | UserManagement owns `user_profiles`; Ticket now owns only Ticket Assignment Settings. |
| Ticket assignment settings split | Done | Codex | Legacy Ticket technician profile tables migrated into explicit assignment settings. |
| Ticket SLA v1 | Done | Codex | SLA resolution, contract SLA field, ticket show panel, index SLA risk badges, Knowledge docs. |
| Ticket Actions v1 | Done | Codex | Shared action names, guard, apply SLA action, UI gating, Knowledge docs. |
| Ticket Workflow v1 | Done | Codex | Default workflow, states, transitions, runtime validation, Ticket show actions, Knowledge docs. |
| Ticket Workflow Editor v2 | Done | Codex | Admin create/edit workflow metadata, states, transitions, and stored requirements. |
| Ticket Workflow v3 Conditional Actions And Escalation | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`; all eight 2026-07-17 Feature Slices plus the 2026-07-18 customer notification and automatic migration-placement slices are implemented. Workflow supports Signal-style grouped requirements, versioned steps, server-enforced action/API parity, escalation and eligible assignment, senior review/evidence, Ticket-origin Sales quotes, planned scope, controlled fulfilment/Economy closure, Published-only transition notifications, and requirement-based per-Ticket placement when active work is migrated to a new version. Dev migration, focused cross-module tests, Knowledge/BookStack sync, and human review are tracked under `HR-2026-07-17-001`. |
| Ticket Solution Policy | Done | Codex | Approved RFC 2026-06-03 keeps internal solution notes enabled by default and admin-configurable. Follow-up RFC `docs/rfc/2026-07-28-ticket-internal-note-solution-toggle.md` completes GitHub issue #190 by replacing the duplicate composer type with a policy-protected Mark as solution switch that retains technician notification. Human review is `HR-2026-07-28-005`. |
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
| Contact Settings Slice | Done | Codex | Contact owns defaults and relation types. Follow-up slice `docs/feature-slices/2026-07-28-contact-portal-invitation-override.md` adds the approved global portal-invitation default and create-only per-Contact override for GitHub issue #185. |
| Legacy Planning Files Cleanup | Done | Codex | Moved Markdown planning/spec files out of production view paths and updated runtime doc references. |
| Task Settings Slice | Done | Codex | Task module now owns manual task defaults for status, priority, and estimate. |
| Warroom Settings Slice | Done | Codex | Warroom now owns dashboard windows, list limits, and visible panels. |
| Knowledge Settings Slice | Done | Codex | Knowledge now owns manual article defaults for visibility, status, review, and priority. |
| Risk Settings Slice | Done | Codex | Risk now owns defaults for assessments, item scoring, item status, and review interval. |
| Missing Settings Ownership RFC | Done | Codex | RFC approved; Asset, Contact, Task, Warroom, Knowledge, and Risk settings slices completed. |
| Ticket Customer Completion API | Done | Codex | GitHub issue #194 and approved RFC `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`; API coordinators can publish eligible Tickets, send idempotent customer replies including `send_solution`, inspect decisions, transition to Resolved, and close through existing guarded actions. Dev migration, OpenAPI, Knowledge, regression tests, and human review `HR-2026-07-29-002` are recorded. |
| Organization-controlled AI and coordinator access | In Review | Codex | GitHub issue #178 and approved RFC `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`; least-privilege keys, installation/provider/model/agent/workload policy, privacy gateway enforcement, workload tokens, metadata audit, and minimized worklog/stale APIs are implemented. Controlled provider smoke and human review remain under `HR-2026-07-29-012`. |
| Calendar Ownership View Metadata | Done | Codex | GitHub issue #137 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-view-metadata.md`; Calendar overlays and APIs expose stable calendar-owner/type/group metadata without broadening visibility, and the single-event API now applies private-detail masking. Human review is `HR-2026-07-29-003`. |
| Calendar Owner Badges And Accessible Color | Done | Codex | GitHub issue #138 and Feature Slice `docs/feature-slices/2026-07-29-calendar-owner-badges-accessible-color.md`; day/week/month/list use one privacy-safe owner badge with text, color swatch, accessible owner/type label, truncation, and narrow-screen overflow. Human review is `HR-2026-07-29-004`. |
| Calendar Type Indicators | Done | Codex | GitHub issue #139 and Feature Slice `docs/feature-slices/2026-07-29-calendar-type-indicators.md`; non-personal events use shared accessible type badges in all four views without weakening private masking. Human review is `HR-2026-07-29-005`. |
| Calendar Ownership Filters | Done | Codex | GitHub issue #140 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-filters.md`; server-scoped groups and `Only mine` filter by Calendar owner, preserve navigation/search state, intersect explicit Calendar selections, and expose clear empty states. Human review is `HR-2026-07-29-006`. |
| Calendar Mobile Readability | Done | Codex | GitHub issue #141 and Feature Slice `docs/feature-slices/2026-07-29-calendar-mobile-readability.md`; month/week retain accessible scrolling and dense month days expose a filtered `+N more` drill-down. Human review is `HR-2026-07-29-007`. |
| Calendar Ownership Rollout Tests And Knowledge | Done | Codex | GitHub issue #142 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-rollout-tests-knowledge.md`; regression coverage, Calendar docs, TODO, website handoff, and manual review tracking complete the rollout. Human review is `HR-2026-07-29-008`. |
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

### 18. Storage Unit-Aware Picking And Adjustments

**Status:** Post-Beta
**Owner:**
**Domain:** Storage / Ticket
**Goal:** Replace the conservative safety blocks for identified inventory with explicit unit selection.

Initial scope:

- Add serial selection and batch/expiry-aware quantity allocation to Ticket picking.
- Add unit-aware inventory corrections that update Item, StockUnit, and Movement atomically.
- Define FEFO defaults and an authorized override for expiry-controlled stock.
- Preserve provenance and location when moving, consuming, or correcting identified units.
- Keep generic quantity-only adjustment and picking blocked for identified stock until this workflow exists.

### 19. AI Tool Hardening For Tickets

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
