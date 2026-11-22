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

## Workday session policy - approved 22 November 2026

RFC: docs/rfc/2026-11-22-workday-login-sessions.md.
Human-review checklist: HR-2026-11-22-WORKDAY-SESSIONS (In Review).
Dev implementation, Main merge and the named production rollout were explicitly authorized.

For a newly installed workday-session build, set SSO_SESSION_MINUTES=720 and
SESSION_LIFETIME=720 in the target installation. The first is a fixed SSO grant maximum;
the latter is a shared sliding idle allowance for SSO, local and Customer Portal web sessions.
Retained environment overrides take precedence over new code defaults. Refresh config with
umask 0002 and php artisan config:cache through the existing deployment procedure. No schema,
frontend build, queue, scheduler or Keycloak token change is required. Never run session/cache
clears or revoke live employee sessions as an installation smoke test.

Read back only session.driver/lifetime/expire_on_close, sso.session_minutes and cookie flags.
Verify trusted HTTPS Set-Cookie expiry (12 hours), Secure/HttpOnly/SameSite and actual FPM
responses. New SSO grants must last 720 minutes; existing grants retain their original expiry.
Have the employee sign in again after rollout. Activity must never extend the fixed grant.
Check provider-specific session limits and genuine logout/revocation separately before
claiming a full real workday pilot. Do not expand shared Keycloak realm settings.

Preserve old code/configuration for rollback. Reverting source/config affects future grants;
previously issued 12-hour grants remain valid until expiry unless explicitly revoked. Record
rollout time and grant cohort before a rollback requiring immediate shorter policy. Keep local
fallback working and do not bulk-delete identity links or ordinary web sessions.

## Installed production workday policy - 22 November 2026

Svein Tore explicitly authorized the scoped production rollout after Main PR408. The three
runtime files from Main7301a74a are installed; retained SESSION_LIFETIME was changed to720,
SSO_SESSION_MINUTES=720 added, configuration rebuilt and PHP-FPM gracefully reloaded.
Actual public HTTPS session cookies use Max-Age43200 with Secure/HttpOnly/SameSite=Lax;
FPM-user config and a fully rolled-back grant probe independently confirm720/720 minutes.
Existing grants retain old expiry: ask employees to sign out and use Sign in with work account
again. No session flush, migration, queue/scheduler or Keycloak configuration change occurred.
See docs/plans/2026-11-22-workday-session-production.md for the private rollback location and
procedure. HR-2026-11-22-WORKDAY-SESSIONS is In Review; provider-client limits, employee
long-idle/workday experience and assistant retrieval are not proved by runtime installation.
