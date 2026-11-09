# Feature Slices: Customer Timebank Corrections

Status: Done On Dev - accepted by Svein Tore; BookStack publication explicitly deferred
Date: 2026-11-09
Parent: ../rfc/2026-11-09-timebank-adjustments-and-charge-reversals.md (Approved)
Owner: Codex. Approver/reviewer: Svein Tore.

## Goal
Deliver traceable commercial corrections while retaining employee work.

## User-Visible Behavior
Adjust period balance or reverse one customer charge with preview, reason and durable history.
Browser and official API use the same operations in every operational slice.

## Scope
1. Ledger/projection and writer invariants (Done On Dev): additive schema, canonical source,
   adjustment/reversal effects, financial evidence, serialized writers, invariant tests.
2. Operational workflow (Done On Dev): shared preview/apply/read-back, UI/API, permissions/scopes,
   Swagger, source entry links, durable retries and privacy.
3. Reports and acceptance (Done On Dev; BookStack deferred): gross/net reporting/exports, native contention, regressions,
   Knowledge/BookStack publication, coverage matrix and human-review evidence.

## Out Of Scope
Main promotion, automatic invoices/credits, payroll changes, pooling rules, partial reversals.

## Data Touched
Additive Commercial ledger, existing sources (retained), draft Economy state and read models.
No source minute edits/backfill, grants, provider/background activation.

## Permissions
Dedicated adjust/reverse authority plus existing Client/source/Task privacy and Economy rights.

## Tests
Dev unit/feature/API regressions, native concurrency, UI/Swagger and financial state checks.

## Documentation
RFC, ADR, TODO, HR-2026-11-09-TIMEBANK-CORRECTIONS; Knowledge and actual BookStack read-back.

## Done Criteria
All three slices verified on Dev, coverage matrix complete, docs and handbook verified or exact
external blocker recorded. Original work intact; named review recorded on 2026-11-09. Individual unreported scenarios remain documented.
Never expose operational controls before ledger-aware competing writers and native races pass.

## Verification and remaining owner

Dev migration batch53 and exposure are verified;300/3572 affected and14/126 correction tests
pass (overlap),15 OpenAPI payloads validate and native races/source retention/rollback pass.
Reports/exports are implemented and tested. The remaining external dependency is Knowledge/
BookStack synchronization/publication/read-back: supported connector twice returns ConnectError.
Svein explicitly deferred this documentation step on 2026-11-09; owner Svein / Integration resumes
when requested. HR-2026-11-09-TIMEBANK-CORRECTIONS is Reviewed. No Main/production/tool rollout or live correction.
See ../plans/2026-11-09-timebank-corrections-verification.md.

Svein Tore explicitly accepted the delivered Dev implementation on 2026-11-09: "Godkjenner. BookStack får vente".
