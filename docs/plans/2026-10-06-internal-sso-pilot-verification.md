# Internal Keycloak SSO - Guided Dev Pilot

Date: 2026-10-06 (Europe/Oslo)
Status: provider configured and real bounded employee pilot verified on Dev.
Human review: HR-2026-10-05-SSO, In Review; Main/production/general rollout remain blocked.
Authorization: Svein Tore asked Codex to perform the setup and offered interactive browser sign-in.
No commit, push, Main promotion or Nexum production deployment was performed.

## Verified result

- Created the separate confidential Keycloak client nexum-psa-dev in realm tronderdata.
  Exact callback: https://dev.nexumpsa.eu/sso/callback.
  Exact back-channel URL: https://dev.nexumpsa.eu/sso/backchannel.
  Exact post-logout URL: https://dev.nexumpsa.eu/login.
- Standard code flow with PKCE S256 and RS256. Implicit/direct/device/CIBA grants,
  service accounts and full-scope access are off. Only the basic client scope is attached.
  Idle timeout is 1,800 seconds (existing realm limit); maximum client lifetime is 3,600.
  The first creation request exceeded the realm idle limit and was rejected without creating
  a client. The corrected request returned 201 and the exact settings were read back.
- The generated client secret was sealed on the administration host with a temporary Dev
  RSA public key. Only ciphertext was transferred; plaintext stayed in process memory.
  The existing encrypted provider model stored a disabled/unverified preparation record.
  Temporary key and scripts were removed. An explicit operator audit records preparation.
- Svein signed in locally and saved the prepared settings with his current Nexum password.
  He confirmed this in chat. The normal guarded settings flow verified discovery and recorded
  provider_configuration_updated. The secret field stayed blank and retained the encrypted value.
- SSO_ENABLED is true on Dev for this controlled pilot, with provider enabled/verified,
  revision 2. Exactly one local account was explicitly linked through the browser.
- Ordinary local logout followed by Sign in with work account returned to the same Dev
  dashboard. Audit records show three real work_account_login events for the pilot account.
- For the back-channel test, matched the live Keycloak subject and session to the exact
  Nexum identity/session hashes. The session contained only nexum-psa-dev. Terminated only
  that session through Keycloak Admin REST, received 204 and read back its absence.
  Nexum accepted the signed notification, revoked the grant, and the browser was redirected
  to login on refresh.
- Sign out of work account in Nexum reached Keycloak's confirmation, then returned to the
  exact Nexum login page after Logout. The user subsequently signed in again with SSO.
- Final read-back: one identity, three grants (two revoked and one active), two signed
  logout receipts. Local passwords, roles and Customer Portal membership were not rewritten.

## HTTPS and logging

Apache backup:
  /root/nexum-sso-dev-20261006/nexum-integration-hub-dev.conf

Only the Dev TLS vhost was changed: callback access logs retain method/path/status without
query strings; Dev responses use a single no-referrer policy. Apache syntax check and reload
passed. Synthetic callback probes were omitted from log query strings, while callback paths
remained logged. Normal certificate verification was retained throughout. Independent access-log read-back
showed six callback paths without queries and two successful back-channel POSTs from the
active Keycloak backend (HTTP 200).

The first network probe ran on a different Keycloak installation and was not representative
of the active issuer. NPM read-back established that auth.tronderdata.no forwards to the
active backend at 192.168.2.9. Actual signed logout delivery, database revocation and browser
denial resolved the question. No DNS, NPM, firewall, VPN or public-exposure changes were made.

## Additional verification requested by Svein - 2026-10-06

- Rechecked the live employee profile and provider administration using the browser.
  The provider remains enabled and verified at revision 2, with exactly one linked identity.
- Visually inspected the work-account page on desktop and a narrow viewport, and the
  provider settings on a narrow viewport. The forms remained usable with no horizontal
  document overflow. Password fields have associated labels; Tab from the profile password
  field reached Unlink work account. Temporary viewport overrides were reset.
- The saved client-secret field is an empty password input with no value attribute.
  Provider administration still requires the current Nexum password before saving.
- Submitted one deliberately wrong password to Unlink work account. The page returned
  Access denied. Reloading the profile and an independent database read-back both
  confirmed that the intended identity remained linked.
- Anonymous trusted-HTTPS probes: login 200; portal and provider administration 302 to
  local login; empty back-channel POST 400. All four responses retain no-referrer.
- Added an isolated regression test,
  test_unlinked_emergency_admin_can_log_in_locally_during_provider_connection_failure,
  in InternalSsoTest. With SSO enabled, a simulated provider connection failure rejects
  SSO without leaking exception text; a separate unlinked Admin can still sign in locally.
  Its password and roles remain unchanged, no SSO grant or identity is created, and the
  provider stays enabled. This test passed on Dev: 1 test / 18 assertions.
- The outage is simulated through the HTTP test transport and SQLite :memory: database;
  no real Keycloak outage, emergency-admin creation or live credential change was performed.
  The pilot account still has no confirmed local TOTP. A suitable human-operated account
  is required for live TOTP/recovery and a real emergency-admin browser check.
- Broad Dev regression run passed: 221 tests / 1,517 assertions in 485.45 seconds.
  Command: HOME=/tmp php artisan test app/Modules/UserManagement/Tests/Feature
  app/Modules/UserManagement/Tests/Unit
  app/Modules/CustomerPortal/Tests/Feature/CustomerPortalFoundationTest.php --compact.
  This included the existing 24 SSO cases, local TOTP/recovery flows, guarded authentication,
  role/status enforcement and Customer Portal membership/access isolation.
- The new outage test was added after the broad run loaded its tests and was verified
  separately. Combined evidence: 222 distinct tests / 1,535 assertions, all passing.
  Current SSO coverage is 25 cases / 270 assertions across those two runs.
- Focused PHP syntax, Pint and git diff whitespace checks passed for the added test.

## Remaining human checks and limits

The pilot's local Nexum account has no confirmed local TOTP. Therefore real local-MFA and
recovery-code prompts were not exercised in this pilot. Their current and guarded
UserSecurity paths passed again in the additional verification above. Automated coverage
does not establish a real employee TOTP/recovery prompt or a human-operated emergency login.

Svein Tore explicitly confirmed on 2026-10-06 ("Bekrefter") that the intended work account
was linked and SSO login worked as expected. This is a partial human confirmation.
The remaining manual MFA, emergency-admin, customer-isolation and desktop/narrow-layout
review must be completed before general rollout or Main/production.
Existing Vault HR-2026-09-04-003 remains separate.

No application source or migration changed during this setup. Changes are the new Keycloak
client, encrypted Dev provider configuration, one user-approved identity link, Dev runtime
gate, Apache callback privacy configuration, and coordination/Knowledge documentation.
Pre-existing dependency advisories remain tracked in TODO; this setup does not remediate them.

## Operation and rollback

Dev is usable with Sign in with work account at https://dev.nexumpsa.eu/login.
Other employees must explicitly link their own existing accounts; Customer Portal retains
its current login. This is a controlled Dev pilot, not general deployment approval.

To stop SSO on Dev, set SSO_ENABLED=false and run php artisan config:clear with umask 0002.
Existing grants fail closed on their next request; local login and identity history remain.
Keep the client disabled rather than deleting it if a provider-side pause is needed.
If reverting Apache, restore only the backed-up vhost after comparing concurrent changes,
then run apache2ctl configtest and systemctl reload apache2.

Main/production remain Svein-owned: separate provider client/secret, locked dependencies,
the documented additive migration, configuration/view refresh, exact callback setup and
completed human review are still required. No queue, scheduler or frontend build is introduced.

References:
- https://www.keycloak.org/securing-apps/oidc-layers
- https://www.keycloak.org/docs-api/latest/rest-api/index.html
- docs/rfc/2026-10-05-internal-keycloak-sso.md
- docs/plans/2026-10-05-internal-sso-verification.md (historical automated implementation evidence)
