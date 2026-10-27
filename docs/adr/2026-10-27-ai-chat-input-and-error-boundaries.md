# ADR: Reject Chat Credentials And Normalize Provider Failures

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (approved Issue completion), Codex (technical defaults)

## Context

See the approved RFC and Issues #269/#278. Persistence, session flashing and Livewire
snapshots precede egress policy, so egress redaction alone cannot protect chat input.
Provider failures have multiple persistence/structured consumers beyond rendered chat.

## Decision

Use one reusable pre-persistence detector at both chat input entry points, before ordinary
validation, plus safe rejection state. Keep authorized destination guidance separate from
detection so it cannot establish permanent Integration ownership of credentials.
Use a central safe provider failure contract with allowlisted category/status metadata and
no retained raw exception/response. Apply it at shared execution/result boundaries and chat
status, preserving existing fallback attempts and structured result semantics.

## Rationale

These boundaries protect every existing copy while retaining current domain authority and
successful workflows, without introducing a secret vault or a second provider runtime.

## Consequences

Some credential-like examples must be reworded rather than submitted verbatim. Unlabelled
opaque text is not reliably identifiable; chat remains forbidden for credentials. Safe
errors provide correlation instead of raw provider diagnostics. No migration is necessary.

## Alternatives Considered

Provider-only redaction leaves persisted chat/session data. Chat-only error filtering leaves
structured outcomes, failed jobs and telemetry. Generic string truncation preserves secrets.

## Follow-Up

Complete both RFC slices, negative/compatibility tests and handbook read-back. Keep
HR-2026-10-27-AI-INPUT-ERRORS Pending until named human review; production remains separate.
