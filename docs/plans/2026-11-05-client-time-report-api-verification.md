# Customer reporting API verification - 2026-11-05

Status: Done On Dev / Main Merged And Independently Verified / Manual Acceptance Pending.
RFC: ../rfc/2026-11-05-client-time-report-api.md (Approved).
ADR: ../adr/2026-11-05-report-api-discovery.md.
Human review: HR-2026-11-05-CLIENT-TIME-REPORT-API Pending before production release.
Parent HR-2026-11-05-CLIENT-TIME-REPORT remains separate/In Review.

## Coverage
All four supported customer report operations are implemented and tested:
- GET clients/{client}/reports/time => summary, getClientTimeReport, clients.reports.time.read.
- GET clients/{client}/reports/time/items => items, getClientTimeReportItems, same read scope.
- GET clients/{client}/reports/time/entries => entries, getClientTimeReportEntries, same read scope.
- GET clients/{client}/reports/time/export => XLSX/PDF, exportClientTimeReport, read AND export scopes.
Module routes own all operations; ClientApiGuard refreshes active actor. Five original browser rights
are checked independently. Shared query/export remain untouched; no new financial calculation.
Typed summary/item/entry/totals/meta/error schemas and binary content types are OpenAPI source.
ReportApiDefinition is optional/domain-owned; registry metadata gains api_endpoints without data
access. Existing Ticket SLA and Workday report calculations/gates are unchanged.

## Automated verification
Initial seven API tests pass97 assertions on PHP8.3: shared-source parity actual5/basis30,
date/paging/errors, missing/native permissions/inactive actor, metadata/Worklog-only grants,
private Task+projection/other-customer exclusion, XLSX/PDF, discovery and 10,001-row rejection.
Full Dev and isolated latest-Main Clients/Report matrices include generated Swagger route/schema
assertions and all existing Worklog governance contract assertions; results recorded below.
Native active-actor, permission and bearer boundary is also exercised by existing domain tests.
No live customer/time/financial fixture, provider call, token/grant or gateway mutation.

## Isolation
Implementation is in /var/Projects/tdPSA, branch Dev. Promotion candidate is an isolated latest-Main
worktree. Its own Composer loader resolves App/Tests to the candidate; no live .env copied.
Only the two new catalog abilities/metadata are applied to Main, excluding unrelated Dev ordering.
A promotion snapshot initially treated Dev-untracked parent-report files as deletions; this was
caught by diff inspection. Aborted candidate runs were discarded; candidate corrected by exact
authoritative source bytes before the final full matrix. No source implementation defect resulted.
Generated Swagger output is a test artifact and is excluded from the scoped commit. At deployment
regenerate Swagger from the installed source after separately approved rollout.

## Handbook
Client Domain Overview page451 revision17 and Report API page506 revision3 have the exact new
source sections, independently read back and compared to source. Both pages and chapters450/436
inherit; book339 existing audience overrides inspected unchanged. No permissions changed.
Employee OpenWebUI retrieval remains a separate manual check. Search returned duplicate Report API
entries; the newer existing page506 was used, with no duplicate page creation or deletion.

## Gates and deployment
Svein approved implementation and scoped Main merge: "Godkjenner, kjør på og merge til main etterpå".
Manual intended-consumer/paging/download/privacy checks and handbook retrieval remain Pending,
before production release; the separately approved source merge is not blocked by them.
No production/gateway deployment, automatic native scope grant, coordinator policy bypass, queue,
scheduler or provider activation. No migration or frontend build required.
Future separately approved rollout ships scoped files, clears ordinary route/config/opcache and
regenerates Swagger; verify real API and intended account. A fresh tool catalog/gateway registration
is separate from code/API availability and is not claimed here.
JSON responses explicitly state live-page consistency and full-period totals. Existing source
association/financial-owner and estimate-completion limitations remain those of the browser report.
Rollback removes scoped endpoints/scopes/descriptors only; source time/order history remains intact.

## Regression contract reconciliation

Initial full Dev95/1210 and Main94/1202 each had one failure: the exact Clients API manifest
required the four new operations. All other cases passed. The manifest now includes those four
operations and eight generated schemas; the generic contract test now checks typed binary content
as well as JSON refs. No API application behavior changed after the full matrices. Final Dev
API+contract tests pass10/520 (PHP8.3,02:55). Main final contract/API/Report result follows.

## Final pre-merge evidence

Final isolated Main API/contract/Report matrix passes16/586 on PHP8.3 (02:55); Dev final10/520
passes. All earlier non-contract regression cases passed; only the exact manifest/test contract was
updated afterward. Scoped Pint and diff checks pass. Source application files remain identical
to Dev except Main preserves its unrelated existing catalog ordering. No new runtime repair.

## Main delivery - 2026-11-05

PR397 merged expected head b338c4f550797e5a5221a09a62dc647ce0fbae28 as
5b9b862a1ef79162266602afb4ad8f320e3830e7. Independent fetch confirms the entire Main tree
da9eda40c67993969f55eb43798e3493a3b5a3a9 exactly equals the verified candidate (empty diff).
21 scoped files only; unrelated Dev catalog ordering/gateway changes preserved. Source documentation
follow-up records this completion without changing application code or rerunning runtime tests.
No production/gateway rollout, grant, worker or scheduler activation occurred. Source merge
is complete; both parent browser review and new API manual acceptance remain explicitly separate.
