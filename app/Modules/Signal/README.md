# Signal Domain

Signal owns normalized cross-domain signals, active rules, rule execution audit, and outbound
webhook deliveries.

## Current Slice

Implemented:

- `signals` event/classification records.
- `signal_rules` with JSON conditions and JSON actions.
- `signal_rule_executions` audit rows.
- `signal_webhook_deliveries` and queued webhook delivery.
- Tech UI for signal feed, signal detail, rules, and rule editing.
- Marketing campaign events write normalized Signal records.
- Inbound Email classifies bounces, automatic replies, out-of-office replies, and unsubscribe
  requests into normalized Signal records before ticket routing.
- Protected integrations can create signals through `POST /api/v1/signals` with the
  `signals.create` API ability.
- Client and Contact profile pages show related Signal history.
- Rules can create Sales follow-up opportunities/activities with `sales_follow_up`.
- Rules can create operational Tickets with `ticket_follow_up`.

## Rule Conditions

Rules currently match these fields:

- `source_domain`
- `signal_type`
- `severity`
- `status`
- `min_confidence`
- `has_client`
- `has_contact`
- `payload_equals`
- `payload_contains`

Rule JSON is validated before save. Unknown condition fields, unknown action types, missing required
action fields, and invalid webhook URLs are rejected in the form.

Example:

```json
{
  "source_domain": ["marketing"],
  "signal_type": ["unsubscribe"],
  "min_confidence": 80
}
```

## Actions

Supported direct actions:

- `marketing_suppress_contact_email`
- `tag_contact`
- `tag_client`
- `emit_signal`
- `sales_follow_up`
- `ticket_follow_up`
- `webhook`

Example:

```json
[
  {"type": "marketing_suppress_contact_email"},
  {"type": "tag_contact", "tag": "Unsubscribed"},
  {"type": "sales_follow_up", "follow_up_minutes_from_now": 1440, "next_follow_up_type": "call"},
  {"type": "ticket_follow_up", "subject": "Investigate signal"},
  {"type": "webhook", "url": "https://example.test/webhook"}
]
```

Every rule execution writes an audit row with action results.
