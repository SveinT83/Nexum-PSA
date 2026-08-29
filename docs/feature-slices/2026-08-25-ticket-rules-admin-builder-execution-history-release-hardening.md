# Feature Slice: Ticket Rules Admin Builder, Execution History, And Release Hardening

Status: Done
Date: 2026-08-25
Approved by: Svein
Approved on: 2026-08-25
Level: 3
Parent: ../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md (Slice 6)
Owner: Svein / Codex
Related ADR: ../adr/2026-08-25-ticket-rule-versioning-authority-execution-boundary.md
Related issue: GitHub #231
Human review: [HR-2026-08-25-013](../human-review.md#hr-2026-08-25-013-ticket-rules-triggers-actions-and-audited-execution) (Pending)

## Goal

Expose the complete verified Ticket Rules capability through a compact Bootstrap/Livewire builder,
safe preview/publish flow, immutable execution history, Ticket history link, controlled retry, and
release evidence without exposing unfinished behavior or activating Main/production.

## User-Visible Behavior

Authorized administrators receive one Ticket Rules workspace with a compact rule list and a builder
organized as When, If, Then, Else, Flow, and Test. They can save a draft, add/remove/reorder grouped
conditions and ordered actions, choose typed targets, preview against an authorized Ticket and
synthetic event without writes, and publish an immutable version after all permission/policy checks.

Authorized operators can filter immutable execution history by rule, Ticket, event, result, and date,
open a run to see privacy-safe condition/action/change evidence, and follow one compact internal
automation_run entry from Ticket history. Failed or not-run idempotent actions may be retried only
through a separately authorized, current-state-validated operation. Customers see none of this.

## Scope

- Replace the static Ticket Rules form with a Ticket-owned Livewire editor. Keep routes, controllers,
  Livewire classes, and views in the Ticket module and use Bootstrap plus established shared buttons,
  cards, and sortable headers.
- Centralize every English trigger, field, operator, action, status, validation, summary, preview, and
  help label in the Ticket-owned registry/presenters. Do not directly include Signal or Email
  domain-specific rule partials.
- Implement When with typed trigger selection and contextual filters; If with root and nested
  ALL/ANY groups; Then and Else with ordered collapsible action rows; Flow with successful
  Continue/Stop semantics; and Test with authorized no-write preview.
- Add accessible add/remove/reorder controls for groups, rows, Then actions, and Else actions.
  Reorder must support keyboard buttons and touch as well as optional drag handles.
- Use typed searchable/select controls for Ticket fields, Queue, Owner, SLA, category, tag, Custom
  Field, published Workflow/version/state, and transition. Never require raw database IDs for
  ordinary use.
- Show contextual operators/inputs only when implemented and registry-valid. Do not expose stubs,
  disabled promises, arbitrary JSON, executable classes, or raw query controls.
- Preserve every supported legacy/versioned field during edit. Convert a legacy flat condition list
  to one displayed ALL group without mutating it merely by opening the page. Render unknown legacy
  nodes as explicit read-only incompatibilities and never silently drop them on save.
- Add explicit Save Draft, Preview/Test, and Publish actions. Draft saves never affect runtime.
  Publish revalidates references, schema, publisher authority, action-specific authority, system actor
  authority, compatibility, and immutable checksum before atomic selection.
- Make the first Save Draft request idempotent with a unique creation token. A transport retry by the
  same operator and normalized payload must return the first durable draft, while token reuse for a
  different request fails closed.
- Keep the legacy create, update, toggle, and delete routes inside the locked legacy authority
  boundary. They require current operator and action authority, cannot mutate draft creation/payload
  fields or schema-2 publications, and cannot become a bypass around the separate schema-2
  enable/disable boundary.
- Upgrade the rule index with Draft/Published/Disabled state, order, trigger summary, Then/Else
  summary, Continue/Stop, version, last execution, count, and failure signal. Add bounded search,
  filters, pagination, and accessible sortable links.
- Add rule detail, global execution index, and execution detail routes before generic rule bindings in
  app/Modules/Ticket/routes.php.
- Add paginated execution filters for rule, Ticket, event, result, and date. Present root source,
  initiator, protected actor, correlation/causation, chain depth, duration, frozen version, condition
  group/row outcomes, selected branch, ordered action status, safe changes, stop decision, external
  result, retry relation, failure, and loop/budget reason.
- Use allowlisted presenters. Never raw-dump action/event payloads, message bodies, attachment data,
  secrets, tokens, authentication headers, supplier/email payloads, or unauthorized Custom Field
  values.
- Link one compact internal automation_run Ticket history entry to the run. Keep all rule/execution
  detail out of Customer Portal, customer replies, and external notification content.
- Add controlled retry for failed/not-run idempotent positions whose current targets, preconditions,
  permissions, and state still match. A full rerun is a separate warned operation that requires
  preview and explicit higher authority; it is never the default retry.
- Correct the existing exact index-route permission gap so tech.admin.settings.tickets.rules uses
  Ticket Rule management/log permissions rather than falling through to ticket.manage_settings.
- Keep Livewire 3 as the only Alpine runtime. Ordinary fields remain deferred; structural actions are
  explicit and retain the affected section/focus after rerender.
- Produce migration/backfill/permission read-back, compatibility, no-loop/no-failed-run, broad test,
  authenticated HTTP smoke, and human-review evidence on authoritative Dev.
- Keep runtime/capability switches default-off until the complete Dev verification and named human
  review gates are satisfied. This slice does not authorize Main or production activation.

## Out Of Scope

- New trigger/action/domain behavior beyond Slices 1-5.
- Arbitrary JSON as the normal editor, PHP, SQL, shell, scripts, unrestricted HTTP, or raw database ID
  entry for ordinary users.
- Customer access to rules, previews, internal state, permissions, audit, retry, or history details.
- A first-class Ticket team. Queue remains routing and Owner remains individual assignment.
- Localization, language files, translation keys, Norwegian copy, or partial language scaffolding.
- Automatic completion of human review based on tests, silence, merge, or deployment.
- Commit, push, merge, Main promotion, production migration, production activation, or deployment.

## Data Touched

- Mutable Ticket Rule drafts and immutable published versions through the Slice 1 authority boundary.
- Additive draft payload, checksum, editor, timestamp, and unique creation-token fields. Draft
  creation identity is mutable-lifecycle evidence, not runtime configuration.
- Additive normalized-event loop reason and blocked semantic fingerprint fields plus one reason
  index. The wrapper event fingerprint remains independently unique.
- Slice 2 root/event/rule/action/after-commit evidence through read-only paginated presenters, plus
  explicit immutable retry attempts linked to their source execution.
- Minimal additive indexes required by measured execution-history filters; no destructive log rewrite.
- One compact internal Ticket history automation_run link per root.
- Additive permission catalogue/role grants and route middleware mappings for manage, publish,
  preview, execution view, retry, and full rerun.
- Livewire/Blade Ticket module views, routes/controllers, registry presenters, tests, Knowledge, module
  docs, TODO status, release notes, and human-review checklist during implementation.
- Existing ticket_rule_logs remains a clearly labeled legacy read-only surface and is never presented
  as equivalent to v2 evidence.

## Permissions

- Preserve ticket.manage_rules for drafts/management and ticket.rule_publish for publication.
- Use ticket.rule_preview for preview and ticket.rule_execution_view for internal execution history,
  each combined with ordinary Ticket work-context authorization.
- Add ticket.rule_retry for failed/not-run idempotent retry and ticket.rule_full_rerun for the warned
  preview-gated full rerun. Do not grant either through broad role synchronization.
- Publication also requires every registry-declared action-specific publication authority.
- Route middleware must contain exact patterns before broad Ticket settings fallbacks, including the
  exact rules index route.
- Admin/Superuser grants are additive and read-back verified. The protected actor receives only
  code-owned runtime capabilities. No UI can select the actor or edit its permissions.
- Preview/log/retry presenters must apply field-level and Custom Field visibility; customer roles have
  no route or API access.

## Tests

- Livewire component tests cover deferred ordinary fields, add/remove groups/conditions/actions,
  Then/Else separation, contextual filters/operators/targets, reorder, stable keys, focus/section
  retention, validation attachment, legacy round-trip, and unknown-node non-loss.
- Route tests cover exact index/manage/publish/preview/log/retry/full-rerun mappings, work context,
  unauthorized/forbidden/not-found behavior, deleted users/rules, and customer non-disclosure.
- Draft/publish tests prove isolation, immutable versions, checksum/order, concurrent publication,
  missing/inactive targets, actor/action permission loss, and no unfinished option exposure.
- Preview tests prove runtime-identical condition/action/collision/loop warnings with zero Ticket,
- First-save tests prove one rule per creation token, safe same-request replay, conflicting reuse
  refusal, concurrency behavior, and evidence-aware migration down. Legacy-route tests prove draft
  and creation-token fields plus schema-2 publications never cross the legacy mutation boundary.
  counter, audit, queue, Signal, notification, or external mutation.
- Index/history tests cover filtering, sorting, pagination, empty/missing states, version/failure
  badges, safe display, N+1 bounds, and legacy log labeling.
- Execution detail tests cover condition outcomes, Then/Else, ordered statuses, no_change, not_run,
  failed, loop_blocked, external unresolved, causation, duration, redaction, and customer isolation.
- Retry tests cover failed/not-run only, already-successful exclusion, idempotency, current-state and
  permission revalidation, source links, concurrency, and separate warned preview-gated full rerun.
- Browser human review covers desktop/mobile/touch/keyboard add/remove/reorder, dependent selectors,
  errors, summaries, preview, publish, history, focus, and accessible names.
- Single-runtime regression proves application JavaScript does not import or start a second Alpine
  runtime and Livewire controls remain responsive.
- Broad Ticket, Workflow, CustomField, Taxonomy, Email, Signal, Portal, Integration, API, assignment,
  SLA, scheduled creation, permission, migration, MariaDB, and queue suites pass.
- Pint, frontend build if assets change, git diff --check, full Laravel suite when practical, and
  authenticated Dev HTTP smoke checks pass. Any failure blocks completion or receives an explicit
  approved deferral.

## Documentation

- Update app/Modules/Ticket/Docs/knowledge/ticket-rules-assignment.md with the final user workflow,
  triggers, grouped conditions, Then/Else, order, actor, Workflow, Custom Fields, loops, preview,
  history, retry, privacy, and troubleshooting.
- Update Ticket overview/technical operations, Workflow v3, Custom Fields, Taxonomy, permissions,
  Integration/API, deployment, and rollback documentation.
- Update docs/TODO.md, the Feature Slice index, and one stable docs/human-review.md entry during
  implementation handoff. Automated tests do not mark it Reviewed.
- Prepare a public-safe nexumpsa.eu handoff only after verified implementation. Mark it do not publish
  until human review and release status permit publication.

## Current Implementation Evidence

The Slice 6 implementation and automated Dev verification are complete on authoritative Dev.
Authenticated builder interaction and responsive/keyboard/touch review remain named human checks
under Pending checklist `HR-2026-08-25-013`; they do not authorize runtime activation or release.

- The final post-format Issue #231 plus Workflow matrix passes 198 tests / 2,419 assertions in
  106.37 seconds. The earlier complete Issue-only matrix passes 173 / 2,128 in 95.31 seconds.
- The targeted privacy/compatibility regression passes 18 / 195 in 28.11 seconds. Its dynamic
  default Queue fixture is order-independent, and restricted-preview coverage asserts the stable
  generic full-rerun denial.
- An independent Ticket, Workflow, Portal, Signal, Custom Field, and RMM cross-module matrix passes
  239 tests / 1,978 assertions in 117.98 seconds.
- The broad repository run completed 2,452 passing tests / 23,683 assertions in 984.31 seconds and
  initially reported ten failures. The one Issue-related Email duplicate-link fixture was corrected
  to assign canonical client work context and now passes 1 / 3 in 20.15 seconds.
  `EmailProviderHealthDeadlineTest` passes isolated 2 / 34 in 22.88 seconds. The remaining eight
  unrelated, independently reproducing baseline failures are three Customer Portal
  notification/provider-binding tests, three `EmailLivePublisherStateMachineTest` tests, one
  Integration `max_tokens` expectation, and one `UserProfileBackfill` count.
- Exact route mapping keeps execution view, retry, and full rerun on separate permissions
  plus ordinary Ticket view/work-context authorization.
- History and preview fail closed as one `Restricted evidence` projection when a frozen rule
  references a Custom Field the viewer cannot inspect. Rule names, branch/outcome state, actions,
  changes, counts, duration, events, and executions are withheld together. Result filtering and
  result/duration sorting are disabled when bounded historical evidence is restricted.
- Retry is bounded to failed or `not_run` idempotent positions after current-state, target, actor,
  and operator revalidation. The default is three total attempts per position including the
  original, with a hard ceiling of 20; candidate positions are bounded by the action budget with a
  hard ceiling of 500. Older immutable attempts are counted but omitted from the bounded detail.
- Runtime and preview share derived-event identity and exact loop reasons
  `repeated_event_fingerprint`, `depth_budget_exceeded`,
  `evaluated_rule_budget_exceeded`, and `action_budget_exceeded`.
- Migrations 060000 through 120000 ran path-scoped and in order in Dev batches 12 through 18.
  Read-back found every expected Workflow pause, loop reason/fingerprint, operator permission,
  draft payload, and unique draft-creation-token column/index.
- The protected actor is disabled for login, system-owned, roleless, and has exactly
  `ticket.update`, `ticket.assign`, `ticket.note_internal`,
  `ticket.workflow_escalate`, and `signal.action.execute`. Admin and Superuser have retry and
  full-rerun permissions; Tech has neither.
- Compatibility preflight returned compatible, mapping-complete, and fence-matched. The gated
  backfill completed with zero created and zero skipped under provenance
  `issue-231-dev-verification-2026-08-26`; authority stayed `legacy` at generation 0.
  Dev contains zero rules, versions, drafts, paused Tickets, and loop events.
- Migration 120000 provides the unique `draft_creation_token` used to replay the first Save Draft
  safely. Draft/creation identity and schema-2 rows remain locked out of legacy
  create/update/toggle/delete routes.
- PHP lint passes for 163 files with zero failures. Scoped Pint passes for all 162 changed files;
  only tracked-clean baseline `TicketRuleEngine.php` is excluded. Blade cache, 13 unique routes,
  Livewire alias resolution, LF/whitespace/final-newline checks, English-only/no-language-file
  checks, debug/scratch scans, and full/scoped diff checks pass. Application caches were cleared and
  Blade was recached.
- The in-app browser loaded `nexum-psa.local`; the direct Ticket Rules URL redirected to
  `/login` because no authenticated session was available. Authenticated interaction,
  responsive, keyboard, and touch verification therefore remains in the Pending human review.
- Publish creates an immutable schema-2 version but preserves inactive state. Separate enable/disable
  requires active v2 capability and authority, manage plus publish permissions, immutable
  checksum/eligibility, and current actor/target checks. Schema-1 compatibility toggles remain on the
  legacy boundary.
- Runtime authority remains `legacy`; v2, every trigger/action/Custom Field capability, and full
  rerun remain off. Human review `HR-2026-08-25-013` remains Pending.

## Dependencies And Rollback

- Depends on completed and verified Slices 1-5, stable registries/events/actions, accepted migration
  evidence, and no unresolved verification failures.
- Do not expose a trigger/action until its runtime, permission, preview, audit, tests, and docs are
  complete.
- Disable builder publication and runtime capability switches independently for rollback while
  preserving drafts, immutable versions, executions, retries, Ticket history, and legacy evidence.
- Workflow pause, loop reason/fingerprint, draft payload, draft creation identity, and completed
  audits are forward-only once evidence exists. Destructive down must refuse; use a reviewed forward
  fix or separately reviewed data-aware downgrade.
- Main/production migration, authority cutover, capability activation, queue restart, deployment, and
  release require separate explicit approval after human review.

## Done Criteria

- [x] Slices 1-5 are complete and verified on authoritative Dev.
- [x] The complete English Bootstrap/Livewire builder exposes only implemented registry capabilities.
- [x] When/If/Then/Else/Flow/Test, typed selectors, add/remove/reorder, draft, preview, and publish
  have responsive Bootstrap markup, accessible semantics, and component coverage. Authenticated
  desktop/mobile/touch/keyboard interaction remains a separate human-review check.
- [x] Legacy and versioned definitions round-trip without silent field loss.
- [x] Rule list, execution filters/details, Ticket history link, redaction, and customer isolation are
  complete and paginated.
- [x] Exact route permissions, action-specific publication authority, fixed actor grants, preview,
  retry, and full-rerun boundaries are verified.
- [x] Migration/backfill, compatibility, permission read-back, focused/cross-module tests,
  broad-suite failure classification, unauthenticated Dev route smoke, and zero loop/failure
  evidence read-back are complete.
- [x] Runtime and capability switches remain default-off until named human review and separate release
  approval.
- [x] All new developer-owned UI, validation, preview, execution, log, help, test, and operator copy
  is English; no language files or partial localization are added.
- [x] Knowledge, module/API/permission/Workflow/Custom Field/Taxonomy docs, TODO, Feature Slice index,
  and human-review checklist are complete at implementation handoff.
- [x] No commit, push, merge, Main promotion, production migration, activation, or deployment occurs.
