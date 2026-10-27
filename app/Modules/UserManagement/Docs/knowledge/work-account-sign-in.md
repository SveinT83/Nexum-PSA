Work account sign-in lets an internal employee use Keycloak to access an existing Nexum account.
Customer Portal login and local password login remain available.

## Set up the provider

An administrator with user.manage_2fa opens User Management, 2FA Enforcement, then
Work account sign-in settings. Use a separate confidential Keycloak client for each installation.
Enable authorization code / standard flow with PKCE S256 and RS256 ID-token signing.
Use only the exact HTTPS callback and post-logout URLs displayed in Nexum. Register the displayed
back-channel logout URL and enable session-required back-channel logout. Disable direct access
grants and implicit flow. Request only openid; no directory/group access is required.

Save the issuer, client ID and secret. Blank secret preserves the saved secret. Saving requires
the current local password and a Nexum authenticator code if configured. The secret is encrypted
and never displayed. Discovery verification confirms metadata only, not client credentials,
actual employee access or provider MFA policy.

The operational SSO_ENABLED setting defaults to false. A configured provider and the operational
gate must both be enabled to offer work-account login. Keep this off until the separate client is configured for the controlled Dev pilot.
The provider-owned MFA policy was deployed on 2026-10-22 at Svein Tore's explicit request. Complete the remaining production acceptance checks in HR-2026-10-05-SSO and HR-2026-10-08-SSO-MFA. Retain a tested emergency local administrator.

## Link an employee

Sign in normally, open Profile > Work Account (SSO), enter the current Nexum password and the current
Nexum authenticator code when enabled, then choose Link work account. Authenticate the intended
Keycloak account and complete any requested MFA. On returning to Nexum, check for the
Work account linked confirmation and the linked provider. The profile page shows setup steps
until linked, then explains future sign-in and how to disconnect. Nexum binds its verified issuer and stable subject to the current local user.
Matching email addresses do not link accounts automatically. A subject cannot replace another
user's link, and one local user cannot receive two links.

## Sign in and sign out

Choose Sign in with work account. The account must already be linked, active and have internal
access. Complete the authentication required by your organisation. A validated SSO login does
not ask for an additional local Nexum 2FA code. A current valid SSO session also satisfies the
role-based login MFA requirement. Roles, permissions and customer memberships are unchanged.

Your organisation must enforce MFA for the Nexum client/users at the identity provider.
Nexum validates the provider's OIDC login; it does not independently verify a second factor
through an assurance claim. Administrators must verify the provider policy before rollout.

Keep local Nexum 2FA enabled for password fallback and retain recovery codes. Choosing local
password login still requires local 2FA when configured, or local enrollment if your role
requires it. Linking, unlinking and sensitive provider changes still ask for your local
password and configured 2FA. SSO does not satisfy Vault step-up or password confirmation.

Normal logout ends the local Nexum session. Profile > Work Account (SSO) offers explicit work-account
logout and explains that other applications can also be signed out. Keycloak may show its own
logout confirmation because Nexum does not retain an ID token.

SSO sessions last at most 60 minutes and have no persistent remember-me cookie. Back-channel
logout, unlinking, provider configuration changes and local account-security changes revoke
matching SSO access. Revocation is checked at the next request. Disabling a Keycloak user alone
is not proof of session revocation: terminate their existing Keycloak sessions and verify the
back-channel notification. Independently disable their Nexum account for immediate local denial.

## Recovery

If Keycloak is unavailable, use local password login. Operationally disable SSO and refresh
configuration to stop SSO sessions without depending on provider availability. Do not delete
user accounts, reset passwords or modify database identity links as a workaround.
Unlinking from Profile requires the local password and existing Nexum authenticator code.
Relinking is explicit and preserves business history.

Callbacks, token errors and account-linking failures use generic messages; upstream token bodies
are not written to application logs. Front proxy/access logging must redact the query string
on /sso/callback because the incoming authorization code is carried there by OIDC.

## Rollout status

The provider-owned MFA amendment is available in production at portal.tronderdata.no
from 2026-10-22 through Main PR #307. The SSO menu and page are Work Account (SSO).
The production files, trusted HTTPS and unchanged credentials/provider/identity state
were independently verified. Provider settings and local factors were preserved.

The explicit rollout request authorizes deployment; it does not confirm the remaining
human checks. HR-2026-10-08-SSO-MFA is In Review for fresh provider MFA, SSO without a
second Nexum code, local password MFA/recovery, sensitive operations and revocation.
The original HR-2026-10-05-SSO retains its remaining checks. Employee assistant retrieval
is a separate verification claim. Contact the administrator if a fresh work-account
login still asks for an extra local login code; retain local 2FA for password fallback.

## API access to the same work-account flow

The typed UserManagement API provides own linked-account status, protocol discovery,
login/link handoff, callback result, unlink/logout and redacted provider settings.
It retains the same first-party session, local password/factor verification, issuer/subject,
nonce/state/PKCE and session revocation. An administrative bearer scope cannot substitute
for the signed-in employee's local proof. See [User Management API](user-management-api.md).
Availability is verified on Dev; this does not authorize provider or production activation.
