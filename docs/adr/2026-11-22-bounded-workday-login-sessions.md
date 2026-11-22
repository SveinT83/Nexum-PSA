# ADR: Bounded Workday Login Sessions

Status: Accepted
Date: 2026-11-22
Decision makers: Svein Tore (approved RFC); Codex (implementation)
Parent: docs/rfc/2026-11-22-workday-login-sessions.md

## Context

The original fixed 60-minute SSO grant and 120-minute web idle allowance interrupt a workday.
Svein approved 12-hour sessions and explicitly requested implementation and Main merge.

## Decision

Retain the existing grant and revocation architecture. New SSO grants default to 720 minutes,
accept SSO_SESSION_MINUTES integer overrides, and always remain between 1 and 720 minutes.
Malformed/overflow values fail closed to one minute. Grant expiry is persisted once and never
renewed by activity. The shared Laravel web idle allowance defaults to 720 minutes and requires
an explicit matching SESSION_LIFETIME change where an installation retains a shorter override.
Local/password and Customer Portal sessions share that sliding idle allowance.

No remember-me, token retention, refresh worker, role or Vault-step-up changes. Existing logout,
fingerprint, provider revision, unlink and status checks remain authoritative. Keycloak session
policy is independently owned; do not lengthen access tokens or shared realm settings.

## Consequences

Idle unlocked browsers retain access longer. Existing grants are not extended. A code rollback
does not shorten already persisted 12-hour grants; any immediate rollback must explicitly target
the rollout's grant cohort. Production rollout and real employee acceptance are separate from
Dev tests and Main merge. HR-2026-11-22-WORKDAY-SESSIONS remains Pending.
