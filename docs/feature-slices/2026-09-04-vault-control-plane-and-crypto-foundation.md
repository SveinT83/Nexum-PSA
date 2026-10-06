# Feature Slice: Vault Control Plane And Cryptographic Foundation

Status: Done On Dev / Human Review Pending
Date: 2026-09-04
Parent: docs/rfc/2026-09-04-vault-domain-and-operational-credential-platform.md
Owner: Codex, approved by Svein Tore

## Goal

Create a runtime-disabled, fail-closed Vault domain and independent envelope-encryption boundary
that later user workflows and source migrations must use.

## User-Visible Behavior

No credential workspace, reveal, copy, customer, API, migration, or activation UI is exposed. The
only operational surface is a secret-free CLI cryptographic-foundation health command.

## Scope

- Singular app/Modules/Vault with an empty module-owned routes.php.
- Strict VAULT_ENABLED=false and independent runtime-approval defaults.
- XChaCha20-Poly1305-IETF envelope codec with a 65,536-byte plaintext limit.
- Random 32-byte DEK per version and independent random 24-byte payload/wrap nonces.
- Deterministic length-prefixed AAD binding installation, scope, Client, item, version, schema,
  algorithm, provider, and provider-key identity.
- Replaceable provider contract and one registered local sealed provider.
- External installation seal key and per-company/per-Client sealed KEKs; no APP_KEY fallback.
- POSIX-only local provider with mandatory effective-user ownership verification before path I/O.
- Full capability health requiring all tables and every database guard.
- Canonical identities, immutable metadata, typed append-only audit, and singular one-to-one
  encrypted material with no generic Eloquent material model.
- Schema-only audited terminal material destruction; no application path.
- Nonserializable/redacted cryptographic buffers and best-effort memzero.
- Four control-plane permissions only, safe command, README, and Knowledge article.

## Out Of Scope

Any secret writer/concurrent version allocation, reveal, copy, use, customer grants, portal, TOTP,
attachments, search, rotation/rewrap, recovery, break-glass, export, destruction actions,
Vaultwarden, API/MCP, #270/#272, legacy migration, production activation, key provisioning, or real
credential storage.

## Data Touched

Safe item/key/version identity metadata, encrypted payload/wrapped-DEK material, typed metadata-only
audit evidence, and default-off configuration. No KEK or seal key is stored in the database.

## Permissions

Only vault.health_view, vault.audit_view, vault.policy_manage, and vault.key_provider_manage exist.
Admin receives the first three; Superuser receives all four; Tech and Viewer receive none. No
secret-content permission is registered.

## Tests

- Round-trip, 65,536-byte boundary, randomized nonces/ciphertext, and independent AAD domains.
- Tamper/AAD/algorithm/format/scope/Client/item/version/schema/provider/key-ID failures.
- No APP_KEY/Laravel Crypt/key generation/key or scope fallback.
- Full table/trigger capability health and exact authenticated scope key.
- Serialization, clone, JSON, queue, dump/export, and debug leakage negatives.
- MariaDB/SQLite immutability, typed audit, exact material/key binding, terminal destruction, and
  rollback guards.
- Canonical UUID/schema/version width/binary and 65,552-byte ciphertext parity.
- Partial schema, malformed config, missing POSIX EUID, missing files, path/mode/owner,
  provider/scope, tamper, and duplicate keyring nonce failures.
- Approved role map, explicit-removal preservation, partial-role bootstrap, no content permission.
- Empty module routes and no registered Vault HTTP route.

Writer rollback and concurrent version allocation are deferred to the next approved write slice.

That slice must replace the foundation-only nullable creator/state-actor allowance with persisted
human/system provenance, exact authorization, typed same-transaction audit, locked version
allocation, and provider-failure rollback before any real material can be written.

## Documentation

Maintain this file, app/Modules/Vault/README.md, and the Vault Knowledge article without reusable
key material or secret-bearing paths.

## Dev Verification

- The focused Vault suite passes 52 tests / 457 assertions, including forced exception-trace,
  leakage, permission, cryptographic, SQLite, and provider failure contracts.
- The isolated MariaDB contract passes 1 test / 137 assertions under strict mode with
  explicit_defaults_for_timestamp=0. All 11 temporal columns are DATETIME with no implicit
  CURRENT_TIMESTAMP or ON UPDATE behavior.
- The additive Dev migration passed pretend and landed as four migration rows in batch 5. Read-back
  confirms five empty Vault tables, the singular material table, 15 guards/triggers, MEDIUMBLOB
  payload storage, non-null audit reason codes, and no key locators or material.
- Permission read-back confirms exactly four control-plane permissions: Admin has three,
  Superuser has four, and Tech/Viewer have none. No secret-content permission exists.
- The health command returns the expected non-zero vault_disabled state, and no Vault HTTP route is
  registered.
- Independent cryptographic review found zero open Slice 1 blockers.
- The complete Nexum suite passes 2,608 tests / 25,215 assertions with HOME isolated to /tmp.

The first Dev migration attempt exposed MariaDB's implicit TIMESTAMP default/ON UPDATE behavior before
the base migration was recorded. Recovery verified exactly three unrecorded partial tables, zero
rows, zero triggers, and zero external inbound foreign keys; only those empty tables were removed in
reverse dependency order. The DATETIME contract was regression-tested before the clean migration.

## Done Criteria

- [x] Module/configuration remain dormant and fail closed.
- [x] Envelope/provider/full-schema/both-driver contracts pass.
- [x] Immutable metadata, one-way erasure, and append-only audit invariants hold.
- [x] Negative leakage, tamper, AAD, scope, config, route, and permission tests pass.
- [x] Only four control-plane permissions exist with the approved role map.
- [x] No credential, key file, writer, consumer, portal, API, route, or UI is active.
- [x] Independent cryptographic review is recorded before real-material work.
- [x] Human review remains Pending until a named reviewer completes it.
