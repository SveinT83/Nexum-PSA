# ADR: Internal SSO Authentication Boundary

Status: Accepted
Date: 2026-10-05
Decision Makers: Codex, implementing Svein Tore's approved SSO RFC

## Context
Keycloak login must preserve local access, Fortify MFA and ongoing Vault UserSecurity work.

## Decision
UserManagement owns isolated Sso classes/tables and additive provider/routes/views.
Use jumbojett/openid-connect-php 1.0.2 with Laravel HTTP transport, strict same-origin
HTTPS metadata/endpoints, RS256-only signatures, mandatory state/nonce/expiry and exact
issuer/subject identity. Its optional claims are tightened by the application adapter.
Do not store access/refresh/ID tokens. Client secrets and temporary PKCE material are encrypted.
Scope is openid only. No automatic email matching, account provisioning or role writes.

Use Fortify's existing challenge endpoint; call DatabaseTwoFactorChallenge::capture when
UserSecurity cutover is active. SSO supplies no Vault proof and never writes remember tokens.
No existing Vault/security writer, Fortify action, Core User or cutover is changed.
Retain local credentials, invitations and MFA requirements. Required-but-unenrolled 2FA
continues through the existing setup gate.

One-time attempts use transactional row consumption and browser-session binding.
Server-side grants enforce expiry, local account fingerprint, unlink/provider changes and
signed back-channel revocation independently of Laravel's session storage driver.
Grant IDs stay in the server session, not browser local storage. Receipt uniqueness prevents
logout-token replay. Role/status authority is rechecked on every SSO request.

## Rationale
This uses existing security extension points and keeps active Vault ownership intact.
No parallel implementation of password, TOTP lifecycle, recovery mutation or step-up is needed.

## Consequences
SSO is default-off and needs trusted HTTPS plus a separately registered Keycloak client.
Existing local MFA remains an additional prompt. Runtime activation and real employee pilot
require named human review. Configuration uses the existing user.manage_2fa permission.
Provider metadata verification is not client-credential or successful-login evidence.

## Alternatives Considered
Email auto-linking and claim-driven roles risk taking over or elevating local accounts.
Replacing the authentication guard or Vault boundaries would collide with active work.
Native PHP sessions/global redirects in the OIDC library do not fit Laravel; only its
token verification primitives are used through the narrow adapter.

## Follow-Up
Run protocol/feature regressions, document recovery, and verify the real Dev pilot before
production promotion. Existing unrelated Composer commonmark advisories require separate
tracked dependency remediation; the three added OIDC dependency packages have no reported
advisory in the 2026-10-05 Composer audit.
