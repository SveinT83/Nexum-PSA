# Ticket and Task actor attribution verification - 2026-11-12

## Authority and state

Svein Tore approved the RFC in chat: "Ja godkjenner". Implementation is in the
authoritative /var/Projects/tdPSA Dev working copy. No commit, push, Main change,
production deployment, customer/provider action or worker/scheduler activation.
Pre-existing dirty work is retained; source preimages are outside the repository
under /tmp/nexum-actor-attribution-20261112.

RFC: ../rfc/2026-11-12-ticket-task-actor-attribution.md.
ADR: ../adr/2026-11-12-explicit-ticket-task-actor-attribution.md.
Slice: ../feature-slices/2026-11-12-ticket-task-actor-attribution.md.
Human-review checklist: HR-2026-11-12-TICKET-TASK-ATTRIBUTION Pending before
Main promotion/production release. Approval to implement is not manual acceptance.

## Implementation

Compact Bootstrap attribution below Ticket subject / Task title, wrapping without
making the Ticket identity column unshrinkable. Names are escaped; dates use
UserDateTime. No new Portal actor projection. Safe actor objects expose ID/name.

Shared explicit actor concern preserves original creator and records the last
domain actor/time. Unknown writes clear the updater rather than reuse a previous
employee or ambient Auth login. actor_updated_at is independent of technical
updated_at. Reading, is_unread-only updates and no-op saves preserve attribution.

Record-owned Task tags/checklist, Ticket tags/custom fields and schedule changes
use an explicit related-mutation marker only after a real difference. Unchanged
checklist text keeps checked state. Related changes and attribution are atomic.

## Mutation inventory

| Surface | Attribution authority |
| --- | --- |
| Ticket create/browser/API | StoreTicket explicit actor and retained scheduled creator evidence |
| Ticket field/status/close/workflow/escalation/pause | Explicit action actor; workflow final assignment/type/SLA writes retain it |
| Ticket automatic assignment and rule rerun | Optional explicit actor passed through engine; verified rule system actor retained |
| Ticket portal publication | Explicit authorized publisher, with no-op guard |
| Ticket merge | Source/target actions and Task context reconciliation retain explicit merger |
| Ticket tags/custom fields | Canonical actions record a real related change |
| Ticket schedules | Compare stored schedule fields excluding technical timestamps and actor bookkeeping |
| Task create/templates | StoreTask verified creator initializes both actor IDs |
| Task browser/API field edit | Explicit authenticated actor; immutable creator |
| Task assignment/status/completion | Explicit shared domain action actor |
| Task tags/checklist | Verified change with same-transaction parent attribution |
| Telephony and manual Intake creation | Retain supplied actor through final Ticket metadata write |
| Inbound relationship/Signal/scheduler without verified local actor | Unknown; preserve external provenance without inventing a person |
| Comments/time/source activities/read state/technical touches | Own authors retained; no editor invented on parent unless parent domain changes |
| Raw SQL/bulk updates | Must explicitly maintain attribution when they change parent domain fields; hooks cannot intercept raw SQL |

No new anonymous actor is provisioned on Dev. The managed automation identity is
exercised only inside the isolated SQLite test harness.

## Migration and runtime

Read-only preflight: Task created_by exists; Task updated_by is absent; Ticket has
both existing IDs; both new timestamps absent. The scoped additive migration
2026_11_12_120000_add_ticket_task_actor_attribution.php was applied on Dev and all
six relevant columns read back present. Historical rows were not backfilled.
ID type matches existing configurable-user-table conventions; no hardcoded FK.

Runtime confirmed through installed Laravel Boost tools: PHP 8.4.27, Laravel
12.69.1, MySQL. Isolated tests enforce SQLite :memory: in Tests/TestCase.
Trusted HTTPS Dev login returns 200. Actual browser detail responses for both
domains are covered by feature tests. Desktop/mobile visual acceptance is pending.

## Verification evidence

Initial focused: 11 tests / 79 assertions passed.
Expanded focused: 14 tests / 93 assertions passed.
Full affected Task/Ticket run: 584 passed, 1 fixture failure / 47,889 assertions.
The recurrence fixture wrote a fake missing creator using an ordinary model edit;
creator immutability correctly blocked that. The fixture now seeds legacy evidence
directly in isolated SQLite, without weakening recurrence assertions or runtime rules.
Final rerun of that fixture and latest changed surfaces is recorded below.
Telephony/Intake matrix: 32 passed / 233 assertions.

Final affected rerun: 72 passed / 42,534 assertions, including all16 new attribution
tests, TaskModule, scheduled SLA, both recurrence-parity cases, Portal API, lifecycle
API and full OpenAPI/route-parity tests. The original recurrence failure is resolved.
The whole Task/Ticket matrix had585 cases; its sole failing fixture was corrected
and both cases passed in the final rerun. Five additional attribution cases were
added after the broad run. These matrices overlap; do not sum all runs as unique tests.
The32 Telephony/Intake cases are additional to Task/Ticket.

Final generated served Swagger has all five actor fields and readOnly markers in
both record schemas. Installed Boost/runtime read-back passes. All42 affected
PHP/Blade files pass php -l and scoped Pint and are web-readable. git diff --check
passes. Blade cache cleared with umask0002. Trusted Dev HTTPS login200.
Raw test logs are outside the repo at /tmp/nexum-attribution-*-tests.log.

## Handbook

Repository source sections updated in Ticket Overview, Task Overview, Ticket
API context/read-back and Task API operations.
Supported BookStack plugin appended only the approved Dev section to existing
Task Overview page398 revision13 and Ticket Overview page409 revision42.
Independent exact section read-back succeeded; all prior page content, ownership
and hierarchy were preserved. Page/chapter permissions inherit book339's current
documentation-role16/17 view grants; no access settings changed.
This is scoped source/BookStack publication. No bulk native Knowledge import,
general push/pull or unrelated pending-article synchronization was performed.
Actual employee assistant retrieval remains a separate HR checklist item.

## Release, rollback and remaining checks

Main/production are unchanged. Svein owns review and any later promotion/release.
Deploy only after the named manual review and a separately authorized rollout:
apply the scoped additive migration before activating the matching model code,
regenerate Swagger, clear Blade cache with umask0002 and preserve compiled-view
group-write permissions. No frontend build or queue/scheduler change is required.

Rollback code before dropping the new columns. Dropping columns loses only new
updater/time evidence; existing Ticket actors and all original work remain.
No rollback or production migration was run in this session.

Manual remaining: desktop/mobile readability, second-person create/edit checks,
API agreement, historical Unknowns, private Task boundaries and intended employee
assistant handbook retrieval. Public website editorial handoff retains explicit do-not-publish gates for
Main/production/manual acceptance; publication is not authorized.

## Changed PHP/Blade sources and tests

- app/Modules/Intake/Actions/RouteIntakeSubmissionToTicket.php
- app/Modules/Intake/Tests/Feature/IntakeModuleTest.php
- app/Modules/Task/Actions/AssignTask.php
- app/Modules/Task/Actions/ChangeTaskStatus.php
- app/Modules/Task/Actions/CompleteTask.php
- app/Modules/Task/Actions/ReplaceTaskChecklist.php
- app/Modules/Task/Actions/SetTaskChecklistState.php
- app/Modules/Task/Actions/StoreTask.php
- app/Modules/Task/Controllers/Api/V1/TaskController.php
- app/Modules/Task/Controllers/Tech/TaskController.php
- app/Modules/Task/Models/Task.php
- app/Modules/Task/Resources/Api/V1/TaskResource.php
- app/Modules/Task/Services/TaskApiPayload.php
- app/Modules/Task/Tests/Feature/RecordActorAttributionTest.php
- app/Modules/Task/Views/Tech/Tasks/show.blade.php
- app/Modules/Telephony/Actions/CreateTicketFromTelephonyCall.php
- app/Modules/Telephony/Tests/Feature/TelephonyModuleTest.php
- app/Modules/Ticket/Actions/EscalateTicketWorkflow.php
- app/Modules/Ticket/Actions/MergeTickets.php
- app/Modules/Ticket/Actions/MutateTicketTags.php
- app/Modules/Ticket/Actions/PublishTicketToCustomerPortal.php
- app/Modules/Ticket/Actions/ReconcileTicketOwnedWork.php
- app/Modules/Ticket/Actions/SelectTicketWorkflowForCreation.php
- app/Modules/Ticket/Actions/StoreTicket.php
- app/Modules/Ticket/Actions/SwitchTicketWorkflowByRule.php
- app/Modules/Ticket/Actions/SyncTicketCustomFieldValues.php
- app/Modules/Ticket/Actions/TransitionTicketWorkflow.php
- app/Modules/Ticket/Actions/UpdateTicketFields.php
- app/Modules/Ticket/Controllers/Tech/TicketController.php
- app/Modules/Ticket/Models/Ticket.php
- app/Modules/Ticket/Resources/Api/V1/TicketResource.php
- app/Modules/Ticket/Services/TicketAssignmentEngine.php
- app/Modules/Ticket/Services/TicketLegacyApiOpenApi.php
- app/Modules/Ticket/Services/TicketRuleCompatibilityActionExecutor.php
- app/Modules/Ticket/Services/TicketRuleSchema2ActionExecutor.php
- app/Modules/Ticket/Services/TicketRuleWorkflowAssignmentPolicy.php
- app/Modules/Ticket/Services/TicketWorkflowMigrationService.php
- app/Modules/Ticket/Tests/Feature/TicketRuleScheduledOccurrenceParityTest.php
- app/Modules/Ticket/Views/Tech/Tickets/show.blade.php
- app/Modules/UserManagement/Support/RecordsActorAttribution.php
- database/migrations/2026_11_12_120000_add_ticket_task_actor_attribution.php
- resources/views/components/record-attribution.blade.php

## Final editorial handoff and housekeeping

One public-safe Norwegian item was appended and read back in the existing active
profile Content and Visibility queue; item count38 ->39. Status is klar for vurdering,
with explicit do-not-publish gates for manual acceptance and Main/production.
No public website or social publication occurred.

Temporary PHP preflight/runtime scripts were removed from /tmp. Test logs,
owned-delta patch and protected pre-existing source snapshots remain outside the
repository for review. Final Dev branch and git diff --check verified unchanged/clean
with respect to branch name and whitespace; the shared working copy remains dirty
as expected, with all unrelated contributions retained.
