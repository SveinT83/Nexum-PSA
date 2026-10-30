# Existing Nexum Workday And Local Time - 30 October 2026

Owner: Codex. Issues: #385 / #386.
Status: Gateway Active / Main Merged (PR #387) / Scoped Production Patch Verified / Handbook Updated.
Human acceptance remains Pending.

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

Exact Main merge and scoped production deployment/read-back are complete; see the verified
completion record below. Final public catalog verification is complete. Keep both Issues open
until their remaining acceptance criteria are proven. #386's original three legacy draft 422
responses still lack original payload/field errors; today's repaired authentication is separate.

## Verified completion of the authorized source rollout

PR #387 merged to main as a80e4f26d551bc89f155ccf9d99a79e914e6d712.
Fetched main tree 80600ff74d06a77a4f44946136588aaf6e3d6a6b exactly equals the tested candidate.
The bounded production package contains eleven runtime files: nine changed and two existing
hotfixes were already current. This is a scoped patch from that Main commit, not a claim that every
unrelated Main change is installed. No production Git checkout/HEAD or unrelated file was changed.

Production backup:
 /var/www/vhosts/tronderdata.no/.codex-backups/nexum-timezone-20261030-095042Z
The first backup-path attempt failed before any runtime write because the site owner cannot write
/var/backups; it was corrected to the protected owner home. Deployment uses the existing site owner
and preserves source permissions. view:clear/view:cache passed with umask 0002; 474 compiled PHP
views are group-writable. Independent read-back verifies all eleven hashes against the package.
Production formatter tests with the corrected profile pass winter, summer and midnight. UTC
storage, existing Workday/history/receipts, weekly clocks and five exceptions remain unchanged.
Trusted public HTTPS /login returns 200. No schema migration, build, queue, scheduler or provider
activation was performed.

The existing plugin was refreshed in place, retaining its connected Primary OAuth account.
The displayed catalog changed from 273 to 450 tools (417 write, 33 read). Public post-retirement
main-route discovery/calls passed independently. Actual employee ChatGPT use is not inferred
from catalog visibility or diagnostic-key tests.

Gateway final audit preserves all 55 unrelated encrypted credential rows and the existing main
registration configuration, team membership/models and original 39 technician tools. Three
personal main-server bindings remain, the separate registration/bindings are retired, and no
diagnostic key or transfer private key remains. Maintained adapter, schema and test artifacts
are in Nexum-MPC/ops; implementation was performed on the Dev/gateway host.

The approved source rollout is complete. Remaining Issue criteria concern named human/browser/
ChatGPT acceptance and the original historical 422 request fields, not an unperformed deployment.
Do not mark either human-review entry Reviewed or claim a valid production employee-time save.

## Final handbook scope and independent employee probe

The production-status sections on Profile 384 revision 13, Ticket Overview 409 revision 40 and
Task Overview 398 revision 12 were independently read back. Existing page/chapter inheritance
and book overrides were read back unchanged. The verified current menu label is Profile > Work plan.
Publication and administrative read-back are complete; employee handbook retrieval is not complete.

A bounded public MCP call through the existing employee-7 BookStack binding returned
MCP -32600: Requested resource not found. The native role defaults are limited; the exact cause
(binding/resource/permission policy) has not been established and no permission was expanded.
Owner: Svein Tore / Integration. Next: diagnose that existing personal binding and intended
employee article scope; then read the published guide with the employee identity. OpenWebUI
retrieval remains separate. The diagnostic key was revoked. This does not invalidate the
separately verified Workday connection; do not claim that employee handbook access passed.

A public-safe editorial handoff item was appended and read back in the agreed Content and
Visibility queue: one item, status klar for vurdering, Publiseres: nei and explicit Ikke publiser.
No website/social publication occurred. No actual employee work time was created or changed.
