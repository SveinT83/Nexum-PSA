# ADR: Separate Privileged Invitation Acceptance From Activation

Status: Accepted
Date: 2026-09-18
Decision Makers: Svein Tore / Codex
Related RFC: #279; Vault domain and operational credential platform
Related Slice: 04; HR-2026-09-04-003 remains Pending

## Context

An actorless invitation can establish a password but cannot carry a current administrator's
Vault proof or approval. Activating a pending human with the protected web Admin/Superuser role
adds a candidate to the authority roster. Pending privileged invitations may survive from dormant
to enforced operation. Svein approved separating these actions in chat on 2026-09-18.

## Decision

With authority enforcement active, privileged invitation acceptance consumes the invitation and
establishes the password but preserves PENDING_INVITE and assigned roles. Only the authentication
epoch advances. Do not log in the recipient; explain that administrator activation is pending.
Ordinary invitations and pre-cutover legacy behavior remain unchanged.

An existing administrator subsequently activates the account through the guarded account-status
operation with current exact plan, step-up, required independent approval or explicit sole-admin
exception, and audit. An invitation receipt is not authority approval. No direct-update shortcut
or temporary privileged session is permitted. This supplements the accepted authorization ADR.

## Rationale

Token possession proves neither current administrator authority nor approval to expand the roster.
Separating the actions retains normal onboarding and the existing two-person/sole-admin rules.

## Consequences

Privileged recipients wait for administrator activation after password setup. Recheck phase and
exact persisted roles in the owned transaction; deny stale branch decisions, token replay and
partial updates. Email verification is not fabricated by the password-only subject; a later
verification write must use its authoritative account flow. No new status is required.
Runtime stays dormant until the complete authority coordinator and source guards are verified.

## Alternatives Considered

Direct privileged activation, fabricated recipient proofs and silent removal of assigned roles
were rejected because each changes authority without the required current reviewed operation.

## Follow-Up

Test protected/ordinary/dormant acceptance, role and phase races, rollback, replay, no privileged
login, direct guarded bypass denial and the later administrator activation. Keep implementation
status in TODO and the delivery handoff, not in the Accepted status of this decision.
