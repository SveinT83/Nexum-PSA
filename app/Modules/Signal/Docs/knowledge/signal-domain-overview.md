The Signal domain is the shared automation layer for normalized events and classifications across
Nexum PSA.

Signal is active. It stores signals, evaluates rules, executes direct actions, audits those
executions, and queues outbound webhooks.

## Current Producers

Marketing campaign events currently create Signal records for:

- Marketing email opens.
- Marketing link clicks.
- Unsubscribe requests.

Inbound Email currently creates Signal records for:

- Hard bounces.
- Soft bounces.
- Automatic replies.
- Out-of-office replies.
- Unsubscribe requests.
- Recognized vendor notifications such as QNAP firmware/security notices.
- Explicit supplier-order confirmations selected by preclassification Email rules.

Intake creates Signal records for:

- Successful non-spam public form submissions.

Email and Ticket rules can also create Signal records, but only through explicit `emit_signal`
actions configured by an admin. Email rule handoff is for selected inbound messages that should
become operational events. Ticket rule handoff is for selected ticket creation cases that should
trigger cross-module automation after the ticket is persisted.

Supplier-order Email signals carry only Email's canonical `trusted_auth` snapshot. Signal does not
accept visible `From`, raw headers, or rule matching as proof of sender identity. Failed or unaligned
facts can be audited, but Storage must require passed and aligned authentication for any active
automatic gate.

Email machine signals are archived before ticket routing so delivery failures and automatic replies
do not create customer tickets. Clients and Contacts expose related Signal history directly on their
profile pages. Signal rules can create Sales follow-up and operational Ticket follow-up. Marketing
and Sales may use Signal data for their own lists and follow-up views, but Signal itself stays focused
on event capture, rule execution, audit, and integrations.

Ticket rules skip Signal handoff for tickets created by Signal automation. This lets Signal-created
tickets use normal Ticket classification without recursively creating more Signal records.

## Signal Settings

Admins with Signal rule management access can configure Signal settings from the Signal admin area.
Settings are stored in `common_settings` and currently control:

- Whether AI-assisted classification is enabled.
- Minimum AI confidence before a classification can become a Signal.
- Source domains where AI fallback may run.
- Allowed AI signal types.
- Signal types that should stop ticket routing after a Signal is recorded.
- The grounding prompt used by the Signal AI classifier.

AI classification is disabled by default. The inbound Email producer always runs deterministic
classifiers first and only calls AI as a fallback. AI never executes actions directly. It records a
normal Signal row, and the Signal rule engine remains responsible for follow-up actions.

## API Ingest

Protected integrations can record signals through `POST /api/v1/signals` with a Sanctum token that
has the `signals.create` ability. This is the preferred entry point for vendor events such as QNAP
update notifications, monitoring events, or other external system events that should be normalized
before rules decide what happens next.

Example payload:

```json
{
  "source_domain": "qnap",
  "client_id": 123,
  "signal_type": "firmware_update_available",
  "severity": "warning",
  "confidence": 95,
  "summary": "QNAP firmware update is available.",
  "payload": {
    "device": "NAS-01",
    "version": "5.2.0"
  }
}
```

## Signal Feed

The admin Signal feed opens with the last 30 days to keep a growing event history manageable. Use
the period selector for older/all history or custom dates. Search covers summary, type, source,
client, and contact. Source, type, severity, and status can be filtered, and supported table columns
can be sorted in either direction.

## Rule Engine

Rules have conditions and ordered actions stored as JSON. Conditions can match source domain,
signal type, severity, status, confidence, client/contact presence, and payload fields.

The visual builder supports compact, reorderable action rows and condition groups. Each group can
match all conditions or at least one condition. Multiple groups can also use all/any matching.
Existing legacy rule definitions remain supported. Advanced JSON is available as a collapsed,
explicit opt-in editor. Unknown actions, unknown condition fields, missing required action fields,
and invalid webhook URLs are blocked before the rule can execute.

Supported actions:

- Suppress marketing email for a linked Contact.
- Tag a linked Contact.
- Tag a linked Client.
- Emit a derived Signal.
- Create a Sales follow-up opportunity/activity.
- Create a Ticket follow-up.
- Create either one Task follow-up or the full current group from an active Task template. Template
  execution remains duplicate-safe and later template edits never rewrite generated Tasks.
- Send a Customer Portal invitation.
- Queue a webhook delivery.
- Queue a Storage supplier Purchase Order import.

Signal should be the automation owner after a normalized event exists. Email remains the owner of
inbound message triage and ticket ingress, while Ticket remains the owner of ticket-local field
routing, SLA selection, workflow entry, and assignment inputs.

Rules run in priority order. A rule may stop lower-priority rules after it succeeds. If an action
fails, later actions in that rule are not run, but other matching rules continue. Every original
attempt and retry writes a `signal_rule_executions` row with the status of each action. From Signal
detail, an operator with `signal.action.execute` can retry only failed/unstarted actions or use the
warned full-rule rerun. Stable action keys prevent the same side effect from being created twice.
Webhook attempts are also tracked in `signal_webhook_deliveries`.

### Webhook Outbox And Delivery States

The delivery row is the durable outbox and its `action_key` is database-unique. It is created in the
same transaction as the Signal action. Rollback retains neither delivery evidence nor an external
request. After commit, Nexum attempts an immediate queue wake-up; `signal.webhook.dispatch` also runs
every minute and recovers rows that remain `pending` if broker publication was lost.

The delivery worker locks and claims one row with an opaque token. Only `pending` may start HTTP, so
duplicate jobs cannot send the same action twice. The states are:

- `pending`: committed but not yet claimed; safe for the dispatcher to wake;
- `running`: one fresh worker claim may be performing the request;
- `delivered`: the receiver returned a successful response;
- `failed`: the receiver returned a non-success response; and
- `unresolved`: transport failed after claim or the claim was abandoned, so receipt is ambiguous.

`failed` and `unresolved` are terminal for automatic delivery. Reconcile them with the receiver
before any explicit resend; resetting them blindly can duplicate external work.

### Storage Supplier Order Import Action

`storage_supplier_order_import` hands a matching Signal to Storage's
`QueueSupplierOrderImport` boundary. The action accepts an optional `profile_id` and a `queue`
boolean that defaults to `true`. It contains no supplier-specific parser configuration.

Signal passes the Signal, rule, normalized action, and stable action idempotency key unchanged.
Storage owns supplier/profile selection, source validation, deterministic extraction, approval
policy, AI fallback, import idempotency, and all Purchase Order decisions. Signal's existing ordered
execution, failure, and retry audit remains in effect.

Example:

```json
[
  {
    "type": "storage_supplier_order_import",
    "profile_id": 42,
    "queue": true
  }
]
```

Example QNAP-style payload condition:

```json
{
  "source_domain": ["email"],
  "signal_type": ["vendor_notification"],
  "has_client": true,
  "payload_equals": {
    "vendor": "qnap"
  },
  "payload_contains": {
    "title": "firmware"
  }
}
```

Example Sales follow-up action:

```json
[
  {
    "type": "sales_follow_up",
    "opportunity_title": "Website interest follow-up",
    "activity_subject": "Call after campaign click",
    "follow_up_minutes_from_now": 1440,
    "next_follow_up_type": "call"
  }
]
```

Example Ticket follow-up action:

```json
[
  {
    "type": "ticket_follow_up",
    "subject": "Investigate bounced customer address",
    "description": "Check if the customer address should be updated."
  }
]
```

Example Intake task and portal actions:

```json
[
  {
    "type": "task_follow_up",
    "subject": "Prepare new user onboarding",
    "assigned_to": 12,
    "due_minutes_from_now": 1440
  },
  {
    "type": "portal_invitation",
    "role": "viewer"
  }
]
```

The Customer Portal invitation action requires a Signal with matched Client and Contact context.
Task and portal actions use the Signal rule owner as actor unless an `actor_id` is provided.

## Permissions

- `signal.view`
- `signal.rule.manage`
- `signal.webhook.manage`
- `signal.action.execute`

## Operational Notes

Signal rules can mutate customer-facing data, such as Contact email eligibility. Keep rules specific,
use priority and stop-processing deliberately, and review per-action execution history after changing
rule actions. Webhook delivery failure does not block signal recording.
Monitor the every-minute `signal.webhook.dispatch` schedule and queue worker. A growing `pending`
cohort indicates scheduler or queue publication trouble; `unresolved` requires operator
reconciliation rather than automatic replay.


## Complete API Support (Dev Only)

Availability: the complete extension is Dev-only. Main promotion, production deployment,
human scenario review and external runtime activation are separate.
Human-review checklist HR-2026-10-27-SIGNAL-API is Reviewed by Svein Tore (2026-10-27).

## Prerequisites And Permissions

Use an active internal Nexum user and a Sanctum bearer key. Configure a key from
Admin > System > Integrations > API. Token scopes never grant native permissions.
Discovery never seeds rights or AI agents; the API does not enable a provider or worker.

| Token ability | Current native authority |
| --- | --- |
| signals.read | signal.view; related Client/Contact reads also require client.view/contact.view |
| signals.create | signal.action.execute |
| signals.rules.read | signal.view |
| signals.rules.write | signal.rule.manage |
| signals.settings.read / signals.settings.write | signal.rule.manage |
| signals.actions.execute | signal.action.execute plus the relevant downstream action rights |
| signals.providers.execute | Additional scope for external effects, never a substitute for native rights |
| signals.operations.read | Same actor/token plus original command rights, scopes and source access |

Webhook rule configuration also requires signal.webhook.manage. Intake-specific
prefill uses the same signal.rule.manage authority as the browser rule form.
Actor/template lookups return bounded form references.

## Supported Operations

| Method | URL | Operation |
| --- | --- | --- |
| GET | /api/v1/signals | index |
| POST | /api/v1/signals | store |
| GET | /api/v1/signals/context | context |
| GET | /api/v1/signals/lookups/{kind} | lookups |
| GET | /api/v1/signals/operations/{operation} | operations.show |
| GET | /api/v1/signals/related/clients/{client} | related.clients |
| GET | /api/v1/signals/related/contacts/{contact} | related.contacts |
| GET | /api/v1/signals/rules | rules.index |
| POST | /api/v1/signals/rules | rules.store |
| GET | /api/v1/signals/rules/context | rules.context |
| GET | /api/v1/signals/rules/{rule} | rules.show |
| PUT | /api/v1/signals/rules/{rule} | rules.update |
| GET | /api/v1/signals/settings | settings.show |
| PUT | /api/v1/signals/settings | settings.update |
| GET | /api/v1/signals/{signal} | show |
| GET | /api/v1/signals/{signal}/executions | executions.index |
| GET | /api/v1/signals/{signal}/executions/{execution} | executions.show |
| POST | /api/v1/signals/{signal}/executions/{execution}/retry | executions.retry |
| POST | /api/v1/signals/{signal}/executions/{execution}/retry-preview | executions.retry-preview |


## Read Feed, Details And Rule Context

1. GET /api/v1/signals/context with signals.read to discover ranges, filters and sorts.
2. GET /api/v1/signals?range=30&q=alert&per_page=25. The default is 30 days,
   newest occurrence first. Ranges are 7, 30, 90, custom and all. Custom from/to dates
   use YYYY-MM-DD. Filter source_domain, signal_type, severity or status. Pagination
   has a hard maximum of 100. Supported columns and asc/desc are returned in context.
3. Read the exact Signal and its /executions list or exact execution detail for
   immutable attempts, original positions, results, errors and retry links.
4. Read /signals/rules/context for defaults, grouped/legacy conditions, operators,
   portal roles and all ten actions. For Intake prefill pass source_domain=intake
   and intake_form_id using an authorized form.
5. Read a saved rule for its fields, revision and execution history. Related Client
   and Contact history use the same exact-parent query as the profile panels.

Sensitive keys and credential-bearing URL parts are redacted in API results. A redacted
value is not a usable credential: never blindly resubmit it as an updated webhook URL.

## Record A Signal

POST /api/v1/signals requires source_domain and signal_type. Optional fields include
source/subject identifiers, Client/Contact references, severity, confidence, status,
summary, payload and occurred_at. Existing contact/client subject aliases remain valid.

For recording without rule processing:

~~~json
{
  "source_domain": "external",
  "signal_type": "vendor_notification",
  "summary": "A vendor notice was received.",
  "process_rules": false
}
~~~

Send a fresh Idempotency-Key for each logical input and reuse the exact key/body on
a network retry. process_rules defaults to true and additionally requires
signals.actions.execute. Inspect returned executions: recording success does not mean
that all actions succeeded. Older signals.create-only callers now need the user's
signal.action.execute permission; there are no automatic grants. Unkeyed legacy
ingest remains supported, but repeating an unkeyed request can create another Signal.

## Create Or Update Rules And Settings

1. Fetch current context, rule or settings with appropriate native/token rights.
2. POST a new rule with name, priority, optional description/is_active/stop_processing,
   conditions and ordered actions. Use grouped all/any or legacy conditions.
   Advanced JSON uses use_advanced_json, conditions_json and actions_json as in web.
3. New rule/settings writes require Idempotency-Key (8-200 characters).
4. For rule update, GET the exact rule and retain ETag. PUT the complete desired form
   with a new Idempotency-Key and If-Match containing that ETag. Rule PUT uses web
   replacement semantics; omitted optional values follow the browser defaults.
5. GET /signals/settings, retain ETag and PUT with a new key and If-Match.
   ai_min_confidence is required. AI enablement is explicit. List values accept typed
   string arrays or browser newline/comma text. Omitted settings use browser defaults.
6. Read back normalized fields and revision. Safe selected-agent status never includes
   provider credentials; Integration continues to own agent/provider administration.

Direct API execution uses the current caller. It cannot impersonate another actor_id
or creator_id. Ticket, Task, Sales, Contact, Client, Customer Portal and Storage keep
their native downstream permissions and scope validation.

## Preview And Retry

1. Select an exact Signal/execution. POST mode failed or all to
   /signals/{signal}/executions/{execution}/retry-preview.
2. Review selected snapshot positions and each action's policy/reasons. Preview
   creates no Signal, attempt, queue, AI configuration or access epoch.
3. Retain preview ETag. POST the same mode to /retry with If-Match and a fresh
   Idempotency-Key. Execution rechecks current rights under the shared Signal lock.
4. Inspect the new linked immutable attempt, or no_work when nothing remains.
   failed selects positions not already done, queued or skipped. all selects the
   original snapshot with the same stable action keys; existing effects are reused.
5. Reload after a stale precondition. Concurrent browser/API retries serialize on
   the same Signal row and cannot create another local effect for one action position.

External webhook, portal invitation and supplier import actions also require
signals.providers.execute, native action rights and the default-off
SIGNAL_API_EXTERNAL_EXECUTION_ENABLED switch. API availability does not enable it.
Supplier imports retain Storage policy and canonical Email authentication; submitted
trust hints never prove identity. Current source mailbox access is still required.

## Operation Read-Back And Outcomes

Every mutation returns operation_id, replayed and X-Operation-Id. GET
/signals/operations/{operation_id} using signals.operations.read and the same actor/token
returns the encrypted receipt's saved local result, state and revision. Original
rights, additional scopes and canonical mailbox access are checked again.
Other actors and tokens cannot read the receipt.

- completed: local command committed; this does not prove inbox/provider delivery.
- queued: outbound intent/job exists. Webhook outbox states distinguish pending,
  running, delivered, failed and unresolved; there is no general resend/reset API.
- failed: action failed and later positions are not_run; other rules may still run.
- unknown / reconciliation_required: a committed handoff or external outcome is
  ambiguous. Reconcile the actual receiver/import/invitation before a new attempt.

Do not reset failed/unresolved deliveries or blindly resend an unknown outcome.
The webhook dispatcher and queue worker are independent operational components;
registered schedule entries do not prove execution health.

## Troubleshooting And Deployment

401: missing, expired or revoked authentication.
403: missing current user/token/action authority or source access.
404: missing exact target, wrong Signal/execution relationship or foreign receipt.
409: key reused for another method/path/body/precondition.
412: current state changed; reload/review before a new keyed operation.
422: unsupported field, invalid definition/value or malformed JSON.
428: required Idempotency-Key or If-Match missing.
503: Signal command migration missing.

The exact additive migration is 2026_10_27_030000_create_signal_api_commands.php.
After a separately approved deployment, regenerate Swagger and clear normal caches.
Preserve APP_KEY and receipt/execution history during recovery. Production and external
activation need separate approval; no historical job backlog is released by this change.
