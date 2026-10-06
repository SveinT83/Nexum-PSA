# ADR 10: Nexum Authority and Vaultwarden Synchronization and Conflict Behavior

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFCs: #279, #270

## Context

Trønder Data already uses Vaultwarden organizations, customer users, and browser/mobile clients. Nexum should manage credentials without requiring administrators to work in Vaultwarden, while selected customer and technician credentials remain available in familiar Bitwarden-compatible clients.

Vaultwarden is optional and may be offline, incompatible, or modified by an external user. Silent bidirectional last-write-wins synchronization could overwrite a newer Nexum version or turn external client access into authority over Nexum operations.

## Decision

Nexum Vault is authoritative for every item adopted into Nexum. Vaultwarden is an optional external publication/import adapter.

Integration owns the Vaultwarden Connector, endpoint, authentication, TLS, transport, provider capability detection, compatibility version, retry/backoff, and connection health.

Vault owns organizations/collections/user/group/contact mappings, item mappings, adopted provenance, desired publication, external version observations, sync cursor, conflicts, resolution, revocation/offboarding intent, and safe sync audit.

The adapter supports:

- read-only inventory and mapping;
- explicit controlled import into draft/staged Vault versions;
- publication of selected active Vault versions;
- organization, collection, user, and group mapping;
- safe update and removal;
- version/fingerprint comparison;
- conflict detection and resolution;
- customer browser/mobile availability;
- compatibility and health monitoring;
- retry, reconciliation, and offboarding.

Every mapping has a stable Nexum item/version reference and external item reference. Sync compares declared versions, hashes/fingerprints of normalized protected content, and provider revision metadata without storing plaintext in sync logs.

After adoption:

- a Nexum change can publish a new external version after policy and mapping checks;
- an external-only change is imported as a conflict candidate, never silently made active in Nexum;
- concurrent or ambiguous changes stop automatic overwrite;
- deletion or access removal on either side becomes an explicit state requiring the mapped policy;
- a stale provider response cannot roll Nexum back;
- a provider outage creates visible lag and retry state but does not make Nexum Vault unavailable.

Conflict resolution offers safe choices such as keep Nexum and republish, import the external value as a new staged Vault version, retain both as separately identified items, or remove the mapping. It shows provenance and timestamps without disclosing values until the reviewer separately has reveal authority and performs step-up.

Nexum customer-portal publication and Vaultwarden publication are separate grants. Mapping a contact to an external organization/collection does not widen Nexum portal access.

Adapter capability is detected and versioned. Vaultwarden's Bitwarden-compatible behavior and available APIs are not assumed to match every official Bitwarden cloud/management API. Unsupported or downgraded capabilities fail visibly. TLS verification is mandatory and cannot be bypassed.

External users cannot change Nexum authorization, lifecycle, relationships, Automation grants, or Connection scope through Vaultwarden.

## Rationale

Authoritative Nexum ownership delivers the requested administration experience and prevents external changes from silently affecting operations. Keeping transport in Integration preserves connector architecture, while Vault owns the security meaning and conflict decisions.

## Consequences

Positive:

- Technicians manage credentials in Nexum while users can retain browser/mobile access.
- Vaultwarden outages do not block Nexum-native Vault.
- External changes are visible without silently overwriting operations.
- Provider-specific transport remains isolated.
- Future Bitwarden or other vault adapters can reuse the boundary.

Negative:

- The adapter needs careful capability/version testing.
- External edits create conflicts that may require technician review.
- Full real-time bidirectional behavior is intentionally limited by authority rules.
- User, organization, collection, and offboarding mapping is operationally complex.

## Alternatives Considered

- Make Vaultwarden authoritative. Rejected because Nexum must work independently and own record relationships, policy, runtime use, and audit.
- Last-write-wins sync. Rejected because timestamps and provider revisions do not prove intended authority.
- One-way export only. Rejected as the complete product because controlled import and external-change awareness are required.
- Mirror every Vault Item automatically. Rejected because machine-only and internal secrets must not be published.
- Embed Vaultwarden API logic in Vault. Rejected because endpoint, transport, credential, and health behavior belongs to Integration.

## Follow-Up

- Build an authoritative capability matrix for supported Vaultwarden versions and APIs.
- Define normalized content fingerprints and conflict states.
- Test create/update/delete, concurrent edits, stale responses, outage, downgrade, mapping changes, and offboarding.
- Document organization/collection mapping and conflict resolution.
- Add adapter health, lag, conflict, and reconciliation monitoring.
