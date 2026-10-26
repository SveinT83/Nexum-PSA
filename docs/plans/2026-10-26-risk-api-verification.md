# Risk API implementation and verification

Status: Implemented and verified on authoritative Dev, 2026-10-26. Human review accepted by Svein Tore, 2026-10-26.
Design approved by Svein Tore: "Godkjenner". Review: HR-2026-10-26-RISK-API.
At original Dev handoff no Main/production action had occurred. Main is now merged as
recorded in the promotion section below; production remains separate.

## Delivered behavior

All R01-R16 workflows are implemented: 27 exact HTTP operations, 37 schemas, context and
bounded options, assessment/item/history operations, explicit approval, guarded deletion,
all six settings, PDF, durable command identity and authorized read-back.
Browser and API share current active actor/owner policy and stable mutation lock ordering.
Approval requires a nonempty mitigated/accepted item set. Changed signed-off item/history
content reopens the assessment; old stamps remain historical and approval_valid is explicit.
Changed locked fields return 422 atomically. History reconciliation uses newest time/ID.
Deleted parents close alternative child reads. No force purge or role expansion was added.

## Automated verification

| Check | Result |
| --- | --- |
| Final Risk suite after formatting/final changes | 26 passed / 3246 assertions, 73.76 s |
| Risk + Clients + WorkContext + Taxonomy + Report + Integration/Swagger | 163 passed / 4923 assertions, 484.83 s |
| PHP syntax | 38 scoped files, no errors |
| Scoped Git whitespace check | Passed |
| Dev HEAD and Git index | Unchanged |

The suites overlap; totals must not be added. The entire repository test suite was not run.
Every registered Risk method has an exact typed contract. Every schema equals generated
Swagger; all 27 positive HTTP responses are recursively checked against that contract.
All operations have scope denials and current actor permission checks. Real-token receipt
isolation/revocation, technician-specific browser permission distinctions, history ownership,
equal-time newest/middle/last deletion, no-op/replay, same-second revision conflicts, strict
approval, soft-deleted parent paths, null dates/settings and PDF are covered.

Commands:

    HOME=/tmp php artisan test app/Modules/Risk/Tests
    HOME=/tmp php artisan test app/Modules/Risk/Tests app/Modules/Clients/Tests app/Modules/WorkContext/Tests app/Modules/Taxonomy/Tests app/Modules/Report/Tests app/Modules/Integration/Tests/Feature/IntegrationModuleTest.php app/Modules/Integration/Tests/Feature/SwaggerProductionScanTest.php

Historical baseline before implementation was 9 tests / 63 assertions. Early regression failures
identified formerly silently ignored scoring fields; the approved rejection behavior and tests
were reconciled. Final relevant verification has no unresolved failure.

## Native database and trusted HTTPS

Exact additive migration 2026_10_26_010000_create_risk_api_commands.php applied on Dev as batch 27.
Native MySQL: ten command columns, one mutex row; independent connection blocked with error 1205;
duplicate identity rejected with error 1062. Probe writes rolled back.
A controlled disposable assessment/item/history fixture was created, read back and removed.
An HTTP approval waited approximately 1.56 seconds behind the domain mutex while the fixture
child changed to open; after commit it returned 409. No approval stamp or receipt was created.
This validates an HTTP API request competing with a controlled native writer under the shared lock;
it is not a claim of witnessed manual browser concurrency.
Eleven authenticated HTTPS JSON paths returned 200, anonymous request 401, missing/deleted-child
reads 404. Final context/settings JSON returned 200; Swagger HTTP 200 exactly matched generated
bytes and all 27 operation IDs / 37 complete schemas.
Temporary tokens were revoked; counts for assessments/items/history/commands/tokens/settings/
Work Context were restored exactly. No customer Risk records existed at preflight.

## PDF verification

A synthetic HTTPS report returned application/pdf, %PDF signature and 6852 bytes.
The one-page report was rendered with PDFium and visually inspected: readable layout, no clipping,
one item, score 6 / Medium, one addressed item, zero open items, expected history.
The existing report template and its language are preserved. This is fixture evidence, not
manual acceptance of all possible report sizes. The fixture PDF remains outside Git under /tmp.

## Knowledge and actual employee handbook

Risk-only repository sync produced three exact, published, internal Knowledge articles:
29 risk/risk-api-support, 30 risk/risk-overview and 31 risk/risk-settings.
API Management article 3 was changed only within its Risk section.
Native automatic BookStack integration canPull=false; pending_push is not external publication.
Actual BookStack publication used the established personal sveintore-admin connection.
Independent connector read-back proved exact source bodies, nonempty rendered HTML and revisions:

| Page | Verified revision | URL |
| --- | --- | --- |
| Risk Overview (447) | 6 | https://doc.tronderdata.no/books/nexum-psa/page/risk-overview |
| Risk Settings (448) | 7 | https://doc.tronderdata.no/books/nexum-psa/page/risk-settings |
| Risk API Support (640) | 1 | https://doc.tronderdata.no/books/nexum-psa/page/risk-api-support |
| Api Management (449) | 134 | https://doc.tronderdata.no/books/nexum-psa/page/api-management |

API Management outside the Risk section is byte-for-byte preserved.
Book 339, chapter 446 and all changed pages inherit permissions. Existing employee Editor role 2
bound to /staff-internal has book/chapter/page view-all. Permissions were not changed.
Employee OpenWebUI retrieval was not tested or claimed.

## Deployment and recovery

Only after explicit authorization and completed human review:

    php artisan migrate --path=database/migrations/2026_10_26_010000_create_risk_api_commands.php --force
    php artisan optimize:clear
    php artisan l5-swagger:generate

Then independently verify HTTPS JSON, authentication denials, PDF and served Swagger.
No frontend build, provider switch, queue restart or scheduler change is required.
Preserve command tables and APP_KEY. Migration down refuses nonempty command evidence;
do not drop receipts to retry an accepted or uncertain command.

## Remaining human work and limits

HR-2026-10-26-RISK-API is the human-review checklist. Svein Tore explicitly approved the delivered scope with "Godkjenner" after the completed
Dev handoff. HR-2026-10-26-RISK-API is Reviewed. Original scenario boxes are retained;
individual witnessed runs are not invented. Main/production require separate authorization.
Risk writes intentionally use one domain-wide mutex; high-volume production performance is
unmeasured. Preserve shared dirty work. Existing unrelated manual-review gates are separate.
No approved Risk implementation row is left as future read/write/action work.

## Changed application and handbook source files

- app/Models/Risk/RiskAssessment.php
- app/Models/Risk/RiskItem.php
- app/Modules/Integration/Docs/knowledge/api-management.md
- app/Modules/Integration/Support/ApiAbilityCatalog.php
- app/Modules/Risk/Actions/ApproveRiskAssessment.php
- app/Modules/Risk/Actions/DeleteRiskItemUpdate.php
- app/Modules/Risk/Actions/DeleteRiskRecord.php
- app/Modules/Risk/Actions/ExecuteRiskCommand.php
- app/Modules/Risk/Actions/RenderRiskPdf.php
- app/Modules/Risk/Actions/StoreRiskAssessment.php
- app/Modules/Risk/Actions/StoreRiskItem.php
- app/Modules/Risk/Actions/StoreRiskItemUpdate.php
- app/Modules/Risk/Actions/UpdateRiskAssessment.php
- app/Modules/Risk/Actions/UpdateRiskItem.php
- app/Modules/Risk/Actions/UpdateRiskSettings.php
- app/Modules/Risk/Controllers/Admin/RiskSettingsController.php
- app/Modules/Risk/Controllers/Api/V1/RiskContextController.php
- app/Modules/Risk/Controllers/Api/V1/RiskController.php
- app/Modules/Risk/Controllers/Tech/RiskController.php
- app/Modules/Risk/Docs/knowledge/risk-api-support.md
- app/Modules/Risk/Docs/knowledge/risk-overview.md
- app/Modules/Risk/Docs/knowledge/risk-settings.md
- app/Modules/Risk/Http/Middleware/RiskApiErrors.php
- app/Modules/Risk/Http/Middleware/RiskApiGuard.php
- app/Modules/Risk/Models/RiskApiCommand.php
- app/Modules/Risk/README.md
- app/Modules/Risk/Resources/Api/V1/RiskApiOpenApi.php
- app/Modules/Risk/Resources/Api/V1/RiskAssessmentResource.php
- app/Modules/Risk/Resources/Api/V1/RiskItemResource.php
- app/Modules/Risk/Resources/Api/V1/RiskItemUpdateResource.php
- app/Modules/Risk/Support/RiskInput.php
- app/Modules/Risk/Support/RiskMutation.php
- app/Modules/Risk/Support/RiskMutex.php
- app/Modules/Risk/Support/RiskPayload.php
- app/Modules/Risk/Support/RiskPolicy.php
- app/Modules/Risk/Support/RiskRead.php
- app/Modules/Risk/Support/RiskRevision.php
- app/Modules/Risk/Tests/Feature/RiskApiContractTest.php
- app/Modules/Risk/Tests/Feature/RiskApiParityTest.php
- app/Modules/Risk/Tests/Feature/RiskSystemTest.php
- app/Modules/Risk/api.php
- app/Modules/Risk/routes.php
- database/migrations/2026_10_26_010000_create_risk_api_commands.php

Planning/status sources also changed: approved RFC; accepted ADR; five complete Feature Slices;
gap matrix, exact contract, baseline, this verification report/.json; scoped TODO and human-review entry.
Raw test/native/source backups remain under /tmp/nexum-risk-api-20261026, outside the repository.

## Website editorial handoff

One public-safe item, NEXUM-2026-10-26-RISK-API, was added to the fixed Content and
Visibility queue and read back exactly as UTF-8. Status is klar for vurdering with an
explicit Ikke publiser gate until human review, Main, production and production verification.
This is editorial input only; no website or social content was published.

## Human acceptance recorded after delivery

Svein Tore approved the completed Risk scope with "Godkjenner" on 2026-10-26.
HR-2026-10-26-RISK-API is Reviewed. Main and production are still unchanged and require
separate authorization. Only documentation/status was changed in this acceptance step;
application tests were not rerun because application code was unchanged.
The approval status was synced to Knowledge and published/read back independently in
BookStack: 447/7, 448/8, 640/2 and 449/135. Original delivery test/native evidence above
is retained as historical evidence. Website editorial input retains Main/production gates.

## Main promotion verified after acceptance

PR #321 merged: https://github.com/SveinT83/Nexum-PSA/pull/321
Merge SHA: bf5c7d0c608895031eea04aa9a65cfd7c8a7ba98. Merged tree exactly equals the isolated tested candidate.
Candidate verification: 26 Risk tests / 3246 assertions; 165 affected tests / 4878 assertions.
38 PHP files pass syntax and Pint. Native scope/actor mapping and all 37 schemas match.
No production migration/deployment, provider switch, queue restart or scheduler action occurred.
