# Economy API Main promotion - 25 October 2026

Status: Human Reviewed; isolated Main candidate verified; authorized merge in progress. Production is not deployed.
Reviewer/release owner: Svein Tore. Implementation/reconciliation owner: Codex.

## Authorization and scope

Svein Tore accepted the completed Dev delivery and requested: "Godkjent. Merge til main og foreslå neste domain som mangler api".
HR-2026-10-25-ECONOMY-API is Reviewed. Historical scenario boxes are retained without claiming
individually witnessed execution. Production deployment and external activation are separate.

The isolated candidate is based on origin/main 3c65053eeff167973725b9a0a8a653bbf5abce3e,
branch codex/economy-api-20261025. The authoritative shared Dev HEAD/index remain unchanged.
The task snapshot, not the whole dirty working copy, defines shared API loader/catalog/controller
changes. Unrelated Ticket/Task abilities and route loaders are deliberately excluded.

Forty scoped PHP files implement 23 supported operations and 34 Economy schemas. The existing
GenerateOrders financial-client allocation fix is a necessary preserved dependency: already
allocated time remains with its recorded client after a Ticket move. No other pre-task Economy
file differs from Main outside the documented scope. Tests use isolated candidate autoload and
synthetic SQLite configuration; no production credentials or provider writes are used.

## Verification

All 40 scoped PHP syntax and Pint checks pass. Production Swagger regeneration succeeds and
other domains' paths and schemas remain structurally unchanged. An initial stale generated
Swagger assertion was resolved by regeneration; no runtime code change was needed. One invocation
used a nonexistent test filename and performed no tests; the corrected affected run is authoritative.
Candidate class reflection resolves candidate Economy and DataExchange files, not shared Dev.

The native additive Economy migration was previously applied/read back on Dev as batch 24.
Native lock/unique probes, trusted HTTPS read/denial/Swagger and six BookStack employee-scope
read-backs are recorded in the linked original verification evidence. They are not production evidence.

## Promotion and deployment boundary

Fresh exact candidate: 428 affected tests / 4904 assertions pass, including Economy/DataExchange,
all Commercial/Portal features, Ticket/Storage workflows, CloudFactory, API administration and
production-mode Swagger scan. No failure is deferred. See economy-api-main-candidate-tests.json.
Push/PR/merge and exact merge-tree comparison will be recorded after successful promotion.
Do not deploy or activate real billing, notifications, provider delivery or scheduler work from
this document alone. On a separately approved deployment, run the exact Economy additive migration,
regenerate Swagger, clear relevant caches and gracefully refresh affected workers as documented
in 2026-10-25-economy-api-verification.md. Preserve durable command/source/notification evidence.
Existing pending Worklog/Calendar operational reviews remain separate; their implementations
and default-off activation gates already on Main are not changed by this candidate.

## Proposed next domain

Read-only route and workflow audit finds DataExchange has six official API entry points against
13 browser routes plus the Livewire ProfileBuilder. Its existing API lists profiles, triggers and
reads runs, downloads files, and previews/commits imports. It does not expose profile creation/editing
or full builder configuration, schedule management, delivery-target management, or GET of a stored
import preview with its review results. These are existing browser workflows, not new provider ideas.
Recommend a full DataExchange parity RFC/matrix next, preserving Economy source policy and
Integration-owned credentials. No next-domain implementation or new RFC was started. Existing
Tripletex-related dirty models/services are outside this audit and must be reconciled before coding.
