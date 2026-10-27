# System API Planning Baseline

Date: 2026-10-27
Status: Investigation and documentation only; RFC Draft.
Runtime: authoritative /var/Projects/tdPSA on Dev; HEAD 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.

Strict host-key-verified SSH using the dedicated current-user key succeeded.
No local Windows Laravel runtime was used. Remote rg is unavailable; targeted
find/grep and in-memory Python were used. No Laravel Boost tool is exposed;
current framework code/routes/tests were inspected instead of guessing behavior.

## Verified State
13 System-owned browser rows, zero System API or /api/v1/system routes and zero
System Swagger paths. Exact methods/actions/middleware are in matrix JSON.
Native authorization is permission-based, not a hard-coded Admin-role check.
No System Livewire class found. System admin index has an unrelated seven-line
contributor addition. Shared Dev has more than 1600 dirty/untracked paths and
several active approved workstreams; preserve all existing work and reconcile
shared files before implementation. Planning is not a second code implementation.

GitHub open searches identified #262 as related scheduler/queue runtime work;
its body and empty comment list were read. No dedicated System parity Issue was
identified, and no GitHub write occurred. #262 already records a Dev scheduler
runner; do not repeat historical absence as current. Actual OS health not audited.
Read remote AGENTS, ai-team-process, module architecture, TODO, relevant human
review, RFC/ADR/slice processes, System routes/controllers/actions/queries/settings/
client/views/Knowledge/tests, native permissions/active/2FA, shared API permission
helper/catalog/loaders/Swagger and targeted downstream branding consumers.
Historical HR-2026-07-16-001 remains Reviewed.

## Baseline And Required Rework
First test attempt could not bootstrap: duplicate controller imports in active
LeadIntelligence/routes.php work. That contributor independently aliased them;
current runtime route discovery succeeds. No code repair performed here.
Second attempt: umask 0002; HOME=/tmp php artisan test app/Modules/System/Tests --compact.
33 passed, 2 failed, 220 assertions, 41.69 seconds; no skips reported.
SecurityHeadersTest::application_responses_include_security_headers and
SecurityHeadersTest::login_page_uses_current_nexum_branding_copy expect 200 but
get 500: SSO-enabled login calls SsoProvider::available(), which queries missing
user_sso_providers in SQLite :memory:. Test class has no RefreshDatabase.
This is a failing baseline, not a complete root-cause repair or API proof.
Owner: Codex, coordinate active UserManagement work. Resolve correct test/schema/
SSO boundary after approval and rerun before green completion. No approved deferral;
do not change live SSO config or silently suppress these failures.

## State
Draft RFC, Proposed ADR, four Draft sequential slices, complete proposed 19-operation
matrix, TODO registration and planned Pending human-review checklist.
No API/scopes/schema implementation, migration, real settings/logo/queue mutation,
backlog release, provider/customer send, commit/push, Main/production change or
runtime activation. Planning alone does not require handbook publication.
After implementation verify actual BookStack content and employee access;
OpenWebUI retrieval remains a separate claim.


## Resolution After Approval

Svein Tore approved the RFC on 2026-10-27: Godkjent. SecurityHeadersTest now uses
RefreshDatabase because its login page deliberately includes SSO schema-backed content.
The initial full baseline then passed 35 tests / 234 assertions. The completed API and
affected-module regression passes 121 tests / 1529 assertions, no failures/skips.
All 19 System HTTP operations have successful response-schema evidence. See the
verification, native, HTTPS and handbook artifacts; the earlier failure is resolved.
