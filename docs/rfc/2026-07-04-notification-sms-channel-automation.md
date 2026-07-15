# RFC: Notification SMS Channel And Automation

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

GitHub Discussion #172 defines SMS as an auditable transactional Notification channel. Nexum has an
existing Notification system plus Email, Ticket, Calendar, Sales, Economy, Signal, and future
ServiceVisit/Booking workflows. What is missing is an SMS provider abstraction, consent-safe
delivery guard, SMS templates, and delivery audit trail.

This is Level 3 work because the full direction introduces external providers, consent/opt-out
handling, templates, cost logging, retries, inbound handling, and cross-module triggers. The first
approved slice is deliberately smaller: dry-run provider foundation only, with no production SMS
provider and no workflow automation triggers.

## Goals

- Add SMS as a Notification channel.
- Support provider abstraction for a first `dry_run` provider, with Telia, Twilio, and future
  providers added in later slices.
- Store sender configuration, provider selection, and dry-run/test mode.
- Separate transactional SMS templates from Marketing campaigns.
- Log send attempts, delivery status, provider IDs, cost metadata, retries, and failures.
- Prepare for inbound/two-way SMS where provider capabilities allow it later.
- Enforce consent, opt-out, phone quality, and audit rules.

## Non-Goals

- Do not build marketing SMS campaigns in this RFC.
- Do not store provider secrets directly in Notification if Integration owns credentials.
- Do not send SMS from workflows that have not explicitly adopted SMS.
- Do not implement Telia, Twilio, or any production provider in the first slice.
- Do not implement inbound/two-way SMS in the first slice.
- Do not implement Booking reminders, quote follow-up, invoice reminders, on-my-way messages, or
  any other automated workflow trigger in the first slice.

## Current Behavior

Nexum can send notifications through existing channels, but SMS is not available as a transactional
provider-backed channel.

## Proposed Change

Extend Notification with an SMS channel and provider contract. The first approved implementation
slice is a dry-run foundation that proves configuration, consent checks, template rendering, and
audit logging before any external provider is introduced.

Notification owns:

- SMS templates,
- channel configuration,
- send orchestration,
- delivery logs,
- retry and failure behavior once real providers are added.

Integration owns provider credentials and connection health where provider settings include secrets.
Source modules own when an SMS should be sent.

First slice behavior:

- Seed a system-wide `sms` Notification channel with provider `dry_run`.
- Add transactional SMS templates separate from Marketing campaign templates.
- Add SMS send/audit log records for manual dry-run sends.
- Add a `SendTransactionalSms` action or equivalent service that validates channel state,
  recipient phone quality, `contact_phones.sms_allowed`, and `contacts.do_not_call` before logging a
  dry-run send.
- Add admin channel settings for enable/disable, provider selection, sender name, default country
  code, and manual test send.
- Keep source-module adoption disabled until later approved slices explicitly connect Booking,
  Sales, Economy, Ticket, Calendar, or ServiceVisit events to SMS.

## Impact Analysis

- **Notification:** new channel, templates, logs, provider abstraction.
- **Integration:** no first-slice credential storage; future production providers use Integration
  for credentials and connection health where secrets are required.
- **Contact:** phone normalization, `sms_allowed`, and `do_not_call` guard checks.
- **Marketing:** opt-out/consent coordination without owning transactional SMS.
- **Calendar/Booking/ServiceVisit/Ticket/Sales/Economy:** no first-slice triggers; later slices may
  adopt SMS explicitly.
- **Signal:** no first-slice SMS actions; later automation rules may call SMS after source workflows
  are approved.
- **Security/Privacy:** consent guard, audit trail, and clear separation from marketing messages.

## Data And Migration Plan

First slice:

- Add an `sms` row to `notification_channels`.
- Add SMS template records or a transactional template table owned by Notification.
- Add SMS message/send log records with provider, status, recipient, rendered body, actor, source,
  failure reason, and metadata.
- Reuse existing `contact_phones.sms_allowed` for transactional SMS consent and `contacts.do_not_call`
  as a block condition. Add missing UI/API handling only where primary contact phone editing already
  exists.

Later slices may add provider credential references, provider message IDs, inbound message logs,
delivery callbacks, retry queues, and cost metadata. Existing notifications remain unchanged.

## Testing Plan

- SMS channel seed and admin enable/disable tests.
- Dry-run manual test send tests.
- Consent and opt-out denial tests using `contact_phones.sms_allowed` and `contacts.do_not_call`.
- Template rendering tests.
- Send log tests.
- Disabled channel and missing phone tests.
- Source module integration tests only as each later workflow adopts SMS.
- Inbound provider webhook validation tests when inbound is implemented later.

## Documentation Plan

- Update Notification Knowledge docs.
- Add dry-run SMS setup docs.
- Update source module docs when they add SMS triggers.

## Decisions

- First provider is `dry_run`.
- First use case is admin-configured foundation and manual dry-run test send only.
- Production providers, inbound SMS, and workflow automation triggers require later approved slices.

## Open Questions

No open questions block the first dry-run foundation slice.

## Approval

Approved by Svein Tore on 2026-07-05 in chat, with the first implementation limited to the
dry-run SMS foundation described in this RFC.
