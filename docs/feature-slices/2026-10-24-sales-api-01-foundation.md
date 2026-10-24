# Feature Slice: Query, actor, input, route and durable command foundation

Status: Merged To Main / Human Reviewed
Date: 2026-10-24
Parent: ../rfc/2026-10-24-sales-full-api-parity.md (Approved by Svein Tore in chat)
Owner: Codex. Human review: HR-2026-10-24-SALES-API.

## Goal

Read/context authorization, pure reads, strict fields, revision/replay receipts and route contracts.

## User-Visible Behavior

The corresponding existing Sales browser workflows are operational through typed official APIs with identical effective authority and business rules.

## Scope

Read/context authorization, pure reads, strict fields, revision/replay receipts and route contracts. Preserve current paths and unrelated dirty work.

## Out Of Scope

No new future CPQ behavior, permission widening, Main/production rollout or real customer/provider mutation.

## Data Touched

Sales and the owning-domain contracts listed in the approved RFC; receipt/delivery metadata is additive and retains evidence.

## Permissions

Current active actor, token, role, record, parent and field authority. Public capability and portal membership remain separate.

## Tests

Meaningful positive/negative feature tests, strict fields, pure reads, concurrency/replay where applicable, exact read-back and affected owning-module regressions.

## Documentation

Update Sales Knowledge, API/field matrix, verification report and live TODO. Publish/read back affected employee articles through the supported path.

## Done Criteria

- [x] All scoped supported workflows implemented and typed.
- [x] Relevant automated checks pass on authoritative Dev.
- [x] Source/generated OpenAPI and exact route/field contracts agree.
- [x] Docs and employee publication/read-back current, or exact owner/blocker recorded.
- [x] Human-review checklist updated; approval not inferred from tests.

## Verification and handoff

Implementation and automated evidence: [Sales API verification](../plans/2026-10-24-sales-api-verification.md).
The checked implementation criteria do not mark human acceptance Reviewed.
HR-2026-10-24-SALES-API is Reviewed; Main merge is approved, production remains separate.

## Human acceptance (2026-10-25)

Svein Tore approved the delivered scope and Main merge in chat: "Godkjent. Merg til main".
HR-2026-10-24-SALES-API is Reviewed. The earlier pending-review wording records the
2026-10-24 implementation handoff; this acceptance clears the Main merge gate.
Production deployment, provider activation and real message delivery remain separate.

Main implementation merge independently verified on 2026-10-25: [PR #311](https://github.com/SveinT83/Nexum-PSA/pull/311),
commit `81812c5b8989dcb3522245b87e69390fb57a2141`. GitHub and origin/main agree; the complete merge tree
equals the tested final candidate e5d4c989abc8bf64a39235d83672bcce696f0f17.
Dev HEAD/index and unrelated work are preserved. Production deployment and activation
were not performed.
