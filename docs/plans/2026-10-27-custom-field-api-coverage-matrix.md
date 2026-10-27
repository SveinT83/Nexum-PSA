# CustomField Browser/API Coverage Matrix

Status: Done On Dev; final Dev 676 / 46696 passes; final isolated Main 236 / 9264 and scoped contracts 3 / 1481 pass; merged in Main PR #344 with exact verified-tree equality.
Date: 2026-10-27. Owner: Codex.
Parent: ../rfc/2026-10-27-custom-field-full-api-parity.md
Exact existing runtime rows and source hashes: 2026-10-27-custom-field-api-baseline.json.

| ID | Existing browser capability | Baseline API | Implemented contract / verification |
| --- | --- | --- | --- |
| CF01 | GET tech/admin/settings/custom-fields; search/model filter/list | GET /api/v1/custom-fields | Full manager list and actor-filtered discovery; query/count/pagination/schema tests |
| CF02 | New/edit modal context, model/type/options and every field setting | No full context; GET /custom-fields/{definition} partial contract | GET /custom-fields/context and typed definition detail; native rights and 11-type tests |
| CF03 | POST tech/admin/settings/custom-fields | Missing | POST /api/v1/custom-fields; all admin settings, validation, replay and exact GET |
| CF04 | PATCH tech/admin/settings/custom-fields/{definition}; edit/activate/deactivate/sort | Missing | PATCH /api/v1/custom-fields/{definition}; partial settings, revision and native read-back |
| CF05 | DELETE tech/admin/settings/custom-fields/{definition} | Missing | DELETE /api/v1/custom-fields/{definition}; active=false, soft delete and retained values; own-operation read-back |
| CF06 | Client workspace/settings/individual value PATCH | Client create/update/read/filter already implemented | Existing /clients CRUD/settings/workspace; field flags, clear/required/unique/denied values and exact read-back |
| CF07 | Client Site workspace/settings/individual value PATCH | Site create/update/read/global and nested filters implemented | Existing /client-sites and /clients/{client}/sites; canonical/alias uniqueness, authority and typed values |
| CF08 | Ticket create/edit/show typed custom fields | Existing Ticket create/update/read with default-off write gate | Owning Ticket APIs; work-context, actor/token/field rights, one-change event/no-op, portal exclusion |
| CF09 | Ticket Rules set/clear and typed conditions over definitions | Existing owning rule contract, independently gated | Preserve pinned definition/model/type/options, redacted evidence and disabled capabilities; no activation |
| CF10 | Retry/concurrent API client transport (new support for mutations) | Missing for definition admin | GET /custom-fields/operations/{operation}; actor/token-bound encrypted results, current authority, stale/replay tests |

Only four browser route rows belong to CustomField itself. Two value PATCH routes
belong to Clients; embedded Ticket/rule workflows must also be verified, not counted
as new CustomField routes. Implemented CustomField HTTP operations: seven (excluding HEAD).
Concrete runtime routes, scopes/native rights, typed operation/schema identities and
owner-workflow tests are recorded in the companion coverage-matrix.json. All seven
success operations match their actual HTTP schemas and trusted HTTPS results.
19 CustomField schemas have no dangling references. Native MySQL locking, replay
uniqueness, encryption and rollback pass; all synthetic data/tokens are removed.
Full core/API guides are published/read back in BookStack 451, with API notes in 449;
existing inheritance is preserved. Employee OpenWebUI retrieval remains untested.
Baseline: existing CustomField 9 / 87 and Ticket Custom Field 8 / 141 passed (17 / 228).
This is historical baseline evidence. Current API verification is recorded in
2026-10-27-custom-field-api-verification.md/.json. Manual review remains Pending.
