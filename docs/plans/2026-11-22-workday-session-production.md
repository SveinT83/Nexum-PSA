# Production workday-session rollout - 2026-11-22

Status: Installed / Runtime And Public HTTPS Verified / Human Review In Review.
Owner: Codex. Authorization: Svein Tore explicitly approved production update with the
12-hour sessions on 2026-11-22, after Main PR408 and the scoped Dev/Main tests.
Human-review checklist: HR-2026-11-22-WORKDAY-SESSIONS. Remaining SSO reviews stay open.

## Exact deployment

Main source: 7301a74a65e4f5c319b363b928291c76d0696b49 (PR408).
Target: verified Nexum VM169 / nexumpsa / 192.168.2.83, machine identity unchanged.
Application: /var/www/nexumpsa/app -> /var/www/nexumpsa/releases/20261109-hosting-4262f1bb.
The symlink/release label was retained; this is a three-file overlay, not a whole-release swap.
Existing customer-registration, private-customer and other overlays were not replaced.

Changed runtime files:
- config/sso.php
- config/session.php
- app/Modules/UserManagement/Sso/SsoSessions.php

All old hashes exactly matched Main's pre-change files; all installed hashes exactly match
the approved Main versions. PHP8.3 lint passed before installation. Existing owner/group/modes
were retained. SESSION_LIFETIME changed120->720 and SSO_SESSION_MINUTES=720 added; all other
environment values were preserved. Config:cache succeeded and php8.3-fpm gracefully reloaded.
No schema, dependency, asset, queue/scheduler, account, permission, client credential or shared
Keycloak realm/client configuration was changed. No real employee grant was extended/revoked.

## Independent verification

- Effective production configuration bootstrapped as actual FPM user www-data: cached config,
  database sessions, SSO enabled, session.lifetime720 and sso.session_minutes720.
- Expire_on_close=false; Secure=true, HttpOnly=true, SameSite=Lax; provider revision2 unchanged.
- The production grant service issued a valid720-minute grant inside a database transaction;
  the transaction was fully rolled back and the specific probe grant was confirmed absent.
  Its expiry pruning was also rolled back. No real login, token or test grant was retained.
- Trusted public HTTPS /login and /up returned200. Actual session cookie Max-Age43200,
  Secure/HttpOnly/SameSite=Lax. Cookie values and credential material were never printed.
- Existing grants retain their prior expiry; staff must sign out/in for a new allowance.
- Prior code evidence: Dev101/1690 PASS; exact Main PHP8.3 100/1673 PASS and one pre-existing
  optional protected-provider test skipped (passes on Dev). No destructive suite ran in production.

## Rollback

/root/nexum-workday-sessions-20261122T204551Z is root-private0700 and contains old source, .env and config
cache copies, metadata and an executable rollback.py. No backup secret was downloaded or
committed. The script restores original ownership/modes and gracefully reloads PHP-FPM.
Code/config rollback does not shorten already-issued12-hour grants. If immediate shortening
is required, review and target only the rollout's grant cohort; do not bulk-flush staff sessions.
Backup restoration itself was prepared and inspected, not rehearsed on production.

## Remaining human/provider acceptance

Svein's approval authorizes this installation; it does not prove elapsed-time sign-in or all
older SSO/MFA checks. Confirm fresh SSO, returning after a long pause/workday, local/portal
fallback, ordinary/signed provider logout and the relevant provider-client session limits.
The production client identity nexum-psa-production was read back, but current Keycloak admin
session limits were not read through a configured administration connection. No shared realm
or access-token lifetime was changed. Do not describe a complete real workday as observed.
Employee OpenWebUI retrieval remains separate. HR-2026-11-22-WORKDAY-SESSIONS stays In Review.

## Documentation publication

Authoritative Knowledge/source and operating docs were updated after runtime read-back.
BookStack612 revision6 and613 revision5 were published and independently read back with exact
normalized Markdown, retained owners/parents/tags and unchanged page/chapter inheritance.
Native ACL reads returned200: pages612/613 and chapter611 inherit without role overrides;
book339 retains its two prior role overrides and inheritance. No access was expanded.
The installed production Knowledge source was patched only for the session paragraph and new
workday section; its prior body was preserved and included in the private rollback manifest.
Source and installed body both explain production availability and fresh sign-in requirements.

Runtime/source installation is complete. The final living docs are published to Dev after scoped
whitespace/UTF-8 review. This is documentation-only publication; the tested Main code and actual
production runtime remain unchanged by the record commit. No additional Laravel suite is needed
for those record edits; the deployment read-backs above and prior code matrix are the evidence.
