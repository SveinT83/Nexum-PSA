# ADR: Sales CPQ Accepted Snapshot Boundary

Status: Accepted
Date: 2026-08-11
Decision Makers: Svein Tore, Codex

## Context

Discussion #170 requires CPQ quotes to store exactly what the customer accepted while preserving
module ownership. Sales owns opportunities and quotes, but Economy owns orders/invoices, Commercial
owns contracts/catalog policy, Ticket owns tickets, and Storage/Asset/Task/ServiceVisit own their own
operational records.

## Decision

Sales will store the immutable accepted CPQ snapshot and Sales-owned conversion-plan rows. Acceptance
does not directly mutate Economy, Commercial, Ticket, ServiceVisit, Task, Storage, Asset, or Project
records unless an existing owner-domain action is already part of the current workflow. Ticket-origin
quotes are the first approved owner-domain exception: Ticket owns the action that processes accepted
Ticket planned lines into safe reservations, draft purchase needs, or pending Ticket costs, and Ticket
owns accepted-quote voiding when those downstream records are still reversible.

## Rationale

This keeps the commercial evidence and dispute-safe quote acceptance in one Sales-owned place while
preventing Sales from bypassing domain-specific validations, permissions, workflow states, stock rules,
contract policy, invoice rules, or future idempotency contracts. It also lets later domain-specific
conversion slices consume one stable Sales plan instead of re-parsing mutable quote lines.

## Consequences

Accepted quotes get durable selected-line, declined-line, quantity, acknowledgement, text, price, VAT,
margin-safe customer, and identity snapshots. Conversion becomes explicit and auditable, but Sales
does not automatically create every downstream record. Each target domain still needs an
owner-approved conversion action before real mutation. Ticket-origin automatic processing and voiding
are audited Ticket actions and never pick stock, send vendor orders, post receipts, or bill Economy
orders.

## Alternatives Considered

Directly create Economy orders, Commercial contracts, Tickets, Tasks, and Storage reservations during
quote acceptance. This was rejected because it would couple Sales acceptance to too many domain rules
and create hidden side effects.

Store only JSON on `sales_quote_versions`. This was rejected because conversion status and future
idempotent downstream actions need queryable rows.

## Follow-Up

Add owner-domain conversion slices for Economy order and Commercial contract first, followed by
Task/ServiceVisit hooks and any broader Storage/Asset reservation or procurement handling that should
exist outside Ticket-owned delivery.
