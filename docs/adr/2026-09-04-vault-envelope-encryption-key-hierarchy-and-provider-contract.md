# ADR 2: Envelope Encryption, Key Hierarchy, and Key Provider Contract

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Vault will concentrate passwords, tokens, private keys, certificates, recovery codes, TOTP seeds, and other high-value secrets. Laravel field encryption with the application APP_KEY is not sufficient as the final Vault architecture because it couples all data to one application key, offers limited item-level rotation and destruction, and makes independent recovery and provider migration difficult.

The design needs authenticated encryption, item/version isolation, replaceable key providers, safe rotation, and protection against database-only compromise.

## Decision

Use envelope encryption with a versioned cryptographic suite and a replaceable key-provider interface.

Each immutable Vault Item version receives a new randomly generated 256-bit data-encryption key. The payload is encrypted with libsodium XChaCha20-Poly1305 authenticated encryption using a unique random 192-bit nonce. Associated authenticated data binds the ciphertext to installation identity, Vault Item ID, version ID, payload-schema version, item type, Client boundary where applicable, and cryptographic-suite version. Moving ciphertext to another record or installation therefore fails authentication.

The data-encryption key is never stored plaintext. It is wrapped by the active key-encryption key through the configured Vault Key Provider. The database stores ciphertext, nonce, wrapped key, provider ID, key version, suite version, and safe integrity metadata only.

The provider contract supports:

- generate or import a protected key-encryption key;
- wrap and unwrap a data-encryption key;
- report health and supported algorithms;
- rotate provider keys and rewrap data keys without decrypting every payload in application code;
- disable or revoke a key version;
- produce protected backup and recovery material;
- return explicit unavailable, denied, corrupted, or retired states.

The initial self-hosted provider uses a dedicated Vault master key kept outside the database and outside APP_KEY. It is loaded from an approved secret file or secret-injection mechanism with least OS access. APP_KEY may protect ordinary application fields but is not the Vault root of trust.

Future KMS, HSM, Vault, or platform providers implement the same contract. Provider selection cannot weaken the mandatory security floor.

Plaintext secret payloads and data-encryption keys exist only in bounded process memory for the authorized operation and are discarded promptly. They are never serialized to jobs, caches, sessions, logs, traces, errors, model prompts, or audit payloads.

Key rotation has two distinct operations:

1. rewrap existing data-encryption keys under a new key-encryption key version;
2. create a new Vault Item version with a new data-encryption key when the secret itself changes.

Cryptographic suite changes use explicit read-old/write-new migration with verification and rollback. Unknown, downgraded, or unauthenticated formats fail closed.

## Rationale

Per-version envelope encryption limits blast radius, permits fast key rotation, supports cryptographic destruction, and separates database backups from key custody. XChaCha20-Poly1305 is available through PHP libsodium, provides authenticated encryption, and has a large nonce space suitable for independently encrypted records. A provider contract allows Nexum installations to start self-hosted and later adopt stronger managed key storage without changing Vault ownership.

## Consequences

Positive:

- Database theft alone is insufficient to decrypt Vault payloads.
- Every version has independent encryption material.
- Key-encryption keys can rotate by rewrapping rather than rewriting all secret payloads.
- KMS/HSM adoption remains possible.
- Record substitution and ciphertext tampering are detected.

Negative:

- Losing both active and recoverable provider key material makes secrets unrecoverable.
- Key-provider outages can block reveal and runtime use.
- Deployment, backup, monitoring, and recovery become security-critical.
- Cryptographic code and migrations require specialist review and extensive tests.

## Alternatives Considered

- Laravel Crypt with APP_KEY only. Rejected as the final Vault root because it couples Vault recovery and rotation to the application key and lacks the required per-version hierarchy.
- One encryption key per Client. Rejected as the only layer because it still creates broad blast radius and awkward item destruction; Client separation remains in authorization and authenticated data.
- Client-side-only zero-knowledge encryption. Rejected because approved server-side Connection, Tool, and Automation use would become impossible or require another competing secret channel.
- Store plaintext data keys encrypted in a database column with the same database-held key. Rejected because it does not create a meaningful trust boundary.
- Custom cryptography. Rejected; only reviewed platform primitives and standard provider APIs may be used.

## Follow-Up

- Threat-model the exact self-hosted key-injection and OS boundary on authoritative Dev.
- Confirm libsodium availability and supported PHP versions before implementation.
- Define versioned payload and associated-data schemas.
- Add known-answer, tamper, nonce-uniqueness, rewrap, downgrade, and corrupted-record tests.
- Complete independent cryptographic review before production migration.
