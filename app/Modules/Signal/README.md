# Signal Domain

Signal owns normalized cross-domain signals, active rules, rule execution audit, and outbound
webhook deliveries.

## Current Slice

Implemented:

- `signals` event/classification records.
- `signal_rules` with JSON conditions and JSON actions.
- `signal_rule_executions` audit rows.
- `signal_webhook_deliveries` and queued webhook delivery.
- Tech UI for a searchable/sortable Signal feed, per-action execution detail, safe retry, and a
  compact condition/action rule builder.
- Signal settings for AI classification policy and ticket-routing stop types.
- Signal-specific AI agent defaults through Integration AI providers.
- Marketing campaign events write normalized Signal records.
- Inbound Email classifies bounces, automatic replies, out-of-office replies, and unsubscribe
  requests into normalized Signal records before ticket routing.
- Inbound Email can use AI-assisted Signal classification as a settings-controlled fallback after
  deterministic classifiers run.
- Email rules can explicitly emit selected inbound messages into Signal when an admin configures an
  `emit_signal` handoff action.
- Ticket creation rules can explicitly emit selected tickets into Signal after the ticket is
  persisted. Signal-created tickets skip Ticket rule signal handoff to avoid recursive automation.
- Intake public forms write an `intake_submission_received` Signal after successful non-spam
  submissions.
- Protected integrations can create signals through `POST /api/v1/signals` with the
  `signals.create` API ability.
- Client and Contact profile pages show related Signal history.
- Rules can create Sales follow-up opportunities/activities with `sales_follow_up`.
- Rules can create operational Tickets with `ticket_follow_up`.
- Rules can create Tasks with `task_follow_up`.
- Rules can send Customer Portal invitations with `portal_invitation`.

## Rule Conditions

Legacy rules continue to match these fields:

- `source_domain`
- `signal_type`
- `severity`
- `status`
- `min_confidence`
- `has_client`
- `has_contact`
- `payload_equals`
- `payload_contains`

New rules use versioned condition groups. Each group can require all conditions or at least one
condition, and the root can require all groups or at least one group. Builder rows support source,
type, severity, status, confidence, client/contact presence, and nested payload paths. Legacy maps
remain executable and are converted into builder rows when edited.

The visual builder is the normal editor. Advanced JSON is collapsed and must be explicitly enabled
before it becomes the save source. Unknown condition fields, unknown action types, missing required
action fields, and invalid webhook URLs are rejected before save.

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
- `task_follow_up`
- `portal_invitation`
- `webhook`

Example:

```json
[
  {"type": "marketing_suppress_contact_email"},
  {"type": "tag_contact", "tag": "Unsubscribed"},
  {"type": "sales_follow_up", "follow_up_minutes_from_now": 1440, "next_follow_up_type": "call"},
  {"type": "ticket_follow_up", "subject": "Investigate signal"},
  {"type": "task_follow_up", "subject": "Review signal", "due_minutes_from_now": 1440},
  {"type": "portal_invitation", "role": "viewer"},
  {"type": "webhook", "url": "https://example.test/webhook"}
]
```

Rules run by ascending priority. A rule can stop lower-priority rules only after all of its selected
actions complete without an exception. An action failure records that action as `failed`, records
later actions in the same attempt as `not_run`, and allows other matching rules to continue.

Every execution and retry writes an immutable audit row with per-action order and status. Normal
retry runs only failed or unstarted actions that have not already reached `done`, `queued`, or
`skipped`. The advanced full-rule rerun uses the same stable Signal/rule/action idempotency keys so
existing tickets, tasks, invitations, follow-ups, derived Signals, and webhook deliveries are not
created twice for the same action position.

## Signal Feed

The Tech Signal feed defaults to the last 30 days. Operators can select 7, 30, or 90 days, a custom
date range, or all history; search summary/type/source/client/contact; filter source, type, severity,
and status; and sort supported columns. Pagination preserves the active query.

Email and Ticket rule engines are not replaced by Signal. Email still owns inbound message triage
and ticket ingress. Ticket still owns ticket-local classification, SLA, workflow entry, and
assignment inputs. Signal owns cross-module automation after a normalized event is explicitly
recorded.

## AI Classification

Signal AI classification is disabled by default. Admins can enable it from Signal settings, set the
minimum confidence, choose source domains, choose allowed AI signal types, choose which signal types
skip ticket routing, and edit the grounding prompt.

The inbound Email producer always runs deterministic classifiers first. AI is only used as a fallback
when Signal settings allow it and an active Signal AI agent exists. AI classification can only return
one of the configured allowed signal types. It records normal Signal rows and does not execute actions
directly; Signal rules still decide mutations such as tagging, suppression, Sales follow-up, Ticket
follow-up, and webhooks.
