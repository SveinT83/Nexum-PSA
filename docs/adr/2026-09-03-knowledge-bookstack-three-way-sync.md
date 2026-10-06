# ADR: Knowledge And BookStack Three-Way Synchronization

Status: Accepted
Implementation: Done On Dev (2026-09-03)
Date: 2026-09-03
Decision Makers: Svein / Codex
Related RFC: `docs/rfc/2026-09-03-knowledge-bookstack-revision-safe-sync.md`

## Context

A single mutable source checksum cannot distinguish a clean provider fast-forward from concurrent
Nexum and BookStack edits. It also cannot prove that the provider stored the exact revision a
queued job intended to send.

## Decision

Nexum is the authoritative repository. Synchronization uses:

- immutable article revision snapshots with stable canonical hashes;
- one per-article BookStack state containing the last proven common local/remote hashes;
- three-way comparison of current local, current remote, and last proven base;
- explicit candidate/conflict states instead of automatic merge or overwrite;
- exact outbound revision binding plus independent provider read-back;
- state-preserving idempotent retries and explicit handling of unknown baselines and deletion.

The canonical hash includes the user-visible content and BookStack placement that the integration
can synchronize. Existing articles start with unknown history unless equality is proven after the
migration.

## Rationale

Three-way comparison is the smallest model that can distinguish clean one-sided changes from
divergence. Immutable snapshots make operator review and stale-job detection deterministic.
Provider read-back proves the stored result instead of relying on an ambiguous write response.

## Consequences

- Pull may create a review candidate where the previous integration would overwrite content.
- Automatic clean inbound synchronization is a separate, default-off policy.
- A provider deletion never deletes the Nexum article automatically.
- Push can report failure after provider acceptance when read-back cannot prove the result; retry
  recovery prevents duplicate creates.
- Revision and sync-state storage grows with actual sync-relevant changes.
- The design supplies revision identity for future Issue #266 work but does not implement that
  broader approval/version lifecycle.

## Alternatives Considered

- Last-writer-wins was rejected because it loses edits.
- Timestamp comparison was rejected because clocks and provider timestamps do not prove ancestry.
- Automatic text merge was rejected because Markdown structure and operational instructions need
  human judgment.
- Treating every existing source checksum as a known base was rejected because pre-migration
  history cannot be proven.

## Follow-Up

Complete human review `HR-2026-09-03-003` before Main promotion, production migration,
deployment, or enabling automatic clean inbound synchronization.
