# Internal Keycloak SSO - Dev Verification

Date: 2026-10-05
Historical implementation evidence. Current configured/pilot state: docs/plans/2026-10-06-internal-sso-pilot-verification.md.
RFC: Approved by Svein Tore in conversation ("Godkjenner").
Authoritative implementation: /var/Projects/tdPSA, Dev working copy.
Status: code implemented and automated checks passing; real-provider setup/pilot pending.
Human review: HR-2026-10-05-SSO, Pending.
No commit, push, Main promotion or production deployment performed.

## Implemented behavior

Internal-only OIDC sign-in, explicit account linking/unlinking with local password and
confirmed Nexum TOTP, unchanged role/status authority, normal and guarded UserSecurity
two-factor entry, exact issuer/subject uniqueness, bounded server-side session grants,
signed back-channel logout with replay checks, explicit provider logout and local fallback.
Provider configuration is permission-protected, encrypted, discovery-verified and default-off.
Canonical HTTPS redirect URLs are pinned to APP_URL; request Host headers cannot select them.
No access, refresh or ID tokens are persisted. Login failures cannot leave partial sessions.
SSO never provides a Vault step-up proof or modifies password/remember-token writers.

## Tests and checks

- Final dedicated InternalSsoTest: 24 tests / 252 assertions passing on Dev.
- Combined earlier SSO, existing TwoFactorAuthentication, profile security boundary and
  CustomerPortalFoundation run: 47 tests / 370 assertions passed.
- Broad UserManagement feature/unit plus CustomerPortalFoundation run:
  216 passed / 2 failed, 1,477 assertions. Both failures were resolved and rerun:
  one new test's HTTP fake precedence; one existing minimal profile-security fixture
  accidentally enabled Workday Calendar projection without its schema.
  The latter now explicitly disables Workday only in that isolated security fixture.
  UserWorkPlanTest still covers the complete projection. No production Calendar behavior changed.
- Actual SSO-to-enforced-UserSecurity test passes with real challenge generation/epoch capture
  and real fenced TOTP verification on an isolated SQLite schema. The fixture releases its
  outer transaction so the production fence owns its required non-nested transaction.
  This test exposed and fixed an initial nested-transaction integration error.
- Protocol coverage uses signed RSA fixtures, not an actual employee Keycloak login.
- Focused Pint and git diff --check pass.
- Blade view cache builds successfully with umask 0002.
- Live trusted HTTPS: /login = 200; disabled /sso/login = 302;
  malformed /sso/backchannel POST = 400 without CSRF cookie. TLS validation retained.

## Dev database read-back

Migration 2026_10_05_170000_create_internal_sso_tables applied in batch 17.
Preflight verified all five names absent and SSO disabled; receipt retained at
/tmp/nexum-sso-migration-preflight.json. No existing tables or user values were changed.
Initial native migration exposed MariaDB multiple-TIMESTAMP default behavior. Replaced
non-null expiry/creation fields with DATETIME. Verified all partial SSO tables were empty
and unrecorded before dropping only those new tables, then reapplied successfully.

Read-back: user_sso_providers, user_external_identities, user_sso_attempts,
user_sso_sessions and user_sso_logout_receipts all exist with zero rows.
Independent schema inspection confirms unique identity_key and user_id indexes.
SSO_ENABLED remains false. No provider secret or real identity link is configured.

## Changed surfaces

New: config/sso.php; Sso services; SsoServiceProvider; SsoController and SsoSettingsController;
profile/Admin SSO views; migration; InternalSsoTest; Knowledge article; runbook and ADR.
Additive hooks: bootstrap/providers.php, UserManagement/routes.php, route permission map,
login view, profile/admin navigation and .env.example. Composer adds the OIDC library
and two dependencies without updating existing package versions in this operation.
Documentation: RFC/TODO/human-review, CustomerPortal identity ADR and Nextcloud SSO direction.
One existing profile-security test fixture received an isolated Workday configuration fix.
All other contributors' implementation remains preserved.

## Remaining gates

1. Register or verify a separate Dev Keycloak confidential client; configure exact URLs,
   RS256, S256, standard flow and back-channel logout. Do not reuse a production client.
2. Save its secret directly through the protected Nexum settings form. No secrets in chat.
3. Prepare proxy query-string redaction for /sso/callback and verify client/MFA policies.
4. Enable only the controlled Dev pilot after setup, then perform real linking, local MFA,
   session termination/logout, emergency local login and visual checks with Svein Tore.
   The approved RFC covers a controlled Dev pilot; named human review is still required
   before Main, production deployment/activation or general rollout.
5. Complete HR-2026-10-05-SSO explicitly. Existing Vault HR-2026-09-04-003 remains separate.

GitHub issue/discussion reconciliation found no directly matching internal-SSO implementation
item. Discussion #165 describes future customer SSO; the approved employee-only scope does
not change that customer-provider plan. No GitHub item/comment was created.

## Dependency follow-up

Composer audit reports pre-existing league/commonmark advisories GHSA-97jj-33gv-5xf9 and
GHSA-3q6v-r5mr-hxv8. They are recorded in TODO for separate dependency remediation.
No advisory was reported for the three added OIDC dependency packages.

## Deployment and rollback

Outside Dev, Svein controls promotion. Install locked dependencies, back up appropriately,
apply the specific additive migration and refresh configuration/views. No queue or frontend
build is introduced. Keep operational activation off until setup and review.
Ordinary rollback is SSO_ENABLED=false plus configuration refresh; retain identities/audits
and use local login. Dropping the schema is not routine recovery.


## Guided Dev setup - 2026-10-06 (preparation history; pilot now verified)

Svein Tore requested that Codex perform client setup and guide the real browser pilot.
He signed in locally to the protected Dev settings page; provider activation still requires
his fresh local password through the normal form. This is not completed human review.

- Created only Keycloak client nexum-psa-dev in the existing tronderdata realm, with exact
  Dev callback/logout addresses, confidential authentication, standard flow, S256 and RS256.
  Direct grants, implicit flow, service accounts and full scope are disabled. Only the basic
  client scope is attached. Client idle lifetime respects the realm's existing 1,800 seconds;
  client maximum lifetime is 3,600 seconds. No realm policy or other client was changed.
- Prepared a disabled, unverified Nexum provider through the approved operator channel.
  Its generated secret was sealed on Keycloak with a temporary Dev RSA public key,
  transferred as ciphertext, decrypted only in process memory, and stored through the
  existing encrypted model cast. Temporary transport key and preparation script were removed.
  A system audit entry records preparation; no user identity was impersonated.
  Normal admin password verification, discovery and Save remain required before activation.
- Backed up the Dev Apache vhost to
  /root/nexum-sso-dev-20261006/nexum-integration-hub-dev.conf, added query-free callback access
  logging, and forced no-referrer on Dev responses. Apache configtest and reload passed.
  Synthetic callback probes were absent from access-log query strings; callback paths remain
  logged. A trusted HTTPS read-back confirms a single Referrer-Policy: no-referrer header.
- No Main/production changes, account linking, role changes or human-review completion.

Current outcome: client configured, real bounded pilot verified and runtime on for Dev. See the 2026-10-06 pilot report; the earlier off/unlinked/pending statements above describe their recorded pre-pilot state.
