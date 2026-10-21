# ADR: Mail Browser And API Operational Parity

Status: Accepted
Date: 2026-10-20
Decision Makers: Svein Tore (full operational scope and ordinary Trash behavior);
Codex (technical implementation under the approved RFC)

## Context

Mail exposed fewer HTTP capabilities than its browser workspace and administration.
Independent controller implementations could drift in authorization, provider effects and outcomes.
Swagger omitted operations and most write contracts. The approved request requires full supported
read/write/action parity, including administration, with ordinary Delete moving to Trash.

## Decision

Keep one domain-owned route registry in Email/routes.php; the existing api.php loader only includes
that registry. Reuse shared domain queries, actions and administration services from browser and API.
Use existing granular Sanctum ability ceilings together with current human/domain/mailbox policies.
Serialize explicit safe resource/operational projections; credentials remain write-only and live
claim tokens/private storage paths never become response data. Preserve scalar progress map keys.

Ordinary Delete uses the remote-operation Trash action. Keep local personal state and optional
provider mirroring as separate outcomes. Ticket creation/provenance is atomic and locks the source
message as well as the selected placement. Stateful operations retain existing idempotency,
version, snapshot, confirmation, audit and read-back contracts.

Publish complete OpenAPI method/path/input/output/error/binary contracts and maintain explicit
browser-route/public-interface parity manifests checked by Laravel tests. Publishing the contract
does not enable existing default-off runtimes or widen existing tokens.

## Rationale

Shared actions make the browser and API execute the same supported domain behavior. Exact source
placement and current authority preserve mailbox privacy across canonical content and operator
inspection. Explicit outcomes avoid treating a queue receipt as completed provider work or delivery.
A route/interface register makes future parity omissions reviewable.

## Consequences

The API now covers administration and operational writes as well as reads. More typed routes and
contract definitions require upkeep. Existing callers keep their routes and previously documented
operation identifiers. Browser toggle forms map to explicit target state or the normal draft/
publication workflow. Real provider/human review remains required before promotion.

## Alternatives Considered

- Read-only inventory: does not meet the explicitly approved operational scope.
- Controllers calling browser redirects or a generic action dispatcher: obscures input and authority.
- Separate API business logic: permits workflow and policy drift.
- Enabling gated collaboration/live functionality for API tests: changes product rollout authority.

## Follow-Up

RFC: ../rfc/2026-10-20-mail-full-api-parity.md.
Evidence: ../plans/2026-10-20-mail-api-verification.md.
HR-2026-10-20-MAIL-API is Reviewed by Svein Tore on 2026-10-21, following explicit chat approval.
Main promotion, deployment and release remain separate actions; independent runtime gates remain.
No new migration, package, runtime switch or infrastructure component is introduced.

## Production Delivery (2026-10-21)

Application release 11cf40227e9dfa52747b96cdf58e5f64ae706f43 was merged and deployed under Svein Tore's explicit request.
All 175 Mail API contracts are published in production, including 109 write/actions. Trusted HTTPS,
source hashes and an authenticated Mail context read were independently verified. Existing runtime
flags remain false; no new migration, provider write, token widening or recipient delivery test.
See docs/plans/2026-10-21-mail-api-production-deployment.md for backup and deployment evidence.
