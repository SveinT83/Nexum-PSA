# SSO Provider-Owned MFA: Main And Production Rollout

Date: 2026-10-22. Operator: Codex. Status: preparing scoped release.
Authorization: Svein Tore explicitly requested "Push og oppdater produktsjonen" after
62 Dev tests / 525 assertions passed. The request authorizes Main and production;
it does not report manual scenarios passed. HR-2026-10-08-SSO-MFA is In Review.
HR-2026-10-05-SSO and HR-2026-10-06-RELEASE retain their remaining open checks.
Existing Vault/UserSecurity reviews are separate; no Vault activation or policy change.

## Scope And Validation

Validated internal SSO delegates login MFA to the configured provider. The server-side
SSO grant satisfies the role gate. Local password MFA/recovery, sensitive account
operations and Vault step-up remain. The existing related SSO form guidance, menu label
and safe password/factor validation feedback are included with their regression tests.
No routes, schema, provider settings, credentials, user roles or runtime switches change.
Main candidate is assembled with a separate Git index from authoritative Dev, preserving
its normal index, HEAD and all unrelated dirty work. Only the scoped SSO delta is selected.
The immutable candidate is tested on Dev with synthetic SQLite/array configuration,
its own autoloader and existing built assets. No production credentials enter the candidate.

## Production Preflight And Procedure

Strict host-key-verified documented Plesk SSH works as the subscription user. Production
PHP is 8.3.36; SSO is enabled and provider revision 2 is available. Three identities are
linked and four local factors are confirmed. No identity or credential is changed.

Before replacement, compare every exact source preimage against Main, create protected
code/environment backup outside the webroot and record unchanged provider/identity/local
factor fingerprints without exposing them. Use short maintenance, graceful worker restart,
normal config/view cache rebuild and independent deployed hashes and trusted HTTPS checks.
No migration, package installation, frontend build or Swagger regeneration is needed.
Rollback restores only this exact code/environment delta and caches; retain all data.

## Remaining Manual Acceptance

Svein verifies fresh Keycloak MFA, SSO without duplicate local code, local password MFA
and recovery, sensitive operations and SSO revocation. Automated OIDC tests do not prove
provider MFA policy. Actual employee OpenWebUI retrieval is a separate unperformed check.
