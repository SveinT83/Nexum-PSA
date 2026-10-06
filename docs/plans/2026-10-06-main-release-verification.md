# Main Release Verification - 2026-10-06

Status: release verified; PR #295 merged and deployed on 2026-10-06.
Current operational evidence: 2026-10-06-production-deployment.md.
Review: HR-2026-10-06-RELEASE, In Review.
Svein explicitly authorized merging SSO, Workday, Tripletex and other completed Dev changes.
Remaining practical acceptance is moved to his production review; no unperformed check is
marked Reviewed. The initial request authorized Main merge; Svein subsequently explicitly requested
production deployment and Workday/SSO/Tripletex setup. See the production deployment report.

## Source and scope

Authoritative Dev: /var/Projects/tdPSA.
Baseline Dev: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98
Baseline Main: d4f3342158d47807f2509bbac3be9aee05597eb9
Initial assembled tree: 6e2370a0b04b3a935cb3ee317fbf3bc09e91e5f8

The candidate combines the four completed local Dev commits with selected working-copy
files, while preserving the latest Main history. It includes SSO, Workday/Tripletex,
Calendar/work-plan integration, Worklog API, Task templates, canonical Contacts, notification
delivery, Mail fixes, Knowledge/BookStack revision workflows and dependency updates.

Unfinished Vault runtime, its database migrations, guarded authentication provider/writers,
and their tests are excluded. Three unrelated scratch names are excluded. Their authoritative
Dev files remain untouched. Vault planning/review documentation is retained as historical
coordination state and does not activate the unfinished product.

Shared-file selection retains existing production Fortify/password/invitation behavior.
Workday profile projection and locking are retained without the unfinished security writer.
SSO retains its strict authentication-driver validator and existing Eloquent MFA path.
Its optional future guarded-provider integration test remains on authoritative Dev and is
excluded from this release together with that provider; ordinary SSO tests remain included.
Vault bootstrap registrations, configuration examples, role permissions and test schema hooks
are excluded. The two-factor confirmed-at cast and diagnostic privacy protections are retained.

Assembly uses a separate Git index. An exported immutable Git tree is tested on the Dev host
with a separate vendor/autoloader and synthetic testing configuration. No real credentials,
database, provider client or live runtime switches are copied into this validation snapshot.

## Verification

- Existing authoritative Dev SSO/security/portal evidence: 222 tests / 1,535 assertions.
- Fresh authoritative Dev Workday/Tripletex run: 223 tests / 2,021 assertions.
- Selected candidate PHP files pass syntax validation.
- Full initial candidate Laravel suite: 2,849 passed and 10 failed (27,416 assertions,
  1,295 seconds). All ten failures were investigated: seven Blade rendering regressions,
  one test-process memory limit, and two release-selection omissions. These are corrected;
  final targeted release-tree regressions pass (see below).
- Workday timeline JavaScript: 8 tests passed.
- Composer manifest and production platform requirements pass on Dev PHP 8.3.
- Fresh Composer audit found two CommonMark advisories in locked 2.10.0. Updated only
  league/commonmark to patched 2.10.2, retaining the other dependency versions.
  Strict composer audit --locked now reports zero advisories and zero abandoned packages.
  Knowledge/Markdown regression coverage passes against the updated lock.
- Release verification exposed two existing Blade component attribute compilation errors in
  Email template editing and Marketing campaigns. Corrected on authoritative Dev; the two
  Email regressions pass (28 assertions) and five Marketing regressions pass (203 assertions).
- The provider deadline test inherited more than its 256 MB worker limit from the full PHPUnit
  process. Reproduced with a 280 MB test fixture, then corrected the test-only worker limit;
  the memory-pressure regression passes (12 assertions). Production worker limits are unchanged.
- Release selection now also retains the existing profile password validation adapter and
  the updated no-default-admin/migration-managed-permissions test. Unfinished guarded writer
  tests remain excluded. Authentication and role/bootstrapping pass in the final tree.
- Final corrected code tree 4070814a91fc394a080d4de0f7e3b2b42be12897: 128 tests pass
  (1,173 assertions, 63.66 seconds), including all ten former failures, ordinary SSO/MFA,
  administrator bootstrap/roles, and the Knowledge/Markdown workflows. No failing test remains.
  The entire 2,859-test suite was not repeated after these bounded fixes; unaffected coverage
  comes from the initial full run, with focused reruns covering every corrected area.
- Final candidate Composer validation, strict locked audit, platform requirements and PHP syntax
  all pass. Only CommonMark changed version after the initial full run (2.10.0 to 2.10.2).
- No Vault runtime directory or Vault/Row04 migration is present in the candidate.
- Source SHA-256 read-back shows no concurrent drift in selected files. Standard Dev index and
  unfinished working files are preserved; final evidence-only documentation follows code tests.

## Production deployment and acceptance

Deployment has now run on Svein's explicit request. The following is the reviewed deployment
procedure; its execution and remaining activation/review state are in the production report.
Keep the previous release and a restorable database backup before running schema/data changes.
This release includes canonical-contact and Knowledge data transitions, not only additive SSO.

Use the normal deployment procedure with the locked Composer dependencies and required PHP
extensions. Run composer install --no-dev --optimize-autoloader, the normal asset build when
needed, php artisan migrate --force, and the normal configuration/view refresh with umask 0002.
Restart long-lived queue workers after deployment. Do not use blanket destructive down migrations.

SSO requires a separate production Keycloak client and production-specific exact callback,
back-channel and post-logout URLs, encrypted provider configuration, trusted HTTPS and callback
query-string log redaction. Do not reuse the Dev client secret or Dev identity/session records.
Keep local sign-in available, then perform the remaining local-MFA/recovery and emergency-admin
checks under HR-2026-10-05-SSO before relying on SSO for routine production access.

Workday requires its configured activation and reviewed permissions. Its retention switch is
independent and must remain off until backup/restore and retention checks are explicitly complete.
Tripletex requires verified company credentials, explicit employee/activity mappings/start dates,
TRIPLETEX_ENABLED and TRIPLETEX_WRITES_ENABLED, and the account's own synchronization switch.
Begin with the approved own-company employee scope. Verify the external minute schedule:run
runner; queue workers and schedule:list alone are insufficient. Sync runs every five minutes.
Check create/edit/read-back and deletion carefully in the chosen test scope.

Human checks remain open under HR-2026-10-05-SSO, HR-2026-10-05-WORKDAY-TRIPLETEX,
HR-2026-10-01-WORKDAY and the included Mail/Worklog/Knowledge/Contact/Task/Notification review
entries. HR-2026-10-06-RELEASE records Svein's decision to perform practical acceptance after
Main merge. Unfinished Vault HR-2026-09-04-003 is excluded and is not waived.

Relevant open review entries retained for production acceptance:
HR-2026-10-06-RELEASE, HR-2026-10-05-SSO, HR-2026-10-05-WORKDAY-TRIPLETEX,
HR-2026-10-01-WORKDAY, HR-2026-09-27-WORKLOG, HR-2026-09-17-EMAIL-MISSING,
HR-2026-09-17-EMAIL-SAVE, HR-2026-09-04-001 (Knowledge approval),
HR-2026-09-03-006 (dependencies), HR-2026-09-03-004 (Web Push),
HR-2026-09-03-003 (BookStack), HR-2026-09-03-002 (Mail read action),
HR-2026-09-03-001 (Task templates), and HR-2026-08-30-001 (Mail storage).
Canonical Contact HR-2026-09-03-005 is already Reviewed, with production migration checks
still required. No review status is upgraded by this merge.

Operational rollback pauses SSO and Tripletex delivery while retaining identity/sync/audit
history, and restores a compatible reviewed application release. Preserve duration-only
Workday data and synchronization baselines. Database restoration requires an explicit plan.

## Migration files changed relative to Main

- database/migrations/2026_08_24_125741_create_ticket_schedules_table.php
- database/migrations/2026_09_03_150000_create_task_template_generation_foundation.php
- database/migrations/2026_09_03_160000_add_task_template_group_to_ticket_schedules.php
- database/migrations/2026_09_03_170000_create_knowledge_article_sync_revisions.php
- database/migrations/2026_09_03_180000_complete_canonical_contact_cutover.php
- database/migrations/2026_09_04_080000_add_knowledge_revision_workflow.php
- database/migrations/2026_09_17_110000_repair_email_unread_access_schema.php
- database/migrations/2026_10_02_120000_create_workday_tables.php
- database/migrations/2026_10_02_120100_deploy_workday_permissions.php
- database/migrations/2026_10_02_140000_create_workday_absence_tables.php
- database/migrations/2026_10_02_140100_deploy_workday_absence_permissions.php
- database/migrations/2026_10_02_160000_create_workday_source_allocations.php
- database/migrations/2026_10_02_180000_deploy_workday_oversight_permission.php
- database/migrations/2026_10_02_200000_create_workday_reminder_receipts.php
- database/migrations/2026_10_02_210000_create_workday_task_conversion_previews.php
- database/migrations/2026_10_05_170000_create_internal_sso_tables.php
- database/migrations/2026_10_05_200000_deploy_tripletex_setup_permission.php
- database/migrations/2026_10_05_210000_enforce_single_tripletex_connection.php
- database/migrations/2026_10_05_220000_create_tripletex_workday_sync_states.php

## Completion

PR https://github.com/SveinT83/Nexum-PSA/pull/295 was merged by Svein at
2026-10-06T13:36:57Z, commit f4b0d4ac1ca4a2a9c86dbbdc78c1635bb4561057.
Codex deployed the exact merged release on Svein's subsequent explicit request.
The earlier GitHub-review blocker is resolved; no ruleset or branch protection was weakened.
See 2026-10-06-production-deployment.md for backups, migrations, live checks, the small
production-only dependency correction and current feature activation state.
HR-2026-10-06-RELEASE remains In Review. Authoritative Dev's unfinished Vault working files
and standard index are preserved; do not promote that entire dirty working tree.
