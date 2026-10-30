# Issues #385 / #386: Local Time And Workday Gateway Investigation

Historical 29 October record. Current main-connection repair, profile correction, Main merge
and production source read-back are recorded in 2026-10-30-workday-gateway-timezone-delivery.md.
The dated evidence below is retained; old pending source/replacement wording is superseded.

Status: Display/guidance Done On Dev; #386 original gateway diagnosis blocked on missing evidence.
No Main merge or production deployment. Human review and employee-profile decision are pending.
Owner: Codex. Human reviewer and production owner: Svein Tore.
Human-review checklist: HR-2026-10-29-LOCAL-TIME (Pending).
Main, production deployment and employee configuration/data changes are separate actions.

## Findings and scope

This is a Level 1 correction of existing timestamp display and contradictory consumer guidance,
with regression investigation of the existing own-employee Workday contract. No new workflow,
delegation, setting, storage, API operation or permission is introduced; no new RFC is required.

The before-fix regressions demonstrated UTC output on Ticket and Task views. The shared
UserManagement formatter now uses the existing canonical profile -> legacy preferences ->
application timezone order. It converts an immutable copy, preserving UTC storage. Ticket
scheduled/lifecycle/documentation-request/SLA timestamps and linked Task deadline dates, and
Task list/detail/quick-view deadline/completion/activity timestamps use it. The Ticket SLA
report, scheduled Ticket-list tooltip and quote/workflow evidence use the same conversion. Profile timezone is explicit.
Date-only work_date, durations, billing, sort order, Task input semantics and API timestamps
are untouched. No application/OS timezone change is made.

Workday already normalizes local input to UTC before future-time comparison. Controlled tests
cover winter/summer 20:45 local now with accepted 20:40 End, rejected 20:46 End, web/API,
retained modal input, no partial revisions/receipts, retry, read-back and separate 480/300
minute employee plans. A production-shaped UTC-profile/Oslo-preference conflict is reproduced
synthetically and corrected using the existing work-plan action on synthetic data only.

## Fresh production read-only evidence, 29 October 2026

The initial connected Plesk call timed out. The existing documented Datanora Diamant SSH
wrapper succeeded with strict known-host validation; no validation bypass or production write.
Employee id 2 is active and not a system actor. The canonical profile/work-plan timezone is
UTC, while legacy preferences and the personal Calendar use Europe/Oslo. The selected
2026-10-29 entry reports timezone UTC and 300 planned minutes. This is a real configuration
conflict; it is not proof of the gateway's authenticated identity.

The employee's 2026-10-29 Workday already exists, version 1, recorded by origin ui at
19:50:59 UTC. It was left entirely unchanged. Do not repeat the old report that no current
day exists. A profile change cannot reinterpret this retained record's fixed timezone.

Sanitized production access-log references show:
- 19:53:57 UTC, PUT /api/v1/workdays/2026-10-29/draft, 422, access_ssl_log:4942.
- 19:58:08 UTC, the same legacy draft path, 422, access_ssl_log:5051.
- 19:58:19 UTC, the same legacy draft path, 422, access_ssl_log:5056.

These are actual server requests, not proof of their originating assistant or employee.
The logs do not establish the body, authenticated user, Idempotency-Key or errors object.
The exact #386 cause remains unproven. Do not substitute a timezone or identity hypothesis.
No diagnostic payload retention is enabled. Independent SHA-256 read-back confirms identical
production and Dev WorkdayController, MutateWorkday and WorkdayTime source. The logged legacy
draft calls pass effective=false and therefore do not execute Save's future-end check; that
specific check cannot explain those three responses. Draft remains a valid legacy operation,
so its use alone is not the 422 cause.

GitHub Issue comments are published and independently read back byte-for-byte:
#385 comment 6508133706 and #386 comment 6508134022. Both Issues remain open.

## Implemented documentation correction

The API consumer guide incorrectly said effective Save was not implemented and sent ordinary
consumers through legacy draft/confirmation. It now documents the existing effective Save,
complete replacement semantics, removal, record-local timezone, personal identity, exact
422-field diagnostics, retry and read-back. Workday source guides distinguish current Save
from legacy compatibility. This fixes verified guidance, not a proven gateway implementation bug.

Three authoritative source sections were published to existing BookStack pages:
Profile 384 revision 10, Ticket Overview 409 revision 39, Task Overview 398 revision 11.
Independent GETs verified the exact appended text and preservation of all previous content.
Page/chapter inheritance is preserved; book 339 existing role grants are unchanged. No
employee OpenWebUI retrieval or new access permission is inferred. Ticket/Task sections
explicitly say Dev only, not production availability.

## Verification record

- Original before-fix run: 5 failures / 4 assertions, including real Ticket/Task UTC display failures.
- First implementation run retained in /tmp/nexum-385-386-focused.log: 3 failed, 10 passed.
  The new inline Blade declarations were corrected to block syntax; decoded JSON assertions
  replaced a raw escaped-slash assertion.
- Corrected focused run: 12 passed / 182 assertions, one Task-index fixture filter failure.
  Assigning the synthetic Task to the own queue corrected the fixture.
- Final isolated Task regression: 1 passed / 7 assertions.
- The overbroad 229-case selection emitted 66 passing progress markers, then made no further
  visible progress for several minutes while CPU-bound. Its owned PHPUnit process was stopped
  after more than ten minutes. This interrupted selection is not a passing matrix and does
  not identify a failing test or prove a numbering defect.
- The unmodified backed-up original Ticket allocation regression was checked independently:
  1 passed / 5 assertions, 76.34 seconds. The suspected numbering failure did not reproduce.
  The broad combined run remains incomplete; it is not used as evidence of a code defect.
- Final filtered affected matrix: 53 passed / 564 assertions in 179.21 seconds; includes every
  changed test, full profile/Workday context/validation/timeline classes and existing affected
  Ticket-show/Task view checks. Supplemental SLA/index/quick-view checks pass: 3 tests / 28 assertions, 98.06 seconds.
  These runs cover 55 distinct affected test cases (one revised case is repeated).
  Do not claim a full application suite.
- Scoped Pint passes all five owned PHP files. All five PHP syntax checks pass.
  Added-line whitespace, UTF-8, readable file permissions and final handbook GET read-back pass.
  Page permissions were independently read back unchanged.

Preimages: /tmp/nexum-issues-385-386-p8773toe. Preserve the shared Dev tree, branch and index.
No commit, push, Main merge, production deployment, credential change, provider activation,
notification, employee time mutation or global clock change is performed by this work.

## Remaining exact actions

1. Affected regression/source/read-back checks are complete; broader/full-suite completion is not claimed.
2. Svein confirms whether employee id 2's profile/work plan should use Europe/Oslo. Preserve
   weekly hours and all five custom Calendar exceptions through the supported action; reread
   the opaque revision before applying. The question is pending; elapsed time is not approval.
   This must not edit the existing Workday. Its fixed timezone needs a separately reviewed
   correction if its display/input must change.
3. Obtain the original gateway method/body field names, safe request reference and decoded
   errors object, plus verified personal connection identity. Use a synthetic own-employee
   scenario to reproduce the exact error; never switch to a shared Superuser or delegation.
4. Source merge and production deployment require Svein's explicit request. Deployment needs
   the formatter plus eight affected views and normal compiled-view refresh as the site owner;
   no migration, asset build, queue/scheduler or provider change is required.
5. Svein performs the named human checks. Only a named human can mark them Reviewed.
