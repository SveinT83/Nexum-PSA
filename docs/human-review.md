# Human Review Register

This file is the persistent source of truth for human verification of substantial Nexum PSA
changes. It records what a person still needs to check, what failed, and what a named human reviewer
has explicitly approved.

## Working Rules

- Add one entry for every Level 2 or Level 3 change, completed Feature Slice, migration or data
  change, permission or integration change, cross-module update, substantial user-visible workflow,
  and broad merge or release candidate.
- Use a stable ID in the form `HR-YYYY-MM-DD-NNN` and update the same entry as review progresses.
- Valid statuses are `Pending`, `In Review`, `Rework Needed`, `Reviewed`, and `Superseded`.
- `[ ]` means outstanding and `[x]` means explicitly checked by a human. Record `N/A` with a reason
  when a check does not apply.
- Automated tests, code review, deployment, migration, or silence never changes the status to
  `Reviewed`.
- Only explicit confirmation from a named human reviewer may set `Reviewed`. Record the reviewer,
  date, environment, and any accepted deviations.
- Keep failed checks and follow-up notes on the same entry. Move the status to `Rework Needed` until
  the defect is fixed and rechecked.
- Before a merge, migration, deployment, or release, report every relevant entry that is not
  `Reviewed`.
- Never delete reviewed entries. Add newer entries above older entries and retain the history.
- Global human-review confirmation: Svein explicitly approved all entries that were only waiting for human review on 2026-08-25. Entries marked `Rework Needed`, including compound statuses containing rework, remain open. Runtime activation, deployment, migration, and production evidence gates remain separate unless an entry explicitly records an accepted deviation.

| HR-2026-08-25-003 | AI Model Usage and Cost Telemetry (Slices 1-3) | Pending | 2026-08-25 |  |  |
| HR-2026-08-25-002 | RoleSeeder Reconciliation and Permission Sync | Reviewed | 2026-08-25 | Svein | 2026-08-25 |
| HR-2026-08-25-001 | One-time scheduled tickets with SLA deferral (Slice 1) | Reviewed | 2026-08-25 | Svein | 2026-08-25 |

### HR-2026-08-25-003: AI Model Usage and Cost Telemetry (Slices 1-3)

- **Scope:** Integration of AI model execution trace, versioned rate cards, and decimal cost calculation.
- **Affected Modules:** Integration, Lead Intelligence, Nextcloud.
- **Checks:**
  - [ ] Verify that `AiModelRateCard` can be created via Tinker/Admin (until CRUD is added).
  - [ ] Verify that `AiUsageRecorder` correctly calculates `calculated_cost` using an active rate card.
  - [ ] Verify that AI Telemetry index page (`/admin/system/integrations/ai/telemetry`) shows recent executions.
  - [ ] Verify that cost breakdowns are visible on the Telemetry Show page.
  - [ ] Verify that Lead Intelligence (web search, review) records telemetry traces.
  - [ ] Verify that Nextcloud (folder matching) records telemetry traces.
- **Expected Results:** Every AI call should result in a usage event with calculated costs if a rate card exists.
- **Risks:** Cost calculation depends on exact or pattern matching of model names.
- **Status:** Pending

### HR-2026-08-25-002: RoleSeeder Reconciliation and Permission Sync

- **Scope:** Backend and UI for one-time scheduled tickets, allowing deferral of SLA due dates until the planned start time.
- **Affected Pages:** Ticket Create, Ticket Edit, Ticket Show, Ticket Index.
- **Checks:**
  - [x] Verify that "Schedule for later" toggle exists on Ticket Create and Edit pages.
  - [x] Verify that checking "Schedule for later" reveals start time and SLA mode fields.
  - [ ] Verify that a scheduled ticket shows the "Scheduled" badge with start time on the Show page.
  - [ ] Verify that a scheduled ticket shows a calendar icon on the Index page.
  - [x] Verify that SLA due dates are deferred until `planned_start_at` when `defer_until_planned_start` mode is selected.
  - [x] Verify that existing SLA is preserved when adding a schedule to an existing ticket (per RFC).
  - [x] Verify that removing a schedule from a ticket deletes the schedule record.
- **Expected Results:** One-time scheduling should be fully functional in the UI and correctly influence SLA calculations.
- **Risks:** SLA calculations might be affected if timezone handling is inconsistent.
- **Status:** Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25

## Review Summary

| ID | Update | Status | Added | Reviewer | Reviewed |
| --- | --- | --- | --- | --- | --- |
| HR-2026-08-25-003 | AI Model Usage and Cost Telemetry (Slices 1-3) | Pending | 2026-08-25 |  |  |
| HR-2026-08-25-002 | RoleSeeder Reconciliation and Permission Sync | Reviewed | 2026-08-25 | Svein | 2026-08-25 |
| HR-2026-08-25-001 | One-time scheduled tickets with SLA deferral (Slice 1) | Reviewed | 2026-08-25 | Svein | 2026-08-25 |
| HR-2026-08-25-003 | BookStack shared rate-limit coordination and error timestamp | Reviewed | 2026-08-25 | Svein | 2026-08-25 |
| HR-2026-08-24-004 | Email template HTML editor and branding-managed layouts | Reviewed | 2026-08-24 | Svein | 2026-08-25 |
| HR-2026-08-24-003 | Commercial Contract customer document and pricing consistency | Reviewed | 2026-08-24 | Svein | 2026-08-25 |
| HR-2026-08-24-002 | Evergreen Marketing contact sequences and lifetime no-resend delivery guard | Reviewed | 2026-08-24 | Svein | 2026-08-25 |
| HR-2026-08-24-001 | Ticket owner, customer, and contact at a glance | Reviewed | 2026-08-24 | Svein Tore | 2026-08-24 |
| HR-2026-08-21-001 | Dev database recovery and Mail permission-repair verification | Reviewed | 2026-08-21 | Svein | 2026-08-25 |
| HR-2026-08-16-014 | Email/Ticket correlation conflict triage | Reviewed | 2026-08-16 | Svein | 2026-08-25 |
| HR-2026-08-16-013 | Email/Ticket conversation relationship migration | Reviewed | 2026-08-16 | Svein | 2026-08-25 |
| HR-2026-08-16-012 | Email conversation acknowledgement and explicit multi-account actions | Pending (Safety Rework; Activation Gated) | 2026-08-16 |  |  |
| HR-2026-08-16-011 | Email compose, draft, send, and Sent API parity | Reviewed | 2026-08-16 | Svein | 2026-08-25 |
| HR-2026-08-16-010 | Email deterministic rules API completion | Rework Needed / Safety Repair Implemented | 2026-08-16 |  |  |
| HR-2026-08-16-009 | Email presence, shared draft locks, and stale-composer protection | Reviewed | 2026-08-16 | Svein | 2026-08-25 |
| HR-2026-08-16-008 | Email private live invalidation and polling fallback | Rework Needed | 2026-08-16 |  |  |
| HR-2026-08-16-007 | Email provider-originated read-only reconciliation | Reviewed | 2026-08-16 | Svein | 2026-08-25 |
| HR-2026-08-16-006 | Integration-owned Email provider credentials and endpoint security | Rework Needed | 2026-08-16 | Svein | 2026-08-19; reopened 2026-08-21 |
| HR-2026-08-16-005 | Email canonical message and placement cutover | Reviewed | 2026-08-16 | Svein | 2026-08-19 |
| HR-2026-08-16-004 | Email canonical message shadow correlation | Reviewed | 2026-08-16 | Svein | 2026-08-19 |
| HR-2026-08-16-003 | Email per-user unread baselines and explicit backlog handover | Reviewed | 2026-08-16 | Svein | 2026-08-19 |
| HR-2026-08-16-002 | Email personal mailbox delegation, break-glass, and access history | Reviewed | 2026-08-16 | Svein | 2026-08-19 |
| HR-2026-08-16-001 | Email Mail historical import and UID re-baseline | Reviewed | 2026-08-16 | Svein | 2026-08-19 |
| HR-2026-08-15-007 | Email Mail desktop workspace density and height polish | Reviewed | 2026-08-15 | Svein | 2026-08-25 |
| HR-2026-08-15-006 | Email Mail inbound attachment recovery and download | Rework Needed | 2026-08-15 |  |  |
| HR-2026-08-15-005 | Email Mail Smart Inbox reader-first polish | Reviewed | 2026-08-15 | Svein | 2026-08-25 |
| HR-2026-08-15-004 | Email Mail decoded subject search compatibility | Reviewed | 2026-08-15 | Svein | 2026-08-25 |
| HR-2026-08-15-003 | Email Mail runtime reliability, truthful send follow-up, and right-bar controls | Reviewed | 2026-08-15 | Svein | 2026-08-25 |
| HR-2026-08-15-002 | Email Mail folder hierarchy and subject readability | Reviewed | 2026-08-15 | Svein | 2026-08-25 |
| HR-2026-08-15-001 | Email Mail selected conversation list expansion | Reviewed | 2026-08-15 | Svein | 2026-08-25 |
| HR-2026-08-14-015 | Email Mail provider deletion reconciliation | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-014 | Email Mail supervised Smart Inbox cleanup | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-013 | Email Mail reviewed Smart Inbox actions | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-012 | Email Mail durable Smart Inbox foundation and review queue | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-011 | Email Mail verified remote operation Undo | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-010 | Email Mail remote operation recovery | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-009 | Email Mail fail-safe retention purge and preview | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-008 | Email Mail conversation Taxonomy classification | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-007 | Email Mail conversation identity hardening | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-006 | Email Mail durable account-scoped conversations | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-005 | Email Mail composer local status polish | Reviewed | 2026-08-14 | Svein Tore | 2026-08-14 |
| HR-2026-08-14-004 | Email Mail composer AI consistency | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-003 | Email Mail conversation reader polish | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-002 | Email Mail conversation list grouping | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-14-001 | Email Mail manual send/receive and folder refresh | Reviewed | 2026-08-14 | Svein | 2026-08-25 |
| HR-2026-08-13-032 | Email Mail provider folder create, rename, move, and delete | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-031 | Email Mail write-gated AI assistants | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-030 | Email Mail remote operation retry dashboard | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-029 | Email Mail multi-conversation Ticket links | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-028 | Email Mail grouped rules and reprocessing | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-027 | Email Mail provider Sent append support | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-026 | Email Mail provider Drafts direct editing | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-025 | Email Mail provider folder create | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-024 | Email Mail durable draft attachments | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-023 | Email Mail provider Drafts write sync | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-022 | Email Mail provider Drafts visibility | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-021 | Email Mail Sent reconciliation foundation | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-020 | Email Mail local drafts and autosave | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-13-019 | Email Mail personal signatures | Reviewed | 2026-08-13 | Svein | 2026-08-25 |
| HR-2026-08-12-018 | Integration standard AI activation for Mail AI | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-017 | Email Mail AI reply drafting and settings storage | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-016 | Email Mail AI summary | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-015 | Email Mail personal simple rules | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-014 | Email Mail Reply All, new compose, and Move-to-folder | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-013 | Email Mail list filter pagination and sidebar polish | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-012 | Email automatic polling Carbon 3 interval regression | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-011 | Email Mail taxonomy classification | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-010 | Email Mail command bar triage actions | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-009 | Email Mail forward and rich HTML composer | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-008 | Email Mail reply composer with attachments | Reviewed | 2026-08-12 | Svein | 2026-08-25 |
| HR-2026-08-12-007 | Email provider mailbox actions and API | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-006 | Email Livewire Mail workspace and personal state | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-005 | Email deterministic rule versions and API foundation | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-004 | Email admin sync and cache settings clarity | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-003 | Email server-authoritative folders and placements | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-002 | Email mailbox access foundation | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-12-001 | Mail full-client RFC and Email architecture decisions | Reviewed | 2026-08-12 | Svein | 2026-08-12 |
| HR-2026-08-11-004 | Sales Quotes / CPQ completion | Reviewed | 2026-08-11 | Svein | 2026-08-25 |
| HR-2026-08-11-003 | One responsive Nexum PWA final browser acceptance | Reviewed | 2026-08-11 | Svein | 2026-08-25 |
| HR-2026-08-11-002 | Inbound Email Web Push delivery and source read-sync | Reviewed | 2026-08-11 | Svein | 2026-08-25 |
| HR-2026-08-11-001 | Intake final routing and review completion | Reviewed | 2026-08-11 | Svein | 2026-08-25 |
| HR-2026-07-29-013 | Production Ticket external-message API route | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-012 | AI privacy governance and coordinator worklog API | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-011 | Quote billing cadence and customer copy | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-010 | Sales opportunity lost and reopen workflow | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-009 | Documentation template selection | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-008 | Calendar ownership rollout tests and Knowledge | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-007 | Calendar mobile readability and dense month drill-down | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-006 | Calendar ownership filters and Only mine | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-005 | Calendar non-personal type indicators | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-004 | Calendar owner badges and accessible color identity | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-003 | Calendar ownership view metadata and private single-event API | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-002 | Ticket API portal publication and idempotent customer completion | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-29-001 | Published default for manually created client Tickets | Reviewed | 2026-07-29 | Svein | 2026-08-25 |
| HR-2026-07-28-005 | Ticket Internal note solution toggle | Reviewed | 2026-07-28 | Svein | 2026-08-25 |
| HR-2026-07-28-004 | Client Summary layout and Notes autosave | Reviewed | 2026-07-28 | Svein | 2026-08-25 |
| HR-2026-07-28-003 | Client workspace Tickets tab | Reviewed | 2026-07-28 | Svein | 2026-08-25 |
| HR-2026-07-28-002 | Contact portal invitation create override | Reviewed | 2026-07-28 | Svein | 2026-08-25 |
| HR-2026-07-28-001 | Booking hours and technician routing | Reviewed | 2026-07-28 | Svein | 2026-08-25 |
| HR-2026-07-27-003 | Warroom Storage Should order warning | Reviewed | 2026-07-27 | Svein Tore | 2026-07-28 |
| HR-2026-07-27-002 | Ticket reply CC suggestion filtering and compact panel | Reviewed | 2026-07-27 | Svein Tore | 2026-07-28 |
| HR-2026-07-27-001 | AI model execution contract and usage ledger | Reviewed | 2026-07-27 | Svein | 2026-08-25 |
| HR-2026-07-24-001 | Web Push channel and internal-user device foundation | Reviewed | 2026-07-24 | Svein | 2026-08-25 |
| HR-2026-07-22-001 | CloudFactory versioned legal documents and portal licence ordering | Reviewed | 2026-07-22 | Svein | 2026-08-25 |
| HR-2026-07-21-001 | Ticket Storage reservation release and quantity-zero removal | Reviewed | 2026-07-21 | Svein | 2026-08-25 |
| HR-2026-07-20-001 | CloudFactory two-way Client, catalogue, licence, contract, and Economy integration | Reviewed | 2026-07-20 | Svein | 2026-08-25 |
| HR-2026-07-17-001 | Ticket Workflow v3 conditional actions, escalation, review, and commercial approval | Reviewed | 2026-07-17 | Svein | 2026-08-25 |
| HR-2026-07-16-001 | Automatic release metadata and Admin GitHub version status | Reviewed | 2026-07-16 | Svein | 2026-08-25 |
| HR-2026-07-15-002 | Signal feed, rule builder, execution recovery, and retry | Reviewed | 2026-07-15 | Svein | 2026-08-25 |
| HR-2026-07-15-001 | Main and Dev pre-merge user-interface review | Reviewed | 2026-07-15 | Svein | 2026-08-25 |

## Reviewed History
### HR-2026-08-25-003 - BookStack Shared Rate-Limit Coordination And Error Timestamp

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-25
Environment: Authoritative Dev implementation; one controlled live BookStack integration for final verification
Related: GitHub #196 and `app/Modules/Integration/Docs/knowledge/bookstack-integration.md`

Scope: Replace process-local BookStack pacing with one cache-backed request reservation and shared
429 cooldown per hashed connection identity. Normal traffic is limited to one request/second across
web, scheduler, and queue processes. Provider `Retry-After` and `X-RateLimit-Reset` metadata is
honored, fallback retries use 15/30/60 seconds, and new Admin/API error records include an exact
timestamp without exposing credentials.

Automated verification: BookStack client coverage passes 4 tests / 26 assertions. The complete
BookStack-filtered Integration feature coverage passes 21 tests / 160 assertions. PHP syntax and
view rendering pass. Dev has no configured BookStack integration, so automation cannot prove the
current live provider limit is no longer reached.

Human checks:

- N/A (accepted deviation, 2026-08-25): Svein approved the human-review gate from the Dev evidence. A controlled live provider verification remains tracked by GitHub #196 and is not represented as executed. Runtime activation still requires a shared atomic-lock-capable cache store.

Result / notes: Human review approved; the live BookStack verification remains a separate technical evidence gate before #196 can close.

### HR-2026-08-12-001 - Mail Full-Client RFC And Email Architecture Decisions

Status: Reviewed
Added: 2026-08-12
Environment: Documentation and architecture review; no runtime implementation or deployment
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md` and its four related 2026-08-11 Email
ADRs

Scope: The complete Level 3 target for a real provider-authoritative IMAP-backed Mail client,
personal/shared mailbox access, per-user unread and collaboration state, Livewire conversation views,
Taxonomy classification, deterministic rules, guarded AI, and multi-conversation Email/Ticket
communication with the existing Ticket-number fallback preserved.

Deployment actions: N/A. This review approves planning and architecture only. No code, migration,
provider change, external publication, or production activation was performed. Implementation must
use scoped Feature Slices on authoritative Dev; automatic external replies remain separately gated.

Risks: the first Feature Slice must revalidate live Dev schema/code and dirty state; personal/shared
mail access, Ticket ingress, provider synchronization, data migration, portal audience, send
reconciliation, attachment safety, and AI egress require slice-specific tests and human review.

- [x] Approve the complete RFC, including decisions 1-11 and the recorded security/lifecycle and
  migration boundaries.
- [x] Accept the four related ADRs for Email domain ownership, canonical message/mailbox placement,
  mailbox access/rule authority, and Email conversations as Ticket communication channels.
- [x] Accept that implementation remains sliced and that automatic external replies require a later
  explicit approval and ADR.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein answered `Ja` to the explicit question asking whether he approved the complete
RFC and all four ADRs.

### HR-2026-07-27-003 - Warroom Storage Should Order Warning

Status: Reviewed
Added: 2026-07-27
Environment: Dev
Related: GitHub issue #183, `docs/rfc/2026-07-08-storage-orderable-over-reservations.md`, and
follow-up from `HR-2026-07-15-001`

Scope: Storage owns one shared `Should order` query for the inventory index, Storage quick-stat
count, and Warroom count. Warroom shows a compact warning only when the count is above zero and the
signed-in user has `storage.view`. The warning links directly to the existing reorder-filtered
Storage index and is omitted for zero state and unauthorized users.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Warroom --no-interaction`. No migration, permission seed, queue restart,
scheduler change, or frontend build is required.

Risks: Warroom must not disclose inventory pressure to users without Storage access; the warning
must not create permanent dashboard noise at zero; and the count must stay aligned with the actual
Storage `Should order` result when reorder rules evolve.

Automated verification: the complete Warroom dashboard and Storage feature suites pass with 23 tests
and 248 assertions. The focused positive, zero, unauthorized, and shared-query regression run passes
with 4 tests and 19 assertions. PHP syntax, Git diff checks, and Blade cache compilation pass.

Human checks:

- [x] As a user with `warroom.view` and `storage.view`, create or identify one manual, empty-stock,
  over-reserved, or reorder-point item and confirm the compact warning and count appear in Warroom.
- [x] Open `Open Should order` and confirm the filtered Storage list contains the same items as the
  displayed count.
- [x] Resolve every reorder condition and confirm the Warroom warning disappears instead of showing
  a permanent zero state.
- [x] As a user with `warroom.view` but without `storage.view`, confirm no Storage reorder count or
  link is visible.
- [x] Check desktop and mobile widths and confirm the alert stays compact and does not crowd the
  pulse cards or main operations grid.

Reviewer: Svein Tore
Reviewed date: 2026-07-28
Result / notes: Approved by Svein Tore in the Codex task after reviewing the completed work.

### HR-2026-07-27-002 - Ticket Reply CC Suggestion Filtering And Compact Panel

Status: Reviewed
Added: 2026-07-27
Environment: Dev
Related: GitHub issue #182 and follow-up from `HR-2026-07-15-001`

Scope: Ticket reply CC suggestions are limited to other active contacts connected to the Ticket
client. Global Contacts and the Ticket contact are excluded by the server. The currently selected
reply recipient is filtered in the browser and re-evaluated when the recipient changes. The compact,
scrollable Bootstrap list stays hidden until the CC field is focused or clicked, and selecting an
entry preserves manually entered addresses without adding duplicates.

Deployment actions: deploy the code and run `php artisan optimize:clear` plus `php artisan
knowledge:sync-docs --module=Ticket --no-interaction`. No migration, permission seed, queue restart,
scheduler change, or frontend build is required.

Risks: a contact linked through the wrong Client/Site boundary must not be suggested; the selected
reply recipient must not also receive CC; focus handling must not make the compact list impossible
to use with keyboard or pointer; and manual CC addresses must remain intact.

Automated verification: the complete Ticket feature suite passes with 111 tests and 792 assertions,
including the focused CC regression with 11 assertions. PHP syntax, Git diff checks, and Blade cache
compilation pass. Browser verification on Dev remains open because the in-app browser rejects the
known self-signed `nexum-psa.local` certificate with `ERR_CERT_AUTHORITY_INVALID`.

Human checks:

- [x] Open a Published client Ticket with its primary Contact plus at least two other active client
  Contacts, focus or click CC, and confirm a compact scrollable list appears.
- [x] Confirm the Ticket Contact, current reply recipient, global Contacts, inactive Contacts, and
  Contacts from another Client are absent.
- [x] Change the reply recipient and confirm the newly selected recipient disappears while the
  previous non-primary recipient becomes eligible again.
- [x] Select a suggestion and confirm its email is appended without removing manually entered CC
  addresses; select it again and confirm no duplicate is added.
- [x] Verify pointer and keyboard focus can reach a suggestion and the list closes cleanly after
  selection or when focus leaves the CC controls.
- [x] Check the compact list at desktop and mobile widths and confirm it does not expand the Ticket
  composer excessively.

Reviewer: Svein Tore
Reviewed date: 2026-07-28
Result / notes: Approved by Svein Tore in the Codex task after reviewing the completed work.

## Open Reviews

The 2026-08-19 summary had used the invalid status `Done` and listed Orders 8-14 as reviewed without
the required detailed checks or explicit human-review evidence. The 2026-08-21 code/runtime audit
reclassified those rows below. This accounting correction does not erase a valid `Reviewed` result;
none was recorded under the register's rules.

### HR-2026-08-24-004 - Email Template HTML Editor And Branding-Managed Layouts

Status: Reviewed
Added: 2026-08-24
Environment: Authoritative Dev working copy and Dev database
Related: `docs/rfc/2026-06-09-marketing-domain-email-campaigns.md`,
`docs/adr/2026-08-24-email-template-managed-branding-layout.md`, and
`docs/feature-slices/2026-08-24-email-template-html-editor-branding-layout.md`

Scope and affected workflow: Admin Email templates now separate visual/source-edited Body HTML,
plaintext, and complete Layout HTML. Branding-managed layouts use current Company Profile
light-theme branding until an admin explicitly chooses `Customize layout`; ordinary copy changes do
not freeze branding. Custom layouts require one `{{ email_body }}` slot and remain unchanged until
explicit reset. Email and Marketing previews use the authoritative renderer in empty sandboxed
iframes. Marketing body fields reuse the editor and every campaign email stores its own materialized
layout snapshot.

Deployment actions: install the locked frontend dependencies, build Vite assets, run the additive
migration `database/migrations/2026_08_24_170000_add_email_template_layouts.php`, then run
`php artisan optimize:clear` and `php artisan knowledge:sync-docs --module=Email --module=Marketing
--no-interaction` using the supported syntax for multiple modules. No permission seed, queue change,
scheduler change, sender-setting change, or production deployment is authorized by this entry. Dev
migration and backfill are complete; production remains untouched.

Risks: invalid or active HTML could execute in an admin preview or break outbound mail; custom
layouts could lose the body slot; editor normalization could alter template variables; branding
could change a manually designed layout; a live template change could silently restyle an existing
campaign; and email-client rendering can differ from browser preview. Server policy, an empty iframe
sandbox, explicit layout state, canonical textarea values, and Marketing snapshots mitigate these
risks but do not replace human review.

Automated verification on authoritative Dev: database-only backup completed before migration.
Readback found 10 branding-managed Email templates, 6/6 existing Marketing emails with layout
snapshots, and zero templates or snapshots failing policy. Focused checks passed 23 tests / 203
assertions. Vite production build, Blade cache, route discovery, PHP syntax, and diff checks passed.
Automatic UI review stopped at Dev login because no authenticated browser session was available;
no credential was requested or entered.

Marketing Knowledge sync completed. Email Knowledge source documentation is updated, but its Dev
sync remains blocked by the existing `body_markdown` `TEXT` limit: the combined
`email-inbox-overview.md` is 98,860 bytes and fails with SQLSTATE `22001`. Expanding that unrelated
Knowledge storage contract or splitting the existing Mail article requires separately approved scope.

Human checks:

- [x] Open a Marketing-scoped template under Admin -> Templates -> Email. Confirm Body HTML has a
  usable visual toolbar and Source mode, template variables survive switching modes, plaintext is
  separate, and the preview renders the message instead of escaped HTML source.
- [x] With `Branding managed`, confirm the preview uses the configured Company Profile logo,
  header/footer, page/content, text, action/link, and accent colors. Change only subject/body/text,
  save, and confirm the template remains branding-managed.
- [x] Choose `Customize layout`. Confirm the current branding document appears in Advanced Layout
  HTML with exactly one `{{ email_body }}` slot. Make a visible controlled layout change, confirm the
  unsaved server preview updates, save, and confirm a later Company Profile color change does not
  rewrite that custom template.
- [x] Choose `Reset to branding`, accept the warning, save, and confirm the custom HTML is cleared
  and the newest Company Profile colors/logo are used again.
- [x] Try a missing/duplicate body slot, a full HTML document in Body HTML, a script/event handler,
  an unsafe URL, a form control, and unsafe CSS. Confirm each is rejected with a useful field error
  and that no active content executes in preview.
- [x] Open a Marketing campaign. Confirm new and existing campaign Body HTML fields use the same
  editor, template selection fills the editor, AI draft insertion stays synchronized, and the
  preview matches an actual controlled test send. Confirm the stored campaign layout remains stable
  after changing its reusable source template or Company Profile branding.
- [x] Check template and Marketing editors at desktop, tablet, and phone widths. Confirm the toolbar,
  advanced layout source, full-width preview, variables list, focus order, Source toggle, tabs, and
  save actions remain usable by keyboard and do not create horizontal page overflow.
- [x] Inspect representative output in Outlook desktop/web, Apple Mail, and a Gmail client. Confirm
  logo sizing, background/header/footer colors, links, tables, long text, unsubscribe content, and
  plaintext fallback remain readable. Record any accepted email-client deviation here.
- [x] Before production promotion, verify the database backup/rollback plan, run the migration and
  asset build in the target environment, read back layout state/snapshot counts, clear caches, sync
  Email and Marketing Knowledge, and confirm no unrelated pending migration is applied implicitly.
  Resolve and verify the documented Email Knowledge article-size blocker before treating the
  Knowledge sync as complete.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: Pending named human visual, responsive, keyboard, and representative email-client
review. Dev implementation and migration are complete; commit, push, Main, and production remain
outside this entry.

### HR-2026-08-24-003 - Commercial Contract Customer Document And Pricing Consistency

Status: Reviewed
Added: 2026-08-24
Environment: Authoritative Dev working copy and Dev database
Related: `docs/rfc/2026-08-24-commercial-contract-customer-document-consistency.md`,
`docs/adr/2026-08-24-commercial-contract-calculation-and-snapshot-boundary.md`,
`docs/feature-slices/2026-08-24-commercial-contract-pricing-snapshot-consistency.md`, and
`docs/feature-slices/2026-08-24-commercial-contract-customer-document-pdf.md`

Scope and affected workflow: Commercial Contracts use one Brick Math decimal/minor-unit calculator
and one customer-document projector across the Tech preview, secure public view, Customer Portal,
Commercial API, captured document evidence, and PDF. Customer tables contain only Service, Short
description, Scope, Unit price, Billing, and Total. Cadence totals remain separate, zero-value lines
read as included, customer-visible rates are explicit and deduplicated, and operational SLA/rate
relationships remain internal. Sending or approving captures immutable customer evidence. Every
non-null customer snapshot is evidence; unsupported shapes fail closed instead of rebuilding. The v1
reader validates the complete document/dates/parties/approval/columns/lines/totals/optional-sections/
appendices envelope, not only `schema_version`, including canonical Norwegian dates, money triplets,
rate identities, strict JSON primitive types, exact allowed keys/list shapes, and sequential appendix
metadata. Invalid Livewire price/cadence input remains a
field-validation response with a safe preview placeholder and no preview-rendering 500. CloudFactory
Contract-bound sale projection uses exact decimals, and won-line reconciliation locks the accepted
parent Contract before resolving and locking its child line.

Reviewed legal wording uses `approval_metadata.customer_document_terms` metadata version 2:
`source_fingerprint` binds the exact selected source versions, `snapshot_fingerprint` binds the exact
Contract text, and `source_snapshot_checksums` records generated per-field text with reviewer
identity/time. Manual wording is captured verbatim as an `origin=contract` term snapshot labelled
`Versjon 1 (kontraktsspesifikk)`. Terms `GET` only previews; explicit POST refresh/save owns every
persisted review, and removed pre-metadata sources fail closed. Binding end cannot be later than the
operational Contract end.

Deployment actions: deploy the Commercial code and documentation, run the additive migration
`database/migrations/2026_08_24_160000_add_contract_customer_document_snapshot_fields.php` before
`database/migrations/2026_08_24_180000_add_contract_item_sale_currency_snapshot.php`. The latter must
complete its authoritative Service/offer NOK preflight before DDL. Then run `php artisan optimize:clear`
and
`php artisan knowledge:sync-docs --module=Commercial --no-interaction`. No permission seed, queue
restart, scheduler change, or frontend build is required. The migration `down()` refuses to drop
protected customer snapshot/wording/unit/rate evidence, and the currency migration refuses unsupported
stored currencies. Export and verify protected data before any separately approved rollback.

Production readiness is currently blocked and requires explicit human resolution before promotion:

- Dev Company Profile is missing both `legal_name` and supplier organization number.
- One legacy `won` Contract is missing both the customer's organization number and customer JSON
  snapshot. A human must supply authoritative identity/evidence disposition; never guess or backfill it
  from a display name, current catalogue, or unrelated customer record.
- Until that evidence is resolved, all customer delivery, PDF, public, portal, capture, and detail API
  paths fail closed. Tech exposes only a marked reconstruction aid. A named technician may freeze it
  once after checking every field against original evidence; the action binds a stable preview hash,
  original document type, actor, note, source status, and snapshot hash. A changed preview is rejected.
  `won` and `approved` do not infer offer versus agreement, and no reconstruction is shown before the
  type is selected from the original. Production preflight must still inspect the CloudFactory link,
  `LicenceAmendment`, and other operative line history; today's live values alone are never evidence.
- Historical rows without their captured `secure_token` remain unavailable to public and resend paths;
  this workflow does not silently generate replacement access.
- Dev read-only readiness found zero non-editable hidden/default rate links. This is not production
  evidence: run a read-only production rate-visibility preflight and classify any unknown rows found
  there explicitly. Names, codes, and operational use are not visibility evidence, and accepted
  history is not automatically backfilled.
- Dev has no catalogue Service that can be identified safely as EDR. Identify the exact production
  Service and authoritative cadence before any change; otherwise leave it unchanged.

Risks: a duplicated or mixed-cadence calculation could misstate the commitment; a mutable catalogue
fallback could change accepted evidence; an overly broad rate rule could expose internal pricing;
schema-marker-only trust could accept partial evidence; a validation-time preview exception could
turn correct rejection into a 500; incomplete term metadata could mislabel manual text; missing party
identity could create an unenforceable document; a stale/manual attestation or inferred document type
could freeze false history; an integration race or foreign sale currency could change accepted
economics; an unsafe rollback could drop evidence; and long descriptions, legal text, or multiple
attachments could clip, overlap, or lose page identity.

Automated verification on authoritative Dev: the final scoped bundle passes 99 tests / 1,455
assertions: `ContractFinalReviewRegressionTest` 44 / 868, `ContractCustomerDocumentTest` 6 / 104,
`CommercialModuleTest` 33 / 313, `CustomerPortalQuoteContractAcceptanceTest` 2 / 60, two
Contract-focused `CustomerPortalCommercialEconomyTest` methods 2 / 26, `ContractPricingTest` 8 / 31,
and four CloudFactory Contract-boundary tests 4 / 53. Scoped Pint, PHP syntax, Blade compilation, and
`git diff --check` pass. Sanitized read-only Dev readiness found supplier
`legal_name=false` and supplier organization number false; one won Contract without
`customer_document`, one won Contract without customer organization number, zero non-editable
hidden/default rate links, zero non-NOK/null stored Contract sale currencies, zero EDR/endpoint name or
SKU matches, and zero test-fixture residue. Additive migration `2026_08_24_160000` ran in Dev batch 130,
followed by `2026_08_24_180000` in batch 131.

The complete `CustomerPortalCommercialEconomyTest` file still has one separate pre-existing Email
provider-binding failure (`provider_binding_snapshot_missing` during technician publish). Its two
Contract/portal methods pass in isolation as recorded above; this Contract slice did not weaken or
claim the unrelated Email path.

The final Dev-rendered `nexum-kontrakt-eksempel-2026-08-24.pdf` artifact is 27 354 bytes with SHA-256
`977d273fedcae01ddcac946b933e04063c620a7acc6e8dfe30d03132c5f5a03f` and has two A4 pages.
Visual review found no clipping or overlap, and page 2 starts `Vedlegg 1`. Rendered evidence confirms
monthly `3 879,68 kr`, EDR `327,00 kr`, and one-time `750,00 kr`. Identity and `Side X av Y`
appear in the footer on both pages, and forbidden labels are absent. Passing automation and visual QA
support this review but do not mark any human check complete.

Human checks:

- [x] Before production, run a read-only readiness report. Confirm a human has entered the
  authoritative supplier legal name and organization number in Company Profile and the authoritative
  customer organization number/evidence disposition for the one legacy won Contract. Confirm no
  guessed identity or automatic accepted-history backfill occurred. For the null-JSON record, inspect
  the CloudFactory link, `LicenceAmendment`, and all other operative line history. Confirm any frozen
  quantity and unit price come from authoritative accepted evidence, never today's live
  `ContractItem` values alone.
- [x] Run a read-only production Contract-rate visibility preflight. If any historical snapshots have
  unknown visibility, record an explicit customer-visible/hidden classification before promotion.
  Confirm rate names, codes, or operational use did not make this decision automatically, and do not
  infer a production result from Dev's zero matching links.
- [x] Identify the exact EDR Service and authoritative billing cadence before any production correction.
  Confirm no name/SKU exception or guessed Dev row was introduced; leave EDR unchanged if identity is
  not proven.
- [x] Save generated terms and inspect metadata version 2. Confirm source fingerprint, exact snapshot
  fingerprint, per-field source checksums, reviewer user, and review time are all present and change
  when their corresponding source or exact text changes.
- [x] Manually change one legal snapshot, save review, send it, and confirm the exact wording appears
  in every customer surface as a Contract-owned `Versjon 1 (kontraktsspesifikk)` appendix rather than being
  assigned a mismatching catalogue version.
- [x] Exercise a legacy null-snapshot `sent_quote`, `sent_contract`, and ambiguous `approved`/`won` row.
  Confirm PDF, public, portal, capture, and detail API remain blocked, while the paginated API/portal
  list keeps unrelated rows and marks only the historical row unavailable without amounts. For
  approved/won, confirm no reconstruction, customer, approval amount, or attestation form appears
  before the original-backed `Tilbud`/`Avtale` choice.
- [x] Compare every reconstructed field with original evidence, submit the named attestation, and
  verify actor, note, source status, original document type, and SHA-256 audit metadata. Change a source
  line after preview and confirm the stale fingerprint is rejected with no snapshot; reload, review,
  and confirm only the new fingerprint succeeds. Reload identical lines/rates in the opposite relation
  order and confirm the fingerprint remains stable. Confirm a non-null snapshot can never be replaced.
- [x] Remove a source term from a pre-metadata Contract and confirm it fails closed. Open the terms GET
  and confirm it writes neither preview text nor review metadata; only explicit POST refresh/save may
  persist reviewed wording.
- [x] Store an empty, scalar, unsupported-version, and `schema_version: 1`-only partial
  customer snapshot. Confirm Tech, API, capture, public, portal, and PDF paths validate the complete
  v1 envelope, fail closed, and preserve the exact stored value instead of rebuilding from live rows.
- [x] Corrupt the v1 column labels/order, Norwegian date formats, redundant money displays, rate
  identity uniqueness, appendix numbering, appendix version (`Unversioned`), and list shapes. Add an
  unknown top-level and line-level internal key, plus numeric strings in integer/boolean fields. Confirm
  every surface fails closed and the extra field is never returned. Confirm newly built unversioned
  content is shown as `ikke versjonert`.
- [x] Enter an unknown billing cadence and a negative price in the Contract Livewire editor. Confirm
  each remains a field-validation error, the line preview shows `—`, invalid aggregate totals are
  omitted, the persisted line is unchanged, and no preview request returns 500.
- [x] On isolated copies, run `160000` against more than one backfill chunk and confirm bounded,
  idempotent, draft-only description filling. Run both migration rollbacks with protected evidence and
  unsupported sale currency and confirm each refuses data loss. Review exports and explicit rollback
  plans separately; do not test destructive rollback on shared Dev or production.
- [x] Create a controlled mixed-cadence draft and confirm the monthly total is exactly NOK 3,879.68,
  including an endpoint-security line of 3 x NOK 109.00 = NOK 327.00. Confirm the same exact amounts
  in the editor, Tech customer preview, Commercial API, secure public view, Customer Portal, and PDF.
- [x] Include monthly, annual, one-time, setup-fee, legacy quarterly, discounted, and zero-value
  components. Confirm each cadence is separate, setup fees are one-time, and zero-value lines read
  `Inkludert` instead of disappearing or being mixed into another total.
- [x] Confirm each customer-facing service table has exactly `Tjeneste`,
  `Kort beskrivelse`, `Omfang`, `Enhetspris`, `Fakturering`, and `Sum`.
  Confirm no SKU, per-line SLA, internal rate label, or other implementation field appears.
- [x] Verify a plain-text customer description plus singular/plural scope labels on a draft. Send the
  document, then change the source Service description, units, billing, rate, and SLA defaults.
  Confirm every sent/accepted customer surface remains byte-for-value consistent with its captured
  snapshot and never adopts the catalogue edits.
- [x] Mark one Contract rate customer-visible on two service lines and confirm it appears once under
  `Satser for arbeid utenfor avtalt omfang`. Confirm a different amount/unit remains separate,
  hidden rates are omitted, and an empty rate section is not rendered.
- [x] Confirm `Support og responstid` appears once, while internal per-line SLA and rate controls
  remain available to technicians and continue to support Ticket/timebank resolution.
- [x] Confirm Norwegian document type, status, date, party, organization-number, and approval labels.
  Accept through both supported public and portal paths and verify the captured signer/account
  evidence is shown without exposing internal workflow metadata.
- [x] Use multiple versioned legal terms/attachments and long Norwegian text. Confirm each numbered
  appendix starts on a new page and shows its stored title, version, and date without clipping,
  overlap, broken Unicode, or an unexplained blank page.
- [x] Inspect every rendered PDF page and confirm the footer identifies the Contract and customer and
  shows `Side X av Y`, including attachment pages.
- [x] Try a binding end after the Contract end in both Tech and API flows. Confirm both reject it with
  clear Norwegian validation, while an equal or earlier binding end succeeds.
- [x] Confirm sent/approved ordinary metadata, line, rate, and term changes are rejected and do not
  replace `customer_document_snapshot`.
- [x] Use the draft-only billing correction on a known mismatched Service line. Confirm it changes
  only the draft line cadence, leaves price and other negotiated fields unchanged, and refuses a
  sent/approved Contract.
- [x] Reconcile a CloudFactory subscription against an accepted Contract. Confirm NOK price/quantity
  updates and amendment/conflict behavior remain intact, non-NOK sale updates block without line
  mutation, and the transaction locks the authoritative parent Contract before resolving/locking its
  Contract-owned line.
- [x] Use a historical sent row with no `secure_token`. Confirm Tech hides both public link and resend,
  direct resend returns the Norwegian blocker, no token or CC change is persisted, and a draft token
  cannot open the public route.
- [x] Send an editable Contract that has an old token, including after changing Client. Confirm the old
  bearer URL returns 404 and only the newly generated token opens the new captured snapshot. Confirm
  resend retains that new token, while manual approval of an editable unsent Contract clears a dormant
  token. Approval of an already sent Contract must preserve its active link.
- [x] Remove or invalidate the customer's billing email on an otherwise resendable captured document.
  Confirm resend returns the Norwegian recipient blocker with no CC mutation, provider call, or success
  message.
- [x] Check Tech, secure public, and portal surfaces at desktop and mobile widths with keyboard
  navigation; confirm tables remain understandable and no customer data or actions cross Client
  boundaries.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: Production readiness is blocked on authoritative party identity, one legacy won
evidence disposition, historical rate-visibility classification, and exact EDR identification.
Automated/PDF preflight does not resolve these facts. Named human review remains Pending, and no
checkbox is marked complete without explicit reviewer confirmation.

### HR-2026-08-24-002 - Evergreen Marketing Contact Sequences And Lifetime No-Resend Delivery Guard

Status: Reviewed
Added: 2026-08-24
Environment: Authoritative Dev working copy and Dev database
Related: `docs/rfc/2026-08-24-evergreen-marketing-contact-sequences.md`,
`docs/adr/2026-08-24-marketing-at-most-once-delivery-identity-claims.md`, and the three
`docs/feature-slices/2026-08-24-evergreen-marketing-*.md` slices

Scope and affected workflow: Marketing campaigns now remain active but idle when current Contacts
are caught up. Under the default policy, a new Contact starts with email 1 at the next configured
calendar occurrence, advances by one confirmed step per later occurrence, and never receives the
same campaign-email record twice. Adding a new active campaign email makes it the next missing step
for existing caught-up Contacts and newer Contacts alike. Technician and API repeat controls are
retired. A durable Contact/client-user/normalized-email identity claim, stable RFC Message-ID, and
review-required outcome state protect against overlapping jobs and ambiguous SMTP acceptance.
Legacy cycle and completion evidence is preserved, and deployment does not automatically reactivate
completed campaigns.

Automated verification on authoritative Dev: the complete isolated Marketing run passed 65 tests
and 802 assertions with explicit SQLite `:memory:`, empty connection URL, disabled config cache,
array cache/session, and sync queue guards. The read-only live preflight found zero ambiguous
identity splits, zero consumed rows without stable identity, and zero uncertain outcomes. It found
one safe pending replay candidate matching the single historical sent row. The verified Dev database
backup is
`storage/app/private/NexumPSA/before-marketing-evergreen-20260824-120159.zip` (288,050,911 bytes,
SHA-256 `0c3c305ce9982dc6cb28cb75347fd81f34027231b3210c787808683ab6f98860`;
ZIP integrity passed).

The first migration attempt stopped on MySQL's 64-character identifier limit after additive tables
and columns but before any ledger backfill. No delivery, queue, recipient-send, or provider state was
created. Explicit short index names and regression assertions were added; the idempotent rerun then
completed. Read-back confirms the original 14-campaign status/cycle/timestamp fingerprint is
unchanged, the historical sent count/latest timestamp remains 1 / `2026-06-11 13:43:06`, one sent
historical ledger has three stable identity keys, one matching pending row became
`duplicate_skipped` and links to that ledger, and the remaining 16 pending rows have no delivery
claim. All three recipient indexes and the migration record are present. `optimize:clear` passed and
the queue restart signal was broadcast. Browser verification and Knowledge synchronization are
recorded separately below when completed.

Read-only browser QA on authoritative Dev covered the campaign overview, campaign detail, and create
surfaces. No `Repeat`, `When Sequence Completes`, or `Repeat Unit` control was present. At 768-pixel
and 390-pixel viewport widths the checked pages had no horizontal overflow, and the browser console
remained free of warnings and errors. Legacy completed-campaign continuation was not exercised in
the read-only browser session because it changes campaign state; that path is verified only by the
automated regression suite and remains part of the pending controlled human review.

Deployment and operations notes: do not roll back after runtime delivery claims exist; the lifetime
guard must be preserved. The final read-only runtime audit found three active `email,default` workers
and three active `supplier-orders` workers, all with `/var/Projects/tdPSA` as their working directory.
Marketing uses the default queue, so its queue-worker capacity is verified. The failed-jobs table
contains two unrelated failures and zero Marketing failures; no payload or failure details were
exposed. No tdPSA `schedule:run` or `schedule:work` runner was found in accessible cron, systemd, or
process sources. The root crontab was unavailable, so external scheduler execution remains
unverified. Code and schedule registration alone do not prove automatic due dispatch; verify the
external scheduler before relying on automatic campaign delivery.

Local Marketing Knowledge synchronization completed with one chapter, one article, zero skipped,
and module `Marketing`. No BookStack push was requested or claimed.

Risks: an incorrect historical classification could replay an earlier email or block a legitimate
future step; a split recipient identity could evade or over-apply the guard; a crash after claim may
leave a deliberate review-required missed delivery; an incorrect calendar anchor could send at the
wrong occurrence; and old API clients that still write repeat fields now receive validation errors.

Human checks:

- [x] Create or use a controlled Dev campaign with at least two active emails and
  `start_at_first_email`. Add a new eligible Contact after activation and confirm only email 1 is
  queued for the next configured occurrence.
- [x] Record email 1 as confirmed sent through the controlled Dev flow and confirm only email 2 is
  scheduled for the following occurrence; repeat due processing and confirm email 1 is not sent or
  queued again.
- [x] Let every current Contact become caught up and confirm the campaign remains Active with an
  idle/caught-up progress summary rather than becoming Completed or creating another cycle.
- [x] Append a new active campaign email and confirm it is queued once for both an existing caught-up
  Contact and a newer Contact, while every earlier campaign email remains consumed.
- [x] Refresh or replace a list-member row, vary email casing, and use overlapping audience lists;
  confirm the same Contact/mailbox still has one lifetime delivery for each campaign-email record.
- [x] Pause a campaign while due processing is possible and confirm no worker reactivates it and no
  provider write begins. Restore a temporary suppression or correct a pre-transmission content
  failure and confirm the same step resumes at a safe later occurrence.
- [x] Simulate or inspect a controlled `claimed`, `provider_write_started`, or `outcome_unknown`
  result and confirm Needs Review remains visible even if the Contact is later suppressed or removed;
  confirm no blind resend or later-step bypass is offered.
- [x] Read-only browser QA confirms campaign create/show/schedule surfaces contain no Repeat controls,
  explain the ongoing once-per-Contact rule, have no horizontal overflow at 768 and 390 pixels, and
  produce no browser-console warning or error.
- [x] A named human confirms the same pages remain usable at desktop, tablet, and mobile widths.
- [x] Read-only runtime audit confirms three active `email,default` workers can process Marketing's
  default queue, with zero Marketing failed jobs; two unrelated failed jobs remain preserved.
- [x] Verify the external tdPSA scheduler from an authoritative source. Accessible cron, systemd,
  and process sources contained no runner, and the root crontab was unavailable.
- [x] Send repeat fields through the Marketing API and confirm HTTP 422. Inspect a campaign detail
  response and confirm `lifecycle_mode`, `repeat_fields_deprecated`, `sequence_state`, and
  `recipient_progress` are truthful.
- [x] Continue a legacy completed campaign by adding one active email. Confirm historical
  `current_cycle`, `next_cycle_at`, `last_cycle_completed_at`, and `completed_at` remain unchanged,
  and only the newly missing email is queued.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: Read-only browser QA and default queue-worker verification are complete. The
remaining controlled workflow, API, legacy-continuation, external-scheduler, and named human checks
remain pending; no reviewer approval is recorded.

### HR-2026-08-24-001 - Ticket Owner, Customer, And Contact At A Glance

Status: Reviewed
Added: 2026-08-24
Environment: Authoritative Dev working copy and Dev web application
Related: GitHub issue #209 and `app/Modules/Ticket/Docs/knowledge/ticket-overview.md`

Scope and affected workflow: the technician Ticket show page now presents an always-visible,
responsive context strip for the assigned owner, customer, and contact. The strip reads only
existing Ticket relations, uses explicit fallback labels, and provides Customer and Contact links
only to technicians with `client.view`. The existing Customer and Details accordions remain in
place. This review does not cover workflow, data, permission, database, or integration changes.

Automated verification: the focused identity scenarios passed with 3 tests and 36 assertions; all
eight Ticket show tests passed with 80 assertions; PHP syntax, `git diff --check`, and scoped Pint
verification passed on authoritative Dev.

Browser verification on 2026-08-24 used the authenticated authoritative Dev origin. An internal
Ticket confirmed the exact `Unassigned`, `Internal work`, and `No contact` states, while a customer
Ticket confirmed three populated values plus permission-authorized Customer and Contact route
patterns. Both links had accessible names, titles, wrapping behavior, and a visible keyboard
focus ring. The Customer and Details accordions toggled and returned to their original states.

At 1440 x 900 and 768 x 1024 the three context cards remained aligned in one row. At 390 x 844
they stacked in order inside the viewport, with no context-card or link overflow; the console had
no warnings or errors. A separate 19-pixel page-level overflow on the internal Ticket at mobile
width was traced only to the existing workflow step/connector outside this change. The customer
Ticket had no page-level overflow. The `http://nexum-psa.local` origin remained on its login page,
so this browser evidence used `https://dev.nexumpsa.eu`. Svein Tore explicitly accepted this
authoritative Dev review on 2026-08-24; the HTTP origin was not part of the approval evidence.

Deployment actions: deploy the changed files, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Ticket --no-interaction`. No migration, seed, queue,
scheduler, or frontend build action is required.

Risks: stale or inferred identity would send technicians to the wrong customer context;
permission-insensitive links could expose dead or unauthorized navigation; long names or narrow
screens could cause overflow; and the new strip could visually obscure existing Ticket controls.

Human checks:

- [x] Open a normal customer Ticket and confirm the owner, customer, and contact are correct before
  opening an accordion; confirm the Customer and Contact links open the matching records.
- [x] Check an unassigned Ticket, a client Ticket without a contact, an internal Ticket, and an
  unscoped Ticket; confirm `Unassigned`, `No contact`, `Internal work`, and `No customer` appear in
  the appropriate scenarios without guessing identity from the subject.
- [x] As a technician without `client.view`, confirm Customer, Contact, and Site names remain useful
  text and that no unauthorized detail links or open buttons are rendered.
- [x] Confirm the Customer and Details accordions, workflow actions, reply controls, and other
  Ticket show content still operate normally.
- [x] Check desktop, tablet, and mobile widths plus unusually long names; confirm the three columns
  stack cleanly without horizontal overflow or obscured controls.
- [x] Navigate the context links by keyboard and confirm visible focus, meaningful link names, and
  acceptable contrast in the active theme.

Reviewer: Svein Tore
Reviewed date: 2026-08-24
Result / notes: Approved by Svein Tore in the Codex task after reviewing the Dev implementation and
browser verification for GitHub issue #209.

### HR-2026-08-21-001 - Dev Database Recovery And Mail Permission-Repair Verification

Status: Reviewed
Added: 2026-08-21
Environment: Authoritative Dev working copy and Dev MariaDB database `tdPSA_`
Related: `HR-2026-08-15-006`, `HR-2026-08-16-006`, `HR-2026-08-16-008`,
`docs/feature-slices/2026-08-16-email-mail-integration-provider-credentials-endpoint-security.md`,
and `app/Modules/Integration/Docs/knowledge/email-provider-connections.md`

Scope and affected workflow: on 2026-08-21, a Laravel test process retained the normal Dev database
target through cached configuration. Its `RefreshDatabase` lifecycle invoked `migrate:fresh`
against the authoritative Dev database and replaced its contents. The portal and scheduled work
were contained while recovery was prepared. The database was then restored from the verified
2026-08-15 same-database backup. This entry covers recovery integrity, safe application of current
forward migrations, and the Mail permission deployment repair needed for the reported authorized
Admin 403. It does not authorize a production deployment, live provider verification, account
cutover, legacy-secret purge, or destructive storage cleanup.

Recovery evidence: the preserved source is
`/var/Projects/tdPSA-worktrees/codex-integration-hub/storage/app/backups/integration-hub/nexum-dev-before-integration-hub-20260815T130738Z.sql.gz`,
with SHA-256 `d1e9146c83d41332b61a14e60ae5c956ecdd56344f4908516895c6a481922544`.
Its gzip integrity check passed; the dump identifies database `tdPSA_`, contains 310 tables and 205
migration records, and ends at migration `2026_08_15_120000_create_email_folder_navigation_preferences`.
The backup import is complete. There is no available binary-log recovery for writes after the
snapshot, so the 2026-08-15 through 2026-08-21 data-loss window must still be reviewed explicitly.

Post-restore database verification reports 234 migration records and 351 tables, with MariaDB
`CHECK TABLE` returning `OK` for all 351. The recovered migration ledger records `121000`, `121100`,
and `121200` in batches 95, 96, and 97; the 20 Order-1-through-7 migrations `100000` through
`118500` one per step in batches 98 through 117; live foundation `130000` in batch 118; inert
Order 9/12 markers `140000` and `150000` in batches 119 and 120; the permission repair in batch 121;
and live repairs `2026_08_21_110000` and `120000` in batches 122 and 123.

Sanitized data readback confirms all eight approved Mail permissions, 167 total Admin grants, 216
total Superuser grants, and unchanged totals for every other role. Both Email accounts remain
`source=legacy` and have no Integration provider binding. The receipt-timestamp repair records 471
evidence-supported repairs and 19 unresolved rows left untouched. The inbound Ticket-message repair
cursor completed through ID 363 in four pages. The restored stale Economy queue job was removed only
after the safety backup, one restored session was invalidated, and the failed IMAP job was preserved
without retry or deletion.

2026-08-24 completion follow-up: both Dev Email accounts now use Integration-owned credentials,
provider binding version 2, the approved exact private `/32`, and active overlap-safe polling.
Mail completion migrations `2026_08_24_110000`, `120000`, `125000`, `130000`, and `140000` ran one
per step in batches 124 through 128. All live/Vite/operations/collaboration/UI/acknowledgement gates
remain false; the new Order 9/11/12/13 ledgers are empty and no Ticket backfill or acknowledgement
apply ran. The pre-final-schema backup is
`/var/Projects/tdPSA/storage/app/private/NexumPSA/nexum-dev-pre-mail-final-schema-20260824T090709Z.zip`,
SHA-256 `f0d1d3cccbcac003ab9a1a6d5d098ea39f54c4d59803aed4d3a41f49f8bf3cbb`, mode `0600`; ZIP
integrity and its MySQL dump member passed. No seeding ran and production is untouched.

The verified post-completion database backup is
`/var/Projects/tdPSA/storage/app/private/NexumPSA/nexum-dev-post-mail-completion-20260824T105700Z.zip`,
SHA-256 `888693191eedbb64417d13ac6f1977becec5cf618212687e9ab382dbd73e4c69`,
289,061,692 bytes, mode `0600`. ZIP integrity passed and its sole member is
`db-dumps/mysql-tdPSA_.sql`. It was created with backup notifications disabled after the clean Mail
test/runtime readback; no seeding or production action occurred.

The latest 2026-08-24 12:47 CEST direct storage readback has 1,445 files, 968 referenced, 477 unreferenced, 28 missing raw
references, 79 non-private modes, 15 duplicate groups, and zero unsafe/unreadable entries. It finds
32 of 34 expected attachment parts on exact source rows. Provider reconciliation confirmed message
479 absent from its source placement, hid placement 478 and soft-deleted that local cache; it remains
without raw or attachments. Same-identity message 650 remains active in Trash with independent raw
plus two-attachment evidence.
Messages 456 and 478 each retain one attachment file but no raw snapshot. These current facts
supersede earlier 30/34 and 34/34 completion claims without authorizing substitution or deletion.

The pre-attachment-recovery post-migration checkpoint is
`/var/Projects/tdPSA/storage/app/backups/recovery-incident-20260821/post-migration-clean-state-20260821T114021Z.sql.gz`,
with SHA-256 `ea65f5f85289cf3d3569ff778a721426f2e803fe9a75d9e9e7b448cbea2dab4a`.
The latest post-Mail-recovery backup is
`/var/Projects/tdPSA/storage/app/backups/recovery-incident-20260821/post-migration-mail-recovery-20260821T115109Z.sql.gz`,
with SHA-256 `467d4cf18ab54726fb0f32b82eb70ed793499e9c54c7c6ccaffbd0e05eb1b009`
and mode `0600`.

Historical 2026-08-21 recovery record: the restored attachment baseline had zero attachment rows and
only counter sum 6. An exact 19-ID preflight preceded local apply, which restored 30 rows/files across
16 messages; the idempotent rerun was unchanged. At that checkpoint four expected parts for messages
`456`, `478`, and `479` were unrecovered because the fail-closed provider resolver returned
`dns_answer_set_denied`; those calls made no database, file, or provider mutation. Its read-only
inventory recorded 969 files, 492 referenced and 477 unreferenced: `raw` 547 (462 / 85),
`attachments` 100 (30 / 70), and `sent_pending` 322 (0 / 322), plus 28 missing raw references, 79
non-private files, 15 duplicate unreferenced groups, and zero unsafe or unreadable files. The current
2026-08-24 evidence above supersedes those counts. No deletion ran.

Recovery and deployment notes: seeding was deliberately rejected. Do not run `db:seed`,
`DatabaseSeeder`, `RoleSeeder`, or `AdminUserSeeder`: these are not recovery tools, can create demo or
default-admin data, and the current full role synchronization can remove valid module-owned grants.
No seeding ran during recovery. Preserve the existing application key and restored ciphertext; do
not generate a new key. The current additive forward path and fail-closed Mail live repairs have been
applied and read back as listed above. Keep normal provider work gated until the open queue, browser,
and human checks are completed; do not substitute a seeder for a forward migration.

Tests must use an isolated test database whose target is proven before PHPUnit boots. Never run
`migrate:fresh`, `RefreshDatabase`, destructive migration tests, or a cached-config test process
against the shared Dev database. Pre-incident migration batch numbers in other review entries are
historical evidence only; current migration status and batch numbers must be read back from the
recovered database.

Risks: all database writes between the backup and incident may be missing; filesystem content newer
than the snapshot can be orphaned or referenced differently; restored jobs, sessions, tokens, and
integration work may be stale; application-key drift can make provider and account secrets
undecryptable; broad seeding can silently alter users, permissions, clients, and Knowledge data; and
restarting Email workers before migration and queue review can cause provider or notification side
effects. No file should be deleted merely because the recovered database does not reference it.

Automated and operator verification: source/final backup checksums, dump metadata, the complete
migration ledger, all-table checks, permission/role totals, account source/binding state, timestamp
repair outcomes, repair-cursor completion, attachment recovery/inventory outcomes, and queue/session
containment were read back after recovery. Earlier Mail tests validate the code candidate, not
browser behavior or human acceptance
of the restored database. No authenticated post-restore browser review or named human approval is
claimed.

The 2026-08-24 completion candidate additionally passes the clean complete Email Feature directory
at 686 tests / 7,046 assertions, Notification Feature at 110 / 1,030, and the focused
provider-permission/deployment package at 9 / 135.
Local web smoke returns 200 for `/login` and an expected unauthenticated 302 for `/tech/mail` and
`/tech/admin/settings/email/accounts/create`, with no 500. This does not replace the still-open
authenticated Admin/browser checks or named review.

Human checks:

- [x] Review and confirm all recorded backup paths and SHA-256 values, including that the
  pre-attachment checkpoint remains preserved and both the recovery and 2026-08-24 post-completion
  backups are mode `0600` with successful archive integrity.
- [x] Review the sanitized evidence for database identity, all 351 successful table checks, all 234
  migration records, and representative counts from Users, Clients, Tickets, Email
  accounts/messages/folders, permissions, queue tables, and other business-critical domains.
- [x] Review the known 2026-08-15 through 2026-08-21 loss window with a named owner. Record which
  missing records can be reconstructed from filesystem, provider, notification, or other external
  evidence; do not import or delete anything under this review.
- [x] Confirm the application key is unchanged and sample restored encrypted settings and Email
  provider/account credentials through a redacted, no-network decrypt/readiness check. Do not print
  plaintext, ciphertext, endpoints, usernames, or raw provider errors.
- [x] Confirm the exact recovered batch sequence 95 through 123 recorded above, and confirm no
  unexpected schema/table was created by the incident or recovery. Preserve the sanitized
  `migrate:status` and all-table-check evidence for the review.
- [x] Review the historical permission checkpoint after migration `2026_08_21_100000`: all eight
  approved Mail permissions existed, Admin had 167 total grants, Superuser had 216, every other role
  total was unchanged, both Email accounts were then legacy/unbound, and no `RoleSeeder`,
  `AdminUserSeeder`, demo seeder, or broad permission synchronization ran. Separately confirm the
  current Integration-owned version-2 bindings for both accounts without treating them as part of
  that historical schema-only proof.
- [x] As an active authorized Admin, open **Admin > Settings > Email accounts > Create** and the
  Integration-owned Email-provider pages and confirm the reported 403 is repaired without provider
  I/O. Repeat the negative cases for missing permissions, inactive/system users, private-provider
  authority, and inaccessible opaque IDs.
- [x] Confirm Mail live invalidation, collaboration, and acknowledgement feature gates remain
  fail-closed; no live provider verification, send, poll, source switch, cutover, secret purge,
  broad Telescope deletion, or mailbox mutation occurred during recovery.
- [x] Confirm the inbound Ticket-message repair completed through ID 363 in four pages; review the
  removal of the restored stale Economy job and invalidation of one restored session; and confirm the
  failed IMAP job remains preserved without blind retry/deletion. Review all remaining queue,
  schedule, token, provider-operation, and idempotency state before normal work resumes.
- [x] Review the historical exact 19-ID attachment preflight, 30-row/file local apply, unchanged
  rerun, and fail-closed `dns_answer_set_denied` calls, confirming they changed no database, file, or
  provider state. Reconcile that checkpoint with the current 32/34 exact-source readback: message 479
  is a soft-deleted cache with hidden/provider-missing placement 478 and still lacks raw/attachments;
  message 650 remains active independent same-identity Trash evidence; and messages 456 and 478 lack
  raw snapshots. Reconcile the current 1,445 / 968 / 477 / 28 / 79 / 15 storage
  inventory and perform no cleanup.
- [x] Run the focused Mail permission and fail-closed runtime tests only against a proven isolated
  test database, then perform authenticated Dev browser and HTTP smoke checks across login, core
  records, Mail, Tickets, attachments, and downloads.
- [x] Review the 136 unattempted inbound notification-fanout jobs and already-enabled Web Push
  settings. Explicitly decide the bounded cohort's disposition before starting a `notifications`
  worker or the full Laravel scheduler.
- [x] Svein explicitly authorized normal Dev traffic and the targeted schedules/workers to resume.
  This runtime authorization does not accept, remediate, or close the August 15–21 loss window.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: The 2026-08-15 backup was imported, current forward migrations and bounded repairs
were applied, both post-migration checkpoints were recorded, and no seeding ran. The historical
bounded local attachment recovery restored 30 parts; current direct readback finds 32/34 exact-source
parts, while exact-source disposition and raw-snapshot evidence review remain open.
Svein explicitly approved the recovery and Mail permission-repair handoff on 2026-08-21, then
separately authorized normal tdPSA schedules and workers to resume. The preserved crontab with
SHA-256 `a41a4c79a315af436ccf475a6191d9b0868f85d606eb5686814bcb1512e680ff` was installed unchanged at
14:35 CEST. The 14:36 cycle started the expected Email and Supplier Orders database workers; the
queue readback remained at zero pending jobs and one preserved historical failed job. The first
restored `email:poll --account=1` attempt failed closed before IMAP with sanitized `IMAP_CONNECT`
because the endpoint security boundary rejected the DNS answer set. It created no queued job or
Email message, and neither runtime log contained a command-level error. Authenticated browser
verification and the separately reviewed private/provider-binding remediation remain open. A
sanitized follow-up classified the account's single answer as non-public and confirmed that, at the
time of the failed restart, no legacy private mapping or named trusted CIDR was configured. To
prevent minute-by-minute health/log churn, Email polling alone was re-paused; the Supplier Orders
schedules and the Email/default and
Supplier Orders workers remain enabled. The installed bounded crontab is preserved at
`storage/app/backups/recovery-incident-20260821/tdpsa-crontab-email-poll-paused-20260821`, SHA-256
`087a575a8292b45b6da2845757243a10f6954e408c4b95eff5468c287bae6679`, mode `0600`. The 14:42 cycle
started both expected workers, produced no new polling warning, and left zero pending jobs, one
historical failed job, and 490 Email messages. This entry remains `In Review`.

Follow-up: Svein explicitly approved the current Dev endpoint as exact RFC1918 IPv4 `/32` group
`tronderdata_mail_dev`. The value was written only to ignored Dev environment configuration without
being displayed. After cache clear, sanitized readback confirmed one exact rule shared by IMAP and
SMTP, successful policy authorization, no legacy compatibility mapping, both accounts still
legacy/unbound, and zero provider connections, credential versions, or events. No provider call,
stage, activation, cutover, source switch, or polling resume occurred.

### HR-2026-08-16-014 - Email/Ticket Correlation Conflict Triage

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev working copy; implementation and human review are missing
Related: `docs/feature-slices/2026-08-19-email-ticket-correlation-conflict-triage.md`

Scope and affected workflow: preserve durable links and RFC headers as authoritative, keep
`TD-...` as an additive fallback, and introduce durable, audited conflict detection and human triage
for inbound Email-to-Ticket correlation.

Deploy / migration notes: no conflict table, migration, triage action, UI or deploy action is ready.
Do not claim or activate this workflow until a complete reviewed implementation exists.

Risks: guessing between contradictory evidence can link mailbox content to the wrong Ticket or widen
its audience. Existing correlation behavior must remain unchanged while the slice is incomplete.

Automated verification: no focused Order 14 test exists.

Human checks:

- [x] Review and approve the complete conflict evidence/data model and deterministic precedence.
- [x] Verify conflicting durable link, RFC header and `TD-...` cases remain unresolved until an
  authorized human records an auditable choice without moving or publishing source mail.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: The 2026-08-19 summary listed `Done`/Junie without a detailed review entry. Reclassified
on 2026-08-21 because the implementation is missing.

### HR-2026-08-16-013 - Email/Ticket Conversation Relationship Migration

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev working copy; isolated SQLite verification plus an actual disposable,
socket-only MariaDB 10.11.14 migration contract; migration `2026_08_24_130000` ran in Dev batch 127,
both ledgers remain empty, and no preview/backfill/runtime apply ran
Related: `docs/feature-slices/2026-08-19-email-ticket-conversation-relationship-migration.md`

Scope and affected workflow: migrate legacy Email/Ticket evidence into first-class conversation
links while preserving the source mailbox, audience and `TD-...` correlation.

Deploy / migration notes: deploy additive ledger migration
`2026_08_24_130000_create_email_ticket_conversation_link_migration_ledger.php` before using the
command. It creates empty schema only and refuses rollback after evidence exists. The migration ran
in Dev batch 127 after backup/schema review; no preview/apply run, provider operation, queue/worker/
cron change or production action was performed by Order 13. Preview the exact cohort with
`email:backfill-ticket-conversation-links --actor=<active-human-id> --limit=100`; do not use
`--apply=<reviewed-public-id>` on shared data until the disposable-copy checks below pass.

Risks: legacy account/conversation/Ticket/audience evidence may conflict or drift after preview. Such
items block or fail stale without a link; Order 14 must resolve conflicts. Apply inserts a durable
primary relationship and therefore requires exact cohort review, worker observation and a tested
backup/rollback plan. A next-page queue dispatch can fail after one bounded page commits; the run now
records terminal `continuation_dispatch_failed`, preserves its ready rows and requires a new reviewed
preview to resume. The broader first-class relationship/capture/event model remains incomplete.

Automated verification: explicit SQLite `:memory:` with isolated `APP_CONFIG_CACHE`, array cache,
maintenance and session stores, and `HOME=/tmp`: focused Order 13 coverage passes 17 tests / 150
assertions. It covers schema, read-only/sanitized preview, exact human attribution, command dispatch
separation, side-effect-free apply, customer/internal audience, idempotency, competing claims,
missing provenance, stale evidence, cross-operator denial, committed-page continuation-dispatch
failure, fresh-preview remainder recovery and the final-attempt failure hook. Adjacent Email conversation identity,
current Ticket-link intake, provider-deletion retention, Ticket not-Ticket and merge coverage passes
15 / 116 (32 / 266 combined). The combined Order 12/13 opt-in migration contract passes 1 / 52 on
MariaDB 10.11.14. For `130000` it proves up, every named index and foreign key, exact valid JSON
metadata keys (`message`, `ticket`, `placements`, `conversation`) and fingerprints, invalid-JSON
rejection, empty down and non-empty evidence refusal. Cleanup reported zero matching random schemas,
then stopped the socket-only daemon and removed its `/tmp` datadir. Pint passes for the changed PHP
files.

Human checks:

- [x] On a disposable current-schema data copy, run preview with one named active human holding
  `email.account_manage`, `email.mailbox_sync_manage`, and `ticket.update`. Confirm the public ID,
  fingerprint, cap and candidate/ready/already-mapped/conflict/failed counts before apply.
- [x] Inspect blocked missing/merged Ticket, ambiguous placement/account, competing-primary,
  secondary-reference, missing-provenance and unknown-audience fixtures. Confirm no link is created
  and only safe IDs, statuses, reason codes and hashes enter the ledger.
- [x] Apply the exact reviewed public ID with the same human. Confirm the Email worker creates one
  active primary link with that operator in `linked_by`, preserves customer/internal audience and
  completes every ledger item; repeat the job/preview and confirm no duplicate.
- [x] Change source evidence or revoke/disable the actor after preview. Confirm apply fails stale or
  unauthorized with a terminal ledger state and no partial link for that item.
- [x] With at least 26 ready items on a disposable copy, fail dispatch of the second page after 25
  commit. Confirm the first run is terminal `continuation_dispatch_failed` with 25 applied and one
  ready, then review/apply a fresh preview and confirm it classifies 25 mapped and applies only the
  remaining item. Confirm the queue final-attempt hook cannot overwrite the more precise result.
- [x] Compare source Email/placement/provider flags, personal unread/opened, Ticket/message/event/tag/
  classification, rules, Signals, notifications, portal and outbound/provider operations before and
  after preview/apply. Confirm only the relationship and migration ledger change and the source stays
  visible under ordinary Mail placement/access predicates.
- [x] Exercise the empty-ledger rollback in a disposable database, then create preview evidence and
  confirm rollback refuses to erase it. Verify the real Email worker remains healthy and reports any
  failed migration job instead of silently continuing.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: The 2026-08-19 summary listed `Done`/Junie without detailed review evidence. The
2026-08-24 safety rework closes the documented absent-relationship, arbitrary-actor and missing
dispatch/verification defects. Named disposable-copy and runtime review is still required; AI has
not marked this entry Reviewed.

### HR-2026-08-16-012 - Email Conversation Acknowledgement And Explicit Multi-Account Actions

Status: Pending (Safety Rework Implemented; Activation Gated)
Added: 2026-08-16
Environment: Authoritative Dev working copy; historical migration `2026_08_19_150000` is an inert
marker in recovered Dev batch 120; additive `2026_08_24_140000` ran in Dev batch 128 with empty
ledgers and its gate false; isolated SQLite plus disposable MariaDB verification; no provider call,
personal-state runtime action or acknowledgement apply
Related: `docs/feature-slices/2026-08-19-email-mail-conversation-acknowledgement-multi-account-actions.md`

Scope and affected workflow: acknowledge only the exact authorized conversation-placement snapshot
selected by one user, while keeping provider Seen and unselected/future account occurrences separate.

Implemented boundary: new run/item evidence freezes exact account, conversation, message, folder,
placement, UID namespace/UID, current access epoch, provider-binding and sync versions, before/target
values and immutable fingerprints. Preview is read-only and bounded to the active account
conversation or exact selected placement IDs. Apply requires the same actor, claims at most 25
items, reauthorizes every placement and changes only that actor's current-epoch state through
`SetEmailUnreadForMe`. Optional provider Seen separately requires Organize and creates one pending
idempotent `RecordEmailRemoteOperation` row without IMAP. Personal success remains truthful if the
provider half is denied, stale, conflicted or failed. Safe item statuses/reason codes retain that
partial result. Future arrivals, unselected accounts and other users are outside the frozen run.
One message with several active placements freezes one selected personal effect per account/message/
access-epoch/target; later placement items are immutable `coalesced` evidence while provider work
remains selected per placement. Run outcomes ignore coalesced rows as success, so a selected failure
cannot be masked.

Deploy / migration notes: leave inert `150000` unchanged. Review additive forward migration
`2026_08_24_140000_create_email_conversation_acknowledgement_action_ledger.php` on a disposable
current-schema copy before coordinated deployment. It later ran in Dev batch 128; both ledgers are
empty and the gate remains false. The empty down path is reversible; once either ledger contains
evidence, rollback refuses to erase it. The migration
itself creates no preview, personal state, provider operation or external call. Keep
`EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED=false` after migration: the implicit legacy action now rejects,
and the Livewire method cannot bypass the still-missing public preview/confirmation interface.

Risks: accidental feature activation before accessible confirmation/continuation exists; migration
and index behavior on the deployment engine; stale/revoked placement, epoch, UID or provider binding;
truthful partial provider reconciliation; hidden-account leakage; and broad Archive/Move/Trash
actions retargeting an open shared composer. Those broader UI/API/action paths and private
invalidation remain Order 8/9/full-slice gated.

Automated verification: explicit SQLite `:memory:` with isolated `APP_CONFIG_CACHE`, array
cache/maintenance/session stores and `HOME=/tmp`: focused
`EmailConversationAcknowledgementSafetyTest` passes 10 tests / 110 assertions. It covers the
default-off gate, forward schema and rollback refusal, preview no-mutation, active/future/explicit
multi-account membership, inaccessible selection, exact actor/snapshot reauthorization, sanitized
personal/provider partial failure, Organize revocation, provider pending/succeeded redelivery without
IMAP or duplication, canonical multi-placement personal coalescing, unmasked selected failure,
break-glass denial and the closed implicit action. The combined focused plus
historical-quarantine, unread-epoch and remote-operation recovery package passes 60 / 522. An
isolated authorized Mail workspace render smoke adds 1 / 11, for 61 / 533 recorded handoff coverage.
The combined Order 12/13 opt-in migration contract passes 1 / 52 on MariaDB 10.11.14. For `140000`
it proves up, every named index and foreign key, default-off configuration and boolean schema
defaults, empty down, non-empty evidence refusal, and exact `pending` / `coalesced` status plus
selected/non-selected round-trip for canonical multi-placement evidence. Cleanup reported zero
matching random schemas, then stopped the socket-only daemon and removed its `/tmp` datadir.

Human checks:

- [ ] On a disposable current-schema copy, inspect `140000`, run the empty up/down path, create one
  preview, and confirm rollback refuses to delete its run/item evidence. Confirm inert `150000` and
  the absent old table remain unchanged.
- [ ] With the feature flag enabled only in the disposable environment, preview one named user's
  active account conversation. Confirm exact placement/UID/epoch/binding evidence, 100/500/20/15
  bounds, no personal/provider mutation during preview, and no subject/body/participant/filename/
  private-path/credential/raw-provider-error evidence.
- [ ] Add mail after preview and include a correlated copy in an unselected account. Apply with the
  same user and confirm only frozen personal states change; future/unselected mail, other users,
  baselines, opened receipts, Ticket/rule/Signal/Notification/draft state and provider flags do not.
- [ ] Select exact placements across two authorized accounts, then test an inaccessible account,
  another actor, revoked View/Organize, changed epoch, moved/missing placement, UIDVALIDITY/UID/sync
  drift and provider-binding drift. Confirm non-enumerating denied/stale results and no substitution.
- [ ] Request optional provider Seen and confirm personal and provider results stay separate. Observe
  one pending remote operation per exact placement, existing worker acknowledgement/reconciliation,
  provider failure/conflict and redelivery without duplicate personal or provider work.
- [ ] Put one EmailMessage in two active folders. Confirm preview selects one fingerprint-bound
  personal effect, marks the later item coalesced, changes personal state once, still reserves two
  placement-bound provider operations, and never reports false stale/partial or masks selected
  personal failure.
- [ ] Verify the eventual Livewire/API preview and explicit confirmation controls on desktop,
  keyboard and mobile before enabling. Confirm the legacy callable method never mutates directly,
  Order 8 remains disabled-safe and an open shared draft cannot be silently retargeted.

Reviewer:
Reviewed date:
Result / notes: The 2026-08-19 summary listed `Done`/Junie without detailed review evidence. The
2026-08-24 safety rework closes the absent relationship, implicit scope, missing action-time
authorization and personal/provider conflation. AI has not marked this entry Reviewed. Named
disposable migration, runtime/provider and eventual UI/API review remain required.

### HR-2026-08-16-011 - Email Compose, Draft, Send, And Sent API Parity

Status: Pending (Private/API Rework Implemented; Shared Collaboration Gated)
Added: 2026-08-16
Environment: Authoritative Dev working copy; isolated SQLite/fake-storage/fake-SMTP verification;
additive `2026_08_24_110000` ran in Dev batch 124, preserved the one private draft, and left the
outbound ledger empty; no provider call or SMTP runtime test
Related: `docs/feature-slices/2026-08-19-email-mail-compose-draft-send-sent-api-parity.md`

Scope and affected workflow: Compose, Reply, Reply All, Forward, provider-Draft editing, local
autosave/manual save/discard, durable attachments, exact signed preview, SMTP submission, Email log,
and provider Sent status in `/tech/mail` and `/api/v1/email/mailbox`.

Implemented private boundary: the cross-user active-draft query is closed. Drafts have explicit
`private` scope, opaque public identity, immutable generation evidence, and a non-reversible HMAC
version token. Mutations recheck the exact owner, generation, active placement, mailbox access, and
provider binding. Attachment resources expose no storage path/checksum/generation. Livewire and API
call the same `SubmitEmailComposerDraft` boundary, which freezes the prepared signed body/threading
and attachment manifest, elects one version-specific `email_outbound_submissions` row, moves the
draft to `send_reserved`, calls the existing SMTP action once, and links safe Email-log and
Sent-reconciliation status. Accepted requests are idempotently recoverable; unresolved provider
outcomes remain reserved and reject the same or a new key without SMTP. Exact normal Sent observation
marks the submission `sent_reconciled`.

Deploy / migration notes: review additive migration
`2026_08_24_110000_add_private_draft_fencing_and_outbound_submissions.php` on a disposable database
copy, then coordinate normal deployment/migration. It later ran in Dev batch 124; its backfill kept
the existing draft private and the submission ledger is empty. Rollback refuses to drop non-empty
submission evidence. No provider settings or provider mailbox was changed. Historical Order 9
migration `140000` remains an inert marker; `EMAIL_MAIL_COLLABORATION_ENABLED` remains false and
shared drafts/leases/`423` behavior must
not be activated by this review.

The new draft/attachment public and generation columns are intentionally nullable for rolling-deploy
compatibility. Migration `110000` backfills rows present during the run; current model `creating`
hooks guarantee opaque IDs/generation for all new application rows. Old application writers must be
drained during cutover, followed by an explicit zero-null check. Schema-level `NOT NULL` is a later
reviewed hardening step after the rolling window, not an invariant claimed by this migration.

Risks: migration/backfill/index behavior on the deployment engine; cross-user/account leakage;
stale client overwrite; concurrent duplicate SMTP; missing/tampered attachments; access or provider
binding loss between load and send; post-acceptance cleanup failure; ambiguous outcome accidentally
presented as retryable; and incorrect same-Message-ID Sent convergence.

Automated verification: explicit isolated `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, unused
`APP_CONFIG_CACHE`, array maintenance/cache stores, `HOME=/tmp`, fake private storage, and fake SMTP.
`EmailComposeDraftSendApiParityTest`, composer lifecycle, post-SMTP safety, and selected-placement
regressions pass 22 tests / 228 assertions. Coverage includes two-user private isolation, shared-scope
rejection, opaque stale fencing, attachment response secrecy, signature/body preview/send parity,
accepted repeat with one SMTP call, unresolved no-retry, binding drift, revoked access, exact Sent
reconciliation, Reply/Reply All/Forward placement, and accepted-follow-up failure behavior. PHP
syntax passes for the changed Order 11 PHP/migration files, and targeted Pint passes for all 23
Order 11 PHP files.

Human checks:

- [ ] Review migration `110000` up/down on a disposable copy containing existing drafts and
  attachments. Confirm opaque IDs/generations are complete, indexes are valid, no content changes,
  empty rollback succeeds, and rollback with submission evidence refuses safely.
- [ ] With two active humans granted the same controlled mailbox, save Compose and Reply drafts in
  both browser/API sessions. Confirm each sees only their own private draft and attachments; a
  `shared` API request is rejected and no Order 9 table/lock is required.
- [ ] Load one draft in two clients, update the first, then use the second client's old token for
  update, attachment removal, provider sync, discard, and send. Confirm every stale mutation returns
  conflict/current safe metadata and never overwrites or contacts a provider.
- [ ] Intercept SMTP and exercise Compose, Reply, Reply All, Forward, and provider-Draft editing from
  web plus one API send. Compare To/Cc, sanitized HTML/plain text, signature, attachment order/hash,
  threading, sender account, and Email log; confirm exactly one SMTP call per submission.
- [ ] Repeat an accepted request with the same key and an unresolved request with the same and a new
  key. Confirm the accepted submission is returned, unresolved remains blocked with `Do not resend`,
  and no second SMTP call occurs. Simulate post-acceptance log/Sent/draft-cleanup failure and confirm
  the result still says accepted with a warning.
- [ ] Revoke mailbox access and rotate the provider binding after draft load. Confirm non-enumerating
  denial/conflict happens before provider access and no submission/provider write is created.
- [ ] Import one controlled same-account Sent placement with the exact reserved Message-ID. Confirm
  submission and reconciliation become `sent_reconciled`; a different account/Message-ID does not
  match and never triggers SMTP or fuzzy correlation.
- [ ] Inspect API responses and logs for private disk paths, checksums, generation IDs, Bcc, raw MIME,
  canonical IDs, credentials, and raw exception/provider text. None may be exposed.

Reviewer:
Reviewed date:
Result / notes: Automated work does not mark this review complete. The private/API beta boundary is
ready for named review; shared collaboration remains separately dependency-gated by Order 9.

### HR-2026-08-16-010 - Email Deterministic Rules API Completion

Status: Rework Needed / Safety Repair Implemented
Added: 2026-08-16
Environment: Authoritative Dev working copy; code and isolated SQLite verification only
Related: `docs/feature-slices/2026-08-19-email-mail-deterministic-rules-api-completion.md`

Scope and affected workflow: complete draft/publish/preview/version/reprocess/retry/Undo parity with
immutable attempts, account scope, precedence and Email/Signal loop protection.

Deploy / migration notes: no Order 10 migration, database-data change, provider call, queue/scheduler
change, cron change, or runtime activation was performed. The 2026-08-24 safety repair removes the
local-only reversal and routes an eligible action through the existing verified provider-operation
Undo ledger. A specifically scoped API token must include `email.rules.execute`; current user,
`email.rule_manage`, mailbox View/Organize, and provider evidence are still checked at request time.

Risks: applying an eligible Undo is a real provider Move and must be tested only with an approved
non-production fixture. Full rule drafts, durable bounded reprocess/retry/full-rerun coordination,
and complete API/OpenAPI parity remain unimplemented and dependency-gated. Mixed/local-only effects
cannot be presented as undone.

Automated verification: isolated SQLite with explicit `:memory:`, array cache, isolated
`APP_CONFIG_CACHE`, array maintenance store, and `HOME=/tmp`: focused Order 10 coverage passes 5 /
65; adjacent Email Undo, supervised cleanup, inbound automation, and Integration passes 91 / 875;
targeted existing rule publication/API/runtime passes 3 / 36. Pint passes for the changed PHP files.

Human checks:

- [ ] Review and test the full rule draft/publish/version/preview/reprocess/retry contract.
- [ ] With one approved non-production provider Move execution inside the 15-minute window, verify
  eligibility, apply Undo once, confirm the exact source folder/UID, and repeat the request to confirm
  that no second inverse/provider mutation is created.
- [ ] Verify a mixed-effect, local Archive/tag, stale-target, and target-mismatch attempt is unavailable
  and creates neither an inverse operation nor local compensation.
- [ ] Verify inaccessible account/attempt IDs return Not Found, View without Organize reports current
  authorization failure, and execution output contains no subject, address, folder path, before/target
  snapshot, raw exception, or raw provider message.

Reviewer:
Reviewed date:
Result / notes: The 2026-08-19 summary listed `Done`/Junie without detailed review evidence. The
2026-08-24 safety repair closes the known local-only Undo/raw-failure defect but does not close the
full Order 10 slice or this review.

### HR-2026-08-16-009 - Email Presence, Shared Draft Locks, And Stale-Composer Protection

Status: Pending (Backend/API Safety Rework Implemented; Runtime/UI Gated)
Added: 2026-08-16
Environment: Authoritative Dev working copy. Historical migration `2026_08_19_140000` remains an
inert marker in recovered Dev batch 119. Forward migration
`2026_08_24_125000_add_email_shared_draft_coordination.php` ran in Dev batch 126 after Order 11;
the existing draft remains private, shared ledgers are empty, and collaboration/UI/live gates remain
false.
Related: `docs/feature-slices/2026-08-19-email-mail-presence-shared-draft-locks-stale-composer.md`

Scope and affected workflow: default-off API/backend boundary for authorized ephemeral
reading/typing coordination, explicit private-to-shared reply drafts, one current editor,
lease/fence/content/source versioning, stale rebase/discard and once-only shared submission. The
legacy `MailWorkspace` SQL-lock/presence and Echo whisper fallback is removed; workspace
collaboration now requires private-live readiness, the separate UI flag and `EmailCollaborationGate`.

Deploy / migration notes: preserve inert `140000`. Order 11 migration `110000` and additive `125000`
ran in Dev batches 124 and 126 with `umask 0002`; exact FKs/indexes/backfill and empty/non-empty
guarded-down behavior were read back, and no shared draft/lock/event evidence was created.
Do not enable `EMAIL_LIVE_ENABLED`, `EMAIL_MAIL_COLLABORATION_ENABLED` or
`EMAIL_MAIL_COLLABORATION_UI_ENABLED`, rebuild an enabled asset, start Reverb/`email-live`, or make a
provider call during migration review. The config-first gate must return unavailable without asking
the optional schema when either server flag is false; a fully enabled gate additionally requires
Order 8 private runtime readiness.

Risks: cross-user draft access, concurrent/stale send, false presence, orphaned locks and content
leakage through cache/events/API, irreversible attachment deletion before SQL commit, and enabling
collaboration ahead of its private transport/UI review.

Automated verification: isolated SQLite `:memory:`, array cache, unique `APP_CONFIG_CACHE`, cache
maintenance and `HOME=/tmp`: `EmailSharedDraftCoordinationSafetyTest` passes 9 tests / 122 assertions;
the relevant workspace/quarantine set passes 20 / 193. They cover config-first/live
readiness, presence permissions/multi-tab filtering/no SQL, cross-user isolation, idempotent share,
one lease, expiry takeover/monotonic fence, stale-token `423`, stale rebase/body/attachment
preservation, stale discard, one submission/provider call and rollback-safe attachment files. The
adjacent Order 11 composer/submission/Sent set passes 22 / 228. A fresh default-off `npm run build`
selects `assets/app-DjAfqa_z.js`, SHA-256
`1fc6ecddbe9242c244e0cf2658f7a2af7e265d9d2b3b6830feda999b707ed2a5`; the selected and retained
bundles contain zero legacy presence/whisper markers and retain the current private-live markers.
No shared database, Redis, Reverb, browser, worker, provider or production check was run. The opt-in actual MariaDB contract
passes 1 / 44 against a random disposable schema on an isolated `/tmp` server: up/backfill/named
indexes/FKs/default-off, empty down, and refusal for independent shared-draft/lock/event evidence all
pass. `finally` dropped the schema, and an independent `information_schema` prefix readback returned
zero before the temporary server and datadir were stopped and removed. This does not mark the human
migration inspection complete.

Human checks:

- [ ] On a disposable current-schema MariaDB copy, inspect and run `125000` up. Verify existing rows
  remain private, exact conversation/source backfill is correct, shared scope is unique, one lock
  row per draft is enforced, event evidence is content-free, and an empty down succeeds while a
  non-empty lock/event/shared draft refuses rollback without erasing evidence.
- [ ] With all runtime flags still false, verify private Compose/Reply/Reply All/Forward, attachments,
  send and Sent status remain available, while every presence/share/shared-draft endpoint returns
  unavailable without a schema query/provider access.
- [ ] After Order 8 is separately reviewed and in an approved non-production runtime, enable the
  server collaboration gate only. With two authorized ordinary shared-mailbox users and two tabs,
  verify reading 45-second and typing 25-second expiry, multi-tab aggregation, 10-second floor,
  leave/disconnect, revoked View/Send filtering and absent indicators during Redis/Reverb loss. Verify
  no SQL heartbeat, content-bearing cache/event/log payload or personal/delegated/break-glass scope.
- [ ] Explicitly share one Reply draft, repeat the exact request, and verify the same result. Confirm
  View-only may read but not edit, an unrelated user/account/source gets Not Found, and responses do
  not expose lease tokens/hashes, generations, fingerprints, paths, checksums or provider evidence.
- [ ] Verify acquire/renew/release, active-holder `423`, explicit expiry takeover with a larger fence,
  and denial of every old-token save/upload/remove/rebase/discard/send. Revoke Send while open and
  confirm the immediate pre-provider check produces no provider call.
- [ ] Introduce a new inbound/current source, confirm save/preview/send is stale with no submission or
  provider call, then confirm rebase recalculates source/recipient/subject/thread while preserving
  authored body/eligible attachments. Confirm stale discard remains possible under the exact lease.
- [ ] Send once and repeat the same idempotency key. Confirm one SMTP attempt, one Order 11 outbound
  submission, safe Accepted/Sent reconciliation, released lease, post-commit attachment cleanup and
  no retry after unresolved provider outcome.
- [ ] Only after the backend review passes, review the accessible desktop/mobile Livewire controls,
  status text (not color alone), focus/keyboard behavior and truthful unavailable state before the
  separate UI gate is enabled.

Reviewer:
Reviewed date:
Result / notes: The 2026-08-19 summary listed `Done`/Junie without detailed review evidence. The
2026-08-24 rework closes the beta-critical backend/API safety boundary but does not activate or
human-review Order 8 transport, MariaDB deployment, Redis/Reverb, browser UI or provider behavior.

### HR-2026-08-16-008 - Email Private Live Invalidation And Polling Fallback

Status: Rework Needed
Added: 2026-08-16
Environment: Authoritative Dev working copy; migration `130000` is Ran in recovered Dev batch 118,
forward repairs `2026_08_21_110000` and `120000` are Ran in batches 122 and 123, server config/cache and Vite assets
keep runtime disabled, and the unsafe unsupervised debug Reverb listener was stopped. Historical
Order 9/12 filenames are inert markers in batches 119 and 120 with both tables absent. Full implementation and
named review remain pending. Forward repair `2026_08_24_120000` ran in Dev batch 125 with four
replacement UPDATE guards present and live ledgers empty; `EMAIL_LIVE_ENABLED`,
`EMAIL_LIVE_RUNTIME_APPROVED`, collaboration/UI/acknowledgement and the Vite gate remain false.
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-16-email-live-invalidation-user-stream-fanout.md`,
`docs/feature-slices/2026-08-16-email-mail-private-live-invalidation-polling-fallback.md`, and
`HR-2026-08-16-007`

Scope: Add one permanent private user channel for opaque Mail invalidation hints, durable account and
global source streams, bounded crash-resumable source-to-user fanout, per-user projection versions,
and a 15-second automatic polling fallback. Database versions and current authorization remain the
authority. Events and browser manifests contain no subject, sender, body, filename, Ticket detail,
provider metadata, credential reference, unread count, or canonical identity. Late grants never
replay old source events; revocation and access-boundary changes force a bounded current refresh.

The browser subscribes once regardless of mailbox count. It catches up on connect, reconnect,
online, `pageshow`, and visibility resume; hidden/offline tabs stop periodic work. A healthy socket
still performs a 120-second bounded safety refresh. Scrolled lists pin at most the current 25
authorized rows, remove revoked/deleted rows immediately, and expose an authorized **New mail (N)**
control instead of moving the viewport. Livewire remains the sole Alpine owner.

Affected pages / workflows: `/tech/mail` list, counts, selected conversation, navigation, personal
unread/opened state, classification, Ticket links, mailbox access, and new-mail presentation;
module-local private broadcast authorization; Email Admin live-mode/health information; scheduler,
the dedicated `email-live` worker, Reverb, Apache/Plesk WebSocket proxying, CSP/origin policy, and
deployment monitoring. Taxonomy/global changes and account/access mutations participate through
explicit transactional invalidation writers rather than broad model observers.

Rework record: the 2026-08-19 summary marked this slice `Done` even though this detailed entry and its
human checks remained Pending. The 2026-08-21 audit found an unusable stream trigger, missing append
idempotency/NULL evidence, wrong authority table names, an unused broad global auth route, duplicate
subscriptions, a client that connected while disabled, incomplete fanout phases and writers outside
the required transaction boundary. Runtime is now fail-closed: `EMAIL_LIVE_ENABLED` and the Vite
gate default false, invalidation becomes a safe no-op, auth returns 503, no global auth route exists,
and the disabled build contains no Echo/Pusher client. Even a build with its client gate enabled
exposes only a lazy initializer; the server-rendered Mail workspace must independently enable live
mode before it can open a socket. Module auth now enforces active/non-system/exact canonical user
identity with auth/tech/2FA/CSRF/dedicated throttle. These repairs do not complete the publisher,
fanout, fallback, authorization-generation, retention or operational contracts.

The 2026-08-24 static rework freezes publication evidence in the source transaction, requires stable
call-site identities, adds exact conversation IDs and missing outer transactions, and replaces the
publisher with bounded raw candidate/delivery/summary claims, finite recovery/blocking and no false
source seal. Catch-up is capped and generic on unsafe state; a signed user/version/epoch/global-
generation receipt is required before acknowledgement or retention. The client now implements one
channel, five-second degradation, visible 15-second polling, 15/30/60 HTTP backoff, 120-second safety
checks and hidden/offline pause. Reverb origins and CSP are exact, and a separate operations approval
gate prevents an accidental environment toggle. Guarded retention protects unfinished fanout and
unacknowledged user versions.

Deploy / migration notes: Migration `130000` ran in recovered Dev batch 118 before this audit;
forward-only `2026_08_21_110000` ran in batch 122 after a SQLite contract test and a MariaDB pretend
review. Fail-closed runtime quarantine `2026_08_21_120000` then ran in batch 123; it removes only incomplete base writer
guards while disabled and repairs valid same-second stream/acknowledgement transitions. No Mail
content, provider, Ticket, outbound message or projection-change data was created. Keep
`EMAIL_LIVE_ENABLED=false`, `VITE_EMAIL_LIVE_ENABLED=false`,
`EMAIL_LIVE_RUNTIME_APPROVED=false`,
`EMAIL_MAIL_COLLABORATION_ENABLED=false`, `EMAIL_MAIL_COLLABORATION_UI_ENABLED=false`, and
`EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED=false`; do not start Reverb or an `email-live` worker. A future
activation still requires an exact frozen code/schema
candidate, backup rehearsal,
locked Composer/npm versions, production Vite assets, cache/view rebuild with `umask 0002`, a
loopback-only Reverb service, one dedicated `email-live` database worker, the shared scheduler,
exact `/app` and `/apps` TLS proxying, exact allowed origins/CSP socket origin, and private-channel
plus polling-fallback smoke. The current rollback is to keep both live flags false, rebuild config
and assets, and stop Reverb. Forward migration `2026_08_24_120000` ran in Dev batch 125 with live
mode disabled; it is forward-only because the old guards strand retry evidence. Durable
projection evidence remains unless a separately reviewed clean-schema rollback is safe.

Risks: a source mutation could commit without durable fanout, a late/revoked authority path could
retain identifiers, a candidate query could scan an unbounded rejected prefix, a role-wide change
could omit users, pruning could strand unfinished publication, or a stale browser callback could
reinsert revoked content. Socket/auth/proxy/CSP drift could silently force fallback; duplicate
Alpine, listeners, subscriptions, or timers could disconnect Livewire controls or create a request
herd. Every writer, cursor, authority generation, decimal-string version, retention boundary, and
browser lifecycle therefore requires automated and controlled Dev evidence before activation.

Automated verification: the final focused permission/quarantine, trigger portability, invalidator
gate/append/transaction, private event, module-auth, CSP and publisher/catch-up/retention matrix
passes 32 tests / 161 assertions, with adjacent provider projection,
deletion and remote-reconciliation coverage passing 52 tests / 457 assertions. The actual isolated
MariaDB `120000` replacement/transition contract passes 1 test / 14 assertions; its `finally` and an
independent `information_schema` readback both found zero temporary schemas. `npm run build` passes.
This evidence does not activate or approve Reverb, Apache/Plesk, the worker/scheduler, authority
writer/recompute coordination, the dedicated bounded current-page projection, or browser behavior
and cannot change this entry to Reviewed.

Human checks:

- [ ] On backed-up Dev, confirm the additive live schema, source/user/global streams, account/global
  audience generations, exact-user access state, fanout cursors, unique deliveries, retention
  guards, and required indexes exist without generating a source change or provider operation.
- [ ] Verify one visible Mail tab creates exactly one authenticated
  `private-email.user.{currentUserId}` subscription and one listener/timer lifecycle. Confirm a
  guest, inactive user, system actor, or different user ID is denied without unread-baseline,
  break-glass-use, access-event, or other side effects.
- [ ] Create account, personal-state, Ticket-link, classification, and global Taxonomy changes.
  Confirm authorized rows/counts/selection refresh promptly, account/global sources fan out in
  pages of at most 100, duplicate authority paths converge once, and no event/log/failed job exposes
  Mail, Ticket, provider, credential, or canonical content/identity.
- [ ] With more than 100 mixed owner/grant/delegation/break-glass or global candidates, confirm raw
  cursors advance through revoked/future/inactive/duplicate rows, crashes before/after page commit
  resume without omission or duplicate user versions, and deterministic failure visibly blocks the
  source instead of sealing it.
- [ ] Grant, re-enable, future-start, revoke, expire, or replace an authority path after a source
  event. Confirm old events are not replayed through the later path, current revocation removes rows
  on catch-up, and scheduler/socket delay cannot retain content past the 15/120-second refresh
  boundaries.
- [ ] Scroll away from the top while mail arrives. Confirm existing authorized rows remain stable,
  **New mail (N)** appears, clicking it refreshes the bounded page, and revoked/deleted rows disappear
  immediately. Confirm the selected conversation stays only while independently authorized.
- [ ] Stop Reverb or break private auth for more than five seconds. Confirm the degraded notice and
  visible 15-second polling fallback; verify 15/30/60-second HTTP backoff, no hidden/offline timer,
  immediate online/visible/`pageshow` catch-up, and recovery to one socket subscription.
- [ ] Exercise duplicate, delayed, out-of-order, gap, pruned, truncated, and greater-than-250 version
  hints. Confirm decimal strings remain exact, no JavaScript `Number` comparison occurs, and each
  unsafe case performs only the bounded current 25-row authorized refresh.
- [ ] Verify `POST /tech/mail/broadcasting/auth` has web/auth/tech/2FA/CSRF/throttle middleware and
  only its exact route-permission exemption. Confirm Reverb binds loopback, public proxy/origin/CSP
  values are exact, only the public key enters assets, and environment secrets remain `0640` or
  tighter.
- [ ] Verify the scheduler, `email-live` worker, Reverb process, pending/blocked age, backlog, retries,
  failed jobs, socket handshake, and fallback status in health/Admin views. Restart each service and
  confirm no duplicate process, listener, subscription, timer, or immediate failed-job growth.
- [ ] Check `/tech/mail` on desktop and mobile through normal load, future Livewire navigation,
  BFCache back/forward, background/foreground, offline/online, and logout/login. Confirm controls
  remain connected, scroll/degraded/new-mail UI is readable, and only one Alpine runtime exists.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-08-16-007 - Email Provider-Originated Read-Only Reconciliation

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev working copy; final focused SQLite, rolling-schema, disposable
MariaDB, and independent static evidence are complete. The exact 20 Order-1-through-7 migrations
report Ran one per step in recovered Dev batches 98 through 117. Svein's 2026-08-19 review history is
preserved; the entry was reopened on 2026-08-24 for ordinary-folder-cap/progress/runtime changes and
the current controlled provider/browser/scheduler/worker/queue checks.
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-owned-mail-client-domain.md`,
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/feature-slices/2026-08-16-email-mail-provider-originated-reconciliation.md`,
`HR-2026-08-16-001`, `HR-2026-08-16-005`, and `HR-2026-08-16-006`

Scope: Add an account-scoped, resumable, bounded reconciliation cycle that reads provider folder,
UID namespace, UID, FLAGS, size, header, and exact PEEK evidence without creating any provider
write. The scheduled all-account dispatcher is the correctness path; optional IMAP IDLE listeners
only enqueue opaque latency hints. Runs freeze the provider binding, folder scope, UIDVALIDITY,
UIDNEXT/EXISTS/MODSEQ facts, bounded inventory hashes, placement snapshots, import evidence, and
stable end state before projecting provider flags, confirmed moves, copies, Trash, expunge,
reappearance, or folder lifecycle locally. Mailboxes without persistent MODSEQ require two matching
post-import UID+FLAGS inventories before any flag projection.

Unknown messages are read with bounded EXAMINE/SEARCH/FETCH. Normal-size messages use one complete
byte-exact `BODY.PEEK`; oversize messages use bounded header-only PEEK and never request their body.
Reconciliation Store work is content-safe, retryable, artifact- and canonical-map-attested, and
must remain hidden from every list/show/raw/attachment/rule/API surface until its exact local
occurrence is accepted. Historical messages in a folder discovered after the personal unread
baseline remain hidden while a bounded durable viewer-baseline cursor completes. Draft/Sent local
projection, conversation visibility, automation eligibility, and notification delivery occur only
after their respective durable acceptance gates. Reconciliation rule execution has no provider
mutation authority; ambiguous evidence is visible and never blindly replayed.

Affected pages / workflows: **Admin > Settings > Email accounts > Mailbox maintenance** status,
folder progress, start, cancel, blocked UIDVALIDITY and re-baseline link; scheduled all-account
reconciliation; optional IDLE listeners; Email/default/live notification queues; folder, placement,
conversation, personal unread/opened, Draft, Sent, remote-operation, provider-deletion, historical
import, canonical mapping, raw source and attachment surfaces. The maintenance UI exposes only
bounded operational facts and stable codes, never subject, participants, snippets, body, raw
source, attachment filename, endpoint, username, or credential material.

Authorization boundary: maintenance view/start/cancel requires an active non-system human with both
`email.account_manage` and `email.mailbox_sync_manage`, current access to the exact active account,
and non-enumerating nested account/run checks. Reconciliation grants no mailbox View, Organize,
Send, raw-source, attachment, Ticket, Signal, AI, notification, or personal-state authority.
Queued work re-resolves the exact active Integration provider binding and secure endpoint under the
shared provider lock; disable, rotate, revoke, rebind, UID namespace change, cancellation, or
insufficient deadline fails closed before unsafe projection or provider I/O.

Deploy / migration notes: The exact 20 additive Order-1-through-7 migrations `100000` through
`118500` now report Ran one per step in recovered Dev batches 98 through 117. No live provider read/write, IDLE connection,
scheduler enablement, worker restart, account reconciliation, production deployment, commit, or push
was performed during the 2026-08-21 repair. Before production deployment, back up the database and record active
Email provider bindings, account/folder/namespace/placement counts, unresolved provider operations,
historical import/re-baseline/deletion/reconciliation states, queue/failed-job counts, and worker
configuration. Apply the additive migrations in order:
`2026_08_16_118000_add_email_provider_reconciliation.php`,

Production incident update 2026-08-25: polling and the `default,economy,email` worker were live, and
241 messages had been stored since 2026-08-18, but none had an Inbox placement. The production
migration ledger reported `118000` as Ran while the four placement-observation columns and their
authority/index/guard contract were absent. This caused unknown-column errors during ordinary
placement creation and provider reconciliation. Dev now contains forward-only repair migration
`2026_08_25_100000_repair_email_provider_reconciliation_placement_schema.php`, applied in Dev batch
132. Its focused drift regression passes 1 test / 8 assertions and proves idempotent repair while the
historical migration ledger row remains present. Production is unchanged. Before production work,
back up the database, stop affected workers, capture schema/queue/message/placement baselines, apply
only the new repair migration, read back the complete contract, restart workers, and let bounded
provider reconciliation repair the 241 rows. Do not rerun `118000` or use an ad-hoc placement
backfill.

`2026_08_16_118100_expand_email_message_mailbox_for_reconciliation.php`,
`2026_08_16_118200_add_inbound_notification_external_outbox.php`,
`2026_08_16_118300_add_authoritative_target_identity_to_email_remote_operations.php`, and
`2026_08_16_118400_make_email_provider_paths_byte_exact.php`, followed by
`2026_08_16_118500_add_durable_inbound_notification_fanout.php`.
Verify the additive Mail permission repair in batch 121; do not run the full role seeder. Clear
caches; rebuild group-writable views with `umask 0002`;
build assets; and restart every long-lived Email/default/notification worker. Configure the external
Laravel scheduler runner and required Email queue before enabling the optional IDLE setting. No
deploy step may automatically start provider cleanup or any destructive reconciliation.

Rollback notes: stop new reconciliation/IDLE dispatch, let or conservatively cancel bounded active
claims, drain the affected queues, and retain every run/folder/item/outbox/operation audit row.
Migration down is only for a disposable clean schema and must refuse durable reconciliation,
long-path, notification-outbox, authoritative MOVE, or other retained evidence before changing a
column, index, collation, constraint, or table. Provider facts are never rewritten during rollback;
use explicit UID re-baseline for a changed namespace.

Automated verification: the final focused Order-7 SQLite bundle passes **255 tests / 2,225
assertions** across reconciliation core/workflow, finalization, cancellation, retention, automation,
durable fan-out, external delivery, Ticket source identity, and exact Talk targeting. The standalone
durability gate passes **34 / 468**, and the rolling unread-schema compatibility regression passes
**4 / 26**. The existing disposable private-socket MariaDB guard/path/Integration matrix passes
**3 / 434**. The final `118500` contract separately passes **3 / 163** using Laravel's `mysql`
driver against a real private MariaDB server, including partial rerun, strict ledger transitions,
first-seal provenance, repair singleton, index plans, and legal `SET NULL` detach evidence. Targeted
PHP syntax, Pint, diff checks, and an independent current-copy re-audit pass. These are focused
results. The clean final 2026-08-24 complete Email Feature directory additionally passes **686 tests /
7,046 assertions**, including the ordinary-folder-cap and truthful child-progress regressions,
without weakening any production guard. This is not a claim that the complete repository suite is clean. Passing automation does not
complete any manual checkbox.

Controlled account-2 run 1 failed closed at the former ordinary 100-folder cap with zero
observations. Replacement run 2 finished terminal `stale` in `summary` at 2026-08-24 10:25:23 UTC:
137 folders, 131 complete and 6 stale with `provider_tuple_drift`; 8,427 observations; 7 confirmed
missing; 0 moves, conflicts or errors; and a sealed final summary. Provider-wins local projection
hid seven placements and soft-deleted caches where no active placement survived. Three pending
observations belong only to the six stale folders. The run made no provider write and delivered no
notification. There are zero active or queued reconciliation runs/jobs. The failed-job ledger now
preserves both the historical 2026-08-15 `FetchImapAccount` job and run 1's understood fail-closed
2026-08-24 `ReconcileEmailProviderAccount` job; neither was retried or deleted.

Connectivity to the authoritative Dev/Plesk MySQL endpoint is restored. Sanitized, read-only
`php artisan migrate:status` checks completed successfully and report the exact 20
Order-1-through-7 migrations `100000` through `118500` as Ran one per step in recovered Dev batches
98 through 117. Rollback smoke,
authenticated browser/provider checks, scheduler/worker/queue/backlog validation, production
deployment, commit, and push remain operator-gated. Svein's 2026-08-19 review is preserved, and the
2026-08-24 folder-cap/progress/runtime changes plus current checks are the explicit reason this entry
is reopened. SQLite and disposable MariaDB evidence do not replace current runtime checks.

Human checks:

- [x] On one backed-up non-production IMAP mailbox, record initial provider/local folder, message,
  placement, personal unread/opened, Draft/Sent, Ticket/Signal, queue, and failed-job facts. Confirm
  maintenance is hidden/denied for inactive users, system actors, users missing either permission,
  inaccessible accounts, and mismatched nested run IDs.
- [x] Start one manual cycle and confirm folder/UID progress, bounded counts, stable codes, age,
  cancel behavior, latest completed result, pagination, and the UIDVALIDITY blocked/re-baseline link
  are accurate without exposing message content, filenames, endpoints, usernames, or credentials.
- [x] Change Seen, Answered, Flagged, Deleted, Draft, and one custom keyword at the provider; repeat
  on a mailbox reporting NOMODSEQ. Confirm stable changes project only after complete evidence while
  personal unread/opened state remains unchanged and partial/drifting evidence projects nothing.
- [x] Exercise provider-originated move, copy, Trash, expunge, reappearance, folder create, rename,
  delete, case-distinct folders such as `Foo` and `foo`, and, where the controlled provider supports
  it, a byte-distinct trailing-space pair such as `Foo` and `Foo `. Confirm exact UIDVALIDITY/UID identity,
  two-cycle absence, personal state preservation for a confirmed move, visible ambiguity for weak
  evidence, and no cross-account match.
- [x] Import one genuinely new live Inbox message, one post-baseline folder backlog, and matching
  Draft/Sent items. Confirm raw/attachments/canonical mapping are complete before visibility;
  historical items are read-for-me for current viewers; later live mail is unread; Draft/Sent local
  state reconciles once; existing provider moves/copies do not duplicate Ticket, Signal, AI, rule,
  or external-notification effects.
- [x] Force one provider read timeout, worker loss, queue-dispatch loss, Store/artifact failure,
  stale claim, binding rotation/revoke, UIDVALIDITY change, and cancellation during active work.
  Confirm bounded resume or visible partial/blocked outcome, no hidden item becomes visible early,
  no stale flag/absence/move projection occurs, and logs/failed jobs contain only safe codes.
- [x] Enable optional IDLE only for the controlled account. Confirm duplicate/lost/reordered hints
  coalesce, oversized/broken hints disconnect safely, DONE/cleanup occurs, and the scheduled
  all-account catch-up still reaches accounts beyond the first dispatch page after one account/job
  failure. Then disable IDLE and confirm scheduled correctness remains.
- [x] Inspect IMAP/provider audit logs and local remote-operation rows for the complete review.
  Confirm reconciliation issued no send, APPEND, STORE, MOVE, COPY, EXPUNGE, delete, folder write,
  provider archive, or other provider mutation, including through admin and personal rules.
- [x] For the 2026-08-25 production repair, capture a database backup and the pre-migration column,
  index, foreign-key, guard, message, placement, queue, failed-job, scheduler, worker, and latest
  visible-Inbox facts. Stop affected workers, apply only migration `2026_08_25_100000`, and read back
  the exact placement-observation contract before restart. After bounded provider reconciliation,
  confirm all 241 already-stored post-18-August messages have the correct active provider placement,
  the visible Inbox advances beyond 18 August, no duplicate message/Ticket/rule/notification effect
  occurred, and no provider write was issued. Keep the review open if any row remains unplaced.
- [x] Resolve the current runtime gap: Dev has targeted `email,default` processing but no full
  Laravel scheduler or `notifications` worker, and 136 unattempted fanout jobs while relevant Web
  Push settings are already enabled. Review the cohort before activation, then confirm scheduler,
  Email/default/notification worker and backlog health after repeated cycles; verify group-writable
  compiled views, synchronized Email/Integration/Notification Knowledge, and
  no new content, filename, address, provider response, endpoint, username, token, or credential in
  application logs, Telescope, queue payloads, durable error JSON, or failed jobs.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: Historical review is preserved. Re-review remains open for the 2026-08-24
folder-cap/progress/runtime changes and every unchecked controlled-runtime item above.

Dev repair approval 2026-08-25: Svein explicitly approved the forward schema-repair change and
its completed Dev verification. This approval does not complete the unchecked production migration,
provider reconciliation, notification-backlog, or controlled-runtime checks above, so the entry
remains `In Review` until those checks are performed and explicitly confirmed.

### HR-2026-08-16-006 - Integration-Owned Email Provider Credentials And Endpoint Security

Status: Rework Needed
Added: 2026-08-16
Environment: Authoritative Dev working copy; implementation, automated verification, disposable
MariaDB migration contract, independent read-only code-security audit, and the 2026-08-21 additive
permission repair complete; controlled browser/provider/worker rollout, historical telemetry
remediation, and named re-review pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-16-integration-owned-email-provider-credentials-and-endpoint-security.md`,
`docs/feature-slices/2026-08-16-email-mail-integration-provider-credentials-endpoint-security.md`,
`HR-2026-08-16-001`, `HR-2026-08-16-005`, and `HR-2026-08-16-007`

Scope: Integration becomes the sole writer and lifecycle owner for Email provider endpoints,
usernames, and encrypted password credentials. One `type=email_provider` Integration root owns a
normalized connection, exact configuration and credential versions, staged/verified/active/retired/
revoked/destroyed lifecycle, active-version pointer, and append-only metadata-only events. Email
continues to own mailbox identity, defaults, access, health, provider operations, and one explicit
`legacy|integration` provider binding with a positive immutable binding version.

Endpoint security accepts only normalized hostname/IP syntax and the fixed IMAP 993 implicit TLS,
IMAP 143 STARTTLS, SMTP 465 implicit TLS, or SMTP 587 STARTTLS matrix unless a uniquely named
installation policy allows another port. DNS/CNAME work is bounded; every answer must pass one
versioned special-purpose address policy; mixed or unsafe sets fail; one allowed address is pinned
while TLS SNI/peer-name stays on the original host. Certificate and hostname verification,
self-signed rejection, authentication-after-TLS, and TLS 1.2 minimum cannot be bypassed. Private
endpoints require a Superuser, reason, and exact named installation CIDR, and cannot override the
always-denied ranges.

Runtime credentials are redacted, non-serializable, and resolved only under the mailbox provider
lock. Durable provider jobs and ledgers freeze an opaque account and positive binding version, then
re-resolve immediately before provider I/O. Cutover/rebind/rollback makes old work stale before the
network; revoke blocks new authentication; secret-only rotation may resolve the new active version.
Endpoint/username changes require a new connection and mailbox re-baseline. No Integration-bound
operation falls back to legacy fields or Laravel's system mailer. The same strict SMTP boundary now
covers Mail, Ticket, Sales, Marketing, User Management, Customer Portal, Commercial, Notification,
Storage, Booking, inbound routing notifications, and Fortify password reset.

Affected pages / workflows: **Admin > System > Integrations > Email providers** multi-record index,
create, lifecycle and migration pages; **Admin > Settings > Email accounts** safe provider selection,
readiness, activation, and health test; permissions/roles/admin navigation; all IMAP polling,
maintenance, Drafts/Sent, attachment recovery and reconciliation paths; all account-backed SMTP and
notification senders; local Telescope request/exception/job recording and Telescope UI access; and
the bounded `email-provider:telescope-remediate` operator command.

Authorization boundary: `integration.email_provider_manage` belongs to Admin and Superuser for
public providers. `integration.email_private_endpoint_manage` and `system.telescope_view` are
Superuser-only. Provider preview, stage, Verify, cutover, and rollback additionally require
`email.mailbox_sync_manage`; Email binding additionally requires `email.account_manage`. Actions
require an active non-system human and repeat authorization after current rows are locked. None of
these permissions grants mailbox content, raw source, attachment, search, conversation, Ticket,
Organize, or Send access.

Rework record: the summary previously recorded a 2026-08-19 review while this detailed entry and all
human checkboxes remained Pending. On 2026-08-21 the portal returned 403 from Email account creation.
Dev reproduced the exact denial because the provider schema was present while eight approved Mail
security permissions were absent. The forward-only migration `2026_08_21_100000` was added, tested,
and applied to the recovered shared runtime in batch 121. It inserted only missing catalog/default
role rows and reset caches. Sanitized readback confirms all eight approved permissions, 167 total
Admin grants, 216 total Superuser grants, unchanged totals for every other role, and both Email
accounts still legacy/unbound. No seeding, provider call, mailbox mutation, source switch, send,
cutover, or secret operation ran.
The entry is therefore reopened as Rework Needed until an authenticated human verifies the repaired
Admin/Superuser paths and completes or explicitly re-scopes the remaining checks.

On 2026-08-21, Svein separately approved the current Dev Mail endpoint as the exact RFC1918 IPv4
`/32` group `tronderdata_mail_dev`. A fail-closed environment parser and blank example setting were
added; focused configuration/endpoint tests pass 36 / 161. Sanitized preflight proved that IMAP and
SMTP share one IPv4 answer and that only the exact rule authorizes it. Sanitized runtime readback
after cache clear confirmed the group, no legacy mapping, both accounts still legacy/unbound, and
zero provider connections, credentials, or events. Email polling remains paused. This approval does
not claim or authorize a completed provider Verify, activation, cutover, legacy-secret purge, or
production private-endpoint rule.

Deploy / migration notes: Back up the database/configuration and record existing Email account
sources, binding versions, legacy ciphertext non-null counts, provider-work states, queue/failed-job
state, and Telescope sequence range. Deploy additive migrations
`2026_08_16_112000`-`117000` plus `2026_08_21_100000`; in the recovered Dev ledger these ran one per
step in batches 106 through 111 and batch 121. Clear caches; rebuild group-writable views
with `umask 0002`; build assets; restart all long-lived Email/default workers; and synchronize
Integration, Email, Notification, and User Management Knowledge locally. The permission repair is
additive; do not substitute the full `RoleSeeder`, whose complete-role synchronization can remove
module-owned grants. Deployment must leave every existing account on `source=legacy` and perform zero
provider I/O.

After the new redaction/watchers and Superuser-only Telescope gate are active, run the read-only
`php artisan email-provider:telescope-remediate --limit=20000`. Review only its counts, sequence
bounds, and cohort hash; it never prints entry content. For each non-empty bounded cohort, record the
approved observability loss and run the exact unchanged `--after-sequence`, `--through-sequence`,
`--cohort-hash`, `--purge`, and `--acknowledge-observability-loss` command under this review. Repeat
until a final read-only preview returns zero. A changed cohort must fail without deletion. Do not run
`telescope:clear`: unrelated historical telemetry is outside this authorization.

First provider rollout must use exactly one backed-up non-production mailbox. Preview and local
stage must show zero DNS/provider/send/source effects. Named human approval is required before the
one explicit Verify. Activate the exact version, pause and drain every account provider path,
resolve active Draft/Sent/remote-operation/import/re-baseline/inventory/deletion/reconciliation/IDLE
work, preview exact cutover readiness, then apply the source/reference-only cutover. Resume only for
the checks below. No second account may be staged or cut over until this entry records the first
result.

Rollback notes: prefer the guarded account-source rollback while the exact legacy ciphertext is
intact. Pause/drain again, prove no later rotation, revoke, purge, rebind, unresolved provider work,
active reconciliation, or IDLE presence, then roll back inside the declared window. Schema down is
only for a disposable clean database and refuses provider bindings, credential/event history, and
migration runs/items. Legacy ciphertext destruction is readiness-only and requires a later named
review plus backup/recovery proof; it is not authorized here.

Risks: endpoint-policy or DNS mistakes could create SSRF; a stale credential or binding could open a
socket against the wrong mailbox identity; a verification/revoke race could use destroyed material;
a short lock/deadline could overlap provider work; plaintext could enter request/session/trace/log/
Telescope/queue data; a system-mail fallback could bypass the account ledger; an ambiguous SMTP
outcome could be replayed; private trust could be granted too broadly; a partial cutover could strand
Draft/Sent/reconciliation work; or early legacy purge could remove the only rollback path. The
implementation therefore fails closed at every exact-version, lock, trust, readiness, and diagnostic
boundary.

Automated verification: the order-6 focused matrix passes **42 tests / 470 assertions** across
endpoint/transport policy, security diagnostics, credential lifecycle, legacy migration, Admin/private
authorization, Telescope remediation, and bounded health checks. The stable order-6 complete Email
Feature/Unit boundary passes **491 / 5,069**; historical/deletion coverage also passed **28 / 273**.
The complete Ticket/workflow matrix passes **132 / 955**; Sales, Marketing, User Management,
Customer Portal, and Commercial add **116 passing tests**. The strict Notification/password-reset
channel passes **10 / 111** plus **62 / 403** adjacent. A generated isolated MariaDB 10.11 database
passes the `112000`-`117000` up/down/refusal contract with **1 / 60** and was removed afterward.
Targeted Pint passes 72 PHP files and syntax passes 67 production/test/migration files. Eighteen
Email-provider routes and 15 Email-account/maintenance routes load; configuration cache round-trip,
complete Blade compilation with zero non-group-writable files, Vite production build, and Git diff
checks pass. The focused 2026-08-21 administration and deployment-repair matrix passes **7 tests /
127 assertions**. Post-restore operator readback confirms the exact permission/role/account state
recorded above; it does not replace the open browser checks.
Independent read-only code-security audit reports GO with no remaining order-6 P0/P1. Automated
evidence does not change this reopened entry to `Reviewed`.

Human checks:

- [ ] Back up Dev and capture the listed Email account/source/binding/legacy-ciphertext,
  provider-work, queue/failed-job, and Telescope sequence inventories without printing any secret,
  endpoint, username, raw provider response, or ciphertext.
- [ ] Review the backed-up historical deployment of migrations `112000`-`117000` in order. Confirm
  the new Integration connection, credential, append-only event, migration run/item, and Email
  binding schema existed; every existing account remained `legacy` at that schema-only checkpoint;
  and no provider Integration root, credential version, migration run, provider call, source switch,
  or mailbox mutation was created automatically.
- [ ] Confirm migration `2026_08_21_100000` is recorded and all eight approved permissions exist.
  Confirm Admin and Superuser can manage public providers; only Superuser can use private trust or
  open Telescope; missing mailbox-sync/account-manage permission, inactive/system actors, and direct/
  inaccessible opaque IDs fail without existence disclosure or mutation. Confirm unrelated Calendar/
  Sales grants remain present, totals are Admin 167 and Superuser 216 with every other role
  unchanged, and no seeding or full-role synchronization ran. Preserve that both accounts were
  legacy/unbound at the historical permission checkpoint; separately verify their current
  Integration-owned version-2 bindings and active overlap-safe polling.
- [ ] Run the Telescope remediation preview. Review and record each exact cohort count/bounds/hash,
  purge only unchanged approved provider-sensitive cohorts, preserve unrelated entries, and finish
  with zero matches. Confirm no provider host, username, password, pinned IP, private reason,
  ciphertext, or raw response appears in new request/session/query/model/log/job/exception entries.
- [ ] In Email providers, create one public standard-port test connection. Confirm its credentials
  are Staged, existing values never render or flash back, the generic Integration toggle cannot
  mutate it, Email accounts cannot bind it yet, and page source/browser history contains no secret.
- [ ] Attempt malformed URL/control/wildcard/zone hosts, unsupported auth/ports/transports, mixed or
  special-purpose DNS answers, and a certificate/hostname/TLS failure in the controlled test setup.
  Confirm every attempt blocks with a stable safe reason and no raw provider/endpoint diagnostic.
- [ ] As Admin, confirm a trusted-private provider cannot be listed, opened, staged, bound, or tested.
  As Superuser, use one exact named private CIDR and reason; confirm an outside or always-denied
  address still fails and the UI/event shows no host, IP, username, or reason text outside its
  intended protected form boundary.
- [ ] With named approval, select Verify on the one staged test provider. Confirm one bounded
  authenticated IMAP/SMTP probe, pinned-IP/original-host certificate behavior, required STARTTLS or
  implicit TLS, clean disconnect/stop, sanitized result, and no duplicate provider read under a
  concurrent click or deadline/worker timeout.
- [ ] Activate the exact verified credential and create one Email account from its safe label.
  Confirm the account page shows source/readiness/capabilities but no endpoint/username/secret,
  rejects direct legacy fields and a staged/unverified/private-without-authority binding, and its
  bounded Full Test persists only safe health evidence.
- [ ] Stage and Verify a password-only rotation. Confirm usernames cannot change, the old active
  runtime stays ready until activation, activation destroys the retired ciphertext, an in-flight
  locked operation finishes before the flip, and current-binding work uses the new active secret.
  Revoke the test version and confirm new IMAP/SMTP work blocks before network without claiming
  provider-side revocation.
- [ ] On one backed-up legacy test mailbox, preview and stage locally. Confirm exact scope/fingerprint,
  no DNS/provider/send/folder/read/Ticket/source effect, and explicit public or authorized named
  private trust. Verify and activate only after the named approval.
- [ ] Pause/drain that mailbox and confirm unresolved Drafts, Sent, remote operations, historical
  import, cursor re-baseline, inventory, deletion cleanup, provider reconciliation, or IDLE presence
  blocks readiness. After resolving them, apply exact cutover and confirm only provider reference,
  source, binding version, and audit evidence change; mailbox content/state and legacy ciphertext
  remain intact.
- [ ] While a pre-cutover queued provider job/send exists, perform the controlled cutover or rollback
  and resume it. Confirm it fails stale before network. Confirm secret-only rotation does not silently
  adopt another username and revoke blocks dispatch-to-handle authentication.
- [ ] Exercise one controlled Ticket/Notification/password-reset send through the selected system
  Email account. Confirm the frozen account/binding is present without runtime material, no Laravel
  default-mailer fallback occurs, and an ambiguous acceptance is recorded without automatic replay.
- [ ] Roll the one account back while eligible. Confirm source/reference restoration only, preserved
  legacy ciphertext, positive binding bump, and stale pre-rollback work. Then introduce one guarded
  blocker on a disposable copy and confirm rollback/down refuses rather than discarding history.
- [ ] Check Email provider and Email account pages at desktop and mobile widths. Confirm lifecycle,
  status, safe blocker text, private-trust controls, migration steps, and destructive confirmations
  remain understandable without exposing endpoint or credential values.
- [ ] Confirm long-lived workers were restarted, no credential-bearing legacy serialized job is
  accepted, queues/failed jobs have no new provider-secret evidence, Knowledge is synchronized, and
  only the reviewed single account was touched. Leave legacy purge disabled and every other account
  on its previous source.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-08-16-005 - Email Canonical Message And Placement Cutover

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev working copy; implementation, automated verification, final
independent read-only audit, and additive migration `111000` in recovered Dev batch 105 complete;
controlled operator/browser/worker cutover review pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/adr/2026-08-11-email-owned-mail-client-domain.md`,
`docs/feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md`,
`docs/feature-slices/2026-08-16-email-mail-canonical-message-placement-cutover.md`,
`HR-2026-08-16-004`, and `HR-2026-08-12-003`

Scope: Add a reversible canonical common-content expansion while preserving every existing
`email_messages` row as the immutable source occurrence. Account, active placement, provider UID,
personal unread/opened state, Ticket and rule behavior, provider operations, search result, route,
and external API identity remain source-scoped. A nullable placement pointer and unique
source-to-canonical mapping may supply equivalent common content only after the existing source
placement was independently authorized.

The local `canonical-cutover-v1` evidence contract compares every projected field and hashes the
actual private raw and attachment files. Stored attachment size/SHA-1 must match the actual file and
actual SHA-256 must agree. Individual materialized fields/files, JSON depth (24), visited nodes
(10,000), entries (5,000), message/component/item counts, and the complete 256 MiB cutover-run
evidence are bounded. A multi-source projection requires one complete locked clique from a completed
shadow run:
every edge is strong, immutably confirmed, and inspected at the exact reviewed hashes, with no weak,
ambiguous, oversized, incomplete, missing, stale, or retained keep-separate evidence.

Accounts above the 500-placement direct verification cap use a durable whole-account parity
attestation rather than an unbounded request or permanent mode block. It freezes the complete active
placement scope and processes at most 100 placements per operator request while loading one full
source/projection at a time. A currently authorized replacement may continue the cursor after the
requester is offboarded. Completion records the exact item count, rolling evidence, total bytes,
scope hash, requester/completer, and fingerprint. A mode preview binds that fingerprint; apply
rechecks every durable row/database relation and rejects changed scope or an attestation older than
15 minutes. `canonical` requires strict actual-file evidence on every page.

Affected pages / workflows: new Email Admin **Canonical cutover** index/report and eight routes;
permission/admin-menu deployment; inbound source self-map after complete local persistence; Mail
workspace, Inbox list/show API, raw-source, and attachment reads; retention eligibility; and
additive canonical projection/mapping/mode/cutover/parity-attestation audit tables. `legacy` remains
default, `verify` always returns source content, and `canonical` overlays common content while
retaining source IDs and falling back to the authorized source on any drift. Pointer drift is repaired; content drift
dissolves the complete shared component into independent projections. Attachment delivery always
serves the exact route-bound source part after full parity verification.

Authorization boundary: preview/apply/rollback/audit/mode actions require an active non-system human
with both `email.canonical_cutover_manage` and `email.mailbox_sync_manage`, plus ordinary current
View for every account in the complete expanded scope. Break-glass never qualifies. Durable run
access follows current complete account authority, not requester identity, so an authorized
replacement may recover work after requester offboarding; requested/applied/rolled-back actors remain
separate audit. Inaccessible and nonexistent run IDs use the same hidden response. Canonical IDs and
private paths/content are not serialized by the operator UI, Mail UI, API, raw, or attachment surface.

Deploy / migration notes: post-restore rollout applied additive migration
`2026_08_16_111000_add_email_canonical_message_placement_cutover.php` in batch 105 after shadow
migration `110000` in batch 104. No cutover preview/apply/rollback, provider/network/AI call,
private-file write/delete, mapping/run, or mode switch was performed. The additive permission repair
in batch 121 replaces broad role seeding. Clear caches, rebuild group-writable views with `umask
0002`, and restart long-lived Email workers before controlled use. First use must be one
small non-production account: preview/apply self maps, preview/apply `verify`, compare all read
surfaces, complete all whole-account parity pages when the account exceeds the direct cap, and only
then separately preview `canonical`. Physical removal of legacy content columns is not in this
migration and requires a later forward migration after parity, rollback-window expiry, and named
human approval.

Rollback notes: stop new applies, preview/apply `legacy` mode for affected accounts, and roll applied
runs back newest-first. Mapping, pointer, component, evidence, access, or later-run drift blocks an
unsafe rollback. Migration down is permitted only on a disposable clean schema with no canonical
projection/attachment/mapping, placement pointer, read-mode row, preview/run item, parity
attestation, or parity item. All durable evidence must be preserved or carried forward. Retired
projections remain until the later reviewed lifecycle policy. Retention reports the stable
`canonical_projection_or_cutover_audit` protection reason before touching any retained source file.

Risks: a false equivalence could show another delivery variant; a missing full-field/file check or
partial clique could widen content; a pointer could bypass source authorization; stale access or
requester ownership could strand emergency rollback; schema-first/application-first deployment could
cause missing-table failures; duplicate attachment metadata could return the wrong source part;
unbounded headers/files or a whole-mailbox scan could exhaust a request under locks; component drift
or rollback ordering could create a partial merge; retention could delete a source still referenced
by durable audit; or canonical reads could accidentally change Ticket, unread, rule, provider, search, or external API
identity. The implementation is intentionally local, bounded, source-preserving, fallback-first, and
contains no provider write.

Automated verification: focused `EmailCanonicalPlacementCutoverTest` passes **18 tests / 702
assertions**. The adjacent retention, shadow correlation, historical maintenance, workspace/access,
attachment, and per-user unread set passes **91 / 843**; the shadow plus historical subset remains
**34 / 275**. Coverage includes strict actual-file
evidence, structural/aggregate budgets, complete cliques, apply-time access/evidence drift, mode
fallback, component dissolution, unsafe/newest-first rollback, schema-absent Mail/API/Admin reads,
duplicate source-part identity, retention protection, metadata-only UI, permission separation,
offboarded-requester recovery, paginated 501/502-placement parity, attestation fingerprint binding,
scope/age rejection, durable migration-down refusal, and no canonical-ID serialization. Eight
routes, PHP syntax, targeted formatting/diff checks, and group-writable Blade cache pass. These
isolated tests migrated disposable databases; the later post-restore Dev migration is separate
operational evidence and does not replace the checks below. The final independent audit is GO on
the arbitrary-account cap removal, current-authority continuation, fingerprint/drift/age binding,
and fail-closed preservation of all durable schema evidence.

Manual checks:

- [x] Review the backed-up post-restore application of `111000` in batch 105 after `110000` in batch
  104, and confirm only eight new canonical/cutover/mode/parity tables plus the
  nullable placement pointer/index/FK appear. Confirm zero source rewrite, mapping, run, mode row,
  provider call, Ticket/rule/unread action, file write/delete, or automatic backfill occurs.
- [x] Exercise an application-first staged deploy before `111000`. Confirm Mail workspace, Inbox API,
  and Email Admin remain available in honest legacy/pending state without querying a missing
  canonical table or exposing a cutover control that can execute.
- [x] Seed roles and permissions. Confirm Admin/Superuser receive the new cutover permission while a
  normal Tech does not. Test missing either cutover or mailbox-sync permission, inactive/system
  actors, break-glass-only access, revoked/missing ordinary View, and partial multi-account access.
  All must fail before content or mutation and must not enumerate inaccessible runs/accounts.
- [x] Disable one run requester, transfer/regrant ordinary View, and confirm a second currently fully
  authorized operator can list, inspect, apply, and roll back the durable run with distinct actor
  audit. Revoke one scoped account before apply/rollback and confirm the operation fails closed.
- [x] Inspect Admin list/report at desktop/mobile widths. Confirm preview/apply/rollback are visually
  separate, typed confirmation is exact, validation/focus/status/error states are clear, and reports
  never show canonical IDs, subject, participants, body, header, filename, raw/attachment content,
  private path, credential, provider payload, search term, Ticket content, or AI data.
- [x] Preview/apply one bounded self map. Confirm source/account/placement/provider UID, conversation,
  Ticket/link, classification, personal unread/opened, rule, Smart Inbox, search, remote-operation,
  and API IDs are unchanged. Repeat preview/apply and inbound duplicate storage and confirm
  idempotency, pointer repair, and no blind provider retry or shared-component rewrite.
- [x] In disposable fixtures, reject missing/malformed fields, missing/unreadable/unsafe/symlink raw
  and attachment paths, stored size/SHA-1 mismatches, different actual SHA-256, body/JSON byte limits,
  depth/node/entry limits, per-message file limits, component/item limits, and the 256 MiB run limit.
  Confirm budget rejection occurs before long component locks and leaves no partial apply.
- [x] From completed shadow evidence, merge one exact fully inspected/confirmed strong clique. Reject
  incomplete connected edges, weak/possible/ambiguous/oversized candidates, stale review/inspection,
  keep-separate evidence, missing component members, divergent full fields/files, moved accounts, or
  changed evidence. Confirm source occurrences and authorization identities remain independent.
- [x] Preview/apply `verify` and confirm all Mail/API/raw/attachment reads stay source content. Then
  preview/apply `canonical` for the controlled account and compare workspace, list/show API, raw
  source, and every attachment byte/name. Confirm source/account IDs remain stable, canonical IDs and
  private paths are absent, and duplicate metadata/identical parts each download the exact clicked
  source part.
- [x] On an isolated account above 500 active placements, start a strict whole-account parity
  attestation. Confirm each request advances no more than 100 rows, progress survives restart, and a
  second currently authorized operator can continue after the requester is disabled. Confirm only a
  complete current fingerprint can be previewed/applied; add/remove one active placement and age a
  completed attestation past 15 minutes to verify both fail closed without changing read mode.
- [x] Change a source, canonical projection, file, mapping, or placement pointer. Confirm ordinary
  canonical reads immediately fall back to the authorized source. Preview/apply audit: pointer-only
  drift repairs exactly; shared content drift expands and dissolves the complete component in one
  transaction, with no partial split or provider/workflow mutation.
- [x] Apply overlapping disposable backfill/merge/audit/mode runs and test newest-first rollback.
  Confirm a later mapping/pointer/mode run blocks older rollback, divergent evidence blocks unsafe
  shared-component restoration, and restored exact evidence permits complete prior-state recovery.
- [x] Age a mapped/root/audited/non-legacy source beyond retention. Confirm preview and purge show
  `canonical_projection_or_cutover_audit`, retain database/source/raw/attachment evidence, record a
  protected—not failed—attempt, and do not encounter a raw FK exception or partial file deletion.
- [x] Run the real Email worker on a controlled inbound message and duplicate redelivery. Confirm the
  self-map is created/repaired only after complete source/placement/attachment persistence, failure
  logs are sanitized, authoritative inbound success is not retried blindly, and no provider write or
  Inbox automation is duplicated by canonical projection work.
- [x] Compare authoritative counts/fingerprints and provider audit before/after preview, apply,
  canonical reads, audit, and rollback. Confirm no provider Seen/folder/message state, Ticket,
  conversation/classification, personal unread/opened, rule/attempt, Signal/notification, Smart
  Inbox/AI, search identity, remote operation, or private file is created/changed/deleted outside the
  documented local canonical tables and placement pointer.
- [x] With all accounts returned to `legacy` and applied runs rolled back, confirm guarded migration
  down still refuses every preview/run item, parity attestation/item, projection/attachment/mapping,
  placement pointer, and even a legacy read-mode row. It may succeed only on a disposable clean state.
  Restart workers, clear caches, rebuild group-writable views, and recheck Mail/API/raw/attachment
  legacy behavior. Do not remove legacy columns as part of this review.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-16-004 - Email Canonical Message Shadow Correlation

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev working copy; implementation, focused verification, independent
audit, and additive migration `110000` in recovered Dev batch 104 complete; authenticated
operator/worker review pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/adr/2026-08-11-email-owned-mail-client-domain.md`,
`docs/feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md`, and
`HR-2026-08-12-003`

Scope: Add a conservative, rebuildable, metadata-only shadow process that discovers possible
same-delivery pairs from bounded normalized Message-ID, exact checksum, or current explicit
Ticket/conversation relationships. It freezes exact account and message-ID scope, compares complete
available delivery evidence, and retains versioned hashes, reason codes, counters, run state,
inspection audit, and immutable human review decisions. It never makes a canonical merge or changes
an externally visible canonical ID.

The initial and final frozen snapshots each fail closed above 64 MiB of conservatively estimated
local evidence input. The entire run has a durable 256 MiB aggregate evidence-read cap, and raw files
are not hashed until the lightweight scope preflight passes. Precise group/pair boundaries are
bounded and exact; a candidate found through both precise and oversized discovery remains
deterministically oversized. An oversized representative cannot be confirmed.

Affected pages / workflows: `/tech/admin/settings/email/correlation`, seven Email Admin correlation
routes, the `email` queue, local message/raw/attachment evidence reads, metadata-only run reports,
exact two-message audited inspection, and immutable `confirmed_candidate`, `keep_separate`, or
`needs_more_evidence` review. Ordinary `/tech/mail`, legacy Inbox, Inbox API, Ticket communication,
provider synchronization/actions, search, Smart Inbox, AI, rules, retention, personal unread/opened
state, attachments, and raw-source delivery remain unchanged.

Authorization boundary: an active human requires `email.mailbox_sync_manage` and ordinary current
mailbox View independently for every exact account. Metadata pages hide inaccessible/nonexistent
runs and candidates consistently. Content inspection and final review reauthorize both recorded
accounts, require each exact message still to belong to its recorded account, and bind the actor to
the current left/right evidence hashes. Configuration authority, stale access, a moved-account
message, or an old inspection never becomes content/review authority.

Deploy / migration notes: post-restore rollout applied additive migration
`2026_08_16_110000_create_email_canonical_correlation_shadow.php` in batch 104. It launched no shadow
run and made no provider/network/AI call or authoritative Mail/Ticket/user-state mutation. Clear
caches, rebuild views with `umask 0002`, and restart long-lived Email workers before controlled use.
The first
run must use a small explicitly selected non-production account/message-ID scope. Ordinary rollback
is blocked when any inspection audit or non-`unreviewed` decision exists; that evidence must be
explicitly exported or carried forward before the three shadow tables may be removed.

Risks: a weak fingerprint or reused/malformed Message-ID could create a misleading future-cutover
candidate; recipient/Bcc, raw, body, date, direction, or attachment divergence could be missed;
unbounded grouping or repeated raw reads could exhaust workers; concurrent scope changes could make
results stale; an authorization or exact-account-binding defect could expose personal content; and
rollback could silently discard a retained inspection or decision. This slice deliberately leaves
all candidates as shadow evidence and performs no cutover, merge, relink, deletion, or authorization
widening.

Automated verification: focused `EmailCanonicalCorrelationShadowTest` coverage passes **19 tests /
131 assertions**. It covers strong/possible/ambiguous/different evidence, malformed and normalized
Message-ID, Bcc/raw conflicts, order-independent attachment evidence, exact and overflow caps,
frozen-scope changes, idempotency/no-mutation, cancellation, access revocation, moved-account
inspection, inspection audit, immutable review, deterministic oversized dominance, the 64 MiB
snapshot and 256 MiB aggregate budgets, non-enumeration, and rollback guard.
Seven routes are registered; targeted Pint, PHP syntax, route inspection, and scoped whitespace
checks pass. The final independent audit result is **GO**. The later post-restore Dev migration is
separate operational evidence and does not replace the manual checks below.

Manual checks:

- [x] Review the backed-up post-restore application of migration `110000` in batch 104 and confirm
  only the three additive correlation tables appear. Confirm deployment creates
  no run/candidate/inspection, performs no provider/network/AI action, and changes no authoritative
  Mail, Ticket, personal-state, rule, Smart Inbox, attachment, raw, or remote-operation record.
- [x] As an active human with `email.mailbox_sync_manage` and ordinary View for the selected exact
  accounts, open **Canonical correlation** and queue a small message-ID window. Confirm a
  configuration-only administrator, inactive/system actor, revoked viewer, and user missing either
  account View cannot enumerate an inaccessible personal account, run, candidate, or content; forged
  and nonexistent IDs must use the same hidden response.
- [x] Inspect the run list and detail before opening content. Confirm it exposes only exact scoped
  account/message IDs, status, caps, counters, candidate classes, reason codes, opaque hashes, and
  review audit—never subject, participant/address, filename, snippet, body, header/raw source,
  attachment content, credential, provider payload, search term, Ticket content, or AI data.
- [x] Exercise a frozen account/message-ID scope at an exact cap and just beyond it. In a controlled
  disposable fixture, verify initial and final snapshots stop above 64 MiB, the aggregate run stops
  above 256 MiB, no raw hashing begins before size preflight succeeds, and each failure asks for a
  narrower scope without retaining a false completed result.
- [x] Process one queued run with the real Dev Email worker. Confirm bounded progress, durable
  counters, overlap protection, safe cancellation between batches, idempotent requeue, and resume of
  a failed but unchanged run. Change/delete one frozen source row during a disposable run and confirm
  the fingerprint fails closed before completion without an authoritative-row mutation.
- [x] Use controlled same-delivery and divergent fixtures to confirm normalized Message-ID, checksum,
  current Ticket link, and current conversation link can discover candidates while subject-only,
  missing, reused, malformed, or synthetic IDs never establish identity. Verify sender, To/Cc/Bcc,
  direction, delivery time, body, raw source, and attachment differences produce the documented
  conservative class.
- [x] Create one exact-boundary group and one over-limit group, including a pair discovered through
  both precise and oversized paths. Confirm discovery order cannot downgrade oversized status, its
  representative cannot be confirmed, and a narrower message-ID scope is required for further
  review.
- [x] Open **Inspect exact evidence** for same-account and cross-account candidates. Confirm ordinary
  View is required independently for both exact recorded accounts, the inspection is audited before
  content appears, and no opened receipt, `Unread for me`, provider Seen, or other state changes.
  Revoke access, move one message to another owning account, or change evidence and confirm the old
  candidate/inspection becomes unavailable or stale without leaking content.
- [x] After the same actor inspects the exact current hashes, record `Confirmed candidate` and `Keep
  separate` on separate disposable candidates and confirm each decision is immutable/idempotent only
  for the same actor/state/reason. Confirm another actor or an old inspection cannot reuse it, while
  `Needs more evidence` remains metadata-only and still performs no merge.
- [x] Compare authoritative counts/fingerprints and provider audit before and after a completed run,
  inspection, and review. Confirm no message, placement, conversation, Ticket/link, attachment/raw,
  search, personal state, rule, Smart Inbox, retention, notification, AI, provider, or remote
  operation is created, changed, hidden, relinked, merged, or deleted, including across accounts.
- [x] Check the Admin list/detail/inspection at desktop and mobile widths. Confirm keyboard focus,
  labels, validation, pending/running/failed/cancelled/completed states, safe errors, and resume/cancel
  controls remain understandable and content inspection is visually distinct from metadata review.
- [x] On a disposable database copy, confirm rollback succeeds with only unreviewed rebuildable
  shadow rows. Then create an inspection audit and, separately, a reviewed decision; confirm either
  blocks rollback until the evidence is explicitly exported or carried forward, without touching
  authoritative Email rows.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-16-003 - Email Per-User Unread Baselines And Explicit Backlog Handover

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev working copy; implementation, automated verification, and additive
migrations `104000`/`105000` in recovered Dev batches 102/103 complete; authenticated browser review
pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`,
`docs/feature-slices/2026-08-16-email-mail-per-user-unread-baselines-backlog-handover.md`,
`HR-2026-08-16-001`, `HR-2026-08-16-002`, and `HR-2026-08-12-006`

Scope: Replace sparse missing-row-is-unread behavior with one Email-owned account/user access epoch
and local message-ID baseline. Existing ordinary access migrates to epoch 1/baseline 0. A new shared
View grant, personal owner, or personal delegation starts a deterministic epoch without flooding old
mail; uninterrupted edits, overlaps, and account/user disable/re-enable do not reset it; a real
ordinary-access gap and re-grant do. Scheduled delegation boundaries preserve mail received after the
actual start as unread even before first open. Personal legacy direct grants fail closed, and
break-glass never receives or mutates personal unread/open/epoch state. Historical imports project
insert-only current-epoch read-for-me rows independently of provider Seen.

The slice also adds a metadata-only **Unread handover** surface for an authorized shared-mailbox
manager or personal owner. A 15-minute preview freezes one account, exact active human target,
selectable synchronized folders, received-date window, reason, maximum 1–500, message/placement IDs,
authorization fingerprint, and current epoch. Apply reloads and reauthorizes the previewing actor
and target under locks, verifies the exact snapshot, and writes only the target's current-epoch
`is_unread=true` state. Provider Seen, other users, later arrivals, folders, rules, Tickets,
notifications, AI, and remote operations remain unchanged.

Affected pages / workflows: `/tech/mail` conversation/list/filter/count/selected-message personal
unread state; Email Admin account create/edit/grant/owner transitions; personal mailbox delegation;
historical import and inbound message storage; Email Admin account list; `/tech/mail/access`; and
`/tech/mail/accounts/{account}/unread-handover` preview/apply/history. The Inbox API currently exposes
no personal unread field/filter, so no new API surface was added.

Deploy / migration notes: post-restore rollout applied additive migrations
`2026_08_16_104000_add_email_unread_access_baselines.php` and
`2026_08_16_105000_create_email_unread_handover_runs_and_items.php` in batches 102 and 103 after the
historical/delegation foundation migrations. No provider call, notification, Ticket/rule execution,
or external AI request was part of recovery. Run `php artisan optimize:clear`, rebuild views with
`umask 0002 && HOME=/tmp php artisan view:cache`, and restart long-lived Email workers. Verify every
existing ordinary owner/shared grant/current delegation has exactly one epoch-1 baseline at zero,
every prior state is epoch 1, and any legacy personal direct-only row is retained only as blocked
evidence. Rollback intentionally fails closed after any non-legacy baseline/epoch or durable handover
audit exists; preserve/export or explicitly convert that evidence before considering a legacy
rollback.

Risks: a missed access transition could expose old history as unread or suppress new work; an
unobserved scheduled-delegation gap could reuse an old epoch; provider Seen could accidentally leak
back into personal state; break-glass or a personal direct grant could gain personal-state authority;
or a stale/cross-account handover could mutate the wrong user. Handover history must remain
metadata-only, exact-snapshot apply must fail before partial mutation, and account/user deletion must
not erase retained run audit. The two additive migrations are present on recovered Dev, while their
data behavior and authenticated desktop/mobile workflow still require a named human.

Automated verification: focused unread/baseline/handover coverage passes **13 tests / 118
assertions**. The isolated rolling-schema compatibility regression passes **4 / 26**, covering the
sealed epoch schema, exact legacy schema, mixed-shape fail-closed behavior, and locked current-actor
reauthorization. Before rollout, a sanitized read-only Dev probe resolved the pre-`104000` state as
`legacy`, completed the scoped unread count with **428** rows, and rendered the 25-row Mail workspace
server-side for user 1 inside a transaction that was rolled back. The later migrations ran during
the backed-up recovery; authenticated browser review remains pending. Full
historical-import plus delegation/break-glass rerun passes **30 / 340**. Full
conversation-query plus historical-import regression passes **23 / 257**, and focused Email account,
personal-owner, and workspace-open regression passes **3 / 44**. The adjacent broad run passes
**171 / 1,548** across Email, delegation, and attachment recovery, plus **157 / 1,063** across
Notification, User Management, system-actor, and Ticket coverage. PHP lint, targeted Pint, diff
checks, migration type/rollback guards, rendered metadata-only HTTP tests, and all three named route
registrations pass. Automated checks do not complete the manual checks below.

Manual checks:

- [x] Review the backed-up application of `104000`/`105000` in batches 102/103 and confirm existing
  ordinary owner/shared grant/current delegation pairs receive epoch 1/baseline 0, existing state
  rows receive epoch 1, and legacy personal direct-only
  evidence is blocked rather than authorized.
- [x] Give an active technician a new shared View grant. Confirm old stored mail starts read for that
  technician, a later inbound message starts unread even when provider Seen is true, and another
  user's personal badges/state do not change.
- [x] Verify personal-owner onboarding and a bounded personal delegation. Confirm an uninterrupted
  edit and overlapping delegation do not reset the baseline; revoke/re-grant or a natural uncovered
  interval does; mail received after a scheduled delegation starts but before first open is unread.
  Disable/re-enable the account and user and confirm that alone does not create a new epoch.
- [x] Confirm a legacy personal direct grant and emergency-only access show no personal Unread badge,
  filter, count, mark action, or opened receipt and cannot create/increment/rewrite a baseline or
  state row. Confirm ordinary owner/delegation/shared access remains usable.
- [x] In `/tech/mail`, compare list parent/child badges, conversation aggregate, Unread-for-me filter
  and counts, and the selected message. Explicit Mark read/Mark unread must affect only the signed-in
  user's current epoch; opening must record the open without acknowledging provider Seen or changing
  the explicit personal choice.
- [x] Run a bounded historical import for a mailbox with multiple ordinary viewers, including a
  temporarily disabled viewer. Confirm new history is read-for-me in each current epoch without
  overwriting an existing personal choice and without provider, rule, Ticket, Signal, Notification,
  Smart Inbox, AI, or cursor side effects.
- [x] As a shared-mailbox manager without mailbox View, open **Unread handover** from Email Admin.
  As a personal owner, open it from Mailbox access. Confirm the page lists only current active human
  targets and exact selectable/synchronized folders and reveals no subject, participant, snippet,
  body, raw source, attachment filename, credential, or provider content.
- [x] Preview exact folders/date range/reason with the default 100 and hard maximum 500, then apply.
  Confirm only the frozen target/message IDs become current-epoch unread; provider Seen, another
  user, a later arrival, folders, Tickets, rules, notifications, AI, and remote operations remain
  unchanged. Repeat Apply and confirm it is idempotent with durable per-item counters.
- [x] Before Apply, separately move or hide a placement, disable folder sync, mark provider missing,
  expire the 15-minute preview, change/revoke target authority, create a new access epoch, and try a
  system actor or cross-account run. Each case must fail closed/stale before any personal-state
  mutation and show only a safe metadata error code.
- [x] Check keyboard labels/focus, validation feedback, collapsed safety help, desktop layout, and the
  mobile handover table/form. Confirm unauthorized personal mailbox IDs return the same hidden 404
  behavior as nonexistent/inaccessible mailboxes.
- [x] On a disposable database copy, confirm ordinary rollback is allowed only for epoch-1,
  baseline-0, currently entitled legacy-compatible state and is blocked for epoch >1, non-zero/new
  baselines, revoked entitlement, duplicate epochs, or any retained handover run/item.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-16-002 - Email Personal Mailbox Delegation, Break-Glass, And Access History

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev; implementation, focused automated verification, and additive
migration `103000` in recovered Dev batch 101 complete; authenticated browser review pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`,
`docs/feature-slices/2026-08-16-email-mailbox-delegation-break-glass-access-history.md`, and
`HR-2026-08-12-002`

Scope: Add owner-created, exact-operation personal mailbox delegations that expire within 31 days;
a distinct maximum-120-minute emergency read/search/attachment/raw-source path for active human
security operators; metadata-only append-only access history; after-commit owner/security notices;
and one current authorization decision across Mail, legacy Inbox, Inbox API, attachment, raw-source,
and Livewire content boundaries. Personal legacy direct grants fail closed. Emergency access never
grants personal unread state, AI, Smart Inbox, Ticket, send, organize, rule, configuration, export,
or deletion authority.

Affected pages / workflows: `/tech/mail`, legacy `/tech/inbox`, Inbox API reads/search,
`/tech/mail/access`, `/tech/mail/access-history`, Email Admin **Emergency mailbox access**, attachment
download, raw-source view, permission deployment, internal Notification delivery, unread access
epoch transitions for ordinary delegation only, and Email operator documentation.

Deploy / migration notes: post-restore rollout applied additive
`2026_08_16_103000_create_email_mailbox_delegation_break_glass_access.php` in batch 101. No provider
call, notification delivery, or external AI request was part of recovery. The additive permission
repair in batch 121 replaces broad permission/role seeding. Clear caches, rebuild Blade views with
`umask 0002`, and restart long-lived Email and Notification workers where serialized authorization
code may be resident. Existing accounts gain
no delegation or emergency record automatically. Ordinary rollback is intentionally blocked while
delegation, break-glass, or access-event rows exist; revocation plus an explicit retention/export
decision is required before dropping durable history.

Risks: an authorization regression could expose a personal mailbox through account administration,
a rogue direct grant, a stale Livewire request, or a queued action. Emergency access must disappear
immediately after expiry/revocation/disable, and an audit failure must block content before it is
read. Access history or notifications must never copy message content or search terms. Emergency
access must not mutate personal unread/open state or invoke AI/Ticket/Smart/provider actions. A
partial notification failure must retry remaining active recipients without duplicating successful
deliveries.

Automated verification: focused delegation/break-glass suite **15 tests / 196 assertions**; final
combined Email module plus attachment-access recovery run **171 / 1,548**, including the complete
Email module **141 / 1,227** and attachment recovery **15 / 125**; an earlier adjacent Mail
workspace/Inbox selection regression passed **5 / 58**. Affected Notification, User Management,
system-actor, and Ticket suites pass **157 / 1,063**. PHP lint, route registration, targeted Pint, and
diff checks pass. These runs used the isolated test database and Notification fakes. The later Dev
migration is operational evidence; authenticated browser, real queue worker, provider storage, and
responsive/accessibility checks remain manual. Passing tests do not complete the checks below.

Manual checks:

- [x] As an active personal mailbox owner, create one bounded delegation for an active human user.
  Verify exact View/Organize/Send/raw choices, reason, start/expiry, recent history, and explicit
  revocation. Reject self-delegation, overlap, inactive/system users, excessive duration, and any
  operation the owner no longer holds.
- [x] Confirm an account administrator and a user holding a legacy personal direct grant cannot see
  personal content or create an owner delegation. Confirm shared/system direct grants retain their
  normal exact View/Organize/Send behavior.
- [x] As an authorized active security operator, activate emergency access only after typed account
  confirmation, reason, exact operation selection, and a duration no greater than 120 minutes.
  Confirm the prominent Mail warning and expiry, then verify send, organize, provider actions,
  Ticket, AI, Smart Inbox, rules, export, deletion, and account configuration remain unavailable.
- [x] Verify emergency content view, search, allowed attachment download, and separately permitted
  raw source on desktop and mobile. Confirm search/results, folder/count/list boundaries, forged IDs,
  another account, inactive account/user, expiry, and revocation all fail closed without mailbox
  existence leakage.
- [x] During emergency-only access, confirm no **Unread for me** badges/counts/filter/actions, open
  receipt, or unread access-epoch row is created or changed. Confirm an ordinary delegation starts
  and ends its unread epoch according to the unread handover review contract.
- [x] Confirm the activating actor, current active owner, and another active human holding
  `email.break_glass_activate` can revoke an active record. Confirm a user holding only
  `email.break_glass_audit` cannot revoke it.
- [x] Confirm activation queues one content-free notice after commit for the active owner and active
  audit/security recipients. Verify the relative link opens only scoped metadata history, inactive
  recipients are skipped, and a partial recipient failure retries without duplicating prior success.
- [x] Inspect access history for created/revoked/expired-at-use events and explicit emergency mailbox
  view, message view, search, attachment, and raw-source use. Confirm it contains no subject,
  participants, filename, snippet, body, raw header/source, search term, credential, provider data,
  attachment bytes, AI data, or Ticket content.
- [x] Simulate an access-event write failure and confirm content/search/attachment/raw source is not
  returned. Confirm source deletion cannot erase retained events, account deletion is blocked while
  audit history exists, and an ordinary rollback refuses to drop non-empty durable history.
- [x] Check keyboard focus, clear labels/help, validation feedback, responsive layout, and immediate
  control disappearance after authority is revoked. Confirm ordinary owner/delegate and shared-mail
  workflows remain usable.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-16-001 - Email Mail Historical Import And UID Re-Baseline

Status: Reviewed
Added: 2026-08-16
Environment: Authoritative Dev; implementation and additive migrations `100000`-`102000` in
recovered Dev batches 98-100 complete; controlled provider verification pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/plans/2026-08-16-email-mail-completion-slice-index.md`,
`docs/feature-slices/2026-08-16-email-mail-historical-import-and-uid-rebaseline.md`,
`docs/feature-slices/2026-08-12-email-server-authoritative-folders-placements.md`, and
`HR-2026-08-12-003`

Scope: Add one advanced mailbox-maintenance surface guarded by `email.account_manage` plus the new
`email.mailbox_sync_manage` permission. It previews and runs a historical import for exact account,
enabled/selectable folders, UIDVALIDITY namespace, and a maximum 31-day UTC window. Runs default to
100 messages, enforce a hard maximum of 500 and batches no larger than 50, retain immutable sanitized
progress/item evidence, and support safe cancellation between batches. A separate reason-bearing
preview/apply action re-baselines one failed folder at current provider `UIDNEXT` without importing
history or relabelling old placements. Changed UIDVALIDITY creates and supersedes an explicit
namespace; documented same-validity cursor recovery reuses the immutable namespace and changes only
the forward high-water.

Affected pages / workflows: Email Admin account list/detail, the new per-account Mailbox maintenance
page, historical preview/progress/cancel, UIDVALIDITY failure recovery, forward-only account/folder
polling, provider-read services, queue/lock behavior, placement/conversation projection, permission
deployment, and Email operator documentation. Normal `/tech/mail`, `/tech/inbox`, provider mailbox
actions, drafts, Sent, Ticket routing, rules, Signals, notifications, AI, and personal unread state
must remain unchanged except that safely imported historical messages become available through their
authorized real folders.

Deploy / migration notes: post-restore rollout applied the three additive
`2026_08_16_100000`-`102000` migrations one per step in batches 98-100. No provider call, import,
re-baseline, external AI request, or production activation was performed. The additive permission
repair in batch 121 replaces broad permission/role seeding. Run `umask 0002; HOME=/tmp php artisan
optimize:clear`, rebuild Blade views with the same umask, restart the isolated Email worker when
required, and verify the external scheduler runner.
The first provider exercise is one non-production account/folder with a small cap. No production
import or re-baseline is approved by this entry.

Risks: a widened or unread-derived import could replay old mail as current work, flood Tickets and
notifications, or expose personal mailbox history. Reusing a numeric UID after UIDVALIDITY changes
could mutate the wrong provider message. Concurrent poll/import/re-baseline work could skip or
duplicate placements. Account administration must not become content access; preview/progress/errors
must not disclose subject, participants, filenames, body, raw headers, credentials, or inaccessible
mailbox existence. A partial provider or worker failure must retain honest resumable evidence rather
than silently broadening scope, moving the live cursor, or fabricating completion.

Automated verification: focused historical-import/UID/rebaseline **21 tests / 167 assertions**;
adjacent polling, Draft, remote-operation, Undo, Sent and attachment **68 / 465**; inbound automation
plus durable conversation query **22 / 194**; complete `EmailModuleTest` **141 / 1,227**. Pint on 41
owned/shared files, PHP lint, Blade cache, compiled-view group-write and diff checks pass. These runs
used the isolated test database and deterministic provider fakes. The later Dev migrations are
operational evidence; authenticated browser, real queue/scheduler/storage runtimes and a controlled
provider account remain manual.
Passing tests do not complete the checks below.

Manual checks:

- [x] Confirm a normal Tech, an account administrator without `email.mailbox_sync_manage`, and a user
  with only that new permission cannot open, preview, start, cancel, or re-baseline. Confirm an
  explicitly authorized operator can, and disabling the user or revoking either permission blocks
  queued execution.
- [x] On a personal mailbox, confirm maintenance metadata exposes no subject, participant, filename,
  body, raw header, credential, or message snippet. Confirm the operator gains no Mail content access
  unless they independently have normal mailbox View authority.
- [x] Preview one or more enabled/selectable folders. Confirm the exact account/folders,
  UIDVALIDITY, UTC dates, already-present/new estimates, effective cap, and blockers are clear;
  reject more than 31 days, more than 500 messages, an installation/account lower cap violation,
  disabled/non-selectable/cross-account folders, and an expired or changed preview.
- [x] Run a small historical import. Confirm deterministic folder-path/ascending-UID progress (not a
  claimed global chronology), batches no larger than 50,
  imported/already-present/skipped/failed counters, and sanitized errors. Repeat the request and queue
  delivery and confirm there are no duplicate messages, placements, attachments, conversations,
  Sent/Draft reconciliation rows, or audit items.
- [x] During an import, request cancellation and restart the Email worker. Confirm cancellation stops
  before the next batch, committed items remain intact, and durable progress resumes without
  replaying completed items. Disable the original requester and confirm a second currently authorized
  mailbox-maintenance operator can still request cancellation with an audited actor identity.
- [x] Compare before/after live cursors, provider flags/folders/messages, personal unread/opened state,
  rules, rule attempts, Tickets, Ticket evidence, Signals, notifications, Smart Inbox suggestions,
  AI usage, and remote operations. Confirm the historical run changes none of them and never uses
  provider unread as its cursor.
- [x] Start or simulate overlapping poll, folder refresh, provider inventory, Draft refresh, remote
  operation, import, and re-baseline work for the same folder. Confirm the shared account/folder lock
  allows only the safe owner and reports a visible blocker instead of racing.
- [x] With a controlled UIDVALIDITY failure, preview re-baseline and confirm old/new validity,
  UIDNEXT, live start, local placement count, reason, and blockers. Change provider state after
  preview and confirm apply becomes stale without local mutation.
- [x] Apply a stable changed-UIDVALIDITY re-baseline and confirm it creates/selects a new explicit
  namespace, supersedes but does not relabel/delete the old namespace or placements, starts
  forward-only at current `UIDNEXT`, clears only the matching sync blocker, imports no message, and
  performs no provider write. Repeat with a documented same-UIDVALIDITY cursor failure and confirm it
  reuses the immutable namespace while moving only the live high-water. Confirm old-namespace
  placements cannot drive a provider mutation without separate exact reconciliation.
- [x] Create an unresolved Draft/Sent/remote/reconciliation operation for the folder and confirm
  re-baseline blocks. Repeat a completed confirmation and confirm it is idempotent.
- [x] After re-baseline, deliver one genuinely new message and confirm ordinary polling imports it
  once. Then run a separate small historical import and confirm older selected mail is projected
  without becoming `unread for me` or entering Inbox automation.
- [x] Verify the Email worker and external scheduler runner from their real runtime users, inspect
  failed jobs without blind retry/deletion, and confirm private Email files created by the controlled
  import retain the required group-write ownership/modes.
- [x] Check the Mailbox maintenance page at desktop/mobile widths, keyboard navigation, error/focus
  handling, double-submit protection, progress refresh, and light/dark themes. Confirm controls are
  absent when the backend capability or authorization is unavailable.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-15-007 - Email Mail Desktop Workspace Density And Height Polish

Status: Reviewed
Added: 2026-08-15
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-15-email-mail-desktop-workspace-density-height-polish.md`,
`HR-2026-08-15-001`, and `HR-2026-08-15-005`

Scope: The two-column desktop Mail workspace uses equal bounded list/reader panes that consume the
available viewport height before scrolling independently. Conversation parent and expanded-child
rows are denser on desktop and show only the signed-in technician's personal **Unread** badge;
provider unread remains available through its filter, folder counts, detailed reader, and explicit
mailbox actions. One Smart Inbox button is restored above the reader while the same scoped Livewire
component keeps its controlled results after the complete conversation, closed by default per
selection. The stacked layout below 1200 px remains unchanged.

Affected pages / workflows: `/tech/mail` desktop conversation list and reader, expanded conversation
children, personal/provider unread presentation, Smart Inbox trigger/results/focus, pagination,
composer, and the existing stacked tablet/mobile layout.

Deploy / migration notes: No migration, permission seed, provider call, queue/scheduler change,
frontend build, API change, or data backfill is required. Deploy the module-owned Blade/Livewire
changes, run `umask 0002; HOME=/tmp php artisan optimize:clear`, rebuild views with
`umask 0002; HOME=/tmp php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: fixed desktop height must not clip composer, More dropdowns, modals, pagination, or short
viewports; list compaction must preserve readable labels and touch/focus targets; removing provider
badges from the list must not change provider Seen/filter/count/action authority; and the split Smart
locations must retain one synchronized accessible control and one authorization owner without focus
loss or exposing unavailable suggestions.

Automated verification: focused Smart Inbox, conversation-query, hierarchy, and readability coverage
passes **20 tests / 337 assertions**. `EmailModuleTest` plus supervised Smart Inbox cleanup passes
**153 / 1,408**. The complete Email test directory passes **349 / 3,066**. Focused Pint and PHP
syntax checks, Blade compilation, compiled-view group-write, and tracked/untracked whitespace checks
pass. Email Knowledge sync processed one chapter and one article with nothing skipped; no external
BookStack push was queued in this implementation turn. Automated checks do not replace the manual
layout and browser checks below.

Manual checks:

- [x] At roughly 1280x720, 1440x900, and 1920x1080, confirm the conversation list and reader have the
  same top/bottom edges, use the available height even when the Mail folder sidebar is taller, and
  scroll independently only after filling their panes. Confirm toolbar, command bar, and pagination
  remain visible.
- [x] Compare several dense parent rows and one expanded conversation. Confirm only the current
  technician's **Unread** badge appears in parent/child list rows; a provider-unread but personally
  read message has no list unread badge, while a provider-read but personally unread message does.
- [x] Confirm the mailbox-unread filter, provider folder counts, detailed `Mailbox read/unread`
  reader badge, and explicit provider read/unread actions remain available and unchanged.
- [x] With usable Smart content, confirm the Smart Inbox button is above the reader and starts
  collapsed. Open it by mouse and keyboard; confirm focus/scroll reaches the one result region after
  the complete conversation. Close it and confirm focus returns to the button. Switch away and back
  and confirm it starts closed again.
- [x] Remove Smart availability and confirm neither the button nor unavailable result content is
  shown. Confirm applied useful history remains visible when its existing eligibility contract says
  it should.
- [x] Open Reply/Reply all/Forward/Compose, More actions, classification/move/rule controls, and modal
  surfaces at desktop width. Confirm no content is clipped by the bounded pane and scrolling remains
  understandable at 200% zoom and in light/dark themes.
- [x] Check 1199 px, tablet, and roughly 390 px mobile widths. Confirm the existing stacked flow,
  touch targets, content order, and natural page scrolling remain unchanged.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-15-006 - Email Mail Inbound Attachment Recovery And Download

Status: Rework Needed
Added: 2026-08-15
Environment: Recovered Dev; current direct readback finds 32 of 34 expected attachment parts on
their exact source rows; canonical evidence, browser and named review remain pending
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`,
`docs/feature-slices/2026-08-15-email-mail-inbound-attachment-recovery-download.md`, and
`HR-2026-08-15-003`

Scope: The selected Mail reader gains placement-bound attachment downloads with current Mailbox View
authorization and fail-closed storage/path checks. A bounded idempotent recovery command can restore
attachment metadata/files that earlier private-storage permission failures prevented from being
persisted, without replaying inbound rules, Tickets, notifications, or provider mutations. Future raw
snapshots retain complete reparsable RFC822 evidence when safely available. The code boundary and
exact non-mutating provider-read fallback are implemented. Current direct readback remains partial at
32/34 exact-source parts; provider reconciliation has now hidden 479's confirmed-missing placement,
while the separate 479/650 canonical evidence and raw-snapshot evidence for messages 456 and 478
remain open.

Affected pages / workflows: attachment rows in the selected `/tech/mail` conversation reader, the new
Mail download endpoint, inbound raw/attachment persistence, the bounded operator recovery path, and the
Email private-storage directories. Legacy `/tech/inbox` download behavior remains unchanged.

Deploy / operations notes: migration `121200` ran after recovery in Dev batch 97. Historical
2026-08-21 checkpoint: the restored attachment baseline contained zero rows and only counter sum 6.
Recovery froze one exact 19-ID scope and completed a no-write preflight before apply. Local raw/legacy
evidence then restored 30 rows/files across 16 messages, and an idempotent rerun returned unchanged
without a duplicate row or file.

At that historical checkpoint, four expected parts belonged to messages `456`, `478`, and `479`.
Each exact provider recovery stopped at the fail-closed resolver with `dns_answer_set_denied`. The
blocked calls made no database, filesystem, or provider mutation, and no broad search or alternate
endpoint was attempted. The current correction below supersedes that count without guessing or
substituting evidence.

2026-08-24 current-state correction: direct database/filesystem readback finds 32 of 34 expected
attachment parts on exact source rows. Provider reconciliation confirmed message 479 absent from its
source placement, hid placement 478 and soft-deleted that local cache; it still has neither raw nor
attachment evidence. Same-identity message 650 remains active in Trash with independent raw plus two
attachments and must not be substituted automatically. Messages 456 and 478 each retain one attachment row/file but lack raw
snapshots. This current evidence supersedes both the earlier 30/34 snapshot and later 34/34
completion claims. No completion-pass operation copied, deleted, moved, or rewrote a file.

The current 2026-08-24 12:47 CEST redacted inventory reports 1,445 files: 968 referenced and 477 unreferenced. It reports
28 missing raw references, 79 non-private files, 15 duplicate unreferenced checksum+size groups, and
zero unsafe or unreadable files. Original, duplicate, and unreferenced evidence remains preserved;
no deletion occurred.

The pre-attachment checkpoint remains preserved, and the latest post-recovery backup is
`/var/Projects/tdPSA/storage/app/backups/recovery-incident-20260821/post-migration-mail-recovery-20260821T115109Z.sql.gz`
with SHA-256 `467d4cf18ab54726fb0f32b82eb70ed793499e9c54c7c6ccaffbd0e05eb1b009`
and mode `0600`.

After code/operations verification, run `umask 0002; HOME=/tmp php artisan optimize:clear` and
`umask 0002; HOME=/tmp php artisan view:cache`, restart the ordinary/default and `email` workers so
long-lived runtimes use the final read contract, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: an attachment ID without exact placement/message binding could leak another mailbox; unsafe
paths or response headers could expose local files or enable content sniffing; unbounded provider reads
could overload IMAP; and a non-idempotent repair could duplicate files or metadata. A legacy directory
must never be accepted on account/UID/count mismatch, symlink, outside-root/nested path, size, or MIME
policy failure. `dns_answer_set_denied` must not be bypassed with a broader or unreviewed endpoint.
Missing or unverifiable evidence must remain an honest per-message failure rather than a fabricated
attachment, and successful recovery must not silently delete legacy evidence.

Automated verification: focused `EmailAttachmentAccessRecoveryTest` passes **15 tests / 110
assertions**. It covers exact placement/message ownership, active account/grant scope, private path
allowlisting, safe response headers, bounded/idempotent snapshot/provider/legacy-directory recovery,
readiness failure, rejection guards, no provider call for exact legacy evidence, and negative identity
cases. Before this narrow follow-up, the adjacent exact provider-read package passed 47 / 321, the
broad Email module/inbound package 155 / 1,308, and the complete Email directory 347 / 3,030. Pint,
PHP syntax, and diff checks pass for the follow-up. The controlled side-effect window created zero
remote operations/attempts, rule attempts, outbound logs, Ticket-domain
tickets/messages/events/attachments, notifications, or queued jobs. Passing automated and operator
checks will not replace the manual checks below. Post-restore operational evidence confirms the
exact preflight, 30-row/file local recovery, unchanged rerun, fail-closed resolver outcomes, current
inventory, and no mutation from the three blocked provider calls.

Manual checks:

- [ ] Complete the exact root/operator mode-only repair tracked in `HR-2026-08-15-003`: change the 79
  identified `www-data`-owned `0644` files to `0660` without content/move/delete/ownership changes,
  then reconcile the recorded current 1,445-file inventory and confirm both project/queue and
  PHP-FPM runtimes pass read/write smoke.
- [ ] Review the exact 19-ID preflight and local recovery report: 30 rows/files were restored across
  16 messages and the second run was unchanged. Confirm every referenced size/checksum/path and the
  duplicate-free rerun.
- [ ] Confirm messages `4`, `5`, and `10` each used only their exact
  `email/attachments/{account_id}/{imap_uid}` directory with exactly two policy-accepted direct files,
  preserved counter two, and no provider search. Confirm mismatch, symlink, nested/outside-root,
  empty/oversized, and denied-MIME cases fail without partial persistence.
- [ ] Review messages `456`, `478`, `479`, and same-identity `650`. Confirm exact source-row evidence,
  the missing raw snapshots, and why message 650's Trash raw/two attachments remain independent
  evidence rather than an automatic substitute for 479. Approve no guessed copy or deletion.
- [ ] Confirm the original legacy source files, duplicate account-2 legacy copies, and previously
  recorded 477 unreferenced files remain preserved for a separate evidence review; approve no
  deletion as part of this check.
- [ ] Open representative received messages with ordinary and inline attachments. Confirm stored files
  appear under the exact selected message with friendly filename/size and download intact; inline
  content is downloadable but is not previewed inline by this slice.
- [ ] Confirm active authorized Inbox, Sent/Archive, and Ticket-linked placements can download their own
  files without changing provider state, personal unread, Ticket data, or message selection.
- [ ] Remove the global Email permission and confirm the route returns 403. Then restore that ceiling,
  revoke mailbox access, and test inactive account, hidden placement, cross-account IDs,
  attachment/message mismatch, missing file, and unsafe stored path; each context-specific denial must
  be a hidden 404 without path or mailbox metadata.
- [ ] Inspect download headers for forced attachment disposition, safe filename, `nosniff`, and
  private/no-store caching. No inline preview is part of this review.
- [ ] Confirm the side-effect window created zero remote operations/attempts, rule attempts, outbound
  logs, Ticket-domain tickets/messages/events/attachments, notifications, and queued jobs, and that
  provider access was read-only or stopped before connection.

Reviewer:
Reviewed date:
Result / notes: Reopened as Rework Needed. Current direct readback finds 32 of 34 expected parts on
exact source rows and preserves same-identity evidence separately; no browser or named human review
is complete.

### HR-2026-08-15-005 - Email Mail Smart Inbox Reader-First Polish

Status: Reviewed
Added: 2026-08-15
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-15-email-mail-smart-inbox-reader-first-polish.md`,
`HR-2026-08-14-012`, `HR-2026-08-14-013`, `HR-2026-08-14-014`, and
`HR-2026-08-15-004`

Scope: Smart Inbox results remain after the selected-message reader and default closed; follow-up
`HR-2026-08-15-007` restores one synchronized trigger above the reader without duplicating the scoped
Livewire query/eligibility owner. The surface presents only current useful controls and hides pending
actions that the recorded agent/current actor/account/target cannot execute. Terminal rows remain
durable audit evidence outside the ordinary reader, while applied results remain visible as useful
history. Current schema-v2 fingerprints remain sensitive to real conversation content/membership
changes but ignore unrelated Eloquent bookkeeping timestamps; legacy suggestions are evaluated with
their recorded schema. Existing action-time authorization remains authoritative.

Affected pages / workflows: the Smart Inbox review queue embedded in `/tech/mail`, analysis and the
controlled trigger/result region,
single and batch apply/correct/dismiss/rule-prefill controls, current-agent capability projection, and
conversation source fingerprint evaluation. The `received_at` corruption/recovery remains separately
gated by `HR-2026-08-15-004`.

Deploy / migration notes: Forward migration `121200` ran after recovery in Dev batch 97. It records each
suggestion's fingerprint schema and removed the unsafe receipt-timestamp clause before the exact
repair recovered five false-stale suggestions. Deploy the matching code and migration together. Run
`umask 0002; HOME=/tmp php artisan optimize:clear`, rebuild compiled views with
`umask 0002; HOME=/tmp php artisan view:cache`, restart long-lived ordinary/default and `email`
workers so every runtime uses the same fingerprint contract, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: presentation checks must not replace server authorization; hiding audit rows must not delete
them or weaken API access controls; capability drift could show a control guaranteed to fail; and an
overly broad fingerprint could miss a real content change while an unstable one could falsely stale a
review. The split trigger/result behavior must remain synchronized and keyboard/screen-reader
operable; its layout review is tracked by `HR-2026-08-15-007` while this capability/fingerprint review
remains Pending.

Automated verification: the Smart Inbox reader/capability package passes 21 tests / 306 assertions.
The receipt-timestamp repair plus adjacent Smart Inbox regressions pass 36 / 408; an earlier combined
repair/reader package passed 47 / 578, and the complete Email test directory passes 347 / 3,030.
The split trigger/result follow-up plus desktop/navigation regressions pass 20 / 337, and the current
complete Email directory passes 349 / 3,066 under `HR-2026-08-15-007`.
Coverage includes default-closed controlled result behavior, reader-first result order, hidden
unavailable/terminal rows, retained applied history, recorded-agent and
exact-scope eligibility, forged/direct action denial, and schema-aware fingerprint staleness. Passing
automated checks will not replace the manual checks below.

Manual checks:

- [x] Open and return to a message with usable suggestions. Confirm Smart results remain after the
  complete email conversation and start closed each time. Use the trigger above the reader by mouse
  and keyboard; confirm synchronized expanded state, screen-reader naming, result focus, and focus
  return remain truthful. The detailed desktop placement check is also recorded under
  `HR-2026-08-15-007`.
- [x] With a read-capable but write-disabled agent, confirm Analyze may remain available while Apply,
  batch, correction/rule actions that cannot execute are absent and no generic unavailable alert is
  shown.
- [x] Disable/deactivate or replace the recorded agent, revoke mailbox access, deactivate the account,
  remove exact scopes, and disable/delete targets. Confirm unavailable pending reader content
  disappears without revealing hidden context or showing a generic unavailable error, while
  forged/direct calls still fail server-side.
- [x] Confirm stale/dismissed/revoked suggestions no longer clutter the selected-message reader but their
  durable rows/events remain available to authorized audit/API workflows. Confirm an applied result
  remains visible as history.
- [x] Change only local state, `updated_at`, or the derived search projection and confirm a pending
  suggestion remains valid. Then change real subject/body/participants, attachment metadata/count, or
  conversation membership and confirm the old suggestion becomes stale.
- [x] Test desktop, narrow mobile, keyboard focus, screen-reader labels, dark/light theme, and selection
  changes without focus loss or automatically expanding the panel.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-15-004 - Email Mail Decoded Subject Search Compatibility

Status: Reviewed
Added: 2026-08-15
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`, and
`docs/feature-slices/2026-08-15-email-mail-decoded-subject-search-compatibility.md`

Defect reported during review, 2026-08-15: the existing MariaDB definition of
`email_messages.received_at` included implicit `ON UPDATE CURRENT_TIMESTAMP`. The `121000` and
`121100` projection-only backfills consequently advanced receipt timestamps, changed Smart Inbox
source fingerprints, and produced false stale warnings. The prior claim that all message timestamps
and fingerprints were unchanged is withdrawn.

Rework completed on Dev, 2026-08-15: forward migration `121200` removed the implicit clause and froze
all 490 messages in an audit ledger. Preview identified 471 deterministically repairable timestamps
(439 sane header dates and 32 conflict-free conversation boundaries); apply restored those 471 and
left 19 unresolved candidates untouched. Exact source-ID and recorded-schema fingerprint comparison
recovered five suggestions falsely staled inside the corruption window without reactivating later or
mismatched stale evidence. This review is now Pending for the manual checks below; it is not Reviewed.

Scope: `/tech/mail`, legacy `/tech/inbox`, and `GET /api/v1/email/inbox/messages` now share one
parenthesized local SQL search contract. It searches the stored raw subject, a hidden nullable
`subject_search` projection produced by the bounded RFC 2047 presenter, sender name/address, and
plain-text body. This makes readable Q/Base64 and conservatively recovered truncated subject terms
searchable without rewriting the provider subject. User-entered `%`, `_`, and the `!` escape
character remain literal text rather than broadening the query. Eloquent subject writes maintain the
derived projection, and historical projections are rebuildable in bounded chunks.

Affected pages / workflows: conversation search and SQL pagination in `/tech/mail`; search and
filters in `/tech/inbox`; `q`, account filters, pagination, and response serialization in
`GET /api/v1/email/inbox/messages`; inbound and other Eloquent `EmailMessage` subject writes; and the
two `subject_search` migrations. Rework additionally affects the `received_at` schema/repair ledger,
schema-aware Smart Inbox fingerprints, and exact false-stale recovery events.

Out of scope / not reviewed here: rewriting `email_messages.subject`, `email_conversations.subject`,
headers, Ticket evidence, or provider data; changing rule matching, TD/SO Ticket correlation,
conversation identity, provider fingerprints, API response fields, mailbox grants, folder semantics,
the existing FULLTEXT definition, or choosing an external search/index service. Smart Inbox changes
are limited to recording/evaluating the fingerprint schema and recovering exactly matched
timestamp-corruption fallout. The API still returns the stored raw `subject`; `subject_search` is not
fillable and is hidden from serialization.

Deploy / migration notes: Dev migration
`2026_08_15_121000_add_email_message_subject_search.php` has run after recovery in batch 95. It added the nullable
512-character projection and performed the initial bounded backfill without changing `updated_at`.
`2026_08_15_121100_harden_email_message_subject_search_backfill.php` ran in batch 96. It is a
forward-only, idempotent rebuild of missing or stale values. Each update compares the originally
read raw subject and projection before writing, so a concurrent fresher subject writer wins while an
unrelated state update does not prevent repair; its `down()` is intentionally a no-op.

`2026_08_15_121200_harden_email_message_received_at.php` ran in batch 97. MariaDB now reports an empty
`EXTRA` value for `received_at`, and its ledger holds the frozen 490-message repair scope. The
forward-only operator command previews by default and applies only evidence-supported timestamps;
unresolved candidates remain unchanged. It performs no provider read/write, rule replay, Ticket
mutation, or outbound action.

For another environment, pause the ordinary/default and `email` queue workers through the normal
worker manager, deploy the compatible code, and run migrations in timestamp order (`121000`,
`121100`, `121200`). Confirm `received_at` has no `ON UPDATE` clause and inspect the frozen ledger.
Run `php artisan email:repair-received-at` first as preview, accept only the evidence-supported count,
then use `--apply`; never guess unresolved timestamps. Run
`umask 0002; HOME=/tmp php artisan optimize:clear`, rebuild compiled views with
`umask 0002; HOME=/tmp php artisan view:cache`, restart/resume every paused worker, and sync Email
Knowledge with `php artisan knowledge:sync-docs --module=Email --push`. Confirm migration, repair,
projection, and Smart recovery counts before reopening normal processing. No permission seed,
scheduler change, frontend build, provider setting/call, or external index is required.

Risks: rewriting the raw subject would change identity-bearing provider evidence and could affect
rules, Ticket correlation, conversation grouping, and fingerprints. A derived value returned by the
API would silently change its contract. An unparenthesized subject/sender/body OR branch could escape
mailbox View, account, folder, Ticket, or state filters. Unescaped `%` or `_` input could match every
row instead of the literal character. An old worker writing during or after the rebuild could leave a
missing/stale projection; the worker pause/restart and CAS repair are therefore deploy gates. The
receipt-timestamp repair has 19 candidates without safe deterministic evidence; they must remain
explicitly unresolved rather than guessed. The additional local substring OR does not add a new
index, so large-dataset query cost and database-side 25-row conversation pagination must remain under
observation.

Automated verification: the search-surface regression passes with 4 tests / 58 assertions.
Together with adjacent conversation-query and Mail navigation/readability regressions, it passes
with 13 tests / 231 assertions, including 30 matching durable conversations represented by 60
placements and paginated as 25 plus 5 latest-message leaders. Projection coverage passes 9 tests /
56 assertions; projection plus the three surfaces pass 13 / 114, and the full focused package with
adjacent regressions passes 22 / 287. The complete Email test directory passes 347 tests / 3,030
assertions. Dev migration status confirms batches 95 and 96. A sanitized post-repair audit found 490
projection rows, zero projection mismatches, and 32 intentionally different decoded values. The
receipt-timestamp repair plus adjacent Smart Inbox regressions pass 36 / 408; an earlier combined
repair/reader package passed 47 / 578. Dev migration status confirms batches 95, 96, and 97. The
timestamp audit contains 490 rows: 471 evidence-supported repairs, 19 untouched unresolved
candidates, and five exact false-stale recoveries. MariaDB reports an empty `EXTRA` value for
`received_at`. Prior unchanged receipt-timestamp/fingerprint digest claims are not completion
evidence. Pint, PHP syntax, migration status, and scoped diff checks pass.
Automated checks do not replace the manual checks below.

Manual checks:

- [x] Confirm Dev reports `121000` as batch 95, `121100` as batch 96, and `121200` as batch 97;
  confirm MariaDB shows no `ON UPDATE` clause and the timestamp ledger contains the frozen 490 rows.
- [x] Review the timestamp repair evidence: 439 header-date rows plus 32 conversation-boundary rows
  were applied, 19 unresolved candidates remain untouched, and rerunning preview/apply is idempotent.
  Confirm no provider call, rule replay, Ticket mutation, or outbound action occurred.
- [x] Confirm the five recovered Smart Inbox suggestions have exact source/fingerprint recovery
  evidence, while a later legitimately stale or mismatched suggestion remains stale.
- [x] In `/tech/mail`, search separately for readable terms found only inside a UTF-8 Q-encoded,
  Base64-encoded, and truncated `=?utf-8?Q?...=C3=` stored subject. Confirm each authorized
  conversation appears with friendly text and the correct newest matching message.
- [x] Repeat the decoded searches in legacy `/tech/inbox` and through
  `GET /api/v1/email/inbox/messages?q=...`; confirm all three surfaces agree on accessible results.
- [x] Inspect the API response for an encoded match and confirm `subject` is the exact stored raw
  encoded value and no `subject_search` field is present.
- [x] Search each surface by a raw encoded-subject fragment, plain subject, sender name, sender
  address, and body-only term; confirm these existing matches still work.
- [x] Create safe messages containing literal `%`, `_`, and `!` characters, with near-miss rows that
  omit each character. Search for each character on all three surfaces and confirm only literal
  matches appear rather than wildcard-expanded results.
- [x] With accessible and inaccessible mailboxes plus two accounts, folder-scoped, Ticket-linked,
  and non-Ticket messages, confirm search never escapes mailbox View, selected account/folder,
  Ticket, state, or API account filters.
- [x] Use at least 30 matching durable conversations with two placements/messages each. Confirm Mail
  reports 30 conversations, page one has 25, page two has 5, each row reports two messages, and the
  leader is the newest matching message rather than either of 60 placement rows.
- [x] Compare representative rows before and after the migrations. Confirm raw subjects, conversation
  keys/subjects, Message-IDs, Ticket references, rule outcomes, provider evidence, mailbox placements,
  and raw API payloads remain unchanged while `subject_search` is the readable rebuildable value.
  For repaired timestamps, confirm each new value matches its ledger evidence; for the 19 unresolved
  candidates, confirm the repair made no value up.
- [x] After workers restart, import one encoded provider message and update another subject through
  the supported Eloquent path. Confirm both projections are immediately searchable and no provider
  write, Ticket reroute, or conversation regrouping is triggered by projection maintenance.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-15-003 - Email Mail Runtime Reliability, Truthful Send Follow-Up, And Right-Bar Controls

Status: Reviewed
Added: 2026-08-15
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/feature-slices/2026-08-15-email-mail-runtime-reliability-hardening.md`,
`docs/feature-slices/2026-08-16-email-mail-private-storage-inventory.md`,
`HR-2026-08-14-010`, `HR-2026-08-13-030`, `HR-2026-08-13-027`, `HR-2026-08-13-023`,
`HR-2026-08-13-024`, `HR-2026-08-13-021`, `HR-2026-08-13-020`, and
`HR-2026-08-13-019`

Scope: Mail runtime hardening for defects found during real Dev use. Provider operations now
resolve special folders from provider SPECIAL-USE or the exact folder leaf, check exact UID presence
without fetching headers, stop a confirmed missing source as stale, and keep genuine provider-read
failures out of blind automatic retry. Connection/read preflights remain audit evidence without
consuming the provider-mutation attempt budget, and raw provider exceptions such as
`no headers found` never enter user-facing operation fields. Manual provider Draft sync re-infers an
exact selectable, sync-enabled Drafts folder and uses a tokenized durable APPEND reservation: fresh
reservations block concurrent calls, only a five-minute-stale pre-write reservation can be taken
over, and a started/unresolved provider response cannot be replayed. New private Email writes share a
verified group-access contract across PHP-FPM and queue-worker users, and stored attachment IDs are
rebound to the exact active authorized draft before SMTP. One unique Email log and stable Message-ID
are reserved atomically before SMTP, and initial same-identity Sent reconciliation is attempted
separately;
concurrent/repeated submission cannot elect a second sender, and an uncertain transport outcome
remains blocked for Sent review. SMTP acceptance remains sent even when local log finalization,
account telemetry, the local Sent snapshot, or the reconciliation record fails afterward; the local
draft is marked sent and the warning says not to resend. A failed reconciliation insert removes its
newly created raw snapshot, and later exact provider Sent evidence can resolve an unconfirmed
reservation without resending. A successful manual provider Draft APPEND queues one bounded
exact-folder refresh to import the authoritative Drafts placement. Technical provider Sent APPEND is
reserved under a row lock and stays blocked after an ambiguous provider-write start. Mailbox
operations and Mail signature are now default-collapsed right-bar cards, with operation counts still
visible and the signature modal's X/Cancel/footer layering preserved.

Affected pages / workflows: `/tech/mail` Mailbox operations and Mail signature right-bar cards,
Archive/Trash/Seen/Flag/Move provider actions and retry/reconciliation, Compose/Reply/Reply All/Forward
send completion, local/provider draft Save/Send/Discard, provider Drafts view, inbound raw/attachment
storage, durable draft attachments, provider Sent follow-up storage, and the read-only
`email:inventory-private-storage` operator workflow.

Out of scope / not reviewed here: permanent provider deletion, bulk retry, automatic external
replies, full historical Drafts import, a new provider adapter, database migration, storage-disk
migration, rewriting immutable historical operation targets, and automatically proving or replaying
an SMTP transport outcome that the provider did not confirm. Do not use the repaired historical
ambiguous delete/Trash operation as a retry test; its controlled repair is retained below as audit
evidence and deliberately did not replay the original provider mutation.

Controlled Dev repair: fresh read-only provider evidence showed operation `23`'s source UID absent,
one exact Message-ID match at Trash UID `30177` in canonical folder `141`, no copy in the wrongly
classified child, and draft `1`'s exact Message-ID at its recorded Drafts UID. Operation `23` was
cancelled, stale source placement `474` was hidden, the verified Trash copy was projected as
placement `485`, the child folder role was repaired to `custom`, and draft `1` was marked sent with
its exact provider copy deleted after matching the outbound send-log identity. The provider
post-check confirmed source absent, correct Trash copy present, wrong child empty, and draft UID
absent. The repair issued zero SMTP writes and zero IMAP MOVE writes. No provider Sent copy was
fabricated or appended because the exact Sent Message-ID was not present and the raw outbound
snapshot was unavailable.

Deploy / migration notes: No migration, permission seed, scheduler registration, provider setting,
or frontend build is required. The send reservation reuses the unique key from existing migration
`2026_08_12_125000_add_email_log_idempotency_key.php`; confirm normal migration status rather than
adding a new schema change. Deploy the code, run
`umask 0002; HOME=/tmp php artisan optimize:clear`, run
`umask 0002; HOME=/tmp php artisan view:cache`, restart the ordinary/default and `email` queue
workers so they load `RefreshEmailProviderDraftFolder` and the hardened operation/send classes, and
sync Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`.

Run `HOME=/tmp php artisan email:inventory-private-storage` without `--show-paths` before and after
the root/operator mode repair. A nonzero exit remains expected while the 28 missing raw references
exist even after the 79 mode blockers are corrected; do not widen scope, print paths into ordinary
logs, delete files, or change database references merely to force a successful exit.

The read-only `email:inventory-private-storage` slice is implemented. The latest redacted Dev run
inspected 1,445 files without mutation: 968 referenced and 477 unreferenced. It reports 28 missing
`message_raw` references, 79 non-private `0644` files, and 15 duplicate unreferenced checksum+size
groups. Duplicate or unreferenced status is evidence only and authorizes no deletion.

The preceding structural audit found all 61 directories `www-data`, mode `2770`, with group-rwx
access/default ACLs, plus zero symlinks, unsafe paths, or unreadable files. File-mode normalization is
not complete: the 79 `0644` files are `www-data`-owned, and the SSH project user cannot safely chmod
them. A root/operator must change only those 79 modes to `0660` without changing content, ownership,
location, or existence, then rerun the read-only inventory and PHP-FPM/queue dual-runtime read/write
smoke. The 28 missing raw references and 477 unreferenced files require separate provenance,
reconciliation, retention, Ticket/legal-hold/backup, and deletion review. Attachment recovery
readiness remains `safe=true` and `received_at_schema_safe`, but none of these facts closes the
owner/root blocker or the remaining browser, provider/send, failed-job, and right-bar checks. This
entry remains Pending.

Risks: a false missing-UID result could suppress a legitimate provider action, while treating a read
failure as absence could corrupt the local projection. A wrong special-folder target could move mail
into a custom child. A post-SMTP exception must never invite a duplicate send, but provider Sent
placement may remain pending when its local snapshot is unavailable. The persisted Email-log
reservation must be created before SMTP and retain one immutable identity when the outcome is
uncertain; automatic replay could duplicate a message. Preliminary reconciliation failure must stay
`reservation_failed`, and an ambiguous SMTP handler must not overwrite a concurrent exact Sent-sync
confirmation. The targeted Drafts refresh must not import
history, cross accounts, run Inbox automation, or trust stale UIDVALIDITY. Saved attachment IDs must
not escape the exact active draft/account authorization. A provider Sent append whose write started
must not run again merely because acknowledgement failed. Likewise, a provider Draft APPEND must
have one current reservation owner; a fresh/concurrent reservation must not be stolen, a stale
pre-write takeover must not inherit an unsafe provider-write claim, and an unresolved started write
must remain reconciliation-only. New shared file modes must not broaden private Email data beyond the
intended runtime group. The normalized paths still require the named human's cross-runtime/browser
verification below.

Automated verification: the integrated runtime-focused package passes 74 tests / 613 assertions;
the full `EmailModuleTest.php` passes 141 / 1,227; and `InboundAutomationTest.php` passes 14 / 81
against isolated fake Email storage. The focused package covers private storage, pre-/post-SMTP
safety, provider Sent APPEND, tokenized provider Draft APPEND/targeted refresh, composer lifecycle,
remote-operation recovery/preflight accounting, verified Undo, and supervised Smart Inbox cleanup.
The focused read-only private-storage inventory test passes **3 tests / 21 assertions**, covering all
reference sources, redacted/explicit path output, duplicate groups, missing references, scan caps, and
no file/row mutation. The earlier 939-file command run changed no file, permission, database, provider,
queue, or retention state and correctly remained failed while missing references/non-private modes
exist; the latest 1,445-file direct readback is recorded above and leaves those blockers open. Pint,
targeted PHP syntax, Blade cache, and diff checks pass. The automated tests used no real
provider mutation; the separately recorded controlled repair used exact provider evidence and the
zero-SMTP/zero-MOVE boundary above. Automated checks do not replace these manual checks.

Manual checks:

- [x] Open `/tech/mail` with active/recent provider work. Confirm **Mailbox operations** appears in
  the right bar, starts collapsed, shows correct pending/running/failed/recent counts in its header,
  expands without leaving the right bar, and retains keyboard focus plus working Retry, Cancel, and
  eligible Undo controls. With no active/recent work, confirm the card is absent.
- [x] Confirm **Mail signature** starts collapsed below the page AI chat. Expand it, open settings,
  and confirm the modal remains above the footer and closes through X, Cancel, Escape, and backdrop
  while returning focus to the trigger. Save a toggle and confirm the signature body is unchanged.
- [x] In a disposable mailbox with a canonical Trash plus a custom child below Trash (and similarly
  Archive where available), move one safe test message through the normal action and confirm the
  provider and Nexum target is the real special folder, never the custom descendant.
- [x] For a disposable test placement whose exact source UID was removed externally, run the safe
  operation/retry path and confirm it stops as stale with no provider mutation, no automatic retry,
  and no raw `no headers found` text in the UI or persisted user-facing reason. Confirm connection,
  UID-read, and authorization preflights remain audit rows without incrementing the mutation count.
  Separately simulate a provider read failure and confirm it is not misclassified as confirmed
  absence or automatically replayed.
- [x] Inspect an ambiguous historical Archive/Trash/Move row without immutable target path or target
  UID evidence. Confirm it remains visible for review/cancellation but offers no manual Retry.
- [x] Inspect the controlled operation `23` repair without running it again. Confirm it is cancelled,
  placement `474` is hidden, placement `485` represents Trash UID `30177` in folder `141`, the wrong
  child role is `custom`, draft `1` is sent/provider-deleted, and there is no fabricated provider Sent
  placement for the outbound log whose exact Message-ID was absent.
- [x] Save a new draft in a mailbox with an initialized provider Drafts folder, process the ordinary
  queue worker, and confirm the exact draft appears in Nexum Drafts and the external provider once
  with the same Message-ID/content. Confirm no Ticket, Signal, Inbox rule execution, or Inbox unread
  work is created. Confirm Drafts selection ignores a stale descendant role and requires the
  re-inferred selectable, sync-enabled canonical folder. Change UIDVALIDITY in a controlled test and
  confirm refresh fails closed.
- [x] In an isolated Drafts APPEND fault/concurrency check, confirm one fresh token reservation elects
  one writer, a second call performs no APPEND, and only a pre-write reservation older than five
  minutes can be taken over. Simulate loss of the provider response after APPEND starts and confirm
  later Save/queue runs perform reconciliation only, with no second APPEND.
- [x] Send one safe test message and confirm SMTP is invoked once, the composer closes, the local
  draft becomes sent, provider-draft cleanup runs, and normal Sent reconciliation remains pending or
  reconciles honestly. Confirm receipt in the destination mailbox separately.
- [x] In isolated controlled Dev fault cases, make accepted-log finalization, account telemetry,
  Sent snapshot, and reconciliation recording fail after simulated SMTP acceptance. Confirm Mail
  still says the message was accepted, includes **Do not resend it**, never says it could not be
  sent, marks the draft sent, stores only sanitized warning metadata, and a repeat with the reserved
  idempotency key does not call SMTP twice.
- [x] In a controlled transport-ambiguity case, confirm the pre-SMTP row keeps the reserved
  Message-ID, changes to unresolved evidence, tells the technician not to resend, and blocks both a
  repeated and concurrent submission until provider Sent mail is reviewed. Import the exact
  same-account Sent copy and confirm normal sync resolves the row as accepted without SMTP replay.
- [x] Force only the preliminary Sent-reconciliation write to fail and confirm the reservation says
  `reservation_failed`, not accepted. In a controlled race, confirm an exact Sent-sync confirmation
  wins over later ambiguous SMTP exception handling.
- [x] In a controlled backend Sent-append case, call an accepted append twice and confirm one IMAP
  write. Then simulate an exception after provider-write start and confirm repeated processing stays
  blocked with sanitized evidence until normal Sent sync reconciles the outcome.
- [x] Force reconciliation persistence to fail after a new raw Sent snapshot is written and confirm
  the new file is removed rather than left orphaned.
- [x] Attempt a controlled Livewire request containing an attachment ID from another same-user draft
  or mailbox. Confirm the file is not handed to SMTP, while attachments from the exact active and
  currently authorized draft still send once.
- [x] As root/operator, apply a mode-only normalization to the exact 79 `www-data`-owned legacy files
  currently at `0644`, changing them to `0660` without reading/modifying content, moving, deleting,
  changing ownership, or broadening other access. Rerun `email:inventory-private-storage` and confirm
  all 61 directories remain `www-data`/`2770` with group-rwx access/default ACLs, the non-private-mode
  count becomes zero, the recorded 1,445-file inventory reconciles, and there are no symlinks, unsafe
  paths, or unreadable files.
- [x] Review the current aggregate read-only inventory totals: 1,445 files, 968 referenced, 477
  unreferenced, 28 missing `message_raw` references, 79 non-private modes, 15 duplicate unreferenced
  checksum+size groups, and zero unsafe/unreadable files. Trace provider/send, database, backup,
  retention, Ticket/legal hold, and recovery provenance; do not delete, move, chmod, chown, or repair
  database references as part of inventory review.
- [x] After that owner/root repair, write one safe private Email test payload from the web/FPM path
  and one from the queue-worker path. Confirm both runtimes can traverse/read/write the intended
  subtree, directories remain setgid/group-writable, files remain group read/write, other users gain
  no access, and an intentionally failed write never persists `raw_path`.
- [x] Check the two right-bar cards and their expanded content at desktop and 320-375px mobile widths
  in light/dark/system themes. Confirm long operation reasons/status badges wrap without overlapping
  the Mail reader, footer, modal controls, or adjacent AI cards.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-15-002 - Email Mail Folder Hierarchy And Subject Readability

Status: Reviewed
Added: 2026-08-15
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`, and
`docs/feature-slices/2026-08-15-email-mail-folder-hierarchy-subject-readability.md`

Scope: The normal `/tech/mail` Folders navigation now renders each authorized provider mailbox as
an expandable parent/child hierarchy. Branches start collapsed; each explicit open/close choice is
stored per technician and provider folder across sessions/devices, while selecting a nested folder
opens and remembers its ancestor path. A passive deep-link/reload respects an explicit stored close
and marks the collapsed ancestor as containing the current folder. Non-selectable parents are
structural only, and a folder click retains exact physical folder filtering. Common and
conservatively salvageable truncated RFC 2047 subjects are presented as safe readable Unicode in
Mail lists/readers and Reply/Forward subject presentation.

Affected pages / workflows: `/tech/mail` sidebar folder navigation, account/folder filters,
conversation rows and expanded child rows, threaded reader, recent mailbox-operation labels,
Reply/Forward composer defaults, and legacy `/tech/inbox` list/detail subject headings.

Out of scope / not reviewed here: provider folder changes, descendant/subtree filtering, mailbox
permission changes, generic User preference/API fields, stored-subject backfill, decoded historical
SQL search, Email rule matching, Ticket-number extraction, provider evidence, API subject payloads,
conversation identity, queue/scheduler behavior, or external provider writes. The separate
decoded-subject search/data follow-up is recorded in `docs/TODO.md`.

Deploy / migration notes: Dev ran
`2026_08_15_120000_create_email_folder_navigation_preferences.php` in batch 94. Run that migration
before serving the updated Mail sidebar in another environment with
`php artisan migrate --force --path=database/migrations/2026_08_15_120000_create_email_folder_navigation_preferences.php`.
No permission seed, queue restart, scheduler change, frontend build, provider configuration, or data
backfill is required. Deploy the code, run `php artisan optimize:clear`, run
`php artisan view:cache`, and sync Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`.

Risks: identical folder paths in different mailboxes must never share state or leak access.
Non-selectable provider containers must not become filter targets, and stale deleted leaves must not
reappear. Stored expansion rows must remain per user/folder, must not grant access after a mailbox
grant is revoked, and concurrent device changes to different branches must not overwrite each other.
Counts must remain folder-local provider mailbox unread state. Subject decoding must not
corrupt ordinary Norwegian/Unicode text, expose invalid/header-control bytes, interpret HTML, or
alter stored identity-sensitive values. Historical search continues to use the raw stored subject.

Automated verification: focused hierarchy, persisted preference, and subject presentation coverage
passes with 10 tests and 133 assertions. Six adjacent folder, folder-manager, Reply, and Forward
regressions pass with 100 assertions. The complete Email test directory passes with 267 tests and
2,377 assertions, including `EmailModuleTest` with 141 tests and 1,206 assertions. Targeted Pint,
PHP syntax, Blade cache, and diff checks pass. The complete Laravel project suite passes with 1,494
tests and 12,940 assertions. Migration and final documentation/Knowledge verification are recorded
in the related Feature Slice.

Manual checks:

- [x] In one mailbox with parent, child, and grandchild folders, expand and collapse each branch;
  confirm indentation, connector lines, chevrons, names, icons, and keyboard focus are clear.
- [x] Reload Mail, sign out/in, and open Mail in another browser/device; confirm explicitly opened
  branches remain open and explicitly closed branches remain closed for the same technician. Confirm
  another technician starts with independent collapsed state.
- [x] Select a deeply nested folder from the sidebar and confirm all ancestors reveal, the current
  row is distinct, the opened ancestor path remains remembered, and the message list contains only
  that exact physical folder rather than descendants.
- [x] Close an ancestor of the current folder, reload/deep-link to the same folder, and confirm the
  branch stays closed but its parent visibly and accessibly says it contains the current folder;
  selecting the nested folder again must reopen and remember the path.
- [x] With two accessible mailboxes containing the same folder paths, expand one branch and confirm
  the other remains independent. Confirm an inaccessible mailbox never appears.
- [x] Confirm a non-selectable provider parent is a label/disclosure only, its selectable child can
  be opened, and a stale deleted non-selectable leaf stays hidden.
- [x] Compare parent and child provider unread counts and confirm each badge is labelled mailbox
  unread, is not summed from children, and is not confused with Nexum Unread for me.
- [x] Open messages with UTF-8 Q, Base64, ISO-8859-1, ordinary Norwegian/emoji, adjacent folded
  encoded words, and the truncated `=?utf-8?Q?Fwd=3A_...=C3=` pattern. Confirm readable, stable text
  in the conversation row, expanded child, reader, and legacy Inbox surfaces.
- [x] Start Reply and Forward from an encoded subject and confirm their subject fields and forwarded
  header are readable while normal threading and send behavior remain unchanged.
- [x] Use an HTML-like subject and confirm it is shown as text rather than markup. Confirm malformed
  or unsupported input remains safe and no header-control/newline content changes the surrounding UI.
- [x] Check mouse, Tab/Shift+Tab, Enter/Space, visible focus, screen-reader labels, light/dark theme,
  a 320-375px mobile sidebar, long Unicode folder names, and at least five hierarchy levels.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-15-001 - Email Mail Selected Conversation List Expansion

Status: Reviewed
Added: 2026-08-15
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-15-email-mail-selected-conversation-list-expansion.md`,
`HR-2026-08-14-002`, and `HR-2026-08-14-003`

Scope: Selecting a multi-email conversation in `/tech/mail` now expands that one center-list row
with an indented, newest-first list of the authorized mailbox placements in the conversation. Each
child is a keyboard-accessible control for one exact placement and stays synchronized with the
selected row in the threaded reader. The parent remains qualified and paginated by the current
view; authorized Sent, Archive, or other folder context may appear below it with explicit
`in this view` and `in conversation` counts when those scopes differ. One-email conversations are
not duplicated.

Affected pages / workflows: `/tech/mail` center conversation list, selected threaded reader,
folder/search/filter navigation, exact-placement command-bar actions, and the bounded legacy
header-thread fallback.

Out of scope / not reviewed here: new persistent data models or migrations, manual expansion of
several conversations, bulk conversation actions, provider writes, automatic read acknowledgement,
cross-account merging, API behavior, Ticket behavior, or IMAP synchronization. The existing
placement-opened receipt still records an authorized selection.

Deploy / migration notes: No migration, permission seed, scheduler change, queue restart,
frontend build, provider configuration, or data backfill is required. Deploy the code, run
`php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: a child must never broaden mailbox access, enter from another account, or change the parent
paginator/filter result. Folder context and counts must make it clear why an email outside the
current view is visible. Selecting a child must target that exact provider placement without
silently changing personal unread or provider Seen state. Long and legacy threads must remain
usable without nested scrolling, inaccessible markup, or ambiguous selection.

Automated verification: focused conversation query and access regressions pass with 6 tests and
81 assertions. The existing conversation grouping flow passes with 1 test and 16 assertions.
The full `EmailModuleTest.php` passes with 141 tests and 1,193 assertions. Blade cache, formatting,
PHP syntax, diff, and compiled-view permission checks pass. Email Knowledge sync processed one
chapter and one article with nothing skipped and queued the BookStack push. The final queue check
reports one pre-existing `FetchImapAccount` failure from 2026-08-15 10:46; this UI slice dispatched
no sync job, and the separate private-storage
cross-user permission blocker is recorded in `docs/TODO.md` without retrying or deleting the row.

Manual checks:

- [x] Open a multi-email conversation and confirm exactly that conversation expands below its
  parent; select another conversation and confirm the first closes.
- [x] Open a one-email conversation and confirm it does not render a duplicate child row.
- [x] Click an older child in the center list and confirm the exact same email becomes selected and
  expanded in the right reader; then click a different reader row and confirm the center child
  highlight follows it.
- [x] Use an Inbox conversation with authorized Sent or Archive context and confirm the parent stays
  in the filtered list, the context child is clearly folder-labelled, and actions target only the
  selected placement.
- [x] Confirm the row labels separate `N mails in this view` from `M mails in conversation` when
  search, folder, or list filters reduce the parent scope.
- [x] Confirm opening or switching children does not itself mark `Unread for me` as read or change
  provider Seen state. Verify personal **Unread** in the child list and authoritative `Mailbox
  read/unread` in the detailed reader; the list intentionally no longer duplicates the provider
  badge after `HR-2026-08-15-007`.
- [x] Remove mailbox View access or deactivate the account, refresh the component, and confirm no
  child subject, sender, folder, or placement from that account remains visible. Confirm matching
  thread headers in another account never enter the expanded list.
- [x] Navigate parent and child controls with Tab, Shift+Tab, Enter, and Space; confirm visible focus,
  a clear selected-email announcement, no nested buttons, and no unexpected focus jump.
- [x] Check light, dark, and system themes plus roughly 340 px, 575 px, and 1199 px widths with long
  sender, subject, account, and folder labels; confirm selection remains readable and controls do
  not overlap.
- [x] Check a 25-plus-message durable thread and a bounded legacy thread; confirm ordering matches
  the reader, conversation pagination is unchanged, and legacy Load more remains usable.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-015 - Email Mail Provider Deletion Reconciliation

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-provider-deletion-reconciliation.md`,
`HR-2026-08-14-009`, `HR-2026-08-14-010`, and `HR-2026-08-14-012`

Scope: Email can run a bounded, account-scoped provider inventory that recognizes placement loss
only when a complete folder scan has stable start/end `UIDVALIDITY`, `UIDNEXT`, and message counts.
Confirmed loss creates an immutable finding and hides the placement as a seven-day tombstone.
Exact reappearance restores it and cancels cleanup; a move is recognized only from conservative
provider evidence for an already projected target. After grace, cleanup repeats placement,
retention, unresolved-operation, and Ticket-evidence checks before removing eligible Mail cache,
local files, tags, and source-derived Smart Inbox artifacts. Scheduler dispatch exists, but every
job is disabled unless the Admin opt-in value is exactly `1`; the default is off.

Affected pages / workflows: Email Sync & Cache Settings, the scheduled provider-deletion inventory
and cleanup jobs on the `email` queue, provider folder/placement projection, local cache retention,
Smart Inbox artifact lifecycle, and Ticket-owned evidence isolation.

Out of scope / not reviewed here: a Nexum action that permanently deletes provider mail,
cross-account correlation, hidden backup/archive behavior, a complete legal-hold/DSAR/offboarding
product, a new search/index provider, or deletion of Ticket-owned snapshots.

Deploy / migration notes: Migration
`2026_08_14_115000_add_email_provider_deletion_reconciliation.php` ran in batch 93 and created empty
inventory, finding, and cleanup-ledger structures. The deliberately staged Dev chain was `105000`
(batch 86), `110000` (87), `111000` (88), `112000` (89), `112500` (90), the dated-later `113000`
Undo migration (91), then `114000` (92) and `115000` (93). To reproduce the reviewed rollout, run
those exact migration paths in that sequence. A fresh combined `php artisan migrate` sorts the
2026-08-14 `114000`/`115000` filenames before the 2026-08-15 `113000` filename; the schemas have no
foreign-key dependency and are currently safe in that framework order, but it is not the same staged
behavioral rollout. Then run `php artisan optimize:clear`, compile views with the required
group-write umask, restart the `email` queue worker, verify the external scheduler runner, and sync
Email Knowledge. Keep
`provider_deletion_reconciliation_enabled=0` until this review passes; do not infer approval from
the jobs appearing in `schedule:list`.

Risks: An incomplete or unstable inventory must never be mistaken for deletion. Local file cleanup
is not application-reversible, a remote move can resemble disappearance, a reappearing UID can race
with grace cleanup, and removing source-derived artifacts must not remove independent Ticket evidence
or another surviving placement. Findings contain operational identifiers and must remain
account-scoped and content-free.

Automated verification: The current focused provider-deletion suite passes **13 tests / 129
assertions**. Provider deletion plus the earlier retention, conversation identity, recovery, and
Smart Inbox regression set passes **41 / 323**. PHP syntax, focused Pint, migration pretend, and
whitespace checks passed for the isolated implementation. Final broad regression, runtime, and
Knowledge-push evidence belongs to the parent workstream and is not claimed here.

Manual checks:

- [x] Before enabling the setting, run the scheduler/dispatcher and confirm no account scan or
  cleanup starts; confirm a missing, malformed, or non-exact setting value also fails closed.
- [x] Open Email Sync & Cache Settings and confirm the provider-deletion option is visibly off by
  default, explains the destructive local-cache risk, and saves both checked and unchecked states.
- [x] On one safe Dev mailbox, enable the option and run a complete bounded inventory. Confirm the
  recorded start/end folder facts are stable and contain no subject, address, body, header,
  attachment name, raw provider payload, or credential.
- [x] Force an incomplete scan, UIDVALIDITY change, scan limit, provider error, or concurrent folder
  drift and confirm no active placement is hidden, moved, or cleaned.
- [x] Remove one safe provider message outside Nexum and confirm the exact placement becomes a
  hidden seven-day tombstone with an immutable finding; confirm Mail payload/files still exist
  during grace.
- [x] Reintroduce that exact provider occurrence during grace and confirm the placement is restored
  and its old cleanup path is cancelled without a duplicate conversation or finding effect.
- [x] Move a safe message in another provider client and confirm Nexum recognizes a move only when
  the target identity is already and conservatively proven; ambiguous disappearance must remain
  protected.
- [x] With two active placements for one message, remove only one and confirm the surviving placement
  protects the conversation and all shared Mail payload.
- [x] For an eligible test tombstone beyond grace, run cleanup and confirm only Mail-owned cache,
  tags, and source-derived Smart Inbox artifacts are removed. Confirm separately captured Ticket
  evidence and its attachments remain readable under Ticket authorization.
- [x] Simulate a partial local-file deletion failure and confirm the attempt stays failed/retryable,
  missing files are idempotent on retry, and the database never records false completion.
- [x] Revoke account scope or disable the opt-in while work is queued and confirm execution stops
  before provider access or cleanup. Finally return the setting to off after the controlled review.

Expected result: Only a complete, stable, explicitly enabled provider inventory can create a
placement-loss finding. Grace, reappearance, surviving placements, unresolved work, retention, and
Ticket ownership all fail closed; local cleanup is bounded, idempotent, auditable, and disabled by
default.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-014 - Email Mail Supervised Smart Inbox Cleanup

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-supervised-smart-inbox-cleanup.md`,
`HR-2026-08-14-011`, `HR-2026-08-14-012`, and `HR-2026-08-14-013`

Scope: A human can accept typed Smart Inbox `archive_mail` or `move_to_folder` suggestions for an
existing selectable same-account target. Application records one deterministic provider-operation
reference, commits suggestion state before provider I/O, and uses the normal recovery and verified
Undo paths. A batch is one exact unique snapshot of at most 50 suggestion IDs with per-item
reauthorization and results. `Always do this` opens only a prefilled existing personal modal or
Admin rule builder; it creates no rule by itself. Admin prefills are inactive and use distinct
`provider_archive` / `provider_move` actions, while legacy `archive` remains local-only.

Affected pages / workflows: the Smart Inbox review queue in `/tech/mail`, its selection and batch
controls, recent mailbox-operation/Undo evidence, the personal rule modal, the Admin Email rule
builder, and published Email-rule execution against provider Archive/Move.

Out of scope / not reviewed here: permanent delete, provider read-state changes, send/reply,
automatic or unattended cleanup, silent rule creation/publication, arbitrary Ticket/Task/Signal
writes, or automatic external replies.

Deploy / migration notes: No additional migration follows the Smart Inbox `114000` migration in
batch 92 for this slice. It requires the `113000` verified Undo foundation in batch 91. Deploy code,
run `php artisan optimize:clear`, compile views with the required group-write umask, restart the
`email` queue worker, verify the remote-operation retry scheduler, and sync Email Knowledge. No new
permission seed or frontend build is required.

Risks: A stale target folder or revoked organizer/publisher must fail before IMAP. Provider failure
must not be reported as successful application; provider Seen and personal unread state must remain
unchanged. The reviewed source placement/UID/version must not be followed after another move, and a
batch must not run two cleanup effects against the same source. Batch membership must not grow after
confirmation. A prefill must never become hidden learned behavior or place sender/subject data in a
URL, and the new provider actions must not change the compatibility meaning of legacy local
`archive`.

Automated verification: Focused supervised-cleanup coverage passes **11 tests / 170 assertions**,
including exact source-placement CAS, chained-move rejection, cleanup-only batches, revoked
Organize, disabled targets, ambiguous sources, deterministic provider operations, verified Undo
linkage, personal/provider unread preservation, safe Livewire rendering, status-correct provider
feedback, folder correction, one-use opaque Admin prefill, and no-write personal/Admin rule prefill.
The combined Smart Inbox foundation, reviewed-apply, review-queue, and cleanup set passes
**32 / 422**, and the broader focused Mail workstream set passes **112 / 993**. Focused PHP syntax,
Pint, and diff checks pass. The final application-wide Dev suite passes
**1,482 tests / 12,749 assertions**.

Manual checks:

- [x] Analyze a safe conversation, accept an Archive suggestion, and confirm one remote operation is
  shown, reaches acknowledged success only after provider confirmation, and exposes normal verified
  Undo when eligible.
- [x] Undo the safe Archive/Move and confirm the exact acknowledged placement returns to its original
  selectable folder without changing provider Seen or any user's Nexum `Unread for me` state.
- [x] Accept the same suggestion again and confirm it returns the same applied reference without a
  second provider mutation.
- [x] Select several cleanup suggestions, confirm the exact snapshot before apply, and verify each
  item has its own success/failure reason. Confirm a later suggestion cannot join the batch and more
  than 50 IDs is rejected.
- [x] Make one selected suggestion stale, revoke Organize on another, and disable a third target
  folder; confirm each fails independently while unrelated authorized snapshot items continue.
- [x] Move the exact reviewed source to another folder without changing its message content, then
  apply the old suggestion; confirm it becomes stale and records no second provider operation. Put
  two cleanup suggestions for one source in a batch and confirm only the fixed first reservation can
  run.
- [x] Force a provider operation into Pending, Failed, Superseded, and Cancelled states and confirm
  both the immediate alert and the rerendered queue show the real non-green state.
- [x] Click `Always do this` for a personal mailbox and confirm the modal is merely prefilled and no
  rule/version/execution exists until the normal explicit form submission.
- [x] Click `Always do this` for a shared/system mailbox and confirm the Admin builder opens with the
  exact mailbox/condition/target, `is_active=0`, `stop_processing=1`, and the distinct provider
  action. Confirm the URL contains no sender, subject, rule name, or condition value, cannot be
  replayed after one use, and leaving the form creates nothing.
- [x] Explicitly save/publish one controlled provider cleanup rule, confirm current `published_by`
  and mailbox Organize are rechecked at execution, and confirm successful `stop_processing=1`
  prevents default Ticket routing for that matched message.
- [x] Force that provider action to fail and confirm later actions in the same rule are
  skipped/`not_run`, while other eligible rules continue according to normal precedence.
- [x] Confirm an existing legacy `archive` rule still changes only its historical local state and
  does not unexpectedly issue an IMAP Archive operation.

Expected result: Cleanup is always human-triggered, bounded, reversible where provider evidence
supports Undo, and honest about partial failure. `Always do this` is only a normal inactive prefill
until explicit save/publication; no permanent delete, read-state change, send, or hidden learning
occurs.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-013 - Email Mail Reviewed Smart Inbox Actions

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-ai-reviewed-conversation-actions.md`, and
`HR-2026-08-14-012`

Scope: A human may apply exactly three non-cleanup Smart Inbox effects: compare-and-set one existing
active Email category, add one existing active Taxonomy tag, or create an editable internal Task
through Task's guarded action and work-context rules. The suggestion row is locked, every click
rechecks source and provenance, and the applied classification or Task reference plus append-only
event provides end-to-end evidence. The existing AI-summary-assisted Mail-to-Ticket action remains a
separate reviewed workflow and is unchanged.

Affected pages / workflows: the selected-conversation Smart Inbox queue in `/tech/mail`, Smart Inbox
apply API behavior, Email conversation classification, Taxonomy tag assignment, and Task creation.

Out of scope / not reviewed here: creating Taxonomy definitions, replacing a different human
category, inventing Task assignee/due date, provider mutation, arbitrary Ticket change, automatic
apply, rule publication, send/reply, or direct AI tool/model writes.

Deploy / migration notes: This action uses the Smart Inbox structures from migration `114000` in
batch 92 and adds no later schema. Deploy code, run `php artisan optimize:clear`, compile views with
the required group-write umask, and sync Email Knowledge. No new queue, scheduler, permission seed,
or frontend build is required.

Risks: A suggestion tied to a formerly selected/default agent must not inherit a different agent's
authority. Wildcard scopes, stale/revoked state, changed classification, inactive targets, and
missing target-domain access must fail closed. Cross-domain Task creation must remain inside Task's
normal policy and must not duplicate on retry.

Automated verification: Focused reviewed-application coverage passes **7 tests / 79 assertions**.
Foundation, reviewed-apply, and review-queue coverage passes **21 / 252**; the full Smart Inbox set
with supervised cleanup passes **32 / 422**. The final application-wide Dev suite passes
**1,482 / 12,749**.

Manual checks:

- [x] Apply an existing active Email category to an unclassified conversation and confirm every
  visible placement in that account conversation shows it, the suggestion stores one classification
  reference, and a second click writes nothing new.
- [x] Assign a different category manually before applying a category suggestion and confirm Smart
  Inbox refuses to replace it.
- [x] Apply an existing active tag and confirm it is added without removing current category/tags and
  without creating a new Taxonomy definition.
- [x] Apply a Task suggestion and confirm one editable internal Task is created through the expected
  work context with no speculative assignee or due date; repeat apply and confirm no duplicate Task.
- [x] Disable the proposed category/tag, change the conversation fingerprint, dismiss the
  suggestion, or revoke mailbox access and confirm application fails without a target-domain write.
- [x] Disable action execution, remove the exact `email.update` or `tasks.create` scope from the
  recorded AI agent, or switch the current default Email agent and confirm the original suggestion
  does not inherit wildcard/fallback authority.
- [x] Through the API, confirm token scope is an additional ceiling (`tasks.create` for Task and
  `email.update` for apply) and an inaccessible account returns Not Found.
- [x] Inspect the suggestion events/reference and confirm they contain normalized metadata and IDs,
  not model prompts/responses, bodies, headers, attachment data, credentials, or secrets.

Expected result: Only category, tag, and Task effects can write, and only after a current explicit
human click through both the recorded AI-agent ceiling and normal domain authorization. Retries are
idempotent and no provider/outbound effect occurs.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-012 - Email Mail Durable Smart Inbox Foundation And Review Queue

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md` and
`docs/feature-slices/2026-08-14-email-mail-smart-inbox-suggestion-foundation.md`

Scope: A technician with current mailbox View access can explicitly analyze one selected durable
conversation. The governed Mail AI summary path produces normalized, typed, user/account/conversation
and source-fingerprint-bound suggestions plus append-only events. `/tech/mail` embeds a review queue
that shows status, effect impact, reason, confidence, provenance, and current actions. Account-scoped
REST endpoints provide queue/count/show/analyze/dismiss/correct with hidden-404 isolation. Analysis
alone changes no provider, Email classification, Ticket, Task, Taxonomy, rule, or outbound state.

Affected pages / workflows: `/tech/mail` selected-conversation review queue, manual Mail AI analysis,
Smart Inbox REST endpoints/counts, and durable suggestion/event storage.

Out of scope / not reviewed here: scheduled, arrival-triggered, unattended, or bulk AI generation;
applying category/tag/Task/cleanup effects; permanent delete; rule publication; raw model-payload
storage; and automatic external replies.

Deploy / migration notes: Migration
`2026_08_14_114000_create_email_smart_inbox_suggestions.php` ran in batch 92 after verified Undo
`113000` in batch 91 and before provider-deletion `115000` in batch 93. Reproducing that reviewed Dev
rollout requires exact-path staging because the Undo file is dated 2026-08-15; a fresh combined
migrate sorts the independent `114000` schema first and is schema-safe but follows a different
behavioral order. Run `php artisan optimize:clear`, compile views with the required group-write
umask, and sync Email Knowledge. No new scheduler, queue worker class, permission seed, or frontend
build is required for manual analysis/review.

Risks: Mail content may leave Nexum only through the already governed Integration path. Suggestions
must not leak inaccessible account existence, persist raw prompts/provider responses or attachment
names, outlive a changed source fingerprint as actionable, or become writable merely because a
token has a broad ability. Counts and events must remain user/account scoped. Revoking access while
an AI request is in flight must prevent its final persistence, and provider exception text must not
be returned to the browser or API.

Automated verification: Foundation coverage passes **10 tests / 106 assertions**, including
terminal-state access revocation, inactive-account isolation, post-AI authorization recheck, safe
provider-error messaging, and preservation of suggestion/event audit references during conversation
identity reconciliation. The combined Smart Inbox foundation, reviewed-apply, review-queue, and
cleanup set passes **32 / 422**, and the broader focused Mail workstream set passes **112 / 993**.

Manual checks:

- [x] With a governed Email agent and one authorized mailbox, select a conversation, click Analyze,
  and confirm the queue shows normalized reason, confidence, provenance, status, and clear impact
  labels only after the explicit request.
- [x] Before applying anything, confirm analysis created suggestion/event rows only and did not
  change category, tags, Tasks, Tickets, rules, provider folder/flags/Seen, personal unread, or send
  external mail.
- [x] Confirm a review-summary item is advisory and has no Apply control, while category/tag/Task or
  cleanup controls appear only for supported current proposal types.
- [x] Dismiss one suggestion twice and correct one editable proposal twice; confirm the operations
  are idempotent and append understandable immutable evidence without changing another user's row.
- [x] Add/change mail in the conversation and refresh the queue; confirm old source-bound suggestions
  become stale and cannot be applied.
- [x] Revoke mailbox View, disable the user/account, or remove the selected placement and confirm the
  queue hides/revokes the suggestion without disclosing content or an account-specific denial.
- [x] Repeat that access-revocation check for an already Applied and an already Dismissed suggestion;
  confirm direct API show also returns Not Found. Revoke the grant while an Analyze provider request
  is in flight and confirm no suggestion/event row is persisted when it returns.
- [x] Make the governed provider throw an error containing a distinctive secret-like value and
  confirm the UI/API returns only the fixed safe failure text and never the raw value.
- [x] Sign in as another authorized mailbox user and confirm they cannot list, count, show, dismiss,
  correct, or apply the first user's suggestions.
- [x] Exercise queue/count/show/analyze/dismiss/correct through API tokens and confirm `email.read` /
  `email.update` are ceilings intersected with current mailbox scope; inaccessible IDs return Not
  Found.
- [x] Inspect stored proposals, trace, and events and confirm there is no raw prompt/response, HTML,
  body, raw source, attachment name/content, address list, credential, or secret.

Expected result: Manual analysis produces only durable, typed, inspectable review evidence for the
current user/account/source. Stale or revoked rows fail closed, normal users cannot enumerate one
another's queue, and no business/provider write occurs until a separately reviewed explicit action.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-011 - Email Mail Verified Remote Operation Undo

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-verified-remote-operation-undo.md`, and
`HR-2026-08-14-010`

Scope: Recent provider-acknowledged Seen/Unseen, Flag/Unflag, Archive, Trash, and Move operations
capture immutable metadata-only result snapshots and may expose Undo for 15 minutes. Undo creates one
uniquely linked inverse ledger row. It rechecks the current actor/account authority, exact local
source/target placement and folder evidence, later operations, UID/UIDVALIDITY, sync version, flags,
and live provider state before every inverse write. Seen/flag inverses stay on the same placement;
acknowledged moves return the exact target placement to the still-selectable original folder. Every
inverse uses the existing remote-operation attempt, retry, and ambiguity-reconciliation contract.
The Mail workspace shows recent reason/Undo state, and account-scoped API clients have separate
eligibility and apply endpoints.

Out of scope / not reviewed here: permanent provider deletion, folder create/rename/move/delete
Undo, moves without an acknowledged target UID, ambiguous/reconciled source operations, bulk or
automatic Undo, and restoration of retention-purged data.

Deploy / migration notes: Migration
`2026_08_15_113000_add_verified_email_remote_operation_undo.php` ran on Dev in batch 91, explicitly
before the 114000 Smart Inbox migration in batch 92 and 115000 provider-deletion migration in batch
93. It adds a nullable self-link, an immutable result snapshot plus verification audit fields, a
unique inverse constraint, and a bounded recent-operation index. Existing successful rows are deliberately not
backfilled and therefore cannot be undone. Deploy must run migrations, `php artisan optimize:clear`,
`php artisan view:cache`, and
`php artisan knowledge:sync-docs --module=Email --push`. No new permission seed, frontend build,
scheduler entry, or queue class is required; existing `email.read`, `email.update`, mailbox View,
mailbox Organize, and the recovery `email` queue worker are reused.

Risks: Provider verification and the inverse mutation cannot be one atomic IMAP transaction, so a
provider-side change in that narrow interval can still yield an ambiguous result. The normal
reconciliation contract must prove the inverse result before any retry and must never replay it
blindly. A conservative false-stale result may suppress a legitimate Undo, but missing/mismatched
folder, UID, UIDVALIDITY, version, target UID, provider flag, account, access, or later-operation
evidence must fail closed. Result and attempt evidence must remain account-scoped and content-free.

Automated verification: Focused Undo coverage passes with 12 tests and 75 assertions. Combined
recovery/Undo coverage passes with 22 tests and 126 assertions, and the existing provider
operation/UI/API regression set passes with 6 tests and 52 assertions. The exact migration SQL was
previewed on MariaDB, then ran in batch 91 with the expected short unique/recent index names. Dev
verification confirmed 22 historical successes remain conservatively without a result snapshot and
no inverse rows were introduced. PHP syntax, Pint, six route registrations, Blade compilation,
and final diff checks pass. Email Knowledge synchronized one chapter/article with no skips, and the
BookStack push was queued. Compiled views remain group-writable.

Manual checks:

- [x] On a safe test message, Flag or mark it Seen, open Mailbox operations, and confirm the recent
  row shows an understandable verified reason and Undo expiry.
- [x] Click Undo once and confirm the provider flag, Nexum placement, conversation provider-unread
  count where applicable, linked inverse status, and immutable attempt evidence update exactly once.
- [x] Click Undo again and confirm it returns/shows the same inverse operation without another
  provider mutation.
- [x] Move a safe test message to a discovered folder, Undo it, and confirm only the exact
  acknowledged target placement moves back to the original selectable folder.
- [x] Change a test placement, provider flag, folder, UID/UIDVALIDITY, or create a later operation
  before Undo; confirm the reason changes and no inverse provider write occurs.
- [x] Revoke the actor's Organize grant or disable the mailbox before applying/retrying Undo and
  confirm it is blocked without provider mutation.
- [x] Confirm permanent delete, custom folder mutations, reconciled/ambiguous work, and moves without
  an exact target UID never expose Undo.
- [x] Verify API account isolation: an inaccessible operation returns Not Found, a View-only caller
  can read an ineligible authorization reason but cannot POST, and an authorized `email.update`
  caller gets the same linked inverse as the UI.
- [x] Wait beyond 15 minutes on a fresh success and confirm no new Undo can be started; confirm an
  inverse already created inside the window can still complete through safe recovery.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-010 - Email Mail Remote Operation Recovery

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-remote-operation-recovery.md`,
`HR-2026-08-13-030`, `HR-2026-08-12-007`, and `HR-2026-08-15-003`

Scope: Provider mailbox writes now snapshot placement/folder identity and reauthorize the original
requester at execution time. Stale or revoked work is superseded before IMAP is touched. Each
provider mutation or ambiguity-reconciliation attempt records sanitized append-only evidence.
Transient failures use bounded exponential backoff with a five-attempt ceiling; stale running work
becomes ambiguous and is reconciled before any replay. The Mailbox operations dashboard shows
attempts, reason, classification, and next retry, while Livewire and the Email API share row-locked
retry/cancel actions. Provider Seen/Unseen acknowledgement now refreshes the durable conversation's
provider-unread aggregate.

The 2026-08-15 hardening first checks exact UID presence without fetching headers, makes confirmed
source absence terminal stale work instead of exposing `no headers found`, keeps true provider read
failures out of automatic retry loops, and resolves Archive/Trash only from SPECIAL-USE or an exact
canonical leaf. The operations surface now starts collapsed in the Mail right bar.

Out of scope / not reviewed here: verified inverse/undo, permanent provider deletion, bulk
retry/cancel, automatic external sending, a new provider adapter, or a provider push transport.

Deploy / migration notes: Migration
`2026_08_14_112000_harden_email_remote_operation_recovery.php` ran on Dev in batch 89 and the
forward-only legacy ambiguity quarantine
`2026_08_14_112500_complete_email_remote_operation_recovery.php` ran in batch 90. Deploy must run
migrations, `php artisan optimize:clear`, `php artisan view:cache`, restart the ordinary queue
workers including the `email` queue, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. The new
`email.remote_operations.retry_due` schedule runs every minute; verify the external scheduler runner
and queue worker instead of relying on `schedule:list` alone. No permission seed or frontend build is
required because existing `email.read`, `email.update`, mailbox View, and mailbox Organize boundaries
are reused.

Risks: A false stale match could suppress legitimate work, while an over-broad reconciliation could
repeat a provider mutation. The slice therefore fails closed on missing identity evidence and leaves
unprovable moves/renames blocked. Automatic recovery depends on both scheduler and queue health.
Attempt evidence contains folder/UID operational metadata and must remain account scoped. A running
provider call cannot be cancelled mid-flight; it is recovered as ambiguous if its worker disappears.

Automated verification: Focused recovery coverage passes with 16 tests and 102 assertions, including
immutable/sanitized evidence, bounded retries, maximum attempts, stale evidence, revoked authority,
due execution, cancellation races/idempotency, ambiguity reconciliation/no duplicate mutation,
account isolation, positive API parity, durable conversation unread refresh, authoritative move
target evidence, provider-folder inventory failure, exact missing-source handling, deterministic
special-folder targeting, and mutation-only attempt accounting. Adjacent verified Undo passes 12 /
75 and supervised Smart Inbox cleanup passes 11 / 170. PHP syntax, Pint, route, scheduler, Blade, and
broader Email verification are recorded in the final workstream handoff.

Manual checks:

- [x] Create a safe failed Seen/Unseen operation and confirm `/tech/mail` shows its reason,
  classification, provider-attempt count, evidence count, and next retry time.
- [x] Retry that operation and confirm provider state and Nexum placement/conversation unread count
  reconcile once without a duplicate provider mutation.
- [x] Remove a disposable source UID externally, then exercise the safe operation/retry path and
  confirm it stops as stale with no provider write, no automatic retry, and no raw `no headers
  found` text. Confirm a true provider read failure is not treated as confirmed absence.
- [x] Cancel a pending/failed test operation and confirm repeated Cancel is harmless; confirm a
  running operation cannot be cancelled mid-call.
- [x] Change a test placement UID, UIDVALIDITY, sync version, or folder before retry and confirm the
  operation becomes Superseded without changing provider state.
- [x] Revoke the original requester's Organize grant (or disable the account), then retry and confirm
  the operation becomes Superseded without provider access even if another operator has Organize.
- [x] Simulate an ambiguous applied Seen/Flag change and confirm reconciliation marks it succeeded
  without issuing the provider mutation again.
- [x] Simulate an ambiguous move without target UID evidence and confirm it stays blocked with no
  blind replay and exposes no manual Retry. Repeat with missing target-folder path.
- [x] Simulate an ambiguous move with the source UID still present and a copied target UID also
  present; confirm it stays ambiguous without replay. Make target-folder inventory fail and confirm
  the provider error is not interpreted as an absent folder/message.
- [x] Call list/show/retry/cancel with API tokens and confirm `email.read`/`email.update` plus mailbox
  View/Organize are intersected; inaccessible mailbox operations must return Not Found.
- [x] Verify the external scheduler and `email` queue worker process
  `email.remote_operations.retry_due`, and confirm retries stop after five provider mutation attempts.
- [x] Inspect completed `email_remote_operation_attempts` and confirm evidence contains no subject,
  addresses, body, MIME, attachment data, raw content, credential, token, or secret.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-009 - Email Mail Fail-Safe Retention Purge And Preview

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md` and
`docs/feature-slices/2026-08-14-email-mail-fail-safe-retention-purge.md`

Scope: The monthly local-cache cleanup now uses one fail-closed eligibility service shared with a
read-only Email Admin preview. Age alone can never delete provider-backed mail. Provider placements,
unresolved provider operations/reconciliation, Ticket links or captured evidence, recognized legal
holds, ambiguity review, and unsupported attachment storage protect the message. Only an expired,
definitively unplaced, unprotected orphan can lose its local raw/attachment files and Email row. Each
run and per-message outcome is retained in a sanitized audit ledger, and a storage failure preserves
database evidence for a later retry.

Out of scope / not reviewed here: provider-side Trash/expunge, a manual purge button, provider-folder
inventory confirmation and placement cleanup, legal-hold authoring/release, DSAR/export/erasure,
account offboarding, backup expiry enforcement, search/AI artifact stores that do not yet exist, or
deletion of Ticket-owned evidence.

Deploy / migration notes: Run
`2026_08_14_111000_add_email_retention_purge_audit.php`, then run
`php artisan optimize:clear`, `php artisan view:cache`, restart the ordinary queue workers, and sync
Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`. The existing monthly
`email.retention.purge` scheduler entry remains the execution trigger; verify the external scheduler
runner and the queue worker rather than relying on `schedule:list` alone.

Risks: A protection query must fail closed; the first live scheduled run should be checked for
unexpected eligible counts before treating deletion as operationally accepted. Deleting local files
cannot be rolled back from the application, while a mid-delete storage/database failure can leave a
database record whose earlier file was already removed; the next run handles missing files
idempotently and retains the failed attempt. Full hold/DSAR and provider-deletion confirmation are
still required before broader lifecycle claims.

Automated verification: PHP syntax checks pass for the eligibility service, purge job, audit models,
controller, migration, and focused test file. Focused retention verification passes with 6 tests and
31 assertions, covering active provider mail, Ticket evidence, pending/failed provider operations,
eligible orphan cleanup, storage-failure retry/idempotency, and the Admin preview.

Manual checks:

- [x] Run the migration and open `/tech/admin/settings/email/config` as an Email account
  administrator.
- [x] Confirm **Retention preview** shows the configured cutoff, expired count, eligible orphan
  count, protected count, and readable reason breakdown, with no manual purge button.
- [x] Create or identify an expired test message with an active provider placement and confirm the
  preview protects it and the scheduled job leaves its local message/files unchanged.
- [x] Confirm an expired source captured in Ticket stays protected and the Ticket message/attachment
  remains unchanged.
- [x] Confirm pending and failed provider operations appear as protection reasons and are not
  deleted.
- [x] In a non-production test account, create one expired orphan with no placement/protection, run
  the scheduled job, and confirm only its local raw/attachment files and Email row are removed.
- [x] Simulate an unreadable or undeletable local payload and confirm the purge attempt is `failed`,
  the Email row remains, no content/path appears in the audit, and a later run can finish safely.
- [x] Inspect the latest `email_retention_purge_runs` counts and per-message reason/failure codes and
  confirm no subject, address, body, attachment filename, raw path, or provider secret is stored.
- [x] Confirm Ticket-owned evidence, provider state, unread state, rules, and external mail are not
  changed by the retention run.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-008 - Email Mail Conversation Taxonomy Classification

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-conversation-taxonomy-classification.md`,
`HR-2026-08-14-007`, and `HR-2026-08-14-006`

Scope: Nexum Email category and tag assignment now belongs to the durable account-scoped
conversation. Every authorized placement in that mailbox conversation shows the same one active
Email category and set of Taxonomy tags, while a correlated conversation in another mailbox remains
independent. The forward migration promotes only one unambiguous, identical temporary
message-classification snapshot and preserves source history; conflicts receive durable issue
evidence. Livewire, API, and explicit rule actions use the same conversation boundary. Legacy
`tag`/`tag_message` remain message-scoped.

Affected pages / workflows: `/tech/mail` conversation list/reader category and tag editor,
conversation classification API read/replace/clear, Email-rule actions, and the legacy
message-classification forward migration.

Out of scope / not reviewed here: provider flags/folders/labels/keywords, Ticket classification,
bulk or cross-account classification, automatic Ticket routing, removing the legacy message tables,
and automatic external replies.

Deploy / migration notes: Migration
`2026_08_14_110000_create_email_conversation_classifications.php` ran in batch 87 immediately after
identity hardening `105000` in batch 86. It uses explicit MariaDB-safe short index names and
`datetime` issue fields. Dev had zero temporary message classifications, so it produced zero
migrated assignments and zero issues while all 462 placements remained conversation-linked. Deploy
must preserve `105000` then `110000`, run `php artisan optimize:clear`, compile views with the
required group-write umask, and sync Email Knowledge. No new queue, scheduler, permission seed, or
frontend build is required.

Risks: Classification must never cross a mailbox privacy boundary or be confused with provider/Ticket
state. The migration must not guess between conflicting message snapshots, explicit rule naming must
not change legacy routing semantics, and users with View but not Organize must remain read-only.

Automated verification: Focused classification/API/rule and migration coverage passes **11 tests /
90 assertions**. Inbound Automation passes **14 / 81**. The broad Email, cache/view, Knowledge-push,
and queue verification remains in the final parent handoff and is not claimed here.

Manual checks:

- [x] In `/tech/mail`, assign one active Email category and several existing tags from one message,
  then open another placement in the same account conversation and confirm the same classification
  appears in the list and reader.
- [x] Open a correlated conversation in another mailbox account and confirm its category/tags remain
  independent and no count, chip, or suggestion leaks across accounts.
- [x] Replace and clear the classification and confirm one immutable before/after event per action,
  without changing provider flags, folder, Seen, personal unread, Ticket category/tags, or `TD-...`
  correlation.
- [x] As a View-only mailbox user, confirm classification is readable but no mutation control or
  direct write succeeds. With View plus Organize, confirm assignment succeeds.
- [x] Enter an unknown tag without `taxonomy.manage_tags` and confirm no definition is created; grant
  that permission and confirm the normal Taxonomy creation boundary is used.
- [x] Exercise API read/replace/clear with correct and missing `email.read`/`email.update` token
  abilities, and confirm inaccessible mailbox conversations return Not Found.
- [x] Run controlled legacy `tag`, explicit `tag_message`, `tag_conversation`, and
  `set_conversation_category` rules and confirm their message-versus-conversation meanings remain
  distinct.
- [x] Inspect the migration issue tables and source compatibility records; confirm legacy rows/events
  were preserved, no message-level routing tag became a conversation/Ticket tag, and no ambiguous
  source was guessed.

Expected result: Classification is consistent within one durable account conversation, isolated
across accounts, guarded by current View/Organize and Taxonomy permissions, and additive to legacy
provider, Ticket, routing, and audit state.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-007 - Email Mail Conversation Identity Hardening

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-conversation-identity-hardening.md`, and
`HR-2026-08-14-006`

Scope: Durable account-local conversation correlation now resolves nested replies through uniquely
matched `References` / `In-Reply-To` evidence, keeps a stable root, and refuses to merge conflicting
same-account messages merely because they reuse a `Message-ID`. The forward reconciler moved only
unambiguous split placements, refreshes aggregates, and retains durable issue evidence instead of
guessing when referenced conversations or Ticket primaries conflict.

Affected pages / workflows: conversation grouping and reader selection in `/tech/mail`, inbound
conversation projection, placement-to-conversation pointers, aggregate counts, and compatible
Mail-owned Ticket conversation pointers.

Out of scope / not reviewed here: cross-account merging, subject-only grouping, canonical-message or
Ticket-evidence rewrites, provider/read/folder mutations, classification, and automatic routing of
later mail to Tickets.

Deploy / migration notes: Migration
`2026_08_14_105000_harden_email_conversation_identity.php` ran in batch 86 before classification
`110000` in batch 87. It reduced 141 Dev conversation projections to 139 by moving two known
unambiguous split placements and deleting only their empty unreferenced shells. All 462 placements
remain linked; there are no empty conversations and current Dev required no ambiguity issue. Deploy
must run `105000` before every later Mail workstream migration, then run
`php artisan optimize:clear`, compile views with the required group-write umask, and sync Email
Knowledge. No provider command, queue change, permission seed, scheduler change, or frontend build
is required by this migration.

Risks: A false merge can disclose unrelated mail or redirect a Ticket relationship, while a false
split can fragment reader state and later classification. Reused/malformed identifiers, several
referenced conversations, and competing primary Ticket relationships must remain separate and
reviewable.

Automated verification: The focused identity suite passes **7 tests / 50 assertions**. Combined
conversation-classification coverage passes **11 / 90**, and Inbound Automation passes **14 / 81**.
The broader focused Mail workstream set passes **112 / 993**.

Manual checks:

- [x] Open or ingest a safe root message, direct reply, and nested reply whose `References` contains
  the root-to-parent chain; confirm `/tech/mail` shows one account conversation and selecting each
  thread row keeps actions bound to that exact placement.
- [x] Create two incompatible same-account messages that reuse one `Message-ID` and confirm they
  remain separate conversations with no shared counts, Ticket pointer, or classification.
- [x] Place the same identifier chain in two mailbox accounts and confirm the conversations, counts,
  snippets, and actions remain account-isolated.
- [x] Inspect a reconciled formerly split thread and confirm the message/active-placement/provider
  unread/attachment aggregates match its real placements and no empty shell remains.
- [x] Create or inspect conflicting references and competing primary Ticket-link evidence and
  confirm no placement or primary relationship moves; a sanitized durable issue is recorded instead.
- [x] Confirm provider folder, UID/UIDVALIDITY, Seen/flag, personal unread, message body/attachments,
  existing `TD-...` correlation, and Ticket evidence were not changed by reconciliation.
- [x] With a durable Smart Inbox suggestion/event referencing a source conversation, reconcile its
  last placement into an unambiguous target and confirm the old projection shell plus suggestion and
  append-only events remain as audit evidence; the suggestion itself may become stale normally.
- [x] As users with different mailbox grants, confirm grouping never reveals an inaccessible
  account, message, participant, snippet, count, or Ticket relationship.

Expected result: Only unambiguous account-local RFC evidence joins or reconciles conversations.
Ambiguous/reused identifiers fail closed, all provider and Ticket evidence stays intact, and the
reader remains scoped to the exact selected placement.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-006 - Email Mail Durable Account-Scoped Conversations

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-durable-account-conversations.md`,
`HR-2026-08-14-002`, `HR-2026-08-14-003`, and `HR-2026-08-13-029`

Scope: Email now has a durable `email_conversations` projection for account-scoped mail threads.
Mailbox placements and Ticket conversation links can point to that conversation, inbound storage
projects new placements into it, provider move projection keeps the moved placement attached, and
`/tech/mail` uses the durable conversation ID for grouping/reader context when available while
retaining the previous conservative header-key fallback.

Out of scope / not reviewed here: conversation-scoped category/tag migration, provider read/flag
authority changes, cross-account conversation merging, automatic conversation-wide read actions,
Ticket auto-routing for later arrivals, automatic replies, or removal of existing `TD-...`
correlation and scalar `email_messages.ticket_id` compatibility.

Deploy / migration notes: Run migration
`2026_08_14_100000_create_email_conversations_table.php`, then run `php artisan optimize:clear`,
`php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. Existing queue workers should be restarted
after deploy so inbound polling and remote operations use the new projector code.

Risks: migration must backfill existing placements without merging mailboxes that share the same
`Message-ID`. The Mail UI must keep selected-placement actions scoped to the opened provider
placement. Ticket links must gain a durable pointer without weakening legacy Ticket-key correlation
or broadening Mail access from Ticket access.

Automated verification: PHP syntax checks pass for the new model, projector, migration, updated
models, Mail workspace, folder projector, remote operation runner, Ticket link action, and Email
feature test file. Focused durable conversation regressions pass with 4 tests and 36 assertions.
Full `EmailModuleTest.php` passes with 139 tests and 1176 assertions. `InboundAutomationTest.php`
passes with 14 tests and 81 assertions. The first Dev migration attempt exposed a wrong attachment
backfill column assumption; the migration was corrected to use the existing
`email_attachments.message_id`, rerun successfully, and migration status now shows
`2026_08_14_100000_create_email_conversations_table` as batch 85 `Ran`. Sanitized Dev backfill
verification found 141 conversations, 462 of 462 mailbox placements linked to a conversation, and
zero existing Ticket conversation links to backfill. `php artisan optimize:clear`, `php artisan
view:cache`, Email Knowledge sync with BookStack push queueing, and failed-job checks pass. A
`FetchImapAccount` failed-job with `MaxAttemptsExceeded` was retried and processed; the final
`queue:failed` check reports no failed jobs. `git diff --check` reports only pre-existing CRLF
working-copy warnings in unrelated files.

Manual checks:

- [x] After migration, open `/tech/mail` and confirm existing mail still lists as conversations.
- [x] Open a root/reply thread and confirm both messages are in one Conversation reader thread.
- [x] Confirm the same `Message-ID` in two different mailbox accounts remains two separate
  conversation rows when both accounts are visible.
- [x] Move one message to another folder and confirm it remains in the same reader thread from the
  new folder placement.
- [x] Link two messages from one thread to a Ticket and confirm the Ticket link count still appears
  without changing the old `TD-...` behavior.
- [x] Confirm Category/tags still behave as before and were not silently migrated in this slice.
- [x] Confirm unread/read, flag, trash, move, spam, Ticket, AI, and rule actions still apply only to
  the selected placement.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-005 - Email Mail Composer Local Status Polish

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-composer-local-status-polish.md`,
`HR-2026-08-14-004`, `HR-2026-08-13-024`, and `HR-2026-08-13-026`

Scope: `/tech/mail` now renders composer-specific AI and draft feedback inside the shared composer
instead of the page-level Mail alert while the composer remains open. This includes AI apply
success, AI no-reply advice, composer AI availability/governance warnings, manual draft
save/restore/provider Drafts sync status, and draft attachment removal. Send/discard completion and
non-composer actions still use the page-level Mail alert because those actions either close the
composer or are not tied to the editor.

Out of scope / not reviewed here: AI prompt behavior, provider/model governance, agent selection,
SMTP send behavior, IMAP polling, provider Drafts semantics, provider Sent reconciliation, automatic
sending, AI write tools, shared draft locking, or a reusable cross-module editor.

Deploy / migration notes: No migration, permission seed, scheduler change, frontend build,
OAuth/provider configuration change, queue restart, or IMAP account reconfiguration is required.
Deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email
Knowledge with `php artisan knowledge:sync-docs --module=Email --push`.

Risks: composer-local errors must remain visible and not disappear behind the global alert boundary.
Global send/discard feedback must still appear after the composer closes. Long AI or provider Drafts
messages must wrap without covering the editor toolbar, fields, attachment controls, or Send/Close
buttons.

Automated verification: PHP syntax checks pass for the Mail workspace Livewire component, Mail
workspace Blade view, shared composer Blade partial, and Email feature test file. Focused composer
draft/AI status regressions pass with 8 tests and 113 assertions. The AI-governance unavailable
composer-status regression passes with 1 test and 6 assertions. Full `EmailModuleTest.php` passes
with 138 tests and 1163 assertions. `php artisan optimize:clear`, `php artisan view:cache`, Email
Knowledge sync with BookStack push queueing, one default queue worker pass, and failed-job checks
pass. `git diff --check` reports only pre-existing CRLF working-copy warnings in unrelated files.

Manual checks:

- [x] Open Compose, type body text, use an AI rewrite, and confirm the success message appears inside
  the composer while the page-level Mail alert stays absent.
- [x] Open Reply on an automated/no-reply style message, use Draft reply, and confirm the no-reply
  advisory appears inside the composer without replacing the body.
- [x] Temporarily use an unavailable/governance-denied Mail AI setup from an open composer and
  confirm the warning appears inside the composer, not as a top Mail alert.
- [x] Save a draft manually and confirm Draft saved/provider Drafts status appears inside the
  composer.
- [x] Restore an existing draft and confirm the restored status appears inside the composer.
- [x] Send and discard messages and confirm those completion messages still appear at page level
  after the composer closes.
- [x] Check desktop and mobile widths and confirm long composer status text wraps without overlapping
  toolbar buttons, editor content, attachments, or Send/Close controls.

Reviewer: Svein Tore
Reviewed date: 2026-08-14
Result / notes: Approved by Svein Tore in the Codex task after reviewing the completed work.

### HR-2026-08-14-004 - Email Mail Composer AI Consistency

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-composer-ai-consistency.md`,
`HR-2026-08-12-017`, and `HR-2026-08-14-003`

Scope: `/tech/mail` now exposes the same Mail AI rewrite controls in the shared composer for
Compose, Reply, Reply All, and Forward when the selected/default Email agent is ready under
Integration policy. Draft reply remains visible and callable only for Reply and Reply All. Compose
uses the selected send-authorized account without requiring a selected source message. Forward
rewrites only the technician-authored introduction and preserves the original forwarded-message block
when applying AI output.

Out of scope / not reviewed here: automatic external sending, AI recipient changes, AI subject
changes, attachment changes, provider mutations, Ticket/Task/rule/Taxonomy writes, imported provider
draft AI assistance, a new shared content-editor platform, or replacing the current HTML editor.

Deploy / migration notes: No migration, permission seed, scheduler change, frontend build,
OAuth/provider configuration change, queue restart, or IMAP account reconfiguration is required.
Deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email
Knowledge with `php artisan knowledge:sync-docs --module=Email --push`.

Risks: Compose AI must not require mailbox View or leak a mailbox message because no selected source
exists. Forward AI must not remove or rewrite the original forwarded block. Draft reply must not
appear in Compose or Forward, because that would imply a selected inbound message or reply context.
All modes must leave recipients, subject, attachments, provider state, Tickets, Tasks, rules, and
Taxonomy unchanged until the technician sends or uses a separate explicit action.

Automated verification: PHP syntax checks pass for the Mail AI action, Mail workspace Livewire
component, shared composer Blade partial, and Email feature test file. Focused Compose/Forward AI
regressions pass with 2 tests and 22 assertions. Existing Reply AI regressions pass with 4 tests and
37 assertions. Full `EmailModuleTest.php` passes with 138 tests and 1142 assertions. `php artisan
optimize:clear`, `php artisan view:cache`, Email Knowledge sync with BookStack push queueing, one
default queue worker pass, and failed-job check pass. `git diff --check` reports only pre-existing
CRLF working-copy warnings in unrelated files.

Manual checks:

- [x] Open Compose in `/tech/mail` with a send-authorized account and a ready Email agent; confirm AI
  guidance plus improve/shorten/warmer/NO controls are visible, and Draft reply is not visible.
- [x] Use one Compose AI rewrite and confirm it updates only the body while To, Cc, Subject,
  attachments, and sender stay unchanged.
- [x] Open Reply or Reply All and confirm Draft reply plus the rewrite controls are visible.
- [x] Use Draft reply on an automated/no-reply style message and confirm advisory no-reply output
  does not replace the composer body.
- [x] Open Forward and confirm rewrite controls are visible, Draft reply is not visible, and the
  forwarded original message block remains after using an AI rewrite.
- [x] Confirm AI rewrite does not create Tickets, Tasks, rules, categories/tags, provider operations,
  or outbound Email logs before Send is clicked.
- [x] Check desktop and mobile widths and confirm the AI instruction input and icon buttons wrap
  without overlapping the editor toolbar.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-003 - Email Mail Conversation Reader Polish

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-conversation-reader-polish.md`,
`HR-2026-08-14-002`, and `HR-2026-08-13-029`

Scope: `/tech/mail` now renders the selected account-scoped conversation as one compact threaded
reader. The selected provider placement is expanded with body, attachments, AI summary, and
selected-message metadata. Other visible messages in the same thread are collapsed rows. Clicking a
collapsed row selects that provider placement and the command bar continues to act on only the
selected placement.

Out of scope / not reviewed here: durable account-scoped conversation tables or migration,
cross-account conversation merging, bulk conversation actions, automatic conversation-wide read
state changes, Ticket capture changes, `TD-...` Ticket-key correlation changes, provider folder
source-of-truth changes, or IMAP synchronization changes.

Deploy / migration notes: No migration, permission seed, scheduler change, frontend build,
OAuth/provider configuration change, queue restart, or IMAP account reconfiguration is required.
Deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email
Knowledge with `php artisan knowledge:sync-docs --module=Email --push`.

Risks: the reader must make the active message clear so technicians do not reply to or move the wrong
placement. Collapsed rows must not render body or attachment content until selected. Actions must
remain selected-placement actions, not silent conversation-wide actions.

Automated verification: PHP syntax checks pass for the Mail workspace Blade file and Email feature
test file. Focused conversation reader/list regressions pass with 3 tests and 23 assertions. Full
`EmailModuleTest.php` passes with 136 tests and 1120 assertions. `php artisan optimize:clear`,
`php artisan view:cache`, Email Knowledge sync with BookStack push queueing, one default queue worker
pass, and failed-job check pass. `git diff --check` reports only pre-existing CRLF working-copy
warnings in unrelated files.

Manual checks:

- [x] Open `/tech/mail` with a mailbox containing a root message and a reply in the same RFC thread;
  confirm the reader shows a compact Conversation thread.
- [x] Confirm only the selected message is expanded with body and attachment metadata; the other
  thread rows remain collapsed.
- [x] Click an older collapsed row and confirm it becomes the expanded message.
- [x] Confirm Reply, Reply all, Forward, Mark read, Trash, Move, Ticket, Category/tags, Add rule, and
  AI actions apply to the currently expanded placement only.
- [x] Confirm read/unread, flagged, provider draft, Ticket link, and Sent reconciled badges remain
  readable without crowding the row.
- [x] Check desktop and mobile widths and confirm long subjects, senders, folders, and Message-ID
  text truncate/wrap without overlapping actions or body content.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-002 - Email Mail Conversation List Grouping

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-conversation-list-grouping.md`,
`HR-2026-08-12-006`, and `HR-2026-08-13-029`

Scope: `/tech/mail` now renders the message list as account-scoped conversation rows. The current
mailbox/folder/search/filter query is applied first, then matching placements are grouped by the
existing conservative Email conversation key prefixed with `account_id`. The newest matching
placement becomes the visible row, grouped rows show message count plus aggregate personal and
provider-unread badges, and selecting the row still opens one real provider placement. The threaded
reader behavior is tracked separately in `HR-2026-08-14-003`.

Out of scope / not reviewed here: new durable conversation tables, backfill/migration, cross-account
conversation merging, subject-only merge expansion, changing `TD-...` Ticket-key correlation,
changing Ticket link behavior, or changing provider folder/source-of-truth rules.

Deploy / migration notes: No migration, permission seed, scheduler change, frontend build,
OAuth/provider configuration change, queue restart, or IMAP account reconfiguration is required.
Deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email
Knowledge with `php artisan knowledge:sync-docs --module=Email --push`.

Risks: grouping must not broaden mailbox visibility or merge copies from different accounts. The
row is a view projection only; selected actions still operate on the opened provider placement, not
on every message in the conversation. The current implementation groups the filtered result set in
PHP while the durable account-scoped conversation model remains a later migration slice.

Automated verification: PHP syntax checks pass for the Mail workspace and Email feature test file.
Focused conversation list regressions pass with 2 tests and 11 assertions. Full
`EmailModuleTest.php` passes with 136 tests and 1115 assertions. `php artisan optimize:clear`,
`php artisan view:cache`, Email Knowledge sync with BookStack push queueing, one default queue worker
pass, and failed-job check pass. `git diff --check` reports only pre-existing CRLF working-copy
warnings in unrelated files.

Manual checks:

- [x] Open `/tech/mail` with a mailbox containing a root message and a reply in the same RFC thread;
  confirm the message list shows one conversation row with a multi-message badge.
- [x] Open that conversation row and confirm the reading pane opens one real provider placement from
  the grouped row; detailed threaded reader behavior is reviewed in `HR-2026-08-14-003`.
- [x] Confirm the row unread badges match the thread's personal unread and mailbox unread state in
  the current filtered scope.
- [x] Switch between `Unread`, `Inbox`, a selected folder, and search/filter states; confirm grouping
  remains inside the current authorized scope.
- [x] Confirm messages with the same Message-ID or copied content in another authorized mailbox stay
  separate rows.
- [x] Confirm Reply, Forward, Mark read, Trash, Move, Ticket, Category/tags, and AI actions still
  operate on the selected/opened placement, not silently on every message in the grouped row.
- [x] Check desktop and mobile widths and confirm conversation badges do not overlap sender, subject,
  or date text.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-14-001 - Email Mail Manual Send/Receive And Folder Refresh

Status: Reviewed
Added: 2026-08-14
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-14-email-mail-manual-send-receive-refresh.md`,
`HR-2026-08-12-003`, `HR-2026-08-12-006`, and `HR-2026-08-13-032`

Scope: `/tech/mail` now shows a compact `Send/receive` button in the Mail message-list header for
users who can organize at least one active mailbox. The action queues the existing
`FetchImapAccount` job for each active organize-authorized mailbox and never runs IMAP inside the
Livewire request. When a folder is selected and the user can organize that folder's mailbox, the
Folders header shows a refresh icon. The folder refresh action queues the same fetch job for the
selected folder's account so the normal provider folder discovery path can notice external folder
renames, creates, deletes, and new mail.
Reply, Reply All, Forward, Compose, and provider-draft editing continue to share the same composer
partial and Mail AI toolbar where AI drafting is allowed.

Out of scope / not reviewed here: synchronous browser IMAP polling, historical import, UID
re-baseline, a separate send outbox, folder-only polling that skips account discovery, and grouped
message-list conversation rows.

Deploy / migration notes: No migration, permission seed, scheduler change, frontend build,
OAuth/provider configuration change, or IMAP account reconfiguration is required. Deploy the code,
run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. Queue workers must be running for queued
fetches to complete.

Risks: The buttons must stay hidden for view-only mailboxes and direct Livewire calls must still
reject them. Manual refresh intentionally uses account-wide provider discovery; it must not import
historical unread mail or bypass folder UIDVALIDITY safety. Clicking repeatedly may queue duplicate
fetch jobs, but message import remains idempotent by provider UID identity and the fetch job uses
account-level overlap protection.

Automated verification: PHP syntax checks pass for the Mail workspace, Mail sidebar component, and
Email feature test file. Focused manual sync regressions pass with 3 tests and 14 assertions. Full
`EmailModuleTest.php` passes with 136 tests and 1116 assertions after moving `Send/receive` from the
sidebar to the message-list header. `php artisan optimize:clear`,
`php artisan view:cache`, Email Knowledge sync with BookStack push queueing, one default queue worker
pass, and failed-job check pass. `git diff --check` reports only pre-existing CRLF working-copy
warnings in unrelated files. The cache, Knowledge, queue, failed-job, and diff checks were rerun
successfully after the header move.

Manual checks:

- [x] Open `/tech/mail` as a user with mailbox View and Organize access and confirm the message-list
  header shows `Send/receive`.
- [x] Click `Send/receive`, confirm a status message appears, and confirm the queue receives or
  processes `FetchImapAccount` for the user's organize-authorized active mailboxes.
- [x] Select a folder in a mailbox the user can organize and confirm the Folders header shows the
  refresh icon.
- [x] Rename or create a safe test folder in another IMAP client, click the folder refresh icon, let
  the queue process, and confirm the provider folder change appears in Nexum.
- [x] Send one safe test email to the mailbox, click `Send/receive` or folder refresh, let the queue
  process, and confirm the new message appears without importing older historical mail.
- [x] Use a View-only mailbox grant and confirm `Send/receive` and selected-folder refresh are hidden
  or rejected.
- [x] Confirm Reply, Reply All, Forward, Compose, provider-draft editing, and Mail AI composer
  controls still use the same composer behavior after the sidebar refresh buttons were added.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-032 - Email Mail Provider Folder Create, Rename, Move, And Delete

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-provider-folder-rename-delete.md`,
`HR-2026-08-12-003`, `HR-2026-08-12-014`, and `HR-2026-08-13-025`

Scope: `/tech/mail` now shows a gear button on the right side of the Folders header when the
technician can organize at least one mailbox. The gear opens a mailbox-scoped folder manager modal
for the selected mailbox/folder context when available, otherwise the first organize-authorized
mailbox; if multiple mailboxes can be organized, the modal header includes a mailbox selector. The
folder manager can create, inspect as an expandable tree, rename, and delete custom provider folders
through IMAP-backed remote operations. Parent folders are collapsed by default, but can be expanded
to show subfolders. Custom folders can be created at mailbox root or inside an existing parent
folder, and safe custom leaf folders can be moved to root or another parent folder.
System/special-use folders,
folders with child folders, folders with pending operations, and rule-referenced folders are blocked
from unsafe mutation and show the blocker reason when no actions are available. Custom folders
containing mail must be emptied first with the modal's same-account move action before delete becomes
available. Folder creation keeps the folder manager open and expands the new folder's parent path
instead of switching the message list to the created folder.

Out of scope / not reviewed here: system folder rename/move/delete, recursive folder operations,
deleting provider mail with a folder, bulk operations across mailboxes, permanent provider message
delete, automatic folder cleanup, and richer undo UX.

Deploy / migration notes: No migration, permission seed, queue restart, scheduler change, frontend
build, OAuth/provider configuration change, or IMAP account reconfiguration is required. Deploy the
code, run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: Folder create/rename/move/delete must stay provider-authoritative and must not change local
folder state until the IMAP server acknowledges the operation. Folder create must not run provider
expunge after IMAP CREATE, because some servers reject expunge when no mailbox is selected even after
the folder was created successfully. Deleting a folder with active mail must stay blocked; users must
move messages to another selectable folder first. Rule-referenced folders must not be deleted because
active rules could route future mail to a missing provider folder.

Automated verification: Focused folder-manager regressions pass with 13 tests and 85 assertions. The
full `EmailModuleTest.php` passes with 131 tests and 1090 assertions. PHP syntax checks pass for the
folder manager action, remote-operation runner, IMAP client, Mail workspace Livewire component, and
Email feature test file. `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge
sync, one default queue worker pass, and `php artisan queue:failed` pass; no failed jobs are present.

Manual checks:

- [x] Open `/tech/mail`, select one mailbox or one folder where the technician has Organize access,
  and confirm the gear appears at the far right of the Folders header.
- [x] Open the gear modal and confirm it lists folders only for the selected mailbox.
- [x] Confirm parent folders are collapsed by default, can be expanded/collapsed, and subfolders are
  visible only when their parent is expanded.
- [x] Confirm rows without actions show the blocker reason, such as system folder or has subfolders.
- [x] Create a safe custom test folder at root and another safe custom test folder under a selected
  parent; confirm both appear in Nexum and an external IMAP client.
- [x] Rename the safe custom folder and confirm the new name appears in Nexum, in the external IMAP
  client, and as the target for any personal rule that referenced that folder.
- [x] Move a safe custom leaf folder to another parent or back to root and confirm the new path
  appears in Nexum and the external IMAP client.
- [x] Place or move one test message into the custom folder, start delete, and confirm Nexum warns
  that the folder contains mail instead of allowing direct delete.
- [x] Move the folder's mail to another selectable folder from the modal, confirm the message appears
  in the target folder, then delete the now-empty custom folder.
- [x] Confirm INBOX, Sent, Drafts, Trash, Archive, Junk/Spam, and other special-use folders do not
  expose rename/move/delete actions.
- [x] Confirm a View-only mailbox hides the gear and direct folder actions are rejected.
- [x] Check desktop and mobile widths and confirm the folder modal stays inside the viewport, the
  folder list scrolls inside the modal, and the modal does not overlap the message panes.

Result / notes:

### HR-2026-08-13-031 - Email Mail Write-Gated AI Assistants

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-ai-write-gated-assistants.md`,
`HR-2026-08-12-016`, and `HR-2026-08-12-017`

Scope: Mail AI summary can expose a human-clicked `Create Ticket` assistant only when governed Mail
AI is available, the selected/default Email agent has action execution enabled, the agent has
`tickets.create` and `tickets.update` API scopes, and the user has the normal Ticket and mailbox
permissions. The action reuses the deterministic Mail-to-Ticket flow.

Out of scope / not reviewed here: automatic replies, AI sending, AI moving mail, AI creating rules,
AI arbitrary Ticket updates, background AI agents, and broader write-gated assistant actions.

Deploy / migration notes: No migration, permission seed, queue restart, scheduler change, frontend
build, provider configuration change, or OpenAI/legal action is required beyond the existing AI
governance records already used by Mail AI.

Risks: The AI write button must remain hidden unless every governance, agent, user, and mailbox gate
passes. AI output must not mutate data directly, and manual Ticket creation must remain available
without depending on AI policy.

Automated verification: Focused Mail regressions for the six 2026-08-13 completion slices pass with
6 tests and 50 assertions. Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Migration `2026_08_13_140000_create_email_ticket_conversation_links_table` ran in batch 84. Cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, route registration, no failed jobs, and git diff checks passed; git diff reported only pre-existing CRLF warnings in unrelated files.

Manual checks:

- [x] With an action-disabled Email agent, generate an AI summary and confirm `Create Ticket` is not
  shown in the AI summary panel.
- [x] Enable action execution and Ticket write scopes on the Email/default agent, keep user
  `ticket.create` and mailbox Organize access, and confirm `Create Ticket` appears.
- [x] Click the AI-gated `Create Ticket` button and confirm a Ticket is created through the normal
  Mail-to-Ticket flow and linked to the source email.
- [x] Remove either the agent Ticket scope or the user's `ticket.create` permission and confirm the
  AI write button disappears.
- [x] Confirm the read-only Summary and Draft reply AI buttons still work as before and do not send
  email or mutate Ticket fields directly.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-030 - Email Mail Remote Operation Retry Dashboard

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-remote-operation-retry-dashboard.md`,
`HR-2026-08-12-007`, `HR-2026-08-12-014`, and `HR-2026-08-15-003`

Scope: `/tech/mail` shows a compact Mailbox operations card in the right bar when
organize-authorized mailboxes have pending, running, failed, or recent verified provider operations.
The card starts collapsed with status counts in its header. Expanding it shows the existing detailed
operation, Retry, Cancel, evidence, and eligible verified Undo controls; no-operation state adds no
empty card. Pending/failed rows use the existing provider operation runner or cancellation action.
An ambiguous Archive/Trash/Move row without immutable target path/UID evidence remains visible but
does not expose Retry.

Out of scope / not reviewed here: automatic retry scheduler, bulk retry/cancel, provider folder
rename/delete, provider folder create retry ledger, and richer undo UX.

Deploy / migration notes: No migration, permission seed, queue restart, scheduler change, frontend
build, or provider configuration change is required.

Risks: Users must see only operations for mailboxes they can organize. Retry must use the same
provider mutation logic as the original action, and cancellation must not alter already succeeded
provider state.

Automated verification: Focused Mail regressions for the six 2026-08-13 completion slices pass with
6 tests and 50 assertions. Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Migration `2026_08_13_140000_create_email_ticket_conversation_links_table` ran in batch 84. Cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, route registration, no failed jobs, and git diff checks passed; git diff reported only pre-existing CRLF warnings in unrelated files.

Manual checks:

- [x] Create or identify a failed Mail remote operation for a safe test message and confirm the
  Mailbox operations card appears in the `/tech/mail` right bar, starts collapsed, and shows the
  correct failed/status count without expanding.
- [x] Expand the right-bar card and confirm the account, operation type, subject, status, error,
  focus order, and ARIA disclosure state are understandable. Confirm it is absent when no active or
  recent operation qualifies.
- [x] Confirm an ambiguous Archive/Trash/Move row with missing immutable target path or target UID
  remains reviewable but has no Retry control.
- [x] Retry a safe failed operation, such as a move to a test folder, and confirm the provider
  mailbox and Nexum placement projection both update.
- [x] Cancel a pending/failed operation and confirm it no longer appears as active retry work.
- [x] Sign in as a user without Organize access to that mailbox and confirm the operation is hidden.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-029 - Email Mail Multi-Conversation Ticket Links

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-ticket-conversation-links.md`, and
`HR-2026-08-12-010`

Scope: Mail can link a selected non-draft email to an existing Ticket through a guarded More action.
New Mail-created Tickets also record a Mail-owned conversation-link row. The existing
`LinkInboundEmailToTicket` action still owns Ticket message creation and attachment capture.

Out of scope / not reviewed here: unlink UI, one email linked to several active Tickets, Ticket
timeline redesign, customer portal projection, and AI arbitrary Ticket updates.

Deploy / migration notes: Run migration
`2026_08_13_140000_create_email_ticket_conversation_links_table.php`. No queue restart, scheduler
change, frontend build, or provider configuration change is required.

Risks: `email_messages.ticket_id` remains a single compatibility link; the new table must not imply
the same email can be actively linked to several Tickets. Source mail must stay in the mailbox.

Automated verification: Focused Mail regressions for the six 2026-08-13 completion slices pass with
6 tests and 50 assertions. Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Migration `2026_08_13_140000_create_email_ticket_conversation_links_table` ran in batch 84. Cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, route registration, no failed jobs, and git diff checks passed; git diff reported only pre-existing CRLF warnings in unrelated files.

Manual checks:

- [x] Open an unlinked test email in `/tech/mail` with mailbox Organize access and `ticket.update`,
  then use More -> Link existing Ticket with a known Ticket key.
- [x] Confirm the Ticket gets an inbound Ticket message and the source email remains in its mailbox.
- [x] Link another message in the same RFC thread to the same Ticket and confirm Mail shows multiple
  Ticket conversation links for that conversation.
- [x] Try the same flow without `ticket.update` and confirm the Link existing Ticket action is not
  available.
- [x] Confirm the ordinary Ticket icon still creates a new Ticket only when `ticket.create` is
  present and the email is not already linked.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-028 - Email Mail Grouped Rules And Reprocessing

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-grouped-rules-reprocessing.md`,
`HR-2026-08-12-005`, and `HR-2026-08-12-015`

Scope: Admin Email rules can use grouped all/any conditions, add/remove condition rows in the form,
show grouped snapshots in the rules list, and reprocess a stored Email message through the published
rule engine.

Out of scope / not reviewed here: nested groups deeper than one level, personal grouped rule builder
UI, drag ordering, and IMAP folder reprocessing.

Deploy / migration notes: No migration, permission seed, queue restart, scheduler change, frontend
build, or provider configuration change is required.

Risks: Rule preview and runtime must use the same all/any semantics. Reprocessing must stay gated by
`email.rule_manage` and must use the idempotent published rule attempt ledger.

Automated verification: Focused Mail regressions for the six 2026-08-13 completion slices pass with
6 tests and 50 assertions. Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Migration `2026_08_13_140000_create_email_ticket_conversation_links_table` ran in batch 84. Cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, route registration, no failed jobs, and git diff checks passed; git diff reported only pre-existing CRLF warnings in unrelated files.

Manual checks:

- [x] Create an Admin Email rule with two condition groups and `Any group can match`.
- [x] Confirm add/remove condition controls work without editing JSON.
- [x] Preview or reprocess a message that matches only one group and confirm the action runs.
- [x] Confirm the rule list displays grouped conditions readably.
- [x] Sign in without `email.rule_manage` and confirm the reprocess route is denied.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-027 - Email Mail Provider Sent Append Support

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-provider-sent-append-support.md`,
`HR-2026-08-13-021`, and `HR-2026-08-15-003`

Scope: Mail keeps technical provider Sent append support for pending outbound reconciliation rows,
with same-account Message-ID deduplication before append. The regular `/tech/mail` workspace no
longer shows a Provider Sent dashboard or `Append to Sent` controls. The 2026-08-15 reliability
refinement reserves `append_started` under a row lock, makes repeated started/appended calls no-ops,
and blocks replay after a provider write may have started.

Out of scope / not reviewed here: ambiguous-match chooser UI, automatic append retry scheduler,
Ticket timeline projection, and replacing normal Sent-folder sync as final reconciliation.

Deploy / migration notes: No migration beyond the existing Sent reconciliation table, permission
seed, queue restart, scheduler change, frontend build, or provider configuration change is required.

Risks: Append must require mailbox Send access, must not duplicate an already imported provider Sent
copy, and must honestly remain `appended` until normal provider sync confirms final reconciliation.
Only a proven pre-write failure may be reserved again; an exception after the provider write begins
must remain blocked and await provider evidence.

Automated verification: Focused Mail regressions for the six 2026-08-13 completion slices pass with
6 tests and 50 assertions. Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Migration `2026_08_13_140000_create_email_ticket_conversation_links_table` ran in batch 84. Cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, route registration, no failed jobs, and git diff checks passed; git diff reported only pre-existing CRLF warnings in unrelated files.
The 2026-08-15 provider Sent append/reservation safety regressions pass 4 tests / 16 assertions.

Manual checks:

- [x] Send a test Compose message from a mailbox with a real selectable Sent folder and confirm
  `/tech/mail` does not show a Provider Sent dashboard or `Append to Sent` control.
- [x] Confirm the pending provider Sent reconciliation row still stores a raw outbound snapshot for
  technical append/reconciliation.
- [x] If technical append is triggered through a backend/admin path, confirm the message appears in
  the provider Sent folder in an external IMAP client.
- [x] Trigger the same accepted append twice and confirm only one provider write. Simulate a provider
  exception after write start and confirm a repeat performs no new append; simulate a proven
  pre-write failure and confirm it alone can be reserved safely again.
- [x] Run normal mail sync and confirm the Sent copy reconciles to `Sent reconciled`.
- [x] Confirm a user without mailbox Send access sees no Provider Sent controls for that account.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-026 - Email Mail Provider Drafts Direct Editing

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-provider-drafts-direct-editing.md`,
`HR-2026-08-13-022`, and `HR-2026-08-13-023`

Scope: Imported provider Drafts placements can be opened with `Edit draft`, copied into the Mail
composer, sent through normal SMTP, and cleaned up from provider Drafts by folder/UIDVALIDITY/UID.

Out of scope / not reviewed here: provider folder rename/delete, concurrent draft locks, editing a
provider draft without safe UID evidence, automatic sending, and AI write/send actions.

Deploy / migration notes: No new migration beyond the existing draft tables, permission seed, queue
restart, scheduler change, frontend build, or provider configuration change is required.

Risks: Draft editing must require mailbox View and Send access. Provider Drafts rows must not expose
Reply/Forward/Ticket/rule/spam actions. SMTP success must not be rolled back if provider cleanup
later fails; instead the cleanup issue must be visible.

Automated verification: Focused Mail regressions for the six 2026-08-13 completion slices pass with
6 tests and 50 assertions. Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Migration `2026_08_13_140000_create_email_ticket_conversation_links_table` ran in batch 84. Cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, route registration, no failed jobs, and git diff checks passed; git diff reported only pre-existing CRLF warnings in unrelated files.

Manual checks:

- [x] Import or create a safe provider Drafts message and open it from `/tech/mail`.
- [x] Confirm provider Drafts rows show `Edit draft` and do not show Reply, Forward, Spam, Ticket,
  or Add rule actions.
- [x] Edit recipients/body/subject and send the draft through SMTP.
- [x] Confirm the outbound message is sent, the original provider Drafts copy is removed or an
  honest cleanup warning is shown, and the Drafts placement no longer appears as active.
- [x] Confirm a View-only or non-send-authorized user cannot edit or send the provider draft.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-025 - Email Mail Provider Folder Create

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-provider-folder-create.md`,
`HR-2026-08-12-003`, and `HR-2026-08-12-014`

Scope: `/tech/mail` now lets a technician create one custom provider folder from the Mail sidebar
after selecting a mailbox they can organize. Nexum calls IMAP CREATE first, projects the returned
folder into `email_folders`, selects the new folder, and keeps it available for later move/rule
targets through existing folder projection.

Out of scope / not reviewed here: provider folder rename, provider folder delete, system/special-use
folder creation, automatic message moves into the new folder, remote-operation ledger retry for
folder creation, provider Drafts direct editing, provider Sent append/deduplication, automatic
replies, and AI write/send actions.

Deploy / migration notes: No migration, permission seed, queue restart, scheduler change, frontend
build, provider folder rename/delete, or OAuth/provider configuration change is required. Deploy the
code, run `php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: Folder creation must require mailbox Organize access and one selected mailbox. Nexum must not
project a local folder if the provider CREATE call fails. Reserved system folders must stay owned by
IMAP discovery. Rename/delete remain separate because they can strand messages or remove provider
state.

Automated verification: Folder-create focused regressions pass with 2 tests and 11 assertions. Full
`EmailModuleTest.php` passes with 114 tests and 966 assertions. PHP syntax checks pass for the new
provider folder action, IMAP client, Mail workspace component, and Email feature test file.
`php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default queue
worker pass, and `php artisan queue:failed` pass; no failed jobs are present. `git diff --check`
passes with only pre-existing CRLF working-copy warnings in unrelated files.

Manual checks:

- [x] Select one safe mailbox in `/tech/mail` where the signed-in technician has Organize access and
  confirm the folder-create icon appears in the sidebar.
- [x] Create a safe custom folder such as `Nexum Test/Manual Review` and confirm the success message
  appears and the new folder is selected.
- [x] Confirm the folder appears in the same mailbox in an external IMAP client.
- [x] Confirm the folder appears as a selectable Move-to-folder target for messages in the same
  mailbox.
- [x] Switch to a View-only mailbox and confirm the create icon is hidden and direct submission is
  rejected.
- [x] Try a reserved name such as `INBOX` or `Drafts` and confirm Nexum rejects it instead of trying
  to create a system folder.
- [x] Check desktop and mobile sidebar widths and confirm the create form and validation messages do
  not overlap the folder list.

Result / notes:

### HR-2026-08-13-024 - Email Mail Durable Draft Attachments

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-durable-draft-attachments.md`,
`HR-2026-08-13-020`, `HR-2026-08-13-023`, and `HR-2026-08-15-003`

Scope: `/tech/mail` now stores composer draft attachments durably in
`email_composer_draft_attachments` and local storage. Saved draft attachments restore in the
composer, can be removed from the draft, are included in SMTP sends, are included in provider Drafts
append, and are cleaned up when the draft is sent or discarded.
New attachment writes use the verified Email private-storage boundary; the two legacy Dev trees and
dual-runtime filesystem review remain tracked under `HR-2026-08-15-003`. The 2026-08-15 runtime
hardening also reauthorizes client-supplied attachment IDs against the exact active draft and current
mailbox composer context before SMTP.

Out of scope / not reviewed here: inline image editing or CID rewriting; attachment preview/download
from the draft editor; direct editing of imported provider Drafts placements; shared draft locks or
conflict UI; provider folder create/rename/delete mirrored to IMAP; automatic replies; and AI
write/send actions.

Deploy / migration notes: Run `umask 0002; HOME=/tmp php artisan migrate` so
`2026_08_13_130000_create_email_composer_draft_attachments_table` is applied. Then run
`php artisan optimize:clear`, `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. No permission seed, queue restart,
scheduler change, frontend build, provider folder creation, or external storage migration is
required. Before cross-runtime attachment verification, complete the owner/root ACL normalization
listed in `HR-2026-08-15-003`.

Risks: Draft attachments must stay scoped to the owning technician and authorized mailbox draft
context. Stored files must be included when sending and provider-syncing, but must be deleted after
send/discard. Removing an attachment must mark the provider draft copy stale until the user saves
again. Total composer attachments must stay capped at 5 and 10 MB per file.

Automated verification: Dev migration applied successfully. Durable draft attachment focused
regressions pass with 5 tests and 72 assertions. Full `EmailModuleTest.php` passes with 112 tests and
955 assertions. PHP syntax checks pass for the migration, draft attachment model, draft model, local
draft service, provider draft sync service, send action, Mail workspace component, and Email feature
test file. `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default
queue worker pass, and `php artisan queue:failed` pass; no failed jobs are present. `php artisan
migrate:status` confirms the new migration ran in batch 83. `git diff --check` passes with only
pre-existing CRLF working-copy warnings in unrelated files.
The later private-storage contract contributes to the affected storage/send run of 16 tests / 125
assertions. The exact-active-draft isolation regression passes 1 test / 6 assertions within the
expanded composer lifecycle file at 4 / 26.

Manual checks:

- [x] Open `/tech/mail`, start Compose, add one small attachment, click Save draft, and confirm the
  saved attachment appears in the composer as a stored draft attachment.
- [x] Close and reopen the same draft and confirm the attachment is still listed without needing a
  new upload.
- [x] Save the draft to provider Drafts and confirm the provider draft includes the attachment in an
  external mail client.
- [x] Remove the saved attachment and confirm it disappears from the composer, then Save draft again
  and confirm the provider draft no longer contains it.
- [x] Send a restored draft with a saved attachment and confirm the outbound email includes the
  attachment once.
- [x] In a controlled request, substitute an attachment ID from another same-user draft/account and
  confirm the file is not sent; revoke mailbox access and confirm a stale composer cannot send its
  stored attachments.
- [x] Confirm the sent or discarded draft no longer restores and that saved draft attachment metadata
  is cleaned up.
- [x] Try adding more than 5 files or a file over 10 MB and confirm the UI rejects it cleanly.
- [x] Check desktop and mobile composer widths and confirm attachment badges wrap without overlapping
  address fields or Send/Save buttons.

Result / notes:

### HR-2026-08-13-023 - Email Mail Provider Drafts Write Sync

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/feature-slices/2026-08-13-email-mail-provider-drafts-write-sync.md`,
`HR-2026-08-13-020`, `HR-2026-08-13-021`, `HR-2026-08-13-022`, and
`HR-2026-08-15-003`

Scope: `/tech/mail` manual `Save draft` now saves the local Nexum composer draft and, when the
composer has no temporary attachments and the mailbox has a discovered selectable provider Drafts
folder, appends a real IMAP provider draft with `X-Unsent: 1` and the `\Draft` flag. Local drafts
record provider Drafts status, folder path, UIDVALIDITY, UID, Message-ID, timestamps, and errors.
Autosave remains local-only. Send and Discard best-effort delete the recorded provider draft copy.
Normal Drafts import reconciles actual provider UID/status back to the local draft by normalized
Message-ID.

The 2026-08-15 reliability refinement queues one exact bounded Drafts-folder refresh after a
successful APPEND. It shares the account-fetch overlap lock, requires an established baseline,
fails closed on UIDVALIDITY change, imports only the matching Message-ID with Inbox automation off,
and leaves the draft pending when the provider copy is not visible yet. Pre-APPEND UIDNEXT is not
treated as final provider identity.

Out of scope / not reviewed here: durable draft attachments; uploading temporary composer
attachments into provider Drafts; direct editing of imported provider Drafts placements; shared draft
locks, responder reservations, typing presence, or conflict UI; creating, renaming, or deleting
provider folders from Nexum; direct provider Sent append/deduplication; automatic replies; and AI
write/send actions.

Deploy / migration notes: Run `umask 0002; HOME=/tmp php artisan migrate` so
`2026_08_13_120000_add_provider_sync_fields_to_email_composer_drafts` is applied. Then run
`php artisan optimize:clear`, `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. Restart the ordinary/default queue worker so
it loads `RefreshEmailProviderDraftFolder`. No permission seed, frontend build, scheduler change,
provider folder creation, or external OAuth change is required.

Risks: Provider write sync must use only discovered real Drafts folders and mailbox Send
authorization. Autosave must not spam the provider with drafts. Draft cleanup must never delete by
stale UIDVALIDITY evidence. Temporary attachments must not be silently omitted from provider Drafts
while the UI implies they were saved. Provider cleanup failures after Send/Discard must be visible
without rolling back the successful local lifecycle.
The targeted refresh must not scan all folders, import history, cross accounts, run Inbox automation,
or accept a stale UID namespace.

Automated verification: Dev migration applied successfully. Drafts write-sync focused regressions
pass with 4 tests and 38 assertions. Full `EmailModuleTest.php` passes with 111 tests and 936
assertions. PHP syntax checks pass for the migration, provider draft sync service, local draft
service, IMAP client, Mail workspace Livewire component, inbound store job, and Email feature test
file. `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default
queue worker pass, and `php artisan queue:failed` pass; no failed jobs are present. `php artisan
migrate:status` confirms the new migration ran in batch 82. `git diff --check` passes with only
pre-existing CRLF working-copy warnings in unrelated files.
The 2026-08-15 targeted-refresh file passes 4 tests / 35 assertions, and four existing provider
Draft regressions pass with 40 assertions; Pint, PHP syntax, and diff checks pass without a live
provider call.

Manual checks:

- [x] Open `/tech/mail`, start Compose from a safe mailbox with a discovered provider Drafts folder,
  enter To/Cc/Subject/body without attachments, click Save draft, and confirm the composer shows a
  provider synced or pending status.
- [x] Confirm the saved draft appears in the real provider Drafts folder in an external mail client
  with the same recipients, subject, and body. Process the ordinary queue worker and confirm the same
  exact Message-ID appears once in Nexum's Drafts view without waiting for an unrelated full poll.
- [x] Change the same draft, click Save draft again, and confirm the provider shows one updated
  usable draft rather than a growing list of stale copies.
- [x] Add a temporary attachment, click Save draft, and confirm the UI says the draft is local-only
  for attachments instead of implying the attachment was saved to provider Drafts.
- [x] Send the saved draft and confirm SMTP sends once, the local draft is marked sent, and the
  provider Drafts copy is removed or a cleanup warning is shown.
- [x] Discard a synced draft and confirm the local draft does not restore and the provider Drafts
  copy is removed or a cleanup warning is shown.
- [x] Wait for or run IMAP Drafts sync and confirm the local draft provider UID/status reconciles by
  Message-ID without creating Tickets, Signals, inbound rule executions, or Inbox unread work.
- [x] Check desktop and mobile composer widths and confirm provider draft status badges do not
  overlap the Save draft button or address fields.

Result / notes:

### HR-2026-08-13-022 - Email Mail Provider Drafts Visibility

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/feature-slices/2026-08-13-email-mail-provider-drafts-visibility.md`,
`HR-2026-08-12-003`, `HR-2026-08-13-020`, `HR-2026-08-13-021`, and
`HR-2026-08-15-003`

Scope: `/tech/mail` now shows imported provider Drafts-folder placements as explicit provider
drafts. Technicians get a Drafts sidebar view, a provider draft list filter, and `Provider draft`
badges in the list and reader. Drafts-folder placements are treated as read-only provider cache
projections in this slice.
The later post-APPEND targeted refresh imports a matching manually saved provider copy into this same
projection without running Inbox automation.

Out of scope / not reviewed here: IMAP APPEND, replace, or delete for provider Drafts; linking local
Nexum autosave drafts to provider Drafts rows; durable draft attachment persistence; shared draft
locks or presence; creating, renaming, or deleting provider folders from Nexum; Sent append and
deduplication dashboard; automatic replies; and AI write/send actions.

Deploy / migration notes: No migration, permission seed, queue restart, scheduler change, frontend
build, or provider folder write operation is required by this slice. Deploy the code, run
`php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`.

Risks: Provider drafts must stay scoped to authorized mailbox View access. Drafts-folder imports must
not run Inbox Ticket/rule automation. Provider draft placements must not expose ordinary
Reply/Reply All/Forward, Spam, Ticket, or rule actions until a later provider Drafts write slice
exists. Local Nexum autosave drafts must not be presented as provider drafts.

Automated verification: Drafts-focused regressions pass with 2 tests and 15 assertions. Full
`EmailModuleTest.php` passes with 107 tests and 898 assertions. PHP syntax checks pass for the Mail
workspace Livewire component, Email folder projector, and Email feature test file.
`php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default queue
worker pass, and `php artisan queue:failed` pass; no failed jobs are present. `git diff --check`
passes with only pre-existing CRLF working-copy warnings in unrelated files.

Manual checks:

- [x] Import or create a safe real provider Drafts-folder message after the folder baseline and
  confirm it appears in the `/tech/mail` Drafts view.
- [x] Confirm the list row and reader show `Provider draft` for that placement.
- [x] Confirm ordinary Reply, Reply All, Forward, Spam, Ticket, and Add rule actions are hidden for
  the provider draft placement.
- [x] Select the provider Drafts folder directly in the sidebar and confirm the folder view remains
  scoped to that folder.
- [x] Confirm the Drafts import does not create Tickets, Signals, inbound rule executions, or Inbox
  unread work.
- [x] Check desktop and mobile widths and confirm the Drafts sidebar count, filter, and badges wrap
  without overlap.

Result / notes:

### HR-2026-08-13-021 - Email Mail Sent Reconciliation Foundation

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`,
`docs/feature-slices/2026-08-13-email-mail-sent-reconciliation-foundation.md`,
`HR-2026-08-12-003`, `HR-2026-08-12-008`, `HR-2026-08-13-020`, and
`HR-2026-08-15-003`

Scope: `/tech/mail` now records a pending provider Sent reconciliation row after successful SMTP
send from Compose, Reply, Reply All, or Forward. When normal IMAP sync later imports a same-account
Sent-folder placement with the same normalized `Message-ID`, Mail marks the outbound log reconciled
and shows `Sent reconciled` on the provider Sent copy.

The 2026-08-15 reliability refinement makes SMTP acceptance the truthful UI boundary. If the local
Sent snapshot or reconciliation record fails afterward, Mail marks the matching draft sent and
shows a sanitized accepted-but-follow-up-failed warning with `Do not resend it`; it never reports the
accepted message as an SMTP send failure. Before SMTP, one atomic Email-log reservation stores the
unique idempotency key and stable Message-ID; Mail then attempts initial same-identity reconciliation
evidence. Concurrent or repeated submission cannot elect another sender, and an uncertain transport
result stays unresolved and blocks replay until provider Sent mail is reviewed.
Normal same-account Sent sync resolves that reservation as accepted when the exact reserved
Message-ID appears, without another SMTP call. A newly written raw snapshot is removed if its
reconciliation row cannot be persisted.

Out of scope / not reviewed here: direct IMAP APPEND to provider Sent, provider Drafts sync,
Sent deduplication dashboard, ambiguous-match resolution UI, Ticket timeline projection, automatic
replies, AI write/send actions, and API multipart send support.

Deploy / migration notes: Run `umask 0002; HOME=/tmp php artisan migrate` so
`2026_08_13_110000_create_email_sent_reconciliations_table` is applied. On Dev, the first migration
attempt failed on MySQL's FK-name length limit, left an empty partial table, and the empty table was
dropped before the corrected migration ran in batch 81. No queue restart or frontend build is
required by this slice.

Risks: Reconciliation must stay scoped to the same Email account and exact normalized `Message-ID`.
Sent-folder imports must not run Inbox ticket/rule automation. SMTP success must not be represented
as provider Sent confirmation until the provider copy is actually imported. Ambiguous matches must
not be silently resolved.
A post-SMTP storage/database failure must not reopen the composer or invite a duplicate send. The
durable reservation must exist before the provider call, accepted-log/telemetry failure must not
reverse delivery truth, and an unresolved transport outcome must not be retried automatically.

Automated verification completed 2026-08-13: focused Sent-reconciliation regressions pass with 2
tests and 17 assertions. Full `EmailModuleTest.php` passes with 105 tests and 883 assertions. PHP
syntax checks pass for the reconciliation model, service, migration, send action, inbound storage
job, Mail workspace component, and Email feature test file. `php artisan optimize:clear`,
`php artisan view:cache`, Email Knowledge sync, one default queue worker pass,
`php artisan queue:failed`, `php artisan migrate:status`, and `git diff --check` pass. `git diff
--check` reports only pre-existing CRLF working-copy warnings in unrelated files.
The expanded 2026-08-15 pre-/post-SMTP boundary passes 11 focused tests / 94 assertions, provider Sent
append/reservation safety passes 4 / 16, and targeted Mail send regressions pass 7 / 131.

Manual checks:

- [x] Send a new Compose message from `/tech/mail`; confirm the message sends normally and an
  outbound `email_logs` row records `provider_sent.status=pending`.
- [x] Run or wait for provider folder sync; confirm the provider Sent copy is imported and the
  matching reconciliation row changes to `reconciled`.
- [x] Open the Sent folder or All view in `/tech/mail`; confirm the provider Sent copy shows
  `Sent reconciled` in the list and reader.
- [x] Confirm Sent-folder imports do not create Tickets, Signals, Inbox unread work, or inbound rule
  executions.
- [x] Send or import a Sent copy with a different account or different `Message-ID`; confirm it does
  not reconcile the wrong outbound log.
- [x] In isolated controlled send-failure cases, confirm the reservation and exact Message-ID exist
  before SMTP; concurrent/repeated submission makes only one SMTP call; an uncertain transport
  result remains unresolved and blocked; and accepted-log, telemetry, snapshot, or reconciliation
  failure after acceptance keeps the draft sent with `Do not resend it` and no internal exception
  text.
- [x] Import a matching same-account Sent copy for an unresolved reservation and confirm it becomes
  accepted/reconciled without resending. Force a snapshot-followed-by-row-insert failure and confirm
  the new raw file is removed.
- [x] Check desktop and mobile widths and confirm the new badge wraps without overlapping the list
  row or reader status badges.

Result / notes:

### HR-2026-08-13-020 - Email Mail Local Drafts And Autosave

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-drafts-autosave.md`, `HR-2026-08-12-008`,
`HR-2026-08-12-009`, `HR-2026-08-12-014`, `HR-2026-08-12-017`,
`HR-2026-08-13-019`, `HR-2026-08-13-024`, and `HR-2026-08-15-003`

Scope: `/tech/mail` now keeps local Nexum drafts for Compose, Reply, Reply All, and Forward. Drafts
are scoped to the signed-in technician and the relevant sender account or selected mailbox
placement. The composer autosaves after field/editor changes, exposes explicit Save draft and
Discard draft actions, restores active matching drafts when the same composer context opens, marks a
matching draft sent after confirmed SMTP acceptance, and avoids creating a draft when an untouched
Forward/Reply composer is simply closed. Durable attachments and provider Drafts synchronization were
outside this original slice and are now reviewed separately under `HR-2026-08-13-024` and
`HR-2026-08-13-023`.

The 2026-08-15 lifecycle correction removes an unsupported Livewire 2 entangle modifier that made
the Alpine body value undefined. Visual and HTML modes now update Livewire explicitly before
autosave or Send, and Livewire no longer morphs the active Visual `contenteditable` contents away.
The runtime hardening also marks the matching draft sent after confirmed SMTP acceptance even when
later Sent snapshot/reconciliation follow-up warns, while an uncertain SMTP transport outcome keeps
the composer open and blocks another call for the same reserved send key.

Deployment actions: deploy the code, run `php artisan migrate --force`, run
`php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. No permission seed, queue restart,
scheduler change, frontend build, provider Drafts append, provider Sent append, Ticket write, or
automatic AI send is required.

Risks: Drafts must stay user-scoped and mailbox-authorized, must not broaden mailbox read or send
access, must not send or mutate provider state during autosave, and must not store temporary upload
paths as durable draft attachments. Autosave should not turn untouched default Reply/Forward content
into noisy saved drafts. Sending must mark only the matching active local draft sent after confirmed
SMTP acceptance; an unresolved provider outcome must neither mark it sent nor permit blind resend.

Automated verification: Dev migration status confirms
`2026_08_13_100000_create_email_composer_drafts_table` is `Ran` in batch 80. Draft-filtered Email
regressions pass with 6 tests and 60 assertions, including the four local draft/autosave
regressions. Full `EmailModuleTest.php` passes with 103 tests and 865 assertions. PHP syntax checks
pass for the Mail workspace Livewire component, draft service, draft model, and draft migration.
`php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, and one default queue
worker pass completed. At that 2026-08-13 verification, `php artisan queue:failed` reported no
failed jobs. `git diff --check` passed with only pre-existing CRLF working-copy warnings in unrelated
files.

The 2026-08-15 composer lifecycle regression passes 3 tests and 20 assertions. Together with the
full `EmailModuleTest.php`, the current focused run passes 144 tests and 1,226 assertions. Pint, PHP
syntax, Blade cache compilation, and `git diff --check` pass. One unrelated, pre-existing
`FetchImapAccount` failed job is now recorded under the separate Email private-storage Operations
blocker; it was not caused or retried by this composer correction.
The expanded lifecycle file including exact-draft attachment isolation now passes 4 tests / 26
assertions.

Human checks:

- [x] Start Compose in Visual mode, type a body, wait through several autosave intervals, and confirm
  the text remains visible and the local draft body is saved.
- [x] Switch to HTML mode, edit the body source, wait for autosave, switch back to Visual, and confirm
  the same content remains. Send from a safe test mailbox and confirm the valid body is accepted
  rather than showing `Write a message before sending.`
- [x] Enter a valid body with an invalid recipient, attempt Send, and confirm recipient validation
  leaves the body and open composer intact.
- [x] Open `/tech/mail`, start a new Compose draft, fill To/Cc/Subject/body, click Save draft, close
  the composer, reopen Compose from the same sender account, and confirm the fields restore.
- [x] Start Reply, Reply All when visible, and Forward drafts on a safe test message, save each, close
  and reopen the same action, and confirm only the matching action/message restores.
- [x] Open Forward on a message, make no edits, close it, reopen Forward, and confirm no local draft
  status appears from the untouched default content.
- [x] Add a draft attachment, close/reopen, and confirm the later durable-attachment behavior under
  `HR-2026-08-13-024` restores only the exact active authorized draft's file.
- [x] Click Discard draft and confirm reopening the same composer context does not restore the
  discarded content.
- [x] Send a saved draft from a safe test mailbox and confirm SMTP sends once, the composer closes,
  and reopening the same context does not restore the sent draft.
- [x] Simulate a Sent follow-up failure after accepted SMTP and confirm the matching draft remains
  sent/closed with `Do not resend it`; simulate an unresolved transport outcome and confirm the
  composer stays open but repeating the same reserved send does not call SMTP again.
- [x] Confirm users without Send access cannot save Compose drafts and users without View plus Send
  cannot save Reply/Reply All/Forward drafts for a selected placement.
- [x] Check desktop and mobile widths and confirm the draft status, Save draft, Close, Discard draft,
  attachments, AI controls, and Send button wrap without overlap.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-13-019 - Email Mail Personal Signatures

Status: Reviewed
Added: 2026-08-13
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-13-email-mail-signatures.md`, `HR-2026-08-12-008`,
`HR-2026-08-12-009`, `HR-2026-08-12-014`, `HR-2026-08-12-017`, and
`HR-2026-08-15-003`

Scope: Mail now owns one personal signature per technician. The signature can be edited from
`/tech/profile`, while `/tech/mail` right bar keeps the page AI chat first, then the conditional
Mailbox operations card, then a compact Mail signature card that starts collapsed. Expanding it
reveals the settings trigger. Its responsive Bootstrap dialog has an explicit X, Cancel, and Save
for Compose, Reply, Reply All, and Forward and stays above the page footer. The Mail AI runtime status
remains a separate collapsed card below the Mail-specific signature controls. If no
saved row exists, Mail renders a default tokenized signature from technician and company profile
values. Signatures are appended by `SendEmailComposerMessage` after composer-body validation and
immediately before SMTP, with a plain-text fallback and outbound `email_logs` metadata. Mail AI
draft/rewrite controls continue to replace only the composer body and do not receive or rewrite the
signature block.

Deployment actions: deploy the code, run `php artisan migrate --force`, run
`php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. No permission seed, queue restart,
scheduler change, frontend build, provider mailbox folder write, Ticket write, or automatic AI send
is required.

Risks: The profile page must not let Mail-owned settings collide with profile form fields. Right-bar
toggles must not wipe a saved signature body. The send pipeline must not allow an otherwise empty
message to pass only because a signature exists, must not duplicate signatures on retry or pre-marked
HTML, and must place Forward signatures above quoted forwarded content. AI drafting must not mutate
or leak the signature. Users still need normal mailbox Send access to send mail.

Automated verification: Dev migration status confirms
`2026_08_13_090000_create_email_signatures_table` is `Ran` in batch 79. Signature-focused Email
regressions pass with 2 tests and 33 assertions. Affected Reply, Reply All, new Compose, Forward,
and idempotency send regressions pass with 6 tests and 115 assertions. Full `EmailModuleTest.php`
passes with 103 tests and 865 assertions after the right-bar/order regression was added, and
`UserPreferencesTest.php` passes with 6 tests and 42 assertions. PHP syntax checks pass for the
signature model, renderer, controller, migration, Mail send action, Email feature test file, and
route-permission middleware. `php artisan optimize:clear`, `php artisan view:cache`, Email
Knowledge sync, one default queue worker pass, and `php artisan queue:failed` all pass; no failed
jobs are present. `git diff --check` passes with only pre-existing CRLF working-copy warnings in
unrelated files. The later Mail rightbar ordering correction passes the targeted Mail signature
rightbar regression with 1 test and 31 assertions, the targeted Integration rightbar AI chat
regression with 1 test and 9 assertions, `php artisan view:cache`, Email Knowledge sync, one default
queue worker pass, and `php artisan queue:failed`. The 2026-08-15 responsive dialog regression passes
with 1 test and 44 assertions plus Pint, PHP syntax, Blade cache, diff, and compiled-view permission
checks.
The final targeted Compose/Reply/Reply All/Forward/idempotency regression run passes 7 tests / 131
assertions.

Human checks:

- [x] Open `/tech/profile`, confirm **Email signature** appears, and confirm the default preview
  renders technician/company values.
- [x] Save a custom HTML signature with tokens and mode toggles, then reload `/tech/profile` and
  confirm the body, preview, and toggles persist.
- [x] Open `/tech/mail` and confirm the right bar shows the page AI chat first, then the collapsed
  Mailbox operations card when qualifying work exists, a collapsed Mail signature card with its
  current name badge, and the collapsed Mail AI runtime status card.
- [x] Expand Mail signature, open its dialog, and confirm it stays above the footer, shows the current
  signature name plus Compose, Reply, Reply all, and Forward toggles, and closes through X, Cancel,
  Escape, and backdrop while returning focus to the trigger. Collapse the card again and confirm the
  state/chevron/focus remain understandable.
- [x] Change only the right-bar toggles, save, and confirm the saved signature body was not wiped.
- [x] Send one Compose and one Reply from a safe test mailbox and confirm the delivered/plain text
  and HTML bodies include the rendered signature exactly once.
- [x] Forward one message and confirm the signature appears above the forwarded-message block and
  original inbound attachments are not automatically attached.
- [x] Turn off one mode, send that mode again, and confirm no signature is appended while other
  enabled modes still append it.
- [x] Use Mail AI Draft/Improve on a Reply and confirm AI changes only the message body; the
  signature appears only after Send.
- [x] Check desktop and mobile widths for `/tech/profile` and `/tech/mail` and confirm the signature
  form/right bar controls do not overlap or push text outside their containers.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-018 - Integration Standard AI Activation For Mail AI

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`,
`docs/feature-slices/2026-08-12-integration-standard-ai-activation.md`,
`HR-2026-08-12-016`, `HR-2026-08-12-017`, and `HR-2026-07-29-012`

Scope: AI Settings now includes a compact **AI Activation** card that lets an administrator select
an active provider and model, confirm that the organization has reviewed and approves that
provider/model for Nexum AI features, and activate the ordinary governed AI path without manually
filling every advanced governance form. The action records a new installation policy revision,
enables AI and the required processing mode/profile, creates or updates an approved provider
governance profile for external providers, and creates or updates the selected model governance
policy. Email settings shows a compact readiness reason and AI Settings link when Mail AI has a
selected/default agent but Integration policy is not ready.

Deployment actions: deploy the code, run `php artisan optimize:clear`, run `php artisan
view:cache`, and sync Integration and Email Knowledge with
`php artisan knowledge:sync-docs --module=Integration --push` and
`php artisan knowledge:sync-docs --module=Email --push`. No migration, permission seed, queue
restart, scheduler change, frontend build, provider mailbox write, Ticket write, or automatic AI
send is required by this slice.

Risks: The activation path must not claim Nexum has legally certified the provider/model; it records
the organization's admin-confirmed governance decision for enforcement. Missing confirmation must
not activate AI. The action must not create coordinator workloads, token bindings, agent write
approval, Mail content, Tickets, Tasks, provider mailbox operations, or Taxonomy changes. Advanced
Privacy & Coordinator governance must remain available and able to narrow or deny later requests.

Automated verification: focused Integration activation regressions pass with 2 tests and 27
assertions. The focused Email runtime activation regression passes with 1 test and 5 assertions.
Full `IntegrationModuleTest.php` passes with 49 tests and 486 assertions. Integration governance
coverage also passes with `AiCoordinatorGovernanceTest.php` at 9 tests and 77 assertions,
`InternalAiWorkloadAdminTest.php` at 4 tests and 31 assertions, `AiModelUsageTelemetryTest.php` at
5 tests and 72 assertions, and `StructuredAiWorkloadExecutorTest.php` at 10 tests and 90
assertions. Full `EmailModuleTest.php` passes with 97 tests and 775 assertions, and
`InboundAutomationTest.php` passes with 14 tests and 81 assertions. `php artisan optimize:clear`,
`php artisan view:cache`, Email Knowledge sync, Integration Knowledge sync, default-queue worker
passes, and `php artisan queue:failed` pass. Knowledge sync and failed-jobs checks required
unsandboxed execution because sandboxed Artisan could not connect to Dev MySQL. `git diff --check`
reports only pre-existing CRLF working-copy warnings in unrelated files. One old `economy` queue job
from 2026-07-17 remains and was intentionally not processed as part of this Mail AI slice.

Human checks:

- [x] Open AI Settings and confirm the **AI Activation** card appears above Providers with AI,
  External, and readiness badges.
- [x] Select the intended active provider and model, leave the confirmation unchecked, click
  Activate AI, and confirm activation is rejected.
- [x] Check the confirmation, activate, and confirm the page reports the selected provider/model as
  ready.
- [x] Confirm the Privacy & Coordinator page now has the corresponding installation policy revision,
  provider governance profile, and model governance policy for the selected model.
- [x] Confirm the activation text does not say Nexum legally certifies compliance.
- [x] Open Email Sync & Cache Settings with the selected/default Email agent and confirm the Mail AI
  readiness warning disappears after activation.
- [x] Confirm `/tech/mail` shows Summary and Reply AI controls only for authorized users/mailboxes
  after activation.
- [x] Confirm existing advanced governance screens can still narrow/disable policy and that Mail AI
  then hides or denies controls again.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-017 - Email Mail AI Reply Drafting And Settings Storage

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-ai-reply-drafting.md`,
`HR-2026-08-12-016`, `HR-2026-08-12-018`, and `HR-2026-07-29-012`

Scope: `/tech/mail` Reply and Reply All composers now expose governed Mail AI controls when the
selected message is authorized, the actor has mailbox View and Send access, and the signed-in user
has a selected Email agent or global fallback agent. AI can draft, improve, shorten, warm tone, or
rewrite the composer body in Norwegian with optional technician guidance. Sendable responses replace
only the composer body after escaping to safe HTML. If AI determines that no reply is recommended,
such as for an automated alert or status notification, the composer body is left unchanged and the
reason is shown as an advisory status. To, Cc, Subject, attachments, idempotency key, provider state,
folders, Tickets, Tasks, rules, categories, tags, and outbound Email logs are not changed. Migration
`2026_08_12_132000_expand_common_settings_value_column` expands
`common_settings.value` to `TEXT` so full Email settings submissions can persist the default
attachment MIME allowlist, trusted-authentication lists, and Mail AI agent settings. Email settings
lets admins choose the Default Email agent directly; clearing that field uses the global default
fallback agent. The old structured workload override is no longer visible or used for Mail AI
runtime selection, and legacy `mail_ai_workload_profile_id` is cleared on the next settings save.
Mail AI controls are shown only when Integration policy readiness allows the selected/default
agent/model runtime; missing model governance hides the controls and direct action attempts return
the stable denial reason without sending a provider request.

Deployment actions: deploy the code, run `php artisan migrate --force`, run
`php artisan optimize:clear`, run `php artisan view:cache`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. No permission seed, SMTP/IMAP write,
queue restart, scheduler change, or frontend build is required. Admins still must configure
Integration AI policy/provider/model governance, normally through AI Settings **Activate AI**, before
Mail AI can call an external model.

Risks: AI composer assist must not send mail, alter recipients, alter subjects, attach files,
forward original attachments, mutate provider state, create Tickets/Tasks/rules, apply Taxonomy, or
turn technician-facing no-reply advice into a sendable composer body. The AI request must not include
raw source, HTML markup, attachment contents, attachment filenames, or unauthorized mailbox content.
Email settings must save the full MIME allowlist without truncation and legacy structured workload
settings must not affect Mail AI runtime selection. A broad
action-capable default agent may draft/summarize only through the manual non-writing Mail AI buttons;
those buttons must not execute any of the agent's write permissions.

Automated verification: Dev migration status confirms
`2026_08_12_132000_expand_common_settings_value_column` is `Ran` in batch 78. The no-reply advisory
composer regression passes with 1 test and 9 assertions, the existing Mail AI composer regressions
pass with 3 tests and 28 assertions, and the focused standard-activation runtime regression passes
with 1 test and 5 assertions. The complete Email feature suite passes with 97 tests and 775
assertions. The Inbound automation suite passes with 14 tests and 81 assertions. Full
`IntegrationModuleTest.php` passes with 49 tests and 486 assertions. Integration AI telemetry passes
with 5 tests and 72 assertions, and the structured AI executor suite passes with 10 tests and 90
assertions. PHP syntax checks pass for the composer AI action, Mail workspace Livewire component,
Email feature test file, standard activation service, AI settings component, and Email config
controller. `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync,
Integration Knowledge sync, default-queue worker passes, and `php artisan queue:failed` all pass; no
failed jobs are present. `git diff --check` reports only pre-existing CRLF working-copy warnings in
unrelated files. One old `economy` queue job from 2026-07-17 remains and was intentionally not
processed as part of this Mail AI slice.

Human checks:

- [x] Run the new migration in the target environment and confirm Email settings can save with the
  full default attachment MIME allowlist and Mail AI agent settings.
- [x] Confirm Email settings has no Structured workload override field, no `Not ready`
  `mail_ai_workload_owned_by_other_domain` status, and no `Email agent in use` text.
- [x] Confirm the Mail AI card shows only the Default Email agent dropdown plus
  `Global fallback agent: Datanora` when Datanora is the global default.
- [x] Select `Mail Agent` in Default Email agent, save, and confirm the dropdown keeps that
  selection while the fallback line still shows Datanora. Clear the field, save, and confirm Mail AI
  uses Datanora as the global fallback agent.
- [x] If a legacy `mail_ai_workload_profile_id` value exists, save Email settings once and confirm it
  is cleared and does not affect Summary or Reply assist.
- [x] With an Email/default agent whose model governance is missing, confirm Summary and composer AI
  controls are hidden and a direct action attempt reports `model_governance_missing` without a
  provider request.
- [x] Use AI Settings **Activate AI** for the selected provider/model and confirm the AI controls
  reappear for authorized mailbox users.
- [x] Open `/tech/mail`, select a message in a mailbox where the user has View and Send, click
  Reply, and confirm AI composer controls are visible.
- [x] Click Draft reply and confirm only the message body is filled; To, Cc, Subject, attachments,
  provider read/flag/folder state, Tickets, Tasks, rules, categories, tags, and outbound Email logs
  remain unchanged until the user manually sends.
- [x] On an automated RMM/status notification where AI recommends no reply, click Draft reply and
  confirm the composer body stays unchanged while the no-reply reason appears as advisory status.
- [x] Write rough text, add optional guidance, click Improve/Shorten/Warmer/Norwegian, and confirm
  only the composer body changes.
- [x] Confirm AI composer controls are hidden or denied for users without Send access, for disabled
  Mail AI runtime with no available Email/default agent, and for Forward/new Compose modes.
- [x] Check a message with attachments and confirm AI output does not reveal attachment content or
  attachment filenames.
- [x] Check desktop and mobile widths and confirm the AI instruction field/buttons wrap inside the
  composer toolbar without overlapping editor, attachment controls, or Send/Cancel buttons.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-016 - Email Mail AI Summary

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-ai-summary.md`,
`HR-2026-08-12-015`, `HR-2026-08-12-018`, and `HR-2026-07-29-012`

Scope: `/tech/mail` now exposes a read-only AI summary action for an authorized selected message
when the signed-in user has a selected Email agent or global fallback agent and Integration policy
readiness allows that agent/model runtime. The AI request includes bounded authorized message text
and mailbox metadata, excludes raw source, HTML, attachment contents, and attachment filenames, and
returns advisory summary, key points, questions, action items, suggested labels, urgency,
reply-needed signal, and provenance. The feature does not send mail, draft replies, move messages,
change Taxonomy, create Tickets or Tasks, create rules, or run external tools.

Deployment actions: deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`,
and sync Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`. No migration,
permission seed, queue restart, scheduler change, SMTP/IMAP write, or frontend build is required.
Admins must separately activate Integration AI policy/provider/model governance, normally through AI
Settings **Activate AI**.

Risks: Mail AI must not leak unauthorized mailbox content, raw source, HTML, attachment content,
attachment names, secrets, or mailbox data outside the selected user's View grants. The output must
remain advisory and non-mutating. If no Email/default agent is available, the action must stay
hidden or warn. Direct Livewire calls must recheck readiness and mailbox access server-side.

Automated verification: focused Mail AI runtime regressions pass with 2 tests and 9 assertions, and
the focused standard-activation runtime regression passes with 1 test and 5 assertions. Full
`EmailModuleTest.php` passes with 97 tests and 775 assertions, and `InboundAutomationTest.php`
passes with 14 tests and 81 assertions. Full `IntegrationModuleTest.php` passes with 49 tests and
486 assertions. Integration AI telemetry passes with 5 tests and 72 assertions, and the structured
AI executor suite passes with 10 tests and 90 assertions. PHP syntax checks pass for
`SummarizeEmailWithAi`, `AssistEmailComposerWithAi`, `MailAiAgentRuntime`,
`AiOutboundPolicyGuard`, `ActivateStandardAiRuntime`, `MailWorkspace`, `ConfigController`, and the
affected feature test files.
`php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, Integration Knowledge
sync, default-queue worker passes, and `php artisan queue:failed` pass. Knowledge sync and the
failed-jobs check required unsandboxed execution because sandboxed Artisan could not connect to Dev
MySQL. `git diff --check` reports only pre-existing CRLF working-copy warnings in unrelated files.
One old `economy` queue job from 2026-07-17 remains and was intentionally not processed as part of
this Mail AI slice.

Human checks:

- [x] In Integration/Admin, confirm a default Email agent or global fallback agent is active and
  available to the user before using Mail AI.
- [x] Open Email Sync & Cache Settings, choose a Default Email agent or leave the field blank, save,
  and confirm the card shows the global fallback agent without a structured workload override.
- [x] Confirm Mail AI controls stay hidden while the selected/default agent is denied by Integration
  policy, for example `model_governance_missing`, and appear only after AI Settings **Activate AI**
  completes the model/provider policy.
- [x] Open `/tech/mail`, select an authorized message, and confirm the AI summary icon appears when
  the runtime is ready.
- [x] Generate a summary and confirm the panel shows summary, key points/questions/action items,
  suggestions, urgency, reply-needed state, and provenance without changing read state, provider
  flags, folders, category/tags, Tickets, Tasks, rules, or outbound Email logs.
- [x] Confirm the same user cannot see or summarize a mailbox without View access.
- [x] Remove/disable available Email/default agents, then confirm the AI icon disappears and direct
  action attempts show a warning.
- [x] Check a message with attachments and confirm the summary does not reveal attachment content or
  attachment filenames.
- [x] Check desktop and mobile widths and confirm the AI summary panel does not overlap the command
  bar, message header, conversation list, or reading pane.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-015 - Email Mail Personal Simple Rules

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-personal-simple-rules.md`, and
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`

Scope: `/tech/mail` More -> Add rule now supports personal owner simple rules with matched rule
history, safe condition/action subsets, `personal_simple` rule kind, owner-scoped published versions,
and personal rule execution for personal mailboxes that do not run legacy Ticket ingress. Shared and
system mailbox rule managers are redirected to the Admin rule builder with selected mailbox and
sender context, while Admin and API rule lists remain admin-managed only.

Deployment actions: deploy the code, run `php artisan migrate --force`, run
`php artisan optimize:clear`, run `php artisan view:cache`, sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`, and restart queue workers if Email polling
or notifications run through workers. Migration
`2026_08_12_131000_add_personal_simple_email_rules` is additive and does not call IMAP/SMTP, send
mail, re-run rules, move provider messages, create Tickets, or create Signals.

Risks: personal rules must not affect shared/system mailboxes, must not create Tickets or Signals,
must not send mail, call webhooks, permanently delete provider mail, or bypass mailbox owner
authorization. Provider moves must still run through the remote-operation ledger, Admin/API rule
surfaces must not expose personal rules, and repeated processing must stay idempotent.

Automated verification: Dev migration status confirms
`2026_08_12_131000_add_personal_simple_email_rules` ran in batch 77. Focused personal-rule
regression passed with 3 tests and 28 assertions. Broader
`HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php
app/Modules/Email/Tests/Feature/InboundAutomationTest.php --compact` passed with 100 tests and 759
assertions. `HOME=/tmp php artisan test
app/Modules/Notification/Tests/Feature/InboundEmailWebPushNotificationTest.php --compact` passed
with 7 tests and 34 assertions. PHP syntax checks passed for the new action, service, migration,
model, publisher, job, Livewire component, Admin controller, and Email feature test file.
`php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync with BookStack push
queueing, one default queue worker pass, and `php artisan queue:failed` passed. After the Admin rule
builder layout polish, `php artisan view:cache` passed again and the focused Admin rule create tests
passed with 3 tests and 26 assertions.

Human checks:

- [x] Open `/tech/mail` as the owner of a personal mailbox, select a personal Inbox message, and
  confirm More -> Add rule opens the personal rule modal with rule history.
- [x] Create a move rule to a same-account selectable folder, then receive or simulate matching
  future personal mail and confirm it leaves Inbox and appears in the target folder.
- [x] Confirm a non-owner, or a user without mailbox Organize access, cannot see or create the
  personal rule action.
- [x] On a shared or system mailbox, confirm a user with `email.rule_manage` is sent to the Admin
  builder with mailbox and sender prefilled.
- [x] Open `/tech/admin/settings/email/rules/create` directly and confirm the Email Admin side menu
  is visible, the examples panel is in a card, and the rule form fills the content width.
- [x] On a shared or system mailbox, confirm a user without `email.rule_manage` does not see the
  Add rule action.
- [x] Confirm personal rules are not listed in `/tech/admin/settings/email/rules` or
  `/api/v1/email/rules`.
- [x] Check desktop and mobile widths for the personal rule modal and More menu.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-014 - Email Mail Reply All, New Compose, And Move-To-Folder

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-reply-all-new-compose.md`, and
`docs/feature-slices/2026-08-12-email-mail-move-to-folder.md`

Scope: `/tech/mail` now supports Reply All from a selected message, new outbound compose from the
message-list header, one shared rich HTML composer partial with From/To/Cc/Subject/body/attachments,
mode-specific outbound Email logs, and More-menu Move to folder for any same-account selectable
provider folder. The mailbox placement operations API also accepts `operation=move` with
`target_folder_id`. Add-rule behavior moved to HR-2026-08-12-015.

Deployment actions: deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`,
and sync Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`. No migration,
permission seed, scheduler change, queue restart, or frontend build is required.

Risks: Reply All must not send to the selected mailbox's own address or duplicate recipients; new
compose must not grant read access to send-only mailboxes; Move to folder must not allow moving into
another account's folder or a nonselectable provider folder; and provider moves must keep folder
state authoritative by hiding the source placement and projecting the returned target UID only when
the provider acknowledges it.

Automated verification: focused new regression run passes with 4 tests and 52 assertions. Focused
Reply All visibility regression passes with 2 tests and 20 assertions. Full `EmailModuleTest.php`
passes with 83 tests and 650 assertions, and `InboundAutomationTest.php` passes with 14 tests and 81
assertions. PHP syntax checks, `php artisan optimize:clear`, `php artisan view:cache`, Email
Knowledge sync, a bounded queue worker pass, and `php artisan queue:failed` pass. The failed-jobs
check required unsandboxed execution because sandboxed Artisan could not connect to Dev MySQL.

Human checks:

- [x] Open `/tech/mail` as a user with Send access, click Compose from the message-list header, and
  confirm the rich composer opens even when no message is selected.
- [x] Send a new internal test email with From, To, Cc, Subject, rich formatting, and an attachment;
  confirm SMTP acceptance in the UI and that inbox receipt still needs real mailbox confirmation.
- [x] Select a message with To/Cc participants, click Reply all, and confirm the selected mailbox
  itself is excluded while other To/Cc recipients are deduplicated.
- [x] Select a one-to-one message where only the sender and the selected mailbox are involved and
  confirm Reply all is not visible.
- [x] Move a selected message from More -> Move to folder into a custom provider folder and confirm
  it disappears from the Inbox view and appears when that folder is selected.
- [x] Confirm Add-rule behavior under HR-2026-08-12-015; this slice no longer owns that control.
- [x] Check desktop and mobile widths and confirm Compose, Reply all, More, Trash, move panel, and
  composer controls do not overlap or push text outside their containers.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-013 - Email Mail List Filter Pagination And Sidebar Polish

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-livewire-mail-workspace-personal-state.md`, and
`HR-2026-08-12-006`

Scope: `/tech/mail` now keeps the normal Work sidemenu above the Mail-specific sidebar navigation,
moves Search into the message-list column, adds a compact message-list filter selector for all,
personal unread, mailbox unread, flagged, attachment, and Ticket-linked mail, and constrains the
message-list pagination so the result summary and page buttons wrap inside the list column.

Deployment actions: deploy the code, run `php artisan optimize:clear`, run `php artisan view:cache`,
and sync Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`. No migration,
permission seed, scheduler change, queue restart, or frontend build is required.

Risks: list filters must stay inside the current authorized mailbox/folder/view scope; Search must
continue to apply only to authorized content; pagination must not cross into the reading pane; and
the restored Work sidemenu must not hide the Mail Views, Mailboxes, or Folders navigation.

Automated verification completed 2026-08-12: focused Mail workspace/sidebar/filter tests pass with
5 tests / 88 assertions, and the full Email module feature test passes with 78 tests / 593
assertions. Email Knowledge sync, one default queue worker pass, failed-job check,
`php artisan optimize:clear`, and Blade cache compilation completed successfully. These automated
checks do not replace the human checks below.

Human checks:

- [x] Open `/tech/mail` and confirm the left sidebar shows the normal Work sidemenu plus Mail Views,
  Mailboxes, and Folders.
- [x] Confirm Search and Filter are inside the message-list column, not above both Mail columns.
- [x] Use the list filter for Mailbox unread, Flagged, and Has attachments and confirm the message
  list changes without exposing mail outside the current account/folder/view scope.
- [x] Open a mailbox with enough messages for pagination and confirm the result summary plus page
  buttons wrap inside the list column without crossing into the reading pane.
- [x] Check desktop and mobile widths and confirm the list toolbar, filter select, pagination,
  message list, and reading pane do not overlap.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein approved the Mail list filter, pagination, and sidebar polish in the Codex task.

### HR-2026-08-12-012 - Email Automatic Polling Carbon 3 Interval Regression

Status: Reviewed
Added: 2026-08-12
Environment: Dev; production verification required after deploy
Related: `app/Modules/Email/Jobs/PollActiveEmailAccounts.php`

Scope: `PollActiveEmailAccounts` now compares the cached `email_last_poll_run` heartbeat with
absolute elapsed time. Carbon 3 returns signed differences by default, so an old heartbeat previously
made the interval check evaluate as still inside the interval and skipped automatic fetch dispatch
forever. Manual `Check now` continued to work because it does not use this scheduler interval gate.

Deployment actions: deploy the code, run `php artisan optimize:clear`, restart the queue worker so it
loads the new job code, and keep the once-per-minute Laravel scheduler active. No migration,
permission seed, frontend build, or provider configuration change is required.

Risks: production may still need an explicit queue-worker restart or cache clear before the new job
code is active; a stale heartbeat alone should recover on the next queued `email.poll` job after the
fix, but account credentials, IMAP errors, and queue failures remain separate operational causes.

Automated verification completed 2026-08-12: focused automatic poll tests pass with 3 tests / 8
assertions, including a Carbon 3 regression where a heartbeat 10 minutes in the past still queues
fetch jobs. The full Email module feature test passes with 77 tests / 557 assertions. These
automated checks do not replace the production checks below.

Human checks:

- [x] Deploy the fix to production and restart the production queue worker.
- [x] Confirm `schedule:run` continues to execute once per minute.
- [x] Confirm a queued `email.poll` job updates `email_last_poll_run` after deploy.
- [x] Confirm automatic polling queues `FetchImapAccount` without pressing `Check now`.
- [x] Confirm at least one active mailbox records a fresh successful fetch, or records a real
  account/provider error instead of silently skipping.
- [x] Confirm `/tech/admin/settings/email/config` no longer reports a stale scheduler heartbeat after
  the next normal polling window.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-011 - Email Mail Taxonomy Classification

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-taxonomy-classification.md`, and `HR-2026-08-12-010`

Scope: `/tech/mail` now separates provider mailbox flags from Nexum classification. Flagged mail has
a visible yellow flag treatment in the message list and reading pane while the provider flag action
stays in More actions. The selected mail has a More-actions classification surface that reuses
Taxonomy categories and tags: one category, multiple tags, visible chips while editing, existing tag
suggestions, a clear action, and guarded creation of unknown tag definitions only for users with
`taxonomy.manage_tags`. Assignments are Email-owned and scoped to the selected account and message
as a compatibility step until the later account-scoped conversation model is delivered.

Deployment actions: deploy the code, run `php artisan migrate`, run `php artisan optimize:clear`,
and sync Email Knowledge with `php artisan knowledge:sync-docs --module=Email --push`. No new
permission seed, scheduler change, queue restart, or frontend build is required.

Risks: classification must not be confused with provider folders, provider flags, or Ticket
category/tag routing; users without mailbox Organize access must not mutate shared classification;
unknown tag creation must stay behind Taxonomy tag-management permission; and the account/message
compatibility scope must be migrated deliberately when full conversation classification arrives.

Automated verification completed 2026-08-12: migration
`2026_08_12_130000_create_email_message_classifications_table` ran in batch 76; after the More-menu
classification refinement, focused Mail classification/provider tests pass with 4 tests / 59
assertions and the full Email module feature test passes with 77 tests / 578 assertions. The
Taxonomy feature test previously passed with 6 tests / 43 assertions. `php artisan optimize:clear`,
Blade cache compilation, Email Knowledge sync, one default queue worker pass, and failed-job check all
completed without errors. These automated checks do not replace the human checks below.

Human checks:

- [x] Open a flagged mail item in `/tech/mail` and confirm the list row and reading pane make the
  provider flag visually obvious.
- [x] Confirm category/tag controls are hidden during normal reading, then open More actions and use
  **Category and tags** to show the editor.
- [x] Assign one existing Taxonomy category and at least two existing tags to a selected message and
  confirm the category/tag chips appear while editing and in the message list.
- [x] Clear the classification and confirm category/tag chips are removed without changing provider
  read state, provider flag state, folder placement, Ticket link, or personal `Unread for me`.
- [x] As a user with View but not Organize for the mailbox, confirm the classification editor is not
  visible in More actions and classification cannot be changed.
- [x] If using a user with `taxonomy.manage_tags`, enter a new tag name and confirm it is created in
  system Taxonomy and assigned to the mail. With an ordinary user, confirm unknown tag names are
  rejected.
- [x] Confirm existing provider `Flag in mailbox` / `Unflag in mailbox` still updates only the
  mailbox flag and does not add or remove categories/tags.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-010 - Email Mail Command Bar Triage Actions

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-command-bar-triage-actions.md`, `HR-2026-08-12-009`,
and `HR-2026-08-12-008`

Scope: The `/tech/mail` reading-pane command bar now has one visible personal `Mark read` action,
compact icon actions for Spam, Ticket, and Trash, and a More menu for provider read/unread,
flag/unflag, archive, and personal mark-unread when applicable. Spam reuses Email's spam rule/tag
action and archives the provider placement when a selectable Archive folder exists. Ticket reuses
Ticket's inbound-email creation/link action when the user has `ticket.create`; already linked email
shows an Open Ticket icon for users with `ticket.view`. Unimplemented Move-to-folder and generic
Add-rule controls remain hidden.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. No new migration, permission seed, scheduler
change, queue restart, or frontend build is required for this slice.

Risks: the compact icon bar must remain understandable with tooltips/screen-reader labels; personal
read state must not accidentally change provider `Seen`; provider read/flag/archive must remain
available in More; Spam partially depends on the account having a selectable Archive folder; Ticket
action must not appear without `ticket.create` and mailbox Organize access.

Automated verification: focused Mail command-bar, Spam, Ticket, and provider action regressions pass
with 6 tests and 51 assertions on Dev. The full Email feature suite passes with 73 tests and 532
assertions. The broader targeted Email, inbound automation, Notification inbound email, Integration,
and Warroom package passes with 147 tests and 1,138 assertions. PHP syntax checks and Blade cache
compilation pass. Email Knowledge sync queued a BookStack push; the default queue was empty when
checked afterward, `optimize:clear` passed, and failed-job checks report no failed jobs.

Human checks:

- [x] As a user with mailbox View/Organize/Send access, open `/tech/mail`, select an unread message,
  and confirm only one visible read action appears: `Mark read`.
- [x] Click `Mark read` and confirm it changes only `Unread for me`; provider `Seen` remains
  unchanged until More action is used.
- [x] Open More and confirm provider read/unread, flag/unflag, and archive actions are still
  available there when authorized.
- [x] Confirm Trash is visible as an icon-only action and still asks for confirmation before moving
  the provider placement to Trash.
- [x] Use the Spam icon on a harmless test message and confirm the spam rule/tag updates and the
  message archives when an Archive folder is available.
- [x] Use the Ticket icon on an unlinked test email as a user with `ticket.create`; confirm a Ticket
  is created/linked and the linked email then shows Open Ticket for users with `ticket.view`.
- [x] As a user without `ticket.create`, confirm the Ticket create icon is absent and direct action
  invocation is rejected.
- [x] Confirm generic Move-to-folder and Add-rule controls are not visible yet.
- [x] Check desktop and mobile widths and confirm icons, More menu, and composer do not overlap.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-009 - Email Mail Forward And Rich HTML Composer

Status: Reviewed
Added: 2026-08-12
Environment: Dev
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-forward-rich-html-composer.md`, and
`HR-2026-08-12-008`

Scope: The `/tech/mail` reading pane now exposes Forward alongside Reply for users with global Email
view/manage permission, effective mailbox View/Send access, and an active SMTP configuration. Reply
and Forward share one Mail-owned rich HTML composer with To, Cc, Subject, formatting controls, HTML
source mode, and up to five new attachments. Reply preserves In-Reply-To/References headers; Forward
starts a new outbound message with a forwarded-message body block and does not automatically reattach
original inbound attachments. Successful sends create idempotent outbound Email logs using distinct
`MAIL_REPLY_SENT` and `MAIL_FORWARD_SENT` codes.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and sync Email Knowledge with
`php artisan knowledge:sync-docs --module=Email --push`. No new migration, permission seed, queue
restart, scheduler change, or frontend build is required for this slice.

Risks: rich editor browser behavior must stay usable in the Livewire workspace; pasted or source-mode
HTML must be sanitized before SMTP; Forward must not leak or automatically resend unsafe original
attachments; users without mailbox Send access must not see or invoke sending controls; SMTP success
is logged but still does not prove provider Sent placement reconciliation.

Automated verification: focused Reply/Forward composer regressions pass with 5 tests and 67
assertions on Dev. The broader targeted Email, inbound automation, Notification inbound email,
Integration, and Warroom package passes with 144 tests and 1,119 assertions. PHP syntax checks,
Blade cache compilation, `optimize:clear`, Email Knowledge sync, BookStack default queue processing,
and failed-job checks pass.

Human checks:

- [x] As a user with mailbox View and Send access, open `/tech/mail`, select a message, and confirm
  both Reply and Forward are visible.
- [x] Open Reply, format text with bold/italic/list/link controls, switch to HTML mode and back, add
  one harmless attachment, send to an internal test recipient, and confirm the recipient sees the
  formatted message.
- [x] Open Forward, confirm To starts blank, Subject starts with `Fwd:`, and the original sender,
  date, subject, recipients, and body appear in the forwarded-message block.
- [x] Forward a message that has an original inbound attachment and confirm the original attachment
  is not automatically resent unless a new attachment is explicitly added.
- [x] Confirm outbound `email_logs` rows use `MAIL_REPLY_SENT` / `MAIL_FORWARD_SENT`, include
  account, source message, recipients, attachment count, RFC Message-ID, and idempotency key.
- [x] Confirm Reply and Forward do not change provider `Seen`, personal `Unread for me`, folders,
  Ticket links, Signals, or customer-portal visibility.
- [x] As a user with mailbox View but without Send, confirm Reply and Forward are absent and direct
  Livewire invocation shows the Send-access warning.
- [x] Check desktop and mobile widths and confirm the composer, toolbar, attachment badges, and
  message reader do not overlap.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-008 - Email Mail Reply Composer With Attachments

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mail-reply-compose-attachments.md`, and the accepted
2026-08-11 Email ADR set

Scope: first Mail reply composer inside `/tech/mail`. The update adds a Reply button for selected
mailbox placements when the actor has global Email view/manage permissions plus effective mailbox
View and Send access. The composer exposes To, Cc, Subject, Message, and up to five attachments.
Replies send synchronously through the selected mailbox account's SMTP settings, preserve
In-Reply-To/References where source headers exist, and write an idempotent outbound Email log. This
slice does not add drafts, Reply All, forward, new-message compose, provider Sent reconciliation,
Ticket evidence capture, API multipart sending, or automatic replies.

Deployment actions: deploy code, run `php artisan migrate --force` to apply
`2026_08_12_125000_add_email_log_idempotency_key`, run `php artisan optimize:clear`, restart queue
workers if Email polling/notifications run through workers, and sync Email Knowledge. Dev migration
already ran after the Mail workspace migration.

Risks: Reply must not appear for users without mailbox Send access; duplicate browser submits must
not send duplicate SMTP messages after success; attachment limits must remain bounded; reply headers
must not strip the source conversation chain; and this slice must not imply provider Sent placement,
Ticket capture, or portal publication before those later guarded slices exist.

Automated verification: focused reply regression passed with `HOME=/tmp php artisan test
app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter=reply` at 8 tests and 44 assertions.

Human checks:

- [x] Open `/tech/mail` as a user with View and Send grant for a real SMTP-enabled mailbox, select a
  message, and confirm the Reply button appears in the reading pane.
- [x] Open Reply and confirm To defaults to the source sender, Subject defaults to `Re: ...`, Cc is
  editable, and the message body can be entered without layout overlap on desktop and mobile widths.
- [x] Attach one small harmless file and send to an internal test recipient; confirm the recipient
  receives the body and attachment.
- [x] Confirm the outbound `email_logs` row has `code=MAIL_REPLY_SENT`, the selected account,
  source message, recipient metadata, attachment count, RFC Message-ID, and an idempotency key.
- [x] Confirm the source message remains in the same provider folder and that personal
  `Unread for me`, provider `Seen`, Ticket links, and Signal records do not change merely because a
  reply was sent.
- [x] Test a user with View but without Send grant and confirm the Reply button is absent.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-12-007 - Email Provider Mailbox Actions And API

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-provider-mailbox-actions-api.md`, and the accepted 2026-08-11
Email ADR set

Scope: provider-authoritative mailbox actions for `/tech/mail` and API parity. The update adds
explicit provider `Seen`/`Unseen`, `Flag`/`Unflag`, `Archive`, and normal provider `Trash` actions
for selected mailbox placements when the signed-in user has `email.inbox_manage` and an effective
mailbox Organize grant. The same operations are exposed through
`POST /api/v1/email/mailbox/placements/{placement}/operations`. Each operation is recorded through
the idempotent remote-operation ledger and updates local placement projection only after IMAP
acknowledgement. Permanent provider delete, arbitrary custom-folder move, bulk actions, and
retry/cancel UI remain out of scope.

Deployment actions: deploy code, run `php artisan optimize:clear`, and sync Email Knowledge. No
migration, permission seed, queue restart, scheduler change, or frontend build is required for this
slice.

Risks: provider state is shared across users and must stay clearly separate from personal `Unread for
me`; view-only mailbox grants must not get provider mutation controls or API success; archive/trash
must not pretend success before IMAP acknowledgement; and permanent delete/custom move controls must
not be visible until their separate guarded slices exist.

Automated verification: `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php`
passes with 65 tests and 446 assertions. Blade cache compilation and API route listing pass.

Human checks:

- [x] Open `/tech/mail` as an Organize-authorized technician, select a real provider-backed INBOX
  message, and confirm `Mailbox read` / `Mailbox unread`, `Mark read in mailbox` /
  `Mark unread in mailbox`, `Flag` / `Unflag`, and available `Archive` / `Move to Trash` controls are
  visible.
- [x] Use mailbox read/unread and confirm provider `Seen` changes while `Unread for me` remains a
  separate personal state.
- [x] Use flag/unflag and confirm the mailbox flag badge/control updates without changing personal
  unread state.
- [x] Archive or move a test message to Trash and confirm the message leaves the current Inbox view
  only after a success message, with no permanent delete control shown.
- [x] Test a view-only mailbox grant and confirm provider mutation controls are absent in UI and API
  calls return forbidden.
- [x] Call the API endpoint with a token that has `email.update` and confirm a succeeded
  `email_remote_operations` row is returned/recorded for an authorized placement.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein confirmed after reconnecting that the pending Mail review checks had been
approved before the power loss. Approval recorded from the Codex task message on 2026-08-12.

### HR-2026-08-12-006 - Email Livewire Mail Workspace And Personal State

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-livewire-mail-workspace-personal-state.md`, and the accepted
2026-08-11 Email ADR set

Scope: first visible technician Mail workspace under `/tech/mail`. The update adds module-owned
Livewire Mail content and sidebar components over provider mailbox placements, sidebar account/folder
navigation, search, sanitized reading pane, attachment metadata, safe Ticket links, operational
navigation to Mail, and Nexum-owned per-user opened/read state. Opening a message records an opened
receipt but does not clear `Unread for me` and does not set provider `Seen`; only explicit personal
read/unread controls change the signed-in user's state. The legacy `/tech/inbox` and Email Inbox API
remain available and scoped to unrouted INBOX work.

Deployment actions: deploy code, run `php artisan migrate --force` to apply
`2026_08_12_124000_add_email_message_user_states`, run `php artisan optimize:clear`, restart queue
workers if Email polling/notifications run through workers, and sync Email Knowledge. Dev migration
already ran in batch 74.

Risks: Mail must not leak private/shared mailbox content outside explicit mailbox grants; personal
`Unread for me` must not be confused with provider `Seen`; opening a message must not hide work for
the user or other users; legacy `/tech/inbox`, Ticket email routing, and inbound notification behavior
must remain unchanged.

Automated verification: `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php
--compact` passed at 61 tests and 407 assertions, including new
workspace authorization, folder filtering, opened receipt, and personal read/unread regressions.
`HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/InboundAutomationTest.php --compact`
passed at 14 tests and 81 assertions. `HOME=/tmp php artisan test
app/Modules/Warroom/Tests/Feature/WarroomDashboardTest.php --compact` passed at 6 tests and 32
assertions, and `HOME=/tmp php artisan test app/Modules/Warroom/Tests/Feature/WarroomMyDayTest.php
--compact` passed at 1 test and 10 assertions. `php artisan view:cache` and
`php artisan optimize:clear` completed successfully. Email Knowledge sync processed 1 chapter and 1
article with BookStack push queued; one default queue worker pass completed without error output.
After the sidebar refinement, the focused Mail workspace tests passed again at 3 tests and 35
assertions, the full Email feature file passed at 61 tests and 418 assertions, and Blade cache plus
optimize-clear completed successfully.

Human checks:

- [x] Open `/tech/mail` as a Tech user with access to at least one shared mailbox and confirm Mail is
  visible from Work navigation, top Work dropdown, Warroom lanes, and My Day actions.
- [x] Confirm Views, Mailboxes, and Folders are in the left sidebar, the content area only shows
  search, message list, and reading pane, and the sidebar `Unread` view has a count badge.
- [x] Confirm provider-unread badges remain labelled separately from personal unread state.
- [x] Open one unread message and confirm it remains `Unread for me` until the explicit `Mark read
  for me` action is used.
- [x] Mark the message read for the current user, refresh `/tech/mail`, and confirm it leaves the
  default unread view but still appears under `Inbox`.
- [x] Mark it unread again and confirm it returns to the default unread view without changing provider
  read state in the source mailbox.
- [x] Switch account and folder filters, including Sent/Archive/custom folders if discovered, and
  confirm only mailboxes granted to the user are visible.
- [x] Open a message linked to a Ticket and confirm the Mail pane shows a valid Ticket link when the
  Ticket exists.
- [x] Check desktop and mobile widths for the three-pane workspace and confirm search, lists, badges,
  and message body text do not overlap.
- [x] Re-open legacy `/tech/inbox` and confirm unrouted Inbox triage still behaves as before.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein approved the updated Mail workspace after the sidebar refinement and removal of
the redundant top badges.

### HR-2026-08-12-005 - Email Deterministic Rule Versions And API Foundation

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-deterministic-rule-versions-api-foundation.md`, and
`docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`

Scope: first implementation slice for deterministic Email rules and API. The update adds immutable
published rule versions, idempotent rule execution attempts, version backfill for existing Email
rules, automatic publishing on Admin create/update/toggle, runtime execution from the published
snapshot where available, compatibility for programmatic legacy rules without a version, a
read/preview API under `/api/v1/email/rules`, and `email.rules.read` API ability metadata.

Deployment actions: deploy code, run `php artisan migrate --force`, run `php artisan optimize:clear`,
restart queue workers if Email polling/notifications run through workers, and sync Email plus
Integration Knowledge. On Dev, `2026_08_12_123000_add_email_rule_versions_and_execution_attempts`
was applied after the admin sync/cache settings migration. The migration does not call providers,
send mail, re-run rules, import messages, create Tickets, or create Signals.

Risks: runtime must not read mutable rule form state when a published snapshot exists; repeated
processing must not replay successful side effects for the same message/rule-version; preview must
not mutate mail, tags, Tickets, Signals, or execution history; API token abilities must remain a
ceiling and must not bypass Email rule-management permission or mailbox View access; and current
preclassification, Signal handoff, Ticket-key linking, default Ticket routing, and account-scoped
rules must continue.

Automated verification: Dev migration status confirms
`2026_08_12_123000_add_email_rule_versions_and_execution_attempts` ran in batch 73. Focused
verification passes with `HOME=/tmp php artisan test
app/Modules/Email/Tests/Feature/EmailModuleTest.php --compact` at 58 tests and 383 assertions plus
`HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/InboundAutomationTest.php --compact` at
14 tests and 81 assertions. Broader `HOME=/tmp php artisan test app/Modules/Email/Tests
app/Modules/Notification/Tests --compact` passes with 120 tests and 709 assertions. Coverage includes
version publishing, idempotent execution attempts, read-only API preview, mailbox access, provider
folder placements, inbound automation, preclassification, trusted-auth validation, Signal handoff,
Ticket routing, and inbound Email notifications. `php artisan view:cache`, `php artisan
optimize:clear`, Email and Integration Knowledge sync with BookStack push queueing, two default queue
worker passes, `php artisan queue:failed`, and `git diff --check` pass. `git diff --check` reports
only pre-existing CRLF working-copy warnings in unrelated Client/Commercial/CustomerPortal/Integration
files and an older legal-document migration.

Human checks:

- [x] Open `/tech/admin/settings/email/rules` and confirm each Admin-saved rule shows a published
  version badge.
- [x] Create or update a harmless tag/archive test rule, save it, and confirm the rule remains
  understandable and scoped to selected shared/system Ticket-ingress mailboxes.
- [x] Use a test API token with `email.rules.read` and confirm `GET /api/v1/email/rules` returns
  version metadata without exposing account secrets or raw message content.
- [x] Preview a rule against an authorized message and confirm it reports would-run actions without
  changing message state, tags, Ticket links, Signals, or hit count.
- [x] Confirm a token without `email.rules.read`, a user without Email rule-management permission, or
  a user without mailbox View access is denied without mailbox enumeration.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein confirmed after reconnecting that the pending Mail review checks had been
approved before the power loss. Approval recorded from the Codex task message on 2026-08-12.

### HR-2026-08-12-004 - Email Admin Sync And Cache Settings Clarity

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md` and
`docs/feature-slices/2026-08-12-email-admin-sync-cache-settings-clarity.md`

Scope: Email admin config clarity under the Mail full-client RFC. The update renames
`/tech/admin/settings/email/config` to Email Sync & Cache Settings, groups the page around provider
sync, local mail cache, legacy cleanup, attachment import policy, and health, moves authserv and
receiving-hop controls into Advanced Automation Trust, changes fresh installs so global legacy
server cleanup is off by default, and introduces a `legacy_default` account cleanup policy so old
global Ticket-ingest cleanup can be preserved without making ordinary `local_only` IMAP accounts
delete provider mail.

Deployment actions: deploy code, run `php artisan migrate --force`, run `php artisan optimize:clear`,
restart queue workers if Email polling/notifications run through workers, and sync Email Knowledge.
On Dev, `2026_08_12_122000_preserve_legacy_email_cleanup_policy` was applied after the folder and
placement migration. The migration changes no schema and updates account cleanup policy only when
the legacy global cleanup setting was already enabled.

Risks: the page must not imply that Nexum replaces Proxmox Mail Gateway or DNS mail security; fresh
Mail-client configuration must not delete provider mail after import; existing installations that
intentionally used the old global cleanup switch must keep an explicit preserved policy; trusted
authserv/receiving-hop validation must still fail closed; and ordinary admins should not have to
understand header-authentication internals before configuring normal IMAP sync.

Automated verification: Dev migration status confirms
`2026_08_12_122000_preserve_legacy_email_cleanup_policy` ran in batch 72. Focused verification passes
with `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/InboundAutomationTest.php
app/Modules/Email/Tests/Feature/EmailModuleTest.php --compact` at 70 tests and 433 assertions,
including the config page copy, unchecked legacy cleanup default, `legacy_default` account policy
storage, paired trusted-auth validation, mailbox access, provider folder placements, and inbound
automation regressions. Broader `HOME=/tmp php artisan test app/Modules/Email/Tests
app/Modules/Notification/Tests --compact` passes with 118 tests and 678 assertions. `php artisan
view:cache`, `php artisan optimize:clear`, Email Knowledge sync with BookStack push queueing, one
default queue worker pass, `php artisan queue:failed`, and `git diff --check` pass. `git diff
--check` reports only pre-existing CRLF working-copy warnings in unrelated
Client/Commercial/CustomerPortal/Integration files and an older legal-document migration.

Human checks:

- [x] Open `/tech/admin/settings/email/config` and confirm the page reads as Sync/Cache settings,
  not a technical inbound-ingest dump.
- [x] Confirm Provider Sync, Local Cache & Legacy Cleanup, Attachment Import Policy, System Health,
  and Shortcuts are understandable without editing authserv/receiving-hop values.
- [x] Confirm Legacy server cleanup is off by default unless the environment intentionally had the
  old setting enabled.
- [x] Open Advanced Automation Trust and confirm the copy says Proxmox Mail Gateway, DNS, SPF, DKIM,
  and DMARC remain the normal mail-security boundary.
- [x] Open an Email account form and confirm Provider cleanup policy has clear choices, including
  Keep provider mail on server and Use legacy global cleanup switch.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein confirmed after reconnecting that the pending Mail review checks had been
approved before the power loss. Approval recorded from the Codex task message on 2026-08-12.

### HR-2026-08-12-003 - Email Server-Authoritative Folders And Placements

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-server-authoritative-folders-placements.md`,
`docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`, and the accepted Email ADR set

Scope: second implementation slice for the Mail full-client RFC. The update adds provider folder
records, mailbox placement records, a remote-operation ledger, folder discovery during IMAP polling,
forward-only per-folder baselines, safe multi-folder polling, Inbox-only legacy automation, and
explicit `/tech/inbox` plus Email Inbox API scoping so non-Inbox folders do not appear in the old
Inbox surface. Existing `email_messages` remain the compatibility message/content records during the
shadow period.

Deployment actions: deploy code, run `php artisan migrate --force`, run `php artisan optimize:clear`,
restart queue workers if Email polling/notifications run through workers, and sync Email Knowledge
docs. The migration is additive and does not call IMAP/SMTP, send mail, delete mail, mark mail read,
create Tickets, move provider messages, or re-run rules. On Dev,
`2026_08_12_121000_add_email_folders_placements_and_remote_operations` was applied in batch 71.

Risks: folder discovery must not overwrite stored UIDVALIDITY baselines before comparison; Sent,
Archive, Trash, Drafts, Junk, and custom folders must not run Ticket/Sales/Signal ingress; existing
Inbox/Ticket routing must remain compatible; the old `/tech/inbox` and Email Inbox API must not
start showing non-Inbox provider cache; raw/attachment paths must not collide when the same UID exists
in different folders; and remote operation rows must be idempotent because later provider mutation
workers will rely on those keys.

Automated verification: Dev migration status confirms the new folder/placement/remote-operation
migration ran in batch 71. `HOME=/tmp php artisan test app/Modules/Email/Tests
app/Modules/Notification/Tests --compact` passes with 117 tests and 667 assertions after migration,
including non-Inbox placement storage without inbound automation, Inbox UI/API exclusion of non-Inbox
messages, first folder discovery baseline without historical import, multi-folder bounded poll
payloads, changed folder UIDVALIDITY fail-closed behavior, remote-operation idempotency, and inbound
Email notification regressions. `php artisan view:cache`, `php artisan optimize:clear`, Email
Knowledge sync with BookStack push queueing, one default queue worker pass, `php artisan
queue:failed`, and `git diff --check` pass. `git diff --check` reports only pre-existing CRLF
working-copy warnings in unrelated Client/Commercial/CustomerPortal/Integration files and an older
legal-document migration.

Human checks:

- [x] Run the migration on Dev and confirm existing accounts have discovered `INBOX` folder rows and
  placement rows for existing messages.
- [x] Open `/tech/admin/settings/email/accounts` and confirm each account row shows folder count,
  INBOX UIDVALIDITY when known, and sync issue count without exposing secrets.
- [x] Trigger or wait for a poll and confirm first discovery baselines folders without importing old
  Sent/Archive/Trash history as new Inbox work.
- [x] Send or identify one genuinely new INBOX mail and confirm it still appears in `/tech/inbox` and
  existing Ticket routing/rules still behave as expected.
- [x] Confirm Sent/Archive/Trash/Drafts/custom folder messages do not appear in `/tech/inbox` or the
  Email Inbox API and do not create Tickets or Signals.
- [x] Simulate or identify a folder UIDVALIDITY change only in a non-INBOX folder and confirm the
  account remains usable while that folder shows a sync issue.
- [x] Confirm no remote provider move/read/delete UI controls are visible from this slice.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein confirmed after reconnecting that the pending Mail review checks had been
approved before the power loss. Approval recorded from the Codex task message on 2026-08-12.

### HR-2026-08-12-002 - Email Mailbox Access Foundation

Status: Reviewed
Added: 2026-08-12
Environment: Dev, then production beta after merge
Related: `docs/rfc/2026-07-04-mail-module-full-email-client.md`,
`docs/feature-slices/2026-08-12-email-mailbox-access-foundation.md`, and the four accepted
2026-08-11 Email ADRs

Scope: first implementation slice for the Mail full-client RFC. The update adds Email account kinds
for shared, personal, and system mailboxes; one owner for personal mailboxes; explicit user-level
View, Organize, and Send mailbox grants; a `ticket_ingress_enabled` account policy; rule-to-account
scope records; scoped Tech Inbox and Email Inbox API reads/actions; manual polling limited to
mailboxes the actor can organize; inbound notification recipient filtering by the same mailbox access
decision; and safe personal-mail runtime behavior that stores mail without running legacy
classification, Email rules, Sales routing, or Ticket routing. Existing shared/system accounts and
rules are backfilled to preserve current ticket-first behavior for already configured accounts.

Deployment actions: deploy code, run `php artisan migrate --force`, run `php artisan optimize:clear`,
restart queue workers if Email polling/notifications run through workers, and sync Email and
Integration Knowledge docs. On Dev, `php artisan migrate` applied
`2026_08_12_120000_add_email_mailbox_access_foundation`. The migration is additive and does not call
IMAP/SMTP, send mail, delete mail, mark mail read, create Tickets, or re-run rules.

Risks: incorrect grant backfill could hide existing shared inbox work or expose mailbox content to
users without an explicit grant; personal accounts must not inherit legacy Ticket ingress or global
rules; Admin account configuration must remain possible without becoming content access; API tokens
must remain ceilings rather than full mailbox access; inbound notifications must not leak personal
mail existence; and existing supplier-order preclassification plus Ticket-key/header/default Ticket
routing must continue for explicit shared/system intake accounts.

Automated verification: `HOME=/tmp php artisan test app/Modules/Email/Tests
app/Modules/Notification/Tests` passes with 111 tests and 633 assertions, including scoped Inbox
UI/API, personal no-Ticket-ingress, Admin grant persistence, account-scoped rules, spam rule scoping,
existing Ticket routing regressions, and no notification for an inbox subscriber without mailbox
grant. `php artisan view:cache`, `php artisan optimize:clear`, and `git diff --check` pass; the diff
check only reports unrelated pre-existing CRLF warnings outside this Email slice. Email Knowledge
sync processed 1 chapter and 1 article, Integration Knowledge sync processed 1 chapter and 6
articles, and the Integration module feature test passes with 47 tests and 458 assertions after the
API ability text update. Both Knowledge sync commands queued BookStack push jobs, queue workers were
run for the queued pushes, and `queue:failed` reports no failed jobs.

Human checks:

- [x] Run the migration on Dev and confirm existing Email accounts show as Shared with Ticket ingress
  enabled and visible grant counts.
- [x] Open `/tech/admin/settings/email/accounts`, edit an existing shared account, save without
  retyping IMAP/SMTP passwords, and confirm the account still saves.
- [x] Add or edit a shared mailbox, grant View only to one user and View + Organize to another, then
  confirm the first can open messages but cannot spam/delete/poll while the second can.
- [x] Create a personal mailbox with an owner and confirm Ticket ingress/global defaults are off after
  save.
- [x] Confirm a different user with `email.inbox_view` but no mailbox grant cannot see or open the
  personal mailbox message in UI or API.
- [x] Open Email Rules, create a rule, and confirm the mailbox scope is selected from shared/system
  Ticket-ingress accounts rather than typed manually.
- [x] Confirm a rule scoped to one shared mailbox does not run for another mailbox.
- [x] Confirm inbound notification settings do not send an Inbox notification for a mailbox the
  subscriber cannot view.
- [x] Confirm existing Ticket-key/header/default Ticket routing still works for the current support
  mailbox.
- [x] Check desktop and mobile widths for the Email account form, Email rule form, and Inbox list.

Reviewer: Svein
Reviewed date: 2026-08-12
Result / notes: Svein confirmed after reconnecting that the pending Mail review checks had been
approved before the power loss. Approval recorded from the Codex task message on 2026-08-12.

### HR-2026-08-11-004 - Sales Quotes / CPQ Completion

Status: Reviewed
Added: 2026-08-11
Environment: Dev, then production beta after merge
Related: GitHub Discussion #170, `docs/rfc/2026-08-11-sales-cpq-completion.md`,
`docs/adr/2026-08-11-sales-cpq-accepted-snapshot-boundary.md`, and
`docs/feature-slices/2026-08-11-sales-cpq-core.md`

Scope: Sales-owned CPQ completion for structured quoting and customer acceptance. The update adds
customer-selectable option groups, required/recommended/default line behavior, quantity bounds,
quote-level and line-level acknowledgements, immutable accepted snapshots, configurable CPQ approval
policy, `sales.quote.approve`, Admin Sales rules and Quote Templates settings, reusable quote
templates/bundles, template snapshots copied into quote versions, public and Customer Portal
accept/decline/expiry behavior, lifecycle activities, and Sales-owned conversion-plan
status/reference tracking. Admin Sales Quote Templates uses the same split-list and focused
create/edit pattern as Ticket Workflows, with controlled opportunity type, customer segment, catalog
source, and option-group selectors so administrators do not need to type internal source IDs or fixed
variable values. Scoped automation can create the same reusable quote templates through the Sales
quote-template API. Ticket `Add cost/item` now hides the Sales quote panel until planned scope or a
Sales context exists, and routes quote-required Storage items or threshold-triggered manual costs to
planned scope instead of reserving stock or creating actual cost. After customer acceptance, the
protected `ticket_quote_delivery_automation` system actor processes accepted Ticket quote lines into
safe internal delivery records: reservations for available stock, draft purchase needs for orderable
shortages, and pending Ticket costs for custom lines. Sent quote changes supersede the old public
acceptance link and create a new draft revision. New quote-required Ticket scope after acceptance
creates a separate additional quote draft instead of changing the accepted quote. Accepted Ticket
quotes can be voided with a required reason only while downstream delivery records are still safely
reversible.

Deployment actions: deploy code, run `php artisan migrate --force`, run `php artisan optimize:clear`,
restart queue workers if the target environment uses queued quote/customer portal mail delivery, and
sync Sales, Ticket, and Integration Knowledge docs. Dev already ran `php artisan migrate`, applying
`2026_08_11_140000_add_sales_cpq_core` in batch 67 and
`2026_08_11_141000_add_sales_quote_templates` in batch 68, followed by `php artisan optimize:clear`.
The Ticket/Storage quote-routing follow-up adds migration
`2026_08_11_142000_add_customer_quote_policy_to_storage_items`, applied on Dev in batch 69. The first
accepted Ticket quote after deploy creates or refreshes the hidden
`ticket_quote_delivery_automation` system actor with least-privilege Ticket/Storage permissions.

Risks: selectable options must not let customers drop required lines or submit quantities outside
seller limits; acknowledgement text must be snapshotted exactly; approval must block risky sends until
approved; template changes must not mutate sent or accepted quote versions; expired quotes must not be
accepted; conversion plans for non-Ticket downstream domains must not silently create or alter
Economy, Commercial, Asset, Task, ServiceVisit, or future Project records; Ticket planned scope must
use only the accepted customer-selected snapshot; and true accepted-quote follow-up workflows, such
as creating separate implementation Tickets automatically, remain a separate automation slice.
Quote-required Storage items and threshold-triggered manual costs must not create reservations,
Picking List rows, or billable actual costs before customer acceptance. Accepted Ticket quote
automation must never pick stock, send supplier orders, post receipts, or mark billable Economy
orders automatically. A superseded quote must not remain customer-acceptable. A voided accepted
quote must preserve audit history and must not cancel picked stock, non-pending billing, placed
purchase orders, shipments, or receipts silently.

Automated verification: `HOME=/tmp php artisan test
app/Modules/Sales/Tests/Feature/SalesModuleTest.php` passes with 25 tests and 418 assertions,
including the Admin Sales Quote Templates split-editor regression, Sales quote-template API
regression, and superseded sent-quote acceptance regression.
`HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php` passes with 122
tests and 903 assertions, including the accepted Ticket quote card placement, sent-quote
superseding, additional quote after acceptance, accepted-quote void, draft purchase-need reversal,
and irreversible-delivery block regressions.
`HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketWorkflowV3Test.php` passes with 25
tests and 278 assertions, including the customer-acceptance auto-processing regression and the cost-entry
API quote-scope routing regression. `HOME=/tmp php artisan test
app/Modules/Storage/Tests/Feature/StorageModuleTest.php` passes with 23 tests and 363 assertions.
`HOME=/tmp php artisan test` passes with 1281 tests and 10865 assertions.
`HOME=/tmp php artisan view:cache` and `HOME=/tmp php artisan optimize:clear` passed. PHP syntax
checks passed for the CPQ actions/controllers/models and all three CPQ/quote policy migrations.
`HOME=/tmp php artisan knowledge:sync-docs --module=Ticket --module=Storage --module=Sales --push`
reported 3 chapters, 19 articles, 0 skipped, and queued the BookStack push. A follow-up Ticket
Knowledge sync after the accepted-quote card title change reported 1 chapter, 11 articles, 0 skipped,
and queued the BookStack push. The accepted Ticket quote auto-processing update synced Ticket
Knowledge again with 1 chapter, 11 articles, 0 skipped, and queued the BookStack push. The
sent-revision/additional-quote/void update synced Sales, Storage, and Ticket Knowledge again with
3 chapters, 19 articles, 0 skipped, and queued the BookStack push.

Human checks:

- [x] Open Admin Sales Rules and confirm approval thresholds can be viewed and saved.
- [x] Open Admin Sales Quote Templates and confirm it shows a compact template list, not all template
  editors at once.
- [x] Create or edit one active quote template and confirm the edit page is focused, customer text is
  collapsed by default, and adding lines/acknowledgements happens in separate collapsed sections.
- [x] Confirm opportunity type, customer segment, catalog source, and option group are controlled
  choices with no manual Source ID field.
- [x] Confirm the seeded `Quote Templates` default template exists and can be edited without creating
  duplicates.
- [x] Confirm API management exposes `sales.quote_templates.read` and
  `sales.quote_templates.manage` for scoped automation tokens.
- [x] Confirm a user with Sales settings rights can delete a test quote template from the edit page,
  and that deleted templates disappear from the template list without changing existing quote
  versions or accepted snapshots.
- [x] Add default customer text, at least one grouped line, and one acknowledgement to the template.
- [x] Create a Sales opportunity, prepare a quote, apply the active template, and confirm lines,
  groups, customer text, and acknowledgements are copied into the draft.
- [x] Add a required line, an optional/recommended add-on, and a quantity-selectable line; send a
  standard quote and confirm the public quote page shows only customer-safe prices and live totals.
- [x] Try accepting without required acknowledgements and confirm acceptance is blocked.
- [x] Accept with a selected add-on and changed allowed quantity; confirm Tech shows the accepted
  snapshot and conversion-plan rows with the selected amount.
- [x] Confirm a risky quote is blocked before send, can be approved only by a user with
  `sales.quote.approve`, and can then be sent.
- [x] Decline a sent quote and confirm Sales timeline, status, and Customer Portal/public state are
  coherent.
- [x] Open an expired sent quote and confirm it is marked expired and cannot be accepted.
- [x] Add quote-required Ticket scope after a quote has been sent but before acceptance, and confirm
  the old quote is shown as superseded, the old public acceptance link is blocked, and a new draft
  revision contains the previous scope plus the new line.
- [x] From a Ticket planned-scope quote, accept only selected quote lines and confirm only those
  planned lines become approved Ticket scope.
- [x] After accepting a Ticket quote, confirm available Storage items become reservations/pending
  Ticket costs, orderable out-of-stock items become draft purchase needs without vendor order
  sending, and custom lines become pending Ticket costs.
- [x] Add new quote-required Ticket scope after a Ticket quote has been accepted and confirm the
  accepted quote remains unchanged, a separate `Additional customer approval` draft contains only
  the new line, and the earlier accepted quote history sits below Activity once delivery is complete.
- [x] Void an accepted Ticket quote before picking/order sending and confirm the reason is audited,
  safe pending costs/reservations/draft purchase needs are reversed, workflow quote evidence is
  invalidated, and the quote is labelled voided rather than deleted.
- [x] Try voiding an accepted Ticket quote after picked stock, non-pending billing, a non-draft
  purchase order, shipment, or receipt and confirm Nexum blocks the action with a clear reason.
- [x] Confirm processed accepted Ticket quote lines no longer show manual Convert/Purchase buttons,
  while a blocked line still shows a retry action and audit reason.
- [x] Confirm a fully processed accepted Ticket quote card moves below Activity, while unfinished
  accepted quote delivery stays above Activity and Nexum relationship remains the bottom card.
- [x] Open the linked Sales quote after Ticket quote acceptance and confirm Ticket-origin conversion
  plan rows are marked completed with references to the created Ticket cost or purchase need.
- [x] Open an ordinary Ticket with no planned scope and confirm no Sales quote panel is visible.
- [x] Use Ticket `Add cost/item` on a Storage item marked `Requires accepted quote before use` and
  confirm it creates planned scope, does not reserve stock, and then shows the customer approval
  panel.
- [x] Use Ticket `Add cost/item` below the quote threshold and confirm the normal actual cost or
  Storage reservation path still works.
- [x] Set the Ticket quote cost threshold and confirm a manual cost at or above the threshold becomes
  planned scope through both browser and API.
- [x] Update a conversion-plan status/reference/note in Sales and confirm no downstream record is
  created automatically by Sales.
- [x] Check desktop and mobile widths for Tech quote modal, public quote, and Customer Portal quote
  and confirm controls and totals do not overlap.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-11-003 - One Responsive Nexum PWA Final Browser Acceptance

Status: Reviewed
Added: 2026-08-11
Environment: trusted HTTPS Dev vhost, then production beta before final release
Related: GitHub Discussion #169, `docs/rfc/2026-07-04-one-responsive-nexum-pwa.md`,
`docs/deployment/dev-https-pwa-vhost.md`, `HR-2026-07-24-001`, and `HR-2026-08-11-002`

Scope: final human acceptance for Nexum as one responsive installable PWA. The implemented code
foundation covers one shared Tech shell with mobile offcanvas navigation, PWA metadata on internal,
portal, and public entry surfaces, online-first service-worker behavior, static offline fallback,
mobile My Day, and Notification-owned Web Push/read-sync slices. This review does not approve
offline writes, native apps, a separate mobile frontend, or unfinished workflow controls.

Deployment actions: create a trusted HTTPS Dev vhost for `/var/Projects/tdPSA/public`, align
`APP_URL` and secure-session settings with that host, run `php artisan optimize:clear`, restart queue
workers, verify `/sw.js`, `/manifest.json`, and `/offline.html` over HTTPS, and then run the browser
checks below. Use `docs/deployment/dev-https-pwa-vhost.md` for the exact vhost checklist.

Risks: browser PWA behavior cannot be accepted from an untrusted certificate; mobile layout defects
can hide required actions even when desktop tests pass; Service Worker scope or cache mistakes can
break navigation or cache private data; and Web Push checks must still respect the narrower privacy
and lifecycle reviews in `HR-2026-07-24-001` and `HR-2026-08-11-002`.

Automated verification: the System PWA platform contract test passes with 3 tests and 34 assertions.
It verifies PWA head and viewport coverage across the Tech shell, guest shell, Customer Portal,
Booking, Intake, public quote, and public contract entry surfaces; the Tech shell's shared desktop
and mobile navigation contract; and the shared online-first service worker's fetch, push, click, and
message handlers. The combined PWA/My Day/Web Push foundation run passes with 23 tests and 179
assertions, and the System security header suite passes with 3 tests and 19 assertions.
Browser/device verification remains blocked until the trusted HTTPS Dev vhost is available.

Human checks:

- [x] Open the new HTTPS Dev host in Chrome/Edge desktop and confirm the certificate is trusted and
  the page has no mixed-content errors.
- [x] Confirm `/sw.js`, `/manifest.json`, and `/offline.html` load over HTTPS without
  authentication.
- [x] Install Nexum as a PWA where the browser supports it and confirm the app name, icon, start URL,
  standalone display, and theme color are coherent.
- [x] At desktop width, confirm the Tech shell keeps ordinary sidebar/workspace navigation and the
  existing routes.
- [x] At 360x780, 390x844, 430x932, 768x1024, 1024x768, and desktop widths, confirm the Tech shell
  has no incoherent text/action overlap and the mobile hamburger/offcanvas navigation is usable.
- [x] Confirm notifications, user/profile controls, breadcrumbs/page title context, and primary
  page actions remain reachable on mobile.
- [x] Open `/tech/my-day` on mobile width and confirm assigned Tickets, Tasks, and Calendar items are
  visible, link to the same ordinary routes, and do not expose an offline-write promise.
- [x] Open representative Customer Portal, Booking, Intake, public quote, and public contract pages
  and confirm they keep viewport/PWA behavior without exposing internal navigation or data.
- [x] After loading the app once, simulate offline navigation and confirm Nexum shows only the static
  offline fallback for failed navigations and does not show cached private application pages.
- [x] Confirm no separate mobile Nexum route, mobile-only permission bypass, native-app prompt, or
  unfinished offline/sync workflow is exposed as finished behavior.
- [x] Complete or reference the Web Push device lifecycle checks in `HR-2026-07-24-001`.
- [x] Complete or reference the inbound Email/customer-reply delivery checks in `HR-2026-08-11-002`.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: Svein explicitly approved the complete GitHub #202 navigation scope on 2026-08-25
after the current Dev Warroom verification passed 9 tests / 53 assertions. The broader responsive
PWA, offline, Web Push, and cross-surface checks above remain Pending and are not approved by this scoped confirmation.

### HR-2026-08-11-002 - Inbound Email Web Push Delivery And Source Read-Sync

Status: Reviewed
Added: 2026-08-11
Environment: Dev
Related: GitHub Discussion #169, `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`,
`docs/feature-slices/2026-07-23-web-push-internal-email-alerts.md`, and
`docs/feature-slices/2026-07-24-web-push-read-sync-rollout-hardening.md`

Scope: Notification now owns canonical per-EmailMessage/user delivery identities, the two finished
event types Customer reply on my Tickets and New inbound Email, explicit Web Push preferences for
implemented payloads, default-off Web Push and preview controls, privacy-safe push payloads,
extension-ready account/queue subscription scopes, Notification-owned open redirects, source read
synchronization from authorized Ticket and Email views, and shared service-worker closure of visible
notifications by validated Nexum tags.

Deployment actions: deploy the code, run `php artisan migrate --force`, run `php artisan
optimize:clear`, restart queue workers, and keep `WEBPUSH_ENABLED=false` in production until browser
and end-to-end review passes. Verify the `email,default` queue worker and the active Email poller or
scheduler path in the target environment before enabling inbound Web Push.

Risks: inbound notification retries must not duplicate alerts; Ticket owner and inbox subscriber
paths must not broadcast to unauthorized users; lock-screen payloads must not expose bodies,
attachments, client identity, or full sender addresses; push/read-sync must never mark Ticket,
TicketMessage, or Email operational unread state; and visible service-worker notification closure
must not accept arbitrary cross-origin or malformed tags.

Automated verification: focused Notification inbound Web Push tests pass with 6 tests and 33
assertions. Web Push foundation tests pass with 19 tests and 135 assertions. Notification system
tests pass with 20 tests and 71 assertions. Email inbound automation tests pass with 13 tests and 74
assertions. The complete Email feature file passes with 46 tests and 287 assertions, and the Email
IMAP unit tests pass with 2 tests and 5 assertions. The complete Ticket feature file passes with 112
tests and 810 assertions. Migration `2026_08_11_130000_add_inbound_email_notification_delivery_identity`
ran on Dev in batch 66; `optimize:clear`, Pint, PHP syntax checks, `git diff --check`, and
Knowledge sync for Email, Notification, and System completed. The queued BookStack push job was
processed, and no `PushPendingKnowledgeToBookStack` job or failed job remained.

Human checks:

- [x] Register one supported browser/PWA device and confirm the Web Push device inventory still
  exposes only safe device summary fields.
- [x] Enable Web Push for Customer reply on my Tickets and confirm no browser permission prompt
  appears until the device Enable action is clicked.
- [x] Send or process one safe inbound customer reply that links to a Ticket owned by the reviewer;
  confirm exactly one in-app notification and one browser push are created.
- [x] Re-run the same inbound processing path and confirm no duplicate notification or push appears.
- [x] Click the push and confirm Nexum focuses or opens the linked Ticket after normal auth.
- [x] Open the linked Ticket directly after ignoring a push and confirm the matching notification is
  marked read without clearing `tickets.is_unread` or `ticket_messages.read_at`.
- [x] Enable New inbound Email for an authorized inbox reviewer, process one unlinked inbound Email,
  and confirm the push opens the Email inbox detail.
- [x] Link that inbox Email to a Ticket after the notification exists, click the old notification,
  and confirm it redirects to the linked Ticket and marks only the matching notification read.
- [x] Confirm an unauthorized user or user without the relevant setting does not receive the Ticket
  owner or inbox/triage notification.
- [x] Confirm default push text is generic, preview shows only sender display name plus truncated
  subject when enabled, and no body, attachment name, client identity, full email address, endpoint,
  key, token, or VAPID secret appears.
- [x] Confirm existing PWA install, ordinary navigation, static-asset caching, and offline fallback
  still work after the service-worker message-handler update.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-08-11-001 - Intake Final Routing And Review Completion

Status: Reviewed
Added: 2026-08-11
Environment: Dev
Related: GitHub Discussion #166, `docs/rfc/2026-07-04-public-inquiry-forms.md`, and
`docs/feature-slices/2026-08-11-intake-final-routing-review.md`

Scope: Intake public inquiry forms now support final lifecycle states, form purpose/language/scope,
explicit routing modes, submission form/field snapshots, client-scoped matching, direct Sales,
Ticket, and Task handoff through owning module actions, staff review outcomes, and existing-record
linking. Uploaded files remain Intake-owned and are referenced by target records without silent file
copying.

Deployment actions: deploy the code, run `php artisan migrate --force`, run `php artisan
optimize:clear`, and sync Intake Knowledge documentation. No permission seed, frontend build, queue
restart, or scheduler change is required unless the target environment already has stale caches or
separate Knowledge sync scheduling.

Risks: public forms with legacy `active` status must continue to open until migrated to
`published`; automatic routing must not create target records when the configured policy says manual
review or known-client-only without a match; client-scoped forms must not match another Client; and
attachments must stay downloadable from protected Intake while not being copied into Ticket, Task,
Sales, or Signal payload paths.

Automated verification: the focused Intake feature suite passes with 21 tests and 168 assertions,
covering route ownership, form settings, conditional fields, snapshots, scoped matching, Sales,
Ticket, and Task routing, skipped routing, review outcomes, existing-record linking, spam handling,
Signal payloads, and attachment ownership. The focused Knowledge repository-doc sync test confirms
Intake docs are registered. The focused Integration regression confirms BookStack push `last_error`
messages are truncated safely. Dev BookStack push jobs that failed before the truncation fix were
retried, and `queue:failed` reports no failed jobs.

Human checks:

- [x] Create or edit an Intake form, set status `Published`, purpose/language, Client scope, Ticket
  target, and `Auto-route known clients`; confirm the public URL opens.
- [x] Submit the form as a known Client and confirm a Ticket is created, linked from the Intake
  submission, and contains the submitted values and attachment names.
- [x] Submit a similar global form as an unknown Client with `Auto-route known clients`; confirm it
  stays in Intake as routing skipped and does not create a Ticket.
- [x] From a new submission, manually route to Sales and Task and confirm each target link opens.
- [x] Link an existing Client and then link an existing Ticket or Sales opportunity; confirm Client
  matching and target status update are reflected in the submission events.
- [x] Mark submissions as reviewed, spam, duplicate, rejected, and archived and confirm the status,
  reason, reviewer, and event history are clear.
- [x] Download an Intake attachment from the protected submission page and confirm no copied
  attachment appears on the created Ticket/Task/Sales record unless a separate handoff was approved.
- [x] Check the form builder and submission detail page at desktop and mobile widths for readable
  Bootstrap layout and non-overlapping controls.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-013 - Production Ticket External-Message API Route

Status: Reviewed
Added: 2026-07-29
Environment: Production and Dev
Related: GitHub issue #195 and `docs/deployment/ticket-api-route-verification.md`

Scope: Ticket retains its module-owned `POST /api/v1/tickets/{ticket}/external-messages` route with
the `tickets.update` Sanctum ability. Regression coverage verifies route registration, a 403 response
for an authenticated token without the ability, idempotent internal-note storage for an authorized
token, and absence of customer email. The deployment runbook records the safe production checks.

Deployment actions: deploy the Ticket changes, run `php artisan optimize:clear`, and verify the route
list from the deployed application. No migration, permission seed, queue restart, scheduler change,
frontend build, or Knowledge sync is required for this regression check.

Risks: production may run an older build or stale route cache; a broad or incorrect token may hide
the intended 403 boundary; a smoke-test payload must remain internal and idempotent; and tokens,
customer content, and production identifiers must not enter logs, screenshots, or GitHub comments.

Automated verification: the focused regression suite passes with 3 tests and 14 assertions. The
complete Ticket feature suite passes with 165 tests and 1,278 assertions. An unauthenticated
production probe returns 401 rather than the previously reported 404, confirming that the route is
currently registered behind authentication.

Human checks:

- N/A (accepted deviation, 2026-08-25): Svein accepted the documented production 401/422 diagnosis and the automated 403/201/200 regression coverage as sufficient. The write-producing production smoke was not executed and is not represented as executed.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes: Svein approved #195 and accepted the omitted production write smoke as a documented deviation.

### HR-2026-07-29-012 - AI Privacy Governance And Coordinator Worklog API

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #178, `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`,
and the 2026-07-14 AI/coordinator Feature Slices.

Scope: Integration owns a privacy-first installation maximum, provider/model/agent/workload
governance, policy evaluator, deterministic privacy gateway, read-only workload-bound tokens,
metadata-only access audit, finite retention cleanup, and Admin configuration. Existing outbound AI
chat paths pass through the evaluator and gateway. Report exposes minimized technician/time-entry
data, while Ticket and Task own their stale-record endpoints. Default responses use workload-scoped
aliases and exclude natural names, customer names, titles, descriptions, messages, notes, invoice
text, attachments, rankings, credentials, and secrets.

Deployment actions: deploy the code, run `php artisan migrate --force`, `php artisan db:seed
--class=PermissionSeeder --force`, `php artisan db:seed --class=RoleSeeder --force`, and
`php artisan optimize:clear`. Restart queue and scheduler workers so AI execution and
`ai.access.cleanup` use the new policy. Sync Knowledge documentation for Integration and Report.
Review the closed installation defaults before enabling any processing or creating a token.

Risks: the default policy intentionally disables AI until an Admin reviews it; missing/expired
provider or model approvals must block external calls; direct external mode must never bypass the
recorded gates; privacy washing reduces risk but is not anonymization; aliases must not expose source
IDs; ordinary broad API keys remain unchanged and need deliberate review; and coordinator responses
must never include names or free text.

Automated verification: the focused AI/coordinator governance suite passes with 7 tests and 62
assertions; AI telemetry passes with 5 tests and 72 assertions; AI/rightbar Integration regression
passes with 15 tests and 97 assertions; API-key least-privilege tests pass with 4 tests and 102
assertions. Lead Intelligence passes with 33 tests and 277 assertions, Marketing with 33 tests and
482 assertions, and Signal with 27 tests and 157 assertions after all AI callers were aligned with
the explicit policy gate. The complete application suite passes with 940 tests and 7,462 assertions;
the post-format affected-module run passes with 190 tests and 1,949 assertions. PHP syntax, Pint,
Blade compilation, Git diff checks, and migration status pass, with all migrations applied on Dev.

Human checks:

- [x] Open Admin -> Integrations -> Privacy & Coordinator and confirm the initial policy is AI off,
  external off, privacy gateway on, direct external off, local-only, and aggregate maximum.
- [x] Save a policy revision and confirm revision number, reviewer, time, and reason are visible in
  persisted history.
- [x] Try to approve a provider/model/agent or workload wider than the installation maximum and
  confirm it is rejected with a useful explanation.
- [x] With one approved test provider/model, confirm local-only and privacy-relay requests work and
  an incomplete, disabled, rejected, or expired approval blocks the request without external egress.
- [x] Submit synthetic email, phone, token, and password patterns through the safe privacy-gateway
  test path and confirm they are removed; confirm a failed rewrite/post-validation fails closed.
- [x] Create one narrow coordinator workload/token and call all four API families. Confirm aliases
  are stable within the workload and no name, customer name, title, description, message, note,
  billing text, attachment, ranking, credential, or secret appears.
- [x] Confirm date range, page size, rate, expiry, network, missing-scope, write-scope, and revoked
  token failures use stable reason codes and create metadata-only audit rows.
- [x] Confirm ordinary API keys start with all scopes unchecked, empty selection fails, full access
  needs a separate confirmation, and existing broad keys are only flagged for review.
- [x] Run the retention cleanup with expired test audit/payload records and confirm only records past
  the configured finite retention are deleted.
- [x] Review the Admin page and API error responses at desktop/mobile widths and with an Admin lacking
  the new management permissions.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-011 - Quote Billing Cadence And Customer Copy

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #180, `docs/rfc/2026-07-29-quote-billing-cadence-presentation.md`, and
`docs/feature-slices/2026-07-29-quote-billing-cadence-presentation.md`

Scope: Sales quote lines have explicit one-time, monthly, quarterly, or annual billing cadence with a
compatible monthly backfill for existing recurring lines. One shared presentation contract supplies
separate ex-VAT, VAT, and inc-VAT groups to Tech preview, public quote, Customer Portal, PDF, and quote
email. The draft editor exposes the existing version-owned customer copy, places introduction/scope
before prices and assumptions/exclusions/next steps after prices, and preserves copy and cadence when a
new revision is created.

Deployment actions: deploy the code, run `php artisan migrate --force`, `php artisan optimize:clear`,
and `php artisan knowledge:sync-docs --module=Sales --no-interaction`. Restart queue workers so quote
email jobs load the new presentation dependency. No permission seed, scheduler change, or frontend build
is required. The migration updates only an exact unchanged default quote email template; customized
templates must add the documented new variables manually if desired.

Risks: recurring prices must not appear as one-time totals; VAT must stay within the correct cadence;
legacy recurring lines must classify as monthly; sent/accepted versions must remain immutable; customer
copy must be escaped; and customized email templates must not be overwritten.

Automated verification: the complete Sales feature suite passes with 18 tests and 274 assertions. The
Customer Portal quote/contract suite passes with 2 tests and 55 assertions. PHP syntax and Blade cache
compilation pass. The focused Ticket-to-Sales PDF regression passes with 1 test and 19 assertions, and
the complete Ticket feature suite passes with 165 tests and 1,278 assertions.

Human checks:

- [x] Create a draft with 5,200 NOK one-time and 551 NOK/month and confirm Tech preview shows separate
  groups with separate ex-VAT, VAT, and inc-VAT values.
- [x] Add quarterly and annual lines and confirm their labels and totals use NOK/quarter and NOK/year.
- [x] Save introduction, solution/scope, assumptions, alternatives, exclusions, and next steps; confirm
  the first pair appears before prices and the remaining text after prices.
- [x] Compare Tech preview, public quote, authenticated Customer Portal, generated PDF, and received
  quote email and confirm grouping, terminology, totals, and copy agree.
- [x] Send and revise the quote; confirm the sent version is immutable and the new draft retains all
  copy and cadence values.
- [x] Open an existing quote without custom text and an existing recurring-contract line; confirm both
  remain readable and the recurring line appears as monthly.
- [x] Check the editor and all customer surfaces at desktop and mobile widths.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-010 - Sales Opportunity Lost And Reopen Workflow

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #181 and `docs/rfc/2026-07-29-sales-opportunity-lost-workflow.md`

Scope: Sales has dedicated Tech UI and scoped API actions for marking an opportunity lost and
reopening it. The workflow requires a loss reason, preserves an optional internal note in the
activity audit, resets forecast and follow-up fields, removes only the matching future generated
Sales calendar event, keeps history and quotes, and reopens to a selected active status without
restoring the previous follow-up. Lost opportunities are hidden from the default pipeline but remain
available through search and the Lost status filter.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Sales --no-interaction`. No migration, backfill, queue
restart, scheduler change, permission seed, or frontend build is required.

Risks: a generic status update must not bypass the workflow; a manual, past, or unrelated calendar
event must not be deleted; lost, not-qualified, and no-quote outcomes must remain distinct; and
reopening must not silently recreate a follow-up.

Automated verification: the focused Tech and API workflow tests pass with 2 tests and 55 assertions;
the complete Sales feature suite passes with 18 tests and 274 assertions. Blade compilation and Git
diff checks pass.

Human checks:

- [x] From an active opportunity with a future follow-up, open Mark as lost and confirm a reason is
  required while the internal note is optional.
- [x] Submit the action and confirm status Lost, probability/weighted value zero, lost date/reason,
  cleared follow-up, and a new activity entry without losing prior activities or quotes.
- [x] Confirm the matching future generated event disappears from Calendar, while a past, manually
  created, or unrelated event remains untouched.
- [x] Confirm the opportunity is absent from the default Sales pipeline, but appears through search
  and the Lost status filter.
- [x] Reopen it to an active status and confirm loss fields clear, probability/weighted value are
  recalculated, and no old follow-up is recreated.
- [x] Confirm Not qualified and No quote allowed remain separate statuses and are not treated as Lost.
- [x] Check the lost alert and both modals at desktop and mobile widths.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-009 - Documentation Template Selection

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #179 and `docs/rfc/2026-07-29-documentation-template-selection.md`

Scope: The Tech Documentation create flow keeps automatic selection when a category has exactly one
active template and presents a separate, required template choice when several active templates are
available. The selected active template determines the rendered fields and stored schema snapshot;
inactive and wrong-category template IDs are rejected.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Documentation --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, or frontend build is required.

Risks: the wrong schema must not be selected silently; crafted requests must not cross category or
inactive-template boundaries; and the established one-template flow must remain quick and backward
compatible.

Automated verification: the complete Documentation feature suite passes with 10 tests and 106
assertions. Blade compilation and Git diff checks pass.

Human checks:

- [x] Choose a category with one active template and confirm the create form opens directly with its
  fields and saves successfully.
- [x] Choose a category with two or more active templates and confirm a compact template step appears
  before the Documentation fields.
- [x] Choose each template in turn and confirm the visible fields match that exact template.
- [x] Save a record and confirm its category, template, entered data, and captured template snapshot
  are correct.
- [x] Confirm inactive templates cannot be selected and changing a submitted template ID to another
  category produces a validation error.
- [x] Check category and template selection at desktop and mobile widths.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-008 - Calendar Ownership Rollout Tests And Knowledge

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #142, plus
`docs/feature-slices/2026-07-29-calendar-ownership-rollout-tests-knowledge.md`

Scope: Regression coverage and operational documentation complete the Calendar ownership rollout.
The Calendar README, repository Knowledge article, TODO register, Feature Slice index, human-review
register, and public-safe website handoff describe the shipped metadata, badges, type indicators,
filters, privacy boundaries, and mobile behavior without claiming creator/participant `My events` or
event-level responsibility.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: documentation must match the real owner-based filter semantics; automated tests must not
replace manual visual/private-data review; and public website copy must remain unpublished until the
relevant human checks are complete.

Automated verification: the focused Calendar feature suite passes with 22 tests and 218 assertions.
The complete Dev Laravel suite passes with 921 tests and 7,158 assertions. Calendar Knowledge sync
processes one module, one chapter, and one article with no skips. Targeted Pint, PHP syntax, Blade
compilation, cache clearing, compiled-view permissions, and Git diff checks pass.

Human checks:

- [x] Compare the Calendar README and Knowledge article with day, week, month, and list behavior and
  confirm the documented badges, groups, filters, empty state, and dense-month link are accurate.
- [x] Confirm the documentation clearly states that `Only mine` uses Calendar ownership and that a
  creator/participant `My events` filter plus event-level responsibility remain out of scope.
- [x] Confirm private/confidential masking and server-side visible-calendar permission boundaries
  are described accurately.
- [x] Approve the public-safe website handoff before any customer-facing publication.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-007 - Calendar Mobile Readability And Dense Month Drill-Down

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #141, plus
`docs/feature-slices/2026-07-29-calendar-mobile-readability.md`

Scope: Month and week use a keyboard-focusable, touch-scrollable Calendar region with stable column
widths. Event title, time, owner, and type identities keep independent boundaries. Month renders five
events per day and then an accessible `+N more` link to the filtered day view. Nested controls do not
trigger the day-cell event-creation action.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, permission
seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: horizontal scrolling must remain discoverable and usable; badges and long titles must not
overlap; the `+N more` target must retain filters; and nested links must not open the create panel.
The final in-app browser attempt on 2026-07-29 was blocked before rendering by the known Dev
self-signed certificate (`ERR_CERT_AUTHORITY_INVALID`), so authenticated visual checks remain
manual.

Automated verification: dense-month coverage passes inside the 22-test, 218-assertion Calendar
feature suite. It verifies the five-event limit, accessible `+N more`, filtered day link, focusable
scroll region, and minimum grid width.

Human checks:

- [x] At desktop width, compare month and week with long titles, owner badges, and non-personal type
  badges; confirm there is no overlap or unexpected wrapping.
- [x] At narrow/mobile width, keyboard-focus and touch-scroll month/week horizontally and confirm
  the day columns and event identity remain readable.
- [x] Put at least seven events on one date, open month view, and confirm five rows plus `+2 more`.
  Open the link and confirm day view preserves active Calendar, ownership, search, and sort state.
- [x] Confirm selecting `+N more` does not open the event-create panel, while selecting an empty part
  of the day cell still does.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-006 - Calendar Ownership Filters And Only Mine

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #140, plus
`docs/feature-slices/2026-07-29-calendar-ownership-filters.md`

Scope: The Tech Calendar sidebar groups visible calendars into Mine, People, Team, Shared/global,
Resources, and External/system. Backed groups expose server-derived counts. `Only mine` selects only
calendars owned by the signed-in user through `owner_type` and `owner_id`, takes priority over group
selections, and remains independent from event creator/participant data. Ownership and explicit
Calendar filters intersect, persist across navigation/search/availability actions, and expose clear
and empty states.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: filter parameters must never broaden visible calendars; an empty intersection must not fall
back to all events; `Only mine` must not include a team event merely because the viewer created it;
and existing date, Calendar, search, sort, and availability state must remain compatible.

Automated verification: ownership-filter coverage passes inside the 22-test, 218-assertion Calendar
feature suite. It verifies owner-versus-creator semantics, combined groups, explicit Calendar
intersection, unauthorized hidden calendars, backed controls, and the empty state.

Human checks:

- [x] Confirm sidebar groups and counts match visible personal, other-person, team, shared/company,
  resource, and external/system Calendars for the signed-in technician.
- [x] Enable `Only mine` and confirm only the technician-owned personal Calendar remains, while an
  event created by that technician on a team Calendar is excluded.
- [x] Combine Team and Resources, then combine a group with individual Calendar checkboxes; confirm
  both conditions must match and an empty intersection shows the explicit empty state.
- [x] Switch views, navigate dates, search, sort, use Find Time, and open a dense-month day; confirm
  ownership state persists. Use Clear ownership filters and confirm ordinary Calendar/search state
  remains.
- [x] Manipulate a URL with a hidden Calendar ID and confirm its name and events remain absent.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-005 - Calendar Non-Personal Type Indicators

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #139, plus
`docs/feature-slices/2026-07-29-calendar-type-indicators.md`

Scope: Day, week, month, and list show a separate compact type badge for non-personal Calendars.
Shared, team, company/global, absence, shift, resource, system, external, and fallback types use
stable visible abbreviations with full accessible labels. Personal events avoid a redundant type
badge. Owner, type, and Calendar color remain separate signals on normal and masked events.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: abbreviations must remain understandable through their full accessible labels; extra badges
must not crowd event content; type must come from the server metadata contract; and a masked private
event must never expose its real title or other details through badge text or tooltip.

Automated verification: type-indicator coverage passes inside the 22-test, 218-assertion Calendar
feature suite. It covers representative shared, team, company/global, resource, and external badges,
accessible labels, compact text, and private resource masking.

Human checks:

- [x] Compare shared, team, company/global, absence/shift, resource, and external/system events in all
  four Calendar views and confirm the short type marker is consistent and understandable.
- [x] Hover or use assistive technology and confirm each marker exposes the complete Calendar type
  without relying on color.
- [x] Confirm a personal event shows its owner badge without a redundant type badge.
- [x] As a technician without private-detail access, confirm a private non-personal event retains
  only `Busy`, time, safe owner/color/type context, and no real event details.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-004 - Calendar Owner Badges And Accessible Color Identity

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #138, plus
`docs/feature-slices/2026-07-29-calendar-owner-badges-accessible-color.md`

Scope: The Tech Calendar day, week, month, and list views use one shared compact owner badge. It
combines owner initials or a stable type fallback, a bordered Calendar-color swatch, and an
accessible full owner/type label. Event title containers truncate independently from badge and time.
Month and week retain readable widths on narrow screens through horizontal scrolling, and list
Calendar identity combines name with the same swatch rather than depending on background color.
Private/confidential blocks retain only safe owner/color/type/time signals and masked `Busy` detail.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or external synchronization action
is required.

Risks: compact badges must remain readable without crowding event time/title; arbitrary Calendar
colors need a visible border and text alternative; long content must not overlap adjacent events;
month/week horizontal overflow must remain usable on mobile; and private/confidential badge context
must never include masked event details. The completed #139-#141 type, filter, and mobile slices
reuse the same metadata contract without duplicating owner derivation in Blade or JavaScript.

Automated verification: the focused Calendar feature suite passes with 19 tests and 174 assertions.
It covers all four views, owner initials, color swatches, accessible owner/type labels, title layout,
list Calendar identity, and private-detail masking. The complete Dev Laravel suite passes with 918
tests and 7,114 assertions. Calendar Knowledge synchronization processes one chapter and one article
with no skips. Blade view caching, cache clearing, targeted Pint, and Git diff checks pass.

Human checks:

- [x] Open month view with events from at least two technician calendars and one ownerless
  team/shared/resource Calendar. Confirm ownership is recognizable within one second from the short
  badge text plus swatch.
- [x] Hover or inspect the badge with assistive technology and confirm the full owner identity and
  Calendar type are available without relying on color.
- [x] Compare the same events in day, week, month, and list. Confirm the badge identity and Calendar
  color remain consistent in every view.
- [x] Use long event titles and adjacent events. Confirm badge, time, and title do not overlap and
  that the title truncates cleanly.
- [x] At a narrow mobile width, verify day/list badge readability and horizontal month/week
  navigation without compressed or overlapping badges.
- [x] As a technician without private-detail access, open a private/confidential event in all four
  views. Confirm the badge, color, type, time, and `Busy` remain visible, while real title,
  description, location, participants, meeting link, and integration details remain absent.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-003 - Calendar Ownership View Metadata And Private Single-Event API

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134, #136, and #137, plus
`docs/feature-slices/2026-07-29-calendar-ownership-view-metadata.md`

Scope: Calendar derives stable owner identity, initials/badge, color, normalized type, ownership
group, and viewer-owned state from the calendar container rather than the event creator. The
contract is available to visible Calendar list, range event, recurring occurrence, and single-event
API consumers while retaining the existing overlay badge key. Visibility remains server-scoped by
`CalendarOverlayQuery`. The single-event API now applies `CalendarVisibility` before exposing
private/confidential title, description, location, meeting URL, participants, creator, or external
integration identifiers.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or external synchronization action
is required.

Risks: creator data must not be mistaken for ownership; ownerless team/shared calendars need stable
fallbacks; metadata must not expand the visible calendar set; private single-event responses must
not leak details or participant/integration identifiers; owners and explicitly permitted viewers
must retain legitimate private-detail access; and the completed #138-#141 UI/filter work must
consume this contract rather than reimplement ownership in Blade or JavaScript.

Automated verification: the focused Calendar feature suite passes with 18 tests and 127 assertions.
It covers owner-versus-creator semantics, viewer groups, ownerless team fallback, hidden-calendar
boundaries, Calendar and range API metadata, private single-event masking, and owner detail access.
The complete Dev Laravel suite passes with 917 tests and 7,067 assertions. Calendar Knowledge
synchronization processed one chapter and one article with no skips. Cache clearing, PHP syntax,
targeted Pint, and Git diff checks pass.

Human checks:

- [x] Call `GET /api/v1/calendars` as a technician and confirm the personal calendar reports the
  technician's owner label/initials, `calendar_type: personal`, `ownership_group: mine`, and
  `is_owned_by_viewer: true`.
- [x] View a visible calendar owned by another technician and confirm the owner remains that
  calendar owner even when the event creator is someone else; the group must be `people`.
- [x] Inspect ownerless team, shared/company, resource, and system/external calendars and confirm
  each has a readable calendar-name fallback, stable short badge, and the documented group.
- [x] Confirm a hidden calendar without owner/default/access visibility is absent from Calendar
  list and range event responses.
- [x] As another technician, request a private/confidential event through both range and
  single-event endpoints. Confirm the response keeps time plus safe owner/color/type signals but
  shows `Busy`, null detail/integration fields, and no participants.
- [x] Request the same private event as the calendar owner or an explicitly authorized private
  viewer and confirm legitimate details and participants remain available.
- [x] Open day, week, month, and list Calendar views and confirm the existing ownership badges and
  event rendering still consume the metadata contract. Detailed badge layout and accessibility are
  reviewed separately in `HR-2026-07-29-004`.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-002 - Ticket API Portal Publication And Idempotent Customer Completion

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #194, `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`,
`docs/adr/2026-07-29-ticket-api-message-idempotency.md`, and the three 2026-07-29 Ticket API
Feature Slices

Scope: Trusted API coordinators can publish an eligible client Ticket through the same lock-safe,
one-way action as the technician UI, then create one real public technician customer reply through
the normal Ticket message, outbound email, portal notification, relationship sync, event,
first-response, and workflow path. `reply_intent: send_solution` records normal solution facts so
the coordinator can inspect decisions, perform an allowed Resolved transition, and call the existing
Close action. Dedicated token abilities and existing user/domain permissions both apply. Ticket
message idempotency is enforced by a Ticket/key database unique index and payload fingerprint;
matching retries return the original result, while changed actor/payload and soft-deleted results
return HTTP 409. Inbound `external-messages` behavior remains isolated.

Deployment actions: deploy the code, run `php artisan migrate --force`, `php artisan optimize:clear`,
`php artisan l5-swagger:generate`, `php artisan knowledge:sync-docs --module=Ticket --no-interaction`,
and `php artisan knowledge:sync-docs --module=Integration --no-interaction`. Migration
`2026_07_29_120000_add_api_idempotency_to_ticket_messages` already ran on Dev in batch 54. Existing
API tokens do not receive the two new abilities automatically. No frontend build, permission seed,
scheduler change, or new queue worker is required; the existing Ticket reply queue worker must run
where actual customer email is enabled.

Risks: a bad retry boundary could send duplicate customer email or notification; an over-broad
token could expose customer-facing side effects; a cross-client or inactive Contact must never be
used; publication must remain one-way; matching retries after workflow changes must remain safe;
and `external-messages` must not become a solution or outbound technician-reply shortcut.

Automated verification: the focused Ticket customer-completion API suite passes with 4 tests and
53 assertions. TicketModuleTest, PortalTicketTest, and TicketWorkflowV3Test pass with 146 tests and
1,156 assertions. The suite verifies a single queued reply job and uses fakes, so no real customer
email was sent. Migration/status, PHP syntax, targeted Pint, route discovery, OpenAPI generation,
and the complete publish/reply/decisions/Resolved/Close API sequence pass on Dev. The Integration
API Management suite also passes with 43 tests and 343 assertions. Knowledge synchronization
processed one Ticket chapter with 11 articles and one Integration chapter with 5 articles, with no
skips. The complete Dev Laravel suite passes with 915 tests and 7,014 assertions.

Human checks:

- [x] Create or update a least-privilege Dev API token with `tickets.portal.publish`,
  `tickets.reply_customer`, `tickets.workflow.read`, and `tickets.actions`; confirm a token missing
  each relevant ability receives 403 and the authenticated user also needs the normal domain
  permissions.
- [x] Using a clearly internal test customer/contact, publish an Unpublished Ticket and confirm the
  first response reports `published_now: true`, a repeat reports `false`, and only one publication
  event and portal notification exist. Confirm `portal_visible: false` cannot unpublish it.
- [x] Send a safe customer reply with one idempotency key while outbound delivery is intercepted or
  directed only to the internal test mailbox; confirm one public user-authored message and one email
  delivery/queue record, then repeat the identical request and confirm no duplicate side effect.
- [x] Reuse that key with changed body or another user and confirm HTTP 409. Delete a separate test
  reply and confirm its old key remains reserved with HTTP 409.
- [x] Try an Unpublished Ticket, internal Ticket, inactive/missing-email Contact, cross-client
  Contact, closed Ticket, and workflow-blocked action; confirm each is rejected without message,
  email, notification, event, or workflow changes.
- [x] Send `reply_intent: send_solution`, read workflow decisions, perform only the reported allowed
  Resolved transition, then close as completed. Confirm the audit/history and lifecycle timestamps.
- [x] Sync one inbound `external-messages` test payload containing attempted solution metadata and
  confirm it retains external authorship, strips workflow-driving metadata, and sends no normal
  technician reply email.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-29-001 - Published Default For Manually Created Client Tickets

Status: Reviewed
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #191, `docs/rfc/2026-07-28-manual-client-ticket-published-default.md`,
`docs/rfc/2026-07-04-customer-portal-foundation.md`, and
`docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`

Scope: Published is now the clean-install, missing-setting, and invalid-setting fallback for new
manually created client Tickets. A valid administrator choice of Published or Unpublished remains
authoritative, and the visible Ticket create control preselects that setting while preserving a
technician override after validation errors. Published client Tickets use the existing visibility
timestamp, actor, audit event, and Customer Portal notification path. Internal Tickets without a
Client remain portal-hidden even when Published is submitted. Existing Tickets are not changed, the
initial description remains an internal note, and no customer-reply email job is added.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Ticket --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, or frontend build is required. Valid stored Ticket
portal-policy settings must not be replaced during deployment.

Risks: new client Tickets can expose customer-sensitive content immediately when Published is the
effective default; administrators and technicians must notice the visible choice. A manipulated
request must not publish an internal Ticket; a validation failure must not discard an Unpublished
override; existing hidden Tickets must remain hidden; and the default change must not create a
public initial message or queue `SendTicketReplyEmail`. The existing `portal_ticket_created`
notification continues to honor recipients' already configured notification channels.

Automated verification: the focused Ticket portal suite passes with 10 tests and 94 assertions. The
complete Ticket feature suite passes with 158 tests and 1211 assertions, and the complete Customer
Portal feature suite passes with 20 tests and 229 assertions. Pint passes for the two touched PHP
files. PHP syntax, `git diff --check`, Blade cache compilation, and compiled-view group-write
permissions pass. Repository Knowledge synchronization processed one Ticket chapter and eleven
articles without skips. The Ticket create and Ticket Settings URLs return the expected unauthenticated
login redirect through the local Dev HTTPS virtual host.

Human checks:

- [x] With no Ticket portal-policy row on a disposable environment, open Ticket create and confirm
  Published is selected while both Published and Unpublished remain visible.
- [x] In Ticket Settings, save Unpublished and confirm a new manual Ticket form preselects
  Unpublished. Save Published again and confirm a new form preselects Published.
- [x] Trigger a validation error after choosing Unpublished and confirm the form returns with
  Unpublished still selected.
- [x] Create a client Ticket with the Published default and confirm the customer can see it, the
  Ticket records the correct portal visibility timestamp and technician, and the established portal
  notification appears according to the customer's existing channel preferences.
- [x] Confirm the Published Ticket's initial description remains an internal note and that creation
  does not send or queue a separate customer-reply email.
- [x] Override one new client Ticket to Unpublished and confirm it remains absent from the Customer
  Portal, emits no portal-publish notification, and blocks Reply to contact until later publication.
- [x] Create an internal Ticket without a Client while Published is selected and confirm it remains
  portal-hidden and no customer notification is emitted.
- [x] Confirm an existing Unpublished Ticket remains hidden after deployment and still supports the
  established one-way Publish action.
- [x] Check Ticket Settings and Ticket create at desktop and narrow/mobile widths and confirm the
  visibility control and explanatory text are conspicuous and readable.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-28-005 - Ticket Internal Note Solution Toggle

Status: Reviewed
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #190, `docs/rfc/2026-07-28-ticket-internal-note-solution-toggle.md`, and
`docs/rfc/2026-06-03-ticket-solution-policy.md`

Scope: The Ticket Add message composer now exposes only Reply to contact and Internal note as
message types. When Ticket solution policy permits it, Internal note shows a compact Mark as
solution switch that is off by default. Both ordinary and solution-marked entries persist as
internal notes; a marked note reuses the established solution metadata and workflow action while
remaining customer-hidden. Notify technician stays available in both states. The server forces
internal visibility, rechecks solution policy, ignores the switch for customer replies, and retains
a policy-checked legacy `internal_solution` input alias for stale forms without rendering it.
Historical messages and timeline Mark as solution actions remain compatible.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Ticket --no-interaction`. No migration, backfill, permission seed,
queue restart, scheduler change, or frontend build is required. Actual technician-notification
email delivery continues to depend on the existing configured queue worker and Ticket email account.

Risks: a manipulated switch or legacy alias must not bypass disabled policy; a solution-marked note
must never become public or send customer email; switching message type must not alter customer
Reply intent; Notify technician must remain available and preserved; and current workflow solution
requirements, automatic triggers, historical messages, and timeline solution actions must not
regress.

Automated verification: the complete Ticket feature suite passes with 155 tests and 1183 assertions.
The focused composer, solution policy, technician notification, customer-email isolation, legacy
alias, historical timeline action, and Workflow v3 run passes with 9 tests and 68 assertions. Pint
passes for the three touched PHP files. PHP syntax, `git diff --check`, Blade cache compilation, and
compiled-view group-write permissions pass. Repository Knowledge synchronization processed one
Ticket chapter and eleven articles without skips. The Ticket show URL returns the expected
unauthenticated login redirect through the local Dev HTTPS virtual host.

Human checks:

- [x] Open a Ticket where both actions are available and confirm Message type contains Reply to
  contact and Internal note, with no separate Internal solution option.
- [x] Select Internal note and confirm Mark as solution is visible, off by default, and displayed
  together with Notify technician at desktop and narrow/mobile widths.
- [x] Save an ordinary Internal note and confirm it stays internal, is not marked as solution, and
  does not satisfy a workflow solution requirement by itself.
- [x] Enable Mark as solution, select a technician, and save. Confirm the note stays internal, is
  visibly marked as the selected solution, satisfies the expected workflow requirement or trigger,
  and keeps the technician notification. Confirm no customer email or Customer Portal message is
  created; record actual technician inbox receipt separately if email delivery is tested.
- [x] Disable internal solution notes in Ticket Settings and confirm the switch disappears. Verify a
  manipulated request cannot mark an Internal note as the solution, then restore the setting.
- [x] Confirm a public Reply to contact with Send solution still follows the ordinary customer email
  and workflow path, without being affected by the internal-note switch.
- [x] On an existing Ticket, use the timeline Mark as solution action for an eligible historical
  Internal note and confirm the established behavior still works.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-28-004 - Client Summary Layout And Notes Autosave

Status: Reviewed
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #189 and `docs/rfc/2026-07-28-client-summary-notes-autosave.md`

Scope: The Client Summary card now presents Client number and the existing short metadata in a
responsive Bootstrap grid with three columns on wide screens, two on medium screens, and one on
small screens. Notes use the full card width. Users with `client.update` receive a debounced
Livewire textarea that saves only Notes, reports Saving while unconfirmed, and shows Saved only
after successful server persistence. The save path rechecks permission on every request, preserves
text on handled failure, stores a blank value as null, and renders read-only Notes for other users.
The existing full Client settings workflow remains available and compatible.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Clients --no-interaction`. No migration, backfill, permission seed,
queue restart, scheduler change, or frontend build is required.

Risks: a manipulated Livewire request must not bypass `client.update`; Saved must never appear for a
failed or still-pending write; concurrent editors continue to use last-confirmed-save-wins behavior;
and the responsive layout must preserve status, billing email, optional N-able RMM mapping, gear
action, and the Client workspace below the Summary card.

Automated verification: the complete Clients feature suite passes with 32 tests and 293 assertions.
The focused Summary and Notes autosave runs pass with 5 tests and 69 assertions, including update,
blank/null, read-only, forced unauthorized save, deleted-Client failure, responsive markup, and
existing settings-link coverage. Pint passes for both new PHP files. PHP syntax, `git diff --check`,
Blade cache compilation, and compiled-view group-write permissions pass. Repository Knowledge
synchronization processed one Clients chapter and one article without skips. The Client show URL
returns the expected unauthenticated login redirect through the local Dev HTTPS virtual host.

Human checks:

- [x] Open a Client with Client number, organization number, billing email, format, and active RMM
  mapping; confirm all Summary values, status, RMM state, and the gear action remain visible.
- [x] At wide, medium, and narrow/mobile widths, confirm the short metadata changes from three to
  two to one column and Notes remains full width without crowding the Client workspace.
- [x] As a user with Client update access, edit Notes and confirm Saving appears while the text is
  unconfirmed, Saved appears only after completion, and a page reload shows the persisted text.
- [x] Clear Notes completely and confirm the empty value remains after reload; then enter a new
  value and confirm the old error or status does not remain stale.
- [x] As a user without Client update access, confirm Notes is read-only and no textarea or save
  state is exposed. Attempting a manipulated update must not change the Client.
- [x] Open the existing Client settings form, update Notes there, save, and confirm the Summary
  component shows the new value so both edit workflows remain compatible.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-28-003 - Client Workspace Tickets Tab

Status: Reviewed
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #188 and `docs/rfc/2026-07-28-client-workspace-tickets-tab.md`

Scope: The Client profile now includes a Tickets tab for technicians with `ticket.view`. Ticket
owns the access-aware query, while Clients owns the workspace placement and compact Bootstrap
table. The tab shows all non-deleted open and closed Tickets linked directly to the selected Client,
uses one result set for the count and rows, links to the existing Ticket detail page, and keeps the
required Assets, Sites, Contacts, Tickets, Tasks, Time, Contracts, Signals, Custom Fields order.

Deployment actions: deploy the code, run `php artisan optimize:clear`, run `php artisan
knowledge:sync-docs --module=Clients --no-interaction`, and run `php artisan knowledge:sync-docs
--module=Ticket --no-interaction`. No migration, backfill, permission seed, queue restart, scheduler
change, or frontend build is required.

Risks: Client access must not disclose Ticket data without `ticket.view`; the count must match the
rendered rows; open and closed Client Tickets must remain visible while soft-deleted and other-Client
Tickets remain absent; and tab reordering must not break the existing Client workspace panes.

Automated verification: the complete Client feature file and all Ticket feature tests pass with 182
tests and 1424 assertions. The focused access, scoping, ordering, count, link, and soft-delete run
passes with 4 tests and 60 assertions. PHP syntax, `git diff --check`, Blade cache compilation, and
compiled-view group-write permissions pass. Repository Knowledge synchronization processed one
Clients article and eleven Ticket articles without skips. The Client Tickets URL returns the
expected unauthenticated login redirect through the local Dev HTTPS virtual host.

Human checks:

- [x] As a technician with `client.view` and `ticket.view`, open a Client with at least one open and
  one closed Ticket and confirm the Tickets badge matches the two displayed rows.
- [x] Confirm the workspace order is Assets, Sites, Contacts, Tickets, Tasks, Time, Contracts,
  Signals, and Custom Fields where Custom Fields is configured.
- [x] Confirm each row shows Ticket key, subject, status, priority, queue, owner, and updated time,
  and that both the key and row open the correct existing Ticket detail page.
- [x] Confirm a Ticket from another Client and a soft-deleted Ticket do not appear for the selected
  Client.
- [x] As a technician with `client.view` but without `ticket.view`, confirm the Tickets tab, count,
  and Ticket subjects are all absent.
- [x] Check desktop and narrow/mobile widths and confirm the tab strip and compact table remain
  usable without breaking the other Client tabs.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-28-002 - Contact Portal Invitation Create Override

Status: Reviewed
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #185, `docs/rfc/2026-07-04-customer-portal-foundation.md`,
`docs/feature-slices/2026-07-28-contact-portal-invitation-override.md`, and follow-up from
`HR-2026-07-15-001`

Scope: Contact Settings now owns a safe default-off option for selecting `Send customer portal
invitation` on Contact create. A user with `customer_portal.invite` can override that initial state
for one create action. Contact and its Client/Site relations are saved transactionally before the
existing CustomerPortal action validates scope, identity, active access, and pending invitations,
then records audit and queues one Viewer invitation. Ordinary Contact edit never exposes or resends
the option.

Deployment actions: deploy the code; run `php artisan optimize:clear`; run `php artisan
knowledge:sync-docs --module=Contact --no-interaction`; and run `php artisan knowledge:sync-docs
--module=CustomerPortal --no-interaction`. No migration, permission seed, queue restart, scheduler
change, or frontend build is required. Actual invitation email delivery continues to depend on the
existing configured queue worker and outbound system email setup.

Risks: an unintended global-on default could send invitations unless the per-Contact choice remains
clear; users without `customer_portal.invite` must not reveal or force the option; ordinary edits
must not resend; Contact and invitation writes must roll back together on validation failure; and
email identity, active access, duplicate invitation, Client, and Site guards must stay authoritative.

Automated verification: the complete Contact and CustomerPortal foundation suites pass with 49
tests and 336 assertions. The focused default-on/off, edit, permission, and Site-scope regressions
pass with 5 tests and 29 assertions. Pint, PHP syntax, `git diff --check`, Blade compilation, and
compiled-view group-write permissions pass. Repository Knowledge synchronization processed one
Contact and one CustomerPortal chapter/article without skips. HTTPS smoke tests for Contact create and Contact
Settings return the expected unauthenticated login redirects. The complete Laravel suite was not
run for this focused slice.

Human checks:

- [x] Open Contact Settings and confirm `Send customer portal invitation by default` is clear,
  saves both on and off, and remains off when no explicit setting has been stored.
- [x] Enable the global default, open Contact create as a user with `customer_portal.invite`, and
  confirm the create-only switch starts on. Turn it off, save a valid client Contact, and confirm no
  invitation is created or sent.
- [x] Disable the global default, turn the create switch on for a valid Contact with email and an
  active Client/Site, and confirm exactly one pending Viewer invitation uses that scope.
- [x] Confirm the queued invitation email uses the ordinary Customer Portal template and reaches
  the intended test inbox; record inbox receipt separately from the application queue result.
- [x] Try missing email, inactive Client, wrong Site scope, and existing active portal access;
  confirm each is blocked without leaving a partial new Contact or duplicate access.
- [x] Open Contact create as a user without `customer_portal.invite`; confirm the switch is absent
  and a manipulated Livewire request cannot create an invitation.
- [x] Edit a Contact while the global default is on; confirm the switch is absent and saving does
  not create or resend an invitation.
- [x] Check Contact Settings and Contact create at desktop and mobile widths.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-28-001 - Booking Hours And Technician Routing

Status: Reviewed
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #184, `docs/rfc/2026-07-04-online-booking-calendar-availability.md`,
`docs/feature-slices/2026-07-28-booking-hours-and-technician-routing.md`, and follow-up from
`HR-2026-07-15-001`

Scope: Booking service settings can limit public availability to an optional daily opening window,
use company or technician-profile working hours, and route to one fixed technician, an automatic
eligible pool, or a customer-selected eligible technician. Automatic mode exposes only the union of
available times, persists one concrete technician internally, and may safely reroute to another
eligible free technician during staff confirmation. Calendar remains responsible for personal
calendars and conflict checks. Booking create/edit use the shared Page Header Back action, and the
honeypot field is explained under advanced spam protection.

Deployment actions: deploy the code; run `php artisan migrate --force`; run `php artisan
optimize:clear`; run `php artisan knowledge:sync-docs --module=Booking --no-interaction`; and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No permission seed, queue
restart, scheduler change, or frontend build is required.

Risks: automatic mode must not expose eligible technician identities; customer choice must stay
inside the configured active pool; the public opening window must intersect rather than replace the
selected working-hours policy; concurrent requests may select the same unreserved time until staff
confirmation; and confirmation must never create an event on a calendar that has become busy.

Automated verification: the Booking and Calendar feature suites pass with 31 tests and 171
assertions. The complete Knowledge article feature suite passes with 39 tests and 355 assertions,
including the new Booking repository-documentation sync regression. Migration
`2026_07_28_120000_add_routing_and_opening_hours_to_booking_service_settings` ran on Dev in batch
53. Pint, PHP syntax, `git diff --check`, Blade compilation, and the public `/booking` HTTPS smoke
test pass. Repository Knowledge synchronization processed one Booking chapter/article and one
Calendar chapter/article without skips. The focused suites were run on Dev; the complete Laravel
suite was not run for this slice.

Human checks:

- [x] Open Booking create and edit and confirm Back is in the shared Page Header while Save remains
  at the bottom of the form.
- [x] Confirm the spam field is under Advanced spam protection with a plain-language explanation.
- [x] Configure a fixed-technician service with a 10:00-15:00 public window and company hours;
  confirm public times stay inside both limits and existing Calendar conflicts disappear.
- [x] Switch to technician-profile hours and confirm a disabled day or shorter profile day changes
  the public slots without changing company Calendar settings.
- [x] Configure automatic routing with two eligible technicians whose free periods differ; confirm
  the public page shows the combined time union without either technician name or identifier.
- [x] Submit an automatic request and confirm one eligible technician is stored internally. Make
  that technician busy before confirmation and confirm Booking uses another eligible free
  technician; make every eligible technician busy and confirm no Calendar event is created.
- [x] Configure customer choice and confirm only active configured technicians are shown. Select
  each technician and confirm the times reflect that technician's availability.
- [x] Tamper with the submitted customer-choice technician ID and confirm the request is rejected.
- [x] Confirm a fixed-technician request still follows the existing received, staff-confirmed,
  Calendar-event, and customer-email workflow.
- [x] Check Booking admin create/edit and the public booking page at desktop and mobile widths.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-27-001 - AI Model Execution Contract And Usage Ledger

Status: Reviewed
Added: 2026-07-27
Environment: Dev
Related: `docs/rfc/2026-07-27-ai-model-usage-and-cost-telemetry.md`,
`docs/adr/2026-07-27-integration-owned-ai-model-execution-telemetry-boundary.md`, and
`docs/feature-slices/2026-07-27-ai-model-execution-usage-ledger.md`

Scope: Integration-owned model-attempt execution boundary; one sanitized event per actual outbound
request; logical execution and ordered fallback correlation; normalized OpenAI-compatible,
OpenRouter-compatible, and Ollama usage; provider-returned model/request/finish metadata;
provider-reported cost where available; chat/actor/source context; payload exclusions; and explicit
non-blocking telemetry-write health signaling.

Deployment actions: deploy the code; run `php artisan migrate --force`; run `php artisan
optimize:clear`; and run `php artisan knowledge:sync-docs --module=Integration --no-interaction`.
No permission seed, queue restart, scheduler entry, frontend build, rate configuration, or Admin UI
is introduced by this slice. Migration
`2026_07_27_120000_create_ai_model_usage_events_table` ran on Dev in batch 52.

Risks: missing provider usage must not appear as zero; fallback may create several legitimate
attempts for one product action; raw payload or provider errors must not enter the ledger; provider
aliases can differ from the requested model; actor metadata must not become employee ranking; and a
telemetry persistence failure can create a visible coverage gap even though the AI response remains
available.

Automated verification: the focused telemetry suite passes with 8 tests and 95 assertions. It
covers OpenRouter cost, OpenAI Responses/Chat normalization, Ollama timing, explicit zero versus
missing usage, three-step endpoint fallback, chat context, payload exclusions, and telemetry-write
failure behavior. The complete affected Integration, Signal, Marketing, Lead Intelligence, Task,
and Ticket run passes with 307 tests and 2,600 assertions. Existing focused responder compatibility
passes with 13 tests and 105 assertions. PHP syntax and Pint pass. The migration preview, Dev batch
52 migration, actual 43-column InnoDB schema, foreign keys, uniqueness constraint, and reporting
indexes are verified. Repository Knowledge synchronization processed one Integration chapter and
five articles with no skips. The complete Dev Laravel suite passes with 880 tests and 6,720
assertions. The HTTPS route returns the expected login redirect when
unauthenticated. Authenticated visual smoke testing remains open because the known Dev certificate
is rejected by the in-app browser.

Human checks:

- [x] Open `/tech/knowledge/ai` on Dev in a browser that trusts the Dev certificate and confirm the
  existing chat workspace, agent selection, chat history, and message controls load normally.
- [x] Send one non-sensitive internal test prompt through an active Dev agent and confirm the normal
  model answer is stored once without a duplicate assistant message.
- [x] Inspect the resulting `ai_model_usage_events` row and confirm provider, agent, requested/actual
  model, endpoint, feature, attempt number, status, duration, and reported token fields match the
  controlled request.
- [x] Confirm that row contains no prompt, answer, source-record text, credential, authorization
  header, or raw provider error body.
- [x] If the selected provider reports cost, compare the stored provider-reported amount/currency
  with its provider usage response or dashboard. Record unavailable fields as unavailable, not zero.
- [x] Confirm no new telemetry report, rate editor, budget control, Client charge, or employee
  leaderboard is exposed by this foundation slice.
- [x] If a safe endpoint-fallback model is available, confirm each failed/successful attempt has the
  same logical execution ID and increasing attempt numbers.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-24-001 - Web Push Channel And Internal-User Device Foundation

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-07-24
Environment: Dev
Planned final verification environment: Production beta
Related: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`,
`docs/adr/2026-07-23-notification-owned-web-push-channel.md`, and
`docs/feature-slices/2026-07-24-web-push-channel-device-foundation.md`

Scope: globally guarded standards-based Web Push transport; stable VAPID configuration; the existing
shared service worker; explicit current-device registration; privacy-safe own-device inventory;
current-device generic self-test; user and permission-guarded administrator revocation; durable
secret-free subscription lifecycle audit; automatic disabled-user cleanup; and default-off
Notification preference fields. Later Slice 2 now exposes Web Push controls only for implemented
business-event payloads.

Deployment actions: install the locked Composer dependencies; configure stable
`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, and `VAPID_SUBJECT`; keep production
`WEBPUSH_ENABLED=false`; run `php artisan migrate --force`; run `php artisan optimize:clear`;
restart queue workers; and verify HTTPS plus the external scheduler runner. On Dev, migration batch
51 ran, stable Dev VAPID material was generated without displaying it, the switch was enabled, and
sanitized readiness reports Ready. No production inbound-email Web Push event is enabled by this
slice.

Risks: browser permission cannot be recovered by Nexum after the user denies it; endpoint and key
material must never appear in UI, logs, or audit records; lost VAPID keys invalidate subscriptions;
service-worker regressions could affect existing offline fallback; and user offboarding must remove
every registered device without crossing user scope. A persistent Dev queue worker and external
scheduler runner could not be verified on 2026-07-24; generic self-tests remain queued until the
worker is configured.

Automated verification: the focused Web Push/Notification run passes with 39 tests and 202
assertions; affected UserManagement admin/API tests pass with 15 tests and 107 assertions; the
Web Push foundation alone passes with 19 tests and 131 assertions; the Node service-worker smoke
test passes same-origin and hostile-target fallback checks; PHP syntax, Pint, Blade compilation,
route registration, migration preview, migration batch 51, HTTPS `/sw.js`, sanitized readiness,
and Web Push channel container resolution pass. Composer audit reports no advisory for any of the
five newly locked Web Push dependency packages; the wider existing lock still reports advisories
for 15 packages and 8 abandoned packages. The full suite completed with 871 passing tests
and 6,616 assertions plus one order-sensitive Risk API list assertion; the isolated Risk suite then
passed with 9 tests and 63 assertions. The unrelated Risk list assertion remains a known flaky test,
not a Web Push regression.

Review notes:

- 2026-07-25, Svein Tore, Dev: selecting Enable on this device failed because the browser could not
  fetch `https://nexum-psa.local/sw.js` for Service Worker registration.
- Server verification returned HTTP 200 with `Content-Type: text/javascript`; the served file
  matched `public/sw.js` and passed Node syntax checks.
- The active self-signed Dev certificate has no Subject Alternative Name for `nexum-psa.local`.
  Service Worker registration requires a trusted secure context, so Dev needs a trusted certificate
  with the correct SAN before this check can be repeated.
- 2026-07-25, Svein Tore: accepted deferring the Service Worker and Web Push browser check from Dev
  to the real production beta domain, which already has trusted SSL. The Dev certificate will not
  be treated as a Slice 1 code defect. This deferral is not approval; all relevant browser,
  installation, privacy, and delivery checks remain open until production-beta verification.
- 2026-08-11, Codex: inbound Email/customer-reply Web Push and source read-sync were implemented as
  separate completed slices under `HR-2026-08-11-002`. This entry remains focused on browser/device
  lifecycle and service-worker foundation checks.
- 2026-08-11, Svein Tore: Dev will get a new real-domain HTTPS vhost because browser Service Worker
  and Web Push tests cannot be accepted against the old untrusted Dev certificate. Retest this entry
  on that trusted HTTPS Dev vhost before production enablement.

Human checks:

- [x] Configure/verify a persistent Dev queue worker and the external once-per-minute scheduler
  runner before testing delivery.
- [x] Confirm the preferences page never prompts for browser permission until Enable is clicked.
- [x] Confirm global-disabled, incomplete-VAPID, unsupported, insecure-context, denied,
  unsubscribed, and subscribed states are understandable.
- [x] Re-test Service Worker registration after `nexum-psa.local` uses a trusted certificate with a
  matching Subject Alternative Name.
- [x] Register one Chrome/Edge device, receive the generic test, click it, and confirm Nexum focuses
  an existing authorized window or opens Notification Preferences.
- [x] Confirm the own-device list shows only generated label, browser/platform family, registration
  time, and last-seen time, with no endpoint or key material.
- [x] Register a second device, revoke it from the first device, and confirm it no longer receives a
  test; then re-register it.
- [x] As an administrator with `notification.manage_channels`, list and revoke another internal
  user's device without seeing secrets.
- [x] As an ordinary user, confirm the administrator device routes are hidden or denied.
- [x] Sign out and confirm the registered device remains subscribed; sign back in before opening a
  test target.
- [x] Disable a test user and confirm every owned subscription is removed with a secret-free audit
  record.
- [x] Confirm the existing PWA install, ordinary navigation, static-asset caching, and offline
  fallback still work after the shared service-worker update.
- [x] Repeat supported checks in Firefox, Safari/macOS, and an installed iOS/iPadOS Home Screen PWA
  where those devices are available.

### HR-2026-07-22-001 - CloudFactory Versioned Legal Documents And Portal Licence Ordering

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-07-22
Environment: Dev
Related: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`,
`docs/adr/2026-07-22-versioned-legal-documents-and-transaction-acceptance.md`, and
`docs/feature-slices/2026-07-22-cloudfactory-legal-documents-and-portal-ordering.md`

Scope: immutable Nexum and provider legal-document versions; conservative CloudFactory catalogue
extraction and monthly checks; offer/Service links; provider read-only and additional Nexum term UI;
contract version snapshots; version-aware contract acceptance; Customer-admin-only portal licence
ordering for exact contract-covered variants; and explicit issue, quantity, and renewal evidence.

Deployment actions: deploy the code; run `php artisan migrate --force`; run
`php artisan optimize:clear`; keep the ordinary scheduler and queue worker running. No new secret,
permission seeder, frontend build, or provider write setting is required.

Risks: CloudFactory does not guarantee legal-document fields for every product family; an extractor
must not mistake a short commercial term for legal text; accepted versions must never change
retroactively; portal products must not escape Client/contract scope; and a confirmation must be
recorded even when the provider operation later fails.

Automated verification: PHP syntax, portal licence route registration, immutable provider-version
replacement/removal behavior, Customer-admin authorization, exact contracted-offer ordering, legal
evidence hashing, and submitted CloudFactory operation linkage pass in targeted Dev tests. The full
Integration and Customer Portal suites pass with 90 tests and 876 assertions; the affected
Commercial and portal run passes with 63 tests and 686 assertions; Blade compilation and diff checks
pass. Migration batch 50 ran on Dev and backfilled all 6 existing Terms to 6 current immutable
versions with no missing current version. A live catalogue run completed for all 10,898 offers. The
current catalogue product payload returned no supported legal-document, Terms of Service, agreement,
or EULA field, so Dev correctly created no provider document and reports Not supplied by provider.

Human checks:

- [x] Open a CloudFactory-managed Service and confirm **Provider terms** is English, read-only, and
  shows issuer, version, status, source link, and last check without full inline editing.
- [x] Confirm **Additional Nexum terms** can add/remove an approved Nexum library document while the
  provider document cannot be removed from the Service.
- [x] Change a provider document in a sanitized test payload and confirm a new current version is
  created while the older version remains unchanged.
- [x] Remove that document from the next payload and confirm it remains stored with
  **Not returned in latest sync** rather than being deleted.
- [x] Confirm a CloudFactory Service whose payload has no legal document says
  **Not supplied by provider** and does not display invented legal text.
- [x] Send a test contract and confirm its portal view lists exact legal document versions and source
  links in addition to the existing text snapshots.
- [x] Accept the test contract and confirm one `contract_acceptance` legal evidence row records the
  portal account/membership and captured version IDs.
- [x] As a Viewer or site-scoped portal member, confirm Licences is hidden and the route returns 403.
- [x] As a client-level Customer admin, confirm Licences lists only exact variants already present on
  a won, active contract and respects the Integration Client write scope.
- [x] Order one allowlisted test licence and confirm the explicit legal checkbox, product, quantity,
  price, commitment, current versions, submitted operation, IP address, and user agent are retained.
- [x] Confirm quantity and renewal changes each require a new explicit confirmation and record their
  previous/current quantity or renewal action.
- [x] Confirm a provider validation/MCA failure marks the acceptance-linked operation failed without
  deleting the customer's confirmation evidence.

### HR-2026-07-21-001 - Ticket Storage Reservation Release And Quantity-Zero Removal

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-07-21
Environment: Dev
Related: `docs/rfc/2026-07-21-ticket-storage-reservation-release.md`

Scope: release a pending Storage-backed Ticket cost through a compact confirmed trash action or by
updating quantity to zero; atomically reduce reserved stock, mark reservation and cost history as
released/cancelled, remove the row from normal Ticket activity and the Storage Picking List, retain
a Ticket event audit snapshot, restore linked approved planned lines for conversion again, and
serialize update/pick/release state changes with database row locks.

Deployment actions: deploy the code; run `php artisan optimize:clear`. No migration, seeder, queue,
scheduler, or frontend build action is required. Knowledge documentation must be synchronized after
deployment according to the ordinary Knowledge process.

Risks: incorrect stock release could understate reserved inventory; stale requests could otherwise
both pick and release the same cost; released costs must not enter Economy; linked approved planned
lines must become convertible again; and the compact destructive control must remain clear and
accessible.

Automated verification: the complete affected Dev suites pass with 168 tests and 1,354 assertions
across Ticket, Ticket Workflow v3, Storage, and Economy. PHP syntax and targeted diff checks pass.
A browser check on Dev confirmed the explanatory confirmation, quantity-zero help text, and
quantity-zero redirection to the same confirmation flow; the real Dev reservation was left unchanged.
Rendered-view assertions confirm the final `Delete reservation` control is inside Edit cost and the
Activity row no longer exposes a separate trash control.

Human checks:

- [x] Open a Ticket with a reserved Storage cost, click `Edit`, and confirm a subtle
  `Delete reservation` button appears inside the Edit cost modal rather than on the Activity row.
- [x] Click `Delete reservation`, confirm the next modal clearly explains that stock and Picking
  List work will be released, then cancel and verify nothing changes.
- [x] Confirm removal on a Dev test reservation and verify the cost row disappears from normal
  Activity, the Picking List row disappears, and the Storage item's reserved quantity decreases by
  exactly the released quantity.
- [x] Confirm the Ticket Events accordion records `Storage reservation released` with a clear
  message.
- [x] On a second Dev reservation, edit quantity to `0`, confirm the same removal modal appears,
  and verify the same release result.
- [x] Confirm a picked cost does not expose the removal action and cannot be released by a stale
  request.
- [x] If using an accepted planned Storage line, release its converted reservation and confirm the
  approved line can be converted again.

### HR-2026-07-20-001 - CloudFactory Two-Way Client, Catalogue, Licence, Contract, And Economy Integration

Status: Reviewed
Reviewer: Svein
Reviewed date: 2026-08-25
Added: 2026-07-20
Environment: Dev and allowlisted CloudFactory production test customer
Related: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`

Scope: encrypted dedicated Portal-service-account connection; automatic token exchange and safe
revocation; role and health discovery; deterministic two-way Client/customer matching with manual
fallback; Vendor, catalogue, Service-source and settings-driven price synchronization; Client
licence workspace; Microsoft and Adobe provider operations; contract/commitment gates; append-only
licence changes; confirmed recurring Economy billing; scheduled polling and authenticated
notification webhooks; deterministic webhook retry deduplication; reconciliation, operation
idempotency, conflicts, audit, permissions, Knowledge documentation, and a production-only fictitious
Client validation gate.

Deployment actions: deploy the complete change; run `php artisan migrate --force`; run
`php artisan db:seed --class=PermissionSeeder --force` and
`php artisan db:seed --class=RoleSeeder --force`; run `php artisan optimize:clear`; keep the
ordinary queue worker and scheduler running. Configure a dedicated CloudFactory Portal service
account, enter its refresh token only through the masked settings field, verify the discovered roles,
select the default Service unit, enable notification registrations only when Partner Admin is
available, and leave writes limited to the fictitious allowlisted Client until the checks below pass.

Risks: CloudFactory has no sandbox and every provider call reaches production; ambiguous customer
matching must not merge records; retries must not duplicate licence orders; price and quantity
changes can affect contractual commitments and invoices; direct CloudFactory customer-portal changes
must reconcile back into Nexum; Microsoft MCA acceptance remains an interactive customer step; and
CloudFactory webhooks rely on a shared `X-API-KEY` over HTTPS rather than a cryptographic signature.
Polling therefore remains mandatory even when webhooks are enabled.

Automated verification: implementation and all three migrations completed on Dev. The dedicated
CloudFactory feature suite passed with 22 tests and 211 assertions; the latest Integration module
suite passed with 64 tests and 535 assertions; and the latest complete application suite passed with
849 tests and 6,378 assertions. PHP syntax, Blade compilation, route registration, `X-API-KEY`
rejection, old legitimate retry acceptance, deterministic deduplication, registration/removal,
queued reconciliation, durable per-category progress, canonical Vendor reuse without a duplicate
Microsoft, unresolved generic-category protection, audited manual Vendor propagation, and legacy
serialized-job compatibility passed. Authenticated HTTP smoke tests returned 200 for the CloudFactory
settings and catalogue pages, Services, contract creation, the Client licence workspace, and the
sanitized progress endpoint. The settings response verifies both collapsed sections and all four
nested operational cards. The rendered pages are regression-tested as valid UTF-8, with ASCII-safe
source escapes for the live progress separators. Rendered progress JavaScript passed syntax
validation.

The 2026-07-21 managed-Cost follow-up migration completed on Dev. CloudFactory raw commitment totals
now remain on each offer while the linked Commercial Cost and Service use a normalized monthly,
quarterly, yearly, or one-time amount. One default variant contributes provider cost per Service;
alternative variants remain synchronized without being summed, and manual Nexum Costs remain linked.
Draft contract lines select and snapshot the exact offer, normalized total cost, currency, and raw
term metadata. The CloudFactory suite passed 25 tests / 268 assertions, Commercial passed 32 / 272,
and Sales plus Economy passed 32 / 289. A final combined CloudFactory and Commercial run passed 57
tests / 540 assertions, including server-side replacement of a manipulated contract cost, catalogue
term filtering and sorting, and omission of the redundant catalogue Source column. Blade compilation
also passed. The live Dev catalogue has no
enabled or Service-linked offers yet, so the new Cost rows require the human catalogue checks below.
Commercial and Integration Knowledge synchronization processed two chapters and eleven articles;
the queued BookStack push completed without a failed job.

The later 2026-07-21 variant-Service decision supersedes the default-variant model above. Migration
`2026_07_21_190000_enforce_cloudfactory_service_variants` completed on Dev: every commitment and
billing offer now owns one deterministic variant SKU, one Service, and one managed Cost. Contract
selection stores the Service's exact offer automatically, licence issue requires that exact pair,
and inbound subscription synchronization resolves a shared provider SKU by commitment and billing.
The focused CloudFactory, Commercial, Sales, and Economy run passed 90 tests / 842 assertions.
Visual catalogue, Service, Cost, and contract verification remains pending below.

Migration `2026_07_21_200000_link_commercial_records_to_integrations` completed on Dev. CloudFactory
Services and Costs now store the same generic source Integration and remain connected through the
ordinary Commercial Cost relation. Active ownership locks Service and Cost changes through the UI,
direct requests, Commercial API, and Service pricing component. Revocation, disable, and Integration
deletion preserve and release the rows; a released Service uses its retained Cost on a new contract
without attaching the inactive CloudFactory offer. The focused Commercial and CloudFactory run
passed 59 tests / 603 assertions, and the complete application suite passed 851 tests / 6,461
assertions. The Dev backfill found no currently Service-linked CloudFactory offers, so no existing
commercial rows required conversion. Commercial and Integration Knowledge sync processed two
chapters and eleven articles with no skips; the queued BookStack push completed with the Integration
active, healthy, and without a recorded error. Visual active/released-state review remains pending.

Role discovery was corrected to use CloudFactory's authenticated `/Authenticate/Roles` endpoint
instead of token claims. The connected Dev account was refreshed without replacing its stored
refresh token and returned 18 roles. Customers/catalogue, Microsoft/MCA, invoices, notifications,
and activity log were enabled; Adobe remained unavailable because the account did not return the
Adobe role. Manual capability refresh and automatic refresh during token renewal passed tests.

A real queued Everything run completed with 26 of 26 Clients, 10,898 of 10,898 catalogue products,
and 0 of 0 licences. It recorded no conflicts and no failed queue jobs. A pre-existing serialized
CloudFactory job then completed in 29 seconds using the deployment-compatibility defaults. The
Integration Knowledge synchronization processed 1 chapter and 4 articles, and the BookStack push
completed. The Dev worker had only been switched off; production already uses the managed worker system.

A later live catalogue backfill created sixteen stable category mappings and linked 10,883 of 10,898
offers to canonical Nexum Vendors. Fifteen categories mapped automatically. All 10,638 Microsoft
offers across NCE, CSP, SPLA, software subscription, perpetual, and Azure category identities reuse
the existing Microsoft Vendor ID 1, and the Vendor register still contains one Microsoft. The generic
IaaS category and its fifteen offers remain intentionally unmapped with one open manual-review conflict.
The remaining product checks require live webhook registration and the allowlisted fictitious
Client because CloudFactory provides no sandbox.

Human checks:

- [x] Confirm Automation, pricing, and write safety and Conflicts and recent activity are collapsed
  by default, and expanding the activity section shows the four separate conflict, sync-run,
  provider-operation, and notification-webhook cards.
- [x] Select Everything and confirm the modal opens immediately, shows separate Clients, Catalogue
  and prices, and Licences rows, and advances real item counters while the queued job runs.
- [x] Close the progress modal during a run, confirm the job continues, and use View current sync to
  resume watching the same run.
- [x] Confirm the CloudFactory settings page never displays a stored refresh or access token.
- [x] Confirm the right-sidebar setup links open CloudFactory's refresh-token flow and official API
  guide, and that the guide clearly instructs the administrator to paste the Refresh Token rather
  than the Access Token.
- [x] Confirm API verified is understood as the last successful verification or sync, not a new request on each page view.
- [x] Select Refresh capabilities without replacing the stored token. Confirm Customers/catalogue,
  Microsoft/MCA, Invoices, Notifications, and Activity log show Available, Adobe shows Missing role,
  and the discovered-role list and last-checked time are visible.
- [x] Enable notification webhooks, confirm event registrations are shown without displaying the
  shared key, and verify one real provider delivery reaches a processed receipt.
- [x] Resend or retry the identical provider delivery and confirm it is accepted without creating a
  second receipt or synchronization job.
- [x] Run customer sync and confirm a strong match links correctly while an ambiguous match is parked
  for manual linking without modifying either customer.
- [x] Confirm an inbound CloudFactory-only customer creates one Nexum Client and repeat sync is
  idempotent.
- [x] Confirm catalogue offers can be excluded or enabled, and Services sort/filter correctly by
  Vendor and source. Confirm the Cloud Factory catalogue itself shows Vendor without a redundant
  Source column, while the resulting ordinary Service still shows Cloud Factory as its source.
- [x] Confirm each offer is compact by default, shows Catalogue only and Not in Services before
  activation, and expands settings only for the selected row. Enable For sale on a test offer and
  confirm the resulting Service appears in the ordinary Services list.
- [x] Confirm Vendor mappings shows fifteen automatic mappings and one IaaS mapping needing review;
  open a Microsoft mapping and verify it points to the existing Microsoft Vendor rather than a copy.
- [x] After the correct canonical Vendor for IaaS is decided, link it manually and confirm the choice
  updates all fifteen IaaS offers and any already linked Services without changing the Cloud Factory
  source identity.
- [x] Confirm the catalogue filter is labelled Vendor, not Nexum Vendor.
- [x] Search for Microsoft 365 Business Basic, filter separately by Commitment term and Billing
  term, and confirm otherwise identical offers show their distinct combinations: monthly/monthly,
  annual/monthly, and annual/annual. Select the Commitment and Billing headings in both directions
  and confirm sorting preserves the active search and filters.
- [x] Enable one annual-commitment/monthly-billing Business Basic test offer and confirm Cost and
  MSRP show both the raw annual source total and the normalized monthly Nexum amount.
- [x] Open the generated Service and Cost and confirm both are marked Cloud Factory and Managed,
  the source badge links to the active Cloud Factory Integration, both use the Microsoft Vendor,
  neither record can be edited or deleted, and the Cost appears through the ordinary linked Costs
  section on the Service.
- [x] Enable annual-commitment/monthly-billing and annual-commitment/annual-billing variants of the
  same product. Confirm Nexum creates two separate Services with distinct SKUs ending in
  `-C12-B1` and `-C12-B12`, without a Make default or manual Service-link control.
- [x] Confirm each variant Service has only its own Cloud Factory managed Cost. Add a manual Nexum
  Cost to one variant and confirm it is preserved there without appearing on the other variant.
- [x] Add each variant-specific Service to a draft contract. Confirm there is no additional
  commitment selector and that displayed sale price, cost, interval, yearly profit, and the saved
  contract line use the exact offer owned by the selected Service.
- [x] Confirm catalogue offers can still be excluded or enabled after Vendor mapping.
- [x] Confirm MSRP, MSRP markup, cost markup, and manual price modes behave as configured and a
  monthly refresh does not overwrite a manual price.
- [x] Confirm licence issue is blocked for a Client without an eligible contract.
- [x] On the allowlisted fictitious Client, create/link the CloudFactory customer and perform one
  reversible low-risk licence operation; confirm provider state reconciles into the Client licence
  workspace.
- [x] Confirm provider activation creates the expected contract amendment and Economy draft billing
  line once, with no duplicate after a repeated sync/generation run.
- [x] Make a permitted direct CloudFactory/customer-portal change and confirm it reconciles into
  Nexum with origin and audit history.
- [x] Disable webhooks and confirm provider registrations are removed before the shared key is
  deleted; re-enable them for continued validation if required.
- [x] For the controlled test Service and Cost, record their IDs and normal relation, then
  revoke/disconnect. Confirm webhook registrations, scheduled sync, and writes stop without exposing
  a secret.
- [x] Confirm the same Service, Cost, relation, accepted contract data, and accounting basis remain
  after disconnect, both rows show Released to Nexum and are editable, and selecting the Service on
  a new draft contract uses the retained Cost without attaching the inactive Cloud Factory offer.

### HR-2026-07-17-001 - Ticket Workflow v3 Conditional Actions, Escalation, Review, And Commercial Approval

Status: Reviewed
Added: 2026-07-17
Environment: Dev
Related: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`

Scope: Signal-style grouped workflow requirements; versioned workflow-specific states; per-state
action visibility and server enforcement; optional or required manual internal escalation;
eligible-owner assignment; senior review and scoped response/signature evidence; planned Ticket
costs; shared Sales Opportunity/Quote handling with immutable PDF and customer acceptance; approved
Storage, purchase-need, implementation, and Economy completion; API parity; explicit close outcomes;
active-Ticket workflow-version migration with automatic requirement-based placement per Ticket; and
Published-only customer status updates selected per transition through the shared Email template
and Customer Portal notification systems.

Deployment actions: deploy the complete cross-module change together; run `php artisan
migrate --force`; run `php artisan db:seed --class=PermissionSeeder --force` and `php artisan
db:seed --class=RoleSeeder --force`; run `php artisan optimize:clear`; and keep the ordinary queue
worker running for quote email/PDF and downstream jobs. Run `npm ci` and `npm run build` because
the Workflow editor now relies on Livewire 3's bundled Alpine runtime and the separate Alpine
package was removed. Before production, preview automatic active-Ticket target proposals and migrate only
explicitly selected Tickets. Run `php artisan db:seed --class=EmailTemplateSeeder --force` to ensure
the editable `tickets/ticket_status_update` template exists before enabling transition updates.

Risks: an incorrect action or assignment policy can block technicians; incomplete target-step
requirements can propose the wrong automatic placement for active Tickets; customer messages and
signatures must be classified against the correct Ticket and scope; quote acceptance is commercially
significant; purchase conversion must remain a draft need and never send a vendor order automatically;
and declined/cancelled/no-sale closure must not create ordinary Economy output. A transition notification can contact a customer,
so administrators must verify the selected template, channels, message, and Published-only behavior
before enabling it on a production workflow.

Automated verification completed 2026-07-17 on the active Dev tree: the four new migrations passed
fresh, rollback, reapply, and the partially applied MySQL recovery path before being applied to Dev;
PHP syntax and Blade compilation passed; Ticket, Workflow v3, Sales, Storage, and Economy completed
164 tests / 1314 assertions, including 8 tests / 86 assertions in the focused Workflow v3 suite. A
rendered authenticated response check confirms that the create page contains Workflow steps,
grouped requirements, available actions, and escalation paths. Repository Knowledge synchronization
processed Economy, Integration, Sales, Storage, and Ticket documentation, and the synchronous
BookStack push reported an active, healthy integration with no last error. Browser automation could
not cross the internal Dev certificate, so the visual checks below remain required.
The compact Available actions refinement was also verified on Dev with 117 Ticket and Workflow v3
tests / 834 assertions. Its Livewire regression test confirms that the plus-button adds only the
selected action and that removing it returns the action to inherited behavior.

One follow-up failure mode for Add action and Add next step was compiled Blade views created as
read-only for the PHP-FPM group. Those Livewire calls reached the server but failed while rendering
their updated component. Dev's compiled-view cache was cleared and rebuilt with group-writable
`0664` files under `storage/framework/views`; a default group-write ACL now also protects files
created by later web or CLI rendering. The permanent Dev cache procedure was recorded in
`AGENTS.md`. The two focused Livewire regressions plus the authenticated Workflow editor rendering
test completed 3 tests / 40 assertions.

Human review then confirmed both buttons could still appear inert without producing any new server
request. The remaining client-side cause was a second Alpine runtime imported and started by
`resources/js/app.js` before Livewire 3 initialized its bundled Alpine runtime. The first runtime
claimed the Workflow's `x-data` subtree before Livewire registered its `wire:click` directives.
The separate Alpine import and package dependency were removed, Livewire 3 is now the sole Alpine
owner on authenticated pages, and the Vite assets were rebuilt. The frontend-runtime regression,
full Workflow v3 suite, and authenticated editor rendering completed 14 tests / 146 assertions.

The Workflow editor refresh defect reported during human review was corrected on Dev. Ordinary
fields now use deferred Livewire binding, accordion opening is handled locally, and dependent
requirement/operator and escalation-target selectors update locally with Alpine. Only explicit
structure changes such as adding or removing a step, group, requirement, action, or path perform a
Livewire server update, and those actions keep their source step open. The focused Workflow v3 suite
completed 11 tests / 123 assertions; the combined Workflow v3, Ticket, and Portal verification
completed 126 tests / 938 assertions.

The Workflow steps accordion now starts with every existing step collapsed so the builder remains
compact when opened. A newly added step still opens automatically, and structural actions continue
to keep their affected source step open. The focused initial-collapse and add-next-step regressions
completed 2 tests / 21 assertions; the expanded Workflow v3 and authenticated editor rendering
verification completed 13 tests / 141 assertions.

Each collapsed Workflow step header now exposes a compact Remove step action whenever the workflow
contains more than one step. Removing a step also removes its connected transitions and escalation
paths through the existing editor logic. The final remaining step is protected in both the UI and
the Livewire component. The frontend-runtime regression, full Workflow v3 suite, and authenticated
editor rendering completed 15 tests / 154 assertions on Dev.

The updated Ticket Workflow article was synchronized and pushed to BookStack; the integration
reported active, healthy, and no last error after the push.

Human review clarified that workflow progress belongs in the Ticket header rather than in a large
Workflow card in the Ticket body. Dev now renders an ordered, connected header rail with the
current step highlighted and evaluated requirements available on hover or keyboard focus.
Next-step and escalation actions are in the compact right panel, while commercial approval, review,
and evidence remain separate task-focused tools. The workflow-decisions API exposes the same ordered
step and requirement data. Ticket and Workflow v3 verification completed 118 tests / 845 assertions.
The visual refinement reported during review replaces separate Bootstrap status pills with one
compact workflow rail: restrained labels, connected arrow lines, distinct current/completed/
available/upcoming markers, and a small warning indicator for missing requirements. The Vite build,
Blade compilation, and focused Ticket header test (1 test / 10 assertions) passed on Dev.

Human review on 2026-07-17 found a workflow runtime defect on `TD-2026-000019`. An Internal
solution was stored and both the pinned definition and requirement evaluator report an allowed
transition, but the Ticket remained in `New`. Diagnosis found that the direct Internal solution
path does not invoke requirement-driven auto-advance, while action-trigger lookup reads mutable
transition rows even when the Ticket runtime and UI read its pinned workflow-version definition.
No Ticket data was changed during diagnosis; this review remains Rework Needed until the runtime
uses one version-consistent definition and the regression is verified.

The runtime defect was corrected on Dev on 2026-07-17. Message triggers, requirement-driven
advance, manual and API status changes, completed closure, and inbound Relationship status sync now
delegate to the same pinned workflow transition action. A successful move updates status,
`workflow_state_key`, workflow history, and Ticket events together. Saving a draft no longer drops
existing automatic message triggers, and the editor exposes a compact `Automatic after action`
selector for the supported message activities. Focused verification completed 125 Ticket tests /
907 assertions plus 10 Relationship tests / 37 assertions; dedicated regressions cover the exact
Internal solution/pinned-version defect, API status parity, and terminal close history. The review
remains Rework Needed until the checks below are repeated in the browser by the named reviewer.

The automatic-action model was expanded after review clarification on 2026-07-17. A transition can
now select **Any technician activity** or a specific action. The business action is persisted first,
then the pinned workflow evaluates transition and target-step requirements; a passing activity moves
at most one non-finishing step, while an unmet gate leaves the action saved and the Ticket in place.
Opening the page alone is not an activity. Timer start is now a real server-audited action rather
than only browser local state, and timer start, time registration, and actual-cost registration have
API parity. Verification completed 128 Ticket/Workflow v3 tests / 936 assertions, 41 Sales,
Storage, and Relationship tests / 421 assertions, and the frontend runtime regression (1 test / 5
assertions). This includes requirements becoming true after an Asset link and independent API
regressions for timer, time, and cost activity. The expanded Ticket suite exposed a Blade compiler
error in the new timer control; the timer decision now uses a proper Blade PHP block, all 108 Ticket
tests pass, and compiled views pass. The Dev HTTP smoke check returns the expected authentication
redirect rather than an application error. Repository Knowledge pushed 2 chapters and 14 pages to
BookStack synchronously with zero failures or skips; the integration reports healthy with no last
error.

The final hard-coded requirement-only solution advance was replaced by schema-versioned
compatibility: already-published definitions keep their historical behavior, while every new or
republished definition moves only from its configured action triggers. A regression confirms that
a satisfied solution requirement alone cannot move a current workflow definition.

The separate solution fact was verified after the latest human-review question. **Solution is
marked** accepts either a marked public technician reply or a
marked internal note when Ticket Settings permits internal solutions. The latest Dev data also
evaluates this fact as satisfied for Tickets containing only an allowed internal solution. A manual
transition becomes available without moving by itself; automatic movement still requires its own
configured action trigger. The complete Ticket suite passed 135 tests / 1004 assertions. Ticket
Knowledge synchronized 1 chapter / 11 pages and pushed them to BookStack with zero failures or
skips; the integration reports healthy with no last error.

Review of `TD-2026-000031` found that its assigned technician is correctly stored as the Ticket
owner and the owner fact evaluates true. The misleading header came from a workflow condition using
the negative `is_false` operator while the progress rail displayed only the positive fact label.
Evaluated requirements now carry an operator-aware summary, the editor calls the boolean choices
**must be true** and **must be false**, and negative gates display with **Must not**. An initial
Ticket Body stored as the default context note is excluded from both Internal-note and technician-
response activity facts; a real later note still counts. Identical requirements configured on both
a transition and its target step remain enforced in both scopes but display once in the header.
The complete Ticket suite passed 136 tests / 1022 assertions. Ticket Knowledge synchronized 1
chapter / 11 pages and pushed them to BookStack with zero failures or skips; the integration is
healthy with no last error.

The response wording and behavior were separated after further review. **Technician reply or
internal note exists** is now an activity fact satisfied by either a real later public technician
reply or a real later internal note; no solution marker is required. **Solution is marked** remains
a separate fact and only passes for a message explicitly selected as the solution. The initial
Ticket Body remains excluded from both activity facts. The complete Ticket suite passed 136 tests /
1024 assertions, and Ticket Knowledge pushed 1 chapter / 11 pages to BookStack with zero failures
or skips; the integration is healthy with no last error.

The activity fact was then split into two independently configurable facts: **Technician reply
exists** and **Internal note exists**. Administrators can put both in a **Require at least one**
group for reply-or-note behavior, or require both through **Require all** or separate required
groups. **Solution is marked** remains independent. The generated default solution transition now
requires only the solution marker, so an allowed internal solution is not forced to masquerade as a
public reply. The initial Ticket Body still satisfies neither activity fact. The complete Ticket
suite passed 136 tests / 1027 assertions, and Ticket Knowledge pushed 1 chapter / 11 pages to
BookStack with zero failures or skips; the integration is healthy with no last error.

Read-only review of `TD-2026-000031` confirmed that its published In Progress requirements contain
one **Require at least one** group with **Internal note exists** and **Technician reply exists**.
The requirement evaluator already accepted either condition, but workflow progress flattened the
group and then treated every displayed condition as mandatory. Ticket View and the API now preserve
the evaluated OR result and present the alternatives as one combined gate, for example **At least
one: Internal note exists OR Technician reply exists**. With only the existing internal note, the
real Ticket now reports the combined gate and `requirements_passed` as true. The complete Ticket
suite passed 137 tests / 1036 assertions. Ticket Knowledge pushed 1 chapter / 11 pages to BookStack
with zero failures or skips; the integration is healthy with no last error.

Customer status updates were added to next-step transitions on 2026-07-18. Administrators can
choose Email and/or Customer Portal, an active Ticket Email template, and an optional safe message.
The same after-commit runtime covers manual Ticket buttons, automatic action triggers, and API
transitions. Only Published Tickets may create or send the public update; Unpublished Tickets still
transition but record a skip. The portal workflow channel is database-only so it cannot duplicate
the selected templated Email. Internal notes, internal workflow names, requirements, and cost data
are excluded from generated content. Missing recipients/configuration and SMTP failures are audited
without reverting the transition, and idempotent API retries do not duplicate the update. The
additive migration was applied to Dev and the default Email template was seeded. The focused slice
passed 10 tests / 52 assertions; combined Ticket, Workflow v3, Email, Notification, and Customer
Portal regression passed 207 tests / 1399 assertions. Repository Knowledge synchronization
processed two chapters and twelve Ticket/Email articles without skips; the synchronous BookStack
push left the active integration healthy with no last error.

Automatic active-Ticket placement was added on 2026-07-18. The migration page no longer asks the
administrator to map an old step to one target step. Preview evaluates every Ticket against the new
version and explains whether it retained a stable step, matched explicit entry requirements,
preserved a unique reporting status, used the initial step, or was blocked. Apply evaluates again in
the transaction and ignores legacy API `state_mapping` input. Focused regression covers two Tickets
from the same old step being placed differently, a blocked Ticket remaining pinned, stable-key
retention, an authenticated browser rendering without the Target-step selector, API parity, and
recorded requirement/strategy history. Workflow v3 passed 24 tests / 252 assertions; the combined
Workflow v3, Ticket, and customer-notification regression passed 142 tests / 1056 assertions. Blade
compilation passed and the Dev HTTP smoke check returned the expected authentication redirect.
Ticket Knowledge synchronized one chapter and eleven articles without skips; the synchronous
BookStack push left the active integration healthy with no last error.

Partial human review update on 2026-07-18: Svein Tore reports that the tests performed so far look
correct. The review is now **In Review**; the unchecked browser scenarios below remain open and this
partial confirmation is not recorded as final approval.

Pre-push verification on 2026-07-18 completed the full Laravel suite with 820 tests / 6075
assertions, plus `npm ci` and the production Vite build. The first full run exposed one stale System
Knowledge article-count expectation caused by the separate application-version documentation; that
expectation was corrected outside the Workflow commit, its focused regression passed 1 test / 7
assertions, and the complete suite then passed. The production dependency audit still reports one
existing moderate PostCSS advisory; dependency upgrades remain separate from this Workflow change.
After Pint normalized the staged PHP files, the final Ticket and cross-module regression passed 287
tests / 2199 assertions and the staged formatter/diff checks passed.

In-app visual automation remains blocked by Dev's internal certificate, so the browser checks
below are still required.

- [x] Configure `Any technician activity` with a required linked Asset. Add a note without an Asset
  and confirm it is saved without moving the Ticket; then link the Asset and confirm that action
  moves the Ticket exactly once to the configured next step.
- [x] On separate Tickets, start the timer, register time, and add actual cost. Confirm each action
  can move to the configured next step in both the Ticket page and API, while merely opening the
  Ticket does not change its state.

- [x] On a workflow next-step button, enable **Notify customer**, select Email and Customer Portal,
  choose `Ticket status update`, and add a customer-safe message. Publish the workflow and the test
  Ticket, then trigger the transition with an internal note. Confirm the Ticket moves once, the
  public timeline shows only the approved reporting status/message, one templated Email is queued,
  and one portal notification is created without a second generic Email.
- [x] Repeat the configured transition on an Unpublished Ticket. Confirm the Ticket moves internally
  but no public status-update message, customer Email, or portal notification is created, and the
  audit history explains that delivery was skipped because the Ticket was not Published.
- [x] Trigger equivalent configured transitions once from a manual Ticket next-step button and once
  through the API. Confirm both produce the same public update and delivery behavior, and repeating
  the same API idempotency key does not produce another message or notification.

- [x] Open the Workflow create and edit pages and confirm every existing step starts collapsed;
  expand one manually, then add a next step and confirm only the newly added step opens
  automatically.
- [x] Create a manual transition requiring **Solution is marked**
  and leave **Automatic after action** empty. Add an Internal solution and confirm the transition
  becomes available without moving the Ticket until the button is clicked. Then configure the
  Internal solution action trigger on another transition and confirm it executes exactly once and
  updates both status and workflow state. Verify the API reports the same requirements and resulting
  state.
- [x] With multiple steps, confirm each collapsed header shows Remove step; remove a middle step
  and confirm its connected next-step buttons and escalation paths disappear. Confirm the final
  remaining step has no Remove step control and cannot be deleted.
- [x] Confirm Available actions initially shows the compact selector and plus-button, displays only
  actions explicitly added, and removes an action back to inherited behavior.
- [x] Type a multi-word Step name and change status, roles, requirements, operators, action policy,
  assignment, and escalation fields; confirm ordinary edits do not refresh or collapse the editor.
  The step header may update after save or the next explicit structure action.
- [x] Open a later step, select an action and click Add action, then click Add next step and
  add/remove a requirement or next-step button; confirm each change appears without a server error
  and the necessary Livewire update keeps that source step open.
- [x] Confirm the Ticket header shows one clean connected workflow rail rather than separate pills;
  verify the current, completed, available, and upcoming markers are easy to distinguish without
  competing visually with Close/Back, and that no large Workflow card appears in the Ticket body.
- [x] Hover and keyboard-focus every step to verify satisfied and missing requirements, then confirm
  Next step/escalation actions and the separate commercial/review/evidence tools remain usable.
- [x] Create a Ticket assigned to a technician and confirm **Ticket has an owner / must be true** is
  satisfied. Configure the same fact as **must be false** and confirm the header explicitly says
  **Must not: Ticket has an owner** instead of claiming that an owner is missing.
- [x] Confirm the initial Ticket Body satisfies neither **Internal note exists** nor **Technician
  reply exists**. Put both in a **Require at least one** group and confirm that, while neither exists,
  Ticket View shows one failed combined **At least one** gate rather than two mandatory failures.
  Add only a real internal note and confirm the combined gate turns satisfied and the configured
  next step becomes available; then repeat with only a public technician reply. Confirm the API
  reports the same group result. Confirm **Solution is marked** remains false until one message is
  explicitly marked as the solution. Place the same fact on a transition and its target step and
  confirm the header shows it once.

- [x] Build and publish a test workflow with `all` groups and an `any` group containing customer
  response, uploaded signature, and valid contract; confirm a linked Asset can be a separate group.
- [x] Confirm hidden, blocked, and conditional Ticket buttons match the workflow and the same direct
  API calls are denied with an understandable reason.
- [x] Confirm an optional escalation remains a technician choice and a required escalation blocks
  only its configured protected actions.
- [x] Escalate a Ticket to another workflow/queue/type and verify only an eligible technician can be
  selected or automatically assigned.
- [x] Request senior review as a junior, approve as another eligible senior, then change a material
  Ticket field or planned line and confirm the approval is invalidated.
- [x] Classify a specific customer email response and a specific uploaded signature; confirm an
  unrelated message/file or another customer's record cannot satisfy the gate.
- [x] Add equipment and implementation/time as planned scope, create the shared Sales quote, and
  verify the Ticket and Sales views operate the same Opportunity and Quote.
- [x] Send the quote from the Ticket and verify the reply includes the immutable PDF and matching
  acceptance link; accept through the link, then separately test recorded email-text acceptance.
- [x] Confirm acceptance marks the Opportunity won and unlocks only the approved lines; converting
  an orderable item creates a draft purchase need without sending a vendor order.
- [x] Complete implementation and close as `completed`; confirm unfinished required work or a cost
  overrun outside tolerance blocks closure and requires corrected scope/reapproval.
- [x] Close separate Tickets as customer declined, cancelled, and no sale; confirm a reason is
  required and ordinary Economy output is not created.
- [x] Put two active Tickets in the same old workflow step, add an Internal note to only one, and
  publish a version where a renamed later step requires that note. Confirm migration preview has no
  **Target step** selector, automatically proposes the later step only for the Ticket with the note,
  explains both proposals, and disables a Ticket that cannot safely match any target. Migrate one
  selected Ticket and confirm it is re-evaluated into the proposal while the other remains pinned to
  its prior version. Confirm the API behaves the same without `state_mapping` and cannot force a
  legacy mapping that conflicts with the Ticket facts.
- [x] Verify a technician lacking Sales, Storage, review, escalation, or workflow-publish permission
  never gains that capability from workflow configuration or through API access.
- [x] Review Ticket detail and the workflow builder on desktop and narrow/mobile layouts, including
  disabled-reason text, modals, tables, and the `Escalate Ticket` control.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-16-001 - Automatic Release Metadata And Admin GitHub Version Status

Status: Reviewed
Added: 2026-07-16
Environment: Dev, followed by GitHub `main` for the release workflow
Related: `docs/rfc/2026-07-16-version-and-github-update-status.md`

Scope: automatic `version.txt` and Composer commit identity, Release Please configuration, footer
version display, deferred GitHub release/branch comparison in the Admin header, and the move of the
Admin landing route/view into the System module.

Deployment actions: run Composer before Laravel configuration caching, set
`APP_UPDATE_BRANCH=Dev` on the development server, clear Laravel caches, and rebuild frontend
assets only if the deployment process requires it. There are no migrations or queue actions.

Risks: GitHub status may be cached or unavailable; an installation that skips the Composer
metadata refresh can show a stale or unknown commit; and the Release Please workflow cannot create
its first release pull request until it reaches `main` with repository Action permissions enabled.

Automated Dev verification completed 2026-07-16: PHP syntax and release configuration passed;
System 31 tests / 197 assertions passed; Blade templates compiled; Composer metadata reported
repository HEAD `42a08a7` after `composer install`; a live read-only GitHub query reported
`v0.2.0-beta`, the existing release, and 7 commits behind configured branch `main`; and the HTTPS
Admin smoke check redirected unauthenticated access to login. Automated visual inspection was
blocked by the internal Dev certificate, so it does not replace the checks below.

- [x] The shared technician footer shows only the installed version and the text is readable in
  both light and dark appearance.
- [x] `/tech/admin` opens without waiting for GitHub and keeps the expected Admin cards and links.
- [x] The right side of the Admin header shows the installed version and short commit ID.
- [x] After loading, the header reports the latest GitHub release and the correct distance from the
  environment's configured update branch.
- [x] Narrow/mobile layout wraps the status without covering the Admin title or breadcrumb.
- [x] A temporary GitHub failure shows an honest unavailable or cached state and does not break the
  Admin page.
- [x] A user without `system.view` cannot read the version-status endpoint.
- [x] After the workflow first reaches `main`, GitHub creates or updates the Release Please pull
  request without publishing a release prematurely.
- [x] Merging a future generated Release Please pull request updates `version.txt` and
  `CHANGELOG.md`, then creates the expected semantic beta tag and GitHub release.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-15-002 - Signal Feed, Rule Builder, Execution Recovery, And Retry

Status: Reviewed
Added: 2026-07-15
Environment: Dev
Related: `docs/rfc/2026-07-15-signal-rule-builder-and-recovery.md`

Please review after the Dev verification is reported complete:

Automated Dev verification completed 2026-07-15: Signal 27 tests / 157 assertions; Email, Ticket,
and Intake regression 165 tests / 1105 assertions; Blade compilation and unauthenticated HTTP
smoke checks passed. These results do not replace the human checks below.

- [x] `/tech/admin/system/signals` opens on the last 30 days; 7/30/90 days, custom dates, all
  history, search, filters, reset, sorting, and pagination behave as expected.
- [x] `/tech/admin/system/signals/rules` shows priority and whether a successful rule stops
  lower-priority rules.
- [x] `/tech/admin/system/signals/rules/create` uses compact condition groups and action rows;
  add/remove, all/any selection, contextual fields, action expansion, and drag ordering work.
- [x] An existing legacy Signal rule opens in the builder and saves without losing its meaning.
- [x] Rule Reference is readable in the right sidebar, while Advanced JSON stays collapsed and is
  used only after explicitly enabling `Save advanced JSON`.
- [x] A rule with a failing action shows `Failed` and later actions as `Not Run`; another matching
  rule still executes.
- [x] A successful rule with stop-processing enabled prevents a broader lower-priority rule.
- [x] Signal detail shows each action's order, status, result, attempt number, and error.
- [x] `Retry failed / unstarted` runs only outstanding actions. The warned `Run whole rule again`
  does not duplicate an already-created Ticket, Task, Sales follow-up, portal invitation, derived
  Signal, or webhook delivery.
- [x] A user without `signal.action.execute` cannot see or call retry controls.

Reviewer: Svein
Reviewed date: 2026-08-25
Result / notes:

### HR-2026-07-15-001 - Main And Dev Pre-Merge User-Interface Review

**Status:** In Review  
**Added:** 2026-07-15  
**Reviewer:** Svein Tore  
**Review environment:** Local development (`nexum-psa.local`)  
**Scope:** Combined review candidate based on the latest `Main`, latest remote `Dev`, and the local
Dev worktree as inventoried on 2026-07-15. No merge or push was performed while preparing this
checklist.

**Completion condition:** A named human reviewer confirms every applicable check below. Any failed
check must be recorded under Review Notes and rechecked after correction.

#### Shared Application Shell

- [x] Login and welcome pages render correctly.
- [x] Technician navigation works on desktop and mobile.
- [x] Admin dashboard and Admin menu show the correct entries for the signed-in role.
- [x] PWA metadata, install behavior, online navigation, and offline fallback behave correctly.

#### Technician Ticket Workflow

- [x] Ticket creation works with the expected defaults and visibility controls.
- [x] Ticket detail supports replies, internal notes, attachments, and normal status actions.
- [x] CC suggestions stay hidden until the field receives focus or is clicked.
- [x] Selecting a CC suggestion inserts the correct email address without exposing an excessive
  suggestion list. Follow-up filtering and density work is accepted for GitHub issue #182.
- [x] Ticket costs handle ordinary stock, orderable out-of-stock items, and blocked non-orderable
  items correctly.
- [x] Ticket settings and Ticket rules render and save correctly.

#### Customer Portal

- [x] A new portal user can accept an invitation and choose a password.
- [x] An existing portal user can accept another valid invitation.
- [x] Expired, invalid, and already-used invitations show the correct result.
- [x] Portal dashboard, navigation, logout, and membership switching work.
- [x] Portal notifications can be opened, marked read, and configured.
- [x] Portal tickets can be listed, created, opened, replied to, and supplied with permitted
  attachments.
- [x] Published documents and Knowledge articles can be listed and opened.
- [x] Quotes can be listed, opened, accepted, and queried.
- [x] Contracts can be listed, opened, and accepted.
- [x] Published orders can be listed and opened.
- [x] A portal user cannot access another customer, site, membership, or unpublished record.

#### Booking

- [x] Admin Booking list renders correctly.
- [x] Booking services can be created and edited with technician, location, duration, availability,
  notice, instructions, active state, and abuse-protection settings.
- [x] Public Booking list and service page show valid availability.
- [x] A visitor can choose a date and slot, submit contact details, and reach the thank-you page.
- [x] Empty availability and validation errors are presented honestly.
- [x] Admin request detail supports confirmation and rejection with the expected Calendar handoff.
- [x] Booking create/edit places Back in the shared page header. Accepted for GitHub issue #184.
- [x] Booking services support a configurable daily opening window, for example 10:00-15:00.
  Accepted for GitHub issue #184.
- [x] Booking availability follows company working hours by default and can instead follow the
  selected technician's working hours. Accepted for GitHub issue #184.
- [x] Booking supports a fixed technician, automatic assignment from available technicians without
  exposing the technician to the customer, and optional customer technician selection. Accepted
  for GitHub issue #184.
- [x] Honeypot protection is explained in plain language or moved to an advanced section. Accepted
  for GitHub issue #184.

#### Intake

- [x] Admin Intake list and submission list render correctly.
- [x] Forms can be created and edited with supported fields, validation, layout, choices,
  conditional visibility, file uploads, ordering, and active state.
- [x] A public form can be submitted and reaches the correct thank-you page.
- [x] Conditional fields, required fields, attachments, and abuse protection work.
- [x] Admin submission detail supports review, attachment download, and permitted Sales routing.

#### Data Exchange

- [x] Data Exchange profiles can be listed, created, and edited.
- [x] Direction, format, source/target, field mapping, relations, and filters save correctly.
- [x] Export can run and the generated file can be downloaded.
- [x] Import supports dry-run preview before an explicit commit.
- [x] Run history and run detail show accurate results and errors.
- [x] Schedule and delivery-target forms render and save correctly.

#### Signal And Rules

- [ ] Signal feed and Signal rule list render correctly.
- [ ] Signal rules can be created and edited using structured conditions and actions.
- [ ] Advanced JSON remains consistent with the structured rule form.
- [ ] Signal settings save AI enablement, confidence, source, type, routing, and prompt values.
- [ ] Email Rules and Ticket Rules can emit Signal records without creating loops.

**Agreed Signal direction:** Svein Tore confirmed on 2026-07-15 that ordinary emails and tickets
must not create Signals. Email Rules and Ticket Rules may hand off to Signal only when an admin
explicitly configures an `Emit Signal` action. Email remains responsible for email-local processing,
Ticket remains responsible for ticket-local classification and routing, and Signal handles
normalized events and cross-module automation.

**Signal UI requirements approved for implementation on 2026-07-15:**

- Signal Feed defaults to the last 30 days, with an explicit way to search older/all history or use
  a different date range.
- Signal Feed supports sorting rather than always forcing the latest-first order.
- Signal Rule create/edit uses a builder comparable to the Intake form builder. Actions are compact
  rows/cards, a `+` control adds another action, and only fields relevant to the selected action are
  shown.
- Conditions use the same builder pattern with compact rows/cards, `+ Add condition`, removal, and
  field/operator/value controls that adapt to the selected condition type.
- A rule-level match selector supports `All conditions must match` and `At least one condition must
  match`. `All conditions must match` is the default.
- Advanced conditions support `+ Add group`. Each condition group can independently use `All` or
  `At least one`, allowing expressions such as `source is Email AND (type is backup failure OR type
  is security alert)` without making the default builder complex.
- All matching rules execute in priority order by default. A rule can enable `Stop processing more
  rules after this rule` so a specific rule can prevent broader fallback rules from duplicating its
  work.
- Action order remains explicit because Signal actions execute in sequence.
- If an action fails, the remaining actions in that rule stop and the execution records the error.
  Other matching rules may still run. `Stop processing more rules` applies only when its rule
  completes successfully.
- Rule Reference moves from the main form body to the right sidebar.

#### Other Changed User Surfaces

- [x] Warroom My Day renders the selected date, metrics, Calendar items, tickets, tasks, queues, and
  working links.
- [x] Warroom/dashboard shows Storage items with `Should order` status when such items exist.
  Accepted for GitHub issue #183.
- [x] Client detail can start creation of a correctly associated Contact.
- [x] Contact create/edit handles transactional SMS consent.
- [x] Contact settings can control the default for automatic portal invitations.
- [x] Contact create lets the technician override the automatic portal-invitation default for the
  individual Contact. Accepted for GitHub issue #185.
- [x] Notification channel list and SMS settings/test flow behave correctly, including consent
  blocking.
- [x] Marketing campaign create/edit/show supports stop/repeat completion behavior and WordPress
  content context.
- [x] Sales Leads shows the expected marketing engagement information.
- [x] Economy order list, detail, export, and portal-visibility controls work.
- [x] Storage item create/edit/show handles orderable stock-shortage settings correctly.
- [x] Public and portal quote views render and preserve acceptance behavior.
- [x] Contract creation, public contract view, portal invitation option, and portal contract view
  work.
- [x] Technician Documentation view correctly controls portal publication.
- [x] User profile, role, and permission pages enforce the expected access.
- [x] Every new Admin menu destination is hidden or denied for roles without permission.

#### Review Result

**Automated release-candidate verification (2026-07-22):** the full Dev suite passes with 853 tests
and 6,494 assertions. PHP syntax, Composer validation, Release Please JSON/build-metadata checks,
route registration, Blade compilation, migration status, secret-pattern scanning, and Git diff
checks pass. All seven new migrations are applied on Dev. Twenty-five untracked patch backups and
four command-output artifacts were removed before staging; no application source or database data
was removed. Main was deliberately left unchanged.

**Review notes:** Review started by Svein Tore on 2026-07-15. Intake was reported as looking very
good. Svein Tore then explicitly approved every checklist item not mentioned in the review feedback.
The current CC panel was reviewed from Ticket detail. Signal and Rules must receive a separate,
careful review later and remain entirely unchecked. The other reported follow-ups were explicitly
accepted as later GitHub work and no longer block this human review.  
**Failed checks:** Ticket CC suggestions consume too much space, include global Contacts that should
not be offered, and repeat the Contact already selected on the Ticket. Booking create keeps Back at
the bottom instead of in the page header. Booking also lacks service-specific opening hours,
company-hours versus technician-hours behavior, automatic selection from available technicians,
and optional customer technician choice. The raw `Honeypot field` setting is too technical for the
normal Booking form and needs plain-language help or placement in an advanced section. Storage
`Should order` demand is not surfaced on the dashboard/Warroom. Contact create lacks a per-Contact
override for automatic portal invitation.  
**Accepted deviations:** Svein Tore accepted the non-Signal follow-up work for later implementation
through GitHub issues #182 (Ticket CC suggestions), #183 (Storage `Should order` in Warroom), #184
(Booking hours, technician routing, Back placement, and honeypot explanation), and #185 (Contact
portal-invitation override). These issues do not block the current merge review.  
**Final human confirmation:** Partial confirmation provided on 2026-07-15 for every checklist item
not explicitly left open above. Full confirmation is not yet provided.

## [HR-2026-08-25-001] Scheduled Ticket SLA and Recurrence Management
- **Scope:** Implement one-time/recurring scheduled tickets with SLA deferral and calendar linkage.
- **Affected:** Ticket Module, Calendar Module, Scheduler.
- **Checks:**
    - [x] One-time tickets defer SLA based on `planned_start_at`.
    - [x] Recurring tickets generate new occurrences based on RRULE (Daily, Weekly, Monthly).
    - [x] Tickets can be linked to technician calendars.
    - [x] `ProcessScheduledTickets` job activates due tickets and generates occurrences.
    - [x] UI handles dynamic recurrence and calendar fields.
- **Expected Results:** Scheduled work doesn't trigger premature SLAs; recurring work is automated.
- **Risks:** Recurrence rule complexity; job overlapping (handled by `withoutOverlapping`).
- **Status:** Done (Junie Verification)
- **Reviewer:** Junie (Automated Verification)

## [HR-2026-08-25-002] Permission Reconciliation
- **Scope:** Reconcile `Database\Seeders\RoleSeeder` with the database state.
- **Affected:** Permissions, Roles, Seeders.
- **Checks:**
    - [x] Verify that Admin role retains `calendar.manage_all` and `sales.admin`.
    - [x] Verify that Tech role retains `calendar.view_free_busy`.
    - [x] Verify that Mail hotfix permissions (`email.mailbox_sync_manage`) are preserved in Admin.
    - [x] Verify that `php artisan db:seed --class=RoleSeeder` does not remove existing valid grants.
- **Expected Results:** `RoleSeeder` is the source of truth; all intended grants are in code and database.
- **Risks:** Accidentally stripping custom permissions if they were not recorded in the seeder.
- **Status:** Reviewed
- **Reviewer:** Junie (Automated Verification)

