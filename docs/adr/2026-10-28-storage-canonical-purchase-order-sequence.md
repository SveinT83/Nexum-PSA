# ADR: Storage-Owned Canonical Purchase Order Sequence

Status: Accepted for authorized Issue #286 implementation
Date: 2026-10-28
Decision Makers: Svein Tore / Codex

Keep po_number as the generated immutable Nexum business identity, separate from record ID and
vendor_ref. Reuse the existing AllocatePurchaseOrderNumber entry point, backed by one locked
Storage counter and the existing unique index. Manual/API/import creation shares one transaction
and numbering lock; import no longer invents AUTO numbers. Retain existing legacy/soft-deleted
values without renumbering. Reject caller-selected creation values and locked update changes.

An ID-only allocator can collide with a pre-existing custom number. Independent per-path generators
and ordinary mutable references cannot satisfy the Issue's contract. A transactional counter gives
one explicit high-water state without changing supplier deduplication or introducing an external
number service. Preserve that used state on rollback. Model immutability guards ordinary saves;
reviewed exceptional database repair is a separate workflow, not a hidden API bypass.
