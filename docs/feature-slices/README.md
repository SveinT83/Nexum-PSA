# Feature Slice Index

Store larger Feature Slice documents in this folder.

Use `docs/processes/feature-slice-process.md` for the required process and template.

Feature Slices break approved RFCs and beta-completion work into small, complete, testable pieces.

## Ticket Rules Triggers, Ordered Actions, And Audited Execution

1. `2026-08-25-ticket-rules-architecture-versions-legacy-compatibility.md` (Done)
2. `2026-08-25-ticket-rules-execution-envelope-audit-loop-foundation.md` (Done)
3. `2026-08-25-ticket-rules-standard-update-message-assignment-tag-automation.md` (Done)
4. `2026-08-25-ticket-rules-workflow-actions-composite-events.md` (Done)
5. `2026-08-25-ticket-rules-ticket-custom-fields-assignment-parity.md` (Done)
6. `2026-08-25-ticket-rules-admin-builder-execution-history-release-hardening.md` (Done)

All six Feature Slices are implementation-complete on authoritative Dev. All new Ticket Rules copy
remains English and no language files were added. Runtime activation remains default-off until the
relevant human review and separate release approval permit it. Database authority remains legacy;
every v2 trigger, action, Custom Field, and full-rerun capability remains off. Authenticated
responsive/keyboard/touch review remains Pending under `HR-2026-08-25-013`.

## RMM Alert Rules

1. `2026-08-25-rmm-alert-rules-occurrence-and-audit-foundation.md` (Done on Dev; human review pending)
2. `2026-08-25-rmm-alert-rules-domain-actions.md` (Done on Dev; human review pending)
3. `2026-08-25-rmm-alert-rules-admin-and-operations.md` (Done on Dev; human review pending)

Rule definitions remain inactive by default. Controlled retry, recurrence windows, resolution
actions, notifications, scripts/remediation, webhooks, and AI require later approved slices.

## Task Stopwatch And Time Registration

1. `2026-08-25-task-ticket-billing-minimum-and-time-authority.md` (Done on Dev; migration and human review pending)


## Calendar Ownership Rollout

1. `2026-07-29-calendar-ownership-view-metadata.md` (Done)
2. `2026-07-29-calendar-owner-badges-accessible-color.md` (Done)
3. `2026-07-29-calendar-type-indicators.md` (Done)
4. `2026-07-29-calendar-ownership-filters.md` (Done)
5. `2026-07-29-calendar-mobile-readability.md` (Done)
6. `2026-07-29-calendar-ownership-rollout-tests-knowledge.md` (Done)
## Ticket API Customer Completion

1. `2026-07-29-ticket-api-portal-publication.md` (Done)
2. `2026-07-29-ticket-api-idempotent-customer-reply.md` (Done)
3. `2026-07-29-ticket-api-solution-completion.md` (Done)

## Ticket API Read Completion

1. `2026-08-25-ticket-message-read-api.md` (Done; human review pending)

## AI Model Usage And Cost Telemetry

1. `2026-07-27-ai-model-execution-usage-ledger.md` (Done)

The parent RFC orders the remaining direct-call coverage, rate-card, reporting/retention, and
optional budget slices. Create each detailed slice before implementing it.

## Web Push And Inbound Email Alerts

1. `2026-07-24-web-push-channel-device-foundation.md` (Done; human browser/device review remains open)
2. `2026-07-23-web-push-internal-email-alerts.md` (Done)
3. `2026-07-24-web-push-read-sync-rollout-hardening.md` (Done)

Do not enable production inbound Web Push until the named human checks in `HR-2026-07-24-001` and
`HR-2026-08-11-002` are complete.
