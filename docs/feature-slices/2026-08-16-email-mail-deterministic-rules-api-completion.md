# Feature Slice: Email Mail Deterministic Rules API Completion

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-010`

## Goal

Finish one deterministic Email rule boundary shared by Admin, REST API, personal-rule proposals,
background reprocessing, and runtime execution. Rules gain explicit draft/publish behavior, immutable
published snapshots, bounded selection previews, durable per-action outcomes, safe retry, warned full
rerun, and verified undo without replaying a successful provider or cross-domain side effect.

## Dependencies

Implementation begins after completion orders 7-9 are stable. It must use the final canonical source
and placement boundary, current mailbox authorization and access epochs, provider-reconciliation
conflict state, Integration provider-binding freshness, private invalidation contract, and shared-draft
coordination. The slice must re-read `InboundEmailRuleEngine`, `PersonalEmailRuleEngine`,
`EmailRulePublisher`, the Admin rule controller, the current read-only rules API, Signal loop guards,
and every target-domain action before editing them.

## User-Visible Behavior

- Admin edits a draft without changing the currently published rule. Publish shows the exact diff,
  effective account scope, action authority, and denials, then creates one immutable version.
- Rule preview may target one authorized message or an explicitly bounded account, folder, search,
  selected-message, or UTC date scope. It shows matched, skipped, denied, irreversible, and proposed
  action counts before any execution.
- A reprocessing run is durable and resumable. Operators see progress and per-message/per-action safe
  reason codes rather than raw exceptions or mailbox content in operational lists.
- Retry runs only failed or `not_run` action positions whose target-domain idempotency evidence says
  they have not already succeeded. A warned full rerun still cannot replay a successful side effect.
- Undo is offered only for an allowlisted action with a verified inverse and unchanged target state.
  Unsupported or stale inverses disappear or show an honest unavailable reason.
- Web, API, queued jobs, and accepted `Always do this` proposals call the same validation, policy,
  preview, publish, execution, retry, and undo actions.

## Rule Authority And Precedence

The existing accepted precedence remains mandatory:

1. non-overridable ingestion, security, trusted-routing, retention, and compliance policy;
2. organization/shared/system rules published for an explicit account scope;
3. personal rules limited to the current owner's ordinary mailbox authority;
4. explicit guarded Signal or target-domain handoff.

`preclassification`, normal organization, and personal phases are ordered deterministically by
authority layer, phase, weight, rule ID, version, and action position. A lower layer cannot undo,
skip, or widen a higher restriction. `stop_processing` takes effect only after every ordered action
in that rule succeeds; after an action failure, later actions become `not_run`, while another eligible
rule may continue according to the published policy.

Rules always have explicit account IDs. Folder targets belong to exactly one selected account.
Personal rules cannot invoke provider writes, Ticket/Signal/cross-domain writes, external sends, or
organization publication without their separately authorized policy. Permanent deletion and
automatic external sending remain outside this slice.

## Additive Data Model

Reserve migrations after order 9, currently `2026_08_16_132000` and `133000`; renumber only if an
earlier dependency occupies them.

### Draft and publication foundation

Add one current `email_rule_drafts` row per rule:

- rule, base published version, monotonic lock version, owner/publisher kind, account scope, phase,
  priority, stop behavior, grouped conditions, ordered actions, validation status and fingerprint;
- creator/updater and timestamps;
- no runtime effect and no secret, body, subject, recipient, or preview-result content.

Published `email_rule_versions` remain immutable. Add a stable definition-schema version,
precedence layer, and publication policy fingerprint where needed. Existing published behavior is
backfilled as the base version and an equivalent clean draft without publishing a new version or
replaying a rule. Draft optimistic concurrency fails with a stable stale-draft response.

### Reprocessing and per-action evidence

Add `email_rule_reprocess_runs` and `email_rule_reprocess_items`:

- actor/system-policy owner, operation (`preview`, `apply`, `retry`, `full_rerun`, `undo`), status,
  bounded account/rule/version/message scope, frozen selection fingerprint, caps, expiry, counts,
  progress, cancellation, parent run, and safe error code;
- one frozen item per source message plus exact active placement, account, UID namespace, source
  state/fingerprint, selected rule version, match/denial summary, status, attempts, and timestamps;
- no ordinary body, sender, subject, recipient, raw path, attachment filename, Ticket detail, or raw
  exception in operational rows.

Add durable `email_rule_action_attempts` (or an equivalent normalized action ledger):

- parent execution/reprocess item, exact published version and action position;
- stable logical idempotency key based on source message, placement, published version, and action
  position; a separate execution/undo key for the concrete attempt;
- immutable action snapshot hash, status (`pending`, `running`, `succeeded`, `failed`, `not_run`,
  `blocked`, `undo_pending`, `undone`, `undo_failed`, `stale`), sanitized reason, target reference,
  before/after/inverse evidence hashes, provider-operation or target-domain idempotency reference,
  lease token/expiry, actor/system policy, and timestamps.

Successful logical action keys are terminal. Retry and full rerun must discover and reuse that fact
before calling the target domain. Running claims are tokenized and have a bounded stale takeover;
the losing worker cannot complete or overwrite the current owner.

## Shared Action Boundary

Extract reusable Email actions/services for:

- draft create/update/validate;
- publish preview and apply;
- message/selection preview;
- start/cancel/query reprocessing;
- claim and execute one ordered action;
- retry failed/not-run actions;
- full rerun with explicit confirmation; and
- preview/apply verified inverse.

Target domains remain owners of their writes. Provider archive/move uses the existing remote-operation
ledger and verified Undo. Category/tag and local-state changes store exact prior assignment/version
and verify it before inverse. Ticket creation/linking, Signal emission, notifications, external send,
and any action without an approved inverse are not presented as undoable. A target action's durable
idempotency reference is recorded before a rule action may become `succeeded`.

`ProcessInboundRules` and historical/reconciliation paths must select an explicit execution mode.
Historical imports and reconciliation never replay rules implicitly. Reprocessing never calls IMAP
or scans a provider; it works only from a frozen, currently authorized local source/placement scope.

## Selection And Runtime Bounds

- Single-message preview remains available.
- Multi-message preview requires explicit account scope and exactly one of selected IDs, folder,
  bounded search, or UTC date range.
- Default cap is 100 messages, hard cap 500, and the preview fetches cap plus one so overflow is an
  honest `scope_too_large` result rather than silent truncation.
- A preview expires after 15 minutes. Apply reauthorizes actor, account, message, placement, rule,
  version, source fingerprint, policy, and target references and rejects a changed selection.
- Jobs process at most 25 items or about ten seconds per claim on the dedicated `email-rules` queue.
  Queue payloads carry only IDs and expected policy/binding versions.
- Cancellation prevents new claims but does not claim to undo already completed actions.

## Permissions And API

Add global permissions `email.rule_publish` and `email.rule_reprocess`; keep `email.rule_manage` for
the Admin definition surface. Seed publish/reprocess conservatively to Admin/Superuser, never Tech by
default. Every account in a shared/system rule additionally requires current rule authority; every
preview/reprocess source requires ordinary current Mail `View`. Provider actions require current
`Organize`; target-domain writes require that domain's exact authority. Break-glass content access
never grants rule publication or execution.

Add API abilities as request ceilings:

- `email.rules.read` for definitions, versions, previews, and permitted execution metadata;
- `email.rules.write` for draft mutation and publication; and
- `email.rules.execute` for reprocess, retry, full rerun, cancel, and undo.

Service/workload tokens must be bound to explicit account IDs and operations. A broad Email ability
never means every personal mailbox. Route binding and list queries return non-enumerating denials for
rules, versions, runs, items, messages, placements, and accounts outside current scope.

REST resources include rule index/show, draft create/update, validate/preview, publish, version
history/show, execution history/show, reprocess preview/apply/status/cancel, retry, full rerun, and
undo preview/apply. Existing read-only routes remain compatible. API resources never serialize raw
mail content, canonical IDs, credential references, private-file paths, or unsafe target evidence.

## Email And Signal Loop Protection

- Preserve the explicit `emit_signal` handoff and its stable source/action identity.
- A Signal produced by an Email rule cannot return through Signal automation and replay the same
  Email action; the existing source-domain and action-position guards remain mandatory.
- Ticket creation/linking keeps current TD/header/durable-link precedence and cannot create another
  Ticket after one already succeeded.
- Supplier-order and trusted-routing Email paths retain their current rule versions, account scopes,
  stop semantics, and loop identifiers.
- Reprocessing never re-sends notifications merely because a previous rule attempt is inspected or
  retried; notification delivery uses its own immutable identity.

## Out Of Scope

- Automatic external replies, permanent provider deletion, webhook actions, Marketing/bulk send,
  arbitrary scripts, nested rule groups deeper than group-to-condition, and AI execution authority.
- Provider-side rule synchronization or IMAP searching.
- Replacing Email rules with Signal, Ticket, or a generic workflow engine.
- Undo for an irreversible or target-state-uncertain action.
- Exposing mailbox content to a configuration-only administrator or account-unbound API token.

## Data Touched

- Existing `email_rules`, `email_rule_versions`, `email_rule_execution_attempts`, rule account pivots,
  logs, Admin views, API resources/routes/abilities, and runtime engines.
- New draft, reprocess, item, and per-action evidence rows.
- Existing provider-operation, Taxonomy, Ticket, Signal, Notification, and personal-state records only
  through their guarded actions after apply; preview writes none of them.
- Dedicated `email-rules` database queue and scheduler dispatch for resumable runs.

## Tests

- Draft isolation, optimistic concurrency, validation, publication diff, immutable versions,
  enable/disable publication, and exact web/API parity.
- Fixed precedence across security/preclassification/organization/personal/handoff layers, weight/ID
  ordering, stop only after success, and failure-to-`not_run` behavior.
- One-message and every bounded selection type, cap plus one, expiry, changed query membership,
  placement/source/version/policy staleness, and no preview side effects.
- Action lease race, worker restart, redelivery, cancellation, progress and safe error evidence.
- Failure after a target side effect but before local completion, retry without duplicate effect,
  successful-action skip, warned full rerun, and per-action rather than whole-rule retry.
- Verified provider move/archive inverse, tag/category/local-state inverse, stale-target denial, and no
  Undo control for Ticket, Signal, send, notification, or unknown actions.
- Personal/shared/system, owner/grant/delegation/revocation, break-glass exclusion, API account binding,
  non-enumerating route/list scope, and execution-time permission loss.
- Email/Signal/Ticket/Notification/supplier-order loop and idempotency regressions.
- No provider search/read/write from preview/reprocessing; no personal unread change unless the exact
  published action explicitly and validly owns it; no raw exception/content in ledgers or API.
- Migration backfill/rollback guards, old read-only API compatibility, route/OpenAPI output, queue and
  scheduler registration, cache/view build, and affected Email/Signal/Ticket/Integration tests.

## Documentation And Operations

Update Email README and Knowledge, Integration API ability documentation/OpenAPI, Signal/Ticket docs
where their handoff is affected, TODO, the completion index, and `docs/human-review.md`.

Deploy additive migrations with `umask 0002`, seed permissions/abilities, clear caches, rebuild group-
writable views, and restart default plus `email-rules` workers. No deploy automatically publishes a
draft, reprocesses historical mail, retries an attempt, or applies an inverse. The operator first runs
one preview-only controlled Dev scope and verifies zero provider/cross-domain writes.

`HR-2026-08-16-010` remains Pending until a named reviewer checks draft/publish, version history,
account scope, preview counts/denials, apply progress, controlled failure/retry, safe full rerun,
verified Undo and stale Undo, API token bounds, loop protection, worker/backlog health, sanitized
evidence, and unchanged provider/personal state outside the exact actions.

## Done Criteria

- [ ] Orders 7-9 are stable and their action/authorization/event boundaries are re-read.
- [ ] Draft/publish, versioning, precedence, bounded preview, reprocessing, per-action idempotency,
  retry, full rerun, and verified Undo share one tested Email action layer.
- [ ] Admin and REST API have equivalent honest behavior with explicit permissions, abilities, account
  scope, current reauthorization, and non-enumerating denials.
- [ ] Target-domain actions and Email/Signal loop guards prove that no successful or irreversible side
  effect is replayed or falsely presented as undoable.
- [ ] Focused and affected-module tests, migrations, queues, routes, OpenAPI, docs, Knowledge, deploy
  checks, and `HR-2026-08-16-010` are complete while human review remains Pending.
