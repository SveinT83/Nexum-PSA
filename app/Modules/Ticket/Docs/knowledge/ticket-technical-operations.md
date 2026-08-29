This article summarizes the technical operating model for the Ticket domain.

It is intended for developers and administrators who need to understand where behavior lives, what should be tested, and which boundaries should not be bypassed.

## Module Ownership

Ticket code belongs in `app/Modules/Ticket`.

Domain routes must stay in:

```text
app/Modules/Ticket/routes.php
```

Do not add Ticket routes to `routes/web.php`.

Controllers should stay under:

```text
app/Modules/Ticket/Controllers
```

Views should stay under:

```text
app/Modules/Ticket/Views
```

## Important Actions

Use action classes instead of duplicating behavior in controllers.

Important actions:

- `StoreTicket`
- `AddTicketMessage`
- `PublishTicketToCustomerPortal`
- `StoreIdempotentTicketCustomerReply`
- `CreateTicketFromInboundEmail`
- `LinkInboundEmailToTicket`
- `ChangeTicketStatus`
- `CloseTicket`
- `MarkTicketRead`
- `MarkTicketMessageSolution`
- `MergeTickets`
- `RegisterTicketTimeEntry`
- `ReserveTicketStorageItem`
- `UpdateTicketStorageReservation`
- `ReleaseTicketStorageReservation`
- `UpdateTicketTimeEntry`
- `ApplyTicketSla`
- `AssignTicketOwner`
- `MutateTicketTags`
- `SyncTicketCustomFieldValues`
- `SelectTicketWorkflowForCreation`
- `TransitionTicketWorkflowByRule`
- `SwitchTicketWorkflowByRule`
- `SetTicketRuleWorkflowAutomationPause`

## Important Services

Important services:

- `TicketRuleEngine`
- `TicketAssignmentEngine`
- `TicketSlaResolver`
- `TicketWorkflowRuntime`
- `TicketActionGuard`
- `TicketMergeSuggestionService`
- `TicketReplyContactResolver`
- `TicketRuleExecutionCoordinator`
- `TicketRulePreviewService`
- `TicketRulePreviewQueueSimulator`
- `TicketRuleFullRerunBoundary`
- `TicketRuleExecutionPresenter`
- `TicketRuleSchema2ActionExecutor`
- `TicketRuleSchema2ConditionEvaluator`

These services own reusable business rules. Avoid reimplementing those rules in views or controllers.

## Ticket Rule Versioning And Compatibility Operations

Feature Slice 1 for Ticket Rule versioning is an additive compatibility foundation. It does not
authorize or expose the future v2 runtime. `TicketRuleEngine` remains authoritative for the current
`on_create` rules, Queue remains the routing group, and Owner remains the individual assignment.

### Runtime Authority And Immutable Evidence

The foundation adds lifecycle and compatibility metadata to `ticket_rules`, immutable snapshots in
`ticket_rule_versions`, and the `ticket_rules` row in `ticket_rule_authority_fences`.

- `runtime_authority` defaults to `legacy`; this slice has no command, route, or UI that changes it.
- The authority fence stores a monotonic catalog generation and canonical full-catalog checksum.
- Current Admin create, edit, toggle, and delete writes lock that fence and advance the generation
  whenever the definition catalog changes. Runtime hit counters are operational data and do not
  advance it.
- Each compatibility snapshot stores canonical definition JSON and a definition-only SHA-256
  checksum. Definition order, normalized weight, and stop behavior are part of that checksum;
  counters and other operational noise are not.
- Model guards and database triggers named `ticket_rule_versions_update_guard` and
  `ticket_rule_versions_delete_guard` reject update and delete attempts. A later edit therefore
  marks the logical rule as drifted instead of rewriting version 1.
- Legacy compatibility provenance is `legacy_backfill`. Historical `published_by`, `published_at`,
  and the optional non-user `provenance_key` remain null when unknown. Do not create or infer a
  publisher.

The read-only preflight enumerates `TicketRule::withTrashed()`, returns bounded sanitized details,
and reports valid, invalid, ambiguous, eligible, already-versioned, unversioned, drifted, skipped,
and deleted counts. Invalid or ambiguous rules retain their current source definition and
`is_active` value and remain ineligible for v2. Soft-deleted definitions may retain immutable
history but remain outside runtime selection.

### Migration Order And MariaDB Resume Safety

After a reviewed backup, run only the two Slice 1 migrations in this order:

```bash
php artisan migrate --path=database/migrations/2026_08_25_240000_create_ticket_rule_versioning_foundation.php --force
php artisan migrate --path=database/migrations/2026_08_25_241000_deploy_ticket_rule_publish_permission.php --force
```

`provenance_recorded_at` deliberately uses MariaDB `DATETIME`, not a non-nullable `TIMESTAMP`.
The original `TIMESTAMP` form was rejected by MariaDB before the final path-scoped migration
succeeded.

MariaDB DDL may auto-commit before a migration runner records completion. Migration 240000 can be
retried while the version table is empty and the fence is still `legacy` at generation 0: it
recognizes an existing version table, published-version foreign key, fence table/row, and rebuilds
both immutability triggers after an interrupted one-trigger stage. It fails closed if immutable
versions or runtime-authority evidence already exist. Do not manually delete evidence to force a
retry.

Migration 241000 creates `ticket.rule_publish` once, grants it additively to existing Admin and
Superuser roles, verifies each available grant, and preserves unrelated permissions. Canonical
fresh seeds include the same permission; do not run `RoleSeeder` as a deployment shortcut. Its
`down()` is intentionally forward-only and does not revoke a reviewed grant.

These migrations are already recorded as Ran in batch 4 on authoritative Dev. Do not rerun or roll
them back there.

### Preflight And Gated Backfill

Run the read-only inspection first:

```bash
php artisan ticket-rules:compatibility-preflight --limit=100
```

Review `status`, `runtime_authority`, `catalog_generation`, `catalog_checksum`,
`fence_matches_catalog`, `mapping_complete`, counts, and bounded details. Copy the exact generation
and lowercase 64-character checksum into the gated command; replace the uppercase placeholders
before execution:

```bash
php artisan ticket-rules:backfill-compatibility --expected-generation=GENERATION_FROM_PREFLIGHT --expected-checksum=SHA256_FROM_PREFLIGHT --provenance-key=deploy.issue-231 --confirm-write
```

`--provenance-key` is optional and must identify a non-user deployment or operator context. The
write is refused without `--confirm-write`, if the catalog generation/checksum changed, or if
authority is no longer `legacy`. A successful run remains on legacy authority. Re-run the preflight
afterward; it must show the reviewed mapping state before any later slice may propose activation.

### Rollback Boundary

Before compatibility evidence exists, migration 240000 can remove its empty additive foundation.
After any immutable version, lifecycle/compatibility reference, advanced generation, or authority
change exists, destructive `down()` is refused. The operational rollback is a forward disable while
retaining evidence. Slice 1 itself has no v2 switch to disable.

### Authoritative Dev Evidence On 2026-08-25

- Migrations 240000 and 241000 ran path-scoped in batch 4 after the MariaDB `DATETIME` correction.
- Schema read-back found the version table, published-version foreign key, authority fence, and both
  immutable update/delete triggers.
- Dev contained zero Ticket Rules. Preflight returned `compatible`, `mapping_complete=true`, a
  matching checksum, `runtime_authority=legacy`, and `catalog_generation=0`.
- The exact gated zero-row backfill completed idempotently: zero versions were created, the version
  table stayed empty, and the authority fence remained legacy at generation 0. Read-back remained
  compatible and mapping-complete.
- `ticket.rule_publish` existed once and was granted to Admin and Superuser; Tech did not receive it.
  No `RoleSeeder`, v2 activation, queue dispatch, Signal, notification, or external delivery ran.

All new operator output and documentation for this foundation are English. Language files and
localization are deferred. Human review checklist
`HR-2026-08-25-013` remains Pending and still blocks the later release/activation decision.

## Ticket Rule Execution Foundation Operations

Feature Slice 2 adds the default-off execution, audit, preview, delivery, retry-selection, locking,
and loop-control foundation. It does not authorize v2 activation, Main promotion, production
migration, or production deployment.

### Runtime, Transaction, And Audit Contract

`runtime_authority=legacy` in `ticket_rule_authority_fences` and false
`config('ticket_rules.v2_enabled')` are independent fail-closed gates. A stale config/schema
combination never falls back silently. Explicit test-only execution is isolated; ordinary runtime
continues through `TicketRuleEngine` until a separately approved activation satisfies the complete
catalog and human-review gates.

For an approved v2 root, the coordinator holds the per-Ticket row lock and shared catalog fence,
freezes the published version set, and drains relevant derived events in deterministic FIFO order.
Each selected branch uses a savepoint. A branch failure rolls back only that branch, retains the
outer authorized Ticket mutation, records failed and later `not_run` positions, and permits later
rules unless a root budget, duplicate fingerprint, or successful stop decision halts the run.

Root, event, rule-execution, action-position, and after-commit records contain bounded safe evidence.
Completed evidence is protected by model guards and ten database immutability triggers. Raw message
bodies, attachments, secrets, tokens, authentication headers, and unrestricted payloads never enter
the durable audit. External action payloads remain in memory until commit; persisted delivery
evidence is minimized. An unresolved external delivery can be retried only after explicit
confirmed-not-delivered reconciliation, and only the reconciliation fingerprint is retained.

### Actor And Operator Permissions

The protected non-login `ticket_rule_automation` actor has exactly these code-owned permissions for
the Slice 2 compatibility catalogue:

- `signal.action.execute`
- `ticket.update`

The actor has no role and does not inherit authority from the initiator, publisher, imported JSON,
or an API ability. Every action reauthorizes through `TicketActionGuard` and validates the current
target. Admin and Superuser receive additive `ticket.rule_preview` and
`ticket.rule_execution_view` grants. Preview also requires ordinary authorization for the exact
Ticket; later history UI must additionally enforce Ticket work-context scope.

### Migration Order And Forward Reconciliation

For a future environment, take a reviewed database backup and run only the reviewed Ticket Rule
paths in order after 240000 and 241000:

```bash
php artisan migrate --path=database/migrations/2026_08_25_242000_create_ticket_rule_execution_foundation.php --force
php artisan migrate --path=database/migrations/2026_08_25_243000_deploy_ticket_rule_execution_permissions_and_actor.php --force
php artisan migrate --path=database/migrations/2026_08_26_050000_add_ticket_rule_delivery_reconciliation_fingerprint.php --force
```

Migration 242000 owns the additive execution schema, restrictive lineage keys, indexes, and
completed-evidence guards. Migration 243000 creates the fixed actor and additive operator grants
without broad role synchronization. Migration 050000 is the reviewed forward reconciliation that
adds the delivery reconciliation fingerprint without editing a migration already recorded in an
environment.

On authoritative Dev, a concurrent task recorded 242000 and 243000 in batch 7 before the planned
controlled Slice 2 migration step. Do not rerun them. Historical migration 242000 remained
unchanged. Migration 050000 then ran path-scoped in batch 11 after the scoped backup
`/tmp/nexum-issue231-backups/pre-ticket-rule-reconciliation-20260825T231826Z.sql`, whose SHA-256 is
`e080d1e4a597f22fd1242ab91ba41e77623318ba33bf0b6b551e923bac0dc305`.

After any completed evidence exists, rollback is a forward disable to legacy authority while
preserving versions, runs, events, executions, action results, deliveries, and Ticket history. Do
not invoke destructive `down()` or delete evidence to repair deployment state.

### Authoritative Dev Evidence On 2026-08-26

- Seven focused suites pass 37 tests / 313 assertions. Pint passes for 46 focused files, all changed
  PHP files pass syntax lint, and the global Git diff check passes.
- Live read-back shows `runtime_authority=legacy`, v2 configuration false, zero execution runs and
  after-commit deliveries, ten immutability triggers, and 22 foreign keys.
- The automation actor has exactly `signal.action.execute` and `ticket.update`, has no roles, and
  Admin plus Superuser have both preview and execution-view grants.
- An actual MariaDB transaction contract proved failed-branch savepoint rollback, later-rule
  continuation, outer Ticket survival, immutable completed evidence, and cleanup on outer rollback.
- Two independent connections proved the lock boundary: the competing connection received
  `SQLSTATE[HY000]` / error `1205` while authority was then restored to `legacy`.
- No v2 activation, queue dispatch, Signal delivery, notification, or external effect was performed
  against persistent Dev data.

The slice is complete on Dev, but human-review checklist `HR-2026-08-25-013` remains Pending. It
blocks release and any runtime-authority activation.

## Ticket Rule Slices 3-6 Operations

Slices 3-6 add typed mutation events and actions, Workflow v3 operations, Ticket Custom Fields, and
the Admin builder/execution workspace. Their code is present on Dev, but every runtime and
capability gate remains disabled. Ordinary behavior therefore stays on the legacy creation-only
engine until the release boundary below is separately approved.

### Capability And Authority Gates

The following independent controls must all agree before a v2 action can run:

- `ticket_rule_authority_fences.runtime_authority` must be `v2`;
- `config('ticket_rules.v2_enabled')` must be true;
- the exact trigger and action capability entries in `config/ticket_rules.php` must be true; and
- Ticket Custom Field UI/API/rule capabilities must be true for those surfaces.

All of those values default to false or `legacy`. `ticket_rules.full_rerun_enabled` is an
additional independent default-off gate. A stale worker that sees v2 database authority without the
required code/config/schema fails closed; it must never silently skip v2 automation or fall back to
legacy.

Queue is the routing group. Owner is one individual assignment. No deployment or migration adds a
Ticket team relation.

### Additional Migration Order

After a reviewed backup and the already documented 240000, 241000, 242000, 243000, and 050000
migrations, run these reviewed paths in order:

```bash
php artisan migrate --path=database/migrations/2026_08_26_060000_expand_ticket_rule_automation_actor_permissions.php --force
php artisan migrate --path=database/migrations/2026_08_26_070000_add_ticket_rule_workflow_pause_state.php --force
php artisan migrate --path=database/migrations/2026_08_26_080000_expand_ticket_rule_workflow_actor_permissions.php --force
php artisan migrate --path=database/migrations/2026_08_26_090000_add_ticket_rule_loop_evidence.php --force
php artisan migrate --path=database/migrations/2026_08_26_100000_deploy_ticket_rule_admin_execution_permissions.php --force
php artisan migrate --path=database/migrations/2026_08_26_110000_add_ticket_rule_draft_payload.php --force
php artisan migrate --path=database/migrations/2026_08_26_120000_add_ticket_rule_draft_creation_token.php --force
```

Migration 060000 expands the protected actor for standard Ticket actions. Migration 070000 adds only
the Workflow automation pause timestamp, durable actor ID, bounded reason, and actor index.
Migration 080000 installs the final fixed runtime permission set. Migration 090000 adds
`loop_reason_code`, `blocked_event_fingerprint`, and the `tre_loop_reason_ix` index to normalized
Ticket Rule event evidence. The durable reason codes are `repeated_event_fingerprint`,
`depth_budget_exceeded`, `evaluated_rule_budget_exceeded`, and `action_budget_exceeded`. Repeated
event and depth blocks retain the original blocked semantic event fingerprint separately from the
unique wrapper event fingerprint; rule/action budget blocks have no blocked event fingerprint.
Migration 100000 additively grants
`ticket.rule_retry` and `ticket.rule_full_rerun` to existing Admin and Superuser roles without
syncing or removing unrelated grants. Migration 110000 adds mutable draft payload, checksum, editor,
and timestamp fields. Migration 120000 adds one nullable UUID `draft_creation_token` with the
named unique index `ticket_rules_draft_creation_token_unique`. The token makes the first Save
Draft request idempotent: a transport retry by the same operator with the same normalized draft
returns the first durable rule instead of creating a duplicate, while reuse for a different draft
fails closed. The canonical permission catalog and Admin fresh-seed bootstrap list include the same
approved operator permissions; this does not authorize a live `RoleSeeder` run.

After migration, read back:

- all seven migration rows and their batches;
- the three Workflow pause columns and `tickets_rule_workflow_paused_by_index`;
- `ticket_rule_events.loop_reason_code`, `blocked_event_fingerprint`, and
  `tre_loop_reason_ix`;
- the four Ticket Rule draft payload columns and their three named indexes;
- `ticket_rules.draft_creation_token` and
  `ticket_rules_draft_creation_token_unique`;
- Admin/Superuser retry and full-rerun grants, with no Tech grant;
- the protected actor as non-login, roleless, with exactly
  `ticket.update`, `ticket.assign`, `ticket.note_internal`,
  `ticket.workflow_escalate`, and `signal.action.execute`; and
- the still-legacy authority fence, false configuration/capability gates, and zero unexpected
  execution or delivery rows.

The legacy create, update, toggle, and delete HTTP routes remain fenced through
`LegacyTicketRuleMutationBoundary`. They require an active current operator, rule management and
publication permissions, and the current action-specific permission. They cannot mutate draft
storage, creation identity, or schema-2 published rows. Schema-2 enable/disable uses only the
separate published-rule boundary and remains unavailable while v2 authority or capability
prerequisites are false.

Migration rollback is evidence-aware. The Workflow pause, loop-evidence, draft-payload, and
draft-creation-token migrations refuse destructive removal after their evidence exists;
permission/actor migrations are forward-only. Preserve immutable
versions, runs, action attempts, delivery results, Ticket history, Workflow history, pause evidence,
and drafts. Use a reviewed forward fix after evidence exists.

### Authoritative Dev Release-Hardening Evidence On 2026-08-26

- Migrations 060000 through 120000 ran path-scoped and in order in batches 12 through 18. Schema
  read-back confirmed every expected Workflow pause, loop reason/fingerprint, operator permission,
  draft payload, and unique draft-creation-token column/index.
- The protected actor is disabled for login, system-owned, roleless, and has exactly
  `ticket.update`, `ticket.assign`, `ticket.note_internal`,
  `ticket.workflow_escalate`, and `signal.action.execute`. Admin and Superuser have retry and
  full-rerun grants; Tech has neither.
- Runtime authority remains `legacy` at generation 0. V2 configuration, every trigger/action/
  Custom Field capability, and full rerun remain off. Rules, versions, drafts, paused Tickets, loop
  events, executions, and deliveries all read back zero.
- Compatibility preflight returned compatible, mapping-complete, and fence-matched. The exact gated
  backfill completed with zero created and zero skipped under provenance
  `issue-231-dev-verification-2026-08-26`; legacy authority remained unchanged.
- The final post-format Issue #231 plus Workflow matrix passes 198 tests / 2,419 assertions in
  106.37 seconds. The Issue-only matrix passes 173 / 2,128 in 95.31 seconds, and an independent
  cross-module matrix passes 239 / 1,978 in 117.98 seconds.
- The broad repository run completed 2,452 passing tests / 23,683 assertions in 984.31 seconds and
  initially reported ten failures. The Issue-related Email duplicate-link fixture was corrected and
  passes 1 / 3 in 20.15 seconds; `EmailProviderHealthDeadlineTest` passes isolated 2 / 34 in
  22.88 seconds. The eight remaining unrelated, independently reproducing baseline failures are
  three Customer Portal notification/provider-binding tests, three
  `EmailLivePublisherStateMachineTest` tests, one Integration `max_tokens` expectation, and one
  `UserProfileBackfill` count.
- PHP lint passes 163 files with zero failures. Scoped Pint passes all 162 changed files, excluding
  only tracked-clean `TicketRuleEngine.php`. Blade cache, 13 unique routes, Livewire resolution,
  line-ending/whitespace/final-newline, English-only/no-language-file, artifact, and full/scoped diff
  checks pass. Application caches were cleared and Blade recached.
- The in-app browser loaded `nexum-psa.local`, but the direct rules URL redirected to `/login`
  because no authenticated session was available. Authenticated builder interaction and responsive,
  keyboard, and touch review remain in Pending human checklist `HR-2026-08-25-013`.
- All six Feature Slices are implementation-complete on Dev. No commit, push, Main promotion,
  production migration, activation, deployment, or release occurred.

### Preview, Retry, And Full Rerun

Execution history and preview are permission projections, not raw audit exports. If a frozen
version references any Ticket Custom Field the viewer cannot inspect, the complete run projection
becomes `Restricted evidence`: rule names, branch/outcome status, action/change details, counts,
duration, events, and executions are withheld together. Result filters plus result/duration sorting
are unavailable when the bounded historical version set contains restricted evidence, preventing
those controls from becoming an outcome oracle.

Draft and complete-published-set previews use the same typed trigger, condition, target, permission,
ordering, collision, derived-event, and loop-budget planning as runtime with `apply=false`. A
preview must write no Ticket, Custom Field, counter, audit, queue, Signal, notification, or external
state.

A normal retry selects only failed or `not_run` idempotent action positions and revalidates current
state, target, protected actor, operator permission, and prior result. It creates a linked immutable
attempt and never replays an already successful position. One action position allows three total
attempts by default, including the original runtime attempt. Configuration may lower or raise that
bound through `TICKET_RULE_MAX_RETRY_ATTEMPTS_PER_POSITION`, but the application hard ceiling is
20 and the candidate set is also bounded by the configured action budget with a hard ceiling of
500. Execution detail loads only the newest allowed attempts per position and reports older
immutable attempts as omitted.

Full rerun remains separately disabled. When explicitly enabled after release approval, it is
available only for a terminal `ticket.created` run and a user with
`ticket.rule_preview` plus `ticket.rule_full_rerun`. Preview creates a short-lived signed receipt bound to the source run,
Ticket/work context, operator, Ticket-state fingerprint, authority generation/checksum, frozen
published set, and complete preview-plan checksum. Execution re-previews and rejects expired,
replayed, changed, failed, halted, or mismatched plans before creating a linked immutable run.

### Controlled Activation

There is deliberately no ordinary Admin button or public command that changes runtime authority.
Activation is a separate reviewed release operation:

1. Complete `HR-2026-08-25-013` with a named human reviewer and record separate release approval.
2. Back up the database, pause relevant Ticket/Signal workers, run the reviewed migrations, clear
   caches, and restart workers on the reviewed code while authority remains `legacy`.
3. Run `ticket-rules:compatibility-preflight`; if required, run the exact generation/checksum-bound
   compatibility backfill, then rerun preflight. Any invalid, ambiguous, drifted, unversioned, stale,
   or unmapped rule blocks activation.
4. Make a reviewed code/config release that enables `TICKET_RULE_V2_ENABLED` and only the exact
   trigger/action/Custom Field capability entries approved for that release. Capability entries are
   code-owned and are not editable by rule JSON.
5. Invoke `ActivateTicketRuleV2Authority` through a reviewed operator boundary with the exact latest
   preflight generation/checksum and an active user holding `ticket.rule_publish`. The action locks
   the fence, revalidates the catalog and actor in one transaction, records immutable activation
   evidence, and refuses stale or repeated activation.
6. Enable each reviewed schema-2 rule separately through the fenced published-rule boundary. It
   revalidates v2 capability/authority, `ticket.manage_rules`, `ticket.rule_publish`, immutable
   checksum/version, protected actor, and current targets. Publishing alone never activates a rule.
   Schema-1 compatibility toggles remain on the legacy boundary before cutover.
7. Read back `runtime_authority=v2`, generation/checksum, activation actor/time/checksum, actor
   grants, gates, and frozen published set. Run focused create/update/message/tag/assignment/
   Workflow/Custom Field smoke cases and inspect no-loop/no-failed/no-unresolved execution reports.

Rollback after activation is a reviewed forward disable that preserves all definition and execution
evidence. Never direct-update the fence, delete audit rows, rerun historical migrations, or use
`RoleSeeder` as a deployment shortcut.

## Jobs And Queues

Customer replies are sent through queued jobs.

Important jobs:

- `SendTicketReplyEmail`
- `SendTicketInternalNotificationEmail`

Queue workers must be running in environments where outbound ticket email should actually send.

## Testing

Ticket behavior is covered by:

```text
app/Modules/Ticket/Tests/Feature/TicketModuleTest.php
```

New Ticket behavior should add or update tests.

Feature tests are preferred for:

- Routes.
- Controllers.
- Validation.
- Settings.
- Ticket creation.
- Email behavior.
- Workflow transitions.
- SLA behavior.
- Assignment.
- Merge behavior.
- Time and cost registration.

Unit tests may be added for isolated services where feature tests would be too broad.

## API

Ticket API routes are available under `/api/v1/tickets`.

Scopes:

- `tickets.read`
- `tickets.create`
- `tickets.update`
- `tickets.portal.publish`
- `tickets.reply_customer`

Routes:

- `GET /api/v1/tickets`
- `GET /api/v1/tickets/{ticket}`
- `GET /api/v1/tickets/{ticket}/messages`
- `POST /api/v1/tickets`
- `PUT /api/v1/tickets/{ticket}`
- `PATCH /api/v1/tickets/{ticket}`

- `POST /api/v1/tickets/{ticket}/portal-visibility`
- `POST /api/v1/tickets/{ticket}/messages`
- `POST /api/v1/tickets/{ticket}/external-messages`
The `{ticket}` route parameter uses the public ticket key, such as `TD-2026-000001`, because the
Ticket model route key is `ticket_key`.

The message read route requires `tickets.read`, accepts `per_page` from 1 to 100, and returns
only message ID, type, visibility, author type, and creation time plus persisted first-response
verification. It never returns message bodies, subjects, raw metadata, attachments, author IDs, or
customer content.

API creation must use `StoreTicket`. API field updates must use `UpdateTicketFields`, and status
changes must use `ChangeTicketStatus`. Do not bypass these actions from API controllers, because
they create events and enforce ticket workflow behavior.

## Knowledge Documentation

Portal publication must use `PublishTicketToCustomerPortal`, which locks the Ticket row and emits
the publication event and notification only for the first state change. API customer replies must
use `StoreIdempotentTicketCustomerReply`; its Ticket-scoped key and database unique index protect
`AddTicketMessage` and all outbound side effects from duplicate retries. Do not reuse
`external-messages` for technician replies because it is an inbound synchronization boundary.

Migration `2026_07_29_120000_add_api_idempotency_to_ticket_messages` must run before enabling the
customer-reply API route.

When the Ticket domain is materially updated, update the Markdown files under:

```text
app/Modules/Ticket/Docs/knowledge
```

Then sync repository documentation into Knowledge:

```bash
php artisan knowledge:sync-docs --module=Ticket
```

The sync marks changed Knowledge pages as `pending_push`. Use the BookStack push action in the Integration settings, or queue it directly:

```bash
php artisan knowledge:sync-docs --module=Ticket --push
```

## Related Future Work

Before changing Ticket behavior, check `docs/TODO.md` for related planned work.

Known future ideas that may affect Ticket include:

- Custom Fields and Metadata.
- Task Templates.
- Service Workshop Foundation.
- Operational Signals.
- Shared Send Email Component.
- SLA Reporting Foundation.
- Ticket Knowledge Follow-Up.

Design new changes so they do not create avoidable rework for these known directions.
