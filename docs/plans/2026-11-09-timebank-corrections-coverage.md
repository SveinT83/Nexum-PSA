# Customer Timebank Correction Coverage

Status: Implemented On Dev / Automated Verification Passed / Human Reviewed / BookStack Deferred.
Date: 2026-11-09. Owner: Codex. Approver/reviewer: Svein Tore.
RFC: ../rfc/2026-11-09-timebank-adjustments-and-charge-reversals.md.
HR: HR-2026-11-09-TIMEBANK-CORRECTIONS (Reviewed by Svein Tore, 2026-11-09).

## Browser/API coverage

All browser routes are in Commercial/routes.php. All commands use TimebankCorrectionWorkflow.
No HTML/controller wrapping or direct gateway database operation.

| ID | Browser surface | Official API | Classification / authority | Evidence |
| --- | --- | --- | --- | --- |
| TC01 | Client Contracts or Time Usage -> corrections/history, historical bank selection | Existing GET timebank + GET history | Read; client.view + commercial.timebank.view, source/private Task visibility | browser workflow test; history/source tests |
| TC02 | Preview restore/zero/custom target | POST adjustment-preview | Action, pure read; commercial.timebank.adjust ability + native adjust right | signed target/zero-overuse/stale tests; native preview |
| TC03 | Confirm adjustment | POST adjustments; GET corrections/{id}; GET operations/{id} | Write/read-back; same actor/target/input-bound preview, If-Match and Idempotency-Key | durable replay/stale/revoked/expiry tests; native two-writer races |
| TC04 | Client/report/Ticket/Task named billing entry -> Preview reversal | POST reversal-preview | Action, pure read; reverse ability/right plus exact source/Task privacy | Task actual-versus-billing test; finalized/unknown financial blockers |
| TC05 | Confirm full customer-charge reversal | POST reversals; GET corrections/{id}; GET operations/{id} | Write/read-back; Economy order right + economy.update token ability for draft correction | retained source/draft evidence and native unique/reversal races |
| TC06 | Source status, edit controls and correction history | Existing exact consumption/Ticket reads, GET history/corrections | Read; source-native policy still applies | exact read-back + immutable source/parent deletion tests |
| TC07 | Report and XLSX/PDF, gross registered work and net customer effects | Existing report summary/items/entries/export | Read/export; existing report conjunction/private Task rules | Task no-double-counting; report/export regressions; correction history sheet |

## Schemas and discovery

Seven new official HTTP operations, eleven new typed schemas, explicit ability descriptors,
conditional Economy scope/native rights and current source-visibility requirements.
OpenAPI input schemas reject unknown fields; command schemas require preview_token.
Fifteen actual native/HTTP payloads validate against generated schemas with Ajv, including
all seven new operations and affected context/source/report responses. Source status and balance/report fields are additive.
Commercial api-contract.json/api-schemas.json and existing browser inventory are updated.

## Native verification

See timebank-corrections-native-evidence.json for two-process same-key replay, different-key
conflict, full reversal replay, competing real quick consumption, unique source reversal,
retained source/parent foreign keys, generation exclusion and complete transaction rollback.
Original employee work is unchanged. No operational customer source was used.

## Runtime and remaining checks

Feature exposure is enabled on Dev after migration/verification; source default remains off elsewhere. No automatic rights grants,
provider/queue/scheduler activation, Main change, commit/push or production deployment.
BookStack connector search failed with ConnectError: All connection attempts failed.
Owner Svein Tore / Integration: restore the supported gateway route, publish the changed
Contract System and Commercial API Knowledge sources, read back content and existing employee
access. Employee OpenWebUI retrieval remains a separate human check.

## Acceptance and approved deferral

Svein Tore explicitly accepted the delivered Dev implementation on 2026-11-09: "Godkjenner. BookStack får vente".
BookStack publication/read-back and employee retrieval remain incomplete and deferred; no retry
or publication is performed. Future Main/production/gateway actions require separate authorization.
