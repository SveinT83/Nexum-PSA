# RFC: Ticket and Task creator and updater attribution

Status: Approved
Date: 2026-11-12
Owner: Codex; product approver: Svein Tore
Change level: 3 (cross-module API and additive Task schema change)

## Context

Staff need to see who created a Ticket or Task and who last updated it, both in
the technician workspace and through the official API.

## Goals

Display compact, readable attribution and expose equivalent typed API fields.
Preserve creator identity and accurately attribute subsequent changes.

## Non-Goals

A new audit-history system, new permissions, customer-portal identity disclosure,
historical identity guessing, or production deployment.

## Current Behavior

Authoritative Dev branch inspected on 2026-11-12:
- Ticket model has created_by and updated_by; StoreTicket initializes both and
  several mutation actions already stamp updated_by. TicketResource omits both.
- Task model has created_by and creator relation. StoreTask records the creator.
  TaskResource exposes created_by but not creator name or updated_by.
  Task has no updated_by in its fillable list; a nullable migration is proposed,
  subject to live schema preflight before implementation.
- Ticket and Task detail page headers have no common attribution line.
- Existing parent API parity work is implemented; its remaining operational
  human checks are separate. No dedicated attribution RFC was found.
- GitHub open-issue search for updated_by returned no result. Broader created-by
  results concerned other domains; no duplicate Issue is claimed or created.
- Shared Dev contains substantial unrelated changes, including Ticket.php and
  TODO.md. Preserve them and recheck relevant diffs before implementation.

## Proposed Change

1. Below the Ticket subject / Task title, show a small muted wrapping line:
   Created by: Name - timestamp | Updated by: Name - timestamp.
   Use Bootstrap and existing user date/time formatting. Keep contrast readable.
2. At creation, initialize creator and updater to the verified actor. Keep the
   creator immutable during ordinary updates. At a successful meaningful record
   edit/action, stamp the updater in the same transaction.
3. updated_by means the actor responsible for the most recent persisted domain
   change to the record, including status, assignment and explicit metadata
   actions. Reads, no-op saves and technical touches must not falsely attribute
   a human. Related comments/time entries retain their own authors; only actions
   that change the parent record affect parent attribution. Inventory those
   paths before implementation and keep attribution timestamps consistent.
4. Reuse explicit authenticated actor arguments in browser/API actions. API
   callers cannot submit or override attribution. Integrations must use the
   established verified service/system actor; never substitute the assignee.
5. Distinguish known system changes from missing historical identity. Render
   System only with verified system provenance; otherwise Unknown. Preserve
   existing portal/contact provenance without exposing customer identity or
   converting a contact ID into a staff user ID.
6. API list/detail/create/update/action record read-backs consistently include
   nullable integer created_by and updated_by, plus nullable creator/updater
   references containing only id and name. Keep existing fields and timestamps
   compatible. Document null/unknown semantics in Swagger/OpenAPI. Read access
   and private-Task boundaries remain authoritative.
7. Existing missing values stay null/Unknown; no speculative historical backfill.

## Impact Analysis

Ticket/Task models, mutation actions and external creation adapters, queries,
technician detail views, official resources, OpenAPI schemas and parity coverage.
Audit all supported record-changing paths, including automation, templates,
completion/reopen, assignment, tags and custom fields. Preserve existing policy,
token scope, Work Context and private Task visibility; expose no email addresses.
Eager-load actor references to avoid per-record queries. Add no new route or
permission, provider switch, worker or scheduler activation. An ADR should record
the approved attribution semantics alongside implementation.

## Data And Migration Plan

Preflight actual Dev Task schema. Add nullable tasks.updated_by with a foreign
key matching existing user-key conventions and safe deletion behavior; preserve
historical values and timestamps. Reuse Ticket fields. Apply the additive
migration before updated code and verify synthetic create/update read-back.
Rollback code before removing the new column; column rollback loses only new
updater attribution. Migration and production installation require their own
authorized execution. Confirm whether a separate actor timestamp is needed if
existing technical touches make updated_at inconsistent with the stated actor.

## Testing Plan

Laravel feature tests on authoritative Dev for both browser and API creation,
different-user edits, immutable creator, spoofed actor inputs, no-op/read behavior,
status/assignment actions and explicit system/unknown provenance. Verify nullable
legacy/deleted users, private Task denial, list/detail and mutation read-back,
OpenAPI compatibility and query counts. Render desktop/mobile headers and confirm
existing controls still fit. Add focused regressions for every discovered write
path that could miss or incorrectly overwrite attribution.

## Documentation Plan

Update Ticket/Task authoritative Knowledge sources, module/API docs and parity
evidence. Publish only affected articles through supported BookStack sync and
read back content and employee scope, clearly distinguishing Dev from production.
Record publication blockers in TODO with owner and next action.

## Open Questions

No blocking product decisions remain. actor_updated_at separates domain edits from
technical updated_at touches. Existing configurable-user-table ID conventions are retained.

## Approval

Approved by Svein Tore on 2026-11-12 in chat: "Ja godkjenner".
Initial Dev implementation approval was followed by explicit Main/production
authorization on2026-11-12: "Godkjenner. Merge til Main og push til produktsjon". The human-review checklist
HR-2026-11-12-TICKET-TASK-ATTRIBUTION is Reviewed by Svein Tore on2026-11-12.

## Delivery

Implemented and verified on Dev, merged Main PR403 and installed/verified in
production; scoped migration applied and read back. Evidence: ../plans/2026-11-12-ticket-task-attribution-verification.md.
Handbook availability published/read back in BookStack409/43 and398/14.
Human feature/release approval is recorded; employee-assistant retrieval remains
separate and unverified. Rollout: ../plans/2026-11-12-ticket-task-attribution-main-production.md.
