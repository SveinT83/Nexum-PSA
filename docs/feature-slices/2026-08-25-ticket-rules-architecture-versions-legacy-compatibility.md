# Feature Slice: Ticket Rules Architecture, Versions, And Legacy Compatibility

Status: Done
Date: 2026-08-25
Approved by: Svein
Approved on: 2026-08-25
Level: 3
Parent: `../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md` (Slice 1)
Owner: Svein / Codex
Related ADR: `../adr/2026-08-25-ticket-rule-versioning-authority-execution-boundary.md`
Related issue: GitHub #231
Human review: [HR-2026-08-25-013](../human-review.md#hr-2026-08-25-013-ticket-rules-triggers-actions-and-audited-execution) (Pending)

## Goal

Create the additive, default-off Ticket Rule definition and immutable-version foundation, prove that
every valid existing rule can be represented without semantic drift, and prepare later execution
slices without changing live Ticket Rule behavior.

## User-Visible Behavior

Existing Ticket Rules continue to run through the current creation-time engine with the same order,
conditions, actions, active state, and stop behavior. This slice exposes no new trigger, action,
Workflow automation, execution-log page, or incomplete builder control.

Administrators receive only an English, read-only compatibility/preflight result if an operator runs
the new inspection command. Invalid or ambiguous legacy definitions are reported for review and are
never silently repaired, activated, or discarded.

## Scope

- Accept the linked ADR before implementation.
- Add a Ticket-owned typed registry for the existing `ticket.created` trigger, flat compatibility
  condition fields/operators, existing action types, schema versions, and safe readable summaries.
- Add draft/publish lifecycle fields to logical Ticket Rules without changing current runtime
  selection.
- Add immutable `ticket_rule_versions` snapshots with stable definition-schema version, canonical
  definition-only checksum including normalized weight/order, nullable publisher/publish timestamp,
  and truthful compatibility provenance.
- Implement deterministic conversion of every legacy row enumerated with `TicketRule::withTrashed()`:
  - `on_create` becomes `ticket.created`;
  - the flat condition list becomes one root `ALL` group;
  - weight/order, action order, values, active state, stop behavior, hit count, creator/updater, and
    timestamps are preserved;
  - Else remains empty.
  - a rule already soft-deleted before backfill receives immutable version 1 with truthful deletion
    lifecycle evidence and remains excluded from runtime selection.
- Implement a read-only compatibility preflight that returns the captured authority-fence generation
  and canonical full-catalog checksum and reports valid, invalid, ambiguous, skipped,
  already-versioned, unversioned, and drifted counts with sanitized identifiers and reasons.
- Require a complete one-to-one mapping between every current non-deleted legacy rule and an eligible
  immutable version or explicit administrator disposition before v2 activation. Reconcile current
  active/disabled state separately; retain soft-deleted versions as history without selecting them.
- Backfill compatibility version 1 additively and idempotently. Re-running the migration or backfill
  must not publish a second version or rewrite an immutable snapshot.
- Store nullable `published_by`/`published_at` for legacy version 1 and record separate
  `legacy_backfill` provenance, batch/time, and a nullable non-user deployment/operator provenance key
  when available. Never create/impersonate a User or infer a historical publisher from creator/updater.
- Keep invalid or ambiguous legacy rules at their current `is_active` value and on the legacy runtime.
  Mark them v2-ineligible and block activation until an administrator explicitly repairs, disables,
  or republishes each rule.
- Treat a later legacy edit or reorder as drift: block v2 activation and require an explicit
  conversion/publish that creates another immutable version without overwriting version 1.
- Treat post-backfill rule creation as unversioned and activation-blocking. Reconcile toggles and
  disables truthfully; soft deletion retains history and excludes the rule from execution selection.
- Add a durable default-`legacy` runtime-authority fence with monotonically increasing catalog
  generation and a canonical full-catalog checksum. Route current
  create/edit/reorder/toggle/disable/soft-delete mutations through one Ticket-owned boundary that locks
  the fence and advances the generation on catalog changes. This slice exposes no v2 authority switch.
- Keep the v2 runtime and all new update triggers disabled. The current `TicketRuleEngine` remains the
  production authority for creation rules during this slice.
- Register `ticket.rule_publish` in `PermissionSeeder` and `RoleSeeder::adminPermissions()` so
  canonical fresh seeds retain Admin access; Superuser receives the complete permission catalog.
- Grant it additively to the existing `Admin` and `Superuser` roles with read-back. Do not run
  `RoleSeeder` on current Dev data, create the runtime system actor, or enable unattended actions.
- Make destructive rollback impossible after any version or lifecycle reference exists. The migration
  `down()` must refuse removal after backfill; operational rollback is a forward disable.
- Record schema/read-back evidence and prepare the human-review checklist for the data change.

## Out Of Scope

- Executing rules from immutable versions.
- Performing the v2 authority cutover. The future activation slice must use the atomic fence contract
  and pass concurrent cutover tests before exposing or executing the switch.
- Root events, execution/action audit tables, savepoints, locking, retries, or loop processing.
- Ticket update, field, message, tag, assignment, Custom Field, status, or Workflow triggers.
- Then/Else execution, new action providers, Workflow actions, and Signal changes.
- A first-class Ticket team model. Queue remains the routing group and Owner remains the individual
  assignment.
- Replacing the current Ticket Rules Admin form or exposing draft/publish controls before they work.
- Language files, translation keys, Norwegian UI copy, or partial localization. New developer-owned
  text in this slice is English only; localization is deferred to separately approved future work.
- Retrospective execution against existing Tickets.
- Main promotion, production migration, or runtime activation.

## Data Touched

- Existing `ticket_rules` table: additive draft/published-version lifecycle references and definition
  schema/checksum metadata only.
- New `ticket_rule_versions` table containing immutable definition/order snapshots and publication
  evidence, nullable historical publisher/time, truthful legacy-backfill provenance, and a nullable
  non-user deployment/operator provenance key.
- Publisher and later execution-actor identifiers are nullable, indexed, durable raw User IDs without
  cascade or null-on-delete foreign-key behavior, so later User lifecycle changes cannot rewrite
  immutable evidence.
- Durable Ticket-owned runtime-authority fence state containing default `legacy` authority, the
  monotonically increasing legacy catalog generation, and canonical full-catalog checksum used to
  invalidate stale preflight results.
- Existing `ticket_rule_logs` remains unchanged as legacy evidence.
- Canonical `PermissionSeeder` and `RoleSeeder::adminPermissions()` definitions for
  `ticket.rule_publish`, plus a narrow additive migration or reconciliation action that grants the
  existing `Admin` and `Superuser` roles with read-back. `RoleSeeder` is not run on current Dev data.
- Ticket module models, casts, registry/definition support, conversion/preflight services, tests, and
  documentation.

No Ticket, Workflow, assignment, message, tag, Custom Field, Signal, notification, or external-system
record may be mutated by the compatibility preflight or backfill beyond the explicit Ticket Rule
definition/version schema above.

## Permissions

- Existing Ticket Rule management access continues to protect the current Admin pages.
- Add `ticket.rule_publish` as the future explicit publication boundary.
- Add the key to `PermissionSeeder` and `RoleSeeder::adminPermissions()`; Superuser continues to
  receive the complete canonical permission catalog.
- Grant it to existing `Admin` and `Superuser` roles only through an additive, read-back-verified
  migration or reconciliation action. Do not run `RoleSeeder` or synchronize unrelated grants on
  current Dev data.
- Run fresh-seed coverage only against an isolated disposable test database.
- The preflight is an operator-only Artisan command with no apply, publish, Ticket mutation, queue,
  or external-delivery path.
- This slice does not create or grant permissions to `ticket_rule_automation`.

## Tests

- Migration and guarded rollback tests for supported SQLite test connections and authoritative Dev
  MariaDB: `down()` works only before evidence exists and refuses destructive removal after backfill;
  rules already soft-deleted before backfill remain immutable versioned history and are never selected.
- Logical rule/draft/version relationships, casts, canonical definition-only checksums including
  normalized weight/order, immutable snapshot protection, and deterministic publication ordering.
- Registry validation rejects unknown triggers, fields, operators, actions, executable class names,
  arbitrary queries, and malformed schemas.
- Every existing condition operator and action type converts into one root `ALL` group with identical
  values and ordering.
- Active/inactive state, weight/order, stop behavior, hit count, actor/timestamps, and empty conditions
  or actions retain their current meaning.
- Invalid and ambiguous rules are reported v2-ineligible without changing `is_active`; activation
  remains blocked until explicit disposition.
- Legacy version 1 keeps unknown publisher/time and non-user provenance key nullable and never creates
  or impersonates a User or presents creator/updater as publisher.
- An edit or reorder after backfill produces checksum drift, does not overwrite version 1, blocks
  activation, and requires a later explicit conversion/publish for a new immutable version.
- Create/toggle/disable/soft-delete-after-backfill tests prove a complete activation mapping, truthful
  current state, retained deleted history, and exclusion of deleted rules from execution selection.
- A rule soft-deleted before backfill is enumerated with `withTrashed()`, receives exactly one
  compatibility version, retains deletion evidence, and is excluded from execution selection.
- Already-versioned and concurrent backfills skip deterministically without duplicate versions or
  snapshot rewriting.
- Fence tests prove every current legacy definition mutation locks and advances the catalog generation,
  and that a mutation after preflight invalidates its captured generation/checksum. Atomic concurrent
  cutover tests remain mandatory in the later runtime-activation slice.
- Preflight is read-only, sanitized, paginated/bounded where needed, and makes no Ticket mutation,
  queue dispatch, Signal emission, or external call.
- Backfill is idempotent and concurrent attempts produce one compatibility version per logical rule.
- Current `ticket.created` behavior remains covered through the existing engine and focused Ticket,
  Email, API, Intake, Relationship, Telephony, Signal, and scheduled-creation regressions.
- Permission migration is additive and preserves unrelated role grants. Isolated fresh-seed coverage
  proves `PermissionSeeder`, Admin defaults, and Superuser's full catalog retain
  `ticket.rule_publish` without running `RoleSeeder` on current Dev data.
- English-only operator output and documentation contain no hardcoded Norwegian copy or language-file
  scaffolding.
- `git diff --check`, Pint for changed PHP, and the focused Laravel test set pass on authoritative Dev.

## Documentation

- Update the parent RFC with final schema names or compatibility deviations discovered during
  implementation.
- Accept and link the Ticket Rule versioning/authority ADR.
- Add developer documentation for definition-schema versions, immutable snapshots, checksums,
  compatibility conversion, preflight output, and the still-disabled v2 runtime.
- Update `docs/TODO.md` with implementation status and remaining Feature Slices.
- Create a new `HR-...` checklist entry before the slice handoff, including backup, migration,
  preflight, schema read-back, rollback limits, and confirmation that current rule results remain
  unchanged.
- Do not publish a customer-facing website update for a schema-only foundation. Any future handoff
  remains `do not publish` until verified user-visible implementation and human review.

## Done Criteria

- [x] The ADR is `Accepted` and this Feature Slice is `Ready` before implementation begins.
- [x] Additive schema/runtime permission grants avoid broad role synchronization, while canonical
  `PermissionSeeder` and Admin default-role definitions include `ticket.rule_publish`.
- [x] Every valid legacy row from `TicketRule::withTrashed()` receives exactly one immutable
  compatibility version; already-deleted rows retain history and remain unselectable.
- [x] Legacy publisher/time and non-user provenance key remain nullable when unknown; no User is
  created or impersonated and truthful backfill provenance is stored.
- [x] Invalid or ambiguous rules are reported v2-ineligible with sanitized evidence, retain their
  source data and `is_active` value, and block v2 activation until explicit disposition.
- [x] Edit/reorder-after-backfill drift blocks activation without overwriting compatibility version 1.
- [x] New unversioned rules block activation; current toggles/disables reconcile truthfully; deleted
  rules retain history and are excluded from execution selection.
- [x] A durable default-`legacy` authority fence exists; every current legacy catalog mutation locks it,
  advances generation on change, and invalidates stale preflight evidence.
- [x] This slice neither exposes nor performs cutover; the later activation slice owns the atomic
  generation/checksum revalidation, authority flip, and concurrent cutover regressions.
- [x] Compatibility tests prove condition/action/order/active/stop equivalence.
- [x] The existing creation-time runtime remains authoritative and behaviorally unchanged.
- [x] New runtime, update triggers, Workflow actions, and incomplete UI remain disabled and hidden.
- [x] Destructive rollback is refused after any version/lifecycle evidence exists.
- [x] Focused tests, migration/read-back checks, Pint, and `git diff --check` pass on Dev.
- [x] Documentation and a new human-review checklist entry are complete.
- [x] No Main/production mutation, queue dispatch, Signal emission, or external delivery occurs.
