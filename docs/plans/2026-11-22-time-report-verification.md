# Employee suggestions and registered time report verification

Status: Done On Dev / Main Merged And Independently Verified / Human Review Pending.
Date: 2026-11-22
Owner: Codex. Approver: Svein Tore.
RFC: ../rfc/2026-11-22-employee-suggestions-and-ticket-task-time-report.md (Approved).
ADR: ../adr/2026-11-22-identified-registered-time-reporting.md.
Human review: HR-2026-11-22-TIME-REPORTS Pending for production acceptance, not the separately
authorized Main source merge. No production deployment is authorized by this task.

## Implemented scope
Workday names-only Unicode suggestions with click/keyboard selection and full directory paging.
Stable IDs clear on edit; Workday retains literal name search and original date/oversight semantics.
New Reports > Ticket and Task time: employee/Client/record/source/context/status/billable/date
filters, independently paged entry/group detail, full-filter totals and complete internal XLSX/PDF.
Ticket/Task domain queries enforce private visibility and owner/context integrity before reporting.
Direct Ticket time plus Task time counts once; Task-linked Ticket billing projections are excluded.
Estimated/completion provenance and archived/former-person labels remain explicit.
Identified personal API scopes, native actor/record rights, coordinator-bound wildcard denial and
typed OpenAPI contracts cover report, lookups, downloads and Workday employee suggestions.
All new domain routes are in their module routes.php; Report/api.php is a compatibility loader.
No schema, provider, financial, queue/scheduler, permission-grant or source time mutation.

## Dev verification
Authoritative checkout /var/Projects/tdPSA on Dev, PHP 8.3, isolated SQLite :memory:, umask 0002.
Affected matrix: RegisteredTimeReportTest, EmployeeSuggestionsTest, ConfirmedOversightTest,
ClientTimeReportTest, ClientTimeReportApiTest and ReportModuleTest.
Authoritative Dev full matrix: 51 tests / 523 assertions; final focused 11 / 115 pass.
Isolated latest-Main candidate: 51 / 523, final exact-source focused 11 / 115 pass.
Candidate App/User/TestCase reflection paths independently resolve inside the candidate.
The initially shared vendor autoloader was replaced with candidate-local Composer metadata and
an explicit candidate PHPUnit bootstrap before the passing isolated runs. No full application suite is claimed.
Five Node regressions cover focus, continuation, stale IDs/responses, keyboard/duplicate IDs and
visible errors. Scoped PHP syntax/Pint and actual Swagger generation/read-back pass.
The old Client API private-Task fixture was corrected to create with the actual private author,
rather than attempting to rewrite immutable creator attribution. No attribution behavior changed.
Preliminary contract/import and fixture failures were corrected before promotion.

## Synthetic browser verification
Chromium exercised actual server-rendered report content with intercepted synthetic lookup
responses at the Dev origin. It passed focus/all suggestions, Unicode narrowing, keyboard/click
selection, stale ID clearing, unselected exact-ID text validation, Escape and date preservation.
Desktop 1440px and mobile 390px screenshots were visually inspected; no document-wide horizontal
overflow or console errors. This is not a logged-in live employee browser acceptance claim.
The exact Bootstrap asset from the layout was integrity-checked. Missing Chromium libraries were
downloaded/extracted only into /tmp for this test; no OS package/service configuration was changed.
Scratch/evidence: /tmp/nexum-report-20261122, outside the repository. No customer fixtures were
created in the live Dev database. PHPUnit fixtures and bearer tokens are isolated/ephemeral.

## Handbook
Authoritative Report overview/API and Workday oversight Knowledge source updated.
BookStack 437 revision 6 and 506 revision 7 were independently read back against the appended
source sections (BookStack strips trailing newlines). Earlier article text, owner, book/chapter
and unrelated content were preserved.
Pages 437/506 and chapter 436 inherit their audience; book 339 retains its existing documentation
role overrides and inherited fallback. No permission writes were performed.
The documentation clearly states Dev availability and separates production installation.
Employee OpenWebUI retrieval is an unperformed named-human check, not inferred from API read-back.
The combined Report overview contains the full Workday suggestion procedure.

## Delivery isolation
The authoritative checkout has extensive unrelated staged/unstaged work and is not reset or
bulk-committed. Initial index/status and per-file task preimages are retained outside the repo.
Source edits occur only in that checkout; isolated promotion worktrees apply the task delta and
verify delivery/runtime independently, with candidate-local Composer paths and SQLite-only tests.
Generated Swagger is rebuilt for verification, excluded from the scoped source commit.
No unrelated user/security/hosting/Tripletex work or active configuration is included.

## Deployment and rollback
Main source merge is explicitly authorized; production rollout is separate.
A future production rollout ships the scoped source, clears normal route/config/view caches,
refreshes PHP-FPM opcache as needed and regenerates Swagger. No migration, frontend bundle build,
queue restart, scheduler activation or provider setup is required.
Check the installed report routes, explicit abilities and intended human account after deployment.
Rollback reverts the scoped report definitions/routes/views/queries; source work remains unchanged.

## Main source delivery

Svein Tore explicitly authorized Dev implementation and Main merge.
Dev integration f41ea63b292b5a6c1f3d77a5f98bc7aec75ad73b passed the final 51-test / 525-assertion
matrix and was pushed before Main was proposed. Main PR #409 merged source 1a0b6ef4e2bf317e72c30e2a8dca5a5b1a7eb641
as 413e3a10352b987e91e1f8e81aab46823ffba9a0. Independent Git fetch proves exact tested tree
f591591183744ca309fc67cd44f58b563a403092. Final focused Dev and Main runs each pass 11/115.
Five JS regressions and synthetic browser desktop/mobile checks pass. Dev trusted HTTPS login200,
report302 and employee-lookup302 verify the unauthenticated FPM paths; they do not establish an
actual employee's authenticated UI acceptance. Original staged blobs remain byte-identical.

The shared authoritative checkout retains its older HEAD and unrelated pending work rather than
being reset or bulk-committed. This task's source remains there and is verified; scoped publication
used isolated Dev/Main worktrees with correct candidate-local loaders. Generated Swagger remains
an uncommitted derived verification artifact. No production report installation, migrations,
permission grants, provider/queue/scheduler changes or employee-assistant retrieval were performed.
HR-2026-11-22-TIME-REPORTS stays Pending. The approved source merge is complete.

Public-safe editorial handoff: one item added/read back in the fixed Content and Visibility queue.
Status is klar for vurdering, with explicit do-not-publish gates for human/production verification.
No public website or social publication is authorized or performed.
