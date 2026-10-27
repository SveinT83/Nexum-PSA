# ADR: UserManagement API authority, command receipts and sensitive boundaries

Status: Accepted under the approved UserManagement API RFC, 2026-10-27.
Owner: Codex. Product/release owner: Svein Tore.
Parent: ../rfc/2026-10-27-usermanagement-full-api-parity.md.

## Context
Full existing-browser parity includes native account rights, embedded role-permission
operations, local credentials and session-bound OIDC. A generic bearer token is neither
local-factor proof nor a trusted UserSecurity execution context. Dev contains separate
unfinished protected-owner work that must not be promoted with the API release.

## Decision
- Keep every UserManagement registration in its owning routes.php. The existing api.php
  remains an empty compatibility loader; public capability/SSO registrations are explicitly
  loaded before the common auth:sanctum group. No new route file is introduced.
- Sanctum's standard first-party middleware accepts configured Origin/Referer session
  traffic with CSRF while retaining ordinary bearer authentication. Native active human,
  internal access and exact current user permissions are required in addition to abilities.
  Existing SSO settings keep their additional exact user.manage_2fa check. Embedded role
  assignment in both create surfaces requires current user.manage_roles authority.
- Share existing actions with web. SetRolePermission uses explicit desired membership;
  role/permission mutation preserves Spatie behavior and Email live-authority invalidation.
  Profile uploads retain old files until commit and compensate staged uploads on rollback.
- Local password, factor material and SSO mutations require the real first-party session
  and their existing local verification. Keep guarded Fortify/account-owner actions and
  native database guards. Do not synthesize requester/session/proof contexts, temporarily
  remove guards, activate protected auth or bypass a denied protected owner operation.
- Invitation acceptance locks the exact capability and pending human account. The bound
  account-security adapter is the sole protected writer on Dev. Installations that have
  not installed that independently owned adapter retain only their existing eloquent
  password/activation path, explicitly refusing an enforced cutover configuration. A bound
  adapter failure never falls back. Both web and API use this same invitation action.
- Eligible ordinary writes share an additive domain mutex and actor/token/key-unique
  command receipt. Canonical request HMAC, optional legacy/new-required keys, opaque
  If-Match versions and encrypted exact results provide atomic local effects/read-back.
  Recheck current token/native rights after acquiring the mutex, on replay and lookup.
  The mutex serializes this small administrative write domain; it is not a global app lock.
- Secret-bearing/protocol operations keep their owner-specific replay/freshness policy and
  never persist ordinary command receipts or credential-derived request fingerprints.
  API response material is no-store. Drop sensitive controller diagnostics from Telescope;
  domain-scoped exception hooks suppress debug traces/raw exception logs on API routes.
- A post-commit exception produces a retained unknown receipt and requires reconciliation;
  it must not provoke another invite or local write. Queued dispatch does not prove receipt.

## Consequences
Native-role changes can intentionally deny old tokens immediately. Narrow form/search
context remains available only through the corresponding role/permission ability. OIDC
callbacks retain the existing canonical provider redirect and exact issuer/subject,
state/nonce/PKCE and original browser binding. Existing Work Plan and Calendar preference
contracts remain owned by their existing guards/revisions. Their operational switches do
not become enabled by API availability. No destructive user-delete operation is added.

## Verification and recovery
Exact runtime/method/ability/Swagger tests accompany real bearer and session workflows,
including adverse authorization, protocol replay, schema nullability, file rollback,
post-commit unknown results and original-authority lookup. Native MySQL tests verify
receipt uniqueness/encryption/rollback and two-connection mutex exclusion. The exact
additive migration ran on Dev only; no business backfill or auth/provider activation.
Preserve APP_KEY and committed receipts. Recover by scoped code rollback/reconciliation,
not by deleting live receipt/audit tables. Main promotion is explicitly authorized;
production and human scenario checks remain separate in HR-2026-10-27-USERMANAGEMENT-API.
