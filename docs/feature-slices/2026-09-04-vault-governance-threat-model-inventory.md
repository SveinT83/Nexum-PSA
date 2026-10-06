# Feature Slice: Vault Governance, Threat Model, And Credential Inventory

Status: Done On Dev
Date: 2026-09-04
Parent: `docs/rfc/2026-09-04-vault-domain-and-operational-credential-platform.md`
Owner: Codex, approved by Svein Tore

## Goal

Turn Discussion #279 into canonical, source-backed implementation governance before any Vault code
or credential migration begins.

## User-Visible Behavior

None. This slice creates the authoritative security and implementation plan only.

## Scope

- Publish the complete approved RFC in `docs/rfc/`.
- Publish all 12 accepted security ADRs in `docs/adr/`.
- Correct the RFC's current-state text for the account-owned Email credential cutover and shared
  dirty Dev working copy without changing the approved product decision.
- Record a source-backed architecture threat model.
- Inventory every current credential store, consumer, authority, migration risk, and exclusion.
- Record the ordered completion matrix and dependency gates for #270 and #272.
- Add the workstream to TODO and open its parent human-review checklist.

## Out Of Scope

- Vault module, routes, schema, permissions, UI, API, key material, or secret storage.
- Reading or migrating any credential value.
- Activating Vault, Vaultwarden, customer publication, runtime use, or legacy fallback.
- Declaring the product or any production migration ready.

## Data Touched

Documentation only: RFCs, ADRs, audits, plans, TODO, and human-review register.

## Permissions

No runtime permission changes.

## Tests

- Verify all canonical files exist and have `Approved`/`Accepted` status.
- Verify no Vault module, migration, permission, route, API, or UI is introduced by this slice.
- Verify the RFC's Email authority text matches the 2026-09-01 account-owned decision.
- Verify the completion matrix includes every mandatory RFC capability and production gate.

## Documentation

This slice is the documentation baseline for the complete Vault program.

## Done Criteria

- [x] Approved RFC is canonical on authoritative Dev.
- [x] All 12 ADRs are canonical and Accepted on authoritative Dev.
- [x] Threat model and credential inventory are source-backed and secret-free.
- [x] Completion matrix and first code slice are recorded.
- [x] TODO and human-review registers are reconciled after active #266 work completes.
- [x] No runtime behavior or credential data changed.
