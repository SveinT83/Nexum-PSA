# ADR: Storage Procurement And Documentation Carrier Ownership

Status: Accepted
Date: 2026-08-04
Decision Makers: Svein Tore / Codex

## Context

The approved Storage purchase-order, shipment, and receiving workflow needs structured carrier
configuration for safe tracking links. Documentation already owns canonical Vendor and Supplier
master data, while Storage owns stock and procurement operations. Carrier profiles also need to
remain useful after a carrier is renamed, deactivated, acquired, or moved to another tracking
system.

A boundary was needed so carrier master data, operational shipments, integration credentials, and
historical tracking are not mixed into one mutable record.

## Decision

Documentation owns the `shipping_carriers` master-data table, `ShippingCarrier` model, fixed Tech
register, carrier seeder, and safe tracking-link configuration rules.

Storage owns purchase orders, shipment records, tracking identifiers, carrier configuration
snapshots, receipts, and every inventory effect. A shipment may reference the current carrier by a
nullable foreign key, but it snapshots the carrier identity and tracking configuration used at the
time of shipment.

Integration will own credentials, polling, booking, webhooks, and provider adapters only when a
later RFC or Feature Slice approves those capabilities. Documentation carrier records contain an
optional connector identifier but never secrets.

The shared tracking-link resolver accepts either a current `ShippingCarrier` or scalar snapshot
values. It returns browser links only after HTTPS and allowed-host checks. Nexum does not fetch
carrier URLs server-side.

## Rationale

Carrier identity and tracking policy are reusable master data similar to Vendors and Suppliers, so
Documentation is the natural owner. Procurement lifecycle and inventory posting remain Storage
business rules and must not depend on free-form Documentation records.

Snapshotting prevents later administrative edits from rewriting historical shipment behavior.
Keeping credentials in Integration preserves one security boundary for external providers and
avoids turning a Documentation form into a secret store.

## Consequences

- Documentation gains a dedicated structured carrier register and permission.
- Storage can select active carrier profiles while retaining historical snapshots for legacy or
  inactive carriers.
- Posten/Bring and the DHL divisions can remain distinct even if technical adapters are shared.
- Carrier profile edits do not repair or rewrite old shipment snapshots automatically.
- A later connector requires an Integration-owned design and cannot add secrets to
  `shipping_carriers`.
- Cross-module tests must verify the nullable foreign key and snapshot-safe resolver contract.

## Alternatives Considered

Storing carriers as dynamic Documentation templates was rejected because shipment code needs a
stable schema, relations, validation, and query contract.

Adding carrier fields to Vendors was rejected because one legal organization can expose several
transport divisions and tracking systems.

Letting Storage own carrier profiles was rejected because carrier identity is reusable master data
and would duplicate Documentation's existing partner-register responsibility.

## Follow-Up

Implement the ordered Storage shipment and receiving Feature Slices under the approved RFC. Add
Integration adapters only through a separately approved slice. Reverify seeded official source and
tracking URLs as part of release review and when carriers change systems or ownership.
