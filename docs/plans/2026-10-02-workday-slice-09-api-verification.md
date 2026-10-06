# Workday Slice 09 Verification - Employee API Contract And Consumer Handoff

Date: 2026-10-02. Status: Done On Dev (API scope), default-off.
Owner: Codex; product/reviewer: Svein Tore.

Svein explicitly revised this slice: MCP will be handled later, LiteLLM replaces NexumMCP,
and the immediate deliverable is the PSA API for all supported functions. No external
MCP/gateway dependency remains for this API delivery. No LiteLLM adapter or live tool session
is claimed. The existing approved Workday RFC/ADR and Slice 09 have been reconciled.

## Result

All **34 API operations** are implemented and published. They cover normal weekly hours,
Calendar work-plan blocks, actual days, explicit confirmation/correction, source allocation,
confirmed oversight, simple absence, reminder preferences/snooze, internal Task conversion,
settings and retention preview. Each operation has a stable operationId, bearerAuth,
x-required-scopes matching actual route middleware, and a success schema.

The [API consumer guide](../integrations/workday-api-consumer.md) contains the complete method/
path/scope inventory, required permissions, field/identifier conventions, workflow-specific
retry and concurrency rules, examples, pagination, source completeness, errors and explicit
confirmation requirements. Its contract is independent of the later gateway choice.

The implementation pass found:
- 25 operations described their scopes only in prose. They now also publish exact machine-readable
  x-required-scopes, including all required scopes together for multi-scope operations.
- Reminder operations lacked explicit 401 documentation; that is now present.
- Calendar plan-block lists documented only data. Their response now includes pagination metadata.
- Work-plan write schemas now reflect the closed field set. Shared weekly-hours schema reuse
  does not make the read response inherit closed write-only fields.
- Work-plan middleware did not deny coordinator workload-bound tokens, unlike the later Workday
  actions. A real-token regression reproduced HTTP 200 before the fix; the shared plan middleware
  now rejects that credential type. Personal employee tokens retain full supported API behavior.

No paths, operationIds, existing scopes or normal employee workflows were removed.
No credentials were issued in the live Dev database or expanded for an existing consumer.
All created bearer tokens/users/records were confined to the isolated test database.

## Tests

**196 tests passed / 1912 assertions**, including five new consumer contract tests.

~~~bash
umask 0002
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature \
  app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php \
  app/Modules/Calendar/Tests/Feature
~~~

Evidence: /tmp/workday-api-09-cp3980nb/regression.log on Dev.
The full repository suite was not run; this selection covers the changed Workday/plan/Calendar
contracts and their existing permission, source, notification and retention regression cases.

New tests use real personal bearer tokens through the Laravel HTTP pipeline, not Sanctum
actingAs or a mocked gateway. They prove:
- Read/update/read-back weekly hours; create/retry/update/cancel/read Calendar plan blocks.
- Register/read/correct/cancel/list/history absence.
- Read/replace preferences; deliver a synthetic in-app reminder through its real job;
  read/snooze and suppress after API confirmation.
- Draft/read/source discovery/allocation/preview/confirm/retry/correct/reconfirm/history/list.
- Explicit Task preview/create/retry/read-back, unchanged actual-time total and zero Ticket time.
- Separate overview/settings token, confirmed overview/detail/history, settings update/read-back
  and metadata-only retention preview.
- All 34 operations reject anonymous access with 401 and an unscoped personal token with 403.
- Workload-bound wildcard token cannot enter employee work-plan operations.
- Exact route-to-OpenAPI scope/security/success-schema parity and unique operationIds.

The first new workflow fixture omitted delivery of its faked queued reminder job and therefore
had no unread reminder to fetch. The fixture was corrected to execute the real job in the isolated
database; production behavior was retained. The coordinator regression failed before the fix
and passed after. No failure or incomplete API operation is deferred.

## Published API verification

Trusted HTTPS GET https://dev.nexumpsa.eu/docs returned 200. All 34 published operation
objects matched Dev's regenerated OpenAPI. All **49 transitively referenced schemas** resolved.
OpenAPI 3.0.0 uses server URL /, so operation paths already include /api/v1.

Verification time: 2026-10-02T21:19:32Z.
Full published OpenAPI SHA-256:
c21953f8b27651fc0593abdb4c1dc875e7281cc7794e49ad12b6ace64d7827b6

Canonical selected operation-object SHA-256 (sorted JSON keys, compact separators):
12fa16e6525d75aa19fd21eea6d093a4f16cb6f7b7f0ec83f23e26a2c3b3c0b4

This identifies the generated contract on the shared, uncommitted Dev working copy; it is not
a Main release identifier. The verification manifest is at
/tmp/workday-api-09-cp3980nb/api-evidence.json. No token values are included.

Live anonymous HTTPS checks returned 401 for users/me/work-plan, workdays and
POST workday-settings/retention-preview. Authenticated writes/read-back are separately verified
by the isolated HTTP-stack tests, not claimed as live employee/gateway or browser approval.

## Files and ownership

Updated runtime/contract files:
- UserManagement/Http/Middleware/EnsureEmployeeWorkPlan.php.
- UserManagement/Controllers/WorkPlanController.php and Resources/Api/V1/WorkPlanOpenApi.php.
- Calendar/Controllers/WorkPlanBlockController.php and Resources/Api/V1/WorkPlanBlockOpenApi.php.
- Workday/Controllers/Tech/WorkdayController.php, AbsenceController.php, ReminderController.php,
  and Controllers/Admin/WorkdaySettingsController.php.
- New Workday/Tests/Feature/ApiContractTest.php.
- Generated storage/api-docs/api-docs.json.

Paths above are under app/Modules. Documentation updates: this verification, the consumer guide,
Workday Knowledge/API articles, accepted ownership ADR, Workday RFC/ADR/plan, TODO, Slice 09/index,
the same human-review entry, and a supersession note on Slice 08's old MCP prerequisite.
The local review mirror contains documentation only.

Preflight verified branch Dev and existing shared dirty state. No open GitHub Workday Issue
matched the scope. Unrelated changes were preserved. No commit, push, merge or external message.
PHP syntax, Pint, UTF-8, document links and scoped whitespace checks passed.

## Operations and remaining gates

No migration, frontend build, worker/scheduler change or runtime activation is required by
this slice. optimize:clear and l5-swagger:generate completed on Dev.
All existing Workday and retention switches remain off; no live employee data was changed.

HR-2026-10-01-WORKDAY is a human-review checklist entry, still Pending. It blocks Main
promotion/merge and production migration/deployment/activation. API tests do not mark it
Reviewed. Combined Dev pilot activation, browser/mobile/keyboard review, opted-in real
notification receipt and the documented backup/archive/retention checks remain.

MCP/LiteLLM adapter implementation and live connection verification are explicitly deferred
by Svein. They do not block this API result. The task does not change Vault ownership,
automatically share credentials, impersonate employees or introduce a PSA MCP server.
Tripletex, holiday approval/balances, advanced rota and phone-provider queue automation remain
future scope. Svein continues to own Main promotion and production rollout.
