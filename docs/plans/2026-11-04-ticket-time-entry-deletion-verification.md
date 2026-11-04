# Guarded Ticket Time Deletion - Verification And Main Promotion

Date: 2026-11-04
Owner: Codex; approval, manual reviewer and promotion owner: Svein Tore.
Parent: ../rfc/2026-11-04-ticket-time-entry-deletion-api.md (Approved).
Slice: ../feature-slices/2026-11-04-ticket-time-entry-deletion.md.
Human-review checklist: HR-2026-11-04-TIME-DELETE Pending.

## Authorization And Scope

Svein approved "Godkjenner. Lag det og merge til main" on 2026-11-04.
This authorizes the bounded implementation, source commit/push/PR and Main merge.
It does not approve manual checks, production/gateway rollout, grants or real-case cleanup.

Ordinary implementation was performed directly in /var/Projects/tdPSA on Dev, preserving
all prior dirty/staged/untracked work. A separate promotion worktree is based on actual
origin/main aad4945fb619505ffebb2efaf0d8b1bdb241563d. Only this task's new files and
specific route/catalog/documentation/matrix additions were transferred to that candidate.
No unrelated Dev difference is included. Composer files match Main; independent Composer
metadata was used, and ReflectionClass confirms the candidate action loads from its own
checkout rather than through Dev's autoload map.

## Delivered Behavior

- Actor-bound, ten-minute GET deletion preview with exact record and blocker codes.
- DELETE of pending manual direct time with reason, preview token and idempotency key.
- Independently readable deletion audit receipt after the source row is gone.
- Dedicated tickets.time.delete scope plus current ticket.view, ticket.register_time and
  ticket.update rights, active actor, nested/context and workflow checks.
- No deletion of Task-derived, processed, allocated, ordered or retained Workday sources.
- Transactional original-row snapshot/actor/reason; exact retries reuse one event and
  do not advance Economy revision again. No generic SQL/model endpoint.

EconomyMutex and worker/source/Ticket locks serialize against existing producers.
Ownership is read before opening the transaction; transactional dependency reads use
current locking reads so InnoDB REPEATABLE READ cannot hide a just-committed reference.

## Automated Verification

Authoritative Dev, nine-file affected matrix: 91 tests / 43,290 assertions passed.
Independent latest-main candidate, same nine files: 91 / 43,259 passed.
After final locking/retry/formatting changes, TicketTimeDeletionApiTest,
TicketLedgerApiTest and WorkdayEntryApiTest pass on BOTH Dev and the independent
candidate: 27 tests / 346 assertions each. These overlap the broad matrix; do not add
them as unique test totals. No full application test suite was run.

The matrix includes Ticket read-back, current authority boundaries, complete Ticket/Task
Swagger/inventory/schema references, Economy API, Workday API/contract and Report.
New deletion coverage includes another technician's row, original preservation, retained
exact audit/independent receipt, each scope/permission, wrong nesting, invalid fields,
changed/expired/wrong-actor previews, Task references, financial dependencies, historical
and pre-index Workday references, missing-schema denial and rollback on audit failure.
Four new PHP files pass syntax checking and Pint; scoped diff whitespace checks pass.

Project PHPUnit intentionally refuses non-isolated databases. That guard was preserved.
The configured Dev MySQL connection returned code 2002; no connection setting, firewall
or operational data was changed. Direct PHPUnit used the project's isolated SQLite
configuration instead of Artisan's database-dependent outer boot.

## Native Engine Concurrency

A temporary private-socket MariaDB 10.11.19/InnoDB instance on the Dev host used only
an isolated candidate schema and synthetic fixtures. No external port, actual employee,
customer, invoice, Workday or production record was used. The authoritative Dev contains
unrelated in-progress security schema, so native fixtures use the independently tested
Main candidate; no security guard or existing application schema was relaxed.

Three independent-connection checks passed:
1. Two concurrent exact deletion requests return the same event; one row and one event.
2. A financial writer holds the actual EconomyMutex and commits a synthetic allocation.
   Deletion waits, returns 409 and preserves the source/allocation without a deletion event.
3. A Workday writer holds worker -> source -> Ticket locks and commits a retained source
   reference. Deletion waits, returns 409 and preserves source/evidence without an event.

Sanitized native/OpenAPI evidence is included with this plan. This proves the isolated
native-engine boundaries; it is not a claim of production concurrency or human acceptance.
The private test server and synthetic request/fixture files are stopped/removed after QA.

## OpenAPI And Documentation

The generated independent candidate Swagger artifact was read back: all three exact
operations, operation IDs, deletion scope and 200/401/403/404/409/422/503 contracts exist.
This is generated-source evidence, not a claim of deployed HTTPS/gateway availability.
The tracked Swagger artifact receives only these three operations and their missing
transitive schema dependencies; every pre-existing path/schema is preserved. The
existing Dev artifact independently contains the same three exact operations; it is
not copied wholesale into this promotion.
The existing parity matrix now inventories the three additional operations separately
from the original browser parity scope.

Repository Ticket Time Registration and API Management sources are updated.
Their exact new sections were published through the supported BookStack connector:
- page 427, revision 34, Ticket Time Registration;
- page 449, revision 167, Api Management.
Both actual stored sections match the reviewed source exactly and preserve all earlier
content. Existing page and chapter permissions inherit; book 339 and global view roles
were inspected without changes. Existing Editor/Viewer view permissions provide the
ordinary employee reading path. Employee-specific OpenWebUI retrieval remains untested.
No unrelated pending Knowledge articles were bulk-pushed or audience grants widened.

## Remaining Human And Production Gates

HR-2026-11-04-TIME-DELETE is Pending for controlled manual client use, exact-source/
receipt/original preservation, denied/protected states, intended DELETE-body support
and actual employee handbook retrieval. It gates production release, not the source
merge Svein separately authorized. No checkbox is inferred Reviewed from tests/merge.

Related broad HR-2026-10-20-TICKET-TASK-API remains Pending,
HR-2026-09-27-WORKLOG remains Pending, and HR-2026-11-04-TICKET-MESSAGE-API remains
In Review. Their unperformed manual/provider/release checks are not claimed complete.

No migration/package/frontend build is introduced. After separately authorized deployment,
verify existing Economy/Workday schema, refresh normal cache/opcache, regenerate Swagger
with php artisan l5-swagger:generate and read back real deployed operations. No queue/
scheduler start, token grant or gateway catalog update is part of this merge.
Code rollback must preserve audit and must not silently restore or re-delete removed data.

## Promotion Record

The containing PR promotes only this verified scope to main. The actual PR, merge SHA and
independent origin/main read-back are reported in the handoff and authoritative Dev TODO.
Production and Benjamin's reported duplicate entries remain unchanged by this work.
