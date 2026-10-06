# ADR 4: Vault Authorization, Step-Up Authentication, Approval, and Sole-Admin Exception

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Ordinary page access is too broad for a credential platform. A technician may need to discover that an item exists without revealing it; an administrator may configure policy without reading secrets; a customer may receive only selected items; and a Tool or Automation may use a credential without revealing it to a human or model.

The authorization model must also handle direct and Vault access-group grants, Client and record scope, deactivated technicians, customer publication, sensitive operations, step-up authentication, and independent approval without locking a sole-admin installation. Vault owns those access groups and their memberships; UserManagement remains authoritative for user identity and general roles.

## Decision

Every Vault operation uses one centralized effective-authorization decision. The most restrictive intersection wins:

1. active authenticated human, customer, service, or workload identity;
2. global Vault permission or API ability;
3. Client, Site, and related-record visibility;
4. direct, Vault access-group, collection, customer-publication, or runtime grant;
5. Vault Item classification and lifecycle state;
6. requested operation;
7. current session freshness and step-up state;
8. device, rate, quantity, time, and risk policy;
9. required approval or standing Automation authorization;
10. current dependency, contract, Connection, and incident-lock state.

No role, API token, Agent, Tool, Connection, relationship, or item identifier grants access by itself. Superuser status does not create an invisible secret-content bypass.

Permissions separate at least:

- administer Vault policy and key-provider configuration;
- manage item metadata and relationships;
- create or edit secret versions;
- verify and activate versions;
- reveal or copy plaintext;
- request use-without-reveal;
- grant internal access;
- publish to customers;
- export;
- review safe audit;
- perform recovery;
- perform key management;
- migrate legacy sources;
- activate break-glass;
- approve high-risk operations.

The UI may group these into understandable roles, but enforcement remains operation-specific.

Sensitive reveal, copy, export, recovery, broad grant changes, key operations, break-glass, and destructive lifecycle actions require fresh step-up authentication. Passkey/WebAuthn is preferred when configured; otherwise Nexum requires the strongest enrolled 2FA plus fresh primary authentication. A normal long-lived Nexum session is insufficient. Step-up expires quickly and is invalidated by identity, permission, device, policy, or incident changes.

Independent approval is required for critical operations whenever the locked durable candidate roster
contains another candidate. That second identity must satisfy the operation's approval-eligible or
governance-recovery-eligible rule and become decision-ready for the concrete decision; if it cannot,
the operation denies rather than falling through to sole-admin. The approval is bound to an immutable
plan digest containing actor, action, Vault scope, target item/key versions, recipients, limits,
reason, and expiry. A changed plan requires new approval. The requester, Agent, Tool, or Automation
cannot approve its own request.

When the locked candidate roster contains only the requester, the sole-admin exception permits the operation with the strongest step-up, explicit warning, reason, short expiry, enhanced audit, and mandatory post-event review. It is never silently selected.

For the first authorization implementation slice, the durable sole-admin candidate roster is all
human identities whose persisted status is exactly `ACTIVE` and who have the protected
Admin/Superuser role, independent of Vault permission, TOTP enrollment, login, or step-up. Ordinary
approval-eligible is narrower: candidate plus exact live/mapped `vault.approval_decide`, confirmed
TOTP, and relevant company scope or exact Client visibility, but no active proof. `vault.policy_manage`
is not a general grant-approver requirement. Governance-recovery-eligible adds live/mapped
`vault.policy_manage`; readiness-floor, recovery, unlock and policy-governance counts use that
eligible set without requiring a current proof. Decision-ready means the operation's required
eligible class plus a fresh active, non-revoked, same-session/actor/epoch-bound step-up proof
referenced by the exact plan, request and concrete decision. Standard step-up remains reusable until
TTL expiry or revocation and has no consumed state; only the separate TOTP-enrollment proof is one-
use and consumed.
A second candidate who is not decision-ready blocks expansion rather than enabling sole-admin
fallback. The exception is valid only when the locked roster
contains exactly requester. Roster reduction is guarded against creating a silent sole-admin path;
emergency reduction requires the closed `emergency_security_deactivation` reason and sets a fail-
closed quorum lock with separately reviewed recovery.

That slice uses fresh password plus confirmed TOTP with a tight plus/minus one counter window,
atomic replay prevention, and a configurable ten-minute default proof. WebAuthn/passkey remains the
preferred final target but is not simulated by reusing the current wide-window TOTP helper.

Graph administration uses separate grant-management and approval-decision permissions. Policy
management remains settings/policy authority only, and no pre-existing content grant is required to
administer the graph. Content operations remain unsupported/default-deny until their own additive
permission and operation slices.

Interactive Tool use follows the normal action-approval policy. Automation may use a pre-approved standing authorization only when that authorization names the Automation version, allowed Vault Items or selectors, target Connections and operations, limits, expiry/review date, and verification contract. Standing authorization is not plaintext access.

Deactivation or grant removal invalidates new sessions and runtime grants immediately. Items remain organization-owned and can be reassigned by an authorized administrator.

## Rationale

One effective decision prevents UI, API, jobs, customer portal, and AI runtime from drifting into different access rules. Separating administration from secret content follows least privilege, while step-up and immutable approval prevent a broad session or changed plan from authorizing a critical action.

## Consequences

Positive:

- Administrators can manage configuration without automatically reading secrets.
- Technicians, customers, API clients, Agents, and Automations use the same policy engine.
- High-risk actions are bound to exactly what was reviewed.
- Multiple administrators provide real separation of duties.
- A sole administrator is warned but not permanently locked out.

Negative:

- Effective access can be complex and needs clear explanation and preview in the UI.
- Revocation and policy changes must invalidate caches and queued grants quickly.
- Step-up and approval add friction to legitimate sensitive work.
- The permission matrix requires extensive negative tests.

## Alternatives Considered

- One broad vault.manage permission. Rejected because administration, reveal, export, recovery, and publication have very different risk.
- Administrator bypass. Rejected because configuration authority is not secret-content authority.
- Approval on every runtime use. Rejected because reviewed Automation must operate unattended within a bounded standing authorization.
- No approval for sole admins. Rejected because the residual risk must be explicit and audited.
- Let Agents approve their own plans. Rejected because approval must be independent of the proposer and executor.

## Follow-Up

- Define the initial permission catalog and role templates.
- Broker every UserManagement role, permission, TOTP-eligibility, and status mutation that changes
  Vault authority through the locked Vault authority-mutation boundary.
- Implement one reusable authorization service used by UI, API, jobs, runtime, and portal.
- Add effective-access preview without leaking inaccessible item existence.
- Test direct/Vault-access-group/collection precedence, Client isolation, deactivation, stale approval, changed plan, and sole-admin behavior.
- Document step-up, approval, Automation authorization, and offboarding procedures.
