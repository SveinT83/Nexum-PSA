# Operational Signals Domain Idea

Status: Draft, not fully discussed.

This document captures a future idea for handling incoming machine, service, monitoring, SSL, asset, and operational notification emails that are valuable but should not always become tickets.

## Problem

Many incoming emails are currently treated like noise or spam because they are not directly actionable by a technician.

Examples:

- QNAP or NAS notifications.
- Device app center messages.
- Power, storage, backup, or security warnings.
- SSL certificate renewal reminders.
- SSL certificate renewed confirmations.
- Domain or service lifecycle notifications.
- Monitoring messages from systems that are not yet integrated through an API.

These emails exist for a reason, but humans cannot manually process a large volume of them in Inbox or Ticket views without losing focus.

The system needs a way to keep the value of these messages without forcing every message into Inbox or Ticket.

## Proposed Concept

Introduce an Operational Signals capability.

A Signal is a structured operational event detected from an incoming email or future integration payload.

Signals are not tickets by default.

Signals can be:

- Logged only.
- Linked to an Asset, Client, Site, Contact, Domain, or future Service record.
- Shown on an entity timeline.
- Escalated to a Ticket when rules say the signal requires action.
- Ignored or dismissed when not useful.

## Suggested Flow

1. Email arrives in the Email Domain.
2. Email Rules evaluate the message.
3. A rule may classify the message as:
   - Ticket
   - Inbox item
   - Spam/ignored
   - Operational Signal
4. Signal matching attempts to identify related entities.
5. The signal is stored and optionally shown on the entity timeline.
6. Escalation rules decide whether to create a Ticket.

## Matching Examples

Signals may match by:

- Asset name.
- Hostname.
- Serial number.
- Device identifier, such as `NAS99D3C6`.
- Domain name.
- Email sender.
- Subject pattern.
- Client-specific rule.
- Site-specific rule.
- Future custom fields or metadata keys.

Example:

An email subject contains:

```text
[Info] [App Center] Notification from your device: NAS99D3C6
```

If `NAS99D3C6` matches an asset name, hostname, alias, or custom field, the signal should be added to that asset timeline.

If no asset exists, the signal may still be stored as an unmatched Signal and optionally grouped by the detected token.

## Signal Severity

Suggested severities:

- Info
- Notice
- Warning
- Error
- Critical

Severity may be rule-driven first. AI may help later, but should not be required for v1.

## Escalation Rules

Operational Signals need rules that decide when a Ticket should be created.

Examples:

- Info from QNAP: log only.
- Warning from QNAP: log and show on dashboard.
- Critical from QNAP: create Ticket.
- Three warnings from the same asset within 24 hours: create Ticket.
- SSL expires within 14 days: create Ticket.
- SSL renewed successfully: log only.
- Backup failed once: log warning.
- Backup failed three times: create Ticket.

Rules should be configurable globally and eventually per Asset, Client, Site, or Service.

## Entity Timelines

Signals should be visible where they create context.

Examples:

- Asset timeline: device notifications, warnings, service signals.
- Client timeline: client-wide service alerts.
- Site timeline: site-specific infrastructure messages.
- Domain/service timeline: SSL and domain lifecycle messages.

This should not replace audit logs. Signal history is operational context for technicians.

## Relationship To Other Domains

Email Domain:

- Owns inbound email ingestion.
- Runs email rules.
- Can classify a message as a Signal instead of Ticket or Inbox.

Ticket Domain:

- Receives escalated Signals when a rule creates a Ticket.
- Tickets should remain for actionable work, not raw notification storage.

Asset Domain:

- Can receive matched Signals on asset timeline.
- May define asset-specific signal rules later.

Custom Fields and Metadata:

- May provide stable identifiers used for matching.
- Examples: `asset_hostname`, `monitoring_device_id`, `qnap_device_id`, `domain_name`.

Intelligence Domain:

- Future domain can analyze Signals for trends, health, and recommendations.
- Operational Signals should produce structured data suitable for later intelligence work.

## Initial Scope Recommendation

This idea is not ready for implementation yet.

Recommended first version when discussed:

- Add Signal storage.
- Add basic Email Rule action: `create_signal`.
- Add simple pattern/entity matching for hostname, asset name, and domain name.
- Add Signal timeline entries on Asset and Client where matched.
- Add escalation rule support for severity/count/time-window.
- Add tests for email classification, signal creation, matching, dismissal, and ticket escalation.
- Add Knowledge documentation when implemented.

## Out Of Scope For First Version

- Full monitoring platform replacement.
- Native QNAP, SSL, backup, or domain registrar integrations.
- AI-only classification.
- Complex ML/Intelligence scoring.
- Automatic asset creation.
- Complex dashboard analytics.

## Open Questions

- Should this live as a standalone `Signal` module/domain or as part of Email initially?
- Should unmatched Signals be visible in a dedicated review view?
- How long should low-value Info signals be retained?
- Should Signal rules be global first, or should Asset-specific rules be included in v1?
- Should recurring Signals be grouped into one rolling Signal thread?
- Which entities need timelines before this can be useful?

## GitHub Idea Text

```markdown
# Idea: Operational Signals Domain

Nexum receives many operational emails that are not really spam, but also should not always become tickets.

Examples include QNAP/device notifications, backup warnings, SSL certificate reminders, SSL renewal confirmations, domain lifecycle messages, monitoring alerts, and other system-generated messages.

Today these messages can overwhelm Inbox and Ticket workflows. Humans cannot manually handle a large volume of low-level operational notifications, but the information still has value.

## Proposal

Introduce an Operational Signals capability.

A Signal is a structured operational event detected from inbound email or future integrations.

Signals are not tickets by default.

Signals may be:

- Logged only.
- Linked to an Asset, Client, Site, Contact, Domain, or future Service record.
- Shown on an entity timeline.
- Escalated to a Ticket when rules say action is needed.
- Dismissed or ignored when not useful.

## Example

An inbound email subject says:

`[Info] [App Center] Notification from your device: NAS99D3C6`

If `NAS99D3C6` matches an asset name, hostname, serial, alias, or custom field, the event should be logged on that asset timeline.

If the message is only informational, no ticket should be created.

If the message is critical, or repeated several times in a time window, rules may create a ticket automatically.

## Suggested Flow

1. Email arrives in the Email Domain.
2. Email Rules classify it as Ticket, Inbox, Spam/Ignored, or Signal.
3. Signal matching tries to identify related entities.
4. The Signal is stored and shown on relevant timelines.
5. Escalation rules decide whether to create a Ticket.

## Escalation Examples

- Info from QNAP: log only.
- Warning from QNAP: log and show on dashboard.
- Critical from QNAP: create Ticket.
- Three warnings from the same asset within 24 hours: create Ticket.
- SSL expires within 14 days: create Ticket.
- SSL renewed successfully: log only.
- Backup failed once: log warning.
- Backup failed repeatedly: create Ticket.

## Relationship To Existing Domains

Email should own ingestion and rule classification.

Ticket should only receive Signals that require work.

Asset should receive matched Signals on its timeline.

Custom Fields and Metadata can provide stable matching identifiers such as `asset_hostname`, `monitoring_device_id`, `qnap_device_id`, or `domain_name`.

Future Intelligence functionality can analyze Signals for trends, customer health, asset health, and recommendations.

## First Version Scope

- Store Signals.
- Add Email Rule action for creating Signals.
- Match Signals to known Assets, Clients, Sites, and domain/service identifiers where possible.
- Show Signals on entity timelines.
- Add simple escalation rules.
- Allow dismissal/ignore.
- Add tests and Knowledge documentation.

This idea is not fully discussed yet and should be refined before implementation.
```
