# Feature Slice: AI Data Egress Policy Foundation

Status: Done
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Create the Integration-owned installation maximum policy and one enforceable decision service.

## User-Visible Behavior

An administrator can enable or disable AI, external processing, privacy washing, and direct external
processing; select allowed processing modes, maximum data profile, context scope, retention, and
request limits; and see the effective privacy-first defaults.

## Scope

- Add relational policy/revision storage after confirming the Dev schema.
- Add the installation maximum policy with default AI off, external off, privacy gateway on, direct
  external off, and aggregate data maximum.
- Add one policy evaluator with stable allow/deny reason codes.
- Enforce that lower-level policy cannot widen the installation maximum.
- Add explicit Admin permission, Bootstrap settings UI, validation, and revision actor/time metadata.

## Out Of Scope

- Provider/model governance forms.
- Actual local/external model routing.
- Coordinator API endpoints.

## Data Touched

New Integration-owned policy and policy-revision records; exact table names and indexes are confirmed
against the Dev database before implementation.

## Permissions

New Integration privacy-policy management permission. Reading effective policy may use a narrower
diagnostic permission; mutation remains Admin-only.

## Tests

- Privacy-first defaults on a clean install.
- Persistence, validation, revision metadata, and authorization.
- Every lower policy dimension can narrow but not widen the maximum.
- Disabled/expired policy fails closed with stable reason codes.

## Documentation

Add Integration Knowledge documentation for installation-level AI/data-egress policy.

## Done Criteria

- One tested evaluator is the required entry point for later AI/coordinator disclosure.
- The settings UI only exposes behavior that is enforced.
- Migrations, permission seed, focused tests, and Admin HTTP smoke test pass on Dev.
