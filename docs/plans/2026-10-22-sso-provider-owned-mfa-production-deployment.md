# SSO Provider-Owned MFA: Main And Production Rollout

Date: 2026-10-22. Operator: Codex. Status: deployed and independently verified; human acceptance In Review.
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

## Final Validation And Main

Dev rerun after the portable optional-provider test guard: 62 passed / 525 assertions,
71.55s. Immutable Main candidate: 55 passed / 453 assertions and 1 explicit optional
protected-provider skip, 36.88s. That provider and its six separate guarded-entry tests
are unpublished Dev scope; none of that runtime was imported into Main. The guarded
compatibility scenario passes on Dev. The candidate includes the required existing
SsoServiceProvider feedback handler. No scoped test failure remains. PHP syntax, Pint
and whitespace passed. Dev HEAD, branch and normal Git index were verified preserved.

PR #307: https://github.com/SveinT83/Nexum-PSA/pull/307.
Application Main SHA: 0e5a6956f0779ea57f7c84b3a8ac9364e5cc2dd8.
Tested and merged tree: c0c0994369c1cafa6d7fd909dbd0886e4f3d3d47.
The normal PR merge used the repository's existing allowed actor policy; no ruleset or
branch protection was changed. Only 18 scoped files entered Main; production retains its
larger pre-existing TODO/human-review documents byte-for-byte and receives the other 16.

## Backup, Execution And Independent Read-Back

Deployment completed 2026-10-22 09:56:16 UTC / 11:56 Europe/Oslo.
Protected backup: /var/www/vhosts/tronderdata.no/private/sso-mfa-release-20261022-0e5a695/.
Code/environment backup: 31,062 bytes; SHA-256
f78eed46048d6c664681d1f9851dfaded90bb0c70bad3b153f0b8d66b7474a78.
Fresh transaction-consistent database backup: 197,954,717 bytes; SHA-256
c140c523349c92619966c2980c65c26e3b4b6698de7ddcdf28cfa576eb37b32d.
Gzip integrity and file hashes pass; temporary database option credentials were removed.
No restore rehearsal is claimed. Backup/data content remains outside the webroot.

Maintenance and graceful queue restart waited for the active reserved job to finish.
All 16 exact Main paths were installed with readable permissions and subscription ownership.
Config/view caches, route clearing and graceful worker restart passed. No queue retry,
purge or unrelated data/provider action occurred; no migration/build/package install.
Maintenance was lifted. No rollback was needed. APP_COMMIT_SHA records the application SHA.

A separate application bootstrap and file comparison independently verified all 16 hashes,
readable modes, SSO enabled/provider revision 2, maintenance off and unchanged fingerprints
for local passwords/factors, provider settings and external identities. Existing Mail flags
remain false. No real SSO login or upstream MFA-policy acceptance is inferred from this.
Trusted HTTPS /up, /login, /api/documentation and Swagger return 200; anonymous Mail context
returns 401. A separate desktop HTTP check confirms /up and /login 200 and the SSO login
option present. No production test suite used the live database or employee credentials.

Protected evidence includes before.json, after.json, backup-evidence.json, deployment.json,
independent-verification.json and Artisan logs in the backup directory. The package and
runner remain in the protected private directory. Rollback restores this exact code/env
snapshot and normal caches, retaining all current database/audit/provider state.

## Documentation And Handbook

Authoritative Knowledge source and SSO/TODO/human-review status were reconciled first.
BookStack employee and administrator guides 612/613 revision 3 were published and
independently read back exactly, with production status and manual acceptance In Review.
Page inheritance remains on with no role overrides; existing chapter/book inheritance
and staff Editor view permissions remain unchanged. Employee OpenWebUI retrieval is not
claimed. The existing public website item was updated (1 changed, 0 added, 32 retained),
keeping the human/editorial publication hold. No public website publication was requested.
