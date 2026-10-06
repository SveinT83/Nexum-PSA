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
HR-2026-10-05-SSO must be completed before production or general rollout. Retain a tested emergency local administrator.

## Link an employee

Sign in normally, open Profile > Work Account, enter the current Nexum password and the current
Nexum authenticator code when enabled, then choose Link work account. Authenticate the intended
Keycloak account. Nexum binds its verified issuer and stable subject to the current local user.
Matching email addresses do not link accounts automatically. A subject cannot replace another
user's link, and one local user cannot receive two links.

## Sign in and sign out

Choose Sign in with work account. The account must already be linked, active and have internal
access. Nexum's existing local two-factor challenge still applies; upstream MFA does not replace
it. Roles, permissions and customer memberships are unchanged.

Normal logout ends the local Nexum session. Profile > Work Account offers explicit work-account
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

Implementation and automated validation are complete on Dev. The separate Keycloak client,
explicit account linking, real employee sign-in, signed back-channel logout and work-account
logout were verified on 2026-10-06. Dev is enabled for the controlled pilot. Local Nexum MFA
and the remaining human checks are still required before general rollout; production is not activated.
