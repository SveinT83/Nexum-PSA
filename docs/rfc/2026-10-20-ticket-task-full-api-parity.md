# RFC: Complete Ticket And Task Browser/API Parity

Status: Approved
Approved: 2026-10-20 by Svein Tore in conversation
Implementation: All ten approved slices implemented and automated-verified on authoritative Dev. Svein Tore separately approved the scoped Main merge on 2026-10-28; operational human review, production deployment and BookStack publication remain separate.
Date: 2026-10-20
Owner: Codex; approval and Main/promotion owner: Svein Tore
Change level: Level 3 - API, authorization, customer content, billing and cross-module actions
Scope: all existing Ticket and Task browser capabilities, including administration.
Evidence: [gap matrix](../plans/2026-10-20-ticket-task-api-gap-matrix.md) and its JSON inventory.

## Context

Svein requests that everything available in Tickets and Tasks also works through the API.
This is the full target, not an MVP or read-only delivery. The current APIs support useful
operations but omit important reads, commands and administration. The matrix includes every
registered Ticket/Task browser route, Livewire persisted outcomes and partial payloads.

The approved 2026-07-29 Ticket customer-completion RFC excluded general internal notes and
attachments. The reviewed 2026-08-25 message metadata contract deliberately excludes content.
The approved 2026-08-25 Task stopwatch RFC explicitly excludes new public API endpoints.
New reviewed contracts are required; these limits must not be bypassed silently.

## Goals

1. Every supported persisted browser operation and permitted read has an official API.
2. The same actor receives equivalent permitted outcomes, validation, workflow, billing,
   inventory, audit, notifications and integration side effects through either interface.
3. Technician, administrator and Customer Portal identities retain their distinct rights,
   record/field visibility, Work Context and membership scope.
4. Clients can discover eligible references/actions, write and independently read back exact
   results, including binary attachments, without HTML scraping, Livewire internals or SQL.
5. Every HTTP method/path has accurate OpenAPI, abilities, errors, relevant tests and
   documentation. Automated parity checks prevent new browser-only capabilities.
6. Every matrix gap remains required; slices order delivery without reducing the full target.

## Non-Goals

No new business rules, unrelated domains, shared credentials, automatic token widening,
production activation, commit/push, Main merge or deployment. No cross-device running-timer
service: the existing timer is client draft state. No automatic real customer messages,
billing settlement, stock mutation or schedule execution during QA. Existing Ticket Rules,
full-rerun, AI and Custom Field activation gates are not enabled by exposing an API.

## Current Behavior

The matrix is the current Dev implementation evidence. Metadata-only message reads and full
technician content are separate contracts. PATCH status_id does not call CompleteTask;
PATCH owner_id does not execute TicketAssignmentEngine. Task actual time and Ticket billable
time are separate authorities. Working workflow/quote/planned-line/review/evidence/customer-
reply APIs remain supported and must be reused.

## Proposed Change

### Typed domain contracts and shared actions

Add controllers, requests, resources and tests under the existing Ticket/Task modules.
Use existing module route ownership/registration; no domain routes in routes/web.php.
Keep existing API compatibility and place static context/capability paths before bindings.
Use shared authoritative domain actions. Extract inline browser logic only where needed,
preserving browser regressions. Read projections are explicit, bounded resources.

Do not implement an unrestricted dispatcher, arbitrary model/SQL access, user impersonation,
browser-controller redirect wrapper or Livewire transport as the API.

The matrix lists each proposed endpoint family and source behavior. Each Feature Slice
freezes exact fields, request/response/error schemas, ownership, side effects and tests
before coding. GET has no business writes; explicit read receipts use POST. PATCH documents
omitted/null behavior. Unsupported mutation fields fail clearly rather than appear saved.
Retain established compatible payloads/statuses unless an approved replacement is required.

### Authorization and visibility

Every operation requires Sanctum authentication, explicit token scope AND the current user's
domain/record/field/Work Context/membership rights. An API key cannot add browser rights its
actor lacks. Check current user status and current object permission at execution time.
Admin APIs retain admin and existing fine-grained operator permissions.

Add abilities only with implemented routes/tests. Proposed scope families are:

- Ticket: content.read, notes.create, attachments.read/write, read-state.write, solutions.write,
  time.read/write, costs.read/write/pick, assignment.execute/profile/manage, merge, classify,
  delete, documentation.request, relationships.escalate, reports.read, settings.read/manage,
  rules.read/manage/publish/execute and ai.use, all prefixed with tickets.
- Task: time.read/write, checklist.write, complete, assignment.write, status.write, ai.use,
  templates.read/manage/apply, schedules.read/manage/run and settings.read/manage, prefixed tasks.
- Portal: portal.tickets.read/create/reply/attachments, with current valid membership.

Resolve final ability names against ApiAbilityCatalog in each slice. Preserve existing
abilities where their documented contract is already appropriate. No token is modified or
reissued by this project. Wildcard tokens still require current actor/object permission.
Capability/readiness responses show only the current actor's permitted operations and safe
blocked reasons, including disabled runtime/provider dependencies.

Do not broaden existing tickets.read metadata into full conversations. Add explicit content
and attachment scopes/resources. Preserve external-messages as inbound sync; it cannot
impersonate technician authorship or a solution. Portal APIs use the authenticated customer
and selected valid membership, never an admin identity with arbitrary client_id. Use the
supported identity boundary without bypassing existing login/MFA or inventing shared tokens.

### Exact outcomes, retries and concurrency

Reuse current transactional idempotency: customer-reply keys, Task generation runs, receipt
identities and immutable rule publication. Keys never bypass current permission checks.
Same actor/operation/key and conflicting payload returns 409 with no duplicate effect;
valid replay returns original result references.

For a new non-repeatable command lacking a durable identity, its slice must specify/test a
minimal domain-owned request receipt or equivalent unique constraint before exposure. Store
fingerprints and result references, not raw customer bodies/files/secrets/provider payloads
in generic request logs. Locks, constraints, failure rollback and independent read-back must
prove no duplicate time, billing, stock pick, email, notification or Task generation. Ordinary
safe state updates do not require a new general execution platform.

Results identify exact affected resources and permitted read-back links. Queued, SMTP accepted,
provider reconciled and delivered remain separate states. Pending operations expose status
references; they must not return a misleading completed flag. Stale drafts/checksums,
cross-object IDs and billed/picked rows fail with bounded actionable errors.

### Ticket coverage

All T01-T27 matrix groups are required: full context/payload and recurrence/calendar linking;
conversation content/internal notes/solutions/technician notifications; reply files and binary
downloads; Ticket/message read receipts; rate/time reads/corrections and invoice suggestions;
cost/reservation edit/release/pick; assignment; merge suggestions/dismissal/merge without preview;
not-ticket/delete; documentation requests; bound Email reply drafts; Relationship escalation;
SLA data; personal/admin assignment; policies/catalogs; Rules lifecycle/history/retry; Portal.
Current workflow, quote, planned-line, review, evidence, publication and close APIs remain.

Reuse AddTicketMessage, MarkTicketMessageSolution, MarkTicketRead, UpdateTicketTimeEntry,
reservation/pick/release, MergeTickets and current rules/workflow services. Check nested
Ticket/message/time/cost/attachment ownership before effects. Full content does not expose
provider secrets or unrestricted metadata. Mailbox access remains required for source email.

### Task coverage

All K01-K12 groups are required: complete fields/tags/checklist/rate selections; hierarchical,
dependency/activity/time read-back; assignee/status activity and notification; checklist;
CompleteTask; actual time and Ticket billing delta; AI; template/item graph CRUD/usage/history;
owner lookup/preview/apply; schedules/manual generation/history; defaults/billing settings.

CompleteTask remains the completion boundary, including child/dependency/checklist/time guards.
RegisterTaskTimeEntry retains actual vs cumulative billable minimum, pending Ticket projection,
rate authority, protected coupled rows and Worklog deduplication. Do not invent Task deletion or
time correction where no browser-supported operation exists.

ApplyTaskTemplate remains the atomic application boundary. Templates stay mutable and edits
affect future applications only. Do not introduce version/publish/approval semantics. Preserve
graph/reference-delete, owner scope, catch-up limits and unique occurrence generation. Do not
add arbitrary Asset/Site ownership just because high-level future-facing docs mention it.

## Delivery Slices After Approval

One primary implementation at a time. Each slice receives a concrete Feature Slice file,
baseline, tests, documentation, read-back evidence and current status. All ten remain required.

| Slice | Required complete result | Matrix |
|---|---|---|
| 01 | Authorization/capabilities/context and bounded read-back contracts | T01-T02, T21, K01, K12, X01-X02 |
| 02 | Ticket notes/solutions/content/reply files/download | T05-T08, T10 |
| 03 | Ticket time/cost correction, invoice suggestion, release/pick | T11-T13 |
| 04 | Ticket full fields/schedules/read/assign/merge/classify/delete | T03-T04, T09, T14-T17 |
| 05 | Task fields/assignment/status/checklist/completion/time/AI | K01-K07 |
| 06 | Task templates/graph/targets/preview/apply/history | K08-K09 |
| 07 | Task schedules/generate/history and settings | K10-K11 |
| 08 | Ticket settings/catalogs and assignment administration | T22-T23 |
| 09 | Ticket Rules draft/preview/publish/version/history/retry | T24-T25 |
| 10 | Cross-domain/Portal/SLA and full API/schema acceptance | T18-T20, T26-T27, X01-X02 |

## Implementation Checkpoint (2026-10-20)

All ten slices are implemented on Dev. The complete matrix covers 130 browser route rows,
214 API route rows and 216 HTTP operations: 113 reads and 103 writes/actions. Swagger includes
all actual methods, request/response/error shapes, multipart/binary contracts and cumulative
or conditional grants. PUT/PATCH aliases and all schema references are checked automatically.
The generated JSON was independently fetched over trusted HTTPS with HTTP 200.

Shared actions preserve domain authority for fields, conversation/files, ledgers, Task completion,
actual versus billable time, template graphs/generation, schedules, settings, assignment, workflow,
Rules, Mail, Knowledge, Relationships and Portal membership. Legacy API routes now reside in the
modules' guarded routes.php files. Current user permissions and customer context also apply to
legacy actions. Full-operation input field lists prevent partial validation stages from silently
accepting unsupported writes. Existing resource identifiers and supported request flows remain;
quote/review/evidence responses now use the explicit safe read-back projections.

Final broad regression: 66 files, 560 passed / 15,569 assertions. The subsequent legacy
internal-Ticket Task-owner regression failed before its narrow context fix; afterward all 12
affected Task/read-back/lifecycle/Swagger files passed 106 tests / 11,079 assertions. The existing
non-null owner/customer/private invariants remain enforced, with no Ticket backfill or GET write.
Final evidence and remaining human checks are recorded in
../plans/2026-10-20-ticket-task-api-acceptance.md and HR-2026-10-20-TICKET-TASK-API.

No real customer/provider sends, operational stock/billing writes, migration, runtime-switch
activation, token widening, commit/push, Main promotion or production deployment were performed.
Repository Knowledge sources are updated. BookStack publication remains blocked by missing
supported Dev integration/access configuration; ten unrelated pending articles must not be
bulk-pushed. Svein owns the supported path and Codex the subsequent exact-source publication,
content read-back and employee-access verification.

## Impact Analysis

Ticket/Task own endpoints/actions. Integration owns implemented abilities/API docs. Email,
Storage, Economy, Commercial, Calendar, Knowledge, Relationship, CustomerPortal and Notification
retain existing integrated behavior. Add affected-owner regression tests. UserManagement/current
identity remains authoritative. No unfinished visible UI control is added.

Dev has overlapping dirty Ticket controllers/resources/tests, Task changes, ability catalog,
TODO and human review. Capture/re-read exact baselines before editing and preserve contributions.
The explicit user priority is Ticket/Task parity, not unrelated work or reopening closed Issues.
Open #285 retains Rules production activation, #262 scheduler/queue runtime and #275 Documentation
revision ownership. Their gates are not completed by this API work. Worklog #288/#289/#290 remain
separate deployment/access contracts, not reasons to weaken policy for record-level time APIs.

## Data And Migration Plan

The approved implementation reuses current tables; no schema migration has been introduced.
Task time retry receipts use existing Task activity under a Task row lock; stock and note retries
use their existing domain rows/unique identities. Most adapters reuse current tables.
If a command needs a durable identity, its slice documents the exact additive migration,
uniqueness/locking and rollback/retention policy before application. No broad migration/seeder,
token change, production backfill or data cleanup runs implicitly. Rollback removes/disables
only new API access; actual time, billing, mail, stock, Task groups and audit history are not
deleted to pretend effects were reversed.

## Testing Plan

- Run narrow existing Dev baselines before editing; fix or report failures explicitly.
- Map every browser business operation and persisted Livewire outcome to an official API;
  distinguish UI-only presentation and existing verified equivalents.
- Test token-only/user-only denial, inactive identity, nested ownership, visibility, context,
  field rights, Portal membership and sensitive content/file permission.
- Test exact validation/status/resource and independent GET read-back of writes.
- Prove complete rejects unchecked checklist, dependencies, children and required missing time;
  PATCH status must not masquerade as domain completion.
- Prove actual/billable time, cumulative minimum, rate authority, coupled edit protection,
  Worklog deduplication, pending/ordered/invoiced boundaries and inventory lifecycle.
- Retry/concurrency/failure tests cover email, notifications, time, pick, merge, Task groups,
  schedule occurrences and rule publication/retry. No repeated effects.
- Test JSON/multipart/binary limits and safe filenames; compare every HTTP method/path/parameter
  to generated OpenAPI, including PUT/PATCH aliases, pagination and error contracts.
- Denied AI policy, missing provider, disabled capability or absent external scheduler has a
  truthful error/readiness result, never successful-looking inactivity.
- Run affected Ticket/Task and integrated-owner tests on Dev with synthetic/faked effects;
  no real customer email or operational stock mutation during QA.
- Record named human review under HR-2026-10-20-TICKET-TASK-API before relevant Main promotion,
  migrations/deployment or release. Tests never mark human review complete.

## Documentation Plan

Update Ticket/Task and API Management authoritative Knowledge for every delivered slice:
prerequisites, menus/operations, abilities, payload/results, error/retry recovery, Dev-only
availability and delivery state. Publish through the supported Knowledge integration and read
back actual BookStack article content and employee access scope. A local Markdown edit is not
publication. Record blockers, remaining action and owner in TODO.

Update matrix, Feature Slice status, TODO and human review in the same implementation session.
Create an ADR for significant new shared authorization/receipt/response architecture decisions.
Only completed, verified public-safe behavior enters the website handoff; this draft is not a
feature announcement.

## Open Questions

Svein explicitly approved cross-client Ticket merging without a preview on 2026-10-20. The API must not require a preview token or same-client restriction. It captures and verifies current rows inside its transaction, binds retries to actor/request, and retains Mail access/ordinary authority. Ticket-owned Tasks and active stock reservations follow the target ownership context. Historical actual time, rate snapshots, allocations and existing orders remain auditable. Unallocated pending contract references from another client cannot consume the previous client's contract; clear those references with original values recorded in Ticket history while retaining the recorded amount. Already allocated financial rows retain their original customer/contract snapshots and pending generation must honor that allocation customer. No existing invoice/order is reassigned or recreated by merge. Exact
schemas, final ability names and extraction details are reversible technical choices documented
in the slices. New privacy/business/permission behavior beyond equivalent browser access
requires a revised approval before implementation.

## Approval

Svein Tore explicitly approved this concrete RFC in conversation on 2026-10-20:
The explicit approval was supplied in Norwegian. It authorizes the full existing-behavior
Ticket/Task API parity scope and its delivery sequence on authoritative Dev. Approval is not
human acceptance of implementation and does not authorize Main promotion or production deployment.

## Scoped Main Promotion Authorization (2026-10-28)

Svein Tore explicitly requested "Godkjenenr og merge" after the complete Dev handoff. This separately authorizes the scoped commit/push/PR/Main merge previously excluded from the implementation scope; it does not confirm execution of the operational checklist or authorize production deployment. See [promotion evidence](../plans/2026-10-28-ticket-task-api-main-promotion.md).

## Final route audit (2026-10-28)

The full target also includes the browser Task Docs Markdown file. GET /api/v1/tasks/docs now shares its authoritative reader with the browser and requires tasks.read plus current task.view. Final coverage is 217 operations (114 reads, 103 writes/actions), 130 browser rows and 63 Livewire public methods, with explicit classifications, API counterparts, contracts, scopes, tests and read-back references. Existing retry-key/rule-draft conflicts are documented as HTTP 409. These complete the approved parity target without changing business permissions or enabling runtime gates. See the current promotion record and coverage matrix.
