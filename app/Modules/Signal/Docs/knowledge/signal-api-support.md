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
