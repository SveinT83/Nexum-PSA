# ADR 3: Key Backup, Recovery, Break-Glass, and Compromise Response

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

A secure Vault must remain recoverable after host loss, key-provider failure, administrator loss, or an operational mistake. At the same time, a database backup, application backup, or single routine administrator action must not silently become a universal decryption path.

Nexum installations may have several eligible administrators or only one. Requiring a second person unconditionally would lock out a sole-admin installation, while allowing ordinary self-approval everywhere would defeat separation of duties.

## Decision

Treat key recovery and break-glass as separate, strongest-risk workflows.

Vault data backups contain encrypted records only. Key-provider and recovery material is backed up separately with distinct storage, access, retention, and custody. The normal application database, APP_KEY, logs, and ordinary deployment archive never contain enough material by themselves to recover Vault plaintext.

A versioned recovery package contains only the minimum encrypted key-provider recovery material and manifest needed to restore declared key versions. It is authenticated, encrypted, tamper-evident, installation-bound, and identified by a safe fingerprint. Recovery packages never contain Vault item plaintext.

When two or more eligible independent administrators or recovery custodians exist, recovery, provider replacement, mass rewrap, or emergency broad access requires approval by another eligible person. The requester cannot satisfy the independent approval.

When exactly one eligible administrator exists, Nexum permits a sole-administrator exception instead of creating a deadlock. It requires the strongest available step-up authentication, a clear irreversible-risk warning, a typed reason, a short-lived operation scope, confirmation of current backup state, enhanced audit, and a mandatory post-event review. Nexum must actively warn that one-person custody is weaker and recommend adding another custodian.

Break-glass grants do not reveal master keys and cannot bypass encryption. They create a short-lived, named authorization for specific Clients, items, operations, or a declared incident. Default duration is minimal, automatic expiry is mandatory, and every reveal/use remains individually audited. Global unlimited break-glass is unavailable during ordinary operation.

Recovery executes in an isolated maintenance flow. It verifies the recovery-package fingerprint, installation binding, provider manifest, approvals, and dry-run health before changing active configuration. Restored keys remain inactive until verification succeeds. Recovery never reactivates revoked workload grants, customer publication, Connections, queued jobs, or destroyed Vault versions automatically.

Compromise response supports:

- immediate lock of reveal, export, and runtime secret use;
- disabling a provider or key version;
- revoking affected Vault Items, Connections, customer publication, and runtime grants;
- identifying affected key and item versions without exposing values;
- rewrapping unaffected data under a new provider key;
- rotating actual credentials where compromise could have exposed plaintext;
- preserving tamper-evident audit evidence;
- a documented decision between recovery, credential rotation, and cryptographic destruction.

Scheduled isolated restore drills must prove that both encrypted data and separately held recovery material are usable. A successful backup job without a verified restore is not a healthy recovery posture.

## Rationale

Separate custody reduces the chance that one stolen backup or routine administrator session compromises the Vault. The independent-approval rule provides separation of duties where possible, while the explicit sole-admin exception preserves operability for small Nexum installations without silently weakening the model.

## Consequences

Positive:

- Host or database loss can be recovered without storing plaintext backups.
- Multi-admin installations gain real two-person control for the highest-risk operations.
- Sole-admin installations remain usable and visibly accept their higher risk.
- Recovery cannot silently restore revoked access or automation.
- Incident response can lock and scope the Vault before full credential rotation.

Negative:

- Recovery operations are slower and require maintained runbooks and custody.
- Losing all recovery material can make encrypted data permanently unavailable.
- Restore drills need an isolated environment and human participation.
- The sole-admin path remains a residual risk and must be prominent in reviews.

## Alternatives Considered

- Put keys in the database backup. Rejected because database theft would expose both ciphertext and its decryption root.
- Require two custodians for every installation. Rejected because a sole-admin deployment would be unrecoverable by design.
- Let any superuser recover silently. Rejected because ordinary administration is not equivalent to cryptographic custody.
- Store a universal vendor recovery key. Rejected because it creates a cross-installation master compromise path.
- Automatically resume all Connections and Automation after restore. Rejected because identity, scope, contract, and credential state may have changed.

## Follow-Up

- Define supported recovery-package storage media and custody guidance.
- Add the sole-admin warning and post-event review workflow.
- Test provider loss, partial backup, corrupt package, wrong installation, stale manifest, and revoked-key scenarios.
- Add scheduled restore-drill status and alerts.
- Create the incident, recovery, and mass-rotation runbooks before production use.
