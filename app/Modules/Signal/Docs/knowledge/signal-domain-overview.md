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

Intake creates Signal records for:

- Successful non-spam public form submissions.

Email and Ticket rules can also create Signal records, but only through explicit `emit_signal`
actions configured by an admin. Email rule handoff is for selected inbound messages that should
become operational events. Ticket rule handoff is for selected ticket creation cases that should
trigger cross-module automation after the ticket is persisted.

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
- Create a Task follow-up.
- Send a Customer Portal invitation.
- Queue a webhook delivery.

Signal should be the automation owner after a normalized event exists. Email remains the owner of
inbound message triage and ticket ingress, while Ticket remains the owner of ticket-local field
routing, SLA selection, workflow entry, and assignment inputs.

Rules run in priority order. A rule may stop lower-priority rules after it succeeds. If an action
fails, later actions in that rule are not run, but other matching rules continue. Every original
attempt and retry writes a `signal_rule_executions` row with the status of each action. From Signal
detail, an operator with `signal.action.execute` can retry only failed/unstarted actions or use the
warned full-rule rerun. Stable action keys prevent the same side effect from being created twice.
Webhook attempts are also tracked in `signal_webhook_deliveries`.

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
