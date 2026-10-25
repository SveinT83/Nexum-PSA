# Feature Slice: Public, Portal and timebank workflows

Status: Complete on Dev; human review Reviewed (Svein Tore, 2026-10-25)
Main promotion: [PR #313](https://github.com/SveinT83/Nexum-PSA/pull/313), d1e688a2ff6e794dd15629db89d720405548dce1; verified 2026-10-25.
Production deployment and controlled delivery activation remain separate.
Date: 2026-10-25
Parent: ../rfc/2026-10-25-commercial-full-api-parity.md (Approved by Svein Tore).
Owner: Codex
Human review: HR-2026-10-25-COMMERCIAL-API (Reviewed by Svein Tore on 2026-10-25; promotion remains separate).

## Goal

Deliver C07-C08, C17-C18 under the full approved API target.

## User-Visible Behavior

The supported browser operations in this scope have typed APIs with equivalent current rights,
validation, persisted result and side effects. No runtime or provider is activated automatically.

## Scope

C07-C08, C17-C18. Preserve existing module ownership and shared dirty work.

## Out Of Scope

Main/production, live customer/provider operations, invented business behavior, unrelated changes.

## Data Touched

Commercial records, additive receipt/delivery metadata where required; shared loader/ability/docs
only through additive scoped edits. No historical snapshot rewrite.

## Permissions

Token ceiling plus current active actor/domain/object/field and actual Admin policy. Public/Portal
identity never derives from staff rights. Preserve source Integration and legacy evidence guards.

## Tests

Meaningful allow/deny, typed input, persisted read-back, concurrency/retry and relevant side-effect
regressions on authoritative Dev. Compare affected browser/shared actions; record exact evidence.

## Documentation

Update module Knowledge, OpenAPI, matrix, verification report and TODO. Publish/read back affected
BookStack articles before claiming final completion. Employee assistant retrieval is separate.

## Done Criteria

All scoped workflows and fields pass relevant tests/read-back, contracts/docs are accurate,
all failures are resolved or concretely blocked, and remaining human checks are explicit.


## Current Verification - 25 October 2026

Implemented on authoritative Dev. Complete Commercial/portal suite: 129 tests / 1898 assertions;
broader affected suite: 113 / 1104. All pass; four existing SLA PHPDoc metadata warnings.
Route/schema/new-web-inventory checks and 68 scoped syntax/Pint checks pass.
Native migration batch 23, two-connection lock/unique probes, seven authenticated HTTPS reads,
exact Swagger and eight internal Knowledge matches pass. Eight BookStack pages/sections read back
with staff access. Earlier database outage resolved. Delivery switches remain default-off.
HR-2026-10-25-COMMERCIAL-API is Reviewed: Svein Tore accepted the completed delivery with "Godkjent".
No Main/production deployment or real send occurred; controlled activation remains separate.
See ../plans/2026-10-25-commercial-api-verification.md.
