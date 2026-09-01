# ADR: Ticket Rule Versioning, Authority, And Execution Boundary

Status: Accepted
Date: 2026-08-25
Accepted by: Svein
Accepted on: 2026-08-25
Decision Makers: Svein and Codex
Related RFC: `../rfc/2026-08-25-ticket-rules-triggers-actions-execution.md`

## Context

Ticket Rules must grow from mutable creation-time JSON into audited automation that can react to
Ticket creation and later changes. The runtime must preserve Workflow v3 state, ordinary Ticket
authorization, legacy rule behavior, immediate caller-visible results, and reliable failure evidence.

The design needs one durable answer for definition ownership, publication, runtime identity,
transactions, locking, retries, and recursive rule events. Controllers, model observers, and
individual action providers must not invent different answers.

## Decision

Ticket owns the rule definition, publication, event, execution, and audit boundary. Email, Signal,
Workflow, and other domains may expose typed facts or guarded actions, but they do not execute or
authorize Ticket Rules.

Each logical rule has a mutable draft and immutable published versions. A root execution freezes the
ordered published-version set that was active when the root event began. Draft edits and later
publishes affect only later root events. Existing rules receive compatibility version 1 through an
additive, deterministic backfill; the legacy runtime remains authoritative until compatibility
verification and the relevant runtime slice explicitly activate the new engine.

Every immutable version stores a canonical definition-only checksum over normalized weight/order,
trigger, grouped conditions, ordered Then/Else actions, stop behavior, and definition schema version.
Operational counters and timestamps are excluded. While the mutable legacy Admin form/runtime remains
authoritative, preflight compares the current legacy definition with compatibility version 1. Drift,
an unversioned current rule, or a v2-ineligible legacy definition blocks v2 activation. Once v2 owns
editing, reordering requires a new published version.

Compatibility version 1 never invents historical publication evidence. `published_by` and
`published_at` remain nullable when unknown; separate provenance records `legacy_backfill`, the
backfill batch/time, and a nullable non-user deployment/operator provenance key when available. It
does not create or impersonate a User, and creator/updater is not represented as the historical
publisher. A later explicit conversion or publish creates a new immutable version and never
overwrites version 1.

Publisher and execution-actor identifiers are nullable, indexed, durable raw User IDs rather than
foreign keys that cascade or become null when a User is deleted. This preserves immutable evidence
without blocking normal User lifecycle work. Relationships may resolve the current User when it still
exists, while audit presenters must retain the recorded identifier and fall back to a safe deleted-user
label when it does not.

Activation requires a complete one-to-one mapping between every current non-deleted legacy rule and an
eligible immutable version or explicit administrator disposition. Current active/disabled state is
reconciled separately from immutable definitions. Soft-deleted rules retain versions as history and
are never selected. Post-backfill creation, edit, reorder, toggle, disable, and soft delete are all
covered by activation preflight and regression tests.

Ticket owns one durable runtime-authority fence record, defaulting to `legacy`, with a monotonically
increasing legacy catalog generation and canonical catalog checksum. Every current legacy
create/edit/reorder/toggle/disable/soft-delete path takes the same write fence and advances the
generation when it changes the catalog.

The later v2 cutover is one atomic Ticket-owned transaction. It locks the authority fence, revalidates
the captured generation, full checksum, mapping/dispositions, and current lifecycle state, then changes
runtime authority only if every check still matches. After the switch, legacy mutation paths fail
closed or route through versioned publication. A concurrent write therefore becomes part of the
accepted catalog or causes activation to abort/retry; it cannot land between validation and cutover.

Authoritative Ticket actions emit normalized events after they have a stable before/after result.
Broad Eloquent observers are not an automation authority. Each event records its source, initiator,
root correlation, parent causation, changed fields, and deterministic idempotency key.

Unattended rules execute as the protected, non-login `ticket_rule_automation` system actor. The actor
has only code-owned permissions required by enabled action providers. The initiating human, API key,
or upstream system is retained as causation evidence and never lends authority to the rule.

A root Ticket mutation and its synchronous rule work use one database transaction and a per-Ticket
row lock. After the original authorized mutation succeeds, each selected Then/Else branch runs in a
database savepoint:

- a successful branch keeps all ordered synchronous changes;
- a failed branch rolls back only to its savepoint;
- the failure result is recorded after that rollback inside the root transaction;
- the original authorized mutation and earlier successful branches remain intact; and
- external effects are dispatched only after the root transaction commits.

Connections used for mutating Ticket Rule execution must support the required savepoint and locking
semantics. A runtime that cannot provide them fails closed before running the mutating branch.

Derived Ticket events remain in the same root causation chain and are drained in deterministic FIFO
order. No-op writes emit no event. Durable event, rule-version, branch, and action-position keys stop
duplicate delivery. A visited-state fingerprint, depth limit, evaluated-rule limit, action budget,
and per-Ticket lock stop direct and indirect cycles. Explicit retry may resume only failed or not-run
idempotent positions whose preconditions still match; it never replays successful positions by
default.

Queue is the Ticket routing group and Owner is the individual assignment. This architecture does not
add a first-class Ticket team model, membership table, or team action provider.

## Rationale

- Ticket remains responsible for Ticket behavior and can enforce one result across UI, API, Email,
  integrations, scheduled creation, and Workflow-triggered paths.
- Immutable versions make executions explainable and prevent a draft edit from rewriting history.
- Savepoints isolate automation failures without erasing the authorized user or API operation.
- A dedicated least-privilege actor avoids impersonation and makes unattended authority reviewable.
- Typed action providers preserve existing domain guards instead of duplicating business logic.
- Durable causation and idempotency provide evidence for loop prevention, concurrency, and retries.
- Keeping Queue and Owner as the approved assignment concepts avoids inventing an unsupported team
  domain.

## Consequences

- Additive schema is required for rule versions and, in the next slice, root/event/execution records.
- Ticket actions must eventually converge on normalized event boundaries; direct model mutations are
  not sufficient for automation coverage.
- Mutating runtime activation depends on verified database savepoint and row-lock behavior in both
  supported tests and authoritative Dev MariaDB.
- Action providers must declare schema, permission, phase, idempotency, and audit projections.
- Failed rule branches remain visible and may leave earlier successful rules committed by design.
- Existing rules retain their current behavior until compatibility evidence supports an explicit,
  default-off runtime switch.
- A legacy edit after backfill creates visible drift and blocks activation; it never rewrites the
  immutable compatibility snapshot.
- Schema rollback may drop the new version boundary only before any version or lifecycle reference
  exists. After backfill, `down()` refuses destructive removal and rollback uses a forward disable.
- The future localization project may add language files, but this ADR and its implementation slices
  introduce only English developer-owned UI copy until that work is separately approved.

## Alternatives Considered

- **One generic automation engine:** rejected because domain facts, authorization, persistence, and
  failure contracts would become ambiguous across Ticket, Email, Signal, and Workflow.
- **Run as the initiating user:** rejected because unattended behavior would depend on that user's
  changing employment and permissions and could create inconsistent results by source channel.
- **Run as the publishing administrator:** rejected because publication is not durable runtime
  authority and would amount to long-lived impersonation.
- **Commit the original mutation before rules:** rejected as the default because callers could
  observe intermediate state and concurrent workers could interleave before synchronous rules finish.
- **Roll back the whole request on any rule failure:** rejected because optional automation must not
  erase a valid authorized Ticket operation.
- **Suppress every rule-originated event:** rejected because legitimate chained rules would never
  run; causal loop detection is safer and more expressive.
- **Use Queue, roles, or Workflow pools to synthesize a new team relation:** rejected. Queue already
  represents routing and Owner already represents individual responsibility.

## Follow-Up

- Implement and verify the additive version/compatibility foundation under the linked Feature Slice.
- Prove savepoint and row-lock behavior before activating mutating runtime work in Feature Slice 2.
- Record exact runtime limits, action-provider contracts, migration commands, and read-back evidence
  in later Feature Slices and the human-review checklist.
- Keep all new Ticket Rules UI, validation, preview, log, and help text in English until a separate
  language-file effort is approved.
