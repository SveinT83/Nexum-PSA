# ADR: Provider-owned MFA for SSO, local MFA for password login

Status: Accepted
Date: 2026-10-08
Decision Makers: Svein Tore (explicit behavior approval), Codex (implementation)
Supersedes: local SSO challenge/enrollment policy only in 2026-10-05-internal-sso-authentication-boundary.md

## Context
The configured internal identity provider handles MFA. A second local Nexum challenge
duplicates that login step. Local password fallback must keep its current protection.

## Decision
Trust the validated configured-provider login for SSO authentication. Skip local login
TOTP after its callback and accept only a currently valid server-side SSO grant at the
role MFA enrollment gate. Reuse existing user/identity/provider/fingerprint/revocation
validation; a linked identity or unvalidated session marker is insufficient.
Keep local credentials, password login MFA, sensitive-action reauthentication and
Vault step-up unchanged. SSO neither records local factor success nor grants Vault proof.
Retain enforcement of old in-flight pending SSO challenges during upgrades.

## Rationale
The authentication method determines which MFA system handles login. Reusing the
revocable SSO grant avoids a second flag with a weaker or inconsistent lifetime.

## Consequences
Provider administrators must enforce MFA for the Nexum client/users before promotion.
Nexum does not infer MFA from arbitrary amr/acr values or assert that it verified an
upstream second factor. A weakened provider policy weakens SSO; local factors do not
compensate for that. Keep local 2FA for fallback and sensitive account changes.
No credentials, database migrations or provider settings change.

## Alternatives Considered
Disabling local 2FA also weakens local password login and is rejected.
A generic session boolean or identity-link check would outlive actual SSO validity.
Per-provider assurance-claim mapping is outside this explicitly approved policy.

## Follow-Up
HR-2026-10-08-SSO-MFA must verify provider enforcement and real SSO/password behavior
before Main/production promotion. Preserve the remaining original SSO human checks.

## Production rollout - 2026-10-22

Svein Tore explicitly requested Main and production rollout, superseding the earlier
deployment hold while preserving all unchecked human acceptance. Main PR #307 is
deployed and independently verified; HR-2026-10-08-SSO-MFA stays In Review. Provider MFA
policy and actual user flows remain manual checks; no claim-proven upstream MFA is asserted.
