# Timebank Corrections: Verification And Production Handoff

Status: Main Merged / Production Migrated And Enabled / Human Reviewed /
BookStack explicitly deferred on 2026-11-09.
Date: 2026-11-09. Owner: Codex. Product approver/reviewer and promotion owner: Svein Tore.

## Delivered behavior

- Client Contracts -> Adjust balance / history, or Time Usage -> Timebank corrections and history.
- Restore allowance, zero remaining balance or set custom remaining integer minutes, with
  reason, exact period, signed before/after and a reviewed confirmation.
- Full customer-charge reversal from named Client/report/Ticket/Task billing entries. Original
  source minutes/date/person/notes remain; actual Task work and payroll/Workday evidence persist.
- Append-only correction/effects, canonical source uniqueness and restrictive foreign keys.
  Parent deletion cannot cascade away the original source or correction period.
- Pending generation and Task billing totals retain original source identity and do not recreate
  reversed charges. Draft financial correction retains original line/allocation evidence and
  updates active-line totals. Finalized/exported/unknown economics block the whole correction.
- Browser and official API share previews, policy, transaction boundary, durable command receipts
  and exact read-back. Conditional Economy authority is rechecked on replay; financial record
  references are redacted when the current reader lacks that authority.
- Registered work remains gross; customer measures are net of reversals. Reports show linked
  correction history; real XLSX includes a Corrections sheet and PDF renders corrected history.
  Balance meters use effective allowance, while original allowance/usage remain visible.

## API coverage

Seven new operations under /api/v1/commercial/clients/{client}/timebank:
GET history; POST adjustment-preview/adjustments/reversal-preview/reversals;
GET corrections/{correction} and operations/{operation}.
Existing context, exact consumption/Ticket reads and report APIs gain correction-aware fields.
Eleven typed schemas, new adjust/reverse abilities, explicit current native rights and nested
source/private Task policies. Command inputs require preview_token, If-Match and Idempotency-Key.
See 2026-11-09-timebank-corrections-coverage.md for the browser/action/API/policy evidence map.

## Verification

- Affected module matrix: 300 tests / 3572 assertions, zero failures.
  Commercial feature suite, Clients time report/browser/API, Economy feature suite,
  Task operation/module, Ticket ledger/deletion and Workday source/manual workflows.
- Final correction suite after conditional-authority and balance-meter hardening:
  14 tests / 126 assertions, zero failures (overlaps the broad matrix; do not add totals).
  Includes actual XLSX reopen, numeric gross/net/correction cells and PDF signature/rendering.
- 37 changed non-Blade PHP files pass syntax checks; new files pass Pint. Web-process file
  readability/directory traversal checked. Existing shared dirty work is preserved.
- 15 payloads validate against generated OpenAPI with Ajv: three actual native workflow
  payloads and twelve actual HTTP test responses covering all seven operations, context,
  exact source read-back and report summary/entries. Legacy nullable source-rate references
  and Laravel paginator page fields were corrected in schemas.
- Focused MariaDB 10.11.19 test uses a private UNIX socket with networking disabled, synthetic
  fixtures and exact current command/ledger migrations. Same-key two-process replay creates
  one correction; competing distinct keys produce one success/one stale conflict.
  Reversal replay, a real concurrent quick-consumption action, unique source reversal,
  immutable source and parent FK retention, generation exclusion and full rollback pass.
  Supporting fixture column types are normalized from SQLite; this does not certify every
  historical migration. See 2026-11-09-timebank-corrections-native-evidence.json.
- Trusted HTTPS on Dev: login 200; unauthenticated correction history 401. Actual employee
  browser/2FA/visual acceptance remains a human check; automated browser workflow is verified.
- The full application suite was not run; the affected-domain matrix above is the scope.

## Dev runtime state

Authoritative copy: /var/Projects/tdPSA, branch Dev.
Exact migration 2026_11_09_120000_create_timebank_corrections ran in Dev batch 53.
New head/effect foreign keys: 3 / 2. Both source ledger-sequence columns exist.
COMMERCIAL_TIMEBANK_CORRECTIONS_ENABLED=true is set only in ignored Dev .env; source default
remains false for other installations. No credentials were copied/printed.
Two permission definitions exist; read-back found zero new explicit role or direct-user grants.
Existing privileged fallback remains unchanged. Runtime config/routes/views were cleared with
umask 0002. No frontend build/dependency change is needed; no workers or scheduler were activated.
Read-back found zero correction/effect rows on Dev: no customer reset or reversal was executed.
The disposable native database server was shut down after testing.

No commit/push, Main change, production deployment, gateway publication, financial-provider write, email
send or real employee/customer financial correction was performed by this task.

## Handbook and remaining external dependency

Authoritative Markdown sources updated:
app/Modules/Commercial/Docs/knowledge/contract-system.md and commercial-api.md.
Targeted Knowledge source synchronization and actual BookStack publication/read-back are NOT
complete. The supported BookStack connector failed twice with:
ConnectError: All connection attempts failed (including a retry after the broad test load ended).
No article write or ACL change was attempted; no employee access or assistant retrieval is claimed.
Owner Svein Tore / Integration: restore the approved gateway connection, synchronize the two
affected source articles, publish to the existing mappings, read back exact content and existing
employee scope. Employee OpenWebUI retrieval is a separate acceptance check.
Svein explicitly deferred this documentation step on 2026-11-09. The Dev delivery is accepted;
the handbook is not represented as published/current. Resume the deferred step when requested.

## Human review: HR-2026-11-09-TIMEBANK-CORRECTIONS

Status: Reviewed by Svein Tore, 2026-11-09. This is a human-review checklist entry.
Overall delivered Dev acceptance is explicit; individual scenarios below were not separately
reported as executed. Retain them as the original review scope; future environment/tool acceptance
remains separate from this approval:

1. Restore allowance, zero an overdrawn bank and set a custom balance; check before/after,
   effective period, reason, actor and retained original usage.
2. Reverse direct quick/Ticket charges and a Task billing projection; employee work and
   Workday/payroll evidence must stay unchanged.
3. Confirm source edit/deletion protection, duplicate/retry behavior, expired/stale previews
   and revoked native/token authority.
4. Check draft Economy totals, retained original line/allocation and generation suppression.
   Finalized/exported/unknown financial state must block apply without a partial bank change.
5. Compare browser and official API read-back, gross/net report totals, XLSX/PDF and private
   Task visibility. No Task/Ticket work double counting or cross-period credit transfer.
6. After supported handbook/tool publication, verify intended employee/assistant retrieval
   and fresh-chat discovery separately.

The authoritative persistent checklist is docs/human-review.md on Dev. No individual box was marked complete automatically. Earlier report/rollout human-review entries
remain open and unchanged.

## Promotion and rollback

Svein owns any later commit/push, Main promotion and production rollout. Before an authorized
rollout, re-read human-review.md and apply the exact additive migration before exposing writes:

    php artisan migrate --path=database/migrations/2026_11_09_120000_create_timebank_corrections.php --force
    php artisan optimize:clear
    php artisan l5-swagger:generate

Set the correction flag only for the approved installation after compatible readers/writers,
required human review and rollout checks pass. No automatic rights/provider/background activation.
Once correction rows exist, rollback must disable mutations and retain ledger/receipts; an old
reader that ignores corrections is unsafe. Destructive migration down refuses posted evidence.

## Website handoff

One public-safe item was appended and read back in the current-profile Content and Visibility
handoff file. Status: klar for vurdering. Explicit Ikke publiser gate remains for ordinary production rollout; BookStack synchronization
is now an approved deferral. Human acceptance was received on 2026-11-09. No website/social post was sent.

## Files changed in the authoritative Dev scope

- app/Http/Middleware/EnforceTechRoutePermission.php
- app/Modules/Clients/Actions/BuildClientTimeUsageEntries.php
- app/Modules/Clients/Actions/CorrectClientTimeUsage.php
- app/Modules/Clients/Actions/ExportClientTimeReport.php
- app/Modules/Clients/Controllers/Api/V1/ClientTimeController.php
- app/Modules/Clients/Controllers/Api/V1/ClientTimeReportApiController.php
- app/Modules/Clients/Queries/ClientTimeReportQuery.php
- app/Modules/Clients/Resources/Api/V1/ClientTimeReportSchemas.php
- app/Modules/Clients/Views/Tech/partials/time-report-data.blade.php
- app/Modules/Clients/Views/Tech/show.blade.php
- app/Modules/Commercial/Actions/CalculateClientTimebankBalances.php
- app/Modules/Commercial/Actions/RegisterClientTimebankConsumption.php
- app/Modules/Commercial/Actions/TimebankCorrectionWorkflow.php
- app/Modules/Commercial/Controllers/Api/V1/CommercialTimebankController.php
- app/Modules/Commercial/Controllers/Api/V1/TimebankCorrectionController.php
- app/Modules/Commercial/Controllers/Tech/Contracts/TimebankCorrectionController.php
- app/Modules/Commercial/Docs/api-contract.json
- app/Modules/Commercial/Docs/api-schemas.json
- app/Modules/Commercial/Docs/knowledge/commercial-api.md
- app/Modules/Commercial/Docs/knowledge/contract-system.md
- app/Modules/Commercial/Models/Contracts/ClientContractTimeConsumption.php
- app/Modules/Commercial/Models/TimebankCorrection.php
- app/Modules/Commercial/Models/TimebankEffect.php
- app/Modules/Commercial/Resources/Api/V1/CommercialApiOpenApi.php
- app/Modules/Commercial/Resources/Api/V1/TimebankCorrectionOpenApi.php
- app/Modules/Commercial/Support/TimebankLedger.php
- app/Modules/Commercial/Support/TimebankRevision.php
- app/Modules/Commercial/Tests/Feature/TimebankCorrectionTest.php
- app/Modules/Commercial/Views/Tech/Contracts/timebank-correction-preview.blade.php
- app/Modules/Commercial/Views/Tech/Contracts/timebank-corrections.blade.php
- app/Modules/Commercial/routes.php
- app/Modules/Economy/Actions/DeleteOrderLine.php
- app/Modules/Economy/Actions/GenerateOrders.php
- app/Modules/Economy/Actions/ReverseCustomerTimeCharge.php
- app/Modules/Integration/Support/ApiAbilityCatalog.php
- app/Modules/Task/Actions/RegisterIdempotentTaskApiTime.php
- app/Modules/Task/Actions/RegisterTaskTimeEntry.php
- app/Modules/Task/Controllers/Tech/TaskController.php
- app/Modules/Task/Views/Tech/Tasks/show.blade.php
- app/Modules/Ticket/Actions/DeleteTicketTimeEntry.php
- app/Modules/Ticket/Actions/RegisterTicketTimeEntry.php
- app/Modules/Ticket/Actions/UpdateTicketTimeEntry.php
- app/Modules/Ticket/Models/TicketTimeEntry.php
- app/Modules/Ticket/Resources/Api/V1/TicketTimeEntryResource.php
- app/Modules/Ticket/Views/Tech/Tickets/show.blade.php
- config/commercial.php
- database/migrations/2026_11_09_120000_create_timebank_corrections.php
- docs/TODO.md
- docs/adr/2026-11-09-timebank-correction-ledger-and-employee-time.md
- docs/feature-slices/2026-11-09-timebank-corrections.md
- docs/human-review.md
- docs/plans/2026-10-25-commercial-api-inventory.json
- docs/plans/2026-11-09-timebank-corrections-coverage.md
- docs/plans/2026-11-09-timebank-corrections-native-evidence.json
- docs/rfc/2026-11-09-timebank-adjustments-and-charge-reversals.md

Ignored Dev .env contains only the additional feature-exposure setting change.
Transient harnesses, scoped backups and raw test artifacts are outside the repository under
/tmp/nexum-timebank-20261109; no scratch files were added to the repository root.

## Acceptance update (2026-11-09)

Svein Tore explicitly accepted the delivered Dev implementation on 2026-11-09: "Godkjenner. BookStack får vente".
The Dev delivery is accepted and the handbook work is deferred by the reviewer. This does not
claim completed BookStack/employee retrieval, individual scenario execution or permission to
commit/push, promote Main, deploy production or publish gateway tools. No such action is performed.

## Main And Production Installation (2026-11-09)

Main PR #400 is merged; VM169 runs 26c28cab with the feature enabled (migration batch60).
Main candidate301/3589 tests pass; production HTTPS reads/previews/XLSX/PDF are verified.
All1050 existing source/Workday rows are unchanged; no real correction or permission grant.
Gateway tool publication is blocked by admin HTTP401; BookStack remains explicitly deferred.
See 2026-11-09-timebank-corrections-main-production-rollout.md for current evidence.
