# RFC: Workday Login Sessions

Status: Approved
Date: 2026-11-22
Owner: Codex / Svein Tore
Change level: 3 - authentication session policy

## Context

Svein Tore reports that Nexum signs users out too quickly after Keycloak login and
requests that a login session last at least one working day. This authorizes diagnosis
and a concrete proposal. Svein Tore approved the 12-hour policy and requested implementation
and Main merge on 2026-11-22.

## Goals

An employee who signs in at 07:00 can continue until 19:00, including returning after
an idle period, unless they sign out or a security/provider revocation ends access.
Keep a finite expiry, current account authorization, MFA and explicit logout effective.

## Non-Goals

Indefinite remember-me, longer access/ID-token lifetimes, refresh-token storage,
Vault step-up changes, changed permissions, shared Keycloak realm-wide policy changes,
and production deployment. Main merge is explicitly authorized.

## Current Behavior

Read-only inspection of the authoritative Dev checkout and production on 2026-11-22:

- Dev owner/path: sveintore@192.168.2.201:/var/Projects/tdPSA, branch Dev.
- Production identity verified: nexumpsa / 192.168.2.83 / VM 169, with the previously
  recorded machine identity and strict verification against the existing host key.
- Active production application symlink resolves to release 20261109-hosting-4262f1bb;
  later individual overlays exist, so this release name does not identify all installed code.
- config/sso.php sets session_minutes to 60 without an environment override.
- SsoSessions::create additionally caps grants with min(60, ...), so changing only
  configuration cannot provide a workday session.
- SsoMiddleware checks the grant on every request. Activity never extends its expiry.
- Production effective configuration: sso.enabled=true, sso.session_minutes=60,
  session.driver=database, session.lifetime=120, expire_on_close=false, config cached.
- The one currently retained production SSO grant has a 60-minute creation-to-expiry
  interval. No identity, session ID, token or personal activity was included in this evidence.
- Session cookies are Secure, HttpOnly and SameSite=Lax.
- The existing employee Knowledge article explicitly documents at most 60 minutes.
  This is a policy amendment, not an undocumented configuration repair.

The 60-minute fixed SSO expiry is a sufficient application-level explanation for forced
sign-out even during active use. Exact timings of each reported user incident have not
been traced. Keycloak's current realm/client session limits were not read from its admin
API and are not claimed verified.

## Proposed Change

1. Use 720 minutes (12 hours) as the default fixed maximum for newly issued Nexum SSO
   grants. Make the value available as SSO_SESSION_MINUTES; validate/bound it so invalid
   configuration cannot create an unlimited grant. Keep an explicit maximum of 720 minutes.
2. Raise the Laravel web-session idle allowance to 720 minutes in the target installation,
   and document SESSION_LIFETIME=720. Laravel's shared web-session setting also affects
   local password and Customer Portal sessions: they may remain idle for 12 hours.
   The fixed 12-hour maximum applies to SSO; ordinary web-session idle time remains sliding.
3. Do not extend existing grants in the database. Employees sign in again after deployment
   to receive the new allowance. The persisted SSO expiry stays fixed from issuance.
4. Keep local logout, signed back-channel logout, account disablement, security fingerprint,
   unlinking and provider-revision checks effective on the next request. Keep local
   password MFA, sensitive-action confirmation and Vault step-up unchanged.
5. Do not change Keycloak access-token lifetime as a workaround. Before claiming live
   workday acceptance, check the relevant Nexum client/provider session limits and actual
   logout behaviour. Any necessary provider change needs a documented, scoped follow-up;
   do not expand a shared realm policy as part of this RFC.

## Impact Analysis

Affected: UserManagement SSO grant issuance, installation-level Laravel web sessions,
local/password login and Customer Portal idle allowance, operating documentation and
employee Knowledge. Existing browser and typed API SSO flows share the same grant service.
No routes, permission scopes, account links, provider credentials, roles, frontend assets,
queues, scheduler or domain ownership changes are proposed.

Risk: an unattended, unlocked browser can retain access longer. Signed/logout and local
security revocations remain mandatory; this RFC does not promise access after revocation.
Do not modify ongoing Vault/UserSecurity writers or unrelated dirty Dev changes.

## Data And Migration Plan

No schema migration or grant backfill. Change only scoped Dev code/configuration after
approval; test on Dev before any authorized commit or push. Production rollout remains
Svein-owned and separately requested. Preserve the old code and environment values for rollback.
Refresh the configuration cache through the existing deployment procedure using umask 0002;
no cache flush of application data, job replay, queue start or scheduler change is needed.
Rollback must account for already-issued 12-hour grants: revoke only grants from this rollout
when a shorter policy must take effect immediately; do not assume a code rollback shortens
persisted expiry values.

## Testing Plan

- New SSO login remains valid after 61 minutes, after an 8-hour workday and just before
  12 hours; rejects at the exact fixed expiry, including after intervening activity.
- Configuration override and malformed/zero/negative/over-maximum values remain bounded.
- Real database-session storage and Set-Cookie expiry must support returning after more
  than the old 120-minute idle limit; a grant-validity unit test alone is insufficient.
- Existing logout/back-channel isolation, expiry, unlinking, disabled provider/user,
  fingerprint changes, MFA and Customer Portal authorization regressions pass on Dev.
- Verify local/password and Customer Portal idle allowance and retained MFA explicitly.
- Check trusted HTTPS web/PHP-FPM responses and deployed effective config before claiming
  live availability. No real employee session is revoked as a diagnostic test.
- Record a new human-review checklist before implementation handoff. Svein verifies fresh
  SSO login, return after a long break, local fallback and logout/revocation. Automated time
  travel tests are distinct from elapsed-time production acceptance.

## Documentation Plan

Update the existing work-account Knowledge source and internal-keycloak-sso runbook after
implementation. Publish/read back only the affected BookStack articles with existing access
scope, distinguishing Dev implementation from production availability. Employee OpenWebUI
retrieval is separate verification. Amend the session decision ADR and link the new review
entry without marking older SSO reviews complete.

## Open Questions

Resolved: Svein approved the proposal, including the shared web-session idle allowance.

## Approval

Approved by Svein Tore in conversation on 2026-11-22:
"Godkjenner forslaget om 12 timer. Implementer og merge til main".
Dev implementation, scoped commit/push and Main merge are authorized. Production deployment
is separate. Human acceptance must remain Pending until explicitly confirmed; the Main
merge request does not mark the manual checklist Reviewed.

## Production authorization and delivery - 22 November 2026

Svein Tore explicitly approved production rollout: "Godkjenner. Oppdater produksjon med
12-timersøktene også." (Norwegian approval, recorded without changing the prior decision.)
The three tested Main runtime files were installed as a scoped overlay; both effective
session policies are now 720 minutes and public HTTPS cookie Max-Age is 43200. This amends
the earlier no-production-authorization statements for this named rollout only. Remaining
manual/provider/employee-assistant checks stay In Review, not inferred Reviewed from rollout.
See ../plans/2026-11-22-workday-session-production.md for verification and rollback.
