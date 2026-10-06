# ADR: Separate NexumMCP Ownership

Status: Accepted
Date: 2026-10-01
Decision Makers: Svein Tore
Scope: Documentation of the user's product-direction decision; no runtime change.

## Current direction (2026-10-02)

Svein explicitly states that LiteLLM replaces NexumMCP and MCP work will be handled later.
PSA must deliver complete domain APIs so the external tooling can be built separately.
The original 2026-10-01 NexumMCP-specific consumer choice below is historical and superseded.
The decision to keep the MCP server outside PSA and preserve independent Vault/credential
ownership remains in force. No gateway deployment, credential transfer or API impersonation
is authorized or required by this clarification.

## Context

Svein states that a separate NexumMCP now exists and that Nexum PSA no longer needs
its own MCP server. Secrets in PSA no longer has the purpose of supporting a future
MCP server inside PSA. Earlier Integration Hub and Vault plans must reflect this.
Inspection of the authoritative Dev working copy found MCP references in documentation,
not a PSA product MCP server implementation. The older local Integration Hub RFC and
MCP roadmap are absent from Dev and must not be restored as current requirements.

## Decision

- The separate NexumMCP product owns the MCP server and its protocol surface.
- Remove the PSA-owned MCP server from PSA's target architecture and backlog.
- PSA remains responsible for its domain APIs, authorization, workflows and audit.
  External NexumMCP consumption of those APIs is still valid.
- Secrets/Vault in PSA is not a credential backend for a future PSA MCP server.
  Its technician/customer credential-management purpose and PSA integration needs
  remain independently valid; this decision does not remove Vault or existing secrets.
- MCP-only work is no longer a prerequisite for Vault completion. Evaluate queued
  Connection Broker/Execution work against concrete PSA needs before implementation;
  an old MCP requirement is not sufficient justification. Do not silently cancel
  independent PSA integration or account-security requirements.
- No automatic credential sharing, copying, migration, or plaintext disclosure to
  NexumMCP is introduced. A future cross-product credential arrangement requires a
  separately approved contract.
- Laravel Boost is development tooling, not a PSA product MCP server, and is retained.

This decision supersedes only the PSA-owned MCP and MCP-motivated credential-purpose
parts of earlier plans. The 2026-09-04 Vault ownership and runtime-secret-use ADRs
retain their independent PSA security contracts and historical rationale.

## Rationale

A separate MCP product removes the need to build a second MCP server in PSA and
prevents the credential workspace from depending on an obsolete product direction.

## Consequences

No application routes, permissions, database records, credentials, migrations,
queue jobs or runtime settings change. Vault's current onboarding blocker and
HR-2026-09-04-002 / HR-2026-09-04-003 remain unresolved. No review is marked complete.
This documentation-only change requires no deployment or new human-review gate.
GitHub discussions/issues are not updated by this change.

## Alternatives Considered

Retaining both PSA-owned and separate MCP servers was rejected by Svein's decision.
Deleting Vault was not requested and would remove an independent credential product.

## Follow-Up

Keep PSA API contracts provider-independent for later LiteLLM/MCP consumers. Before resuming queued runtime work,
record the remaining PSA-specific requirements and exclude MCP-only scope.
Any subsequent code, permission or integration redesign follows the RFC process.

## Verification

Read back the changed documentation and validate links and whitespace. Runtime tests
are not required for this documentation-only decision; no runtime changes are made.
