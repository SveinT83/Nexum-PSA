# ADR 5: Vault Item Version Lifecycle, Retention, Destruction, and Audit

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Credentials change over time, may require verification before use, can be compromised, and may remain referenced by Connections, Automations, customers, or historical audit. Editing one encrypted database field in place would erase provenance and make rollback, review, revocation, and incident analysis unreliable.

The lifecycle must distinguish replacing a secret from editing safe metadata and must prevent deletion from breaking live operations or falsifying history.

## Decision

A Vault Item is the stable organizational identity and relationship container. Secret content lives only in immutable Vault Item Versions.

The standard version lifecycle is:

- draft: entered or imported but unavailable to runtime and customers;
- staged: structurally valid and ready for controlled verification;
- verified: verified against its intended target or manually attested where online verification is impossible;
- active: the exact version selected for authorized reveal, customer publication, Connection, or runtime use;
- superseded: replaced by a newer active version but retained for history and an explicit rollback window;
- retired: intentionally no longer active and unavailable to new runtime grants;
- revoked: treated as potentially compromised or no longer trusted; all new reveal and use fail closed;
- destroyed: encrypted payload and wrapped data key have been cryptographically erased after policy permits, while safe tombstone and audit history remain.

Activation is explicit and atomic. Only one active version exists per declared use slot unless an approved provider workflow requires an overlap window. Rotation creates and verifies a new version before switching dependencies. It never overwrites the active ciphertext.

Secret-field, TOTP seed, private-key, and encrypted-attachment changes always create a new immutable version. Safe metadata such as title, tags, owner collection, review date, and relationships may change on the stable item with audited before/after safe values. A metadata change that alters cryptographic associated data or security classification creates a new version or a verified rebind operation.

Dependencies reference either an exact active version or an explicit selector with recorded resolution policy. Before retirement, revocation, destruction, or relationship removal, Vault shows affected Connections, Automations, customer publications, and other consumers.

Revocation blocks new reveal, copy, export, and runtime use immediately. In-flight external actions cannot be undone by relabeling a version; Nexum records what may already have happened and triggers incident handling.

Destruction requires:

- no active dependency or an explicitly approved coordinated shutdown;
- expiry of required operational, legal, audit, and rollback retention;
- strongest step-up and approval policy;
- a preview of affected records and recoverability;
- destruction of wrapped data keys and encrypted payload material;
- a retained secret-free tombstone proving who, what version, why, when, and which policy authorized it.

Ordinary UI deletion is never used for Vault history. Archives hide inactive items from normal work while retaining policy-controlled recovery. Recovery creates a new active version or restores an eligible superseded version through explicit verification; it does not mutate historical records.

Vault audit is append-only and secret-safe. It records actor/workload, action, item/version reference, Client/relationship scope, policy and approval reference, result, sanitized reason/error, timestamp, and correlation ID. It never records secret values, decrypted fields, TOTP codes, attachment plaintext, authorization headers, or recovery material.

## Rationale

Stable items plus immutable versions give Nexum reliable provenance and safe rotation. Explicit states prevent an imported, unverified, revoked, or destroyed credential from being used merely because a database row exists. Cryptographic destruction provides meaningful erasure without deleting the evidence needed for security and accountability.

## Consequences

Positive:

- Complete version and rotation history.
- Safe staged verification and atomic cutover.
- Immediate revocation of new use.
- Dependency impact is visible before destructive actions.
- Audit remains useful without retaining plaintext.

Negative:

- More records and lifecycle UI than an editable password field.
- Providers with overlapping credentials require use-slot rules.
- Historical ciphertext and audit increase retention and backup complexity.
- Revocation cannot reverse an external operation already performed.

## Alternatives Considered

- Update secrets in place. Rejected because it loses provenance and makes rollback and incident analysis unsafe.
- Soft-delete entire rows. Rejected because it confuses lifecycle, dependencies, and cryptographic erasure.
- Allow runtime use of staged versions. Rejected because structural validity is not operational verification.
- Delete audit with the secret. Rejected because accountability and incident evidence must survive as secret-free facts.
- Keep destroyed data keys encrypted forever. Rejected because that is retention, not destruction.

## Follow-Up

- Define valid transition guards and provider overlap rules.
- Add dependency-impact queries and UI.
- Define default review, rotation, rollback, and retention policies by item type.
- Test concurrent activation, stale selection, revocation races, destroyed versions, and recovery.
- Document rotation, revocation, archival, recovery, and destruction.
