# Feature Slice Index

## Workday And Tripletex Automatic Sync - Planned

[Four sequential Feature Slices](../plans/2026-10-05-workday-tripletex-delivery-plan.md)
under the [approved RFC](../rfc/2026-10-05-workday-tripletex-automatic-time-sync.md):
01 company connection/provider contract; 02 effective Save/duration-only time;
03 automatic two-way create/update/delete; 04 recovery/retention/pilot.
Slice 01: production identity verified; single-account setup Done On Dev (29 tests / 96 assertions; uniqueness migration applied). Controlled provider write/precision tests require a suitable test company; mapping/storage work remains. Slices 02-04: Draft / Not started. Owner: Codex; product and human review: Svein Tore.
Human-review checklist: HR-2026-10-05-WORKDAY-TRIPLETEX, In Review; initial save/identity confirmed, Main/production gate remains.
The original Workday slices below document earlier delivered confirmation-based behavior;
their confirmation requirements are superseded for the new target, not rewritten as history.

## Current Dev Pilot Status (2026-10-03)

Svein authorized a synthetic Dev pilot. Employee access is active; cleanup remains off.
Authenticated browser verification awaits user login; HR-2026-10-01-WORKDAY stays Pending.
See [pilot verification](../plans/2026-10-03-workday-dev-pilot-verification.md) and
[review guide](../plans/2026-10-03-workday-dev-pilot-review.md).
The dated default-off implementation evidence below describes earlier delivery state.


Store larger Feature Slice documents in this folder.

Use `docs/processes/feature-slice-process.md` for the required process and template.

Feature Slices break approved RFCs and beta-completion work into small, complete, testable pieces.

## Workday: Actual Time, Simple Plans And Absence

[Implementation plan](../plans/2026-10-01-workday-implementation-plan.md).
Owner: Codex / Svein Tore. Implementation approved by Svein on 2026-10-01; Slices 01-09 Done On Dev (API scope; default-off).
Run one primary slice at a time; API parity is included in every supported workflow.
Human review: HR-2026-10-01-WORKDAY, Pending/planned; Main and production gate.

1. [Simple Work Plan And One Working-Hours Source](2026-10-01-workday-01-simple-work-plan.md) (Done On Dev, default-off)
2. [Manual Workdays, Confirmation And Employee API](2026-10-01-workday-02-manual-days-and-api.md) (Done On Dev, default-off)
3. [Simple Absence And Consistent Calendar Display](2026-10-01-workday-03-absence-and-calendar.md) (Done On Dev, default-off)
4. [Existing Time And Calendar Evidence Reconciliation](2026-10-01-workday-04-source-reconciliation.md) (Done On Dev, default-off)
5. [Confirmed-Time Oversight And Reusable Rights](2026-10-01-workday-05-confirmed-oversight.md) (Done On Dev, default-off)
6. [Workday Reminders And Profile Notification Choices](2026-10-01-workday-06-profile-reminders.md) (Done On Dev, default-off)
7. [Explicit Internal Task Conversion Without Duplicate Time](2026-10-01-workday-07-explicit-task-conversion.md) (Done On Dev, default-off)
8. [Three-Year Retention And Recovery Operations](2026-10-01-workday-08-three-year-retention.md) (Done On Dev, default-off)
9. [Employee API Contract And Consumer Handoff](2026-10-01-workday-09-mcp-and-release-verification.md) (Done On Dev, API scope; default-off)

Slice 05 delivery (2026-10-02): confirmed overview, detail/history, Report discovery and reusable oversight rights are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-05-verification.md) records 136 passing tests / 1392 assertions, three API reads and the Superuser-only Dev permission migration. Existing tokens were not changed. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. Human review remains Pending until the complete pilot is ready.
Slice 06 delivery (2026-10-02): personal plan-aware reminders, Profile notification choices and four own API operations are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-06-verification.md) records 219 distinct tests / 2091 assertions, synthetic channel delivery, verified external scheduler and one additive Dev migration. Both switches remain off and no employee notifications were sent. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

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

Implementation progress (2026-10-01): [Slice 01 verification](../plans/2026-10-01-workday-slice-01-verification.md) records 75 passing tests,
default-off state and remaining human/production gates. Superseded by the 2026-10-02 delivery: Slices 02-03 are also Done On Dev. See [Slice 03 verification](../plans/2026-10-02-workday-slice-03-verification.md). Slice 09 is Done On Dev with the approved API scope.

Slice 04 delivery (2026-10-02): source reconciliation is Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-04-verification.md) records 271 distinct passing tests / 2236 latest assertions, two source API operations and one additive Dev migration. Source minutes stay within actual totals; original Task/Ticket/Calendar and billing data are unchanged. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending; Svein requested manual review only when the complete pilot actually needs it.

Slice 07 delivery (2026-10-02): explicit internal Task conversion is Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-07-verification.md) records 187 distinct tests / 1618 assertions, three API operations, transactional duplicate prevention and one additive Dev migration. No Task/time/billing fixtures or token changes were retained. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Slice 08 delivery (2026-10-02): three-year cleanup and restore operations are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-08-verification.md) records 228 distinct passing tests after the reminder metadata assertion update, original-source preservation, synthetic restore rehearsal and a fresh native employee-row lock probe. Metadata-only UI/API preview is implemented. WORKDAY_RETENTION_ENABLED is independent and false; no live purge, migration, employee activation or token change occurred. Dev inventory has zero Workday roots and 105 historical diagnostic copies, so restore_ready correctly remains false pending approved cleanup. External backup rotation/archive handling remains an explicit pre-activation check. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY stays Pending until complete-pilot review.

Slice 09 scope revision and delivery (2026-10-02, approved by Svein): MCP is deferred; LiteLLM
replaces NexumMCP as the intended external direction. Complete PSA APIs are the immediate
requirement. [Verification](../plans/2026-10-02-workday-slice-09-api-verification.md) records 34 published operations with exact
scope metadata and 196 passing tests / 1912 assertions. The provider-independent API consumer
guide is delivered; no adapter, live tool connection or production activation is claimed.
HR-2026-10-01-WORKDAY remains Pending for the combined pilot and Main/production review.

- [Workday and Tripletex functional pilot](2026-10-05-workday-tripletex-functional-pilot.md) — Done On Dev for the bounded pilot; broader rollout review remains open.
