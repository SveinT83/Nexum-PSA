# ADR 9: Controlled Import, Encrypted Export, and Legacy Credential Migration

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Nexum must migrate credentials from existing Integration, Email, AI, BookStack, RMM, Nextcloud, CloudFactory, relationship, and other storage paths. It must also import from Passportal, Vaultwarden/Bitwarden, and structured files and provide a controlled way to export for backup, transfer, or offboarding.

Credential files are untrusted, duplicates are ambiguous, and a big-bang migration could break live services. Plaintext CSV export would create an unmanaged copy of the entire Vault.

## Decision

Import, export, and legacy migration use versioned runs with preview, explicit authority, immutable evidence, verification, and read-back.

### Import

Every import follows:

1. receive through a bounded encrypted upload channel;
2. quarantine and malware/type/size validation;
3. parse in an isolated worker with no network access unless the adapter requires a separately approved connection;
4. classify item types and fields;
5. map Clients, Sites, Assets, collections, contacts, and external IDs;
6. detect exact provenance and possible duplicates without merging by title, username, or host alone;
7. show a secret-safe preview and conflicts;
8. require an authorized apply decision;
9. create draft/staged Vault Items and Versions;
10. verify selected credentials where safe;
11. activate and publish only through separate lifecycle decisions;
12. retain safe run evidence and destroy temporary plaintext promptly.

Imported values never become active merely because parsing succeeded. Unknown fields remain explicitly unmapped or protected; they are not silently discarded or copied to logs.

### Export

Metadata-only exports may use ordinary safe DataExchange contracts when fields are explicitly allowlisted.

Any export containing secret material uses a Vault-owned encrypted export package. The package is authenticated, versioned, installation/export-run identified, recipient-bound where possible, and encrypted with a fresh export key or approved recipient public key. The decryption key is delivered through a separate protected channel and is never stored beside the package.

Secret export requires strongest step-up, explicit item/Client scope, reason, expiry, rate/quantity controls, and independent approval when eligible. Plaintext bulk CSV/JSON export is unavailable. A single reveal/copy is not an export API.

### Legacy Nexum Migration

Each legacy source has its own migration adapter and run. The run records source owner, schema/version, discovered records, callers, encryption state, dependency map, target Vault Items, conflicts, verification, cutover state, rollback deadline, and purge state.

Migration sequence is preview -> stage -> verify -> cut over selected callers -> read back runtime parity -> monitor -> retire legacy reads -> separately approve purge.

Until cutover, the legacy source remains authoritative for its declared callers. During the cutover window, dual write is avoided; if unavoidable for a specific provider, it requires a source-specific ADR and reconciliation. After cutover, callers fail closed rather than silently reading legacy values.

Rollback is allowed only during the declared window and only when legacy evidence has not changed incompatibly. New Vault rotation, revocation, customer publication, external sync, or dependent execution may invalidate rollback.

Purging legacy ciphertext is a separate destructive operation after backup, rollback, audit, and human-review requirements are satisfied. Migration completion is not inferred from row counts.

## Rationale

Staging and per-source cutover keep operational services working while protecting Vault authority. Encrypted export prevents a convenience feature from becoming the easiest way to steal all credentials. Explicit provenance and conflict handling avoid unsafe deduplication.

## Consequences

Positive:

- Migrations are reviewable, restartable, and source-specific.
- Imported secrets do not become active without verification.
- Exported secret packages remain protected outside Nexum.
- Legacy callers can be proven before old storage is removed.
- Duplicate and conflict decisions retain provenance.

Negative:

- Migration takes longer than a direct database copy.
- Some legacy providers need custom verification and rollback logic.
- Encrypted export requires recipient/key handling and user education.
- Temporary import plaintext requires carefully isolated processing.

## Alternatives Considered

- Big-bang migration. Rejected because one mapping or runtime error could break every integration.
- Merge records by title/username/host. Rejected because similar identifiers do not prove identical authority or secret versions.
- Permanent dual-write. Rejected because it creates two authorities and complex rotation races.
- Plaintext bulk CSV export. Rejected because it creates an uncontrolled high-value copy.
- Keep automatic legacy fallback forever. Rejected because Vault would never become authoritative.

## Follow-Up

- Complete the credential inventory before the first migration slice.
- Define source-specific adapters, verification, rollback, and purge criteria.
- Select and document the encrypted export package format.
- Add malicious file, partial-run, duplicate, conflict, stale rollback, and secret-leakage tests.
- Track every source to verified cutover or explicit retirement in the completion matrix.
