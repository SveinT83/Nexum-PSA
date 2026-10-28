# RFC: RMM Time, Recurrence And SLA Rules

Status: Draft - concrete policy awaiting named product/recipient approval
Date: 2026-10-28
Owner: Svein Tore / Codex
Issue: #228; extends approved RFC 2026-08-25-rmm-alert-rules
Level: 3
No code, schema, current rule, provider, recipient or historical replay is changed by this draft.

## Full Target And Controlling Direction

Normalized Tactical/N-able facts feed Integration-owned RMM Alert Rules. Rules choose Ticket,
Task, Signal, explicit notification or ignore; there is no direct hardcoded Alert-to-Task bridge.
Add active-duration, Asset criticality, genuine recurrence and Client SLA facts to that same
configurable decision layer, with honest explanation of priority, deadlines and actions.
The older approved RFC explicitly excluded recurrence and notification action types. This RFC
must be approved before extending those product/data/recipient boundaries.

The Issue is internally inconsistent about criticality and recurrence: high/critical is stated
both to start at Critical and to bump one level, while recurrence is both Emergency and Medium.
The following concrete proposed precedence makes the full target reviewable rather than silently
choosing contradictory defaults in code.

## Proposed Severity And Deadline Policy For Approval

RMM provider severity remains a normalized fact distinct from derived escalation. Per enabled
policy, duration starts from the exact current activation occurrence, never the original mutable
alert's first_seen_at across reopenings. Suggested defaults: Info until 4 hours, Warning from 4,
Critical from 24 and Emergency from 72 hours. Boundary comparisons are inclusive and UTC-based.
Rules may configure thresholds and action mapping rather than hardcode provider behavior.

Proposed criticality rule: a critical Asset has at least Warning; a high Asset raises the derived
level by one, bounded at Emergency. Recurrence marks a separate recurring fact and enforces at
least Warning, rather than lowering an already Critical/Emergency item to Medium. A configured
recurrence-specific rule can route it differently. These choices require Svein's confirmation.

Default action mapping: Info -> Low / 7 days; Warning -> Medium / 3 days; Critical -> High /
24 hours; Emergency -> Urgent / 4 hours. Priority is an exact active configured domain reference,
never a guessed taxonomy ID/name. Reused work is only raised/tightened by default; no rule may
silently undo a technician's stronger priority or later workflow decision.

Keep Client response/resolution/critical-response targets distinct and resolve the approved
current Contract/SLA source before adding duplicate Client columns. Proposed due_at is the
resolution target; response_due_at is separate evidence. A critical response target never
pretends to be a resolution deadline. Proposed effective deadline uses the tighter configured
rule and applicable SLA target, anchored to the qualifying occurrence/escalation boundary,
not the delayed worker execution time. Existing work is updated only through owning domain
Actions with fresh record/Asset/Client/site checks and auditable before/after evidence.

## Occurrence And Recurrence Evidence

Count only immutable new activations and resolved-to-active reopenings in the exact bounded
provider/Asset/Client/site/fingerprint scope. Routine polling does not add a fire. Provider
failures, duplicates and historical mutable rows never manufacture occurrences or resolution.
Suggested default is at least 3 real occurrences within a rolling 30-day window; windows and
thresholds are configurable. Reassignment never carries another Client's recurrence history.
Show the minimal authorized occurrence IDs/times and reason, without raw provider payloads.

Existing immutable occurrences may be read as history for future evaluations. No migration
executes old actions, infers past fires or retroactively reopens/completes work. A separate
explicitly approved bounded backfill would be required for historical execution.

## Runtime, Idempotency And Ownership

Persist one escalation transition/evaluation identity per occurrence, policy revision and level /
recurrence boundary. Keep definition, fact snapshot, condition/action outcomes, target identity,
claim token and bounded error evidence. Lock/recheck current active occurrence and ownership
before committing a transition or domain action. Repeated scheduler passes and concurrent workers
return the existing result. An interrupted external result remains unknown and must reconcile;
never replay a whole completed RMM occurrence to obtain escalation.

Use one bounded scheduler entry to evaluate active eligible occurrences. Provider ingestion
updates facts through RecordRmmAlertObservation; it does not perform a second independent
escalation engine. The existing action coordinator and work links must handle trigger and
escalation identities without duplicating work. Preserve existing no-blind-replay semantics.
Store domain changes/outbox identity together; queue wake-up is not action/delivery completion.
Default-off new escalation execution and empty-scoped rollout preserve current rules until an
operator approves the exact installation/policy/cohort. Diagnostics are read-only and scoped.

## Notification Policy For Approval

Notifications are explicit configured rule actions, not an automatic consequence of an enum
change. Proposed defaults never notify a customer. An administrator may separately configure
internal recipients/destinations, current independent user preferences and a bounded template.
Assigned Team/lead routing waits for the approved Team membership source from #265. A Client
contact is eligible only after an explicit enabled customer-recipient policy and fresh owning
Client/contact visibility/consent check; never infer the primary contact or use all contacts.

Use the Notification domain's supported channel/outbox actions, per-connection Talk destination
contract (#205), fresh scopes/preferences, default-off gates and truthful provider receipts.
One exact transition/destination key prevents duplicates; unknown delivery reconciles separately.
Lock-screen/email/Talk data is minimized and authorized; no secret, raw provider output, unrelated
Client detail or automatic external attachment. A critical Asset may request immediate action
only through an approved policy. No real recipient/provider activation is authorized by this RFC.

## UI, Data And Deployment

Extend the existing English Bootstrap rule builder with implemented typed duration, criticality,
recurrence/SLA conditions and explicit action mappings. Show calculated facts/deadline anchors,
policy source, previewed target/actions and blocked reason. Preserve ordering, AND semantics,
ignore/stop behavior and current permission integration.rmm_manage. No unfinished toggle appears.

Use additive Integration-owned transition/evaluation evidence and indexes; retain normalized
Asset facts and existing occurrence history. Use the existing authoritative Contract/SLA owner
where it supports this behavior; add fields only under that domain's reviewed contract. Baseline
without enabling rules, changing existing taxonomy IDs, replaying events or altering current work.
Preserve used state/audit on rollback. Document exact migrations, scheduler and worker adoption.

## Complete Verification And Handoff

Test exact thresholds, delayed runners, long active alerts, resolve/reopen, recurrence window
edges, repeated polling, provider failure, criticality precedence, scoped SLA overrides,
response-vs-resolution deadlines, manual stronger priority, scope/ownership drift, disabled refs,
concurrent workers, idempotent work reuse, rollback/outbox, terminal failure/non-replay and safe
errors. Fake all external destinations in tests. Prove real scheduler execution separately from
schedule:list and queue acceptance; actual recipient delivery needs its separately approved
scenario. Update Integration/Asset/Task/Ticket/Notification guides, exact API coverage, actual
BookStack and a named human-review checklist before deployment. All full-target slices are
required before calling #228 complete.

## Approval Needed

Confirm or amend criticality/recurrence precedence and response-vs-resolution deadline mapping.
Confirm explicit configured customer opt-in with no default customer delivery, and select the
shared Team membership source already requested. AGENTS.md requires an approved Level 3 RFC
for the cross-domain workflow, SLA data, permissions and notification recipient behavior.
