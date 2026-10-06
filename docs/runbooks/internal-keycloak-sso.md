# Internal Keycloak SSO Operations

Parent: docs/rfc/2026-10-05-internal-keycloak-sso.md
Review gate: HR-2026-10-05-SSO (In Review; blocks Main, production and general activation).

## Prerequisites
- Trusted HTTPS Dev URL, matching APP_URL and trusted proxy configuration.
- Separate Dev confidential Keycloak client, exact callback URLs, RS256, S256 and back-channel logout.
- Local emergency administrator and an existing employee with local MFA as required.
- Named reviewer for linking/login, customer isolation, logout and provider-outage recovery.
- Review existing Vault HR-2026-09-04-003 before any security cutover; SSO does not activate Vault.

## Deploy on the chosen environment
1. Install locked dependencies with composer install.
2. Record the absent/empty SSO baseline on Dev; take an appropriate environment backup before
   production rollout. Run only the additive migration
   2026_10_05_170000_create_internal_sso_tables.php on Dev after preflight.
3. Keep SSO_ENABLED=false until the configured, approved controlled pilot. Configure the provider through its admin form.
4. Refresh configuration/views using the project's existing deployment procedure and umask 0002.
5. Verify callback HTTPS and the actual web/PHP-FPM path. CLI tests do not establish browser success.
6. Enable the operational gate only for the approved pilot and perform every review check.

No queue worker, scheduler job, npm build, password reset or role migration is introduced.
Temporary expired attempts, grants and logout receipts are pruned as their corresponding flows
run. An inactive installation retains expired records harmlessly; ordinary database retention
may archive them after their expiry without deleting identity links or audit history.

## Failure and rollback
Set SSO_ENABLED=false and refresh configuration. Every existing SSO grant then fails closed
on its next request while local password login remains. Preserve encrypted provider settings,
identity links and audit history. Avoid schema rollback as operational recovery.

Log only sanitized failure class/event names. Disable request tracing for SSO/SSO-factor requests.
Configure the reverse proxy not to log query strings for /sso/callback. Do not paste client
secrets, authorization codes, ID tokens or private keys into tickets, shell history or chat.

A Keycloak account disable is not sufficient evidence of logout: terminate existing sessions,
verify matching provider logout and Nexum denial, and disable the local user when required.

Dev pilot was configured and verified on 2026-10-06; see docs/plans/2026-10-06-internal-sso-pilot-verification.md for current state and rollback.
