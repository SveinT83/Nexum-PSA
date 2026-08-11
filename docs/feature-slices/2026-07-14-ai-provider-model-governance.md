# Feature Slice: AI Provider And Model Governance

Status: Done
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Let each Nexum-owning company approve providers/models and define stricter agent/workload policies
without exceeding the installation maximum.

## User-Visible Behavior

Admins record purpose, recipient, processing/support regions, DPA status, subprocessors, transfer
review, retention/training declarations, DPIA decision and rationale, approval owner, and expiry.
Each provider/model and agent/workload selects a permitted processing mode and maximum data profile.

## Scope

- Add provider/model governance and approval records linked to existing AI providers.
- Add model-specific policy because one provider can expose models with different handling.
- Add agent/workload overrides that can only narrow the installation/provider/model maximum.
- Require documented purpose, DPA/region/transfer fields as applicable, and a DPIA decision before
  direct external processing can be approved.
- Require workforce-purpose/transparency documentation before identified technician activity is used.
- Add expiry warnings and fail-closed approval state.

## Out Of Scope

- Legal certification by Nexum.
- Automatic provider contract/subprocessor verification.
- Actual prompt routing or sanitization.

## Data Touched

New Integration-owned governance, provider/model policy, agent/workload policy, approval, and revision
records linked to existing `ai_providers` and `ai_agents`.

## Permissions

Separate permissions for governance management and read-only governance review.

## Tests

- Incomplete, rejected, disabled, and expired approvals block external processing.
- `DPIA not required` requires a rationale and reviewer.
- Direct external identified/full data cannot activate without all prerequisites.
- Provider/model and agent/workload settings cannot widen the parent policy.
- Workforce identification is blocked without its purpose/transparency gate.

## Documentation

Add provider/model governance and workforce-privacy sections to Integration Knowledge docs.

## Done Criteria

- Admin can understand why a mode/profile is allowed or blocked.
- Governance records are auditable without claiming legal certification.
- Focused Integration/permission tests and Dev HTTP smoke tests pass.
