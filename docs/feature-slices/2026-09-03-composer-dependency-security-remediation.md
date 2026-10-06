# Feature Slice: Composer Dependency Security Remediation

Status: Implemented On Dev / Human Review Pending
Date: 2026-09-03
Parent: GitHub Issue #221
Owner: Svein / Codex

## Goal

Remove every known Composer advisory from the locked dependency graph without changing Nexum's
declared major framework or application package lines, supported PHP 8.2 runtime, database schema,
permissions, or user workflows.

## Baseline

A fresh Dev audit on 2026-09-03 superseded the older Issue snapshot. It found 58 advisories across
16 locked packages: 2 critical, 18 high, 31 medium, 6 low, and 1 unknown severity. Fifteen packages
were reachable from production requirements; PHPUnit was the only development-only package.

Affected package and advisory inventory:

- `dompdf/dompdf`: `PKSA-cv56-2228-pzr6`, `PKSA-6r8f-nxsb-67bq`,
  `PKSA-gh7h-hhy4-byg7`, `PKSA-mwt3-h9tv-kx78`, `PKSA-hp6n-n4kz-21wk`,
  `PKSA-mckv-s5hg-868k`.
- `guzzlehttp/guzzle`: `PKSA-gcrk-3vtt-1r14`, `PKSA-cnw1-2ytm-cgr8`,
  `PKSA-fy2t-3c5f-827y`, `PKSA-qxvb-2bpp-dnk6`, `PKSA-bbs6-q5q9-f3t4`,
  `PKSA-bcdd-5xc7-gwfb`, `PKSA-pwsk-hy21-4gby`, `PKSA-93qv-9n9h-6k6p`,
  `PKSA-k22t-f949-t9g6`.
- `guzzlehttp/psr7`: `PKSA-vznr-tgp9-fd7d`, `PKSA-7qs6-zvnz-h66r`,
  `PKSA-gm5x-j3mz-71n9`, `PKSA-jj5t-2zs1-dcfm`.
- `laravel/framework`: `PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`,
  `PKSA-mdq4-51ck-6kdq`.
- `league/commonmark`: `PKSA-zyf5-hrxv-hrd7`, `PKSA-nv44-1b4d-6gjg`,
  `PKSA-kr3s-894t-g5w2`, `PKSA-9q1p-3s19-bp1q`, `PKSA-5mzr-szzf-z6cn`,
  `PKSA-cqd6-fg4n-nxpf`, `PKSA-1q6p-sqkj-8mmj`, `PKSA-mc58-w91n-f5gv`,
  `PKSA-t21r-vtr5-3mdz`, `PKSA-scnn-p8mm-jbft`, `PKSA-21fb-n1x5-5nf7`,
  `PKSA-2cx9-ynrq-qdk3`.
- `livewire/livewire`: `PKSA-bgw4-5zmg-2njg`.
- `phpoffice/phpspreadsheet`: `PKSA-r22k-87hv-mfk4`, `PKSA-m9cr-9614-rsf7`,
  `PKSA-dqzt-yst9-1w9y`, `PKSA-x678-4z45-v3d5`, `PKSA-gz3f-3cz3-3wsw`,
  `PKSA-x13r-n4wc-4gcr`, `PKSA-8cfg-tzhf-fr83`, `PKSA-hznc-gbby-6w16`,
  `PKSA-jtdk-dcr5-f11n`.
- `phpunit/phpunit` (development only): `PKSA-z3gr-8qht-p93v`.
- `psy/psysh`: `PKSA-4s4z-t146-6123`.
- `symfony/http-foundation`: `PKSA-y6py-qpv1-h52p`, `PKSA-365x-2zjk-pt47`.
- `symfony/mailer`: `PKSA-28rh-rzzn-djk4`.
- `symfony/mime`: `PKSA-wtxr-p26d-nn42`, `PKSA-2n2k-66v2-bwg3`.
- `symfony/polyfill-intl-idn`: `PKSA-dwsq-ppd2-mb1x`.
- `symfony/process`: `PKSA-rkkf-636k-qjb3`.
- `symfony/routing`: `PKSA-bf7t-jnpz-492k`, `PKSA-yc7t-91v9-99xs`.
- `symfony/yaml`: `PKSA-v5yj-8nmz-sk2q`, `PKSA-ft77-7h5f-p3r6`,
  `PKSA-b14r-zh1d-vdrc`.

## Implemented Change

`composer.lock` now selects patched releases inside the existing major lines, including Laravel
12.69.1, Livewire 3.8.7, Dompdf 3.1.6, Guzzle 7.15.5, Guzzle PSR-7 2.13.1,
CommonMark 2.10.0, PhpSpreadsheet 1.30.6, Laravel Excel 3.1.70, PHPUnit 11.5.56,
PsySH 0.12.24, and the compatible Symfony 7.4 patch family. Direct wildcard constraints for
Laravel Fortify and Laravel PWA are replaced with supported bounded constraints.

The transitive `maennchen/zipstream-php` package remains at safe 3.1.2 because 3.2.2 requires
64-bit PHP 8.3 and would silently violate the root PHP 8.2 support contract. HTMLPurifier remains at
safe 4.18.0 because 4.19.0 changes cache-write behavior and failed against the existing shared Dev
cache ownership. Neither retained version has an audit advisory.

No application source, migration, environment value, permission, queue configuration, or runtime
feature flag is changed by this slice.
The final closure run also reconciled stale shared-tree regressions with already accepted behavior:
contract fixtures now use a future agreement period and review terms after lines are created; the
Mail query test follows the accepted direct selected-message read action; legacy Email-provider
redirects retain accessible safe success/error feedback in the unified account workspace; and
Notification/Ticket evidence fixtures account for the immutable Email Live authority bootstrap.
These repairs do not relax the underlying readiness, authorization, provider, or audit controls.

## Compatibility Review

The Symfony 7.4 upgrade guidance was checked against direct application usage. No app usage was
found for the relevant changed extension points, including `Request::get`, custom UUID factories,
custom `GroupSequence` or `Constraint` inheritance, remember-me detail customization, YAML parser
interfaces, or custom container/application `add()` behavior.

An independent patch review caught the initial PHP 8.2 lock incompatibility. The corrected lock has
no package prohibiting PHP or 64-bit PHP 8.2.0, and an actual PHP 8.2.32 Composer install plus
package discovery succeeds.

## Verification

Passed on authoritative Dev:

- `composer validate --strict --no-check-publish`.
- `composer audit --locked`: zero advisories, abandoned packages, and ignored IDs.
- Locked install and Laravel package discovery on PHP 8.2.32 and PHP 8.3.32.
- Lock platform requirements on the provisioned PHP 8.3 runtime.
- `php artisan optimize:clear`, `route:list --except-vendor`, and `view:cache`.
- `npm run build` (493 modules; informational large-chunk warning only).
- Focused auth, Mail, HTTP/integration, PDF, Markdown, queue/scheduler, and spreadsheet coverage.
- Final Data Exchange CSV/JSON/XLSX matrix: 7 tests / 34 assertions.
- Composer diff whitespace check.

After the stale shared-tree regressions were reconciled, the complete PHP 8.3 Dev suite passed on
2026-09-04 with 2,550 tests and 24,750 assertions. The six formerly failing Commercial, Email,
Integration, Notification, and Ticket Rule classes also passed together with 88 tests / 1,102
assertions. A post-patch independent review found one provider-success flash-message overwrite; the
scoped fix and its two-redirect regression then passed 10 tests / 94 assertions and the reviewer
confirmed the finding fixed before the final full-suite run.

PHP 8.2 on Dev can install and discover the exact lock, but its CLI lacks `pdo_sqlite`, so Laravel
tests cannot start there. The same spreadsheet regression passes on the fully provisioned PHP 8.3
runtime.

## Deployment And Rollback

There is no migration. Production deployment must install this exact reviewed lock, clear and
rebuild Laravel caches, restart long-lived queue workers, and perform the checks in
`HR-2026-09-03-006`. Rollback restores the previous reviewed lock, reinstalls it, clears/rebuilds
caches, and restarts workers.

## Done Criteria

- [x] Current affected packages and advisory IDs are inventoried.
- [x] Runtime and development-only exposure are separated.
- [x] The exact locked graph reports zero advisories without ignores.
- [x] PHP 8.2 install compatibility is preserved and independently reviewed.
- [x] Focused consumers, package discovery, routes, views, and frontend build pass.
- [x] The complete repository suite is green after concurrent dirty-tree regressions are resolved.
- [ ] Human review `HR-2026-09-03-006` is explicitly completed by a named reviewer.
