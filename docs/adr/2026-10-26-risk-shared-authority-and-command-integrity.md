# ADR: Shared Risk authority and command integrity

Status: Accepted
Date: 2026-10-26. Decision owner: Svein Tore (approved full Risk RFC).
Implementation: Codex. Parent: ../rfc/2026-10-26-risk-full-api-parity.md.

## Context
Approval, history mutation, owner authorization and deletion require one current
decision across browser and API. Token scopes alone cannot grant missing rights.

## Decision
Risk routes remain domain-owned. RiskPolicy refreshes active actors and applies
current browser permissions plus creator/Superuser rules. Shared actions use a
Risk-owned database mutex before parent/child work. API commands use the same
mutex and atomically commit local effects and a minimal encrypted receipt.
The exact additive migration is 2026_10_26_010000_create_risk_api_commands.php.

Approval requires a nonempty active set with every status mitigated or accepted.
History/item mutations reopen current sign-off while retaining the last stamp
as historical evidence. Deleted-parent children are not independently readable.
History repair chooses created_at DESC, id DESC after clearing inherited ordering;
an empty remaining timeline retains current state without fabricating ownership.

Content revisions cover same-second child/history changes. Command identity binds
actor/token, operation, path, original body and expected revision. Replays and own
receipt reads recheck current underlying rights/scopes and ownership. Receipts
contain identifiers/state, not duplicated risk notes. New actions require explicit
revision/key; legacy ordinary CRUD preserves optional-header compatibility.

## Consequences
SQLite tests alone do not prove native locking/uniqueness: verify on Dev MySQL.
The migration must precede release of shared browser actions. Rollback refuses
nonempty evidence. No provider transport, queue, scheduler or runtime activation.
Main/production and human acceptance remain separate from this design approval.
