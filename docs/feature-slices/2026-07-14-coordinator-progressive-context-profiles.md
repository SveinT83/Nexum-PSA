# Feature Slice: Coordinator Progressive Context Profiles

Status: Draft
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Add identified-business and selected full-context responses only after the minimal API and all
governance/privacy gates are proven.

## User-Visible Behavior

The owning company can select preset or custom field allowlists for identified business context and
selected free text. The UI shows the effective data classification and why a field is allowed or
blocked for each provider/model and agent/workload.

## Scope

- Add separate progressive read abilities for identified business context and selected full context.
- Add technician/Client names, record keys/titles, and individually selected text fields only when the
  effective profile allows them.
- Support local, privacy-relay, and direct-external modes under the approved governance prerequisites.
- Preserve the mandatory secret/credential denylist and workforce-purpose/transparency gate.
- Add profile preview using safe/manual test data and clear risk confirmations.

## Out Of Scope

- Attachment bodies.
- Automated employment decisions, technician rankings, productivity/disciplinary scoring, or write
  actions.
- Treating local or washed processing as automatically compliant or anonymous.

## Data Touched

Response projections and policy/profile configuration; source Ticket/Task records remain read-only.

## Permissions

Separate identified/full-context read abilities plus every existing policy, governance, context, and
record-visibility check.

## Tests

- Each field/data class requires the expected ability and effective allowlist.
- Global/provider/model/agent policy prevents widening.
- Direct external and privacy-relay prerequisites are enforced independently.
- Titles/free text are absent unless explicitly selected; credentials and secrets remain impossible.
- Workforce identification requires documented purpose/transparency and never emits score/rank fields.

## Documentation

Update OpenAPI/Knowledge docs with exact fields, classification, warnings, and omitted data.

## Done Criteria

- Progressive profiles cannot be reached through minimal abilities.
- Every optional field is settings-backed, enforced, tested, and documented.
- Focused Dev tests and authenticated HTTP smoke tests pass before release.
