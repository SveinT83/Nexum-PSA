# Feature Slice: Ticket Rules Ticket Custom Fields And Assignment Parity

Status: Done
Date: 2026-08-25
Approved by: Svein
Approved on: 2026-08-25
Level: 3
Parent: ../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md (Slice 5)
Owner: Svein / Codex
Related ADR: ../adr/2026-08-25-ticket-rule-versioning-authority-execution-boundary.md
Related issue: GitHub #231
Human review: [HR-2026-08-25-013](../human-review.md#hr-2026-08-25-013-ticket-rules-triggers-actions-and-audited-execution) (Pending)

## Goal

Register Ticket as a fully guarded Custom Field target and add typed Custom Field conditions,
change triggers, and set/clear actions while preserving the approved Queue routing, individual Owner
assignment, Workflow eligibility, and fallback assignment contracts.

## User-Visible Behavior

Authorized Ticket create/edit/API flows can read and update active Ticket Custom Fields using their
configured type, options, required state, uniqueness, admin-only flag, and edit permission. After
publication and explicit Dev capability enablement, Ticket Rules can inspect supported current or
before/after Custom Field values, react to actual value changes, and set or clear an allowed value.

Custom Field-based routing may set Queue, assign or unassign one eligible Owner, or explicitly rerun
the Assignment Engine through the existing Slice 3 actions. No team concept is introduced.

## Scope

- Register App Modules Ticket Models Ticket in CustomFieldModelRegistry with stable storage aliases
  and the same canonical model identity across UI, API, discovery, values, rules, and migrations.
- Expose active Ticket Custom Field definitions and values in authorized Ticket create/edit/show and
  supported API read/write flows using the existing value-input and presenter patterns.
- Route value writes through SyncCustomFieldValues or a Ticket-owned wrapper that performs
  NormalizeCustomFieldValue, definition scope, type, option, required, uniqueness, admin-only,
  edit-permission, work-context, and actor authorization checks.
- Capture stable before/after normalized values and emit one ticket.custom_fields_changed event only
  when at least one value actually changes.
- Trigger filters identify one or more immutable Custom Field definition IDs and optionally change
  direction. Missing, deleted, inactive, retargeted, or type-changed definitions fail closed.
- Add condition facts for current value, present/missing, before value, after value, and changed state.
  Operators are whitelisted by field type; numeric/date/boolean/select/multiselect behavior uses the
  Custom Field domain normalizer rather than string guessing.
- Add typed set and clear action providers that call the authoritative Custom Field boundary,
  revalidate the published target, and declare idempotency and a privacy-safe audit projection.
- Pin definition ID, expected model type, expected field type, and safe option identity in the
  immutable rule version. Publishing rejects incompatible or unauthorized definitions.
- Minimize audit values. Admin-only or otherwise sensitive definitions store identifiers, pass/fail,
  changed state, and a redacted/truncated/fingerprinted projection rather than unrestricted content.
- Apply Slice 2 branch savepoint, per-Ticket lock, FIFO derived event, idempotency, retry, and loop
  budgets to Custom Field actions.
- Prove Custom Field-triggered Queue/Owner routing follows Slice 3 precedence: Queue is routing,
  Owner is an individual eligible user, explicit rule decisions suppress fallback, and only explicit
  rerun assignment reassesses.
- Preserve Workflow assignment eligibility and action policy when a Custom Field-based action changes
  Queue/Owner or causes a later Workflow rule.
- Add independent default-off capability gates for Ticket Custom Field UI/API writes and rule
  trigger/action execution until migration, permission, and Dev verification pass.
- Keep all new developer-owned labels, validation, API errors, logs, previews, and help in English.

## Out Of Scope

- A first-class Ticket team, membership table, team selector, or team permission boundary.
- Changing Custom Field core ownership or building a generic cross-domain automation runtime.
- Arbitrary Custom Field definition creation or mutation from a Ticket Rule.
- Raw query/path conditions, executable expressions, scripts, SQL, or unrestricted JSON.
- Customer-visible messages, email actions, commercial approval, billing, time, stock, purchase,
  merge/delete, or unrestricted external actions.
- New Workflow action behavior beyond Slice 4.
- Incomplete Admin builder controls before Slice 6.
- Language files, translation keys, Norwegian copy, or partial localization.
- Main promotion, production migration, production data backfill, or production activation.

## Data Touched

- CustomFieldModelRegistry receives the canonical Ticket registration and legacy storage aliases if
  live compatibility evidence requires them.
- Existing custom_field_definitions and custom_field_values store Ticket definitions/values through
  current type-specific columns and model identity.
- Ticket create/edit/API requests and presenters receive guarded custom_fields input/output.
- Slice 2 normalized events and executions receive minimized definition IDs, type metadata,
  before/after change state, and safe value projections.
- Published Ticket Rule versions receive typed definition references, condition configuration, and
  ordered set/clear actions.
- No Custom Field definition is silently retargeted or changed by rule execution.

Any migration or backfill must be additive, idempotent, bounded, report conflicting aliases/values,
and make no rule or Ticket mutation when compatibility is ambiguous.

## Permissions

- Definition view/edit behavior continues to honor Custom Field admin_only and edit_permission.
- Ticket UI/API reads/writes require ordinary Ticket access, work context, API ability where
  applicable, and each Custom Field definition's permission.
- Publish requires ticket.rule_publish plus registry-declared Custom Field action publication
  authority for every referenced Then/Else position.
- Runtime uses the protected actor's narrow Ticket and Custom Field permissions and reauthorizes the
  definition on every execution.
- Preview/log permissions do not expose a value the viewer could not otherwise view.
- Add permissions only when existing Custom Field/Ticket permissions cannot represent the boundary;
  grant additively with read-back and no broad role sync.
- No initiator, publisher, API token, imported JSON, or Admin role lends authority to runtime.

## Tests

- Registry and migration tests cover canonical Ticket model identity, storage aliases, existing values,
  idempotent registration, ambiguous alias refusal, and supported SQLite/MariaDB behavior.
- UI/API tests cover definition discovery, create/edit/read values, type normalization, required,
  uniqueness, options, admin-only, edit permission, work context, API ability, and error shape.
- Trigger tests cover each supported field type, multiple changed values, unrelated values, no change,
  present/missing, before/after, inactive/deleted/type-changed definitions, and filter relevance.
- Action tests cover set, clear, required-field denial, invalid option/type, uniqueness, permission,
  cross-context, no_change, ordered Then/Else, branch rollback, and exact safe audit.
- Privacy tests prove sensitive/admin-only values are not leaked through logs, preview, history,
  customer surfaces, API metadata, validation, or fingerprints.
- Loop/idempotency tests cover field self-change, A-to-B-to-A, Custom Field-to-assignment-to-Custom
  Field chains, duplicate delivery, retry, budgets, and concurrent writes.
- Assignment parity tests cover Custom Field-based Queue routing, eligible Owner, unassign, rerun,
  fallback suppression, Workflow eligibility, and no team schema or semantics.
- Existing CustomFieldModuleTest plus Ticket, API, Workflow, assignment, Portal, Email, Signal, and
  Integration regressions pass.
- Pint, focused Laravel tests, git diff --check, and authenticated Dev UI/API smoke checks pass.

## Documentation

- Update Custom Fields core Knowledge/developer docs with Ticket registration, permissions, UI/API
  values, rule facts/actions, privacy, and troubleshooting.
- Update Ticket Rules/Assignment, Ticket API, Workflow, permissions, and technical operations docs.
- Update docs/TODO.md and the stable human-review entry at implementation handoff.
- Do not publish website copy until verified implementation, human review, and release status permit
  it; any later handoff remains do not publish until then.

## Implementation Evidence

Slice 5 is implemented on authoritative Dev while Ticket Custom Field UI/API/rule write capabilities
and v2 authority remain default-off.

- Ticket Custom Field rule, Ticket UI/API surface, and core Custom Field regressions pass
  17 tests / 225 assertions.
- Ticket uses one canonical `ticket` model identity. Typed Tech/API writes use the shared normalizer
  and Ticket synchronization boundary; reads remain permission-filtered while disabled writes fail
  closed.
- Typed current/before/after/present/missing/changed facts and set/clear actions pin the definition
  identity/type, reject drift, emit one event only for actual change, and preserve redacted evidence.
- Work-context, admin-only, definition permission, API ability, required/unique/options, and Customer
  Portal non-disclosure boundaries are covered.
- Custom Field-driven routing reuses the standard Queue/Owner and Assignment Engine actions. Queue
  remains routing, Owner remains one eligible individual, and no team model was added.
- Cross-module and release-wide evidence remains recorded under pending human-review entry
  `HR-2026-08-25-013`; this slice does not authorize runtime activation or Main/production work.

## Dependencies And Rollback

- Depends on completed and verified Slices 1-4, especially actor, event, savepoint, audit, assignment,
  and Workflow eligibility contracts.
- Inspect live Custom Field model aliases and Ticket value cardinality before migration; ambiguous
  data blocks writes and must be reported without raw values.
- Disable Ticket Custom Field write and rule capabilities independently for rollback while preserving
  definitions, values, immutable versions, and execution evidence.
- Never delete or rewrite existing values or completed audit as a rollback shortcut. Use a forward
  repair or separately reviewed data-aware downgrade.
- Additive schema down must refuse after Ticket values or execution evidence exist.
- No Main or production rollback/activation work is authorized.

## Done Criteria

- [x] Slices 1-4 are complete and verified on Dev.
- [x] Ticket has one canonical Custom Field model identity across registry, storage, UI, API, and rules.
- [x] Authorized Ticket UI/API flows read and write every supported type through the Custom Field
  normalizer and permissions.
- [x] One minimized event is emitted only after an actual Custom Field change.
- [x] Typed current/before/after conditions and set/clear actions fail closed on incompatible targets.
- [x] Sensitive values remain absent from unauthorized preview, log, history, API, and customer views.
- [x] Queue/Owner and Assignment Engine behavior matches Slice 3 with no team model.
- [x] Workflow eligibility, loop, idempotency, concurrency, branch rollback, and permission tests pass.
- [x] New capabilities remain default-off until approved Dev verification passes.
- [x] English-only copy is used; no language files or partial localization are added.
- [x] Documentation and the human-review checklist are complete at implementation handoff.
- [x] No Main or production migration, activation, promotion, or deployment occurs.
