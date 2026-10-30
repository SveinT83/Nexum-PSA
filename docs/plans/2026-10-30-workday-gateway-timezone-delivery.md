# Existing Nexum Workday And Local Time - 30 October 2026

Owner: Codex. Issues: #385 / #386.
Status: Gateway active and read-back verified; Oslo profile corrected; source Main promotion and
deployment explicitly approved and in progress. Human acceptance remains Pending.

## Approved scope and current result

Svein required the existing Nexum connection in LiteLLM and the existing ChatGPT plugin, then
approved implementation and the timezone repair. He separately approved: "Ja, merge til Main
og rull ut i produksjon" for the tested source package on 30 October.

The main nexumpsa registration now uses the gateway-authenticated user's personal credential for
Workday, employee work-plan and plan-block operations. Missing binding is denied and never uses
the shared credential. Other PSA domains retain their earlier configuration.
Two exact employee accounts have new bounded native tokens (17 / user 13, 18 / user 2), with
workdays.read/write/confirm only. Existing native roles and rights are unchanged. The existing
owner credential was independently verified as native user 1/token 2 and reused only for the owner.
Credentials were transferred encrypted and stored encrypted; no plaintext credential artifact.
The transfer private key was removed. The obsolete separate nexumworkday registration is retired.

Fresh public gateway checks passed for both employees: own list/date-entry reads, Jo Eskil's
Europe/Oslo new-date context, cross-employee 404, settings denial, missing-binding denial and an
invalid Save 422. Cold discovery and an actual public MCP main-route call passed before retirement.
Final post-retirement list/entry, fresh discovery and actual public MCP main-route calls passed.
The final catalog has 50 technician tools, eleven nexumpsa Workday tools and no separate
nexumworkday tools. An intermediate diagnostic SSE parser failure was corrected to handle
SSE response framing; it was not treated as passing application evidence. All diagnostic keys
from every run were revoked.

Jo Eskil/user 2 profile/work plan is corrected through UserWorkPlan.update with a fresh opaque
revision to Europe/Oslo. Weekly hours, all five custom exceptions and complete existing Workday,
revision and mutation-receipt state were independently verified unchanged. The retained 29 October
day remains UTC/version 1. No recorded hours or historical instants were shifted. Norum's current
profile/preferences/Calendar all explicitly use UTC; no unapproved mass timezone change was made.

## Source package and verification

An isolated latest-main tree at 210191d17bed95a12e63aa3cc2454ce494f567c0 contains one formatter,
eight Ticket/Task views and the two existing Workday validation/privacy hotfixes already present in
production. The first Main test exposed those missing Main hotfixes: six failed/six passed. After
including their exact authoritative Dev source, all 12 tests / 184 assertions passed. Two focused
Ticket/Task view tests additionally pass / 17 assertions. No application-wide suite is claimed.
A no-tests-selected diagnostic is not counted as a passing test run.

Authoritative Dev work-plan/formatter regression: 16 tests / 110 assertions. Earlier this session:
WorkdaySaveValidationTest 9 / 163. Gateway adapter/header tests: 16 passed.
The source package requires no schema migration, build, worker restart or scheduler change.
Refresh compiled Blade views as the installation owner, retaining normal group write permissions.
Production preflight confirmed the eight views match owned preimages, the formatter is new and both
Workday hotfixes are already current. No conflicting contributor source was detected in the scope.

## Documentation and human gates

RFC: ../rfc/2026-10-30-existing-nexum-workday-gateway.md.
ADR: ../adr/2026-10-30-personal-workday-existing-nexum-gateway.md.
HR-2026-10-30-NEXUM-GATEWAY-WORKDAY and HR-2026-10-29-LOCAL-TIME remain Pending.
They gate human acceptance. Explicit merge/deployment approval does not mark any manual check done.
BookStack profile page 384 revision 11 contains the new source-owned employee instructions and was
independently read back. The page's existing inherited employee access is preserved; employee
OpenWebUI retrieval is a separate unperformed check.
The existing ChatGPT plugin's connected Svein Primary OAuth account was retained; normal refresh
was performed and app details show 450 tools (417 write/33 read), versus 273 before refresh.
This does not claim that an employee's actual chat or a valid real-time Save was exercised.

## Recovery and remaining evidence

Gateway backup: /opt/ai-gateway/backups/nexum-main-workday-20261030T090023Z.
Maintained candidate/tests: /opt/ai-gateway/workday-main-20261030.
Profile/projection backup: /tmp/nexum-joeskil-timezone-20261030085526-26c9f5af.json.
Restore only owned source/spec/registration/binding changes; revoke only tokens issued here.
Do not replay employee work or overwrite subsequent contributors' changes.

Finish exact Main merge/read-back, scoped production source deployment/read-back, documentation
reconciliation and bounded artifact cleanup. Final public catalog verification is complete. Keep both Issues open
until their remaining acceptance criteria are proven. #386's original three legacy draft 422
responses still lack original payload/field errors; today's repaired authentication is separate.
