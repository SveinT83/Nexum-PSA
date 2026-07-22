# ADR: Integration-Owned Commercial Record Lifecycle

Status: Accepted
Date: 2026-07-21
Decision Makers: Svein Tore / Codex
Related: `docs/adr/2026-07-21-cloudfactory-service-variant-identity.md`

## Context

CloudFactory creates ordinary Nexum Services and Costs, but the existing implementation records only
a source label and a Cost-specific managed flag. A Service is not directly linked to its Integration,
can still be edited or deleted, and a Cost remains locked even after the Integration is disabled.
This does not provide safe active ownership or a reusable handover path to a future provider such as
Pax8.

Nexum must retain commercial master data and accounting history even when a provider connection ends.
At the same time, an active source Integration must remain authoritative for synchronized fields.

## Decision

Services and Costs receive a nullable generic `source_integration_id` and an explicit
`managed_externally` flag. CloudFactory sets both fields whenever it creates or refreshes a record.
The existing `source` value remains historical provenance.

A row is locked only while all of these are true: it is marked externally managed, its source
Integration still exists, and that Integration has active status. Active ownership blocks manual
price or record changes and deletion at the server boundary. The UI shows the provider, managed state,
and a link to the active Integration settings when a provider-specific route exists.

Disabling, revoking, or deleting the Integration never deletes the Service, Cost, their normal
Service-Cost relation, contract snapshots, or accounting history. A disabled or missing source
Integration releases the rows for ordinary Nexum editing while retaining source provenance. A later
Integration can take ownership by assigning its own source Integration id and managed state during a
verified import or mapping workflow.

CloudFactory source offers remain integration staging records. They may be removed with the
Integration, but the canonical Commercial records use nullable source ownership and survive.

## Rationale

This makes Nexum the durable commercial system of record without allowing two systems to edit the
same active prices. Generic ownership avoids CloudFactory-specific columns in Commercial and provides
the required foundation for Pax8 or another provider.

## Consequences

- Every imported Service and Cost has explicit, queryable Integration ownership.
- Active Integration-owned records are visibly read-only and cannot be deleted.
- Revocation immediately makes retained records editable without a destructive conversion job.
- Reconnecting the same Integration restores ownership on its records; synchronization refreshes the
  ownership fields before changing prices.
- Future provider takeover needs a controlled mapping workflow, but no schema redesign.
- Historical `source` remains visible after release and is not treated as an active lock.

## Alternatives Considered

- Permanently lock records by source label: rejected because data must remain usable after disconnect.
- Delete imported records on disconnect: rejected because contracts, profitability, and accounting
  history depend on them.
- Store ownership only through CloudFactory offers: rejected because offers are provider staging data
  and may be deleted with the Integration.
- Use CloudFactory-specific foreign keys on Commercial records: rejected because Pax8 and other
  providers must use the same lifecycle.

## Follow-Up

Keep `HR-2026-07-20-001` open until an active CloudFactory Service and Cost show the source link and
server-enforced locks, and the same retained rows become editable after a controlled disconnect test.
