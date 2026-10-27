# Warroom API Main Promotion

Date: 2026-10-27. Status: Scoped candidate verified; Main merge authorized.
Owner: Codex. Source: authoritative Dev /var/Projects/tdPSA.
Approval: Svein Tore explicitly authorized all completed, verified current domain work
for Main promotion: "Vi skal bare få alt merger til main først nå. Det er godkjent".
Human review: HR-2026-10-27-WARROOM-API is Reviewed by Svein Tore; the separate prior
acceptance is preserved. This promotion does not deploy production.

## Candidate

Branch: codex/warroom-api-20261027. Base: 86b82ed8625ff709c5a753b62614f22b46114b35.
All 28 approved Warroom source/migration/documentation hashes exactly match the
verified Dev snapshot. Only this module, its exact additive migration, six catalog
lines (three access modes and three definitions), Warroom Swagger objects and scoped
coordination documentation are included. All existing API paths/schemas are unchanged.
Private vendor/autoload maps, synthetic environment and test database are isolated;
composer.lock matches authoritative Dev. No production configuration is used or staged.

## Verification

Fresh candidate: 95 tests / 1522 assertions, zero failures/errors/skips, 875.28 seconds.
Warroom 31, mailbox delegation/emergency 15, Integration 49. These counts are from
one combined terminal run. JUnit: /tmp/nexum-warroom-main-20261027/final.xml.
Log: /tmp/nexum-warroom-main-20261027/final.log. Exit code 0.
All seven Warroom operations and 25 schemas, source rights/private Calendar/mailbox
boundaries, settings/replay/concurrency and current token/native access are covered.
Pint: 22 files passed. PHP syntax: 26 PHP/Blade files passed. Scoped whitespace passed.
A buffered initial run was stopped for timing diagnostics; migration tracing proved
normal forward progress through the isolated SQLite setup. The diagnostic single test
passed. Temporary tracing was removed before the clean final combined run above.
No known Warroom verification failure is deferred. No application-wide suite was run.

The prior approved native MySQL/HTTPS/handbook evidence remains in
2026-10-27-warroom-api-verification.md and linked JSON files. Actual BookStack 443/444
content/access read-back was verified for the identical source snapshot. No handbook
permission or provider/runtime switch is changed by packaging this candidate.

## Later deployment

No frontend build, worker/scheduler activation or provider call is needed for this change.
A separately requested deployment must apply only the approved required migrations,
including 2026_10_27_110000_create_warroom_api_commands.php, regenerate Swagger and
refresh normal caches. Preserve APP_KEY and retained command/receipt history.
No production operation, real mail, grant expansion or additional product work is included.
