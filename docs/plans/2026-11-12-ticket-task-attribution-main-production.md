# Ticket/Task attribution Main and production rollout - 2026-11-12

Status: Main Merged / Production Installed And Verified / Human Approved.
Reviewer/release authorizer: Svein Tore, 2026-11-12:
"Godkjenner. Merge til Main og push til produktsjon".
HR-2026-11-12-TICKET-TASK-ATTRIBUTION is Reviewed for this delivered feature.
Unobserved employee-assistant retrieval remains a separate follow-up.

## Scope and isolation

Main base09d1d597; isolated candidate codex/ticket-task-attribution-20261112.
Only owned Dev deltas are three-way promoted; unrelated shared Dev changes stay
in /var/Projects/tdPSA. One handbook append was manually reconciled without
replacing existing Main prose.

Main lacked the already-installed 2026-11-10 Ticket Blade block-directive repair.
Retain that exact repair/comment and OperationalBladeSyntaxTest as a prerequisite;
otherwise a source release could reintroduce the known Ticket500 incident.
This does not authorize the unrelated seven-file PRIVATE-customer repair for Main.

## Test and review evidence

Isolated Main PHP8.3.36:105 tests /42727 assertions PASS, with four existing
PHPUnit doc-comment metadata deprecations. Includes16 attribution cases, Task
module, schedule/recurrence, Portal/lifecycle/API parity, Intake/Telephony and
full operational Blade syntax. Earlier Dev evidence is in the verification report.
Only an existing explanatory Blade comment was retained after that matrix;
narrow full-template compilation is repeated before source publication.

## Production preflight and protected baseline

Strict stored SSH host key verified. Approved owner account/sudo route verified
on VM169/nexumpsa, active release /var/www/nexumpsa/releases/20261109-hosting-4262f1bb.
PHP8.3.6, php8.3-fpm active. Scheduler timer/worker unit remain disabled.
Protected preimages: /root/nexum-attribution-release-20261112/preflight.json.
The manifest covers38 runtime paths (34 existing/four new), plus environment,
cached config, dependency manifests/autoload and built-asset hashes.
Actual affected live files equal Main base except the separately known Blade
repair; retain that exact repair in candidate. No other live overlay is overwritten.
The earlier PRIVATE-customer production overlay is outside these paths and retained.

## Installation and rollback plan

Install exactly the Main-verified38-file manifest, with live-preimage hash checks.
Save existing files and table DDL privately, enter brief maintenance, apply only
2026_11_12_120000_add_ticket_task_actor_attribution.php before model activation,
copy source preserving owner/group/mode, verify autoload, regenerate Swagger and
clear views as the web runtime user, reload PHP-FPM and exit maintenance.
No general migrations, vendor/build/env/config replacement or worker/scheduler
activation. Existing runtime flags and unrelated production patches stay unchanged.

Code rollback restores all38 preimages/removes the four new paths with hash checks,
reloads PHP-FPM and clears views. Leave additive columns in place on ordinary
rollback so newly recorded attribution is not destroyed. No database restore is planned.

## Verification after activation

Read back all installed source hashes, exact new schema columns and migration row,
trusted HTTPS login and Swagger, actual Ticket/Task browser responses and typed API
read-back using authorized internal read-only probes. Preserve current customer
records and avoid real writes/provider/mail/notification side effects. Record
maintenance/FPM/unit state, protected hashes and errors. Update only the two
published handbook sections and public-safe editorial status after production
availability is established. No public marketing publication is authorized.

## Completed Main merge and production installation

PR403 merged successfully to main as15104b5bfe5e91a5919bbecd1baee2b0ebe6a5b8.
Independent Git fetch verifies treef905ac4b84d29fe7662140dec20501939899fdff
exactly equals the105-test candidate. Source branch commitac32d182.
A final one-test/two-assertion Blade compilation rerun also passed after retaining
the production repair's existing explanatory comment.

Installed exactly38 runtime paths into the existing active release; every installed
SHA256 matches merged Main. All live preimages matched before installation.
The additive migration completed and the three new columns and migration journal
were independently read back. No historical backfill or ordinary work write occurred.
Privately saved source preimages, table DDL, manifest and rollback script are under
/root/nexum-attribution-release-20261112. Maintenance exited; PHP-FPM is active.

Environment, cached config, composer manifests/autoload and built-asset hashes are
unchanged. Earlier PRIVATE-customer and hosting overlays remain untouched. Worker
and scheduler timer remain disabled. No customer/provider/mail action was run.

Authorized existing-user internal HTTP-kernel reads as the web runtime user:
Ticket page200/header verified, Task page200/header verified, Ticket API200 and
Task API200 with exact nullable IDs and safe ID/name projections. The probe used
array cache/session and fake queue/mail/notifications; its database transaction
was rolled back. This verifies server rendering/authorization, not a fresh browser
login, real write or employee-assistant acceptance.

Trusted public HTTPS: /login200, /api/documentation200, /docs?api-docs.json200.
The actual Swagger page supplied the JSON URL; the guessed /docs/api-docs.json
path returned404 and was not treated as a contract failure or TLS bypass.
Public JSON independently confirms all five readOnly actor fields in both schemas.
Final all38 source hashes and all protected hashes were rechecked.

Four handbook source sections were appended/read back on production, preserving
prior prose and private preimages. BookStack398 revision14 and409 revision43
independently match only the intended availability edits; prior content, owners,
hierarchy and inherited scope are retained. No bulk native Knowledge sync occurred.
Employee-assistant retrieval remains an explicit separate follow-up.

The public-safe editorial queue is updated for verified production availability;
publication itself is not authorized. No additional implementation or rollout
approval is pending for this feature.
