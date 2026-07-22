# Feature Slice: CloudFactory Secure Connection

Status: Done
Date: 2026-07-20
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Connect a dedicated CloudFactory Portal service account without storing login or MFA credentials.

## User-Visible Behavior

An administrator can save or replace a masked refresh token, verify Partner Self and roles, configure
polling and write controls, synchronize now, and revoke/disconnect safely. Role discovery uses
CloudFactory's authenticated `/Authenticate/Roles` endpoint rather than token claims. Capabilities
refresh automatically during token renewal and can be refreshed manually without replacing the
stored refresh token.

## Scope

Encrypted tokens, automatic access-token exchange, role/health discovery, retry/backoff, secret-safe
errors, `RevokeAllTokens`, settings UI, and audit records.

## Out Of Scope

Webhook delivery until CloudFactory documents signing and replay protection.

## Data Touched

`integrations`, CloudFactory sync runs, operations, and audit events.

## Permissions

`integration.cloudfactory_view`, `integration.cloudfactory_manage`, and
`integration.cloudfactory_write`.

## Tests

HTTP-faked token, role, refresh, retry, masking, validation, revocation, and authorization tests.

Dev validation on 2026-07-21 returned 18 roles for the connected account and correctly enabled
Partner, Microsoft, Finance, notification, and activity-log capabilities while leaving Adobe
unavailable because the account did not return the Adobe role.

## Documentation

CloudFactory administrator Knowledge and operator runbook.

## Done Criteria

- [x] Secrets are never rendered or logged.
- [x] Connection, role discovery, capability refresh, and revocation pass automated tests.
- [ ] Runtime human review remains recorded.
