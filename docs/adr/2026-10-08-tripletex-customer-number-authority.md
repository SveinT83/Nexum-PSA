# ADR: Provider-owned customer numbers and durable creation intent

Status: Accepted
Date: 2026-10-08
Decision Makers: Svein Tore (product authority), Codex (implementation)
Parent: ../rfc/2026-10-08-tripletex-customer-numbers.md

## Context

A number suggestion cannot reserve a provider number. Local and remote databases cannot commit
atomically. Timesheet synchronization currently overloads the connection status flag.

## Decision

Use independent customer/time settings, preserving legacy time settings on first change.
Use provider-backed suggestions only when customer synchronization and integration are active.
Keep immutable provider identity and durable intent before POST, then exact GET read-back.
Unknown writes stop for reconciliation without an automatic repeat POST. Local failure leaves
the verified provider identity available for recovery with the original request key.
Use explicit number adoption for existing bindings and protect ordinary edits/imports.
Existing local allocation is unchanged. Full profile synchronization is a separate future scope.

## Rationale and alternatives

A cached max plus one or a local lock alone cannot reserve a Tripletex number.
Silently selecting a new local number after an outage violates approved authority.
A provider call inside an import batch transaction loses durable intent on rollback; deny it.

## Consequences and follow-up

Requires an additive evidence table, a default-off customer synchronization GUI setting and human
provider/UI verification. No scheduled writer or automatic historical renumbering is introduced.

## GUI write authority revision - 2026-10-08

Svein explicitly rejected requiring .env changes after enabling customer sync in the GUI.
The saved customer_sync_enabled setting is therefore also the write authorization. Remove the
customer-specific runtime flag rather than defaulting it on or silently editing server files.
The transport re-reads the persisted connection immediately before POST and still requires an
active connection, global integration availability and saved customer consent. Account locks,
verified company identity, durable intent and read-back remain. Time-write controls are independent.
This supersedes the initial extra customer-write environment gate.

## Approved profile extension - 2026-10-08

Billing Email and one bound Site address are now implemented on Dev as a separate approved
extension: RFC 2026-10-08-tripletex-customer-profiles and ADR
2026-10-08-tripletex-customer-profile-baselines. Earlier future-scope wording above describes
the original number-only slice. Names, recurring Contacts, other Sites and deletion remain excluded.
The extension adds its own state migration and five-minute scheduled command; the same GUI switch
controls it. Human review HR-2026-10-08-TRIPLETEX-PROFILES and the number review were approved by Svein Tore on 2026-10-08.
