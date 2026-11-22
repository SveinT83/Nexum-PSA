# ADR: Identified registered-time reporting with domain-owned sources

Status: Accepted
Date: 2026-11-22
Decision Makers: Codex under Svein Tore's approved reporting RFC

## Context
Employee reporting needs identified Ticket/Task work across Clients. Existing Worklog is a
policy-bound pseudonymized coordinator surface. Client time reporting includes financial measures.

## Decision
Report orchestrates a read-only work projection from Ticket/Task-owned queries. Count direct
Ticket entries and Task entries exactly once; exclude Task-linked Ticket billing projections.
Use current native source rights, Task privacy and context invariants. Identified APIs require
dedicated personal read/export abilities and reject coordinator-bound tokens, including wildcards.
Use the existing internal identity rule (human with a native role or direct permission) for
employee lookup; status may be disabled for historical attribution.
Keep Workday oversight authorization and recorded-time semantics unchanged.

## Rationale
This reuses source authority while avoiding financial double counting and coordinator policy bypass.
Directory queries do not depend on time rows or selected dates. Suggestions use stable IDs.

## Consequences
No schema or provider changes. Bounded materialization supplies consistent full-filter totals,
grouping, paging and exports. Oversized windows fail explicitly. API/browser share the query.
Employee-confirmed payroll, invoice totals and source registration provenance remain distinct.

## Alternatives Considered
Reusing coordinator Worklog would change its audience/policy contract. Looping over all Client
financial reports would mix financial work and scale poorly. Summing all Ticket/Task rows double counts.

## Follow-Up
RFC 2026-11-22-employee-suggestions-and-ticket-task-time-report; HR-2026-11-22-TIME-REPORTS.
