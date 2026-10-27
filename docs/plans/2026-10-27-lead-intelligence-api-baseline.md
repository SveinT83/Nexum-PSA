# Lead Intelligence API Planning Baseline

Date: 2026-10-27. Authoritative Dev: /var/Projects/tdPSA, branch Dev.
HEAD: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.

- Runtime inventory: 14 browser rows / 11 API HTTP operations, excluding HEAD.
  One browser row is a multi-method redirect, not seven business actions.
- Existing generated Swagger has zero matching LeadIntelligence operations.
- Existing baseline: HOME=/tmp php artisan test --compact --filter=LeadIntelligence.
  Result: 33 passed / 277 assertions; 44.34 seconds; no failures or skips.
  PHPUnit printed unrelated deprecated doc-comment metadata warnings during discovery.
- Five dirty LeadIntelligence views are the existing closed #280 Settings badge work;
  all 39 module file hashes are retained and rechecked after planning writes.
- GitHub #280 and #154 are closed/completed; issue bodies and all comments were read.
  #154's scheduler regression remains covered. No issue/comment or other message posted.
- Older approved June RFCs describe foundation, scheduling, promotion and worker behavior;
  a new Draft RFC defines the actual full API contract and integration impact.
- Browser actor requirement is sales.lead_manage plus active internal access. Existing
  APIs have Sanctum abilities but no equivalent native actor guard. Navigation's admin
  badge does not imply an admin-only role policy.
- Promotion's local insert bypasses Clients/CreateClientRecord provider-number authority
  and infers invoice email. The RFC explicitly proposes shared ownership and no fallback
  invoice email when organization number is blank; no behavior was changed in planning.
- Knowledge parity is separately In Progress / approved. Signal planning is blocked on
  its own RFC approval. This independent proposal starts no second implementation and
  does not alter their scope, status, files or gates.

The RFC, Proposed ADR, sequential Draft slices, full matrix and planned human-review
checklist are reviewable proposals only. This baseline tests existing behavior and
is not evidence that the proposed API is implemented. No application source, business
row, migration, provider/queue/scheduler activation, permission, commit/push, Main or
production write occurred. No handbook publication is needed for unimplemented plans.
Next owner/action: Svein Tore approves the concrete RFC; Codex then implements and
verifies on Dev, with re-audit of shared WIP before any overlapping implementation.
