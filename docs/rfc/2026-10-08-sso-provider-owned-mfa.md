# RFC: Provider-owned MFA for internal SSO sign-in

Status: Approved
Date: 2026-10-08
Owner: Codex / Svein Tore
Change level: 3 - Authentication policy
Implementation: Dev Verified / Scoped Main and Production Rollout Authorized 2026-10-22

## Context
Internal SSO currently challenges for local Nexum 2FA after the configured provider
has authenticated the employee. Svein reports that the provider already uses MFA and
requests removing the duplicate local challenge. This amends the login/MFA policy in
the approved 2026-10-05 internal SSO RFC, not its identity or authorization boundaries.

## Goals
A successful validated SSO callback signs in an existing linked internal user without
a Nexum TOTP prompt. A current valid SSO session satisfies the tech role MFA gate.
Local password login keeps its existing local 2FA challenge and enrollment requirements.

## Non-Goals
No local-factor deletion, mandatory SSO, automatic linking, role provisioning, provider
configuration change, Customer Portal SSO, credential reset, or Vault step-up change.
Linking/unlinking and sensitive provider changes retain fresh local reauthentication.

## Current Behavior
SsoFlow stores a pending grant and sends confirmed-factor users through Fortify's local
challenge. RequireTwoFactor additionally requires local enrollment for configured roles.

## Proposed Change
Remove only the local challenge branch from the validated SSO callback. Complete login
through the existing Login event and server-side grant validation. RequireTwoFactor may
accept an existing SSO grant only after SsoSessions validates the current user, provider,
identity, expiry, revocation and security fingerprint. A linked account or a request flag
alone never qualifies. Do not touch Fortify's local password authentication pipeline.
Retain old pending-challenge validation for sessions already in flight during an upgrade.

MFA is the configured provider's responsibility for this login method. Nexum validates
the OIDC login, not a new amr/acr assurance claim. Provider MFA enforcement is therefore
a required operator check before production promotion; do not claim token-proven MFA.
Keep local factors enrolled for password fallback and sensitive local actions.

## Impact Analysis
UserManagement SSO callback/tests/help, shared RequireTwoFactor middleware and its
local-login regression coverage. Existing SSO session lifetime, revocation, account
status/roles, protected UserSecurity provider and Vault proof requirements remain.
No routes, permissions, database structures, external calls or queue behavior change.

## Data And Migration Plan
No migration or backfill; preserve all credentials, identities and provider settings.
Normal PHP/view deployment only; clear compiled views if cached. No build, worker,
scheduler or config change. Rollback the scoped policy change to restore the extra prompt.

## Testing Plan
Prove the new callback and required-role behavior fail before the patch and pass after.
Cover confirmed local factors, no local enrollment, preserved password/TOTP/recovery,
invalid/revoked/expired/other-user grants, account fingerprint changes, upstream errors,
listener failure, protected provider compatibility and portal regressions.
Manual review: HR-2026-10-08-SSO-MFA, including actual provider MFA enforcement and
SSO/local login with the same account. Automated OIDC fixtures are not a live MFA check.

## Documentation Plan
Update the original RFC/ADR with explicit supersession links, Knowledge and UI guidance,
TODO and human review. Add public-safe editorial handoff only after Dev verification.

## Open Questions
None for the authorized Dev implementation. Actual provider MFA enforcement and real
browser acceptance remain checks before Main/production promotion.

## Approval
Svein Tore approved this behavior in chat on 2026-10-08:
"Ja fix det slik at når vi logger inn med SSO så kreves det ikke lokal 2MFA".
This authorizes the described Dev implementation. Main/production and human review
remain separate actions. No production change is included.

## Verification evidence

See [Dev verification](../plans/2026-10-08-sso-provider-owned-mfa-verification.md):
62 tests / 525 assertions pass. Main/production promotion remains blocked by the pending
human-review checklist; no live provider MFA or new browser sign-in was claimed.

## Explicit rollout authorization - 2026-10-22

Svein Tore requested "Push og oppdater produktsjonen" after the verified Dev handoff.
This authorizes the scoped Main promotion and production rollout. Earlier rollout-hold
wording is historical; remaining provider/browser checks move to production under
HR-2026-10-08-SSO-MFA (In Review). No manual check is marked Reviewed by this request.
