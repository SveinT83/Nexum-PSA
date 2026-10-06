# RFC: Internal Keycloak Single Sign-On

Status: Approved
Date: 2026-10-05
Owner: Codex / Svein Tore
Change level: 3 - Authentication, integration, and identity persistence

## Context

Svein requested SSO for Nexum PSA now that Keycloak is in use internally.
On 2026-10-05 Svein selected internal employees first; Customer Portal users retain
their current login. Svein explicitly approved this RFC in conversation on 2026-10-05.

## Goals

- Offer "Sign in with work account" for existing internal Nexum users.
- Use Keycloak OpenID Connect while retaining Nexum account status, roles and permissions.
- Provide complete configuration, account linking, login, logout, recovery, audit,
  tests and operating documentation before enabling the feature.
- Keep configuration reusable per installation rather than hardcoding Tronder Data.

## Non-Goals

Customer Portal SSO, customer-owned providers, SAML/LDAP, automatic account creation,
group-to-role provisioning, password synchronization, and replacement of Sanctum API
authentication are outside this change.

## Current Behavior

Authoritative inspection: /var/Projects/tdPSA on branch Dev, 2026-10-05.
FortifyServiceProvider authenticates an active, non-system User using a local password.
The web session guard uses user_management and the Eloquent provider. Existing Fortify
two-factor behavior and UserManagement account lifecycle must remain effective.
CustomerPortal shares login identities but owns separate account/membership authorization
under the accepted 2026-07-04-customer-portal-identity-separation ADR.

No Nexum Keycloak/OIDC login implementation or approved SSO RFC was found in the inspected
authentication configuration, UserManagement module, RFCs or ADRs. Nextcloud's
04-sso-future-plan.md explicitly places SSO outside Nextcloud.

Live public discovery, using normal certificate validation, returned:
- Issuer: https://auth.tronderdata.no/realms/tronderdata
- Authorization code and PKCE S256 support: true
- Back-channel logout support: true

This proves discovery availability, not Nexum client registration, credentials, claims,
MFA policy, callback reachability or a successful employee login. No Keycloak setting
was changed. GitHub Issue search for SSO/Keycloak/OIDC found no directly matching item;
Discussion search and customer-SSO Discussion #165 were reconciled before handoff; its future customer scope remains separate.

## Proposed Change

### Configuration and ownership

UserManagement owns provider settings, identity linking and login routes/controllers/views.
This is the existing shared authentication owner; Nextcloud retains its current integration
role. Record the ownership decision in an ADR and reconcile Nextcloud future-SSO guidance.

An authorized administrator configures one internal OIDC provider per installation:
issuer, client ID, encrypted client secret and enabled state. Show exact callback/logout
URLs, connection verification and actionable sanitized failures. Disabled by default;
expose the login button only when configured and enabled. Dev and production use separate
Keycloak clients and exact HTTPS redirect allowlists.

### Identity and access

Maintain a provider-neutral external identity record using exact issuer and subject,
linked to one existing local user, with database uniqueness constraints.
Email and display name are descriptive attributes, never automatic account-linking keys.
A matching email alone must not claim an existing account.

Recommended linking flow: an active internal user signs in locally, completes existing
required reauthentication/2FA, then proves the Keycloak identity in a short-lived,
session-bound linking flow. Reject conflicting links, inactive/pending/system accounts,
and users without internal access. Record link/unlink events without tokens.
Require recent local reauthentication for unlinking and preserve a working login method.
No SSO claims grant or alter roles, permissions or portal memberships.

### Login and MFA

Use server-side authorization code flow with PKCE S256, single-use state and nonce,
strict issuer/audience/signature/expiry validation and normal TLS verification.
Use a maintained OIDC library after reviewing its PHP/Laravel compatibility and validation
behavior; do not implement JWT cryptography manually. Restrict metadata/endpoints to the
configured trusted provider and reject arbitrary redirects or user-controlled endpoints.

Regenerate sessions only after all authentication requirements are met. Recheck current
local account status and internal access, rate-limit failures and prevent callback replay.
Preserve required Nexum 2FA, privileged-account onboarding, password-confirmation and
Vault step-up boundaries. Initial SSO does not assert that Keycloak MFA replaces local
2FA; accepting upstream MFA requires a separately verified assurance contract and approval.

Local password login remains available during rollout, including a tested emergency admin
account. This RFC does not introduce mandatory SSO or disable password reset.

### Sessions, logout and offboarding

Ordinary logout ends the local Nexum session. An explicit work-account logout action also
uses the provider logout endpoint and warns that other work applications may be affected.
Signed, replay-protected back-channel logout invalidates only the matching issuer/session
or subject's SSO sessions, never unrelated local or other users' sessions.
Preserve local inactive-user enforcement. Do not claim that disabling a Keycloak account
instantly revokes all Nexum sessions; document and test the provider revocation workflow,
including terminating existing Keycloak sessions and receiving logout notifications.
Bound SSO session lifetime and require reauthentication after expiry; no indefinite
remember-me persistence for SSO sessions.

## Impact Analysis

Affected: UserManagement, Core User relation, Fortify/session integration, login page,
CustomerPortal authorization regressions and Vault/UserSecurity authentication contracts.
New identity/provider/session records are additive. No business history, portal membership,
role mapping, password or service/API credential is migrated automatically.
No background synchronization, mail sending or new scheduled provisioning job is required.
SSO session invalidation must support the deployed session backend; select and verify the
indexing/invalidation design before implementation.

Active dependency: Vault Slice 04 (HR-2026-09-04-003) is In Progress and already modifies
UserManagement, Fortify actions and Core User. Do not overwrite it or introduce an
unguarded alternate login path. Before code, reconcile its current owner/state and the
authentication entry contract, then record coordinated non-overlapping delivery or an
explicit reprioritization. RFC drafting does not pause or supersede that work.

## Data And Migration Plan

Add provider, external identity and SSO session linkage tables with appropriate unique
constraints and user foreign keys. Final schema follows current session/security contracts.
Store the client secret encrypted; never log codes, tokens, passwords or secrets.
Do not backfill links from email. Existing local credentials and IDs remain intact.
Apply additive migrations on Dev only after approval and preflight. Validate duplicate/race
behavior and read back the schema. Disabling SSO restores the existing local login surface;
revoke SSO sessions and preserve audited identity links during rollback.
Production migration/activation remains a separate Svein-owned action.

## Testing Plan

- Successful linked employee login, first linking, unlink and duplicate/race rejection.
- Unknown subject, wrong issuer/audience/signature, expiry, nonce/state mismatch and replay.
- Inactive, pending, system and portal-only users denied internal SSO.
- Local login/reset/invitations/2FA and CustomerPortal isolation remain functional.
- Required 2FA and Vault step-up cannot be bypassed through an SSO callback.
- Session rotation, bounded lifetime, local logout, signed back-channel logout and replay.
- Provider outage, invalid TLS, missing configuration and secret-redaction behavior.
- Permission checks on configuration/linking plus no unexpected role or account writes.
- Run focused tests on authoritative Dev and affected UserManagement/CustomerPortal/Vault
  regressions. Verify PHP-FPM permissions and trusted HTTPS callback reachability.
- Controlled real employee pilot: linking, login, MFA, logout/revocation and emergency login.
  Automated protocol fixtures do not count as a completed real Keycloak pilot.

## Documentation Plan

Update UserManagement Knowledge, Nextcloud SSO direction and CustomerPortal identity ADR
references. Add an authentication ADR, provider setup/recovery guide, exact deploy/cache
steps and a named human-review checklist before implementation handoff.
Keep TODO and slice status synchronized. Define delivery slices after approval: identity
and configuration; linking/login/security integration; logout/recovery/live verification.
Do not activate a partial feature or publish website claims before verified completion.

## Open Questions

Recipient scope is resolved: internal employees only; Customer Portal retains current login.
Approved behavior: explicit linking of existing users,
local authorization, retained local login and existing MFA/step-up requirements.
Vault/UserSecurity compatibility is verified against both the current provider and the
existing guarded challenge contract; no Vault-owned writer was replaced. The dedicated
integration test exercises real generation/epoch capture outside the SSO transaction.
Separate Dev client configuration and the bounded real employee pilot are verified on 2026-10-06. Remaining human checks are tracked in HR-2026-10-05-SSO.

## Approval

Approved by Svein Tore in conversation on 2026-10-05: "Godkjenner".
Implementation on Dev is authorized; Main/production and named human review remain separate.

## References

- https://www.keycloak.org/securing-apps/oidc-layers
- https://openid.net/specs/openid-connect-core-1_0.html
- docs/processes/rfc-process.md
- docs/adr/2026-07-04-customer-portal-identity-separation.md
- app/Modules/Nextcloud/Docs/knowledge/04-sso-future-plan.md

## Delivery Slices

### 01 - Provider and identity foundation
Status: Done On Dev
Owner: Codex
Goal: persist one provider, exact external identities and bounded attempt/session records.
User-visible behavior: permission-protected provider settings with encrypted secret and honest discovery status.
Scope/data: additive user_sso_* and user_external_identities tables, OIDC adapter and default-off config.
Out of scope: runtime activation, provisioning and changes to existing UserSecurity writers.
Permissions: existing user.manage_2fa plus fresh local authentication.
Tests: encrypted persistence, permission denials, exact identity uniqueness and protocol validation.
Documentation: authentication ADR and operator guide.
Done: migration/rollback reviewed, settings and protocol tests pass on Dev.

### 02 - Linking and login
Status: Done On Dev
Owner: Codex
Goal: existing active internal users explicitly link and sign in.
User-visible behavior: Profile Work Account, optional login button, existing local MFA challenge.
Scope/data: one-time attempts, identity linking/unlinking and local web session grants.
Out of scope: portal SSO, implicit email linking and role provisioning.
Permissions: current internal access, local password plus confirmed local TOTP for linking/unlinking.
Tests: subject binding, races/replay, inactive/portal/system denial, 2FA and current security contracts.
Documentation: UserManagement Knowledge and CustomerPortal/Nextcloud cross-references.
Done: focused and affected login/portal regressions pass without modifying Vault-owned writers.

### 03 - Logout, recovery and verification
Status: Done On Dev - separate client and bounded real login/logout pilot verified; remaining human checks stay open
Owner: Codex
Goal: bounded sessions and reliable offboarding with tested fallback.
User-visible behavior: local logout, explicit work-account logout and revoked-session sign-in.
Scope/data: session grants, signed logout receipts, expiration and rollback documentation.
Out of scope: production deployment and automatic Keycloak user administration.
Permissions: authenticated user's session; cryptographically verified provider logout.
Tests: provider/subject/session isolation, replay, expiry, password/provider changes and failure redaction.
Documentation: HR-2026-10-05-SSO and runtime setup/recovery guide.
Done: automated checks pass; real employee pilot and named human review remain separate activation gates.

## Implementation evidence

See docs/plans/2026-10-05-internal-sso-verification.md. Human review HR-2026-10-05-SSO remains Pending.


Pilot evidence (2026-10-06): docs/plans/2026-10-06-internal-sso-pilot-verification.md. No Main/production approval is inferred.
