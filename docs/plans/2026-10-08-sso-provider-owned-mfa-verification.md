# SSO provider-owned MFA verification - 2026-10-08

Status: Done On Dev / Human Review Pending
Owner: Codex. Reviewer: Svein Tore (not yet reviewed).
Authority: explicit chat request; RFC/ADR 2026-10-08-sso-provider-owned-mfa.
Working copy: authoritative Dev branch; existing unrelated changes preserved.

## Result
A validated internal SSO callback signs in without local TOTP. A valid server-side
SSO grant satisfies the role enrollment gate. Password sign-in retains local TOTP
and recovery, and local enrollment remains required when mandated by the role.
Local credentials, roles, permissions and identity links are not changed by login.
Sensitive local account operations and Vault step-up receive no exemption.

## Files changed in this amendment
- app/Modules/UserManagement/Sso/SsoFlow.php: remove only the duplicate local challenge.
- app/Http/Middleware/RequireTwoFactor.php: recognize a currently valid SSO grant.
- app/Modules/UserManagement/Tests/Feature/InternalSsoTest.php: new regression and updated
  login, recovery, legacy pending-expiry and protected-provider expectations.
- app/Modules/UserManagement/Views/profile/sso.blade.php: explain the new login policy.
- app/Modules/UserManagement/Docs/knowledge/work-account-sign-in.md: operating guidance.
- docs/rfc/2026-10-08-sso-provider-owned-mfa.md and docs/adr/2026-10-08-sso-provider-owned-mfa.md.
- Original 2026-10-05 SSO RFC/ADR: preserve history and link the policy amendment.
- docs/rfc/README.md, docs/adr/README.md, docs/TODO.md, docs/human-review.md and this report.
Earlier menu/field-label changes from this chat remain in the working copy.

## Automated evidence
Before implementation, two new tests failed for the intended reasons: a confirmed
local factor caused a redirect to the local challenge, and a valid SSO user without
local enrollment was redirected to security settings.

Final command (Dev, umask 0002), HOME=/tmp php artisan test with:
- app/Modules/UserManagement/Tests/Feature/InternalSsoTest.php
- app/Modules/UserManagement/Tests/Feature/TwoFactorAuthenticationTest.php
- app/Modules/UserManagement/Tests/Feature/GuardedTwoFactorEntryTest.php
- app/Modules/CustomerPortal/Tests/Feature/CustomerPortalFoundationTest.php

Result: 62 passed / 525 assertions, 72.71 seconds.
Scoped Pint passed for the two PHP runtime files and SSO feature test.
Scoped git diff --check passed.

Coverage includes the same linked account choosing SSO versus local password, unchanged
persisted credential material, local recovery-code use, required enrollment after local
login, nonexistent/foreign/revoked/expired SSO grants, request-flag denial, provider/password/
security-epoch revocation, issuer/signature/nonce/replay rejection, local reauthentication,
listener failure cleanup, guarded UserSecurity provider and Customer Portal isolation.

One intermediate assertion compared distinct Carbon objects by identity; it was corrected
to compare hashes of persisted credential values. No failing test remains.

## Deployment and limitations
No migration, backfill, build, queue, scheduler or provider-setting action.
Normal PHP/view deployment; clear compiled views if cached. Preserve existing local 2FA.
No commit, push, Main promotion or production deployment was performed.
No real provider MFA enforcement or interactive browser acceptance was verified in this
amendment. Nexum trusts the configured provider's login and does not assert amr/acr MFA proof.
HR-2026-10-08-SSO-MFA is Pending and blocks Main/production promotion until Svein verifies
provider MFA, SSO without the extra prompt, local fallback, and revocation.
HR-2026-10-05-SSO remains In Review for its remaining original checks.

## Revalidation - 2026-10-22

The repeated request was reconciled against the approved RFC, current Dev code and
existing open human-review entry. The requested behavior is already present in the
authoritative Dev working copy; no additional runtime edit was necessary.
The same four-file test command passed again: 62 tests / 525 assertions, 73.15s.
Scoped Pint passed for RequireTwoFactor.php, SsoFlow.php and InternalSsoTest.php;
scoped git diff --check passed.

The checked-out origin/main reference still contains the original local challenge
branch. This is a repository-ref observation, not a live production-code inspection.
Main/production rollout and actual provider MFA/browser acceptance remain separate.
HR-2026-10-08-SSO-MFA stays Pending and continues to block promotion/deployment.
BookStack pages 612/613 revision 2 were published and independently read back
with exact expected content and explicit Dev-only status. Page/chapter inheritance
and book overrides were checked; the Editor role bound to /staff-internal retains
book/chapter/page view-all. No permissions changed. Actual employee OpenWebUI
retrieval remains a separate unperformed check.
The existing public website handoff item was refreshed with the new verification
date; 0 items added, 1 updated, 32 items retained. Its do-not-publish gates remain.
Changed in this revalidation: the work-account Knowledge source, docs/TODO.md,
docs/human-review.md, this evidence file, the two handbook pages and that existing
website handoff item. No migration, build, worker or scheduler action is needed;
the original normal PHP/view deployment instructions remain applicable.

## Explicit production request - 2026-10-22

Svein Tore explicitly requested push and production update. The scoped rollout is
authorized and the remaining human checks move to production; historical rollout-hold
statements above are superseded for this requested deployment. Review stays In Review.
See 2026-10-22-sso-provider-owned-mfa-production-deployment.md for actual rollout evidence.
