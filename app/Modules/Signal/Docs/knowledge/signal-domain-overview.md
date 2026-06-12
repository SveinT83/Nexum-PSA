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

Email machine signals are archived before ticket routing so delivery failures and automatic replies
do not create customer tickets. Clients and Contacts expose related Signal history directly on their
profile pages. Signal rules can create Sales follow-up and operational Ticket follow-up. Marketing
and Sales may use Signal data for their own lists and follow-up views, but Signal itself stays focused
on event capture, rule execution, audit, and integrations.

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

## Rule Engine

Rules have conditions and actions stored as JSON. Conditions can match source domain, signal type,
severity, status, minimum confidence, client/contact presence, and payload fields.

Rule forms validate JSON against the supported condition and action catalog before saving. Unknown
actions, unknown condition fields, missing required action fields, and invalid webhook URLs are
blocked before the rule can execute.

Supported actions:

- Suppress marketing email for a linked Contact.
- Tag a linked Contact.
- Tag a linked Client.
- Emit a derived Signal.
- Create a Sales follow-up opportunity/activity.
- Create a Ticket follow-up.
- Queue a webhook delivery.

Every matching rule writes a `signal_rule_executions` row. Webhook attempts are tracked in
`signal_webhook_deliveries`.

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

## Permissions

- `signal.view`
- `signal.rule.manage`
- `signal.webhook.manage`
- `signal.action.execute`

## Operational Notes

Signal rules can mutate customer-facing data, such as Contact email eligibility. Keep rules specific
and review execution history when changing rule actions. Webhook delivery failure does not block
signal recording.
