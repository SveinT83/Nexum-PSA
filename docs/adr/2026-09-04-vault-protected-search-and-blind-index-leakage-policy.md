# ADR 8: Protected Vault Search and Blind-Index Leakage Policy

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Technicians need to find credentials by Client, Site, Asset, service, title, username, host, tags, and related records. Encrypting everything without an index would make the product unusable, while ordinary full-text indexing of secret fields would copy sensitive data into search tables, logs, analytics, and backups.

Searchable encryption and blind indexes also leak equality, frequency, and change patterns. The design must expose only what is necessary for authorized discovery.

## Decision

Vault search is metadata-first and permission-filtered. Every field in an item type is classified as:

- public-safe metadata;
- authorized metadata;
- secret;
- derived sensitive value;
- prohibited from indexing.

Default searchable metadata includes opaque item ID, item type, title, Client/Site/record relationships, collection, safe tags, lifecycle state, and review/rotation status. Even safe metadata is returned only after current authorization.

Passwords, API tokens, private keys, TOTP seeds/codes, recovery codes, encrypted attachment content, authorization headers, and secret notes are never indexed. They cannot be searched by partial value, suffix, prefix, similarity, or full text.

Fields such as usernames, account names, hosts, domains, certificate subjects, and license identifiers are classified per item type and organization policy. If they are considered secret, they remain encrypted and excluded from ordinary search.

For approved exact-match search of selected sensitive identifiers, Vault may maintain a keyed blind index:

- normalize through a field-specific, versioned deterministic rule;
- compute a keyed cryptographic digest with a dedicated search key separated from data-encryption and key-encryption keys;
- include installation, field type, and Client boundary in the input;
- store only the digest and key/normalization version;
- query only after an authorized user selects the relevant Client/scope;
- rate-limit and audit sensitive lookup;
- support key rotation through dual-index backfill and verified cutover.

Blind indexes are not used for low-entropy values when offline guessing or frequency leakage would be unacceptable. They do not support fuzzy search. The UI must not reveal whether an inaccessible matching item exists, and counts, suggestions, autocomplete, timing, and error messages must follow the same rule.

Decrypted-result scanning is allowed only after an authorized user has selected a small bounded candidate set through safe metadata. It occurs in memory, returns only authorized matches, and is never persisted as a generic index or analytics event.

Search logs record safe query category, scope, result count band, duration, and actor where needed, not raw sensitive terms.

## Rationale

Metadata-first search handles the common technician workflow with low leakage. Field classification prevents convenience code from indexing secrets. A narrowly scoped blind index provides exact lookup where operationally necessary while acknowledging and limiting its leakage.

## Consequences

Positive:

- Useful search without copying secret values into a full-text engine.
- Item types can classify operational identifiers appropriately.
- Blind-index rotation and leakage are explicit and testable.
- Inaccessible item existence is protected.

Negative:

- Search is less flexible than ordinary full text.
- Blind indexes still leak equality/frequency within their declared scope.
- Classification and normalization changes require migration.
- Some searches require first narrowing by Client or related record.

## Alternatives Considered

- Full-text index decrypted payloads. Rejected because the search system would become a second secret store.
- Deterministically encrypt every field. Rejected because it leaks equality broadly and complicates safe rotation.
- Never search sensitive identifiers. Rejected as an absolute rule because exact account/host lookup may be operationally necessary.
- Decrypt every Vault Item for each search. Rejected because it is expensive, expands plaintext exposure, and creates timing/availability risk.
- Let an external search provider index Vault. Rejected because it adds another secret and data-egress boundary.

## Follow-Up

- Approve field classifications for every item type.
- Threat-model proposed blind-index fields before enabling them.
- Define normalization and key-rotation versions.
- Add negative existence, autocomplete, count, timing, and log-leakage tests.
- Document search limits and safe technician workflows.
