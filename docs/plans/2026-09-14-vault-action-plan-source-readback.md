# Vault Action-Plan Source And Read-Back Checkpoint

Current execution checkpoint (2026-09-17): **4 of 17 main parts Done On Dev**; 04 In Progress.
Owner: Codex / Svein. Workflow repair is Done On Dev; next product deliverable is the complete 04-A flow.
See [the current delivery handoff](2026-09-17-vault-delivery-workflow.md) for the
active batch, terminal evidence, acceptance criteria and exact next action. Sequence 35999 passed;
do not restart it. The linked handoff owns automation/test status. HR-2026-09-04-003 remains Pending;
this is not a practical UI review invitation. No operational migration or runtime activation.
The older dated continuation notes below are historical evidence, not current execution instructions.

## Bounded Slice 04 completion checklist (2026-09-17)

This is an execution checklist under the existing approved Slice 04, not a new RFC,
scope reduction or replacement of its normative Done Criteria. Main-product reporting
stays **4 of 17 complete on Dev**, with part 04 active; these internal packages do not
increase that denominator. Existing implementation is substantial but the packages
below are not complete end-to-end. A test running or a newly added helper is not an exit.

| Package | Remaining integrated outcome / exit evidence | Normative Slice 04 sections |
| --- | --- | --- |
| 04-A Retained security provenance | Close the required UserSecurity flow/subject families, actorless and multi-subject owners, exact auth-epoch invalidation census, factor-generation statement provenance and same-instant order. Historical graph/authority/quorum evidence must retain the actual owner chain after later account changes; malformed or unfinished history denies. Reuse the already verified rehash, password-update, login-identifier-change and expiry/abort readers. | UserManagement Authentication And Authority Epoch; Vault Step-Up Contract; Exact Persistence Contract; Typed Audit Extension |
| 04-B Authenticated planning | Connect current locked authority/scope/graph readers, owned planning transaction, actual actor/session/proof, complete post-review gate and authoritative immutable source writer/finalizer. All closed graph/authority/quorum operations must be classified without caller-supplied trust flags; rollback leaves no trusted plan. | Mutation And Approval Matrix; Concurrency And Authorization Epochs; Canonical Binary And Digest Contract; Planned Implementation Shape |
| 04-C Protected application of changes | Wire graph effects and UserManagement authority changes through the actual one-use subject/fence stores and exact consumed pre-state snapshots. Cover every existing entry path, default-deny unsupported operations, monotonic epochs, cross-client isolation, raw/replay/partial writes and concurrency. | Cross-Domain Vault Authority Mutation Guard; Exact Persistence Contract; Tests |
| 04-D Approval and sole-admin consumption | Complete real request/decision/exception stores and unique owner-bound consumption with the lifecycle coordinator. Revalidate the exact current plan and eligibility; requester cannot self-approve; a second candidate never silently becomes sole-admin. | Candidate, Eligibility, And Decision Readiness; Approval Request And Lifecycle State; Sole-Admin Exception And Post-Review |
| 04-E Quorum and recovery | Complete current eligibility, pre-incident/root provenance, restoration-only scope, actual recovery-authorization consumption, peer unlock and cooling recovery integration. Bind the already verified quorum plan source/finalization rather than recreating it. | Quorum State One-Use Fence; Candidate, Eligibility, And Decision Readiness; Exact Persistence Contract |
| 04-F Post-review lifecycle | Complete authenticated full-set obligation/finding/remediation/confirmation adapters and durable overdue observation. Bind source/audit finalizers to the real coordinator, retaining inert building/aborted classification and blocking unauthorized expansion without trusting cached counts. | Sole-Admin Exception And Post-Review; Exact Persistence Contract; Typed Audit Extension |
| 04-G Installation and guarded cutover | Finish the coordinated exact guard/audit-trust cutover, bootstrap journal/restart classification, readiness and necessary service bindings. Existing-install and empty-install paths must be restartable and reject drift. Runtime gates remain false; no operational migration is authorized by this checklist. | Dormant, Provisioning, And Enforced Phases; Migration And Deployment Gate; Restartable DDL And Audit-Trust Cutover; Locked DML Classification |
| 04-H Integrated exit and handoff | Run the complete affected default-deny, lifecycle, rollback, leakage and driver/concurrency matrix; reconcile all normative Done Criteria, TODO, Knowledge and review evidence. Only then mark part 04 Done On Dev and begin part 05. HR remains a separate named-human gate. | Tests; Documentation; Done Criteria |

Next functional package is 04-A, then the authenticated coordinator 04-B using those
verified sources. Packages 04-C through 04-G may reuse their existing components but
cannot be declared complete by component presence alone. Required gates must not be
dropped to improve the completion count.

### Active verification handoff

The current run state and exact next action are maintained in
[Vault delivery workflow](2026-09-17-vault-delivery-workflow.md).
The prior session-token sequence 35999 has completed successfully on SQLite and both native
drivers. Do not rerun it or wait on its old session handle. Remaining recovery/generation/authority
integration belongs to the complete 04-A outcome, not another isolated status checkpoint.
The older dated notes below preserve implementation history and prior failure evidence.

### Delivery and performance discipline

- Measure native setup, catalog and workflow costs before assuming the whole remaining
  delay is PHP compilation. The first read-only probe used a retained *incomplete*
  diagnostic fixture: catalog rejection was expected, not a product verification pass.
- The identical trigger lookup returned the same table/timing/event in both query forms.
  Adding the already-validated table predicate is a narrowing, not a cached catalog or
  weakened authority check. No trigger/routine SQL predicate or lifetime changed.
- New native regression uses a tiny owned database to test the actual readback comparator,
  including missing/wrong-table/body/timing/event and missing-function rejection. Its
  private miniature manifest does not pass the public complete-assembly builder.
- Full account workflow is still verified on real migrated/sealed synthetic databases.
  Do not replace full acceptance coverage with the tiny lookup regression.
- Evidence from completed earlier sequences is retained. Re-run only affected selections
  after a concrete code change, and run the complete exit matrix once packages are integrated.
- No source edits while a verification process using that source is active.
- On each handoff report main parts completed, active package, actual new result, remaining
  outcome and test state. Do not report a percentage of total effort from the row count.

Latest continuation (2026-09-14): finalized quorum creation read-back is verified on
SQLite and both native drivers. Native mysql PASS 1 / 199 (2614.47s); the durable
mariadb verification 29962 ENDED exit 0, PASS 1 / 199 (2582.19s), no failures/skips.
Logs and JUnit: /tmp/vault-quorum-verification.MXLGiY/. The earlier lost mariadb
output remains unclassified; these results replace the need to recover it.
Graph creation read-back now also requires the original standard-proof window through
plan finalization and retained factor metadata at that finalization time. Withdrawal
before use denies; later withdrawal preserves historical evidence. Existing canonical
source, audit, guard and approval classification checks remain in force.
A real migrated SQLite regression failed before this change (1 / 204 assertions,
39.70s), then the focused corrected selection passed 3 / 308 (69.47s), exec 85285
exit 0. Both changed PHP files pass Pint. Native sequence 17084 ended exit 1:
mysql failed (1 test / 176 assertions, 2477.89s); mariadb did not start.
The fixture's rollback catch masked the underlying RuntimeException with a boolean
assertion. It now rethrows unexpected exceptions; no production behavior was changed.
Diagnostic 96875 ended exit 1: SQLite 1 / 219 passed (40.91s), then mysql failed
1 / 175 (2493.87s) with PDO SQLSTATE 45000 / vault_action_plan_source_denied.
Logs remain in /tmp/vault-graph-factor-diagnostic.QpxIXq/. A shared short-lived proof
across all six scenarios was a timing dependency; each scenario now creates its own
real proof in a distinct synthetic session and asserts freshness before plan creation.
This is a fixture-isolation correction, not yet proof of the original denial's cause.
No production TTL, guard, clock or memory cap was relaxed. Corrected SQLite PASS
2 / 317 (82.26s), exec 12950 exit 0; Pint passes. Native mysql PASS 1 / 226
(3092.82s) and mariadb PASS 1 / 226 (3111.11s). Sequence 1964 ended exit 0;
durable per-driver logs/JUnit are in /tmp/vault-graph-fixture-isolation.pru8De/.
Both driver paths use the isolated MariaDB 10.11.14 service, not Oracle MySQL.
The earlier failed runs remain recorded; the corrected runs resolve both regressions.
Continuation (2026-09-15): the historical expired-on-retry evidence reader now
reads effective terminal/source identity, same-session/purpose replacement, original
creation provenance, commit window and closed reciprocal audit/trust under owned locks.
The expiry finalizer uses this read-back before returning; later replacement activation
or current account/gate changes do not erase the retained revocation. Other terminal
causes, full generation statement provenance and current authorization remain separate.
Initial focused SQLite 2 / 87 and source/lifecycle suite 62 / 4113 passed. Expanded
trust-tamper tests and all Vault units PASS 858 / 28124 (69.92s), exec 1903 exit 0.
Pint passes all three PHP files. Native sequence 70273 ended exit 1: mysql
4 failed / 3 passed, 181 assertions, 216.71s; mariadb did not start. The two new
history tests passed, but retained native fixture graphs exhausted the original private
service's 4 GiB cap (SQL 1041/5 OOM), then the process exited and later connects failed.
The same private data directory was recovered without deleting diagnostic databases,
raising limits or touching operational MariaDB PID 692. Private service PID 772606,
session 78212, retains skip_networking=1, 64 MiB buffer pool, 20 connections and the
original 4 GiB limit. Four legacy multi-purpose tests now release each finished owned
fixture after its assertions, rather than retaining every trigger graph until teardown.
Corrected focused SQLite PASS 7 / 339 (12.67s), exec 67447 exit 0; Pint passes.
Native rerun 11204 also ended exit 1: mysql 3 failed / 4 passed, 140 assertions,
203.82s; mariadb did not start. Per-purpose cleanup alone did not fix the native
allocation problem: enrollment's raw writer still exhausted the original 4 GiB cap.
The private service was recovered again against the same retained data, PID 775248
(session 24058), without changing the cap, network isolation or operational services.
Five existing writer/lifecycle tests now use the already-reviewed exact eight-trigger
cached base representation on native drivers, with the existing full live catalog
attestation and no capability upgrade. Historical metadata-corruption tests retain
their direct representation. Unexpected lifecycle exceptions are no longer hidden by
rollback-sentinel assertions. No product predicates, TTL or permissions changed.
Full SQLite verification 91698 ended exit 0: 858 / 28124, 73.01s. The previously
failing native enrollment lifecycle now PASS 1 / 100 (246.31s), probe 41843 exit 0.
Native sequence 30154 ended exit 0: mysql PASS 7 / 387 (926.41s), mariadb PASS
7 / 387 (871.68s), on the same private service without another restart or limit change.
Logs/JUnit: /tmp/vault-expiry-history.b0CCp8/{mysql,mariadb}-cached.{log,xml}.
Retain failed original/*-fixture-release logs and the successful enrollment probe.
The expiry read-back/finalizer and corrected native fixture matrix are verified.
Retained terminal integration is now implemented in both graph and quorum creation
readers. A shared metadata helper requires the exact actor/session/purpose, proof window
at finalization, and a consistent active terminal shape, or verified expired-on-retry
source/commit/audit history. Pending proofs and unsupported terminal owners fail closed.
Creation trust, factor statement provenance and current authorization remain separate.
Focused SQLite terminal/expiry tests PASS 3 / 232 (5.55s); five files pass Pint.
Broad Vault units plus both real graph/quorum creation integrations PASS 862 / 28770
(196.97s), sequence 65272 exit 0; log/JUnit: /tmp/vault-expiry-history.b0CCp8/sqlite-terminal-binding.*.
Native terminal integration sequence 33244 also ended exit 0: mysql 1 / 69 (64.77s),
mariadb 1 / 69 (65.20s), zero errors/failures/skips, both on private MariaDB 10.11.14.
Logs/JUnit: /tmp/vault-retained-terminal.XPpz06/{mysql,mariadb}.{log,xml}.
These completed sequences must not be restarted. No runtime is activated.
Quorum's retained factor-use timing gap is now corrected: the standard generation
must remain valid through actual plan finalization, not merely request time. The new
real migrated regression failed before the fix (1 / 199, 40.63s), then passed after
it (1 / 231, 42.67s). It covers peer unlock and cooling recovery, disabled/superseded
before-finalization denial, later-withdrawal retention and exact guard restoration.
Related factor/terminal units PASS 2 / 117 (0.22s); both changed PHP files pass Pint.
Native sequence 31153 ended exit 1: mysql failed 1 test / 123 assertions (2447.66s);
mariadb did not start. The rollback fixture masked an unexpected RuntimeException
with assertTrue(false), so this result does not yet identify the failed predicate.
Logs/JUnit: /tmp/vault-retained-terminal.XPpz06/quorum-factor-mysql.{log,xml}.
The fixture now rethrows unexpected errors without changing product code or TTLs.
Diagnostic sequence 56642 ended exit 1: SQLite PASS 1 / 231 (41.69s), mysql
FAIL 1 / 122 (2400.19s), SQLSTATE 45000 / vault_proof_finalization_denied at the
actual action-plan evidence finalizer. The exact denied subpredicate is not measured.
Logs/JUnit use quorum-diagnostic-{sqlite,mysql} in the same directory; preserve them.
The fixture reused one short-lived proof across independent plan scenarios. Each of
its six scenarios now authenticates a separate synthetic session and creates its own
real guarded proof, asserting active state and freshness before plan creation. This
removes that timing dependency without changing product TTLs, guards or semantics.
All original rollback, audit, corruption and factor-history checks remain; trusted
audit counts account for six proofs and three retained plans. Pint passes. Corrected
SQLite sequence 55565 ended exit 0, PASS 1 / 244 (46.93s). Native sequence 71372
also ended exit 0: mysql PASS 1 / 244 (3397.41s), mariadb PASS 1 / 244 (3393.85s),
zero JUnit errors/failures/skips on the same private MariaDB 10.11.14 service and limits.
Logs/JUnit: /tmp/vault-retained-terminal.XPpz06/quorum-fresh-{sqlite,mysql,mariadb}.{log,xml}.
Do not rerun these completed sequences. No runtime activation or operational change.
Next retained UserSecurity work: a primary password-rehash audit reader now binds the
finalized execution, exact consumed one-subject ledger, human/session/epoch identity,
original effect window, released old gate and reciprocal primary audit/trust. It reads
no current credentials or mutable account epoch and does not itself verify the parent's
complete proof-invalidation set. The UserSecurity store reads it back after gate
finalization so a missing/mismatched primary audit rolls back the owning transaction.
Three PHP files pass Pint. Focused migrated SQLite rehash integration passed
3 / 294 (97.25s), exec 8160 exit 0, primary-history-sqlite.{log,xml} in the same
directory. Coverage includes pending/rolled-back denial, exact ownership, missing
catalog, tampered pending trust and audit time, with exact guards restored before
reads. The expanded test now uses a second real protected rehash to prove retained
evidence survives a later gate/epoch change, not a synthetic account edit.
Expanded SQLite rehash/store/resume verification ended exit 0, PASS 21 / 533
(543.98s), exec 63528; primary-history-expanded.{log,xml} in the same directory.
It proves retained evidence after a second actual protected rehash and all existing
store/resume workflows. Native fixture support now uses the existing owned private
DB wrapper, exact SHOW CREATE guard restoration and the approved compiled assembly;
no operational database or credential is used. Fixture SQLite verification passed
1 / 103 (38.55s), exec 40372 exit 0, primary-native-fixture-sqlite.{log,xml}.
Native rehash verification is complete: mysql PASS 1 / 98 (2324.55s), mariadb
PASS 1 / 98 (2505.14s), both with zero JUnit errors/failures/skips. These are the
with_action_plans datasets on private MariaDB 10.11.14, not Oracle MySQL or all
three assemblies. Durable logs/JUnit:
 /tmp/vault-retained-terminal.XPpz06/primary-history-{mysql,mariadb}.{log,xml}.
The local wrapper handle was lost after desktop refresh; ended remote runner/child
and the complete durable results establish completion, not a recovered wrapper exit.
Do not restart this verified sequence. Next: retained auth-security proof-set and
terminal provenance under the same active Slice 04. Factor/recovery work remains open.
Retained password-rehash terminal read-back now has a dedicated closed owner reader:
the exact primary audit and consumed OLD/NEW epoch subject, every old-epoch proof across
sessions, effective source/terminal/commit, and reciprocal audit finalized by the parent.
One unfinished sibling denies the whole set. Earlier expiry requires its own completed
evidence; other UserSecurity flows and unsupported historical owners still fail closed.
The shared graph/quorum terminal helper dispatches only this supported rehash branch;
no credential writer, runtime binding, permission or operational schema was changed.
The first real two-session standard-proof/rehash regression passed SQLite 1 / 140
(48.14s), exec 97242 exit 0. Pending parent and pending sibling trust deny; audit failure
rolls back the real rehash and proof revocations; a later actual rehash preserves history.
Expanded standard/enrollment integration passed SQLite 4 / 572 (173.15s), exec 3559
exit 0. A missing-source-table regression first failed 1 / 24 (0.20s); the terminal
dispatcher now closes that failure without exposing a bound database diagnostic.
The first broad invocation named a nonexistent test file and ran no tests; its log is
retained. Corrected all-Vault-unit plus actual graph/quorum integration verification
passed 862 / 28821 (203.03s), exec 71555 exit 0, with zero failures. All four changed
PHP files pass Pint; permissions are 0644 sveintore:projectusers and diff checks pass.
Native sequence 71921 ended exit 1: mysql standard PASS 1 / 136 (3282.78s);
enrollment errored 1 / 78 (2473.98s), total 5756.88s. MariaDB-driver stage did not run.
The enrollment fixture incorrectly used later rehash effects-start as historical live
proof use; that proof had expired by then. Revocation of an expired active proof is
valid, but claiming that later instant as live proof use is correctly denied.
The fixture now retains the actual proof-creation commit finalization per session,
asserts that instant precedes proof expiry, and uses it for historical read-back.
No product lifetime, guard, clock, or reader predicate was relaxed. Pint/diff checks pass.
Corrected SQLite verification passed 2 / 294 (95.85s), exec 78652 exit 0.
Native sequence 57635 ended exit 0. Corrected mysql enrollment PASS 1 / 140
(3205.67s); mariadb standard/enrollment PASS 2 / 280 (6454.74s), all with zero JUnit
errors/failures/skips. Earlier mysql standard PASS 1 / 136 (3282.78s) remains valid
product evidence; its newer timestamp fixture also passed SQLite. Both PHP driver
paths use private MariaDB 10.11.14, not Oracle MySQL. Do not rerun these results.
Next bounded continuation: prove inert never-activated pending creation history so
it cannot incorrectly invalidate a completed rehash's old-epoch proof census.
Aborted creation and other terminal owners remain separate, closed branches.
Preserve rehash-proof-set-mysql.{log,xml}; corrected logs/JUnit use
rehash-historical-time-{mysql,mariadb}.{log,xml} in /tmp/vault-retained-terminal.XPpz06/.
Sequence 57635 is complete; preserve its results and do not repeat it.
The inert-pending census gap is now implemented with a separate metadata-only reader.
It requires the exact never-activated pending proof, still-building creation window,
closed human/source audit, and wholly pending reciprocal trust. It grants no access,
does not finalize anything, and reads no live credentials. The rehash census excludes
only that verified inert source; unsupported abort histories continue to deny.
A real third-session pending creation reproduced the gap before the change:
SQLite failed 1 / 79 (39.79s), exec 60813. Corrected standard/enrollment integration
passed 2 / 324 (98.03s), exec 41442 exit 0, including forged trusted-projection denial.
Closed metadata vectors passed 1 / 499 (0.95s), including expired pending evidence,
all typed audit references, malformed identity, missing/duplicate rows and serialization.
The four implementation/metadata PHP files pass Pint and retain 0644 sveintore:projectusers.
Broad verification 63009 ended exit 0: 863 tests / 29320 assertions (206.03s), covering
all Vault units and actual graph/quorum creation integrations. Logs/JUnit use
rehash-inert-pending-* in /tmp/vault-retained-terminal.XPpz06/.
A further real-writer lifecycle regression now covers all three proof purposes: actual
pending creation, inert read-back, activation exclusion, outer rollback and committed
activation. SQLite PASS 1 / 36 (1.84s), sequence 53534 exit 0; Pint passes the test file.
Native sequence 10308 ended exit 0: mysql PASS 1 / 48 (151.70s) and mariadb PASS
1 / 48 (148.30s), zero JUnit errors/failures/skips. Both PHP drivers use private
MariaDB 10.11.14, not Oracle MySQL. Owned synthetic fixtures used the exact approved
cached base guards. This verifies the new reader lifecycle, not a repeat of the full
migrated rehash integration. Logs/JUnit use inert-real-writer-{sqlite,mysql,mariadb}
in the same directory. All five affected PHP files retain 0644 sveintore:projectusers.
No test from this sequence remains running; do not repeat completed verification.
Continuation (2026-09-15): DatabaseVaultAbortedProofCreationReader now validates
original creation metadata/audit, the exact building_abort revocation and the winning
db_time_expired cleanup, including all three reciprocal trust rows, ordinary build
windows, monotone commit sequences and closed typed references. It reads only stable
canonical system identity, not credentials or current authorization/factor state, and
never installs a fabricated evidence context. Partial cleanup remains distinct/denied.
The existing abort finalizer now reads this full history before returning; failed
read-back must roll the whole caller transaction back. No runtime binding is added.
Initial actual migrated SQLite cleanup integration PASS 1 / 281 (84.21s), exec 87323.
Pending plus aborted metadata vectors PASS 2 / 1743 (6.97s), exec 43940, including
changed audit/reference/owner/timing facts, duplicate/missing rows and serialization.
The new finalizer binding and post-finalization read-failure rollback injection PASS
SQLite 3 / 2027 (89.38s), exec 97795 exit 0. All four affected PHP files pass Pint and
retain 0644 sveintore:projectusers. Logs/JUnit use aborted-creation-* in
/tmp/vault-retained-terminal.XPpz06/.
Sequential verification 72096 completed exit 0. Broad SQLite PASS 869 / 31085
(418.08s), native mysql PASS 1 / 334 (7388.09s), native mariadb PASS 1 / 335
(7834.61s); all JUnit errors/failures/skips are zero. Preserve the
aborted-creation-history-{mysql,mariadb}.{log,xml} results; do not repeat this sequence.
Both native driver paths used separate synthetic databases on private MariaDB 10.11.14,
not Oracle MySQL. The complete cleanup reader/finalizer is verified on both drivers.
Continuation (2026-09-15 20:19 UTC): the password-rehash census now separately checks
a completed aborted creation through that reader and the complete abort-capable catalog.
The original exact source-count comparison is retained. No terminal owned by the current
rehash may silently disappear; partial cleanup and unsupported terminal owners still deny.
A new actual cleanup-to-rehash regression failed before this binding (1 / 81, 42.99s),
at the expected retained proof-set denial. Only the expired pending origin is synthetic;
cleanup, two protected rehashes and all their audit/finalization writes use actual stores.
Standard/enrollment regressions, both actual protected rehashes and original/winning
cleanup trust tamper cases PASS 2 / 384 (117.50s), sequence 75141 exit 0. Both changed
PHP files pass Pint and retain 0644 sveintore:projectusers. Sequence 90516 completed exit 0:
broad SQLite PASS 868 / 31071 (387.21s), including all six integrated rehash cases
(1032 assertions); native mysql PASS 2 / 358 (8432.08s); native mariadb PASS 2 / 358
(8370.44s). Both native JUnit reports have zero errors/failures/skips. Preserve
rehash-cleanup-{mysql,mariadb}.{log,xml}; do not repeat this completed sequence.
Both PHP drivers use private MariaDB 10.11.14, not Oracle MySQL.
Logs/JUnit: rehash-cleanup-* under /tmp/vault-retained-terminal.XPpz06/.
Continuation (2026-09-16 01:23 UTC): revoked-pending creation has a separate historical
read entry point. It requires the exact still-building origin with wholly pending audit
and its real finalized revoke-only terminal; it cannot claim completed cleanup. The
rehash census checks this distinct branch and retains the exact source-count invariant.
The new actual revoke-only-to-rehash regression failed before binding (1 / 81, 40.31s).
Synthetic origins are explicit; revocation, two protected rehashes and resumed cleanup
use actual guarded stores. Metadata vectors PASS 3 / 2507 (9.78s), including original
pending, completed cleanup and revoked-pending standard/both enrollment purposes.
Sequence 79254 ended exit 1: both new integrations passed the initial retained census,
then failed in the test's audit-tamper setup (2 / 192, 82.72s). The revoke writer returns
a transition ID, not its evidence-commit ID; the fixture now resolves the exact transition's
commit before reading its audit. This is a fixture correction, not a product-policy change.
Four PHP files pass Pint. Sequence 51609 completed exit 0. Its broad SQLite stage PASS
872 / 32507 (591.60s), zero JUnit errors/failures/skips: all Vault units, all eight rehash
cases and actual completed-creation cleanup. This resolves the test-fixture failure;
retain the failed run for diagnosis. Actual revoke-only retention, both protected rehashes,
tamper rejection and later resumed cleanup pass for standard and enrollment proofs.
Native mysql PASS 2 / 362 (8893.39s), zero JUnit errors/failures/skips. Preserve
rehash-partial-cleanup-mysql.{log,xml}; do not repeat the completed driver.
Native mariadb PASS 2 / 362 (8871.50s), zero JUnit errors/failures/skips, verified
2026-09-16 06:49 UTC. Preserve rehash-partial-cleanup-mariadb.{log,xml}; do not repeat.
Both PHP drivers use private MariaDB 10.11.14, not Oracle MySQL.
Logs/JUnit: rehash-partial-* in /tmp/vault-retained-terminal.XPpz06/.
This remains a password-rehash reader, not a generic UserSecurity owner. Other security
flows and factor statement provenance remain open; partial-cleanup integration is now
verified on both native PHP driver paths. No runtime consumer or new authority is enabled.
Continuation (2026-09-16): retained account history is being extended to the exact
single-subject human password-update and login-identifier-change flows. They have no
primary Vault audit; their original subject ledger and exact transition audits remain
required. The rehash-only entry point retains its existing restriction. Actual guarded
account changes reproduced the missing retained owner before the change: 2 failures /
27 assertions (64.21s), account-history-before.{log,xml}. The shared proof census is
unchanged except for explicit owner dispatch; no writer, guard, runtime binding or schema
is widened. Sequence 92484 PASS 2 / 71 (73.06s) on SQLite. Expanded negative tests initially
hit the schema's equal expected/consumed-count CHECK instead of the reader (sequence
34731, 2 failed / 1 passed, 123 assertions, 69.03s). The isolated corruption fixture now
changes both counts together; no constraint or product rule was weakened. Corrected
sequence 71349 PASS 3 / 291 (84.08s), zero JUnit errors/failures/skips: ownership and
redacted diagnostics, wrong flow/subject/epoch/count, unfinished parent, extra forbidden
subject fields, pending gate, terminal trust tamper, and retention after a later change.
All six changed PHP files pass Pint; new files are 0644 sveintore:projectusers.
Sequence 47253 completed exit 0. Broad SQLite PASS 875 / 32798 (660.81s), zero JUnit
errors/failures/skips: all Vault units, ten integrated rehash/account cases and actual
completed-creation cleanup. Native mysql PASS 2 / 67 (4752.73s), zero JUnit errors,
failures or skips. Preserve account-history-mysql.{log,xml}; do not repeat that driver.
Native mariadb PASS 2 / 67 (4987.46s), zero JUnit errors/failures/skips, verified
2026-09-16 09:59 UTC. Preserve account-history-mariadb.{log,xml}; do not repeat this
completed sequence. No test process from sequence 47253 remains running.
Logs/JUnit: account-history-{broad,mysql,mariadb}.{log,xml} in the same temporary directory.
Both native PHP drivers use private MariaDB 10.11.14, not Oracle MySQL.
Other UserSecurity flows, multi-subject and actorless execution
history, factor statement provenance and authority/quorum consumers remain open.
Evaluation checkpoint (2026-09-16): Svein questioned repeated status-only progress.
The existing verification was allowed to finish; no additional test sequence or feature
code was started during evaluation. Automatic continuation is now PAUSED (read back).
Slice 04 remains In Progress, not complete or ready for practical human review.
Before resuming feature implementation: reconcile a bounded Slice 04 completion list
with approved requirements, measure native setup/catalog/runtime costs, and agree the
continuation approach with Svein. Repeated full catalog compilation/attestation is a
candidate cost center, not a measured diagnosis. Preserve all approved security gates.
No new restriction was installed in the credential write path. Complete owner dispatch,
factor statement provenance, authority/quorum consumption and recovery remain open.
Prior broad creation verification remains 898 / 35894 SQLite, mysql 7 / 772 and
mariadb 7 / 772. Previous native quorum results do not cover the new graph change.
Authority classification/reader dispatch, recovery eligibility and root provenance,
historical proof terminal/generation statement provenance and lifecycle work stay open.
Slice 04 In Progress; product rows 05..16 unfinished; runtime remains disabled.
HR-2026-09-04-003 Pending / not practical-review-ready.
Checkpoint: docs/plans/2026-09-14-vault-action-plan-source-readback.md.
Automatic continuation is PAUSED for delivery-workflow evaluation with Svein.

Date: 2026-09-14
Status: In Progress under approved Slice 04
Owner: Codex
Human review: HR-2026-09-04-003 Pending / not practical-review-ready
Parent: ../feature-slices/2026-09-04-vault-central-authorization-grants-collections-step-up-approvals.md

## Implemented boundaries

- VaultActionPlanSourceGuardDefinitions: exact one-use base INSERT, current installation,
  security/post-review gates, owning building commit and requester-proof provenance;
  immutable UPDATE/DELETE. Standard graph/authority/quorum and both permitted enrollment
  purposes are supported. No approval, effect or trusted finalized snapshot is created.
- VaultActionPlanRequesterProofPredicate: actor/session/authentication/authority epoch,
  current factor, active proof, counter, expiry, exact creation commit/audit/trust and
  closed references. A later accepted TOTP counter does not invalidate an earlier proof.
- VaultActionPlanChildSourceGuardDefinitions: graph/authority immutable headers and
  append-only contiguous subjects under the same fresh pending base and source context.
  Cross-family children, missing header, skipped sequence, stale/finalized owner, replay,
  wrong name/approval classification and changes to retained children deny.
  This is not quorum draft finalization or complete subject/operation validation.
- VaultCanonicalDocumentDecoder: bounded 4096-byte inner-document decoding through the
  sole existing encoder's family and exact re-encoding contracts. Full unsigned integers
  never pass through float; signed UTC microseconds, UUID and NFC/control rules are retained.
  Decoding bytes alone does not establish domain semantics or authority.
- VaultAuthorityPlanSubjectHydrator: exact retained identity/sequence, complete canonical
  subject digest and existing operation matrix, shadow user/role/permission targets and
  embedded operation/reference agreement. Emergency state is rebuilt through the existing
  typed authority-state value and subject factory, including epoch/lock/cooling rules.
  This is a pure helper, not an authenticated DB reader or finalization capability.

- DatabaseVaultAuthorityPlanSubjectReader and DatabaseVaultGraphPlanSubjectReader: read
  the complete plan subject identity under the exact caller-owned transaction, without
  installation/kind filters that could hide corrupt rows, and apply bounded typed hydration.
  Neither returns a trusted action-plan snapshot or authenticates its owning plan/audit.
- VaultGraphEntryHydrator and VaultGraphPlanSubjectHydrator: reconstruct all six stored
  entry families and graph transitions through the existing typed values/factory, then
  compare every derived metadata column, complete subject digest and operation matrix.
  Item scope, canonical names/name keys, redundant active flags and grant effect remain exact.
- VaultGraphPlanSubjectMatrix extracts the unchanged matrix from VaultGraphPlanAssembler
  for shared planner/read-back use. This changes no approval or operation rule.

All these classes are unbound, with no operational installer or migration invocation.
External dependencies in source-guard tests are explicitly synthetic and mutable to
exercise denials. The tested plan/child DDL is actual application DDL. Noncanonical
pending fixture entry bytes do not prove finalization, approval, or execution.

## Verification

- Initial base tests: SQLite/mysql/mariadb each 5 / 344.
- Expanded base provenance, all unrelated audit refs, owner fields, plan dates/digests:
  SQLite 5 / 591 (2.25s), mysql 5 / 591 (28.68s), mariadb 5 / 591 (25.92s).
- Actual base plus graph/authority child DDL and guards:
  SQLite 5 / 642 (2.52s), mysql 5 / 642 (42.94s), mariadb 5 / 642 (41.78s).
  Native tests use separate owned schemas on the isolated skip-networking socket
  /tmp/tdpsa-vault-maria.CESC7v/server.sock, never operational MariaDB.
- Canonical decoder: 28 / 142 (0.12s), normative integer/instant vectors, malformed
  lengths/tags/types, truncated header/payload, wrong family, NFC/control/UUID denial
  and exact size boundary.
- All Vault unit tests plus actual source-guard test: 804 / 26201 (67.70s), no skips,
  before the following new pure hydrator. Log:
  /tmp/vault-action-plan-source-and-canonical-20260914.log.
- Authority subject hydrator: 12 / 424 (0.24s), all ten registered kinds, supported PDO
  scalar representations, shadow target drift/coercion, changed owner/sequence/operation,
  missing/reordered/duplicate subjects, digest/epoch mismatch and malformed emergency state.
- Previous UserSecurity optimization is closed: sequence 13919 passed both nine-denial
  native probes and 60 / 1270 focused consumers. The broader 1248 / 48064 run and both
  full native creation-cleanup drivers passed under the controlled private allocator.
  See 2026-09-13-vault-mariadb-trigger-memory-rework.md for retained failures/limitations;
  do not infer default-allocator or operational resource readiness.

## Final read-back verification in this checkpoint

- Authority-only locked reader: actual subject DDL, SQLite/mysql/mariadb each 1 / 17.
- Expanded authority plus graph locked readers: SQLite 1 / 28 (0.55s), mysql 1 / 28
  (2.27s), mariadb 1 / 28 (1.93s), separate private schemas; all runs ended.
- Graph inner entries: 6 / 53 (0.11s), every family and invalid type/count/derived facts.
- Shared graph matrix extraction: unchanged original assembler tests 6 / 66.
  Expanded retained subject reconstruction exercises every graph operation: 6 / 386
  (0.30s), including shadow metadata, raw entry, identity/order/matrix and digest denials.
- The first expanded graph test rejected its synthetic in-memory epoch-zero fixture.
  That is correct for persisted rows (positive scope epoch). The test now explicitly
  replans first-use cases after scope initialization to one before testing persistence;
  no runtime epoch/schema rule was changed. Corrected verification passes.
- Final all Vault unit tests plus both source/read-back integration classes:
  823 passed / 27026 assertions, no skips, 67.07s, exec 90514 ended exit 0.
  Log: /tmp/vault-plan-source-readback-final-20260914.log.
- Live safe health read-back: provider local_sealed, status vault_disabled.
  Independent safe config read confirms vault.enabled=false and runtime_approved=false.

## Remaining in this same active slice

Current continuation (2026-09-14, retained header/source/audit and pending writer):

- VaultActionPlanHeaderHydrator reconstructs both frozen plan documents from exact
  base/subtype columns, proof XOR, typed scope/reason/version/time, and canonical digest.
- DatabaseVaultActionPlanSourceReader locks the complete same-ID base, only permitted
  subtype and complete subjects. Other family headers/subjects deny; a graph name must
  match the retained subject. These are canonical inputs, never trusted action-plan snapshots.
- DatabaseVaultActionPlanEvidenceReader reads actual reciprocal commit/audit/trust facts,
  all typed references, actor/scope/reason and time bounds. Building remains building.
  It does not replace live proof/graph/authority checks or finalize anything.
- DatabaseVaultActionPlanSourceWriter stages graph/authority sources under an already
  building owner with exact trigger-catalog attestation, actor/session/root locks and
  one-use context per INSERT. Its fixed PDO sink binds the opaque session directly.
  Complete read-back is mandatory; the caller owns rollback. No effect or audit is
  performed by this low-level source staging component.
- DatabaseVaultPendingActionPlanWriter allocates the commit sequence, stages complete
  sources and inserts the actual pending audit through the existing production sink,
  then reads back reciprocal evidence. It requires the full existing audit/proof catalog.
  It does not finalize the plan or substitute for authenticated planning/coordinator checks.
- Header tests PASS 2 / 242. Source plus reciprocal evidence reader tests PASS 4 / 616:
  SQLite 2.05s, mysql 22.06s, mariadb 21.86s. Synthetic mutable evidence rows in this
  reader test exercise corruption and are not finalizer coverage.
- Expanded source staging writer includes authority.totp_confirm enrollment-purpose proof:
  SQLite 3 / 126 (1.08s), mysql 3 / 126 (21.01s), mariadb 3 / 126 (20.88s).
- Actual migrated proof/source/audit writer integration PASS SQLite 1 / 48 (31.43s),
  mysql 1 / 48 (1234.85s), mariadb 1 / 48 (1241.94s). Native sequence 92578 ended exit 0.
  Both native drivers used the same isolated skip-networking process, without restart
  or cache flush. Sequence/source/audit rollback and pending exclusion from trusted
  audit views are verified; this does not finalize or authorize a plan.
- Complete first regression after the enrollment expansion: 833 / 28058, no skips,
  101.25s, exec 20577 ended exit 0.
  Log: /tmp/vault-plan-pending-writers-20260914.log.

Earlier continuation (2026-09-14, structural finalization binding):

- VaultActionPlanSourceGuardDefinitions now exposes a fixed finalization/coupled base
  predicate alongside its unchanged pending-INSERT grammar. Finalization still requires
  current fresh requester proof, actor/session/auth/authority epochs, clear security and
  post-review fences, the exact live owner, and one-use finalize context. Coupled reads
  require that context consumed and the exact finalized owner; no source write becomes final.
- VaultActionPlanFinalizerBinding supplies before-commit, coupled completion and trust-update
  predicates for graph/authority only. It requires one same-ID matching header, complete
  contiguous subject identities, closed per-operation subject multisets, shadow relationships,
  exact name/approval classification, scope/reason, every typed audit reference and reciprocal
  trust/commit finality. Quorum headers/subjects and unrelated family children reject.
  Canonical byte recomputation and current planning authority remain application responsibilities.
- At that checkpoint the binding was not yet wired into the shared proof/UserSecurity
  finalizer or catalogs. The following continuation now connects creation finalization;
  operational installation, planning authorization and the product workspace remain separate.
- The production binding is exercised inside isolated probe triggers on actual plan/child DDL
  with explicitly synthetic mutable external evidence/audit/proof fixtures. All three source
  families (graph, ordinary authority, enrollment authority) verify missing audit/trust,
  wrong/inactive context, all typed-reference corruption, actor/event/reason/scope/time drift,
  quorum contamination, incomplete subjects, reciprocal finalization, outer rollback and replay.
  This is structural binding coverage, not complete shared-finalizer or all-operation integration.
- A first probe failed because its synthetic dependency fixture inferred active_slot TEXT
  from the first terminal row's NULL. The test dependency now declares active_slot integer,
  matching the actual schema. No production type, lifetime or guard was weakened.
- Source plus binding tests PASS SQLite 6 / 642 (3.83s), mysql 6 / 642 (56.72s),
  mariadb 6 / 642 (50.83s). Native sequence 69824 ended exit 0.
- Final combined regression PASS 836 / 28574, no skips, 110.26s, exec 9393 ended exit 0.
  Log: /tmp/vault-plan-binding-regression-20260914.log. Pint --test all 12 current files
  and git diff --check PASS. No verification process remains active.

Current continuation (2026-09-14, integrated graph/authority creation finalization):

- VaultProofCreationFinalizerDefinitions and VaultAuditTrustUpdateGuardDefinition now have
  one explicit complete action-plan-enabled assembly option. Existing default manifests
  remain unchanged. The option requires full attempt/abort support; commit BEFORE consumes
  the exact finalize context, source/audit checks are repeated, and audit trust finalizes LAST.
  This extends the shared finalizer, not a separate permissive UPDATE path.
- VaultProofFinalizationGuardCatalog attests the selected complete raw or compiled assembly.
  The new variant also requires every one of the 15 immutable plan-source/child guards.
  VaultMariaProofGuardRoutineManifest/Catalog accept only the two complete code-defined
  representations (existing or action-plan-enabled), with exact routine/trigger/parameter
  read-back. Mixed variants, missing bodies and extras are not accepted; no DDL repair,
  runtime settings or cached authority were introduced.
- DatabaseVaultActionPlanCreationFinalizer is implemented with owned transaction, actor/
  session and root-first locks, current security/authority/post-review fences, locked source
  proof epoch, complete source/subject canonical digest comparison and actual reciprocal
  evidence/audit read-back before and after the one-use commit CAS. It returns only actual
  finalized evidence; no caller boolean or fabricated trusted action-plan snapshot is used.
  The calling planning coordinator still must authenticate current planning facts and own
  rollback. This helper does not approve a plan, authorize its effects or change access.
- VaultIntegratedProofTestAssembly installs the full new variant only on isolated test DBs.
  The actual migrated plan-writer test now covers the existing inert assembly and the new
  finalized assembly, canonical mismatch, wrong actor, old-assembly denial, rollback/replay,
  trusted-audit inclusion only after finality, and no UserSecurity mutation or proof consumption.
- Actual SQLite finalization first PASS 1 / 88 (35.41s). Combined all-unit/source/read-back/
  plan-writer and existing/new consumer regression PASS 849 / 29412, no skips, 578.32s,
  exec 70813 ended exit 0; log /tmp/vault-plan-finalization-assembly-20260914.log.
- After additionally requiring all 15 source guards in the complete catalog, final migrated
  pending/finalized writer tests PASS 2 / 140 (62.85s), and new-assembly existing consumers
  PASS 4 / 250 (159.69s): standard issuance/reuse, enrollment-purpose issuance, denied/rate
  attempts and internal password-rehash auditing. Exec 16826 ended exit 0.
  Pint --test all 11 changed files and git diff --check PASS; files normalized to 0644.
- Native full migrated new-assembly PASS mysql 1 / 88 (1382.99s; body 1382.87s)
  and mariadb 1 / 88 (1387.19s; body 1387.08s). Sequence 91937 ENDED exit 0.
  Both used the same private skip-networking socket
  /tmp/tdpsa-vault-maria.CESC7v/server.sock, without restart or cache flush.
  Controlled allocator/cap unchanged; this is not operational allocator acceptance.
  Both owned test schemas were independently confirmed absent after cleanup.
  Logs: /tmp/vault-action-plan-finalization-mysql-20260914.log and
  /tmp/vault-action-plan-finalization-mariadb-20260914.log.
  The prior native 1 / 48 was pending-only.
- No provider binding, operational migration/guard activation, runtime activation, real secret,
  Main/production change, commit or push. HR-2026-09-04-003 remains Pending, not UI-review-ready.

Current continuation (2026-09-14, complete structural planning inputs):

- DatabaseVaultGraphStateReader now reads the retained root and scope under the exact
  caller-owned transaction, then deterministic complete group/collection/item/grant/edge sets.
  It rejects pending scope execution, absent anchor, foreign scope/installation, noncanonical
  names, derived active-slot/key mismatch, missing referenced records and changed structural
  digests. Revoked/disabled rows remain represented; no ciphertext or credential material
  is selected. Related-edge reads also include foreign-scope edges to local records rather
  than concealing them behind a scope filter.
- This is structural read-back, not authenticated planning authority. The coordinator must
  still establish actor/proof, authority/policy, current candidate/visibility facts and graph
  execution provenance. Group/collection aggregate digests are retained and scope-bound;
  this reader does not independently derive those aggregate digests from membership/link
  history. Missing-anchor first use is deliberately a separate coordinator path.
- VaultGraphPlanAssembler now rebuilds each subject through the existing transition factory
  using the supplied actual before/after graphs, compares canonical bytes and shadow metadata,
  and requires every unlisted row to stay byte-identical. This includes item metadata, which
  is deliberately not in the five scope digests. Approval/reachability classification happens
  only after that exact check. No caller-selected approval rule was introduced.
- Two regression tests failed before the assembler change (hidden item addition and stale
  before bytes) and pass afterwards. All twelve operation fixtures still pass; focused
  assembler PASS 8 / 400 (0.20s).
- Actual graph DDL reader test PASS SQLite 1 / 73 (0.65s), mysql 1 / 73 (5.58s), mariadb
  1 / 73 (5.01s). External identity/execution parents and their aggregate digests are explicitly
  synthetic; no graph write provenance or operational authorization is claimed. Three
  cross-scope edge corruptions are rejected by actual composite foreign keys before read-back;
  other shape-valid drift is rejected by the reader. Outer rollback restores the same graph.
  The item fixture deliberately has no secret-bearing columns.
- Initial test failures were synthetic parent collation mismatches and expecting an application
  rejection where an immediate composite FK already rejects. Fixtures now match each actual
  native UUID edge (new ascii_bin, foundation utf8mb4_unicode_ci) and assert those FK denials.
  No production constraint, parser or authorization rule was relaxed.
- Final all Vault unit tests plus graph reader, action-plan source/evidence readers and both
  fully migrated pending/finalized writer variants PASS 828 / 27441, no skips, 129.35s.
  Exec 53734 ENDED exit 0. Log: /tmp/vault-graph-planning-inputs-20260914.log.
  Earlier broader native finalizer verification remains recorded above; the new reader's
  independent native tests do not substitute for full future planning-coordinator coverage.

Current continuation (2026-09-14, current authority roster planning reader):

- DatabaseVaultAuthorityPlanningReader now takes root, authority and UserSecurity-gate
  locks before loading complete bounded numeric user/role/permission identities, byte-exact
  polymorphic pivots and factor-generation metadata. It requires enforced, clear pending
  fences, the two exact protected web roles and all six exact control-plane permission IDs.
  It uses direct current rows, not role shortcuts, Eloquent/Spatie cached assignments or
  user-provided readiness flags.
- Candidate counting is independent of permissions, factors, sessions and proof possession:
  distinct ACTIVE non-system humans with Admin/Superuser identities, including both roles
  on one user without double-counting. Governance eligibility separately requires both
  effective policy/approval permissions and current confirmed TOTP. Retained legacy direct
  grants are read but no new direct grant path is introduced.
- Every candidate's protected role IDs, Vault authority epoch, effective policy/approval
  permissions and confirmed-factor facts are canonically reconstructed. Candidate, governance
  and global-fact digests must all match the retained authority state. Orphan/duplicate pivots,
  missing identities, wrong guards, stale classification/epoch/factor metadata and digest drift
  deny. Wrong model_type bytes never alias the canonical User model through DB collation.
- Optional role-wide membership enumerates every matching User row, including inactive and
  system users, bound to the locked gate revision; that is deliberately not the candidate list.
  No password, secret, recovery-code, session, proof or accepted TOTP-counter value is selected.
  Second-precision legacy confirmation metadata is normalized only through the existing exact
  timestamp rule; current DB time controls future-confirmation eligibility.
- This reader returns canonical current facts, not an approval or permission to execute.
  The owning coordinator still must attest guards, source provenance, exact scope visibility,
  requester proof and policy/floor requirements. It does not approve a low-readiness roster,
  activate runtime, validate an emergency root event's audit provenance, or perform a mutation.
- Actual authority-state DDL with explicit mutable external metadata fixtures PASS SQLite
  1 / 252 (0.66s), mysql 1 / 252 (5.49s), mariadb 1 / 252 (4.32s).
  Both native stages ended successfully in 53538 on owned private schemas. Tests cover
  roster deduplication/exclusions, readiness separation, legacy direct grant, complete role
  membership, 24 corruption/fence cases and outer rollback. No session/proof tables or
  credential-bearing user columns exist in the fixture, proving those are not dependencies.
- The first test invocation found a test-helper name collision with Laravel TestCase::seed;
  the helper was renamed seedMetadata. No runtime behavior was relaxed.
- Final complete Vault unit set plus current authority/graph readers, action-plan source/
  evidence readers and both fully migrated pending/finalized writer variants PASS
  829 / 27693, no skips, 128.53s. Exec 73625 ENDED exit 0.
  Log: /tmp/vault-current-authority-planning-20260914.log.
  Pint --test and git diff --check pass. Both new files are normalized to 0644.

Current continuation (2026-09-14, exact graph aggregate contract):

- Read-back against the approved Slice 04 canonical mapping (the exact set-kind paragraph
  following the inner-entry schema) found the unbound implementation used graph-scope-*
  labels instead of groups, collections, group-members, collection-items and grants.
  Svein has explicitly established the approved RFC as truth. A new empty/nonempty exact
  protocol test failed before correction and now passes; the normative document is unchanged.
- Before changing those bytes, safe operational Dev read-back confirmed vault_authorization_scopes,
  vault_action_plans, vault_graph_mutation_plans and vault_graph_write_executions are NOT INSTALLED.
  Health remains local_sealed / vault_disabled. There is no retained operational graph/approval
  to rewrite. No compatibility fallback, data migration, operational DDL or runtime switch was
  introduced. Old private diagnostic fixtures remain historical evidence, not current-format input.
- VaultCanonicalBinaryV1 now exposes two fixed aggregate encoders: digestGroupMembers and
  digestCollectionItems. They use the approved group-members/collection-items set families and
  each parent's own positive auth epoch, exact scope and complete retained relation documents.
  Wrong type/parent/scope, duplicate relation identity and oversized/malformed lists deny.
  Empty sets still include the canonical installation/scope/epoch/count-zero header.
- DatabaseVaultGraphStateReader now independently recomputes every group's membership digest
  and every collection's item-link digest, including retained inactive relations, before comparing
  the five complete scope digests. Thus even a recomputed scope digest cannot conceal a stale
  parent aggregate. The earlier checkpoint's aggregate-read limitation is resolved for this reader.
- Actual-DDL reader fixtures now derive real parent aggregates instead of placeholder bytes.
  Two new rollback cases deliberately keep the scope hash self-consistent while corrupting one
  parent aggregate; both are rejected. Pure tests compare exact independent outer documents
  for empty/nonempty scope and parent sets and cover wrong parent/scope/type/duplicate inputs.
- Focused SQLite PASS 7 / 140 (0.82s). Actual graph-DDL native reader PASS mysql 1 / 81
  (6.18s) and mariadb 1 / 81 (5.05s), separate owned schemas on the same private socket.
  Both native stages and final full unit/current-reader/source/migrated-writer regression
  completed in sequence 48362, exit 0: 831 / 27725, no skips, 130.28s.
  Log: /tmp/vault-graph-aggregate-contract-20260914.log.
- Shared finalizer SQL is unchanged. The current native coverage here is the graph reader;
  earlier full native finalizer results were obtained before this canonical-token correction
  and are not presented as another current full planning-coordinator run.
- No binding, operational migration, activation, credential change, commit, push or Main change.
  HR-2026-09-04-003 remains Pending and not practical-review-ready.

Current continuation (2026-09-14, scoped authority planning facts):

- VaultScopeAuthorityFactEntry and VaultScopeAuthorityPlanningSnapshot implement the exact
  approved scope-authority-fact/scope-authority-facts families. Every global candidate remains
  present even without Client visibility. Canonical sorting, installation/scope/epoch/global
  digest binding, complete ordered roster matching and independently recomputed global roster
  digests reject mixed, incomplete or foreign facts. Company visibility is always unrestricted
  by Client; this is not an effective Vault permission or approval.
- DatabaseVaultScopeAuthorityPlanningReader re-reads the actual current authority state and
  delegates visibility exclusively to CurrentClientVisibilityAdapter. Missing Client is a
  distinct failure, not an empty or simply invisible roster.
- The adapter's new inspectManyLocked path selects only current safe identity/permission/pivot
  metadata on the caller connection. It uses fresh, fully preloaded Spatie User/Role/Permission
  objects and hasPermissionTo with a Permission object, avoiding cached name lookup and stale
  caller relations. Exact web guard and User model bytes apply to role and retained direct
  grants. Existing active and inactive clients have the same contract. No new per-client ACL,
  contract-lifecycle rule, Admin bypass or active-client session shortcut was introduced.
- VaultPlanningTransaction explicitly sets next-transaction REPEATABLE READ before native
  BEGIN and retains exact PDO/owned-transaction identity; a session-variable check alone would
  miss a pending weaker isolation override. Client planning requires this owner and consumes
  complete bounded locking ranges. Company scope needs no Client table or permission lookup.
  No existing transaction runner or ordinary inspect() contract was replaced.
- Focused SQLite canonical/scoped/current-authority plus existing Client visibility feature
  tests PASS 9 / 308 (28.59s). Owned metadata fixtures contain no secret-bearing user columns.
  Both native drivers PASS 2 / 280 (mysql 9.81s, mariadb 9.91s); sequence 61947 ENDED exit 0.
  Separate native PDO contenders prove an existing Client update and absent permission-pivot
  INSERT both block, including after a pending READ COMMITTED override. This is scoped lock
  coverage, not full authenticated planning/approval concurrency acceptance.
- Final complete Vault unit set plus authority/graph/action-plan source readers, source writer
  and both fully migrated pending writer variants PASS 840 / 28414, no skips, 134.13s.
  Sequence 96886 ENDED exit 0; /tmp/vault-scope-authority-planning-20260914.log.
  This run includes six source-writer cases beyond the previous broad checkpoint.
- All eight PHP files pass Pint and retain 0644 permissions; git diff --check passes.
  No guard SQL, operational schema, runtime binding, migration, provider material or credential
  changed. These are current canonical facts, not authenticated execution authority. The future
  coordinator must use the owned planning transaction and attest guards/provenance/current
  requester proof, policy and floor requirements before persisting/finalizing a plan.
  HR-2026-09-04-003 remains Pending / not practical-review-ready.

Current continuation (2026-09-14, finalized graph-plan evidence):

- DatabaseVaultFinalizedGraphPlanReader implements finalized graph-source read-back under the
  exact owned transaction. It verifies root/enforced phase, complete current source/proof guard
  catalogs, canonical graph base/header/full subjects, reciprocal finalized commit/audit trust,
  and the retained requester's matching proof/session/authentication identity plus finalized
  proof-creation audit provenance at plan-request time. Caller booleans cannot supply these facts.
- Only after those checks does it construct VaultActionPlanSnapshot. Missing, pending, malformed,
  wrong-family and guard-drift evidence is unavailable. Authority/quorum deliberately remain
  separate unimplemented dispatch, not silently accepted as graph. The reader is unbound.
- This is retained immutable evidence, not current permission, graph drift, approval, or mutation
  authorization. Retained expiry is represented by the exact bounded immediate/approval window;
  historical reading does not require the requester still logged in or the original proof active.
  Every consuming action still needs its own current checks. No proof secret/counter or user
  credential value is loaded; the reader and returned snapshot are redacted/nonserializable.
- Source inspection found a missing graph-operation/expansion compatibility check. A regression
  that marked access_group.create as expanding failed before the fix. Complete source read-back
  now applies the existing VaultGraphAuthorizationFacts matrix using the retained typed grant
  subject's content-operation identity; the fixed regression passes without changing the RFC.
- The actual migrated writer/finalizer test now reads pending plans as unavailable, then verifies
  finalized immediate group-create and approval-bound member-add snapshots, field/digest/proof
  identity, outer rollback, missing/wrong family and exact guard loss/restoration. The initial
  expanded fixture correctly failed because its after-reachability set was empty while expansion
  was asserted. It now supplies an actual typed synthetic reachability entry and canonical
  digests; no application validation was weakened and no content registry was enabled.
- Initial focused SQLite tests PASS 6 / 805 before that expanded fixture. Final all Vault units
  plus authority/graph/action-plan source readers/writer and both full migrated plan-writer
  variants PASS 840 / 28559, no skips, 142.04s; sequence 84185 ENDED exit 0.
  Log: /tmp/vault-finalized-graph-readback-20260914.log.
- Native verification sequence 68953 ENDED exit 0, all four sequential stages passed:
  mysql source regression 4 / 619 (27.82s), full writer/finalized-reader 1 / 198 (1838.89s);
  mariadb source regression 4 / 619 (25.63s), full writer/finalized-reader 1 / 198 (1823.08s).
  Logs: /tmp/vault-finalized-graph-source-mysql-20260914.log,
  /tmp/vault-finalized-graph-full-mysql-20260914.log,
  /tmp/vault-finalized-graph-source-mariadb-20260914.log,
  /tmp/vault-finalized-graph-full-mariadb-20260914.log.
  Both full-run owned schemas were independently confirmed absent after test cleanup.
  The existing isolated skip-networking server remains available, PID 456340 with a
  controlled 4GiB cap and MALLOC_ARENA_MAX=2. Operational MariaDB PID 692 is untouched.
  Both driver names use this private MariaDB, not Oracle MySQL. Passing controlled native
  tests is not default-allocator or operational resource acceptance.
- Four PHP files pass Pint and retain 0644 permissions; git diff --check passes. Guard SQL and
  operational schema did not change. No runtime binding, real migration, credential material,
  commit, push or Main change. HR-2026-09-04-003 remains Pending / not practical-review-ready.

## Post-review computation and retained gate read-back (2026-09-14)

- VaultPostReviewBlockerEntry and the sole canonical encoder implement the exact dedicated
  inner and outer blocker documents, with every open obligation, fixed set kind, installation,
  positive gate epoch, canonical DB-format time, finding count and due-at-snapshot bit.
  Duplicate identities (including different facts for one ID), non-list/oversized sets and
  invalid clocks/counts reject. Complete entry bytes are sorted and hashed incrementally.
  Both published empty/nonempty golden digests match exactly. The unchanged 100,000-entry
  protocol limit is now public so the readers and encoder share one bound.
- VaultPostReviewGateComputer reuses the existing gate decision rules and derives exact counts,
  digest and ordered newly-overdue identities. Due time is inclusive; open work already blocks
  before due. Confirmed history is excluded from blockers, but confirmed/open-finding
  inconsistency rejects. Future obligations and malformed finding lists reject.
- VaultPostReviewGateComputation compares the full unfenced retained gate (epoch, digest,
  all counts and update time). Newly-overdue evidence cannot be bypassed by manually matching
  cached counts/digest. Computation never writes a gate, grants authorization or performs a
  durable overdue observation. All inputs remain explicitly typed facts, not DB provenance.
- DatabaseVaultPostReviewGateReader requires the exact owned transaction, locks the unique
  installation/root and complete gate identity without installation filters, parses exact
  supported PDO scalar representations and preserves every pending pointer. It selects no
  credential fields and performs no writes. Its caller still must attest guards, read the
  complete finalized source/lifecycle set and use the authoritative clock under these locks.
- Focused pure computation/lifecycle PASS 12 / 98 (0.26s). Initial verification caught private
  constant visibility; the same protocol limit is now public, without changing its value.
  Actual gate DDL read-back PASS SQLite 1 / 33 (0.53s), mysql 1 / 35 (4.31s) and mariadb
  1 / 35 (3.71s). The native tests also prove a contender UPDATE times out under the held gate
  lock. External FK parents are explicitly synthetic; this is not complete obligation provenance.
- Broad all Vault units plus post-review gate/schema tests PASS 846 / 26998 (71.70s),
  no skips, sequence 17594 ENDED exit 0. Log: /tmp/vault-postreview-computation-20260914.log.
  Combined plan/source/finalizer regression PASS 859 / 28569 (143.90s), no skips; sequence
  82876 ENDED exit 0. Log: /tmp/vault-postreview-plan-regression-20260914.log.
- Nine affected PHP files pass Pint and diff checks and retain project-readable permissions.
  No new schema, migration, service binding, route, credential data, Main change or runtime
  activation. The current product and human-review states are unchanged.

## Review evidence source and reciprocal audit read-back (2026-09-14)

- DatabaseVaultPostReviewEvidenceReader reads finding, remediation and confirmation sources
  under the exact owned transaction, unique installation and global gate lock (before any
  particular evidence/obligation source lock). It reconstructs the existing
  typed evidence request, checks the retained obligation digest/lower time bound and requires
  remediation to reference an earlier finding in the same obligation with the same plan digest.
- The reader checks actual commit installation/source/event/outcome/sequence/active/terminal
  fields, exact bounded build window and source/audit/finalization chronology. Every audit
  reference must match the closed reviewed shape and every unrelated reference must be NULL.
  Human actor, reason/outcome and expected scope must match, with reciprocal evidence-owner
  trust state and terminal time. Building stays building; finalized evidence remains readable
  after its build deadline. Expired building and aborted evidence are unavailable here.
- Expected scope is explicitly supplied by the owning verified-origin reader, not guessed
  from a source with no scope column. This adapter is NOT that origin reader. It does not
  establish reviewer proof provenance/current permission, obligation creation/origin validity,
  complete lifecycle projection state, remediation effectiveness or terminal confirmation.
  It installs no source guard, performs no review write and grants no runtime authority.
- Actual review-evidence DDL plus explicitly synthetic mutable external FK parents, commit,
  audit and trust fixtures exercise all three evidence types in company and Client scopes.
  Every typed audit reference, owner/terminal corruption, source digest/time, prior-finding
  order, scope mismatch, outer rollback, pending exclusion and expired finalized history
  are checked. SELECT-only read-back includes SQLite's fixed read-only clock CTE.
  An initial read-only test assertion incorrectly required that CTE to start with SELECT;
  only that test assertion was corrected. Native synthetic user IDs match actual unsigned FKs.
- Final root/gate-first focused SQLite PASS 6 / 684 (2.30s), mysql PASS 6 / 684 (24.39s),
  mariadb PASS 6 / 684 (25.10s). Sequence 91047 ENDED exit 0; private schemas are owned/cleaned by
  the existing isolated helper. This is not a fully migrated source-writer/finalizer test.
- Earlier combined all Vault units, post-review reader/gate/schema and plan/source/finalizer
  regression PASS 865 / 29229 (145.04s), no skips; sequence 69094 ENDED exit 0.
  Log: /tmp/vault-postreview-source-regression-20260914.log. This preceded the final gate-first
  locking addition, which is exercised by the three-driver result above and final regression:
  PASS 852 / 27682 (73.29s), sequence 23811 ENDED exit 0. No skips or deferred failures.
  Final log: /tmp/vault-postreview-source-final-20260914.log.
- Both new PHP files pass Pint/diff checks and retain 0644 project-readable permissions.
  No service binding, runtime flag, schema/migration, actual credential or Main change.

## Reviewer proof creation provenance (2026-09-14)

- The review-evidence reader now verifies the retained reviewer's exact installation, actor,
  standard proof identity, opaque session digest and positive authentication/authority epochs.
  Its exact 60..900-second lifetime must contain the retained review time. The existing
  DatabaseVaultProofCreationTrustReader checks the actual finalized creation commit, typed audit
  and reciprocal trust at that review time. Missing, pending, swapped and unrelated creation
  references deny. No factor secret, recovery code, credential or current session is loaded.
- Expired historical proof remains readable when it was within its valid interval at review.
  This is creation provenance, not current permission or historical proof-revocation/terminal
  authorization; those remain part of the complete originating action/review workflow.
- The initial fixture used an incorrect hard-coded event spelling; it was corrected to the
  existing StepUpProofIssued enum value. Application validation was not weakened.
- Focused SQLite PASS 6 / 1074 (3.83s). Sequential mysql and mariadb both PASS 6 / 1074;
  sequence 61328 ENDED exit 0 (last native stage 32.23s). Both driver names use the same
  private MariaDB server, not Oracle MySQL. External proof/audit owners remain synthetic
  mutable corruption fixtures, not full source-writer/finalizer integration.
- Final units/post-review PASS 852 / 28072 (72.64s), no skips or deferred failures.
  Log: /tmp/vault-postreview-proof-source-20260914.log. Pint/diff checks pass.

## Obligation source and creation-audit read-back (2026-09-14)

- DatabaseVaultPostReviewObligationEvidenceReader locks the unique installation/root and
  global gate before the exact immutable obligation source. It validates the approval versus
  recovery reference matrix, graph/authority/quorum XOR, canonical identities, positive actor,
  raw plan digest and exact configured whole-hour due window. Authority/quorum reject Client scope.
- It independently verifies the actual creation commit, bounded build-window shape, actor,
  reason, scope, audit chronology and every typed reference with reciprocal commit-owned trust.
  The approved obligation audit references only its commit, obligation and consumption XOR
  recovery transition; unrelated plan/proof/content references reject.
- It returns only VaultEvidenceCommitSnapshot, never VaultPostReviewObligationSnapshot.
  A finalized obligation is not proof that consumption/operation/origin completed. The owner
  must verify those exact sources, guards, plan and lifecycle and derive expected scope.
  No raw bool or caller-selected customer is sufficient to create a trusted obligation.
- Pending/expired-building sources remain pending. Retained finalized source evidence survives
  the build deadline, including the approved effects-started resume case. This low-level read
  does not authorize resume, apply an operation or permit independent origin-child cleanup.
  Aborted/malformed/nonreciprocal evidence rejects; full inert-source classification is separate.
- Actual obligation/gate DDL with synthetic external FK parents and mutable audit/commit/trust
  corruption fixtures cover five company/Client and operation/origin cases, every typed audit
  reference, owner/terminal drift, missing identity, rollback, whole-hour policy and read-only
  lock order. These fixtures do not claim completed-origin source-writer/finalizer coverage.
- Focused SQLite PASS 5 / 530 (1.84s), mysql PASS 5 / 530 (23.76s), mariadb PASS
  5 / 530 (26.06s). Sequence 91117 ENDED exit 0, including final all Vault units and
  post-review readers/schema/guards PASS 857 / 28602 (75.54s), no skips/deferred failures.
  Log: /tmp/vault-postreview-obligation-source-20260914.log.
- All four affected source/test PHP files pass Pint; new files retain 0644/projectusers.
  No schema/guard/runtime binding change, operational migration, real credential, Main
  change, commit or push. HR-2026-09-04-003 remains Pending / not practical-review-ready.

## Origin stage, plan and obligation linkage (2026-09-14)

- VaultPostReviewOriginSnapshotValues parses the exact closed coordinator row without silently
  coercing unknown stages, missing/extra fields, invalid IDs, active-slot states, consumed
  authorization presence, terminal references or chronology. All seven stages and four allowed
  origin/operation families are covered. Authorization digests are opaque and diagnostic output
  is redacted. This is structural retained state, not verified consumption/operation provenance.
- DatabaseVaultPostReviewOriginReader locks root/gate then exact origin and base-plan metadata.
  It verifies installation/family, the exact original expiry and plan-before-origin chronology,
  and the graph subtype's actual company/Client identity. Non-graph origins require company
  scope. Claimed and all resumable intermediate stages require their exact global gate pointer;
  assembling and terminal origins cannot own it. An unrelated pending gate is preserved.
- The obligation evidence reader now uses this reader to bind all preallocated operation and
  consumption IDs, origin kind, requester identity and raw plan digest against actual retained
  rows. Finalized obligation evidence requires obligation_finalized, consumption_finalized or
  completed origin stage; pending evidence cannot stand in for that finalized child. Completion
  times must follow effects-started and precede completed origin time where present.
- Expired historical plans and exact resumable stages remain readable. Effects must have started
  within the original plan window; deadline passage afterward never creates new authority.
  The code does not authorize resume or verify the authorization snapshot's full pre-state
  contents, actual operation subjects, consumed approval/recovery source or their lifecycle.
  Complete plan/operation/consumption provenance and source guards are still owner obligations.
- A failing-before regression accepted a swapped preallocated operation ID in all five previous
  source-reader cases. The integrated correction now rejects that swap, different consumption,
  requester/digest/scope/window and impossible stage/commit combinations while keeping pending
  data pending. The fixture uses actual origin/gate/obligation DDL and explicitly synthetic
  external plans/consumption/operation parents; it is not a full composite-finalizer test.
- Focused SQLite PASS 13 / 992 (2.85s), including eight pure stage-matrix tests and five coupled
  origin/obligation/audit tests. Private mysql PASS 5 / 641 (37.67s), mariadb PASS 5 / 641
  (37.91s); both use the retained isolated MariaDB, not Oracle MySQL.
- Final units/post-review readers/schema/guards PASS 865 / 29064 (77.29s), no skips or
  deferred failures; sequence 25333 ENDED exit 0.
  Log: /tmp/vault-postreview-origin-binding-20260914.log.
- Five affected PHP files pass Pint/diff checks and retain 0644/projectusers permissions.
  No guard/schema migration, operational data write, runtime activation, credentials, Main,
  commit or push. HR-2026-09-04-003 remains Pending / not practical-review-ready.

## Approval-origin consumption, request and exception linkage (2026-09-14)

- DatabaseVaultPostReviewApprovalSourceReader is now called by obligation read-back for
  approval origins. It reads the actual typed consumption, request, selected sole-admin or
  TOTP-enrollment exception and approval lifecycle under the same root/gate/origin locks.
  It checks exact executor/request/plan digest, operation XOR, reciprocal origin and
  consumption identity, and consumed_at equal to the obligation's required_at.
- An ordinary independent decision cannot supply a post-review consumption. The closed
  standard-proof/sole-exception versus enrollment-proof/enrollment-exception matrix applies;
  enrollment is restricted to authority.totp_confirm. All proof/source IDs are typed.
  Requester, candidate-count-one/warning/epoch, retained request/exception roster facts,
  matching plan expiry and the bounded original issue/use interval are checked.
- Approved revision 2 with the exact pending consumption and selected exception is required
  until consumption_finalized; that stage and completed require consumed revision 3 and
  the exact consumption last-evidence identity, with no remaining reservation. Historical
  reading uses retained consumption time, not current login/factor state or current TTL.
- This is linked source/projection metadata, NOT finalized request/exception/consumption
  audit trust, proof-creation/terminal/generation provenance, real roster eligibility or
  completed operation/witness evidence. It returns no authorization and grants no resume.
  Recovery remains on its previously verified structural origin path; its different
  authorization-consumption source still needs the corresponding complete reader.
- Actual origin/gate/obligation plus approval-consumption and approval-lifecycle DDL now
  back the six-case fixture, including a separate TOTP-enrollment origin. External plans,
  exceptions, proofs and operation parents remain deliberately synthetic mutable fixtures.
  Swapping the actual consumption executor failed before the binding in all five approval
  cases (the recovery case passed), and the integrated reader now rejects it. Request,
  exception, digest, window, reservation and terminal-phase corruption checks also pass.
- Focused SQLite PASS 6 / 935 (4.95s). Sequential private mysql PASS 6 / 935 (66.32s)
  and mariadb PASS 6 / 935 (52.25s). Both use the retained isolated MariaDB server, not
  Oracle MySQL; these are source/projection read-back tests, not composite-finalizer tests.
- Final units/post-review readers/schema/guards PASS 866 / 29358 (77.46s), no skips or
  deferred failures; sequence 82997 ENDED exit 0.
  Log: /tmp/vault-postreview-approval-source-20260914.log.
- Three affected PHP files pass Pint/diff checks and retain 0644/projectusers permissions.
  No source guard, schema migration, operational credential, runtime flag, Main, commit
  or push change. HR-2026-09-04-003 remains Pending / not practical-review-ready.

## Approval request/exception creation audits and retained proof issuance (2026-09-14)

- The integrated approval-origin reader now checks the actual request and selected exception
  creation commits, bounded original build times, human audit identity, operation vocabulary,
  source-derived scope, every typed reference and exact finalized trust ownership. Requests
  intentionally use requested_at, not a nonexistent created_at source column. Their creation
  must precede exception issue, and exception creation must precede the retained consumption.
- It reads actual standard/enrollment proof metadata and finalized issuance for the request,
  exception and executor at each original use time. Session/auth epoch match the request;
  authority epoch and enrollment generation pointer match the exception. Enrollment is restricted
  to authority.totp_confirm with its exact 300-second issuance interval. Secret material and
  accepted TOTP counters are never selected. This is retained issuance, not terminal-history
  eligibility, current authentication, actual generation provenance or permission to execute.
- Exception expiry is the exact minimum of issue plus 300 seconds, request/plan expiry and the
  original proof expiry. A refreshed standard executor proof is independently verified and does
  not have to equal the original request/exception proof. Historical expired sources remain
  readable using their original timestamps; current access and consumed-witness verification
  are still separate requirements.
- Regression first failed on a swapped request-creation audit actor in all five approval cases;
  recovery remained passing. After the integrated correction, missing/swapped commit/audit/trust
  references, actor, vocabulary, scope, time and proof metadata reject. The positive refreshed
  executor-proof path and its wrong-actor denial are covered. Enrollment fixture vocabulary was
  corrected to the actual enum; its creation audit now receives the already-verified operation
  for the required vocabulary check. No validation rule was weakened.
- Focused SQLite PASS 6 / 1851 (15.17s). Sequential private mysql PASS 6 / 1851 (97.01s),
  mariadb PASS 6 / 1851 (96.45s); both point to the retained isolated MariaDB, not Oracle MySQL.
  These use actual obligation/origin/consumption/lifecycle DDL with deliberately mutable external
  source/evidence fixtures, not a complete guarded finalizer or operational resource acceptance.
- Final units/post-review readers/schema/guards PASS 866 / 30274 (87.31s), no skips or deferred
  failures; sequence 42496 ENDED exit 0. Log: /tmp/vault-postreview-creation-proof-20260914.log.
- Three affected PHP files pass Pint and repository diff checks. Four owned local transport
  patches were removed. No source guard/schema, operational migration, real credential, runtime
  activation, Main, commit or push changes. HR-2026-09-04-003 remains Pending / not review-ready.

## Request/base/subtype mirrors and closed exception operation policy (2026-09-14)

- The integrated approval-origin reader now compares every duplicated base-plan request field:
  actor, session, auth epoch, proof kind/IDs, version, raw plan digest and exact issue/expiry.
  Only version 1 and an exact whole-minute approval-bound lifetime (5 through 60 minutes) pass.
  Graph requests require the actual subtype's candidate/global/scoped authority digests,
  exact scope and expansion/approval flags. Authority requests require the actual before-roster
  and global-authority facts with company scope and NULL scoped-authority digest.
- The actual plan operation must match the origin family and the existing shared audit operation
  policy for both request and selected exception creation. No new policy was invented. In
  particular, peer unlock requires a different eligible decision-ready candidate (approved slice
  section around line 1770); it cannot use a sole-admin post-review origin. Cooling recovery keeps
  its separate authorization path.
- The formerly positive structural approval-quorum fixture is now an explicit rejection test.
  Before integration, four plan-session mismatches and the forbidden peer-unlock exception were
  accepted (5 failing cases, recovery passing, 515 assertions). All now reject. The assertion
  total is lower because that invalid quorum scenario terminates at the required denial instead
  of exercising successful approval-consumption phases. No test or guard was disabled.
- Tests also cover independently corrupted base/subtype/request fields, expansion/approval flags,
  unknown/non-approval operations, consistently unsupported version and consistently non-whole-
  minute expiry across request/plan/origin. The historical-expiry and renewed executor-proof
  paths still pass. The original plan fixture lifetime was corrected from 901 to exactly 900
  seconds; the production lifetime policy was not weakened.
- Focused SQLite PASS 6 / 1581 (13.48s); sequential private mysql PASS 6 / 1581 (89.27s),
  mariadb PASS 6 / 1581 (86.65s), both on the retained isolated MariaDB, not Oracle MySQL.
- Final units/post-review readers/schema/guards PASS 866 / 30004 (85.11s), no skips/deferred
  failures; sequence 2844 ENDED exit 0. Log: /tmp/vault-postreview-plan-mirror-20260914.log.
- These remain retained metadata/evidence checks, not complete finalized plan or actual operation
  authorization. Full plan-extension uniqueness/subject/canonical creation provenance, current
  replay, consumed witnesses, proof terminal history and the lifecycle adapter remain required.
  Two changed PHP files pass Pint; no schema/guard, operational migration, credential, runtime,
  Main, commit or push change. HR-2026-09-04-003 remains Pending / not practical-review-ready.

## Phase-matched consumption audit and actual mutation identity (2026-09-14)

- The integrated approval-origin reader now checks the actual consumption commit's source,
  installation, sequence, closed event/outcome, active slot, abort absence and original build
  interval against the coordinator phase. Consumption-finalized/completed require the finalized
  commit; earlier stages require building. Finalization follows the exact obligation commit and
  effects-started time and cannot postdate a completed coordinator.
- Building consumption may legitimately have no audit yet; it remains inert. Trust without that
  audit rejects. Once an audit exists it must have the exact human executor, allowed consumed
  outcome/reason, scope, original use/create timestamps, every typed reference, and reciprocal
  pending/finalized evidence-owner trust. Finalized consumption never accepts an absent audit.
  Historical expired building evidence stays inert; TTL passage creates neither resume authority
  nor permission to clean up an origin child.
- The audit's graph/authority mutation UUID is resolved from the actual unique execution/witness
  relation, never copied from the source's preallocated execution/witness UUID. Its installation,
  plan, actor, proof and consumed approval identity must match. Missing, ambiguous and mismatched
  mutation metadata rejects. This is identity linkage, NOT applied-state/subject-set/operation-audit
  or consumed-witness verification, and never authorizes an action or coordinator resume.
- Regression first failed on orphan consumption trust in four valid approval cases; denied
  peer-unlock and separate recovery remained passing (4 failed / 2 passed, 175 assertions).
  After integration, owner/state/timestamp/scope/reference/mutation corruption, final audit
  absence and consumption-before-obligation finalization all reject. Pending absent-audit,
  pending present-audit, finalized audit, exact phase restoration and historical-expiry cases pass.
- Focused SQLite PASS 6 / 1943 (18.82s); sequential private mysql PASS 6 / 1943 (112.46s),
  mariadb PASS 6 / 1943 (114.45s), both on the retained isolated MariaDB, not Oracle MySQL.
  Actual origin/consumption/lifecycle/obligation DDL is combined with deliberately mutable
  synthetic external audit/mutation fixtures; this is not full guarded operation execution.
- Final units/post-review readers/schema/guards PASS 866 / 30366 (92.07s), no skips/deferred
  failures; sequence 37249 ENDED exit 0. Log: /tmp/vault-postreview-consumption-audit-20260914.log.
- Two changed PHP files pass Pint/diff checks. No source guard/schema change, operational
  migration, real credential, runtime activation, Main, commit or push. HR-2026-09-04-003 remains
  Pending / not practical-review-ready. Svein's continuation mandate remains active.

## Exact single approval-plan subtype (2026-09-14)

- The approval-origin metadata reader now requires exactly one graph/authority/quorum header
  matching its actual base family and installation. All three families are read by base ID
  without installation filtering, so a foreign-installation extra row cannot evade rejection.
  The canonical action-plan source reader already enforces this invariant; this closes the
  separate approval-origin metadata path, not a new policy or a complete finalized plan reader.
- Corrected the synthetic fixture to insert only its selected subtype, then added same- and
  foreign-installation extra-header probes for every other family in each valid approval case.
  Before the fix: 4 failed / 2 passed, 183 assertions. After the fix: SQLite 6 / 1971 (18.71s),
  private mysql 6 / 1971 (116.29s), private mariadb 6 / 1971 (116.30s), sequence 64099 exit 0.
  Both native drivers use the isolated MariaDB, not Oracle MySQL or operational acceptance.
- Pint passes both changed PHP files. No schema/guard/public contract, migration, runtime or
  credential change. The preceding broad 866 / 30366 result remains a prior baseline, not a new
  run for this private helper change. Full canonical subjects/creation provenance and actual
  operation effects/witnesses/audit remain outstanding under the same approved Slice 04.

## Completed mutation phase and primary operation audit (2026-09-14)

- Approval-origin read-back now checks the actual graph/authority mutation phase when the
  consumption audit exists. Before composite completion the mutation remains pending with no
  applied timestamp or bound primary audit. A completed origin requires applied state, a canonical
  primary audit identity and application no earlier than finalized consumption and no later than
  origin completion. Missing building consumption audit remains explicitly inert, not permission.
- The completed primary audit is read from that exact mutation pointer and checked against the
  actual plan operation, closed shared event/outcome/reason and reference-shape registry, executor,
  source reason, company/customer scope and original effect/create/apply timestamps. Every typed
  reference matches its actual source identity; missing, extra and cross-family references reject.
  Reciprocal trust must be finalized at the mutation's application time, owned by exactly that
  graph/authority mutation, with no evidence-commit/foreign owner or abort metadata.
- Authority TOTP confirmation uses its separate event/reference shape and the actual consumed
  proof's terminal pointer; it cannot borrow the generic authority event or approval-reference
  shape. The full terminal transition/commit and consumed-witness provenance are still outstanding.
  Graph target/execution-subject and authority witness/UserSecurity IDs are bound here, but the
  complete physical subject sets, canonical before/after effects and execution evidence are NOT
  yet proven by this private reader. No new authorization or resume grant is returned.
- The corrected regression failed before implementation: 4 failed / 2 passed, 183 assertions,
  accepting applied mutation state before the origin completed. (An earlier fixture placement
  error was corrected before that regression observation.) After implementation, pending/applied
  phase, missing primary audit, source/audit/trust corruption and every typed-reference denial
  pass alongside existing historical-expiry, enrollment and separate recovery fixtures.
- SQLite PASS 6 / 2215 (22.75s); private mysql PASS 6 / 2215 (133.06s); private mariadb
  PASS 6 / 2215 (135.16s). Both native drivers use the isolated MariaDB, not Oracle MySQL;
  mutable external fixtures do not constitute guarded operation execution or operational acceptance.
- Final units/post-review readers/schema/guards PASS 866 / 30638 (103.62s), no skips or deferred
  failures. Sequence 45501 ended exit 0; no test process is left running.
- Two changed PHP files pass Pint. No public contract/schema/guard change, operational migration,
  runtime activation, real credentials, Main, commit or push. Operational health remains
  provider local_sealed / status vault_disabled (expected exit 1). HR-2026-09-04-003 remains
  Pending and not practical-review-ready; automatic continuation is ACTIVE.

## Physical authority witness and paired execution completion (2026-09-14)

- Completed approval-origin authority mutations now lock the actual witness, the complete witness
  subject set, paired UserSecurity execution and complete execution subject set. Parent reads are
  unique and physical sets bounded to 10000; they are never filtered by installation or state.
  Canonical row IDs and terminal times are checked. Finalized headers and ordinary subjects stay
  inside the effects/application interval; the exact authority_state/claim subject may start at
  origin creation, before effects. This reservation exception cannot be borrowed by a target or
  finalize subject. Subjects lock in sequence order (ID tie-break), matching the approved ordering.
- The read-back reuses VaultAuthorityMutationWitnessCompletion's existing closed finalizer
  predicate with fixed compiler alias replacement and bound source IDs. It requires exact plan,
  installation, actor and proof bindings, reciprocal execution/witness identity, consumed/finalized
  parent states, no active/abort/revoke metadata, complete physical counts, contiguous unique
  sequence sets and terminal timestamps. Cached expected/consumed counts alone cannot pass.
  The predicate/guards themselves were not changed.
- These are completed-origin retained ledgers only. Pending composite stages stay inert and do
  not acquire a completion requirement prematurely. Canonical typed OLD/NEW subject values,
  consumed pre-state authorization snapshot, corresponding graph execution sets, full proof
  terminal/generation provenance and distinct recovery source still need the owning adapter.
  The helper never creates a trust flag, access permission or resume authorization.
- Regression before implementation: 2 failed / 4 passed, 2095 assertions; an allegedly completed
  authority mutation accepted a still-pending witness. Missing/extra/duplicate/foreign/pending
  physical subjects, count mismatches, wrong owner/plan/proof/terminal state and times before
  their allowed origin/effect boundary or after application now reject. Both standard and TOTP-enrollment authority branches
  are covered alongside the unchanged graph and separate recovery cases.
- Initial native sequence 50157 stopped on a fixture expectation (mysql 2 failed / 4 passed,
  2169 assertions, 142.51s): the real consumption FK correctly forbids deleting its witness.
  The one missing-witness probe now accepts only native SQLSTATE 23000 / driver code 1451;
  all other SQL failures rethrow and the other missing-ledger probes still require reader denial.
  No foreign key or runtime rule was disabled. Its mariadb/broad stages did not start.
- FK-corrected checkpoint sequence 58923 passed SQLite 6 / 2303 (24.63s), private mysql
  6 / 2303 (145.41s), mariadb 6 / 2303 (151.24s), broad 866 / 30726 (98.04s).
  Contract review then added the legitimate early fence-claim fixture: the overly broad lower
  time bound failed 2 / 4 (1973 assertions, 22.25s). The corrected typed claim distinction and
  sequence-order locks are included in the final results below, not implied by that older run.
- Final SQLite PASS 6 / 2307 (25.64s). Private mysql PASS 6 / 2307 (157.79s), mariadb
  PASS 6 / 2307 (145.86s), both on isolated MariaDB, not Oracle MySQL or operational acceptance.
- Final units/post-review readers/schema/guards PASS 866 / 30730 (100.76s), no skips/deferred
  failures; sequence 20394 ended exit 0. Log: /tmp/vault-postreview-witness-claim-20260914.log.
- Two changed PHP files pass Pint and no-index whitespace checks. Runtime remains disabled.
  No schema/guard/public contract change, operational migration, credentials, Main, commit or push.
  HR-2026-09-04-003 remains Pending / not practical-review-ready; continuation stays ACTIVE.

## Completed graph physical ledgers and canonical audit targets (2026-09-14)

Continued Svein's full-product completion mandate on authoritative Dev, under the existing
approved Slice 04. No runtime enablement, operational migration, real credentials, Main,
commit or push. HR-2026-09-04-003 is still Pending and not practical-review-ready.

### Implemented

- Added DatabaseVaultCompletedGraphExecutionReader and integrated it into the completed
  approval-origin source read-back. It locks the actual execution and complete physical
  scope/target sets without installation/state filters that could hide invalid extra rows.
- Checks exact installation, plan, actor/proof, scope/key, origin/obligation, consumed state,
  null active/revocation fields, creation/effects/finalization times and physical target counts.
- Rebuilds every graph plan subject through the existing canonical hydrator, operation matrix
  and subject-set digest; compares target IDs, plan-subject IDs, original plan sequence,
  closed kinds and exact OLD/NEW bytes. Reference-only items never become executable subjects
  or inflate target counts, and executable sequences are not renumbered.
- Requires exactly one consumed claim and one consumed finalize scope subject. Uses the
  existing typed scope transition object to verify the epoch/fence changes and all five
  before/after digest fields. The claim preserves its digests; the two subjects share their
  exact before-state; finalize increments the epoch, clears the fence and matches execution
  finalization. Claims may precede effects; target consumption may not.
- Retained graph fence expiry is its own reviewed 1..60 second interval clipped by plan
  expiry, not always equal to the much longer plan expiry. Effects must begin before it
  expires. Exact completed origin work may finish after that short expiry, as required by
  the resumable origin protocol; no new authority or current-proof freshness is inferred.
- Primary mutation read-back now checks plan-subject identity, target kind, mutation kind
  and the schema's exact single-target FK shape. Membership/collection-item audit parent
  references are derived from the canonical relationship subject. They are not fabricated
  as additional mutation target FKs, which the actual schema correctly forbids.
- No schema, guard definition, public route, permission, migration or runtime binding changed.
  The adapter returns only exact safe graph audit references, not a write/authorization grant.

### Verification and regressions

- Initial missing-physical-ledger regression failed before implementation: 2 failed / 4 passed,
  2185 assertions (23.75s), because a completed graph execution's foreign installation was
  accepted. Initial implementation passed SQLite 6 / 2421 (25.80s), private mysql 6 / 2421
  (154.14s) and private mariadb 6 / 2421 (153.74s); sequence 56664 ended exit 0.
- The valid short-fence/after-expiry completion fixture failed before the expiry correction:
  2 failed / 4 passed, 2063 assertions (21.93s). Corrected SQLite passed 6 / 2421 (26.90s).
- Extra expiry/digest probes initially ran in the inert pre-completion phase, producing
  2 failed / 4 passed, 1305 assertions (13.87s); sequence 53543 ended there and native/broad
  stages did not start. The probes were moved to completed origins; no pending-state or
  runtime authorization rule was weakened.
- Corrected pre-primary-binding sequence 8544 ended exit 0: SQLite 6 / 2445 (27.11s),
  mysql 6 / 2445 (160.04s), mariadb 6 / 2445 (156.64s), broad 866 / 30868 (101.37s).
- A valid schema-shaped single-target mutation with the required parent+membership audit
  failed before the primary-reference correction: 2 failed / 4 passed, 2063 assertions
  (22.64s). The final fixture retains the real single-target shape.
- Final SQLite PASS 6 / 2455 (27.81s); private mysql PASS 6 / 2455 (158.11s),
  private mariadb PASS 6 / 2455 (157.55s). Both driver names use the isolated MariaDB instance.
- Final units/post-review regression PASS 866 / 30878 (100.66s), no skips/deferred failures. Sequence 29112 ended exit 0.
  Log: /tmp/vault-graph-primary-completion-20260914.log.
- Fixtures use actual obligation/origin/consumption/lifecycle DDL and mutable synthetic
  external owners for corruption probes. This is not a full guarded graph operation, an
  Oracle MySQL test, operational cutover, or independent production security acceptance.
- Additional graph execution/schema tests PASS 7 / 306 (2.65s), including actual single-target
  mutation shape and claim/finalize matrices.
- Final changed PHP files pass Pint and whitespace checks and are readable as 0644 by the
  project group. No operational deploy/build/queue/scheduler command is required by this change.

### Remaining boundary

The graph canonical statement/physical completion checks do not establish the complete
historical graph, consumed pre-state authorization snapshot, plan creation/terminal proof
history or permission to resume. Complete those owning contracts, canonical authority witness
OLD/NEW values, recovery-authorization source and real lifecycle/overdue writer. Then finish
guarded planning/approval/quorum integration and all product rows, including Documentations ->
Vault navigation and functional technician/customer workspaces, before practical review.

## Completed authority canonical statements and witness mirrors (2026-09-14)

This continuation implements retained subject read-back inside the existing approval-consumption
source reader. It does not activate Vault or establish the full consumed authorization snapshot.

### Implemented

- DatabaseVaultCompletedAuthoritySubjectReader locks the original plan/requester-proof epoch,
  authority subject digest, complete UserSecurity statement set and complete witness subject set.
- The existing authority hydrator and operation matrix authenticate canonical subject bytes and
  targets. The original requester proof supplies the subject-set epoch; a later executor proof or
  current installation epoch cannot silently replace it.
- VaultAuthorityUserSecurityBridge now exposes its pure exact subject-set comparison for retained
  read-back as well as planning, without constructing a fake prepared authority snapshot.
- The bridge rejects extra same-kind statements, requires the confirmed generation to be the
  planned pending generation, and binds every generation row's user/auth epochs. Vault epochs
  remain NULL exactly when that generation transition does not change confirmed readiness.
- VaultAuthorityWitnessSubjectFactory shares its exact plan-subject mapping. Each actual mirror
  must match one concrete UserSecurity statement and canonical planned subject, target/mutation
  kind and every nullable before/after field. TOTP generation rows are intentionally not mirrored.
- Exactly two state fences are hydrated through the existing canonical authority-state value.
  Claim and finalize must join byte-for-byte, advance the planned epoch, preserve exact witness
  ownership and match planned before/after roster/global digests. An explicit emergency semantic
  state subject, when present, must match both outer states. No unrelated target fields are allowed
  on a fence; no state fields are allowed on a mirrored user/permission write.
- Retained subject creation cannot follow consumption. The existing physical completion verifier
  continues to own complete counts/states/reciprocal execution identities and consumption timing.
- New reader serialization is disabled and diagnostic connection details are redacted.
- No route, UI, runtime binding, database migration or secret-value access is introduced.

### Verification

- Extra same-kind write regression before the bridge change: 1 failed / 7 passed, 41 assertions.
  Corrected baseline: 8 / 47. Wrong TOTP confirmation generation regression then reproduced:
  1 failed / 7 passed, 54 assertions.
- Initial broad generation-epoch check incorrectly required Vault epochs on replacement creation;
  existing valid reset coverage rejected it. It was corrected to the approved readiness-change
  NULL rule, not by weakening the fixture. Final bridge PASS 8 / 76 (0.13s).
- Bridge plus existing all-family authority hydration PASS 20 / 500 (0.35s).
- Integration fixtures now use actual typed authority subjects, TOTP generation+confirmation
  statements and canonical claim/finalize bytes. A pre-existing corruption probe used count 2,
  which became valid for the corrected two-row TOTP fixture; it now uses actual count + 1.
  That intermediate run failed 1 / passed 5, 2365 assertions (27.38s); corrected run passed
  6 / 2544 (30.61s).
- Final sequence 94094 ended exit 0: SQLite 6 / 2544 (30.52s), private mysql 6 / 2544 (186.89s),
  private mariadb 6 / 2544 (172.19s), broad Vault units and post-review regression
  866 / 31000 (105.82s), no skips or deferred failures.
- Additional actual witness/mutation schema coverage PASS 21 / 8448 (20.80s), session 1916
  ended exit 0. The foundation health command returns vault_disabled (expected non-zero exit);
  operational runtime stays off. No deploy/queue/build command is needed for this reader change.
- Both native driver names use the same isolated MariaDB server. The integration fixture combines
  actual post-review/origin/consumption/lifecycle DDL with mutable synthetic external owners for
  corruption probes. This is not Oracle MySQL, a fully guarded operation writer, operational
  cutover or independent production security acceptance.

### Remaining boundary

Physical/canonical read-back is not the complete historical authority/graph snapshot, proof
terminal history or permission to resume. Next finish the consumed pre-state authorization and
full plan/proof creation/terminal provenance, the separate recovery authorization source and the
actual full-set lifecycle/overdue database adapter. The existing action-plan source/evidence readers
can be reused for canonical source and creation evidence; do not fabricate trusted snapshots.
Then finish guarded planning/approval/quorum integration and all remaining approved product rows.
Documentations -> Vault and real technician/customer workflows still remain to implement.
HR-2026-09-04-003 stays Pending and is not an invitation to practical human review.

## Canonical original plan creation bound to approval origins (2026-09-14)

This continuation connects the existing full action-plan source/evidence readers to the actual
approval-origin reader. It does not create another trust flag or authorize a new operation.

### Implemented

- DatabaseVaultPostReviewApprovalSourceReader now loads the exact plan evidence owner after
  verifying the original requester proof, then invokes DatabaseVaultActionPlanEvidenceReader.
- The existing source reader reconstructs every canonical graph/authority header and subject,
  recomputes the complete plan digest and rejects unrelated subtype/subject families.
- Actual plan creation commit, typed audit and reciprocal audit trust must all be finalized and
  exact. Matching duplicated plan digests alone cannot establish source provenance.
- Plan finalization must precede or equal the approval request's physical creation commit time.
  The copied requested_at field is the frozen logical plan time, not a substitute for physical
  request creation. An internally coherent but still-building plan cannot own a valid request.
- The original requester proof supplies the authority source epoch; a refreshed executor proof
  remains separate. Existing exception/request/proof/consumption/operation read-back stays intact.
- Finalized historical plans can still be read after their original expiry. The retained fixture
  now rebuilds the canonical historical plan digest consistently across all four references and
  moves its creation evidence with the historical plan, rather than leaving false digest copies.
- No schema, routes, container binding, runtime activation, secret access or operational migration
  is added. Complete current authorization and proof terminal history remain separate work.

### Verification

- Canonical fixture preparation preserved all existing checks: SQLite PASS 6 / 2544 (29.55s).
- The new regression reproduced the missing binding before implementation in every valid
  approval family: 4 failed / 2 passed, 195 assertions (2.16s), with four equal but fabricated
  plan digest copies accepted by the old duplicated-field check.
- After implementation the expanded consumer passed 6 / 2604 (36.66s).
- Further corruption probes cover plan evidence identity, gate/header digest changes,
  commit lifecycle/source/audit references, audit actor/scope/vocabulary, trust ownership,
  missing creation records, coherent pending creation, plan-after-request chronology and
  unexpected quorum subject families. Existing rollback, refreshed-proof and retained-expiry
  tests remain enabled.
- Final sequential verification 16668 ended exit 0:
  - SQLite consumer plus action-plan source/evidence PASS 10 / 3371 (38.38s).
  - Private mysql PASS 10 / 3371 (249.24s).
  - Private mariadb PASS 10 / 3371 (251.14s).
  - Broad Vault units, action-plan source and post-review regression PASS 870 / 31827 (112.55s).
  - No skipped checks or deferred failures in this sequence.
- Both native driver names run against the isolated MariaDB instance, not Oracle MySQL.
  Source-reader tests use actual graph/authority DDL; approval-origin corruption fixtures use
  actual post-review/consumption/origin DDL with deliberately mutable synthetic external owners.
  This is not a fully guarded operational writer or independent production security acceptance.

### Exact next boundary

Do not repeat canonical graph/authority source creation or completed statement/witness read-back.
Next reconstruct the consumed pre-state authorization snapshot from actual locked sources using
the typed encoder/binder below, and complete historical proof terminal/generation provenance, then the distinct recovery-authorization source and full-set
post-review lifecycle/overdue adapter. The normative authorization snapshot has 22 fields in
the approved Slice 04 canonical-family section and a golden vector; do not replace it with
caller-supplied success flags. The current origin stores its digest but complete reconstruction
and source binding are still missing. Then complete guarded planning/approval/quorum assembly
and the remaining approved product rows, including the functional Documentations -> Vault
workspace, before practical review. HR-2026-09-04-003 remains Pending and Vault stays dormant.


## Authorization snapshot encoding and retained executor context (2026-09-14)

### Implemented

- VaultPostReviewAuthorizationSnapshot models the RFC's exact 22-field canonical family.
  Graph requires every scoped field and a standard proof; authority/quorum require all
  scoped fields to be NULL. Identity, epoch, digest and canonical UTC shapes are checked.
- VaultCanonicalBinaryV1 hashes the full ordered family with the existing opaque session
  mechanism. The normative golden vector matches exactly:
  dd52e85c90a819306cd0cf87e9845a2518b7d265ac0b0faf662136783e0540e9.
  Session digest bytes are not exposed through serialization or debug output.
- VaultPostReviewOriginSnapshotValues can compare a typed snapshot against its own retained
  digest, installation, origin/plan/operation identities, effects-start instant and original
  expiry. Assembling, claimed and aborted origins cannot pass this comparison. Historical
  elapsed TTL is not confused with a new current authorization.
- This is typed encoding and pure binding only. The DB owner still must reconstruct all facts
  from locked sources, prove the consumed pre-state witness and perform exact stage CAS.
  No runtime reader is newly allowed to authorize or resume from caller-supplied snapshots.
- DatabaseVaultPostReviewApprovalSourceReader now binds a renewed executor proof to the
  original reviewed session, requester authentication epoch and exception authority epoch.
  Proof identity may change; reviewed security context may not. Comparisons use retained
  pre-state facts, not the current user epoch after a completed self-affecting action.
- No schema, runtime binding, operational migration, credential access or UI activation.

### Verification

- New snapshot units PASS 5 / 110 (0.13s); pre-renewal-fix broad baseline PASS 875 / 31937 (112.90s).
- A new regression reproduced the missing renewal binding before the fix: graph-company
  source accepted another session (1 failed / 12 assertions, 0.81s).
- Corrected SQLite consumer PASS 6 / 2761 (38.44s), including all three renewal-context
  drifts and the existing valid refreshed-proof path.
- Final sequential verification session 42967 ended exit 0:
  - Private mysql PASS 6 / 2761 (226.39s).
  - Private mariadb PASS 6 / 2761 (215.57s).
  - Broad Vault units/source/post-review PASS 875 / 31946 (113.16s), no skips or deferred failures.
  Both native driver names use the same isolated MariaDB server, not Oracle MySQL.
  Corruption tests use actual post-review/origin DDL and mutable synthetic external owners,
  not a fully guarded operational writer or independent production security acceptance.
- Pint passed all six PHP files; explicit permissions are 0644 sveintore:projectusers.
  Health reports vault_disabled, as required. No deploy, queue or build action is needed.

### Remaining boundary

Do not repeat the golden vector, pure origin binding or renewed-executor-context fix.
The snapshot is not yet reconstructed/verified from actual consumed graph/authority/quorum
pre-state sources. Field 20 must resolve the real operation-kind-specific consumed fence;
never invent a witness identity or reuse an unrelated field merely to satisfy the hash.
Complete historical proof terminal/generation provenance, separate recovery authorization
and full-set lifecycle/overdue writers, then guarded planning/approval/quorum assembly and
all remaining product rows. Documentations -> Vault still needs its functional workspace.
HR-2026-09-04-003 remains Pending and not practical-review-ready. Automatic continuation
remains active; no new ordinary go-ahead is needed.


## Retained standard-proof TOTP generation metadata (2026-09-14)

### Implemented

- DatabaseVaultRetainedStandardProofGenerationReader reads the exact standard proof's
  retained generation reference, actor, installation, verification instant and accepted
  counter under the already-owned transaction, then locks that exact generation by ID.
  Owner/state filters cannot hide a mismatched generation row.
- Missing/wrong actor or generation, malformed counter/UUID/time, unknown source/state,
  unconfirmed factors, self-replacement and contradictory terminal metadata deny.
  No password, factor secret, recovery-code or current user credential is selected.
- Runtime generation creation must not follow confirmation; both must exist by proof
  verification. migration_backfill preserves an original confirmation that predates the
  ledger row, while still requiring that row to exist before the Row04 proof was verified.
- Confirmed generations have no terminal fields. Later disabled/superseded generations
  remain readable, with exact reason/state and non-future terminal chronology.
  Equal clock instants are metadata, not statement ordering; SQLite clock padding does
  not manufacture precision. The owning execution/proof history must establish ordering.
- Both post-review reviewer evidence and standard approval request/exception/executor
  read-back invoke the same adapter. Existing creation commit/audit/trust checks remain.
  Enrollment proof/generation handling is not silently treated as standard confirmation.
- The adapter returns no authorization, does not change data and cannot serialize its
  database connection. This is retained metadata validation, not complete UserSecurity
  generation statement/audit provenance, proof revocation history or consumed-origin
  authorization. Those owning checks still remain.

### Verification

- Before implementation, a new regression reproduced acceptance of a wrong generation
  reference: 1 failed / 38 assertions (0.60s).
- Initial SQLite reviewer and approval consumer PASS 12 / 3988 (45.41s).
- Expanded reviewer corruption/leakage plus transaction-owner unit PASS 7 / 1591 (4.88s).
- Initial full sequential matrix 92757 ended exit 0: mysql PASS 12 / 4374 (278.68s),
  mariadb PASS 12 / 4374 (268.87s), broad PASS 876 / 32490 (119.27s).
- A clock-boundary refinement then retained equal verification/terminal instants as metadata;
  strictly earlier terminal instants still deny. Wall-clock equality is not source ordering.
- Final sequence 50210 ended exit 0 on the refined code: SQLite reviewer/owner unit
  PASS 7 / 1597 (4.56s), native mysql reviewer PASS 6 / 1592 (40.19s), native mariadb
  reviewer PASS 6 / 1592 (44.58s), broad Vault units/source/post-review PASS
  876 / 32496 (117.96s). No skips or deferred failures in either sequence.
- Both native driver names use the same isolated MariaDB server, not Oracle MySQL.
  These fixtures use actual post-review/origin DDL with mutable synthetic external owners;
  this is not a fully guarded operation writer or independent production security review.
- Pint passed all six PHP files; permissions are 0644 sveintore:projectusers.
  Whitespace checks passed. Health reports vault_disabled (expected non-zero command exit).
- No operational migration, runtime activation, routes, container binding, queue/build step,
  real credential migration, commit, push or Main/production change.

### Exact remaining work

Do not repeat generation identity/metadata or canonical snapshot encoding. Complete the
retained proof terminal-transition/commit/audit and generation mutation-source provenance,
including original consumed self-mutation ordering rather than today's user/factor state.
Bind the actual consumed pre-state authorization snapshot/fence and finish the separate
recovery source, full-set post-review lifecycle/overdue adapter, guarded planning/approval/
quorum assembly and remaining product rows. HR-2026-09-04-003 remains Pending and not ready
for practical review; Documentations -> Vault still needs its functional workspace.
Automatic continuation remains active without new ordinary go-ahead.

## Revocation executor and proof creation provenance (2026-09-14, verification in progress)

- Reproduced a real terminal-schema mismatch: actorless UserSecurity invalidation wrote
  NULL revoked_by_actor_id, although both production proof terminal CHECK constraints
  require an actual revoker. The first regression failed the exact standard-proof CHECK
  (1 failed / 19 assertions), rather than accepting a weaker synthetic schema.
- Finalizer writes, nested proof update validation and parent read-back now bind the
  human initiating actor or the canonical system actor. The fresh/resumed writer also
  rereads and verifies the retained executor. Human-only expired-on-retry is unchanged.
- Four focused tests use the actual terminal CHECK extracted verbatim from production
  DDL for standard and both enrollment purposes. Synthetic revoker columns now use the
  real numeric actor type. Fresh/retry/outer rollback and parent gate/trust order remain.
- Initial complete proof contracts PASS 60 / 3972 (52.89s). Expanded UserSecurity SQLite
  PASS 23 / 996 (21.35s).
- Native combined run 4195 failed: mysql 17 failed / 6 passed / 196 assertions (213.42s);
  the private server exhausted its unchanged 4 GiB address-space limit and crashed.
  The separate retry 9769 failed OOM at the fourth case (53 assertions, 56.08s).
  Neither is a passing/waived run. Only the isolated test database was affected.
- Each completed focused native fixture now disconnects and drops its exact owned
  synthetic schema after assertions, instead of retaining six large trigger graphs
  until teardown. No predicate, TTL, guard or memory limit was weakened.
  Corrected mysql terminal contract PASS 1 / 102 (71.04s), session 30158 completed.
  Writer/resume/parent matrix 38824 ended 1 failed / 2 passed (342 assertions, 209.88s):
  retained resume still hit the memory limit; fresh writer and parent ordering passed.
- A separate new regression proved that the shared proof-creation reader accepted
  customer-scoped issuance audit (1 failed / 1 assertion). It now verifies installation-
  wide company/0 scope, no customer, positive evidence sequence, original build window
  and exact audit chronology. It rereads the actual proof identity, owner/session and
  verification instant so source-creation and verification timestamps are not conflated.
- Initial new reader + existing consumers PASS 26 / 4807 (46.41s); expanded post-review
  source consumers PASS 12 / 4460 (46.70s). The broad run found two genuine caller
  boundary mismatches where created_at is later than verified_at; the exact proof
  read-back resolves that without weakening the audit lower bound. Focused source/
  creation tests then PASS 7 / 282 (4.74s). Final broad run 81318 PASS 876 / 32720 (123.55s).
- The native base proof/audit representation now caches exactly eight existing guards
  through the same compiler. All four newer capabilities remain false and are enforced by
  the read-only finalization catalog. Native probes reject missing functions and a mixture
  of raw/compiled selected triggers before restoring and attesting the exact representation.
  Compiler plus proof contracts PASS 75 / 4097 (54.44s). Initial probe 63655 rejected drift
  correctly but failed its diagnostic expectation (1 failed / 14 assertions, 44.18s);
  diagnostics now prefer actual complete-candidate drift. Corrected cached mysql resume run 98978 PASS 1 / 162 (387.02s), without a
  private server reset. Missing/mixed guards and unsupported capabilities denied.
  Drift probes now run once per same-typed six-case matrix; all cases retain live full
  attestation. Sequence 91142 completed: mysql fresh/resume 2 / 426 (461.85s), then
  mariadb direct terminal/fresh/resume/parent 4 / 606 (626.50s). No reset or independent
  Oracle MySQL server was used. Broad after the extension PASS 876 / 32720 (120.32s).
  See docs/plans/2026-09-13-vault-mariadb-trigger-memory-rework.md and the linked ADR.
- Current private server is PID 654206, socket /tmp/tdpsa-vault-maria.CESC7v/server.sock,
  skip_networking=1, MALLOC_ARENA_MAX=2, unchanged 4 GiB address-space cap, 64 MiB buffer
  pool and 20 connections. Launch connection/session 32200 remains attached to that
  private server. Operational MariaDB PID 692 was not touched. Prior launch 4260 ended.
  Preserve existing diagnostic schemas and logs; do not restart overlapping tests.
- No operational migration, runtime activation, credential, commit, push, Main or
  production change. HR-2026-09-04-003 remains Pending / not practical-review-ready.
  Complete the running verification and reconcile current results before continuing
  retained terminal/generation provenance, consumed snapshots and the remaining rows.

## Completed authority consumed-snapshot binding (2026-09-14, verification in progress)

- An actual origin-consumer regression accepted an unrelated completed authorization
  digest (86023: 1 failed / 450 assertions, 7.50s). Completed authority read-back now
  recomputes the canonical 22 fields and compares the exact retained origin digest.
- The complete canonical authority subject reader returns its validated claim pre-state.
  The approval consumer binds the actual witness ID, retained executor proof/session/epoch,
  original global facts, plan/gate facts, capture instant and expiry. It does not substitute
  current user/authority rows or let a digest alone authorize an in-progress operation.
- The RFC at lines 1078..1080 explicitly selects the graph execution, authority witness or
  quorum mutation/execution pre-state fence for field 20. Do not use a graph claim-subject ID.
  Graph field 15 still needs its exact aggregate encoding/source resolved before wiring.
- Initial SQLite standard/enrollment cases PASS 2 / 1379 (20.75s), session 81271.
  Initial native mysql shared proof-creation/reviewer/obligation consumers PASS 26 / 4959
  (350.17s), session 71749 completed.
- A second regression proved that a claim consumed after effects was still accepted
  (89817: 1 failed / 458 assertions, 7.74s). Claim consumption now must precede or equal
  the capture instant. Witness authorized_at matches the actual planner's original plan
  requested_at, while its physical created_at follows origin creation.
  Positive original-authorization-before-origin and seven distinct canonical snapshot
  mismatch probes are included. Focused SQLite PASS 2 / 1397 (21.21s), session 89578.
  Broad after final timing changes PASS 876 / 32756 (124.15s), session 92108 completed.
- Final native source-only sequence 98097 ENDED exit 0: mysql authority/enrollment
  PASS 2 / 1397 (144.21s), then mariadb proof-creation/reviewer/obligation consumers
  PASS 26 / 4977 (432.98s). No source-reader verification failure remains deferred.
- Current verification: 4584 still runs full thirteen-trigger mysql creation cleanup.
  At 15:26 UTC the isolated database has 291 recorded migrations through the authorization
  core and the synthetic fixture has begun creating proof commits. Exact database:
  tdpsa_vault_audit_5f5c6a3e7edd. Its connection is 152; server PID 654206 and launch
  session 32200 remain live. Do not restart the test or launch a duplicate full migration.
  Earlier comparable full runs took about one hour including isolated migrations.
- Low-memory source readers used separate owned databases alongside that migration,
  not a second large trigger suite. No same-file code edit overlapped either tested
  sequence. The process-only 4 GiB cap is unchanged and operational PID 692 is untouched.
- All local transport patches created in this continuation are removed after apply.
  Preserve the earlier .codex-vault-audit-persistence.patch and unrelated remote scratch files.
  Changed PHP files are 0644 sveintore:projectusers; tracked and scoped untracked whitespace
  checks pass. Health read-back at 15:22 UTC reports local_sealed / vault_disabled (expected
  nonzero exit). Heartbeat ferdigstill-vault-til-human-review is verified ACTIVE; no user
  decision is needed. Final full-run result/resource/doc reconciliation follows session 4584.
- Prior base-cache verification is complete: 91142 mysql 2 / 426 and mariadb 4 / 606,
  no server reset. Full thirteen-trigger compatibility/native creation-consumer verification
  remains part of this checkpoint. Private PID 654206 and operational PID 692 unchanged.
- Runtime off; no migrations, activation, real credentials, commit/push/Main or production
  changes. HR-2026-09-04-003 remains Pending / not practical-review-ready.
- Continue terminal/generation provenance, remaining consumed graph/quorum and in-progress
  authority snapshot/fences, recovery authorization, lifecycle and the product matrix.

## Retained factor use-time and typed quorum planning (2026-09-14)

- Regression first: the reviewer consumer accepted a generation withdrawn between proof
  verification and review (1 failed / 180 assertions, 1.11s). The shared retained-generation
  reader now requires the actual use instant, rejects use before verification or after
  observation, and rejects a terminal generation earlier than that use. Review, request,
  exception, consumption and completed authority snapshot callers supply their own original
  use time. A later reset/disable still preserves historical evidence. Equal timestamps
  still require the owning execution/proof history to establish statement order.
- Source consumers PASS SQLite 13 / 4567 (48.82s), session 13351 ended.
  Final native sequence 54153 ENDED exit 0: mysql 13 / 4567 (242.42s), then
  mariadb 13 / 4567 (239.94s). Both use private MariaDB 10.11.14, not Oracle MySQL.
  Full terminal transition/audit and runtime-generation provenance are still incomplete.
- Added VaultQuorumPlan with the frozen 22-field document and complete five-field
  quorum-authority-facts document. Closed peer/recovery proof/reason/warning and plan
  lifetime/cooling shapes are checked; opaque session bytes may be hashed, not returned.
  Both published vector digests match. The first vector test attempted to return opaque
  production document bytes and correctly hit the opacity guard; the test now uses only
  the published synthetic session vector for byte-length comparison. No runtime guard
  was weakened. Final typed plan units PASS 3 / 57 (0.11s).
- Added VaultQuorumPlanStateSubject for the exact same-plan, enforced locked-to-open
  semantic transition, incrementing the authority epoch once and forbidding preinstalled
  execution fences. It binds current/proposed candidate/global facts and the exact lock/
  cooling interval. Peer subject eligibility is NULL; cooling recovery retains the exact
  eligibility instant. State units PASS 2 / 52 (0.11s). Governance roster projections
  still require complete owning subject/global-facts verification.
- Extended VaultActionPlanHeaderHydrator with a closed quorum family and coherent
  draft/finalized header shapes. The finalized subject pointer equals the base plan ID.
  Actual subject existence, original proof/authority, commit/audit trust and source
  finalization remain the DB owner's responsibility; a hydrated draft is never authority.
  Valid-length governance projections are not invented as extra signed plan fields.
  Combined new quorum and existing graph/authority header units PASS 10 / 822 (0.42s).
- Final selected broad regression 85828 ENDED exit 0: 882 / 34416 (185.33s), no skips.
  Exact selection: all app/Modules/Vault/Tests/Unit plus Integration tests
  VaultActionPlanSourceReaderTest, VaultActionPlanSourceWriterTest,
  VaultAuthorityPlanningReaderTest, VaultAuthorityPlanSubjectReaderTest,
  VaultGraphStateReaderTest, VaultIntegratedPendingActionPlanWriterTest,
  VaultPostReviewEvidenceReaderTest, VaultPostReviewGateReaderTest and
  VaultPostReviewObligationEvidenceReaderTest. This is an explicit selected set,
  not a claim that every full-migration/operational acceptance test ran.
- Only active verification is 4584, full thirteen-trigger native creation cleanup.
  At 15:55 UTC its exact original owned database tdpsa_vault_audit_5f5c6a3e7edd
  contains aborted=1, building=9, finalized=3 commits, showing actual cleanup progress.
  Connection 152 also uses owned tdpsa_vault_proof_source_* scratch databases for
  exact routine checks; this is the same test, not a reason to restart it.
  Private server PID 654206 / session 32200 remains under the unchanged 4 GiB cap;
  operational PID 692 is untouched. Last observed RSS 3272804 KiB, VSZ 3665656 KiB.
- All 12 PHP paths touched in this continuation pass formatting/whitespace checks
  and are 0644 sveintore:projectusers. Seven owned local code transports were removed;
  preserve .codex-vault-audit-persistence.patch and unrelated remote scratch artifacts.
  Health read-back remains local_sealed / vault_disabled (expected exit 1), branch Dev.
- No operational migration, provisioning, real credentials, runtime/UI activation,
  commit/push/Main/production change. HR-2026-09-04-003 remains Pending and is not yet
  ready for practical human review. Automatic continuation remains active.

## Complete quorum source and guarded draft finalizer (2026-09-14)

- Full thirteen-trigger cleanup 4584 ENDED exit 0: mysql 1 / 310 (5784.52s).
  The exact original run progressed through all three proof purposes, live/fresh/resume,
  routine-catalog drift and rollback boundaries. No restart or duplicate migration was used.
  The owned database tdpsa_vault_audit_5f5c6a3e7edd was removed by normal test teardown.
  PID 654206 / socket /tmp/tdpsa-vault-maria.CESC7v/server.sock stayed under the original
  4 GiB address cap, MALLOC_ARENA_MAX=2 and skip_networking. Latest observed RSS 3275252 KiB,
  VSZ 3673852 KiB. Operational PID 692 was untouched. This is private controlled-allocator
  verification, not default-allocator or production acceptance. Do not rerun 4584.
- VaultCanonicalBinaryV1 now builds the complete quorum-subjects set: exactly one same-plan
  state, optional closed authority families, total <=10000, contiguous authority sequence,
  unique identities/canonical entries and no authority subjects for peer unlock.
  VaultAuthorityPlanSubjectHydrator reuses its exact canonical/target checks for quorum
  restoration rows without pretending that mixed recovery actions are one ordinary authority
  operation. Restoration-only eligibility remains the recovery planner's separate obligation.
- VaultQuorumPlanSubjectHydrator and DatabaseVaultActionPlanSourceReader.quorumLocked read
  actual finalized source/header/state/authority rows under the owning transaction. They bind
  current/proposed governance projections to canonical state bytes, reject cross-family rows,
  omissions, swapped targets/locks, sequence gaps and altered complete-set digests.
  Source claims never substitute for the original requester proof or current authority.
- DatabaseVaultActionPlanEvidenceReader.quorumLocked reads reciprocal pending/finalized
  commit/audit/trust metadata, exact human/scope/proof XOR and source-before-audit timing.
  Frozen plan reason reviewed_unlock maps to audit quorum_lock; recovery maps to recovery.
  A pending creation audit stays pending and cannot authorize approval or execution.
- Added the nine VaultQuorumPlanSourceGuardDefinitions, not installed operationally.
  They require an exact live source context/base/proof, current unfenced lock/roster, draft
  assembly, state-before-optional-authority order, contiguous bounded authority rows and a
  one-use draft-to-finalized update. All non-lifecycle header fields and finalized children
  are immutable. Source-family contamination and authority rows in peer unlock deny.
  Synthetic parent column names are checked against actual authority-state DDL.
- VaultPreparedQuorumPlan and DatabaseVaultActionPlanSourceWriter.quorum stage the exact
  base/draft/state/authority rows, verify the complete current semantic lock including root
  incident, reconstruct actual rows and canonical set before guarded finalization, and read
  the finalized source back. Canonical authority target projection is shared with the existing
  authority writer. The owner must roll back; faults at the final source write and after
  successful finalization both restore all source tables. The evidence commit stays building,
  no plan audit is inserted here, no proof is consumed and no lock/authority effect is applied.
- Focused new hydration plus old/new readback PASS 13 / 1697 (4.89s).
  Source writer plus existing graph/authority writer regression PASS 9 / 781 (4.56s) before
  adding final-write fault coverage. Final new reader/guard/writer selection PASS SQLite
  12 / 1217 (4.80s). Sequence 37189 ENDED exit 0: mysql 12 / 1217 (91.32s), then
  mariadb 12 / 1217 (86.27s), same private server without reset; both are MariaDB 10.11.14.
- Final selected broad 71967 ENDED exit 0: 897 / 35764 (194.53s), no skips/deferred failures.
  Selection is the earlier 85828 set plus VaultQuorumActionPlanSourceReaderTest,
  VaultQuorumPlanSourceGuardTest and VaultQuorumActionPlanSourceWriterTest. This is not a
  claim that every migration, full application suite or operational rehearsal ran.
- Thirteen PHP paths pass Pint, tracked/scoped-untracked whitespace checks, and 0644
  permissions. No runtime binding, routes, operational migration/provisioning, real secrets,
  commit/push/Main/production change. Health remains local_sealed / vault_disabled.
  HR-2026-09-04-003 is Pending, not practical-review-ready. Automatic continuation is active.

## Integrated quorum creation evidence (2026-09-14, verified)

- DatabaseVaultPendingActionPlanWriter now accepts VaultPreparedQuorumPlan, delegates
  complete source assembly to the existing writer, records the actual typed pending
  quorum_plan_created audit and reads pending evidence back. Peer plan reviewed_unlock
  maps to audit quorum_lock; recovery maps to recovery. No proof or authority is mutated.
- VaultActionPlanFinalizerBinding has a closed quorum branch for exact same-plan header,
  state and optional authority set, current lock/roster, original proof, window and typed
  reciprocal audit. Foreign graph/authority children and peer authority subjects deny.
  DatabaseVaultActionPlanCreationFinalizer attests the full existing assembly plus the
  nine quorum source guards, rehydrates actual canonical source, compares prepared input,
  finalizes the one-use commit and checks reciprocal trust in the caller-owned transaction.
- Existing graph and authority plans retain their original digestGraphPlan/digestAuthorityPlan
  encoders. The first broad checks exposed incorrect generic canonicalDocument calls in the
  new dispatch: 96008 failed graph (897 passed / 35707 assertions, 218.43s), and 62987 failed
  authority (897 passed / 35849 assertions, 231.35s). Both runs ended before native stages.
  Both dispatches are corrected; these failures are not deferred or counted as passes.
- New VaultIntegratedQuorumPlanWriterTest exercises a real full-migrated guarded assembly,
  real standard/enrollment proof creation and plan/audit finalization. Peer, standard recovery
  and enrollment recovery each cover wrong digest/actor, replay, outer rollback and retained
  completion. Authority remains locked, pending TOTP remains unconfirmed, proof remains
  active, no quorum/UserSecurity execution is created, and runtime remains false.
  Incident and planning facts are synthetic fixture data, not complete root provenance,
  current restoration eligibility or permission to unlock.
- The initial enrollment fixture omitted its explicit proof-finalizer purpose; corrected
  to pass the actual binding purpose without weakening runtime checks. Focused SQLite
  integrated quorum PASS 1 / 130 (37.45s). Updated graph/authority source-writer fixtures
  expose actual quorum column names for shared SQL compilation while remaining synthetic.
- Final verification exec 58505: five-file Pint PASS; selected broad SQLite PASS
  898 / 35894 (232.50s), no skips/deferred failures. Selection is earlier 71967 plus
  the integrated quorum test. Both digest regressions are now passing in that full set.
  The same sequence ENDED exit 0: source writer + integrated quorum mysql PASS
  7 / 772 (2532.77s); mariadb PASS 7 / 772 (2471.80s). Both native drivers used private
  MariaDB 10.11.14, PID 654206, original 4 GiB address cap/socket
  /tmp/tdpsa-vault-maria.CESC7v/server.sock. No restart, duplicate or TTL change was used.
  This completed sequence does not need repeating.
  No operational migration, runtime activation, real credentials, commit/push or Main change.
- Five changed PHP paths pass Pint, tracked/scoped-untracked whitespace checks and
  0644 sveintore:projectusers permission read-back. Seven documentation/readiness pages
  were reconciled; the six owned local transports were removed. No other scratch/history
  was deleted. Automatic continuation is confirmed ACTIVE every ten minutes.
- Historical full-native proof creation-cleanup 4584 is already complete; do not rerun it.
  HR-2026-09-04-003 stays Pending and not practical-review-ready; Slice 04 stays In Progress.

## Finalized quorum snapshot read-back (2026-09-14, native verification running)

- New DatabaseVaultFinalizedQuorumPlanReader implements VaultActionPlanEvidenceReader
  for quorum creation evidence only. It requires the exact owned transaction, installation
  and enforced phase, finalized source owner, original proof-kind XOR/epoch, exact full
  proof/audit assembly and all nine quorum source guards. It reuses the complete canonical
  source and reciprocal audit/trust readers; a pending owner rejects before any trusted result.
- It checks original actor/session/auth epoch, purpose-specific enrollment issuance or
  standard factor generation metadata, proof creation audit/trust at the original request,
  proof validity through original plan finalization, complete plan lifetime and fixed quorum
  classification. Peer unlock is approval-bound; cooling recovery is immediate and needs
  its separate recovery authorization. The returned company-scope snapshot does not
  authorize effects. Later expiry alone does not erase correctly finalized creation evidence.
- This does not implement pre-incident roster provenance, current restoration eligibility,
  historical terminal transition/UserSecurity statement provenance or authority-plan
  classification. Those remain owner work; no runtime interface binding or UI was added.
- Expanded VaultIntegratedQuorumPlanWriterTest covers all three proof/operation branches:
  missing/malformed IDs, required transaction, pending/rolled-back rejection, all snapshot
  fields/classification, missing-guard denial, altered canonical reason after exact guard
  restoration, and successful read after fixture restoration. It still checks no quorum
  effect or UserSecurity mutation. Retained reads happen after all creation transactions.
  VaultIntegratedPendingActionPlanWriterTest additionally proves graph/authority plans
  cannot be read as quorum. Tests use actual full-migrated isolated guarded databases.
- Initial SQLite selection 17923: the two existing integration cases passed; the quorum
  case's attempted warning=0 corruption was rejected by the existing schema CHECK
  (1 failed / 2 passed, 482 assertions, 106.50s). No production guard was weakened.
  The fixture now switches to the structurally allowed but operation-inconsistent recovery
  reason and restores reviewed_unlock. Targeted corrected run 80926 ENDED exit 0:
  quorum integration PASS 1 / 199 (38.87s). All earlier positive reader branches passed.
- Three touched PHP paths pass Pint, tracked/scoped-untracked whitespace checks and 0644
  sveintore:projectusers permissions. Prior creation verification 58505 fully ended.
  New sole running verification is exec 28255: expanded integrated quorum test on mysql
  then mariadb sequentially, original private socket/PID/cap. Do not overlap or restart.
  No broad rerun of already-passing unrelated tests, operational migration, real secrets,
  runtime activation, commit/push or Main change.
- Slice 04 remains In Progress; HR-2026-09-04-003 Pending/not practical-review-ready.
  The automatic continuation remains active and no ordinary go-ahead is required.

## Exact continuation

1. Native quorum read-back is complete: mysql 1 / 199 and mariadb 1 / 199. The
   durable mariadb rerun 29962 ended exit 0, 2582.19s, JUnit failures/errors/skips zero.
   The subsequent graph factor regression is fixed and verified on migrated SQLite:
   3 / 308, 69.47s, exec 85285 exit 0. Native 17084 failed on mysql (176 assertions,
   2477.89s), so mariadb did not start. Its rollback catch hid the real exception;
   diagnostic 96875 exposed PDO vault_action_plan_source_denied (1 / 175, 2493.87s).
   The six independent scenarios now use separate fresh proof sessions; product TTLs
   and all guards remain unchanged. SQLite 2 / 317 passes (82.26s). Native sequence
   1964 completed exit 0: mysql 1 / 226 (3092.82s), mariadb 1 / 226 (3111.11s).
   Per-driver logs/JUnit: /tmp/vault-graph-fixture-isolation.pru8De/. No test remains
   running from that sequence. Continue the historical expired-on-retry evidence reader.
   Do not restart completed verified graph or quorum sequences.
   Keep the private MariaDB service available; preserve older diagnostic databases and
   unrelated scratch files. The server remains under the original 4 GiB address cap.
2. Wire the current scoped/global/graph readers and owned planning transaction into the
   authenticated planning coordinator. Complete the current full-set post-review DB adapter,
   source provenance and committed overdue-observation path; do not trust cached gate counts.
   Review and obligation source/audit read-back plus reviewer proof creation now exist.
   Origin stage/plan/obligation and approval consumption/request/exception/lifecycle metadata
   linkage now exist, including request/exception creation audits and retained proof issuance.
   Duplicated request/base/subtype fields and closed exception operation policy are now bound.
   Phase-matched consumption commit/audit/trust and actual mutation identity linkage now exist.
   Completed mutation phase and primary operation audit/trust now also have actual read-back.
   Completed authority witness/paired UserSecurity physical ledgers now reuse the actual closed
   finalizer predicate. Completed graph execution/target sets and exactly two scope subjects
   now also have canonical read-back, short fence timing and correct primary audit references.
   Canonical authority subjects, UserSecurity statements and witness OLD/NEW/fence values now
   also have actual read-back. Full canonical graph/authority plan creation and reciprocal
   commit/audit/trust are now bound to request creation in the approval-origin reader.
   Completed authority origins now also bind the canonical consumed pre-state snapshot,
   actual executor proof/session/epochs, original witness authorization and pre-effect claim.
   Do not repeat those checks. Next finish the remaining consumed pre-state families and historical proof
   terminal/generation provenance, plus the distinct recovery-authorization consumption source.
   Retained factor withdrawal is now checked against each actual use time; this does not
   replace terminal transition/audit provenance or same-instant statement-order evidence.
   Graph target counts exclude the separate claim/finalize scope subjects (existing
   VaultGraphWriteExecutionSnapshot). Preserve the exact early fence-claim timing exception,
   sequence-order locks and inert pending composite stages; do not fabricate authority flags.
   Graph audit mutation identity resolves through execution and authority through witness; do not
   substitute the preallocated execution/witness ID. Missing audit on building consumption is
   explicitly inert, never trusted future evidence or permission to claim/execute an origin.
   Exact one-subtype and full canonical graph/authority creation evidence are now enforced;
   historical proof terminal/generation provenance and post-review lifecycle still need the actual owning adapter;
   derive expected scope from that verified origin, never from a caller-selected customer.
   Classify inert building/aborted sources separately rather than treating them as finalized
   findings or silently filtering away missing/mismatched projections. Bind the real lifecycle
   finalizer to actual pending-source/projection/audit checks; never manufacture trusted evidence
   from a building commit to satisfy the earlier pure coordinator's fake repository contract.
   Finalized graph and quorum creation evidence readers now exist; authority reader dispatch
   and current graph replay still need full classification/provenance and all-operation coverage.
   The complete structural graph reader and creation finalizer now exist; do not replace
   current authorization or reciprocal provenance with boolean trust assertions.
3. Quorum source, pending creation audit and shared commit-finalizer dispatch now exist.
   Finish the integrated native verification above; do not repeat source assembly. Current
   recovery eligibility/restoration-only policy and pre-incident roster provenance remain
   owning-planner obligations, not source trust flags. Quorum snapshot dispatch is verified on both native drivers. Complete
   authority classification/dispatch and full retained proof provenance before runtime binding.
4. Complete plan/approval source stores, owner/consumption/origin/classification integration
   and the coordinated exact guard/cutover assembly, then full Slice 04 verification.
5. Continue the remaining approved product rows in the completion matrix, including
   technician workspace/navigation, before inviting practical human review.

Runtime stays off; no actual secret acceptance, Main/production changes, real migration,
commit or push. Svein's completion mandate and existing automatic continuation remain active.
Only an actual external dependency or material missing decision requires user intervention.
## Historical expired-on-retry read-back (2026-09-15)

Implemented directly on Dev in:
- Database/Readers/DatabaseVaultExpiredProofReplacementEvidenceReader.php
- Database/Stores/DatabaseVaultExpiredProofReplacementFinalizer.php
- Tests/Unit/VaultProofSourceGuardTest.php

The new reader is wired into the existing expiry finalizer, not a separate runtime
entry point. It requires the exact owned transaction and guard catalog. It binds the
effective old-proof terminal claim to the exact replacement, original issuance and
finalized revocation commit/audit/trust. It supports standard proofs and both enrollment
purposes. Current account/gate changes and later replacement activation do not revoke
historical evidence. It neither authorizes current use nor validates other terminal causes.

Tests cover pending/finalized history, real replacement activation, wrong actor/session,
original and terminal audit substitution, missing guards, altered transition/terminal/
replacement metadata, and wrong trust state/owner. Exact guards are restored before
each synthetic corruption read, so a catalog failure cannot mask a provenance failure.
The fixture now gives the transition commit its own exact 300-second build interval,
rather than copying the earlier proof commit expiry. No application TTL changed.

Initial focused SQLite: 2 / 87, 3.78s. Full source/lifecycle class: 62 / 4113, 58.52s.
After additional trust-tamper cases, all Vault units: 858 / 28124, 69.92s, exec 1903
exit 0, JUnit zero errors/failures/skips. Pint three files PASS; permissions 0644.
Native sequence 70273 failed mysql (4 failed / 3 passed, 181 assertions, 216.71s):
private service OOM at the original 4 GiB cap, followed by connection refusal. The
history-reader tests passed but the combined native flow remains unverified. The private
service was recovered against its same data directory, PID 772606 / session 78212.
Four legacy multi-purpose cases now release completed fixtures between purposes.
Corrected SQLite 7 / 339 (12.67s) passed, but native 11204 again exhausted the private
4 GiB cap in the raw enrollment lifecycle writer (3 failed / 4 passed, 140 assertions,
203.82s). Per-purpose cleanup alone is insufficient. The existing cached base-assembly
fixture is now used by five writer/lifecycle cases, exactly as approved in the
2026-09-13 cached-predicate ADR's base extension. No production guard or limit changed.
Full SQLite verification 91698 passed 858 / 28124 (73.01s). Native enrollment-only
probe 41843 PASS 1 / 100 (246.31s), exit 0. Native sequence 30154 ENDED exit 0:
mysql 7 / 387 (926.41s), mariadb 7 / 387 (871.68s), on the same private service.
Preserve failed *-fixture-release logs; passing logs use {mysql,mariadb}-cached in
the same directory. Private service PID 775248 / session 24058 retains the original
data and limits. No test from this sequence remains running. Do not rerun these
verified sequences. Continue retained proof terminal integration in graph/quorum
read-back, then the remaining owner-specific terminal branches and provenance.
Do not rerun verified graph sequence 1964 or quorum sequence 29962.

After this verification, continue the remaining historical terminal branches and
generation statement provenance, authority/recovery readers and owning planning/
post-review flow. Slice 04 remains In Progress; rows 05..16 remain unfinished.
No operational migrations, real secrets, runtime activation, commit/push or production
change. HR-2026-09-04-003 remains Pending and not practical-review-ready.
