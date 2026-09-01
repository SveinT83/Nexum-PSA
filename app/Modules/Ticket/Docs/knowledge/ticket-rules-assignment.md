Ticket Rules and Assignment determine how tickets are classified, routed, automated, and owned.

Ticket Rules are Ticket-owned event rules. Assignment Rules and the Assignment Engine remain the
ordinary ownership boundaries.

## Ticket Rules

Ticket Rules are managed under Ticket Settings.

The legacy runtime remains authoritative and continues to evaluate only ticket creation. The
implemented versioned runtime also defines typed triggers for actual Ticket updates, standard-field
changes, messages, tags, Queue or Owner changes, Custom Fields, Workflow changes, Workflow state,
and reporting status. Every v2 trigger and action remains default-off until the compatibility,
human-review, and release gates explicitly enable it.

The complete action catalogue can:

- Set approved Ticket fields and SLA.
- Set Queue as the routing group.
- Assign, unassign, or rerun assignment for one individual Owner.
- Add or remove active Taxonomy tags.
- Add an internal system note.
- Set or clear an authorized Ticket Custom Field.
- Select, transition, switch, pause, or resume Workflow automation through Workflow v3 boundaries.
- Emit a Signal for explicit cross-module automation handoff.

Rules are useful for deterministic routing based on channel, inbound email context, tags, queue, customer context, or other supported fields.

Ticket Rules run before assignment so the final queue, category, priority, type, tags, and SLA can influence owner selection.

The Signal handoff action is opt-in. Use it when ticket creation itself should become a normalized
cross-module event, such as a security escalation or vendor/monitoring incident. Ticket Rules should
still handle ticket-local classification and routing. Signal Rules should handle follow-up outside
the ticket classification layer, such as tasks, sales follow-up, portal invitations, webhooks, or
derived signals.

Tickets created by Signal automation still pass through Ticket Rules for field routing, but Ticket
Rule Signal handoff is skipped for those `signal` channel tickets to avoid recursive automation.

## Versioning And Legacy Compatibility

The first Ticket Rule versioning slice adds a compatibility foundation without changing which
rules execute. `TicketRuleEngine` remains the only runtime authority, and the authority fence
remains `legacy`. There is no v2 authority switch, new update trigger, draft/publish control, or
partially implemented builder UI in this slice.

Valid legacy definitions can be recorded as immutable compatibility version 1 snapshots. Each
snapshot contains the normalized trigger, conditions, ordered actions, weight, stop-processing
behavior, source active state, and truthful legacy provenance. Unknown historical publisher and
publication time stay null; the backfill never creates or impersonates a User.

Version definitions have a canonical definition-only checksum. A separate full-catalog checksum
and generation protect the legacy catalog. Current create, edit, toggle, and delete operations pass
through one Ticket-owned mutation boundary that locks the fence and advances its generation when
the catalog changes. Operational hit counters do not change the catalog generation.

The compatibility preflight is read-only and reports bounded, sanitized rule identifiers, status,
and reason codes. The backfill requires the exact generation and checksum from a reviewed preflight
plus explicit write confirmation. It is additive and idempotent:

- Valid rules receive one immutable compatibility version.
- Invalid or ambiguous rules keep their current source definition and active state, remain on the
  legacy runtime, and are ineligible for a future v2 cutover until explicitly resolved.
- Soft-deleted rules retain version history and remain excluded from execution.
- Later definition edits or reordering become checksum drift; version 1 is never overwritten.

The future publication permission is `ticket.rule_publish`. It is granted additively to Admin and
Superuser, but this slice does not expose publication behavior. New Ticket Rule operator text and
documentation remain English until language files are introduced in a separately approved slice.
See `ticket-technical-operations.md` for the exact preflight, backfill, migration, and rollback
procedure.

## Execution Foundation And Creation Parity

The second Ticket Rule slice adds the audited v2 execution boundary without activating it. The
authority fence remains `legacy`, `config('ticket_rules.v2_enabled')` remains false by default, and
the existing legacy engine remains authoritative in normal Dev and production behavior.

The v2 coordinator is available only for isolated tests and an explicitly approved Dev check. At
the start of a root run it locks the Ticket and catalog authority fence, freezes the ordered
published rule versions, normalizes one event, and evaluates the compatibility condition tree. The
condition model records root and group `ALL`/`ANY` outcomes, row outcomes, the selected `Then` or
`Else` branch, ordered action positions, and last-successful-writer evidence.

Synchronous compatibility actions run as the protected non-login Ticket Rule automation actor.
The initiator remains separate in the audit chain and never lends permissions to the actor. Each
selected branch runs in a database savepoint:

- A successful branch retains its ordered changes.
- A failed branch is rolled back locally, records the failed and later `not_run` positions, and
  allows later rules to continue unless a root guard stops execution.
- No-op suppression, stable idempotency keys, event/rule/action fingerprints, depth/rule/action
  budgets, and loop blocking prevent repeated automation.
- External Signal delivery is queued only after the outer Ticket transaction commits. Its outcome
  remains truthful as queued, succeeded, failed, or unresolved.

Loop termination records one exact reason code:
`repeated_event_fingerprint`, `depth_budget_exceeded`,
`evaluated_rule_budget_exceeded`, or `action_budget_exceeded`. Repeated-event and depth
blocks retain the blocked semantic event fingerprint separately from the unique loop-evidence event
fingerprint. Runtime and preview use the same derived-event identity and reason contract, so a
direct self-loop and an indirect A-to-B-to-A chain terminate with the same privacy-safe evidence.

All production Ticket creation entry points now use `StoreTicket`, including scheduled Ticket
occurrences. Under legacy authority this consolidation preserves existing SLA resolution,
assignment precedence, creation-event ordering, messages, attachments, creator provenance, and
pinned scheduled Workflow version behavior.

The no-write preview service uses the same trigger, condition, target, authorization, ordering,
collision, and loop-risk planning contracts as runtime. It writes no Ticket, counter, audit, queue,
Signal, notification, or external state. Slice 2 exposes no builder, preview page, history page, or
retry control; those operator surfaces remain part of the later release-hardening slice.

Completed run evidence is privacy-minimized and immutable. Raw message bodies, attachments,
credentials, authentication headers, and unrestricted event payloads are not stored. All new copy
is English; language files remain deferred.

## Typed Events, Conditions, And Actions

Versioned rules use one registry-owned definition:

- **When** selects one typed Ticket trigger and its supported relevance filters.
- **If** uses a root All/Any choice and nested All/Any groups. A deliberate conditionless rule is
  shown as Always.
- **Then** contains ordered actions for a matching condition tree.
- **Else** contains ordered actions for a non-matching tree.
- **Flow** applies Continue or Stop only after the selected branch completes successfully.
- **Test** previews the current published set or draft against an authorized Ticket without writes.

Specialized field and assignment relevance consumes the same normalized update event rather than
creating duplicate root runs. A message that also claims an unassigned Ticket is likewise represented
as one root mutation with both message and Owner evidence. No-op mutations emit no event.

Every rule action reauthorizes the protected non-login Ticket Rule automation actor through the
ordinary Ticket or target-domain guard. The publisher, initiator, API token, imported definition,
preview permission, and execution-history permission never lend runtime authority.

## Assignment And SLA Precedence

Queue means the Ticket routing group. Owner means one individually assigned eligible User. Ticket
Rules do not create a team model or infer membership from roles or Workflow pools.

A successful explicit Queue or Owner rule decision suppresses creation-time fallback assignment.
Only the explicit **Rerun Assignment Engine** action asks the Assignment Engine to reassess ownership.
Workflow assignment policy is still applied by the authoritative Workflow action.

SLA selection remains:

1. the last successful Ticket Rule SLA decision;
2. the active Contract SLA; and
3. the configured default SLA.

The immutable execution evidence records the successful writer and any later overwrite.

## Workflow And Custom Fields

Workflow rule actions never patch status, Workflow ID, pinned version, or state directly. They call
the exact Workflow v3 selection, transition, or conversion boundary. One composite event records the
final Workflow, state, reporting status, and assignment result after success. Pausing affects only
rule-driven automatic Workflow movement; it preserves the pinned version, state, history, evidence,
requirements, and manual actions. Resume does not force a transition.

Ticket is registered as the canonical `ticket` Custom Field target. Authorized Ticket UI and API
reads use the Custom Field definition visibility rules. Writes use the shared normalizer and enforce
type, options, required/unique state, work context, `admin_only`, and field permissions. Rule
conditions and set/clear actions pin the definition ID, target model, and field type, fail closed on
target drift, and retain only minimized/redacted evidence.

## Builder, Preview, And Execution History

The English Bootstrap/Livewire builder preserves legacy or unknown nodes rather than silently
dropping them. Draft saves do not affect runtime. Publish creates a new immutable version after schema,
target, publisher, action, and protected-actor validation; publishing does not enable the rule or
change runtime authority.

The first Save Draft request carries a unique creation token. Repeating the same transport request
for the same operator and normalized draft returns the already-created draft instead of inserting a
second rule; token reuse for a different request fails closed. Draft creation identity, draft
payload, and immutable schema-2 fields cannot cross the legacy mutation boundary.

The existing legacy create, update, toggle, and delete routes remain locked to active operators with
`ticket.manage_rules`, `ticket.rule_publish`, and the current action-specific authority.
Draft-bearing and schema-2 published rules cannot be changed through those routes. Schema-2
enable/disable is a separate fenced operation and remains unavailable while v2 authority or its
capability gates are off.

A newly published schema-2 rule stays inactive. Its separate fenced enable/disable boundary requires
v2 configuration and database authority, manage and publish permissions, and fresh actor/target
validation. While authority is legacy, only schema-1 compatibility toggles use the legacy boundary.

The rule workspace provides typed selectors, keyboard/touch move controls, rule detail, and
paginated execution history filters for rule, Ticket, event, result, and date. Run detail shows
privacy-safe trigger, condition, branch, action, causation, duration, failure, loop, and external
delivery evidence. When any evaluated trigger, condition, or action target references a Custom
Field the viewer cannot inspect, index and detail replace all branch, outcome, action, change,
duration, and count signals with one `Restricted evidence` projection. Result filtering and
result/duration sorting are unavailable whenever any bounded historical version is restricted.
Customer Portal and outbound messages never expose execution evidence.

Action retry is limited to failed or `not_run` idempotent positions after current-state,
permission, and target revalidation. A position has at most three total attempts by default,
including the original attempt. Deployments may set
`TICKET_RULE_MAX_RETRY_ATTEMPTS_PER_POSITION`; the hard application ceiling is 20 attempts,
and the retry candidate set is bounded by the action budget with a hard ceiling of 500 positions.
Detail loads only the newest configured number of attempts per position and reports the number of
older immutable attempts omitted from display.

Full rerun is a separate default-off operation. It requires a fresh no-write preview, a short-lived
signed receipt bound to the exact Ticket state and published plan, explicit confirmation,
`ticket.rule_preview`, and the higher `ticket.rule_full_rerun` permission. No receipt is issued
unless the operator can view every Custom Field decision target and edit every Custom Field action
target in the frozen published set.

## Rule Ordering

Rules have weight/order and active state.

Use ordering to keep specific rules above broad fallback rules. For example, a customer-specific backup warning rule should run before a generic inbound email rule.

Rules may support stop-processing behavior depending on the configured rule shape.

## Email Tag Inheritance

Inbound Email tags are copied to tickets before Ticket Rules and assignment finish.

This allows Email preprocessing to drive ticket classification. For example, Email Rules may tag messages as backup, monitoring, invoice, no-ticket, or customer-specific tags before Ticket Rules decide queue, category, and owner.

## Assignment Rules

Assignment Rules are explicit owner-routing rules managed under Ticket Settings.

They can assign based on conditions such as:

- Client.
- Contact.
- Queue.
- Category.
- Priority.
- Ticket type.
- Channel.

Use Assignment Rules when ownership should be deterministic.

Example:

- All tickets from a VIP client go to a named technician.
- A security queue routes to a specific owner.
- A certain ticket type starts with the sales technician.

## Assignment Engine

When no explicit Assignment Rule assigns the ticket, the Assignment Engine can score assignable technicians.

Scoring uses Ticket Assignment Settings and User Management profile data such as:

- Assignable state.
- Working hours.
- Capacity.
- Category skills.
- Tag skills.

This gives the system a reasonable fallback without hardcoding every customer or queue.

## Ticket Assignment Settings

Ticket Assignment Settings support assignment scoring.

Technicians can manage their own ticket assignment settings, and admins can manage settings under Ticket Settings.

Settings can include:

- Capacity.
- User Management profile working hours.
- Category skills.
- Tag skills.
- Assignable state.

Technician profile scoring is intentionally a helper. Explicit Assignment Rules should still be used for hard business requirements.

## Manual Assignment

Ticket show includes assignment context and a manual re-run assignment action.

Manual assignment and re-run assignment should respect action guards and current ticket state.

Open unassigned tickets are also claimed automatically by the active technician when they perform meaningful ticket activity such as adding a message, changing status, editing fields, registering time, or adding a cost. Closed tickets are not auto-claimed, and the unassigned ticket list/stat focuses on open unassigned work.

## Recommended Rule Strategy

Use Email Rules for raw email classification.

Use Ticket Rules for ticket field routing.

Use explicit Ticket Rule Signal handoff only when ticket creation should trigger cross-module
automation.

Use Assignment Rules for explicit ownership.

Use Assignment Engine scoring as fallback.

Keep broad fallback rules at the bottom and customer-specific or severity-specific rules at the top.

## Implementation References

Important files:

- `app/Modules/Ticket/Services/TicketRuleEngine.php`
- `app/Modules/Ticket/Services/TicketRuleExecutionCoordinator.php`
- `app/Modules/Ticket/Services/TicketRulePreviewService.php`
- `app/Modules/Ticket/Services/TicketRuleFullRerunBoundary.php`
- `app/Modules/Ticket/Services/TicketAssignmentEngine.php`
- `app/Modules/Ticket/Support/TicketRuleTriggerRegistry.php`
- `app/Modules/Ticket/Support/TicketRuleActionProviderRegistry.php`
- `app/Modules/Ticket/Actions/ClaimUnassignedTicket.php`
- `app/Modules/Ticket/Actions/AssignTicketOwner.php`
- `app/Modules/Ticket/Actions/MutateTicketTags.php`
- `app/Modules/Ticket/Actions/SyncTicketCustomFieldValues.php`
- `app/Modules/Ticket/Actions/SelectTicketWorkflowForCreation.php`
- `app/Modules/Ticket/Actions/TransitionTicketWorkflowByRule.php`
- `app/Modules/Ticket/Actions/SwitchTicketWorkflowByRule.php`
- `app/Modules/Ticket/Livewire/Admin/RuleBuilder.php`
- `app/Modules/Ticket/Models/TicketRule.php`
- `app/Modules/Ticket/Models/TicketRuleVersion.php`
- `app/Modules/Ticket/Models/TicketRuleRun.php`
- `app/Modules/Ticket/Models/TicketAssignmentRule.php`
- `app/Modules/Ticket/Models/TicketAssignmentSetting.php`
- `app/Modules/Ticket/Controllers/Admin/AssignmentRuleAdminController.php`
- `app/Modules/Ticket/Controllers/Admin/TicketAssignmentSettingsAdminController.php`
- `app/Modules/Ticket/Controllers/Tech/TicketAssignmentSettingsController.php`
