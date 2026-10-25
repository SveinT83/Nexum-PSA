# ADR: Client commands, source locking and durable provider intent

Status: Accepted
Date: 2026-10-25
Decision makers: Codex under Svein Tore's approved Clients API RFC
Parent: ../rfc/2026-10-25-clients-full-api-parity.md

## Decision
Preserve the existing Clients namespace. Domain routes belong to routes.php; the
existing api.php becomes a loader. Token grants and freshly loaded actor rights
are checked independently. Portal licence APIs use the active session membership.

Share Notes, settings, Site persistence/deletion, source correction and staff/portal
licence Actions with browser adapters. Local keyed commands use a database mutex,
durable encrypted minimal references and actor/token/payload binding. Replays
re-project records through current authority rather than returning private snapshots.

External commands commit intent before provider transport and never automatically
repeat an uncertain call. Per-Client resource leases serialize unresolved licence
commands. RMM provisioning retains a unique integration/target intent and verifies
the returned identity through read-only lookup before confirming the mapping.
Reconciliation uses only GET and an explicitly selected exact provider identity.

Client source correction uses EconomyMutex, the same durable boundary as order
generation. Actual Task time and Ticket billing stay distinct. Existing ordered
and coupled-entry guards remain authoritative.

API provider writes have an independent default-off switch. No API operation
changes connection settings, activates provider sync, widens tokens or sends
unrequested customer messages. Existing Tripletex number/profile authority is reused.

## Consequences
The exact additive migration is
2026_10_25_220000_create_client_api_command_records.php. It adds only command,
mutex and RMM intent evidence. Apply it before using keyed commands/provisioning.
Preserve nonempty evidence and encryption keys on rollback. Human review, native
migration/lock probes, trusted HTTP/Swagger and handbook verification remain required.

## Alternatives
Raw controller delegation, provider calls inside local transactions, token-only
authority and replay of unrestricted cached responses were rejected because they
cannot supply the approved operational contract.


Implementation clarification: the approved non-cascading Site delete requirement
also protects legacy cascade/null-on-delete references. Native schema inspection
confirmed Client User/Documentation cascades and Site-scoped portal membership
nulling. Shared deletion refuses these referenced Sites rather than deleting history
or silently expanding portal scope. RMM intent creation and deletion use the same
Client/Site lock order. Move/review linked records through their owner workflows first.
