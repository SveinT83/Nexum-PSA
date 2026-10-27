# Lead Intelligence Browser/API Coverage Matrix

Date: 2026-10-27. Status: Implemented and verified on Dev; merged to Main in PR #343; manual review/production remain separate.
Authoritative Dev: /var/Projects/tdPSA; branch Dev; HEAD 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.
Baseline: 14 browser rows, 11 API operations, zero matching generated Swagger operations.
Result: all current workflows; exact 17 HTTP operations / 47 typed schemas verified.

API targets below are relative to /api/v1. Each planned row requires actor/token/record
authorization, strict inputs, typed Swagger and exact outcome/pure-read tests.

| ID | Browser route | Kind | API target | Current gap |
| --- | --- | --- | --- | --- |
| LI01 | tech.admin.settings.lead-intelligence | read | GET /lead-intelligence/settings; GET /lead-intelligence/context | Partial: actor/typed contract and context gaps |
| LI02 | tech.admin.settings.lead-intelligence.update | write | PATCH /lead-intelligence/settings | Partial: actor, endpoint validation, durable revision gaps |
| LI03 | tech.lead-intelligence.index | read/navigation | GET /lead-intelligence/context; GET /lead-segments | Redirect only; no mutation semantics despite route method list |
| LI04 | tech.lead-intelligence.runs.index | read | GET /lead-research-runs; GET /lead-intelligence/context | Missing list and form context |
| LI05 | tech.lead-intelligence.runs.store | write/action | POST /lead-research-runs | Partial: actor, shared mutation and dispatch/replay gaps |
| LI06 | tech.lead-intelligence.runs.show | read | GET /lead-research-runs/{run}; GET /lead-research-runs/{run}/evidence | Partial: missing Client/Contact identities and bounded evidence |
| LI07 | tech.lead-intelligence.scan-ledger.index | read | GET /lead-scan-ledger | Partial: actor, bounded inputs and typed contract gaps |
| LI08 | tech.lead-intelligence.segments.index | read | GET /lead-segments | Partial: browser count/context and typed authority gaps |
| LI09 | tech.lead-intelligence.segments.store | write | POST /lead-segments | Partial: nested validation, actor and replay gaps |
| LI10 | tech.lead-intelligence.segments.ai-draft | action | POST /lead-segments/ai-draft | Missing; provider/AI approval and durable unknown state required |
| LI11 | tech.lead-intelligence.segments.create | read | GET /lead-intelligence/context | Missing pure defaults/enums/lists/AI availability |
| LI12 | tech.lead-intelligence.segments.update | write | PATCH /lead-segments/{segment} | Partial: shared validation, revision and replay gaps |
| LI13 | tech.lead-intelligence.segments.edit | read | GET /lead-segments/{segment}; GET /lead-intelligence/context | Partial: missing editable context |
| LI14 | tech.lead-intelligence.segments.run-now | action | POST /lead-segments/{segment}/run-now | Missing; one queued job through shared pipeline required |

## Existing API-only capabilities

- POST /lead-intelligence/evaluate-contact: retain and verify scoped evidence, suppression, exact persisted eligibility and replay.
- POST /lead-intelligence/promote-candidate: retain read-only dry-run and actual promotion; cover Clients/provider numbering, Contact bridge and Marketing exclusions.
- GET /lead-intelligence/operations/{command}: planned safe command read-back, including ambiguous provider/dispatch outcomes.

Full runtime route middleware, source hashes and baseline evidence are in the companion JSON.
All LI01-LI14 rows now pass actual response/schema and domain behavior checks.
Final domain suite: 53 tests / 484 assertions, no failures/skips.
See 2026-10-27-lead-intelligence-api-verification.md for cross-domain verification and separate release gates.
An existing route is not evidence that authorization, safe writes or full parity is complete.
