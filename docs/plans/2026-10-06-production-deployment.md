# Production Deployment - 2026-10-06

Status: deployed; Workday active; Tripletex prepared and paused; SSO provider enabled and verified; personal account linking remains pending.
Operator: Codex, explicitly requested by Svein Tore.
Human review: HR-2026-10-06-RELEASE, In Review. Deployment is not human acceptance.

## Authorization and exact release

Svein reported PR #295 merged and asked for production deployment. After deployment he explicitly
requested: "Aktiver Workday og klargjør SSO/Tripletex". This supersedes the earlier report's statement
that agent-operated deployment was not authorized. Practical acceptance remains with Svein.

PR #295 was independently confirmed merged at 2026-10-06T13:36:57Z.
Base release: f4b0d4ac1ca4a2a9c86dbbdc78c1635bb4561057.
Previous production: d4f3342158d47807f2509bbac3be9aee05597eb9.
Deployment completed at 2026-10-06T13:51:28Z.
Runtime: /var/www/vhosts/tronderdata.no/portal.tronderdata.no.
The Plesk Git main ref and FETCH_HEAD match the deployed base release.
All tracked files matched the release before the separately documented one-file Tripletex fix below.
Unfinished Vault code/migrations remain excluded.

## Backup and deployment evidence

Protected backup directory:
 /var/www/vhosts/tronderdata.no/private/nexum-release-20261006-f4b0d4a/

- code-before.tar.gz: 20,654,080 bytes; code, dependencies and environment configuration.
- database-before.sql.gz: 131,239,615 bytes; consistent transactional dump of 383 InnoDB tables,
  including routines, triggers and events. Dump/compression exited successfully; gzip integrity passed.
- Database archive SHA-256: 9dfdbd06be561f250d464d2c8ac1ea33319386ff312faffa64a98422150ddc39.
- deployment.json, before.json, after.json and operation logs retain sanitized evidence.
- The backup is protected outside the webroot. No restore rehearsal was performed.

Production used PHP 8.3.35 and locked Composer dependencies with no development packages.
Composer install, platform requirements, npm ci and the Vite build passed.
During maintenance: worker restart, zero reserved jobs check, backups, exact release deployment,
package discovery, migrations, configuration/view cache refresh and queue restart completed.
All 17 pending migrations passed; migration count increased from 274 to 291; no Pending remains.
Canonical Contact cutover retained all 782 legacy contact IDs; unlinked records fell from 213 to zero.
Ticket (186), campaign recipient (150), campaign event (92) and Knowledge article (434) counts were
preserved. Do not reverse these data transitions using destructive down migrations.

Normal-TLS probes passed for login and health; protected pages redirected to login anonymously.
Authenticated production API read and browser dashboard, Workday and integration pages passed.
No synthetic production Workday or provider timesheet entries were created.

## Workday

WORKDAY_ENABLED=true and the guarded Workday settings form saved enabled=true.
Independent effective-settings read and the actual My workdays browser page confirm availability.
WORKDAY_RETENTION_ENABLED=false remains independent.
The current profile timezone is UTC, while its existing personal calendar is Europe/Oslo.
Svein has been asked whether to align his work plan and Tripletex mapping to Europe/Oslo.
Do not silently change calendar exceptions or claim that this pending decision is complete.

## Internal SSO

Created a separate confidential Keycloak client nexum-psa-production under the existing issuer.
Exact production callback /sso/callback, signed back-channel /sso/backchannel and logout /login.
Authorization code with PKCE S256 and RS256; implicit/direct/device/CIBA grants, service accounts,
full-scope access and optional scopes are off; only basic is attached.
The secret was transferred encrypted and stored through the encrypted provider model.
No Dev identities, sessions, passwords, roles or MFA secrets were copied.

SSO_ENABLED=true and provider enabled=true / verified / revision 2 were independently read back
after Svein saved through the password/TOTP-guarded admin form. The UI confirmed provider discovery.
His account has confirmed local TOTP. The authenticator field is not a Keycloak client secret.
Client secret remains blank in the browser to retain the encrypted prepared secret.
The guarded provider save is complete. The Profile Work Account page is open for personal linking.
Actual production linking, SSO login, local MFA/recovery and emergency-account tests remain pending.
Customer Portal retains its existing authentication.

Callback privacy is configured and independently tested at both reverse-proxy layers:
- NPMplus proxy host 96: exact callback location logs method/path/status without query/referrer.
  Dedicated log format is in /data/custom_nginx/http_top.conf; backed up under
  /data/nexum-production-20261006. API read-back and nginx syntax validation passed.
- Plesk Apache: a supported custom domainVirtualHost.php template changes only the production
  Nexum domain's CustomLog to omit query/referrer. Other domains keep the default format.
  vhost.conf and vhost_ssl.conf set no-referrer; backup under /root/nexum-sso-production-20261006.
  Plesk regeneration, Apache/nginx syntax checks and Apache reload passed.
- A synthetic final callback marker was absent from all inspected Plesk access/error logs and NPM
  general/callback logs, while the callback path remained logged. Response has no-referrer.
- Earlier synthetic probes revealed the original log issue and remain non-secret diagnostic data.
  The Plesk custom template needs comparison with the upstream default after Plesk upgrades.

Temporary encryption private keys and credential-transfer scripts were removed. No plaintext
credential was printed, saved in this repository or added to browser fields.

## Tripletex

One connection is stored encrypted and independently verified against production company 5258869.
The existing approved own-company credential was transferred with authenticated encryption.
Company verification, employee/activity/project reads were executed through the normal controller.
No Dev time rows, synchronization state or broad employee mappings were copied.
Production Svein Tore (user 1) maps to his verified Tripletex employee 1034672, activity 3695275
(Jobb - ikke definert), start date 2026-10-06. Other employees remain unmapped.
Current mapping timezone UTC awaits the question above.

TRIPLETEX_ENABLED=true and TRIPLETEX_WRITES_ENABLED=true prepare the runtime.
The write-contract evidence is the already completed own-company production-provider CRUD pilot
documented in 2026-10-05-tripletex-time-sync-verification.md, not a claim of new payroll tests here.
Connection status remains disabled. Setup initially had zero synchronization states. During the
user's concurrent Workday testing, one pending state and one saved workday appeared for today
(version 5); checked_at remains null and no sync-enable audit exists. Preserve this user-entered
data. Any subsequent mapping/timezone change must account for that established pending state.
The browser independently shows the single verified account, blank token and switch off.

## Production dependency correction

The no-dev production command initially failed: Laravel\\Telescope\\Telescope was absent.
SyncTripletexTime now checks class_exists before disabling optional Telescope recording.
The correction was implemented on authoritative Dev, not authored directly in production.
A scheduler regression verifies that paused connections send no HTTP requests and create neither
Workday nor synchronization state. TripletexTimeSyncTest passed: 12 tests / 71 assertions.
Pint and targeted whitespace checks passed.

Only app/Console/Commands/SyncTripletexTime.php was additionally deployed after checking its
production preimage against f4b0d4a. Its SHA-256 is
816604ffbaacb3737901f8a0edd5d75469e231d36ec5b0abb69b1cc34a892f4e.
Production php artisan tripletex:sync-time then exited 0 with the account still paused.
The backup and exact patch metadata are SyncTripletexTime-before.php and
tripletex-command-hotfix.json in the protected deployment directory.
The small correction is in PR #296 and must be retained in Main before any subsequent redeployment.
https://github.com/SveinT83/Nexum-PSA/pull/296

## Scheduler, existing backlog and remaining review

An actual every-minute Plesk/user cron invokes PHP 8.3 artisan schedule:run.
A separate minute cron runs queue:work database --queue=default,economy,email.
Schedule registration alone was not used as evidence of the external runner.

Before deployment, production already had approximately 186,256 queued and 168,388 failed jobs.
The existing worker command does not cover email-live, notifications or supplier-orders queues.
This backlog was not retried, purged or declared healthy. It requires separate diagnosis before
claiming reliable background email/notification/supplier-order delivery.

HR-2026-10-06-RELEASE, HR-2026-10-05-SSO, HR-2026-10-05-WORKDAY-TRIPLETEX and
HR-2026-10-01-WORKDAY remain In Review. The remaining included-feature reviews are enumerated in
2026-10-06-main-release-verification.md. No entry is marked Reviewed by an automated action.
Svein should complete production SSO/local MFA/emergency login and Workday/Tripletex workflow checks.
Tripletex stays paused until its date/timezone and intended test scope are accepted.

## Recovery

Pause SSO with SSO_ENABLED=false; pause Tripletex through its account switch and runtime flags.
Refresh config and restart relevant long-lived workers. Preserve identity, time and sync history.
Keep the compatible application/DB backup and one-file correction; do not blindly redeploy old
code over duration-only Workday data or reverse canonical Contact migrations.
Infrastructure rollback must compare current configuration with the protected backups first.

Configuration-template reference:
https://support.plesk.com/hc/en-us/articles/12389253620375-How-to-change-Apache-log-format-for-the-domains-hosted-on-Plesk

## Editorial handoff limitation

Automatic approval review rejected the combined local report-copy and website-handoff write
with "blocked by policy". That local command did not run. The authoritative Dev report and
review/TODO records are saved; the separate website handoff was not updated in this deployment turn.
