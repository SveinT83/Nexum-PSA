# Client time report verification - 2026-11-05

Status: Done On Dev / Main Merged And Independently Verified / Human Browser Acceptance Pending.
Owner: Codex. Reviewer/promotion owner: Svein Tore.
RFC: ../rfc/2026-11-05-client-time-report.md.
ADR: ../adr/2026-11-05-client-time-report-source-authority.md.
Human review: HR-2026-11-05-CLIENT-TIME-REPORT In Review; manual checks gate production release.

## Implementation and state
Authoritative /var/Projects/tdPSA, branch Dev. Existing dirty/staged/untracked work preserved.
Scoped source committed/pushed and merged in PR #395; no production deployment, data change or customer send. New employee browser
route tech.clients.time-report (/tech/clients/time-report), Report registry key client.time and
Client Time link. Require all five existing permissions; no grants or new data API.
No migration/backfill, queue/scheduler, provider activation or frontend build required.

## Verification
- Clients and Report feature matrix on PHP8.4.27: 85 tests / 1074 assertions pass (07:32).
- Final PHP8.3.36 report plus temporary synthetic PDF subclass: 23 tests / 219 assertions pass
  (01:01). The 11 report cases run once directly and once through the QA subclass, plus one
  synthetic layout fixture. This is not 23 independent new scenarios.
- Prior focused PHP8.3 matrix before the limit case: 10 / 97; final behavior includes explicit
  10,001-entry rejection for HTML/XLSX/PDF and a valid narrowed-period read.
- Tests cover Task actual5/billing30, zero-time/archived customer records, legacy customer-owned
  archived Task, private Task plus linked billing, other-customer/internal/owner mismatch,
  inclusive work-date and one-sided filters, stored quick and Ticket allocation totals,
  ordered history without financial mutation, permissions and report discovery.
- Actual XLSX output is reopened with PhpSpreadsheet: numeric minute cells and literal
  formula-looking title/note cells verified. Real PDF starts with its proper signature.
- Synthetic 25-entry Norwegian PDF (including long description) rendered to five A4 landscape
  pages and all pages visually inspected. Short entries stay together; descriptions can flow.
  No clipping/overlap or lost Norwegian glyphs seen. Local preview under this chat visualization
  directory only; no customer data used. This is layout QA, not a real customer report.
- Scoped Pint and git diff --check pass. CLI tests use isolated SQLite :memory:, not live DB.
- Initial normal artisan test bootstrap stalled and was terminated before PHPUnit started.
  Explicit testing environment with direct vendor/bin/phpunit used thereafter.
- Live FPM/browser smoke remains unverified: HTTPS dev.nexumpsa.eu timed out from Dev20s,
  trusted hostname/SNI resolve to Dev IP timed out12s, Windows HTTPS timed out15s.
  No certificate bypass, guessed credentials or grant changes attempted.

## Handbook
Source sections updated first in Clients/Report Knowledge Markdown. Supported BookStack connector
appended only these sections, preserving existing articles. Actual Client page451 revision16 new
section exactly matches source after independent read-back; Report page437 revision4 new section
read back. Existing pages451/437 and chapters450/436 inherit, book339 existing overrides inspected;
no access settings changed. This does not prove employee OpenWebUI retrieval.

## Manual gates and limitations
Svein: restore/confirm Dev web reachability and perform HR checklist using the intended customer
and employee accounts; compare source entries and downloaded totals. Internal notes/names are
included; customer-safe delivery policy is unresolved, so no automatic sending or portal access.
Registered Task time may include estimate-completion provenance; not approved payroll.
Processed financial snapshots remain separate from unresolved billing basis; not an invoice or
recomputed timebank balance. Report follows customer source association; use Economy for financial
ownership/invoice history after customer moves, not this work report as accounting evidence.
Per-source/object 10,000 limit is explicit; date narrowing cannot reduce an oversized object list,
which requires separate pagination/scalability work. No silent partial export is returned.

## Deployment and rollback
Svein owns any future source promotion. No deployment performed. Ship scoped source only after
manual acceptance and separately authorized promotion; refresh ordinary Laravel route/config/view
and PHP caches, verify browser/FPM, download both formats. No new migrations/build/background jobs.
Rollback removes only the report route, config definition, Client Time link and report files;
retain source time/order/allocation history and unrelated Dev work.

## Scoped source manifest
- `app/Modules/Clients/Actions/ExportClientTimeReport.php` SHA256 `91d2548efdd3323b8c72b6a5d254460183a94d2bae9c51c10652859c624d9e9f`
- `app/Modules/Clients/Controllers/Tech/ClientTimeReportController.php` SHA256 `16dd3c945a4114a61068c06c20c3b0898aa4c3485ad5ce4d5928202ed8bdaecf`
- `app/Modules/Clients/Queries/ClientTimeReportQuery.php` SHA256 `a223c1727029c8522f38cae6af03d1a58f47b79f6a73bfce064ad0b017d9d54e`
- `app/Modules/Clients/Reports/ClientTimeReportDefinition.php` SHA256 `d9318e9547e4fc6a73d9c8c11209eec2609aa133df346f99af92f6eca8cf1eed`
- `app/Modules/Clients/Tests/Feature/ClientTimeReportTest.php` SHA256 `668a9af5c608b2d75fa8f4e9741a1f8922c3d7ceba97605e42d26220f45aaeae`
- `app/Modules/Clients/Views/Tech/time-report.blade.php` SHA256 `6b88a5ec164c31639af25a22b8cb36d54aacab3dad5daee475f7c30fb944e175`
- `app/Modules/Clients/Views/Tech/time-report-pdf.blade.php` SHA256 `d44860535dea0f6b20d919c3c6c4e964b3606220e441c988f79a90b07cbdf4f9`
- `app/Modules/Clients/Views/Tech/partials/time-report-data.blade.php` SHA256 `c7824266ff6805967f70e1d6039019f6f5519e764fb01e061130ff3c72c75fcc`
- `app/Modules/Clients/routes.php` SHA256 `9839299bb06af6b12be6cde552977ca9855116ad9d73c854b40edac6c510523d`
- `app/Modules/Clients/Views/Tech/show.blade.php` SHA256 `c886753edfddfe2cf64bf64f34386021824cffa91f1196833d203c6842d3ad6b`
- `config/reports.php` SHA256 `14f6962f9363f485ef89722b0bd23d3621478cf53fee8672f31b67f8799b381b`
- `app/Modules/Clients/Docs/knowledge/client-domain-overview.md` SHA256 `91266e19b7513fa833bc971dc93efd325b41a552606d20acc5698eb4fcf139c7`
- `app/Modules/Report/Docs/knowledge/report-domain-overview.md` SHA256 `43363de86a848e69ae267957b03d6056415d8b7832b07fe773897b62a040505c`

## Public website handoff

One public-safe Norwegian item was appended and UTF-8 read back in the verified active-profile
Content and Visibility queue. Status klar for vurdering; explicit do-not-publish gate preserves
human acceptance, production deployment and availability checks. No customer details or internal
infrastructure data included. No publication requested or performed.

## Human approval follow-up

Svein Tore approved the delivered Dev implementation on 2026-11-05 ("Godkjenenr").
The same human-review entry records that approval. No individual manual scenario was reported
as performed; browser, employee-account and assistant retrieval checks remain open. No commit,
push, Main merge, deployment or production verification was requested or performed.

## Scoped Main promotion authorization

Svein Tore explicitly approved commit/push/Main merge on 2026-11-05: "Godkjent. Fix å merge til main".
Candidate based on Main 8737f577461de079e4fed30a67001dfa7dc12ed0. Only eight new feature files,
three integration hunks and scoped documentation/register sections are selected; unrelated dirty
Dev source and handbook changes remain excluded. Main candidate uses its own Composer App/Tests
loader and synthetic test configuration with SQLite :memory:; no live .env copied.
The human review stays In Review for unreported browser/employee-assistant checks before production.

## Isolated latest-Main verification

Main base 8737f577461de079e4fed30a67001dfa7dc12ed0 plus the scoped report passes
87 tests / 1089 assertions on PHP8.3.36 (06:15.784, 518.50MB). Composer.json/lock exactly
match Dev; own Composer App/Tests loader paths independently verified. All eight feature files
exactly match Dev. Scoped Pint5/5, syntax and staged diff checks pass. Regenerated Swagger output
is a test artifact and is excluded; only the 18 approved source/documentation paths are staged.
No application-code repair was needed for Main integration. Main source merge is authorized;
production rollout and unperformed operational acceptance remain separate.

## Verified Main merge - 2026-11-05

PR #395 is merged as bcd643b137fe8cdc1c4afc93179d29fd1e757ce1. The expected PR head was
b64afb55f27e616e3a4930c9faea735e2ca1465e. Independent Git fetch and full-tree comparison verify
cde83d8f19247a64b407fd8ca54852bcd92ee281 exactly; git diff HEAD origin/main was empty against
the tested candidate. GitHub confirmed merge success. No CI statuses were configured/reported for
the source head; the actual PHP8.3 matrix87/1089 and Pint5/5 remain the validation evidence.
Only the scoped18 paths were committed; authoritative Dev index/unrelated dirty work preserved.
A documentation-only follow-up records completed source promotion in the same session.
No runtime tests repeated for this status-only documentation update; application source is
byte-identical to the verified merge. Production deployment and HR manual checks remain separate.
