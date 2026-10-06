# Feature Slice: Vault Central Authorization, Grants, Collections, Step-Up, And Approvals

Status: Approved / Blocked - pending-admin TOTP onboarding decision
Date: 2026-09-04
Parent: docs/rfc/2026-09-04-vault-domain-and-operational-credential-platform.md
Architecture: docs/adr/2026-09-04-vault-domain-ownership-and-relationship-boundary.md and docs/adr/2026-09-04-vault-authorization-step-up-approval-and-sole-admin-exception.md
Owner: Codex
Approval: Approved for implementation by Svein Tore on 2026-09-05 via explicit RFC/ADR approval and “Iverksett”; this does not claim line-by-line Feature Slice review.
Human Review: HR-2026-09-04-003

Current Slice 04 work (2026-09-14): authenticated planning and post-review evidence integration.

Current execution checkpoint (2026-09-18): **4 of 17 main parts Done On Dev**; 04 Blocked.
Decision owner Svein: pending-admin TOTP onboarding versus the second-candidate readiness floor.
The accepted invitation separation remains unchanged; see the delivery handoff for the new gap.
Owner: Codex / Svein. Workflow repair is Done On Dev; next product deliverable is the complete 04-A flow.
See [the current delivery handoff](../plans/2026-09-17-vault-delivery-workflow.md) for the
active batch, terminal evidence, acceptance criteria and exact next action. Sequence 35999 passed;
do not restart it. The linked handoff owns automation/test status. HR-2026-09-04-003 remains Pending;
this is not a practical UI review invitation. No operational migration or runtime activation.
The older dated continuation notes below are historical evidence, not current execution instructions.

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

The previous bounded UserSecurity optimization is verified: final sequence 13919 ended
successfully, nine native drift denials per driver and 60 focused tests / 1270 assertions.
The earlier broad run passed 1248 / 48064 (89 explicit opt-in native skips); sequential
private native creation cleanup passed mysql 1 / 298 and mariadb 1 / 299 without reset.
This is controlled private-allocator verification, not operational/default-allocator acceptance.
Action-plan base and graph/authority child guards are verified but unbound: actual DDL
and synthetic external facts PASS 5 / 642 on SQLite, mysql and mariadb independently.
Canonical decoder PASS 28 / 142; all Vault units plus source integration PASS 804 / 26201.
Authority hydration PASS 12 / 424; graph entry hydration PASS 6 / 53, every family.
The shared graph operation matrix and retained-subject checks PASS 6 / 386.
Locked authority/graph readers PASS 1 / 28 on SQLite and each native driver.
Final all Vault units plus both source/read-back integrations PASS 823 / 27026 (67.07s),
no skips; exec 90514 ended. Live enabled/runtime-approved flags remain false.
Next: owning plan/proof/audit verification, complete quorum assembly, guarded plan/approval
stores and finalization under the same active slice. No new product feature is started.
Current detail: docs/plans/2026-09-14-vault-action-plan-source-readback.md.
See docs/plans/2026-09-13-vault-mariadb-trigger-memory-rework.md for retained evidence.
Runtime off; HR-2026-09-04-003 remains Pending and not practical-review-ready.

Pending-proof cleanup continuation (2026-09-13): source/phase tests PASS 4 / 144; paired
revoke-only definitions are connected to the shared internal proof catalog. Actual source,
production audit and coupled terminal test PASS 1 / 54 across all three purposes, including
last-write rollback/retry. Broad plus both drivers PASS 836 / 26,777, no skips/deferred failures.
The locked caller-transaction writer passes 1 / 66. Shared units PASS 820 / 25,597 and both
writer drivers PASS 1 / 66: combined 822 / 25,729, no skips/deferred failures; runs finished.
Atomic creation cleanup is now connected through exact retained-terminal binding, locked
metadata reader, fixed preparation/finalization, coordinator and staged-loser repair.
Expanded full-migrated SQLite PASS 1 / 248 (1:17.387): all purposes, reciprocal-provenance
denials, live/replay denial, rollback at all three commit writes, fresh completion and retained
resumption. Broad PASS 836 / 26,983. The private-driver live fixture aged out during earlier
expensive cases; test ordering now checks all live cases first with explicit DB-time freshness.
No runtime or TTL change. Corrected SQLite PASS 1 / 251; both drivers rerun in exec 86813.
TODO records the failed run, correction and retry logs; no failed check is deferred.
No operational installer/runtime activation or completion of the remaining authorization/product work.

Latest progress (2026-09-13, inert replacement-transition cleanup, verified bounded flow):
the expiry branch accepts only exact expired_on_retry retained transitions, with both original
and replacement proofs unchanged. Abort-enabled shared proof writes now require finalized
commits. A metadata reader and explicit coordinator entry point cover standard and both
enrollment purposes; other owner-bound transition families stay closed. Expanded migrated
transition/source/expiry/staged-loser tests PASS 4 / 269 (2:06.321). Parser/conjunction tests
PASS 4 / 20, including nested SELECT/CASE and NULL semantics. Guard expression construction
now avoids SQLite parser/AST limits without relaxing leaf checks. Broad PASS 829 / 26,471,
mysql PASS 1 / 124, mariadb PASS 1 / 125; combined 831 / 26,720, no skips/deferred failures.
Exec 9032 finished; exact timings/logs are in TODO. Final formatting/diff/permissions pass,
flags false, helpers unbound and private resources cleaned. Synthetic history is not operational cutover.
No activation; Slice In Progress and HR Pending/not practical-review ready.

Previous progress (2026-09-13, staged losing-cleanup repair, verified bounded flow): the approved
later higher-sequence repair path now has exact source/winner/typed-audit/trust predicates,
locked preparation and atomic finalization. It supports only losers of db_time_expired inert
attempt cleanup, not arbitrary abort families. Original expiry rules remain closed; retained
finalized winner evidence survives its build TTL. New integration tests cover staged attempts,
wrong targets, rollback and immutable winner history. SQLite baseline 2 / 88 plus expanded
loser 1 / 57 (32.123s) pass, including both contender sequence orders and retained winner TTL.
A test-only non-consuming inspection-context error was corrected; runtime did not change.
MySQL passes 1 / 57 (34:52.271); MariaDB passes 1 / 58 (30:03.894).
Broad regression passes 824 / 26,327 (8:20.105); combined 826 / 26,442, no skips or deferred
failures. All runs finished. Live flags false, helpers unbound, final Pint/diff pass. TODO
tracks owned-resource cleanup and next inert-transition source family. No activation;
Slice remains In Progress and HR Pending/not practical-review ready.

Previous progress (2026-09-13, complete inert-attempt expiry flow): closed phase predicates,
explicit shared guard/catalog assembly, two-CAS finalizer and one-transaction coordinator now
join preparation with target/trust abort and cleanup/trust finalization LAST. SQLite source/flow
PASS 2 / 81 (54.891s), expanded whole-flow rollback PASS 1 / 57 (30.110s). MySQL PASS 1 / 57
(34:47.701); MariaDB PASS 1 / 58 (29:56.582). Broad PASS 819 / 26,020 (5:39.222), additional
same-assembly consumer regressions PASS 4 / 250 (2:11.206). Combined 825 / 26,385, no skips
or deferred failures. All runs finished; live flags false, coordinator/finalizer unbound.
TODO tracks resource cleanup. Other source-release matrices and
staged losing-cleanup repair are not opened. No runtime/operational activation. Slice remains
In Progress and human review Pending/not practical-review ready.

Previous progress (2026-09-13, locked cleanup preparation): internal caller-owned writer locks
the target/source/audit/trust and canonical identities, checks exact full guard catalogs and
creates a newer building cleanup commit/source with actual typed pending audit. Sealed migrated
SQLite PASS 1 / 26 (27.898s), including full rollback and missing-guard/live-target denial.
MySQL PASS 1 / 26 (32:50.149); MariaDB PASS 1 / 27 (26:26.092). Final broad PASS 819 / 25,989
(5:53.699), combined 821 / 26,042, no skips/deferred failures. All runs completed; live flags
false and preparer autoloads but remains unbound. TODO tracks cleanup. No target
abort or cleanup finalization yet; these must remain in the same transaction. No runtime
activation or operational entry. Human review remains Pending/not practical-review ready.

Previous progress (2026-09-12, expired-attempt abort source): immutable source guards now
require the exact canonical actor, DB expiry, newer cleanup commit and retained attempt/audit/
pending trust. Other kinds deny. Actual migrated SQLite fixture PASS 1 / 31 (26.246s), including
raw denial, negative cases, rollback and retention. The raw-denial test now asserts the exact
inactive SQLite context exception; runtime was unchanged. MySQL-driver PASS 1 / 29 (30:10.631),
verified 2026-09-13. MariaDB PASS 1 / 30 (25:29.937), final broad PASS 818 / 25,963 (5:20.185).
Combined 820 / 26,022, no skips/deferred failures. All test processes finished; TODO tracks
owned-resource cleanup. Source-only: typed cleanup
audit, target/trust abort and cleanup finalization LAST still require implementation. No runtime
activation, scheduler or operational migration. HR remains Pending/not practical-review ready.

Previous progress (2026-09-12, retained-history writer): new integrated test uses actual migrated
owner schemas, whole cutover, historical preservation and the production foundation recorder.
SQLite PASS 1 / 30 (35.570s) after restoring the missing existing TOTP replay fixture guard.
MySQL PASS 1 / 23 (46:55.925); MariaDB PASS 1 / 24 (40:08.635). Final broad PASS 817 / 25,932
(4:43.708), combined 819 / 25,979, no skips/deferred failures. No runtime/operational activation.
Remaining owner/consumption/abort/classification and product work precede review; TODO owns continuation.

Previous progress (2026-09-12, last-only thaw/completion): internal DDL verifies exact runtime/
permanent guards and the full locked stage-4 manifest before removing only the freeze. Closed
post-thaw readers and a one-use DML completion context verify the full set before/after the
revision-4-to-5 CAS. No classification or operational/runtime entry. SQLite PASS 2 / 264
after a test-only replay assertion correction. Full drivers PASS 6 / 768; broad regression
PASS 816 / 25,902, combined 822 / 26,670. Runtime flags false; helpers autoload but are unbound.
Seven PHP files pass Pint/diff. TODO tracks server/continuation. Remaining integrated full-owner
retained-history/writer, owner/consumption/abort/classification and product work precede review.

Previous progress (2026-09-12, frozen runtime validator): exact legacy/gap/runtime catalogs
retain freeze and permanent trust protection while the locked guard_installed manifest and
lossless timestamp precision are verified. SQLite PASS 2 / 206; full drivers PASS 6 / 598,
broad PASS 816 / 25,902, combined 822 / 26,500. No operational/runtime entry.

Previous implementation progress (2026-09-12, frozen guard-installed marker): a one-use owned
DML context now proves permanent-only catalog plus full locked backfilled set/view, CASes
revision 3 to 4 and verifies fresh post-state. Raw/replay/temporary/overlap/wrong-identity paths
deny; retained guard_installed has a read-only checkpoint. SQLite PASS 2 / 173; full drivers
PASS 6 / 507 and broad regression PASS 816 / 25,902, combined 822 / 26,409. No completed marker,
thaw, operational DDL/runtime or real credentials. Remaining typed owner/consumption/abort/
classification and product work still precede review. Slice 04 In Progress; HR Pending.

Previous handoff verification: three-driver 6 / 384 plus final broad 816 / 25,902 PASS:
822 / 26,286, no skips/deferred failures. The earlier handoff implementation is described below.
Previous implementation progress (2026-09-12, permanent-guard handoff): the exact frozen catalog
and complete locked manifest/trust/view read-back now guard permanent CREATE before temporary
DROP. Exact overlap/permanent restart is supported; missing-both/drift/unknown/ownership deny.
SQLite empty/nonempty DDL rollback/restart and full driver verification passed as recorded above.
At this earlier checkpoint the header stayed backfilled and audit stayed frozen;
no later-stage CAS, operational installer, runtime binding/activation or real credentials.
Cutover completion, typed owner/consumption/abort/classification and product work remain.
Slice 04 In Progress; HR-2026-09-04-003 Pending/not practical-review ready.

Latest implementation progress (2026-09-12, owned backfill coordinator): exact retained-stage
dispatch joins manifest capture/resume, missing-child backfill and its stage CAS in one owned
cutover transaction. Backfilled no-op proves the whole set/view/catalog afresh. Empty/nonempty
rollback/resume/identity/drift checks PASS 6 / 268 on SQLite/mysql/mariadb; later owner tables
remain explicit structural fixtures. Final broad regression PASS 816 / 25,902 (4:07.583):
822 / 26,170, no skips/deferred failures. All runs finished; owned schemas/server cleaned.
Two PHP files pass Pint/diff/permissions. Permanent guard switch/thaw, other typed owner/
consumption/abort/classification and product work remain. No runtime/operational entry or
guard removal. TODO/HR record scope and the next concrete step.
Slice 04 In Progress; HR-2026-09-04-003 Pending/not practical-review ready.

Latest verification progress (2026-09-12): full migrated real-factor boundary PASS on mysql
1 / 103 (24:49.424) and mariadb 1 / 104 (23:31.869). Final broad SQLite PASS 816 / 25,902
(4:14.510): 818 / 26,109, no skips/deferred failures. Four files pass Pint, diff/permissions
pass, all runs finished and exact private schemas/server are cleaned. The initial mysql failure
and test-only per-submission TOTP correction are retained in TODO/HR; runtime rules are unchanged.
Remaining typed owner/consumption/abort/cutover and product work still precede review.
Slice 04 stays In Progress, runtime disabled and human review Pending/not practical-review ready;
no operational migration or activation.

Previous implementation progress (2026-09-12, guarded rate anchors): exact one-use empty seeding and
audited threshold locks are implemented; raw mutation, deletion/replacement, wrong identity and
active-lock extension/reset deny. The rate repository and pruner require a complete exact rate
manifest. Dormant cold-connection SQLite functions do not grant authority. Final broad 816 /
25,903 plus both driver/worker contracts 2 / 118 pass: 820 / 26,139, no skips/deferred failures.
TODO/HR-2026-09-04-003 record private fixture limits and cleanup. Full migrated-Maria factor
boundary, remaining typed owner/consumption/abort/cutover and product work remain. Runtime stays
off, Slice 04 In Progress and human review Pending/not practical-review ready.

Previous implementation progress (2026-09-12, telemetry retention): an exact optional telemetry
assembly now guards consumed-source INSERT, forbids UPDATE and permits only one-use old-row
DELETE with finalized permanent evidence/audit. A separate bounded pruner preserves active locks,
proofs and durable history; no nested maintenance or runtime binding. The migrated real-factor
boundary uses the guards. Broad 816 / 25,891 plus both driver retention contracts 1 / 25 PASS:
818 / 25,941, no skips/deferred failures. A MariaDB collation regression is fixed. Exact evidence
and cleanup are in TODO/HR-2026-09-04-003. Raw anchor guards/final rate assembly, full migrated-Maria
factor boundary and typed owner/cutover paths remain before UI/product slices. Runtime disabled,
Slice 04 In Progress and human review Pending/not practical-review ready.

Previous implementation progress (2026-09-12, combined attempt transaction): standard and both
enrollment purposes now share rate preparation, a one-use same-connection factor claim, real replay
CAS and proof/audit finalization in one transaction. Legacy standalone entries stay top-level.
Actual factor/proof failures roll back; live reuse does not extend expiry; locked attempts never
invoke factors/counter reads. Final broad SQLite 815 / 25,862 plus real worker/driver contracts
1 / 81 per driver pass: 817 / 26,024, no skips. Workers verify the fifth-session and twentieth-actor
thresholds under contention, with DB clocks captured after lock release. Exact evidence/limits are
in TODO and HR-2026-09-04-003. Pruning/raw rate-write guards, full driver-boundary verification and
remaining typed owner/cutover paths still precede UI/review. Runtime off; no operational DDL.

Previous implementation progress (2026-09-12, durable attempts/rate persistence): optional exact
source/finalizer guards and an internal writer connect failed-attempt evidence to real denied audit
and trust-LAST. Evaluated attempts have one rate row; skipped attempts have none. The internal rate
repository captures its clock after both anchor locks, binds one-use state to the owning transaction,
and atomically records/counts/locks. Fifth session and twentieth cross-session failure, exact window
bounds, no active-lock extension and full rollback pass. Migrated SQLite 1 / 129 and synthetic
driver contracts 4 / 225 per driver pass; final broad evidence is in TODO/HR-2026-09-04-003.
No runtime binding/activation or operational DDL. Complete same-transaction factor/replay/issuance,
multi-worker concurrency, pruning/raw rate boundaries and other typed owner/cutover paths remain
before the workspace and practical review. HR stays Pending; automatic continuation is active.

Previous implementation progress (2026-09-12, standard/enrollment lifecycles): the unbound repositories
now execute real create/reuse/expired replacement and trusted read-back in one owned transaction.
They never extend a reused proof or invent authority for non-expiry drift. The real revalidator now
locks physical root, authority, security gate, user and generation in that order and rejects held
fences. Enrollment additionally binds the exact pending generation and closed confirmation/recovery
purpose; neither proof issuance nor reuse confirms TOTP or authorizes the action. Actual bcrypt/
TOTP/replay CAS plus migrated issuance passes 2 / 64. Final broad SQLite passes 808 / 25,387;
repository contracts pass 2 / 114 on each MariaDB driver: 812 / 25,615, no skips. Six PHP files,
permissions and diff checks pass. Both private servers/schemas are cleaned and no tests remain.
No runtime, HTTP boundary, operational cutover or practical-review readiness. Next is durable
attempt source/audit completion before rate orchestration and the remaining typed owner paths.
HR-2026-09-04-003 stays Pending; remaining credential and owner/cutover work continues in this slice.

Earlier implementation progress (2026-09-12, primary rehash audit): the explicit integrated store
now creates/reuses the exact primary event, binds it during parent/gate completion and trusts it
LAST. A verified-login actor scope is activated only after locked password preflight; it never logs
in a user or grants step-up. Actual bcrypt failure/rollback/success/no-op, proof invalidation/resume,
direct trust/binding denial and wrong-primary gate rollback are tested. Broad current SQLite passes
802 / 25,183, no skips. Exact per-run ownership prevents borrowing login identity in a later
transaction on the same PDO; reconnect/failure paths also pass. Extra Feature regression passes
29 / 306. Full migrated mysql/mariadb pass 1 / 236 and 1 / 237, completing 833 / 25,962 with
no skips; private resources are cleaned. HR-2026-09-04-003 records the boundary. Ports stay unbound and
runtime stays off. Remaining credential, owner/cutover and product slices still precede review.

Earlier implementation progress (2026-09-12, actual UserSecurity store integration): internal source/
audit creation, all-session proof enumeration and retained-source resume are connected through an
explicit unbound port. The default store still denies active proofs; the integrated adapter requires
the complete exact guard set and leaves audit trust/gate-LAST to the parent. Actual migrated SQLite
execute/credential-effect/rollback/forced-commit/resume passes 1 / 163. Synthetic writer contracts
pass 6 / 384 on each MariaDB driver; final broad SQLite passes 743 / 25,367 and strict parent-
assembly rejection passes 1 / 3 on each MariaDB driver. Existing store/resume Feature regression
passes 18 / 230. Full migrated-Maria integration passes 1 / 163 and 1 / 164; completed evidence
totals 777 / 26,698, no skips. Both schemas and the exact empty private server were cleaned.
HR-2026-09-04-003 records scope and limitations. Remaining credential-flow coverage,
rehash-primary audit and source/owner/cutover still precede the workspace. No runtime, public port,
operational migration or practical-review readiness. Earlier progress entries are historical.

Implementation progress (2026-09-12, timestamp precision and retained terminal resume): the
internal frozen DATETIME(6) transition and full-catalog precision check are implemented. The
UserSecurity terminal branch supports exact already-created/audited owner completion past its
unchanged expiry; new source/audit scope and ordinary issuance/replacement remain expired.
Completed SQLite and selected mysql/mariadb contracts total 748 / 25,276, no skips; HR-2026-09-04-003
records fixture limits and exact runs. Full migrated-Maria writer runs remain in progress (reuse
the sessions in TODO). Internal invalidation writers, enumeration/application resume, rehash audit
and remaining owner/cutover integration are still outstanding. No operational installation,
runtime/public binding or practical-review readiness is implied.

Implementation progress (2026-09-12, integrated proof/audit catalog): exact parent assembly
attestation and dormant SQLite UDF preparation now support the ordinary proof writers. A real
migrated SQLite test exposed the old audit validator rejecting Row04 events; the explicit runtime
replacement now shares closed one-use semantics with AFTER while retaining base structure and
append-only protection. Real standard/enrollment source/trust completion and outer rollback pass.
Final units/core plus selected mysql/mariadb tests total 729 / 24,938 without skips; HR-2026-09-04-003
records the earlier broader run and synthetic-driver limits. Invalidation writers/enumeration/resume,
primary rehash audit and owner/cutover remain. The planned MariaDB audit DATETIME(6) transition must
precede actual migrated-Maria writer verification. Nothing was operationally installed or enabled.

Implementation progress (2026-09-12, UserSecurity proof-set finalizer): the explicit gate-owned
assembly now couples exact terminal audit trust and execution/gate-LAST completion. Physical
subject/source completeness, every-session active-proof denial, nested marker ownership, exact
audit projection identity and rollback are tested. MariaDB UUID/context collation parity was fixed
using binary identity checks. HR-2026-09-04-003 records verification and synthetic-parent limits.
Shared catalog/UDF setup, internal writers/enumeration/resume and rehash-primary audit remain before
UserSecurity store integration; other terminal/issuance/owner/cutover work still precedes the UI.

Implementation progress (2026-09-12, UserSecurity proof transitions): exact gate/epoch/executor
source validation and coupled terminal commit/proof writes are implemented. Terminal audit trust
stays pending until the coordinated UserSecurity finalizer; the gate remains retained. The existing
transition row is each proof's source, without changing the closed UserSecurity subject registry.
Real human audit insertion, exact consumed epoch post-state and rollback tests pass; final units/core
are 716 / 24,272. Driver verification and fixture limits are recorded in HR-2026-09-04-003.
Complete parent trust/gate-LAST finalization, all-proof enumeration, writers and exact resume next.
Other terminal causes and issuance/owner/cutover integration remain; runtime and UI are still off.

Implementation progress (2026-09-12, enrollment and expiry writers): DatabaseVaultPendingProofWriter
now accepts distinct standard/enrollment bindings; enrollment issue/expiry are database-derived
with fixed 300-second lifetime and exact purpose audit mapping. The expiry writer creates its own
source/commit/audit and uses the coupled finalizer. Real internal-writer replacement/rollback tests
cover standard, confirmation and cooling recovery. Final units/core and selected driver runs pass
720 / 24,375, no skips; see HR-2026-09-04-003 for limits and exact runs. Other terminal causes and
complete credential/repository/owner/cutover integration remain next; runtime and UI stay disabled.
The complete issuance coordinator must create the pending replacement within the same transaction.
Earlier progress entries below describe their historical checkpoint, not current completion claims.

Implementation progress (2026-09-12, coupled expiry and pending standard writer): expired-on-retry
now has a guarded source/commit/terminal-proof/trust-LAST path and an internal application finalizer.
Old revocation precedes new activation; competing claims and complete transaction rollback are
covered for both proof kinds. A standard pending writer creates its own sequence/commit/source/audit
under the existing owned issuance transaction, without exposing opaque session bytes to query logs.
Final units/core plus both driver groups pass 745 / 28,117, no skips; exact runs and fixture limits
are recorded in HR-2026-09-04-003. No operational installer, public binding, runtime, UI or full
issuance/lifecycle readiness is implied. Pending enrollment/expiry-source writers, other closed
terminal causes, full repositories and the remaining owner/origin/cutover integrations are next.

Implementation progress (2026-09-12, expired replacement source): standard/enrollment
expired-on-retry source INSERT and immutability are guarded by exact old creation trust,
current replacement security/session/purpose and separate evidence identity. Final unit/core
and selected driver runs pass 699 / 23,826 without skips. No terminal state changes yet;
the coupled terminal finalizer remains next. HR-2026-09-04-003 records fixture boundaries.

Implementation progress (2026-09-12, coupled proof creation): the explicit integrated guard
assembly now permits only creation-commit finalization, nested pending-to-active source and
audit-trust finalization LAST. The application finalizer retains owned locks, current actor/
session/purpose, exact guard catalog and trusted read-back. Real guarded audit insertion is
covered; terminal transitions and the complete issuance repository remain next. Final units/core
and both isolated drivers pass 712 / 26,261, no skips; HR-2026-09-04-003 records fixture limits.
The frozen protocol and operationally disabled status are unchanged.

Implementation progress (2026-09-12, pending proof sources): exact one-use pending INSERT and
retention guards now cover standard/enrollment source identities, current security facts,
fences and database-time windows. Direct UPDATE remains closed pending coupled creation and
terminal finalization. The earlier broad Feature/core run passed 252 / 17,628. Final units/core
passed 682 / 21,823 and isolated mysql/mariadb source-guard cases passed 3 / 269 each, no skips.
See HR-2026-09-04-003 for permissive-fixture versus actual-core compilation limits. No source
writer, operational installer, public binding or runtime activation was added. Continue coupled
evidence/source/trust finalizers and the proof lifecycle repository within this same slice.

Implementation progress (2026-09-12, live proof integration): standard and single-purpose
enrollment readers now reload and lock current security and exact finalized creation provenance.
The shared creation reader uses closed proof-kind/event/ref/purpose-reason mappings; the central
freshness adapter retains the enclosing transaction and never falls back to stale actor fields.
Units passed 674 / 20,916; identical reader/adapter cases passed 14 / 403 on each isolated MariaDB
driver (702 / 21,722 across final runs, no skips). See HR-2026-09-04-003 for regression/fixture
details. No source writer, mutation eligibility, runtime binding or full authorization readiness
is implied. Continue canonical plan/source DML, complete owner/origin finalizers, trust/cutover
and repository integration. The approved frozen protocol below remains unchanged.

Implementation progress (2026-09-12, approval source and phase integration): exact trusted
request/decision/exception evidence passed 691 / 43,091 across units/SQLite/MariaDB/core assembly.
Authority finalization additionally binds ordinary building reservations or finalized exception
consumption/obligation evidence, the origin stage and reciprocal retained fences. The pure lifecycle
also supports peer-approved TOTP confirmation with its exact enrollment proof. Units/SQLite pass
675 / 28,752; final mysql/mariadb and migrated-core verification passes 17 / 16,750, without skips.
The two final runs total 692 / 45,502. HR-2026-09-04-003 records
red/green evidence and remaining live-proof/canonical-source/coordinator/shared-trust limitations.
These guards do not supply operational source writers or open runtime. Continue the active slice.

Implementation progress (2026-09-12, approval relation binding): exact request/consumer/witness
identities, mode, proof/session, plan version/digests and ordinary/exception shapes now precede
authority finalization. Independent peer approval of TOTP enrollment is explicitly covered.
See HR-2026-09-04-003 for the broad and final targeted verification. This does not complete
approval-source trust, lifecycle/commit phases, origin fences, live proof or protected source DML.
The frozen ordinary-versus-exception finalization order below remains authoritative. Continue
the integrated Slice 04 flow; runtime and shared Row04 trust finalization remain closed.

Implementation progress (2026-09-12, finalized plan source): authority finalization now checks
the exact finalized action-plan evidence commit and audit-trust child, including source/event,
actor/proof/reason and exclusive typed-reference/owner correspondence. Combined verification
passed 683 / 30,844; see HR-2026-09-04-003 for regression evidence and remaining limitations.
This source prerequisite does not complete the source/operation/approval writers or shared
trust finalization. The frozen protocol below and runtime-disabled boundary are unchanged.

Implementation progress (2026-09-12, exact plan/subject identity): the authority finalizer now
requires matching extension reason/digests and a complete one-to-one planned/applied subject
set by identity, installation, sequence, kind and typed targets. See HR-2026-09-04-003 for
regressions and driver verification. Canonical before/after values, actual domain post-state,
proof/approval provenance and shared trust finalization remain required. The frozen binary
codec, context registry and disabled runtime are unchanged.

Implementation progress (2026-09-12, authority finalizer bindings): exact primary-audit
event/operation/reason/ref/actor checks, plan requester/proof equality and consumed-witness /
finalized-UserSecurity physical-set checks now precede finalization. Regression and three-driver
evidence is tracked in HR-2026-09-04-003. This does not complete exact planned subject values,
proof/approval source provenance or protected source DML. Shared Row04 trust finalization is
still closed. Continue those source/operation guards, cutover and repository/classification
integration before the workspace. The frozen contract and runtime-disabled status are unchanged.

Implementation progress (2026-09-11, foundation writer adapter): RecordVaultAuditEvent has
an explicitly injected guarded persistence adapter with exact read-back, owned transaction
handling and no failure fallback. Canonical legacy correlation UUIDs and the returned model
contract are preserved. Combined contracts passed 701 / 25,077; see HR-2026-09-04-003.
The port remains unbound pending complete cutover and guard verification. Row04 finalizers,
repositories/classification and integrated authorization remain before workspace work.

Implementation progress (2026-09-11, authority finalizer prerequisites): a demonstrated
SQLite nullable-source/context mismatch is corrected using null-safe identity and mandatory
marker checks. The primary audit and trust identity must match before consuming authority.
Three-driver partial-context and unchanged success tests passed 10 / 374; combined units,
authority and quorum contracts passed 671 / 20,932. These isolated authority fixtures do
not yet cover full source-specific authorization or shared trust UPDATE integration.
No frozen context family/stage or state-only authorization path changed. See HR-2026-09-04-003.

Implementation progress (2026-09-11, foundation finalization): foundation_direct now creates
and verifies its pending child, then performs the LAST guarded finalization in the same audit
INSERT. The existing one-statement insert context is consumed once; no new family/stage or
second update authority exists. Exact source/ref/state validation and coupled rollback are
tested on SQLite and both MariaDB driver names. Combined contracts passed 722 / 25,478.
The frozen backfill catalog now requires this UPDATE guard. Row04 source/operation and abort
finalizers still deny until implemented; full cutover/runtime binding remains incomplete.

Implementation progress (2026-09-11, evidence source binding): the shared INSERT guard now
enforces each building commit's exact source kind, source UUID and audit event family. The
three-driver regression was reproduced before the fix; final source/INSERT tests passed
32 / 3,115, plus actual migrated SQLite foundation integration 1 / 15. This remains pending
insertion, not source finalization. Frozen behavior below is unchanged; continuation is active.

Implementation progress (2026-09-11, shared foundation context): new foundation_direct inserts
use the same one-use context/sink and pending-child trigger. Closed content references and
origin rules preserve legacy identity without widening Row04. A demonstrated MariaDB
case-insensitive-column bug is corrected with byte-exact code predicates. A second demonstrated
bug in deferred audit visibility is corrected by following the actual proof-transition trigger
and exact source commit/UserSecurity execution, not a nonexistent audit event type. Full nested
view attestation and source dependency preflight are retained. Final combined contracts passed 737 / 25,082.
See HR-2026-09-04-003 for red/green evidence and fixture limits. Trust UPDATE/source finalizers,
the complete legacy writer adapter, final cutover and repository/classification work remain.
No runtime binding, operational migration or human-review readiness. Frozen product behavior
below is unchanged; the source-trigger correction implements its existing aggregate fence.

Implementation progress (2026-09-11, trust retention): the permanent trust DELETE guard is
required by the guarded capture/backfill catalog and backfilled read-back. All-state and
historical deletion, missing/changed guards and restart checks passed. Full units/three-driver
INSERT contracts passed 661 / 7,728; final backfill/core flows passed 7 / 174. The shared closed
foundation vocabulary fixes a five-case demonstrated prevalidation regression without changing
generated DDL/INSERT SQL. Full units plus foundation feature tests passed 656 / 20,358.
The DELETE rule is complete; UPDATE lifecycle/finalizers, foundation-direct adaptation and
the later cutover/repository/classification work remain. See HR-2026-09-04-003 for fixture
limits and evidence. Frozen behavior below is unchanged. No operational migration or activation.

Implementation progress (2026-09-11, guarded backfill checkpoint): the frozen-phase installer
attests every legacy/freeze/new INSERT trigger before typed capture/backfill. Exact missing
foundation children are inserted with one-use contexts and independently locked complete
set/digest/view read-back. The captured-to-backfilled CAS preserves the retained manifest,
requires complete trust, advances revision once, and rolls back with the owning transaction.
Focused SQLite/mysql/mariadb flow/catalog regression passed 12 / 168. The broader unit,
freeze/backfill/INSERT/context/core run passed 698 / 9,016. Final stage_advance-aligned
three-driver flows and the real migrated SQLite empty checkpoint passed 4 / 79. The ordinary
Dev legacy audit catalog also matched exactly in a read-only check. Original MariaDB source uses
SHOW CREATE because ACTION_STATEMENT loses backslash source bytes; no literal comparison
was weakened. Existing foundation UUID versions remain intact. Lifecycle finalizers/guards,
foundation-direct adaptation, permanent-guard transition/final cutover and the remaining
repositories/classification still precede Slice 04 completion. The frozen contract below
is unchanged. No operational migration, activation or practical human-review readiness.

Implementation progress (2026-09-11, temporary trust guard): the closed manifest-bound
foundation_existing INSERT branch and pending-child coexistence are verified on SQLite and
both MariaDB driver names. Installing the permanent guard closes backfill while the temporary
guard remains. SQLite inactive branch functions return NULL/false without installing authority;
active contexts and opaque digest boundaries are preserved. Final combined units, audit/
backfill/context contracts and real SQLite core assembly passed 654 / 8,247. This implements
SQL guard definitions, not the coordinated installer or freshly locked application writer.
Those, trust lifecycle finalizers, foundation-direct adaptation, stage advancement and the
remaining repositories/classification still precede completion. The frozen contract below is
unchanged; no operational migration, runtime activation or practical-review readiness.

Implementation progress (2026-09-11, atomic insertion): internal AFTER INSERT and permanent
coupled-only trust guard definitions now atomically create the matching pending projection.
Shared application/SQL operation, reason, executor and reference shapes reject forged matching
contexts; all five pending typed owners and canonical actor identities are verified. The
final combined unit/integration run passed 645 / 7,494 across SQLite/mysql/mariadb, plus real
SQLite migration/core compilation at 1 / 66. Exact child read-back is used instead of an
invalid nested-trigger ROW_COUNT assumption. No installer or runtime binding was added.
Temporary backfill guards/context, trust lifecycle finalizers, foundation-direct adaptation,
exact coordinated catalog/cutover/stage advancement and remaining repositories/classification
still precede Slice 04 completion. This progress note does not change the frozen contract
below or authorize operational migration, runtime activation or practical human review.

Implementation progress (2026-09-11, continuation): captured-checkpoint read-back now
reconciles the full foundation trust projection and exact trusted-view visibility. Missing
children remain explicit ordered work; conflicting children deny rather than being silently
replaced or finalized. The MariaDB non-updatable TEMPTABLE view stays unchanged: an old
consistent-read snapshot must reject and roll back, with resume in a fresh owned transaction.
Combined unit/integration passed 661 / 7,296; expanded mysql/mariadb snapshot cases passed
2 / 94. This is verification/planning of backfill, not a trust writer or stage-advance gate.
Audit AFTER INSERT and temporary trust guards must precede actual backfill DML. The exact
full cutover contract below still governs; no runtime or operational migration is enabled.


Implementation progress (2026-09-11): guarded manifest persistence now creates/resumes the
configured root/header, inserts exact ordered immutable foundation items through pinned
one-use contexts, and finalizes only after complete independent count/digest read-back.
Exact catalog verification covers complete trigger sets and the complete trusted-audit view,
including literal case/spacing, binary equality, joins and nested owner predicates. Historic
MariaDB DATETIME(0) audit instants normalize to DATETIME(6), preserving existing UTC time.
No operational entry point, trust backfill, permanent guard cutover, classification or
authorization binding is implied. Final test results and remaining failures are recorded in
HR-2026-09-04-003; runtime stays disabled and practical human review is not yet available.

Implementation clarification (2026-09-05): the reviewed `primary/FK` singleton and cutover-header
language requires the non-secret installation root defined below. This closes an FK ordering gap
without adding runtime state or changing product behavior; independent rereview remains required.

Verification (2026-09-10): the new 010170 contract passed SQLite and both Laravel MariaDB driver
names (3 tests / 209 assertions, 153.74 seconds). The existing full post-review/recovery MariaDB
contracts plus post-review gate guards passed 10 tests / 93 assertions. The broader SQLite run
including full migration and all-core assembly checks passed 19 tests / 4,872 assertions; six
opt-in MariaDB cases were skipped in that run. Pint, explicit migration syntax, 0644 file mode,
and git diff --check passed. Full Laravel and final Slice 04/security review remain outstanding;
these focused checks neither activate Vault nor complete the human-review gate.

Implementation progress (2026-09-10): migration 010170 creates the recovery-authorization base
and the two post-review origin tables using the existing frozen schema definitions. These are the
cycle-free predecessors of later quorum transitions, execution witnesses, and review obligations.
The two components expose explicit base-stage ensure/assert methods; their complete-component
assertions continue to reject this incomplete stage. Pretend issues DDL only, exact partial
installation resumes, and partial/divergent/retained-state rollback is refused before any drop.
The migration is atomic on SQLite and per-object restartable on MariaDB, without implicit DDL
transaction claims. It inserts no recovery, origin, singleton, permission, or runtime state.
The broader default-deny guard/repository/cutover/classification work remains unfinished.
Migration 010170 is confirmed Pending on ordinary Dev. This is not authorization to migrate,
activate, or begin the final human review.

Verification (2026-09-09, current proof-index correction): the focused final run of
VaultStepUpCoreSchemaTest, VaultStepUpBaseMigrationContractTest,
VaultActionPlanMigrationContractTest, and VaultRow04CoreAssemblyTest passed 20 tests / 1,987
assertions in 618.19 seconds. MariaDB tests were explicitly enabled on a socket-only disposable
server and exercised both Laravel mysql and mariadb driver names. The integrated SQLite test
also separately passed 62 assertions, including the absence of mandatory future-row FK cycles.
Pint passed all ten affected PHP files, explicit syntax checks passed, and git diff --check was clean.
The full Laravel suite and final Slice 04 security/runtime verification were not run or claimed
complete in this increment. Migrations 010140, 010150, and 010160 remain Pending on actual Dev.

Integrated catalog regression (2026-09-09): a new SQLite assembly contract creates every current
Row04 Vault core manifest against the real application migrations, without future Vault dependency
stubs, and then runs each exact component assertion and `PRAGMA foreign_key_check`. It exposed
missing UNIQUE(installation_id,id) parent keys on standard and enrollment proofs: quorum mutation
FKs referenced those exact pairs, but the isolated component fixtures had hidden the mismatch.
Both proof manifests now declare and verify those parent keys on SQLite and MariaDB; the two
affected frozen SQL fingerprints are updated. Migration 010140 was confirmed Pending on Dev before
the manifest correction. Divergent pre-existing catalogs are still refused, never auto-repaired.
This integrated test checks schema compatibility only, not MariaDB installation ordering, guards,
audit cutover, runtime enforcement, or human approval.

Implementation progress (2026-09-09, plans and approvals): 010150 installs the eight action-plan,
graph-subject, authority-plan/subject, and quorum-plan/subject tables. 010160 installs the eight
approval request/lifecycle/evidence tables. Both stages use the existing exact schema manifests,
support pretend without querying absent dependencies, and reject rollback before dropping any table
when retained state or catalog divergence exists. Their step-up DDL dependency is the five-table
base, not future authority/quorum-dependent proof transitions; full step-up readiness still denies
until those transitions exist. The isolated contract exercises every completed-table prefix on
SQLite and opt-in MariaDB under both Laravel mysql and mariadb driver names. No future Vault table
is stubbed in that contract. The complete SQLite migration chain is also exercised.
These stages create no permission rows, authority baselines, proofs, plans, approvals, or bindings.
Remaining work includes the coupled execution/authority/quorum/post-review tables, coordinated
guards and repositories, audit-trust cutover, classification, and the final Slice 04 verification.
This is implementation progress, not completion of Slice 04 or the whole Vault domain.

Implementation progress (2026-09-09): migration 010140 installs the five step-up base tables
(standard proofs, enrollment proofs, attempt evidence, rate failures, and rate anchors) using
the existing exact schema definitions. Proof transitions are deliberately a later stage because
they reference authority mutations, witnesses, and quorum mutations. The complete component
assertion still rejects the base-only stage. Migration 010140 is DDL-only; it creates no proofs,
permissions, singleton data, or runtime bindings. Local isolated tests exercise each completed-table
prefix, exact restart, divergent catalog rejection, FK rejection, empty rollback, and retained-state
rollback refusal. Actual Dev migration and human review remain pending.

## Goal

Build the dormant, default-deny authorization control plane that later Vault credential workflows
must call. This slice owns flat Vault access groups and collections, allow-only grants, fresh
password-plus-TOTP step-up, action-plan-bound approval, the sole-active-admin exception, typed audit,
and concurrency-safe authorization graph mutations.

Vault remains disabled. This slice creates no secret writer, key provisioner, reveal, copy, use,
runtime consumer, route, API, MCP, UI, portal surface, legacy migration, or real credential.

## Observed Current Boundary

The completed foundation exposes four control-plane permissions, guarded metadata/cryptographic
schema, safe audit, and a non-ready health command. It has no content permission or consumer.

Current code inspection establishes these integration facts:

- UserManagement owns users, exact persisted `PENDING_INVITE|ACTIVE|DISABLED` state, password hashes, confirmed TOTP enrollment,
  sessions, and general Spatie roles/permissions.
- The existing User::verifyTwoFactorCode helper accepts a wide TOTP window and is forbidden for
  Vault step-up.
- Internal Client visibility currently means a persisted Client plus the direct client.view
  permission. The Client list is global for holders of that permission; active_client_id is only a
  session filter, not authority.
- EnforceTechRoutePermission contains Superuser and empty-Admin fallbacks. Vault must not call or
  reproduce those fallbacks.
- UserManagement mutations that can alter the approver pool currently include SyncUserRoles,
  RolePermissions, role/permission management actions, admin and API user updates, and
  UpdateUserStatus. These paths need one cross-domain guard before Slice 04 is safe.
- Laravel's configured Eloquent provider has automatic hash-policy rehash enabled, but the current
  Fortify `authenticateUsing` callback validates the password itself and therefore bypasses the
  provider's automatic rehash. Other SessionGuard/provider entry points may still call
  `rehashPasswordIfRequired()`. Both paths must converge on the app-owned UserManagement boundary
  below; no framework `forceFill(password)->save()` may remain as an unguarded hidden write.

This is proposed design until the slice is approved and implemented. No statement below claims
that the new tables or services already exist.

## Domain Ownership

| Owner | Owns | Does not own |
| --- | --- | --- |
| UserManagement | User identity, human/system classification, status, password and confirmed TOTP data, recovery state, Laravel sessions, general roles/permissions, authentication-security epoch, and TOTP replay counter | Vault groups, memberships, collections, grants, approval plans, or Vault decisions |
| Vault | Vault access groups and memberships, collections and item links, allow grants/revocations, scope authorization epochs, step-up proof metadata, approval/exception/consumption evidence, and Vault decisions | Password/TOTP secrets, general user groups, user profiles, or source-domain records |
| Client | Client record existence and the current client.view visibility rule exposed through a typed adapter | Vault grants or content decisions |

A Vault access group is not a second user directory. External/general group adapters require a later
ADR and cannot be inferred or synchronized here.

## Security Invariants

- Every decision is deny unless every required actor, permission, Client visibility, scope, state,
  epoch, digest, step-up, approval, and audit precondition explicitly passes.
- Missing adapters/records, unknown operations, malformed configuration, stale evidence, inactive
  actors, cross-scope substitution, concurrency drift, and unsupported future state deny safely.
- No Admin/Superuser label, route middleware fallback, creator identity, collection/group
  membership, or control-plane permission grants Vault content by itself.
- Grants are allow-only and exact. There is no deny entry, wildcard, nesting, implicit inheritance,
  creator grant, or remove-then-readd resurrection.
- Collections and Vault access groups are flat. Items and users may participate in several direct
  relationships, but no group/collection may contain another group/collection.
- Scope is immutable. Active-to-disabled and active-to-revoked transitions are terminal; restore,
  ordinary update, hard delete, and evidence deletion are rejected by both application and DB.
- All graph mutations need fresh step-up and typed audit. Access expansion follows the approval
  matrix below. Ordinary graph access reduction never waits for independent approval; candidate-
  roster shrink and quorum recovery follow their stricter authority rules below.
- Authorization is re-evaluated from current rows. Slice 04 adds no cross-request decision cache.
- Password/TOTP secrets, presented factors, raw Laravel session IDs, credential/value-derived hashes,
  offline verifiers, exception text, and free-form hash/context blobs are forbidden from models,
  durable evidence, audit, queues, events, cache, logs, URLs, or output. Only the explicitly named
  canonical BINARY(32) name/session/authorization digests may exist in their typed columns; they
  never appear in audit, logs, URLs, or output.
- The cryptographic runtime switches remain false and cannot be inferred from authorization health.

## Dormant, Provisioning, And Enforced Phases

Vault authority phase is a closed installation state: dormant, provisioning, or enforced. This
slice migrates only to dormant and exposes service contracts; it has no action, route, command, UI,
API, seeder, or automatic path that can transition phase.

- dormant keeps both Vault runtime switches false. UserManagement authentication-security epoch
  tracking and canonical Admin/Superuser/Vault-permission identity protection apply, but existing
  UserManagement mutations do not require a Vault proof/approval/witness that users cannot acquire.
- provisioning is reserved for a later activation slice to establish governance readiness and add
  a UserManagement-owned stateful step-up challenge. It still denies every Vault content operation.
- enforced makes the central authority guard and one-use witness mandatory for the governance-
  changing paths defined here. Entry requires complete schema readiness plus separate governance and
  actor readiness in one reviewed transition; it is impossible in Slice 04.

Schema readiness proves tables/guards only. Governance readiness proves the required protected human
roster and factors. Actor readiness proves a particular stateful Laravel-session actor has the
required permission/factor/proof. None implies either of the others.

The final DML-only classification migration inserts the installation authority row with
control_phase dormant only after every additive schema object and permanent guard has been verified.
Its immutable-phase trigger rejects every later change of the control_phase value and every
production attempt to insert provisioning or enforced. In both dormant and provisioning, services
and database guards reject before factor verification every INSERT, activation, transition, rate
failure, attempt, evidence commit, or typed evidence source for a standard `step_up` or
`totp_enrollment` Vault proof. They likewise reject every approval, graph, authority, quorum, grant,
or content-runtime row. TOTP replay counters are not touched and readiness requires zero Vault
proof, attempt, rate, approval, graph, authority, quorum, grant, and content-runtime rows before
enforced. Raw DML therefore cannot preseed authorization that would become effective after later
activation.

The exhaustive narrow pre-enforcement allowlist is UserManagement's exact password, login-
identifier, invite, recovery, session, pending-TOTP generation/confirmation, bootstrap, system-
actor, and provision-boundary executions; the deployment journal/classification and audit-trust
cutover; and only their exact UserSecurity, migration evidence, and typed audit sources. None can
create or transition a Vault proof. The first TOTP confirmation before enforced uses the
UserManagement verifier, replay counter, persisted generation, and one UserSecurity execution
directly; enforced enrollment instead uses the dedicated enrollment-proof/exception contract.
Unknown phase/source/kind combinations deny. The sole post-migration dormant authority-state write is the one-time existing-install
provisioning-evidence pointer CAS under VaultExistingInstallProvisioningContext; it changes no
phase, epoch, roster, authority digest, quorum or runtime fact and grants no authority. Positive
DB-contract tests use only a test-owned, non-production-autoloaded isolated
schema assembler. In a fresh ephemeral database it inserts enforced before installing the same
immutable-phase trigger and the same generic operational-trigger implementation. The isolated test
registry alone adds `test.vault.synthetic_read` so graph approval-consumption success can be
exercised; production registry equality is not claimed for that fixture, and production route,
service, registry and migration tests prove the synthetic value is absent and rejected. The
enforced authority row may then change only its non-phase fields through the documented operational
fences; control_phase remains enforced. The assembler exposes no transition of a production-
migrated schema, service, configuration value, route, command or seeder. Both drivers therefore
prove the positive trigger protocol without shipping an activation path, while production-schema
tests separately prove dormant service/raw-DML denial and continued UserManagement epoch tracking.

## Client Visibility Adapter

Slice 04 names Contracts/VaultScopeVisibility and
Adapters/Clients/CurrentClientVisibilityAdapter as the only Client boundary used by the central
decision and mutation services.

- Company scope is canonical scope_type=company, scope_id=0, client_id=NULL. It requires the mapped
  Vault management permission but no Client lookup.
- Client scope is canonical scope_type=client with scope_id=client_id>0. The adapter requires an
  existing exact clients.id and a direct Gate/Spatie client.view result for the current actor.
- Current Client code has no narrower per-technician Client ACL. Consequently client.view currently
  covers existing active and inactive Client records. Contract/customer lifecycle rules remain
  owned by their domains and are not invented here.
- Missing adapter, missing Client, mismatched IDs, or denied client.view returns a typed denial.
- The adapter never reads route middleware, active_client_id, an Admin shortcut, or a Superuser
  fallback. If Client later gains an authoritative per-record scope service, replacing this adapter
  requires reviewed tests and cannot retain the old fallback.

## Permissions And Closed Operations

The four existing control-plane permissions and grants remain intact. Slice 04 adds two management
permissions through an additive, migration-managed deployment:

| Permission | Purpose | Default role grant |
| --- | --- | --- |
| vault.health_view | Foundation health | Admin, Superuser; unchanged |
| vault.audit_view | Safe audit inspection | Admin, Superuser; unchanged |
| vault.policy_manage | Vault settings and policy only | Admin, Superuser; unchanged |
| vault.key_provider_manage | Key-provider control plane | Superuser only; unchanged |
| vault.grant_manage | Vault access-group, membership, collection, item-link, and grant graph mutations | Admin, Superuser |
| vault.approval_decide | Independent approval decisions and post-review evidence | Admin, Superuser |

Tech and Viewer receive no Vault permission. Seeder reruns must not regrant an explicitly removed
migration-managed permission. No discover, metadata-view, reveal, copy, use, export, import,
destroy, recovery, or break-glass permission is created.

VaultControlOperation is a closed Slice 04 enum with exactly:

- access_group.create, access_group.rename, access_group.disable;
- access_group_member.add, access_group_member.revoke;
- collection.create, collection.rename, collection.disable;
- collection_item.add, collection_item.revoke;
- grant.create, grant.revoke;
- approval.approve, approval.reject, approval.cancel;
- sole_exception.create and post_review.record;
- authority.user_role_add, authority.user_role_remove, authority.role_permission_add,
  authority.role_permission_remove, authority.direct_permission_remove,
  authority.user_activate, authority.user_deactivate, authority.user_classification_change,
  authority.emergency_deactivate,
  authority.totp_confirm, authority.totp_reset, and authority.totp_disable; and
- quorum.peer_unlock and quorum.cooling_recovery.

Unknown values and later content operations cannot be parsed, persisted, planned, approved, or
treated as effective in Slice 04. Management mutations use their mapped global permission and do
not require a pre-existing content grant. The production content-operation registry remains empty;
the vault_grants relation and approval planner are dormant until a later additive slice introduces
the first exact content operation and matching default-deny global permission. That later migration
must preserve the rule that every grant.create plan is approval-bound. It may not inject a test or
reserved production operation.

## Mutation And Approval Matrix

| Operation | Permission | Fresh step-up | Independent approval |
| --- | --- | --- | --- |
| Create a Vault access group, or rename an active group whether empty or nonempty | vault.grant_manage | Required | No |
| Disable a group | vault.grant_manage | Required | No; access-reducing |
| Add a group member | vault.grant_manage | Required | Only when locked post-mutation reachability expands |
| Revoke a group member | vault.grant_manage | Required | No; access-reducing |
| Create a collection, or rename an active collection whether empty or nonempty | vault.grant_manage | Required | No |
| Disable a collection | vault.grant_manage | Required | No; access-reducing |
| Add an item to a collection | vault.grant_manage | Required | Only when locked post-mutation reachability expands |
| Revoke an item link | vault.grant_manage | Required | No; access-reducing |
| Create an allow grant after a later operation-enablement migration | vault.grant_manage | Required | Always |
| Revoke an allow grant | vault.grant_manage | Required | No; access-reducing |
| Approve or reject another request | vault.approval_decide | Required | No second approval |
| Cancel own pending or approved-but-unconsumed request | Requester plus current mapped management permission | Required | No |
| Create sole-admin exception | vault.approval_decide and the request permission | Required | Exact exception contract |
| Record post-review evidence | vault.approval_decide | Required | No |
| Apply a planned Vault-authority expansion | Existing exact UserManagement permission plus vault.policy_manage | Required | Different pre-state governance-recovery-eligible candidate who is decision-ready for the exact plan when roster has another candidate; otherwise exact sole exception |
| Apply a planned Vault-authority reduction that does not shrink the candidate roster | Existing exact UserManagement permission plus vault.policy_manage | Required | No |
| Apply a planned candidate-roster shrink from more than one | Existing exact UserManagement permission plus vault.policy_manage | Required | Different governance-recovery-eligible pre-state candidate who is decision-ready for the exact plan; never the future sole identity |
| Emergency security deactivation | Direct current user.update | Required | No, but exact emergency plan and quorum lock are mandatory |
| Unlock an emergency quorum lock | vault.policy_manage and vault.approval_decide | Required | Different governance-recovery-eligible pre-change candidate who is decision-ready for the exact plan |
| Run no-alternative quorum recovery after cooling | vault.policy_manage and vault.approval_decide | Required | Exact short-lived recovery exception; never a grant |

Name edits never change identity or scope and use a narrow audited rename action. Every reducing
mutation still requires an explicit closed reason and audit in the same transaction.

Reachability expansion is computed inside the mutation transaction from both the locked before
graph and the proposed after graph. A membership/item-link addition needs approval only if it adds
at least one exact subject-target-operation path that was not reachable before. Absence of a
currently enabled content operation means it cannot expand production content reachability in this
slice.

## Concurrency And Authorization Epochs

Each scope has one vault_authorization_scopes anchor with monotonically increasing auth_epoch plus
group_set_digest, collection_set_digest, membership_set_digest, item_link_set_digest, and
grant_set_digest. Each group and collection also has its own monotonically increasing auth_epoch
and member/item set digest.

Every graph mutation locks in deterministic order:

1. canonical scope anchor;
2. affected access-group IDs in byte order;
3. affected collection IDs in byte order;
4. affected item IDs in byte order;
5. affected grant IDs in byte order;
6. approval request/evidence; and
7. actor and approver-pool identities.

The before and proposed-after reachability sets are computed under those locks. The action plan
binds scope/group/collection epochs and all relevant set digests. Immediately before mutation the
same rows are re-read and the digest is recomputed. Any drift invalidates the request; there is no
retry under the old approval. MariaDB uses locking reads and a unique active slot; SQLite uses an
IMMEDIATE write transaction and equivalent triggers. Deadlock retries rebuild a new locked plan,
never reuse an already consumed decision.

Every graph, authority, and quorum change has an immutable typed pre-apply plan. An approval request
references one immutable action_plan_id FK. The base action plan has exactly one 1:1 graph,
authority, or quorum extension selected by its closed plan_type; triggers reject zero, multiple, or
mismatched extensions. Plans enumerate canonical subject rows, including every before/after value;
IDs never exist only inside a digest. The applied mutation record references the same plan and
cannot be created without all expected subjects and the correct requester/executor step-up and
approval/exception evidence.

The plan-to-application mapping is exhaustive:

- access_group.*, access_group_member.*, collection.*, collection_item.*, and grant.* use
  plan_type=graph, one graph extension, one vault_graph_mutation, and graph audit;
- authority.* including authority.emergency_deactivate use plan_type=authority, one authority
  extension, one vault_authority_mutation, typed authority witnesses for protected
  UserManagement/Spatie writes, and authority audit. Emergency quorum lock is a typed side effect in
  the authority plan/mutation, not a quorum-plan substitution;
- quorum.peer_unlock uses plan_type=quorum, one quorum extension and one vault_quorum_mutation, with
  approval consumption but no UserManagement/Spatie witness; and
- quorum.cooling_recovery uses plan_type=quorum, one quorum extension, one vault_quorum_mutation and
  its recovery extension. It uses the recovery authorization and creates a witness only when exact
  UserManagement/Spatie subjects must change.

Both driver guards reject every other operation/plan/extension/mutation/witness/audit combination.

Every graph, authority, emergency, and quorum applied record has exactly one primary success audit.
The typed mutation is precreated pending with primary_audit_event_id NULL. The service then
preallocates/inserts one audit row whose typed FK points to that already-existing mutation. The
operation finalizer verifies the exact event type/outcome/reason/plan/proof/subjects, changes the
mutation to applied, and sets primary_audit_event_id to the existing event in the same guarded UPDATE.
Applied requires a non-null UNIQUE primary audit FK; pending requires NULL. The reciprocal
audit-to-mutation and applied-mutation-to-audit checks are therefore immediate-FK safe. A second
success audit, an applied mutation without audit, wrong-family audit, ordinary mutation update, or
orphan pending record can never become trusted evidence.

An authority.emergency_deactivate plan must enumerate one authority-user-status subject and one
authority-state subject. Its semantic state transition is one of two closed variants: initial
open/no-lock/no-fence to locked/new-preallocated-lock/no-fence, or continued locked/no-fence to
locked/no-fence with the exact same lock UUID, original cooling_started_at, root incident reason,
and recovery_not_before. The continued variant may change only the guarded authority epoch and
candidate/governance/global-facts digests; it can neither restart, shorten, nor extend the 24-hour
cooling interval. Both variants bind all before/after values and must retain at least one candidate.
The preallocated authority witness separately enumerates the two intermediate authority-state writes
that install and clear its pending-witness fence. The applied authority mutation references the
semantic state subject and both consumed witness subjects. Both drivers reject an emergency plan,
state update, applied mutation, or audit event missing any record, carrying the wrong transition
variant/lock/cooling/reason/digest/epoch, or producing zero candidates.

The initial open-to-locked transition has no future-row FK cycle. After locking state/subjects and
creating the plan, the service precreates the pending authority mutation, subjects and witness, then
preallocates the audit event UUIDv7 and inserts the immutable typed emergency audit row referencing
those already-existing records plus the exact before/after state and digests; the audit INSERT
trigger atomically creates its pending audit-trust projection from the exact insert context. It
installs connection
execution context containing that event UUID, applies the guarded UserManagement/Spatie writes, and
updates authority state with root_incident_event_id referencing the preinserted audit row. The state
trigger verifies the exact emergency event family, plan, mutation, witness, context and before/after
facts while it consumes/finalizes the fence. The audit is transaction-invisible until commit and its
canonical DB time is the transaction event time; any failure rolls audit, witness, mutation, writes
and state back together. A continued locked-to-locked emergency preserves the original root event FK
and appends a separate ordinary emergency audit event. Both-driver tests reject missing/wrong event,
wrong order, partial commit, replay and concurrent root-event substitution.

Every Vault-owned graph write also crosses a one-use execution fence; an application service is not
itself trusted merely because it has built a valid plan. Before planning a create, the application
preallocates every affected row ID as a canonical lowercase UUIDv7 with `Str::uuid7()`. Query-builder
paths generate and validate UUIDv7 explicitly; they never rely on Eloquent `HasUuids`. The transaction
then inserts the immutable action plan, graph extension, and ordered graph-plan subjects before it
creates one short-lived `vault_graph_write_execution`, mandatory scope-claim and scope-finalize
subjects, and its exact target execution subjects. A target subject contains the preallocated target
UUID as typed data but deliberately has no FK to the graph row that does not exist yet, avoiding an
insert-order cycle.

The graph execution similarly stores validated scope_key/type/id but deliberately has no FK to a
future first-use scope anchor. It is inserted first. A first-use anchor INSERT then references that
already-existing pending execution and carries the canonical empty epoch/digests with the execution
claim installed; its trigger derives and verifies exact scope/Client identity. Existing anchors are
locked and claimed by UPDATE. There is never a reciprocal execution-to-anchor FK, so both orders are
immediate-FK safe. Once inserted, an anchor is retained forever even when its Client becomes
inactive: every DELETE, restore, identity change, or delete-and-reinsert attempt is rejected
unconditionally so an old plan can never pass through anchor ABA recreation.

`VaultGraphExecutionContext` exposes execution UUID, exactly one subject UUID, and closed stage
claim|target|finalize to one database connection for one guarded statement. MariaDB uses reserved
session variables cleared on connection checkout/return; SQLite uses registered zero-argument
functions and an IMMEDIATE write transaction. Under locked scope and Client rows, claim changes an
existing anchor only from (old epoch/digests,pending NULL) to the same epoch/digests with
pending_graph_execution_id=execution UUID and consumes the exact claim subject. First use inserts the
canonical empty anchor already claimed to that execution. Epoch exhaustion denies before claim.

Every target INSERT/UPDATE trigger requires that same pending anchor plus an unexpired active
execution matching plan, actor, fresh proof, authorization evidence, target kind/UUID, mutation kind,
scope and exact OLD/NEW values. The sole expiry exception is an exact post-review-origin owner resume
after that coordinator reached effects_started: the trigger must also match the coordinator UUID,
immutable authorization-snapshot digest, consumed pre-state witness, operation and plan IDs, and
current pending scope/gate fences. That exception finishes only already-precommitted subjects for that
origin; it cannot create a decision, plan, proof, or different operation. It atomically consumes that
target subject or aborts. After all target
subjects are consumed, the service computes the exact post-state digests, preinserts the pending
graph mutation, and then inserts its typed audit whose trigger atomically creates the pending
audit-trust projection referencing that mutation. Finalize changes the
anchor exactly once from
(old epoch/digests,pending execution UUID) to (old epoch+1,new digests,pending NULL), consumes the
finalize subject, finalizes the execution, performs the mutation pending-to-applied/primary-audit
binding, and marks that audit trust finalized LAST in the same transaction. Its trigger requires the
exact plan/proof/mutation/audit/trust row and complete subject set. There is
no separate anchor apply stage. The service clears context in
`finally`; affected-row count other than one aborts.

Every graph row stores the consumed execution-subject FK that created it and a last-execution-subject
FK. Create sets both to the same subject; the only permitted rename, disable, add, or terminal revoke
transition replaces only the last reference with the newly consumed subject. The mutation row points
back to the execution and exact subject/target rows, so provenance is bidirectional without making a
future-row FK cycle. A raw write, replay, wrong subject, reused connection context, partial execution,
or commit with an uncleared fence cannot become effective authorization. Transaction rollback restores
the row, subject, mutation, audit, counters, and fence together.

Approval-bound plans and their request use one captured DB time and byte-equal expiry:
`VAULT_APPROVAL_TTL_MINUTES` defaults to 30, accepts only integer 5..60, and expiry is that DB time
plus the configured minutes. `VAULT_IMMEDIATE_PLAN_TTL_MINUTES` defaults to 5, accepts only integer
1..10, and an immediate no-approval plan expires at its captured DB creation time plus that value.
`VAULT_OPERATION_FENCE_TTL_SECONDS` defaults to 30 and accepts only integer 1..60. A graph
execution or authority witness stores `expires_at = min(DB_UTC_NOW + fence_ttl,
bound_plan.expires_at)` and creation rejects when the result is not later than the same captured DB
time. It can therefore never outlive its plan and no implementation may choose rejection instead of
the defined minimum merely because the plan has less than fence_ttl remaining. Expiry denies every
new or unrelated use. Once a post-review origin is
effects_started, however, its exact snapshot-bound graph execution, authority witness, quorum
mutation and owned building evidence commits are resume-only past their original TTL until the
already-started composite finishes; no expiry extension is written and no other action may rely on
that exception. Invalid configuration denies creation.

Lifecycle time is otherwise exact and exhaustive. `VAULT_EVIDENCE_BUILD_TTL_SECONDS` defaults to
300 and accepts only integer 60..900; an ordinary building evidence commit stores
`expires_at = DB_UTC_NOW + ttl`. `VAULT_USER_SECURITY_EXECUTION_TTL_SECONDS` has the same
default/range; its `authorized_at` is exact DB time and `expires_at = authorized_at + ttl`.
UserSecurity-bound evidence commits inherit that exact execution expiry. Origin-bound evidence
commits inherit the exact post-review-origin expiry, which is byte-equal to the bound action-plan/
approval-request expiry, must be later than DB time at creation, and has no separate configuration.
Migration/cutover sources use one fixed 900-second one-use context and must finalize in their same
locked DML unit; crash/re-entry follows only their closed migration protocol.

A quorum mutation and every quorum-bound execution/witness expiry are byte-equal to the bound plan
expiry; there is no separate quorum TTL. A TOTP-enrollment proof is fixed at exactly 300 seconds:
`issued_at` is DB time and `expires_at = issued_at + 300 seconds`. A sole-admin exception,
TOTP-enrollment exception, or quorum-recovery authorization expires at the earliest of DB time plus
300 seconds and every bound request, plan, or step-up-proof expiry that is present; creation rejects
when that minimum is not later than DB time. Every validity test is `DB_UTC_NOW < expires_at`, so
equality is expired. All additions use exact UTC `DATETIME(6)` arithmetic and fail closed on date
overflow. Missing, boolean, float, malformed, or out-of-range configuration denies readiness and
creation; values are never silently clamped. Before effects start, expiry permits only the exact
cleanup/abort path. After effects start, only the already documented exact owner-resume snapshot may
ignore TTL, and it cannot authorize a new side effect or scope.

## Canonical Binary And Digest Contract

VaultCanonicalBinaryV1 is the sole encoder for every stored digest. A document is ASCII
NEXUM-VAULT, byte 00, exact ASCII family tag, byte 00, then fields. Each field is one type byte,
unsigned four-byte big-endian payload length, then payload. Type bytes are NULL=00, BYTES=01,
UTF8=02, UINT64=03, BOOL=04, UTC_INSTANT=05, and UUID=06. NULL has length zero; every other type has
its exact fixed/declared length. UINT64 is eight-byte unsigned big-endian; BOOL is one byte 00 or 01;
UTC_INSTANT is signed eight-byte big-endian Unix microseconds; UUID is raw 16 bytes decoded only from
canonical lowercase RFC-4122 text. Nullable fields use NULL, never an empty-value sentinel.

Full UINT64 is encoded from a validated nonnegative PHP int or canonical decimal string through
pure-PHP high/low-u32 arithmetic. It is never cast through float or a signed PHP int. Normative tests
include 0, 2^32, PHP_INT_MAX, 2^63, and 18446744073709551615. UTC input is an exact UTC instant;
local-time strings, leap-second aliases, floats, and precision truncation reject.

Every Slice 04 instant that is persisted, bound into a plan/digest, used for security freshness,
expiry or cooling, or copied into audit uses canonical UTC `YYYY-MM-DD HH:MM:SS.ffffff`. MariaDB
columns are created with Laravel `dateTime(name, 6)` and read back as `DATETIME(6)`; SQLite columns
are declared DATETIME and must have TEXT storage class
with the exact 26-byte ASCII shape and calendar-valid UTC value. The shared database-clock service
uses `UTC_TIMESTAMP(6)` on MariaDB and SQLite's DB clock at its real millisecond precision padded
with three trailing zero digits; padding is representation, never fabricated clock precision.
Application bindings explicitly use UTC `Y-m-d H:i:s.u`; Laravel's default date binding and SQLite
`CURRENT_TIMESTAMP` are forbidden because they lose fractional precision. Inputs are parsed and
normalized to exactly six fractional digits with integer
arithmetic, never float, rounding, or truncation. UTC_INSTANT parses that canonical value to exact
signed Unix microseconds. Both drivers must round-trip the canonical text and recompute identical
bytes before a plan can be decided, consumed, invalidated, expired, or treated as stale.

The v1 family names and field order below are normative; implementations may not use associative
array iteration or database column order:

- nexum.vault.name-key.v1: 1 display-name UTF8;
- nexum.vault.session-binding.v1: 1 installation UUID, 2 actor UINT64, 3 raw Laravel-session BYTES;
- nexum.vault.foundation-audit-trust-manifest.v1: 1 installation UUID, 2 cutover UUID,
  3 manifest-item-count UINT64, then one complete manifest-item BYTES field in ascending base numeric
  audit ID order;
- nexum.vault.set.v1: 1 set-kind UTF8, 2 installation UUID, 3 scope-type UTF8, 4 scope-id UINT64,
  5 nullable client-id UINT64, 6 epoch UINT64, 7 entry-count UINT64, then each complete entry as
  one BYTES field;
- nexum.vault.candidate-roster.v1: 1 installation UUID, 2 authority-epoch UINT64, 3 candidate-count
  UINT64, then one BYTES field per complete user-ID/protected-role-ID entry;
- nexum.vault.governance-ready-roster-set.v1: 1 roster-kind UTF8 fixed governance-ready (the
  retained schema token for the governance-recovery-eligible roster),
  2 installation UUID, 3 authority-epoch UINT64, 4 entry-count UINT64, then one BYTES field per
  complete governance-recovery-eligible candidate entry;
- nexum.vault.global-authority-facts.v1: 1 installation UUID, 2 authority-epoch, 3 candidate-roster
  digest, 4 governance-ready-roster digest, 5 entry-count, then one BYTES field per complete
  candidate user/Vault-authority epoch/protected-role/effective policy+approval
  permission/confirmed-TOTP entry;
- nexum.vault.scope-authority-facts.v1: 1 installation UUID, 2 scope-type, 3 scope-id, 4 nullable
  client-id, 5 authority-epoch, 6 global-authority-facts digest, 7 entry-count, then one BYTES field
  per complete candidate global-facts/scope-visibility entry;
- nexum.vault.post-review-blocker-set.v1: 1 set-kind UTF8 fixed post-review-blockers,
  2 installation UUID, 3 gate-epoch UINT64, 4 entry-count UINT64, then one BYTES field per complete
  open-obligation blocker entry;
- nexum.vault.quorum-authority-facts.v1: 1 installation UUID, 2 quorum-plan UUID, 3 before global-
  authority-facts digest, 4 current global-authority-facts digest, and 5 proposed global-authority-
  facts digest;
- nexum.vault.graph-plan.v1: 1 installation UUID, 2 plan UUID, 3 requester UINT64, 4 session digest,
  5 requester auth epoch, 6 proof-kind UTF8 fixed step_up, 7 requester proof UUID, 8 operation,
  9 scope-type, 10 scope-id, 11 nullable client-id, 12 ordered subject-set digest, 13 nullable
  proposed display name, 14 nullable name key, 15 typed reason, 16 before reachability digest,
  17 after reachability digest, 18 scope epoch, 19 group epoch/digest set, 20 collection epoch/digest
  set, 21 candidate-roster digest, 22 global-authority-facts digest, 23 scope-authority-facts digest,
  24 post-review-gate epoch, 25 post-review blocker-set digest, 26 requested UTC instant, and
  27 expiry UTC instant;
- nexum.vault.authority-plan.v1: 1 installation UUID, 2 plan UUID, 3 requester UINT64, 4 session
  digest, 5 requester auth epoch, 6 proof-kind UTF8 step_up|totp_enrollment,
  7 requester proof UUID,
  8 operation, 9 ordered subject-set digest, 10 before roster digest, 11 after roster digest,
  12 before global-authority-facts digest, 13 after global-authority-facts digest,
  14 post-review-gate epoch, 15 post-review blocker-set digest, 16 typed reason,
  17 requested UTC instant, and 18 expiry UTC instant; and
- nexum.vault.quorum-plan.v1: 1 installation UUID, 2 plan UUID, 3 requester UINT64, 4 session digest,
  5 requester auth epoch, 6 proof-kind UTF8 step_up|totp_enrollment,
  7 requester proof UUID,
  8 operation, 9 quorum-lock UUID, 10 cooling-start UTC instant, 11 eligibility UTC instant,
  12 ordered subject-set digest, 13 before roster digest, 14 current roster digest,
  15 proposed roster digest, 16 complete quorum-authority-facts document BYTES,
  17 post-review-gate epoch, 18 post-review blocker-set digest, 19 typed reason,
  20 warning acknowledgement, 21 requested UTC instant, and 22 expiry UTC instant;
- nexum.vault.user-security-pre-state-snapshot.v1: 1 installation UUID, 2 UserSecurity execution
  UUID, 3 closed flow-kind UTF8, 4 nullable initiating actor UINT64, 5 nullable session-binding
  digest BYTES(32), 6 affected-users set digest BYTES(32), 7 typed subject-set digest BYTES(32),
  8 nullable freshness-principal pre-auth-security epoch UINT64, 9 nullable freshness-principal
  pre-Vault-authority epoch UINT64, 10 nullable authority-witness UUID, and 11 captured-at DB
  UTC_INSTANT; and
- nexum.vault.post-review-authorization-snapshot.v1: 1 installation UUID, 2 origin coordinator UUID,
  3 action-plan UUID, 4 action-plan digest BYTES(32), 5 operation-kind UTF8 graph|authority|quorum,
  6 operation UUID, 7 requester UINT64, 8 proof-kind UTF8 step_up|totp_enrollment, 9 proof UUID,
  10 session-binding digest BYTES(32), 11 requester auth-security epoch UINT64, 12 installation
  authority epoch UINT64, 13 nullable scope-key BYTES, 14 nullable scope-auth epoch UINT64,
  15 nullable scope-authorization-structure digest BYTES(32), 16 global-authority-facts digest
  BYTES(32), 17 nullable scope-authority-facts digest BYTES(32), 18 post-review-gate epoch UINT64,
  19 post-review-blocker-set digest BYTES(32), 20 consumed pre-state witness UUID, 21 captured-at DB
  UTC_INSTANT, and 22 plan-expiry UTC_INSTANT;
- nexum.vault.fresh-install-empty-state.v1: 1 installation UUID, 2 user-row count UINT64 fixed 0,
  3 role-row count UINT64 fixed 0, 4 model-role-pivot count UINT64 fixed 0, 5 direct-permission-
  pivot count UINT64 fixed 0, 6 relevant role-permission-pivot count UINT64 fixed 0, 7 Row04
  authorization/bootstrap-state row count excluding the exact completed audit-trust cutover baseline
  UINT64 fixed 0, 8 grant-manage-present BOOL fixed false, and 9 approval-decide-present
  BOOL fixed false; and
- nexum.vault.fresh-install-stage-snapshot.v1: 1 installation UUID, 2 bootstrap-run UUID, 3 stage
  UTF8, 4 revision UINT64, 5 fact-count UINT64, then one BYTES field per complete
  `nexum.vault.entry.fresh-install-bootstrap-fact.v1` entry.

For user-security snapshots, actor and session are both NULL exactly for the enumerated actorless
flows. Freshness-principal epochs identify the authenticated actor, or the sole pre-existing affected
user in an actorless single-user flow; both are NULL only for new_user_insert without a pre-existing
principal. Exact initial epochs then live in that typed subject. The affected-user and subject-set
digests bind every user/epoch/subject in multi-user flows. authority-witness is non-null exactly for
an enforced Vault-authority-changing execution. For post-review snapshots, fields 13..15 and 17 are
all non-null for graph and all NULL for authority/quorum; field 20 is the exact graph execution,
authority witness, or quorum mutation/execution pre-state fence selected by operation-kind. No
snapshot contains raw session bytes, credentials, factors, secret-derived data or free-form text.

Set entries are complete encodings of identity, state, active slot, and relevant row epoch. They are
sorted lexicographically by encoded bytes; duplicates and noncanonical order reject. Count is a
UINT64 field. Family tags prevent the same bytes from being reused across domains.

Display names must be valid UTF-8, trimmed only of U+0020 at both ends, contain no control/format or
line-break code point, already be Unicode NFC, contain 1..120 Unicode scalar values, and be at most
480 UTF-8 bytes. Equality is deliberately NFC byte-exact and case-sensitive in Slice 04; no
case-folding or locale collation participates. Validation requires ext-intl Normalizer/IntlChar plus
ext-mbstring. Persisted validated NFC bytes are historical identity and are not silently
recanonicalized after an ICU upgrade; readiness blocks a runtime/version change until vectors pass.
name_key is raw 32-byte SHA-256 of the name-key family encoding. Session, set, roster, authority,
and plan digests are likewise raw 32-byte SHA-256, never hex/base64 text. All comparisons use
hash_equals after exact length checks.

Session digest is recomputed server-side from the current Laravel session ID through the
session-binding family; no request may supply it. Every subject-set entry uses a closed subject-kind
schema with an exact field order and exact typed before/after values. Adding a subject kind or
changing any field requires a new encoder/family version and migration; silent v1 extension is
forbidden.

The golden vectors below freeze the listed representative documents, null/empty distinction,
integer endianness, UUID decoding, Unicode name, set order, and UTC boundary on both PHP runtimes
used for tests. Separate exact-byte family tests cover every remaining inner/subject family against
its numbered schema; the 23-document table is not falsely claimed to contain each one. The DB enforces
storage class/length, closed state, FK/XOR, epoch transition, and that a guarded change supplies a
new digest. SQLite/MariaDB do not recompute SHA-256. Application services recompute canonical names,
sets, plans, current global authority facts, and exact scope authority facts under locks and reject
with constant-time comparison before
trusting a row. Raw SQL with a shape-valid but false digest can never become an authorization allow.

Encoding uses incremental hash_init/hash_update/hash_final(raw=true); it never materializes an
unbounded combined set. Hard protocol limits are 100,000 entries per structural set, 10,000 subjects
per plan, and 4,096 encoded bytes per inner entry. Counts above a limit reject before hashing.

### Normative V1 Inner Families

Each inner entry is a complete VaultCanonicalBinaryV1 document with its own family tag and is placed
in the outer document as one BYTES field. The exact inner schemas are:

| Inner family | Exact numbered fields |
| --- | --- |
| nexum.vault.entry.group.v1 | 1 group UUID, 2 canonical name UTF8, 3 name-key BYTES(32), 4 state UTF8, 5 active BOOL, 6 auth-epoch UINT64, 7 member-set digest BYTES(32) |
| nexum.vault.entry.collection.v1 | 1 collection UUID, 2 canonical name UTF8, 3 name-key BYTES(32), 4 state UTF8, 5 active BOOL, 6 auth-epoch UINT64, 7 item-set digest BYTES(32) |
| nexum.vault.entry.item-ref.v1 | 1 item UUID, 2 scope-type UTF8, 3 scope-id UINT64, 4 nullable client-id UINT64, 5 item-type UTF8, 6 immutable item-state UTF8 |
| nexum.vault.entry.membership.v1 | 1 membership UUID, 2 group UUID, 3 user UINT64, 4 state UTF8, 5 active BOOL, 6 reachability-expanded BOOL |
| nexum.vault.entry.collection-item.v1 | 1 link UUID, 2 collection UUID, 3 item UUID, 4 state UTF8, 5 active BOOL, 6 reachability-expanded BOOL |
| nexum.vault.entry.grant.v1 | 1 grant UUID, 2 subject-type UTF8, 3 nullable subject-user UINT64, 4 nullable group UUID, 5 target-type UTF8, 6 nullable item UUID, 7 nullable collection UUID, 8 operation UTF8, 9 effect UTF8 fixed allow, 10 state UTF8, 11 active BOOL |
| nexum.vault.entry.candidate.v1 | 1 distinct user UINT64, 2 protected-role-count UINT64, then protected numeric role IDs as ascending UINT64 fields |
| nexum.vault.entry.governance-ready-roster.v1 | 1 complete candidate entry BYTES, 2 per-user Vault-authority-epoch UINT64, 3 effective-policy-manage BOOL fixed true, 4 effective-approval-decide BOOL fixed true, 5 confirmed-TOTP BOOL fixed true |
| nexum.vault.entry.global-authority-fact.v1 | 1 candidate entry BYTES, 2 per-user Vault-authority-epoch UINT64, 3 active-human BOOL, 4 effective-policy-manage BOOL, 5 effective-approval-decide BOOL, 6 confirmed-TOTP BOOL |
| nexum.vault.entry.scope-authority-fact.v1 | 1 global-authority-fact BYTES, 2 scope-type UTF8, 3 scope-id UINT64, 4 nullable client-id UINT64, 5 exact Client-visible BOOL |
| nexum.vault.entry.affected-user.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64, 4 before Vault-authority-epoch UINT64, 5 after Vault-authority-epoch UINT64 |
| nexum.vault.entry.reachability.v1 | 1 subject-type UTF8 user|access_group, 2 nullable subject-user UINT64, 3 nullable access-group UUID, 4 target-type UTF8 item|collection, 5 nullable item UUID, 6 nullable collection UUID, 7 closed operation UTF8 |
| nexum.vault.entry.group-epoch.v1 | 1 group UUID, 2 auth-epoch UINT64, 3 member-set digest BYTES(32) |
| nexum.vault.entry.collection-epoch.v1 | 1 collection UUID, 2 auth-epoch UINT64, 3 item-set digest BYTES(32) |
| nexum.vault.entry.post-review-blocker.v1 | 1 obligation UUID, 2 due UTC_INSTANT, 3 unresolved-finding-count UINT64, 4 overdue-at-snapshot BOOL fixed from authoritative DB time |
| nexum.vault.entry.foundation-audit-trust-manifest.v1 | 1 base numeric audit ID UINT64, 2 event UUID, 3 scope-type UTF8, 4 scope-id UINT64, 5 nullable client-id UINT64, 6 nullable Vault-item UUID, 7 nullable secret-version UUID, 8 actor UINT64, 9 actor-type UTF8, 10 event-type UTF8, 11 outcome UTF8, 12 reason-code UTF8, 13 nullable permission-name UTF8, 14 nullable key-provider BYTES, 15 nullable provider-key-id BYTES, 16 correlation UUID, 17 occurred-at UTC_INSTANT, 18 created-at UTC_INSTANT |

The outer nexum.vault.set.v1 set-kind mapping is exact: groups uses entry.group, collections uses
entry.collection, group-members uses entry.membership, collection-items uses
entry.collection-item, and grants uses entry.grant. Candidate roster uses entry.candidate.
Governance-ready roster uses its dedicated set family with roster-kind governance-ready and
entry.governance-ready-roster; no generic set-kind or filtered global-fact encoding may substitute.
Global-authority facts use entry.global-authority-fact; scope-authority facts use
entry.scope-authority-fact. The affected-users set used by a role-wide authority subject uses
entry.affected-user with set-kind affected-users. Candidate count is distinct users, never
user-role pairs. Every
candidate's nested protected-role IDs are deduplicated and numerically ascending before its entry is
encoded. Outer entries are then deduplicated and byte-strcmp sorted by their complete encoded bytes.
Before/after reachability digests are nexum.vault.set.v1 documents with set-kind reachability and
entry.reachability. Graph-plan field 19 is the raw digest of a set-kind graph-plan-groups document
using entry.group-epoch; field 20 is the raw digest of set-kind graph-plan-collections using
entry.collection-epoch. Empty sets still encode the exact outer scope/epoch/count-zero document.
The quorum-plan facts field contains the complete quorum-authority-facts document, not an unnamed
concatenation or generic three-digest blob.
Post-review gate state uses post-review-blocker-set with entry.post-review-blocker; its digest is
never substituted by a generic structural set. Entries include every open obligation even before its
due time because an open obligation blocks immediately. The overdue bit is evaluated only from the
authoritative DB clock captured under the gate lock.
The audit-trust cutover manifest uses its dedicated outer family and
entry.foundation-audit-trust-manifest. Items are unique and sorted by ascending base numeric audit
ID; the event UUID is a separately checked UNIQUE identity. The header stores the raw 32-byte digest
and exact count, and an empty manifest still encodes the complete installation/cutover/count-zero
document. The 100,000-entry structural-set bound applies; a larger pre-cutover table stops before
Row04 DML and requires a separately reviewed bounded migration plan.

Graph plan subjects have one family per closed kind:

| Inner family | Exact numbered fields |
| --- | --- |
| nexum.vault.subject.graph-group.v1 | 1 mutation UTF8, 2 group UUID, 3 nullable before entry.group BYTES, 4 nullable after entry.group BYTES |
| nexum.vault.subject.graph-collection.v1 | 1 mutation UTF8, 2 collection UUID, 3 nullable before entry.collection BYTES, 4 nullable after entry.collection BYTES |
| nexum.vault.subject.graph-item.v1 | 1 mutation UTF8 fixed reference, 2 item UUID, 3 entry.item-ref BYTES |
| nexum.vault.subject.graph-membership.v1 | 1 mutation UTF8, 2 membership UUID, 3 nullable before entry.membership BYTES, 4 nullable after entry.membership BYTES |
| nexum.vault.subject.graph-collection-item.v1 | 1 mutation UTF8, 2 link UUID, 3 nullable before entry.collection-item BYTES, 4 nullable after entry.collection-item BYTES |
| nexum.vault.subject.graph-grant.v1 | 1 mutation UTF8, 2 grant UUID, 3 nullable before entry.grant BYTES, 4 nullable after entry.grant BYTES |
| nexum.vault.subject.graph-execution.v1 | 1 execution UUID, 2 plan-subject UUID, 3 complete canonical graph plan-subject BYTES, 4 target-kind UTF8, 5 mutation-kind UTF8, 6 target UUID, 7 scope-type UTF8, 8 scope-id UINT64, 9 nullable client-id UINT64 |

Authority and quorum subject families are likewise closed:

| Inner family | Exact numbered fields |
| --- | --- |
| nexum.vault.subject.authority-user-status.v1 | 1 mutation UTF8, 2 user UINT64, 3 before-status UTF8 exact `PENDING_INVITE|ACTIVE|DISABLED`, 4 after-status same exact enum, 5 before auth-security-epoch UINT64, 6 after auth-security-epoch UINT64, 7 before Vault-authority-epoch UINT64, 8 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.authority-user-classification.v1 | 1 mutation UTF8, 2 user UINT64, 3 before-system BOOL, 4 after-system BOOL, 5 before auth-security-epoch UINT64, 6 after auth-security-epoch UINT64, 7 before Vault-authority-epoch UINT64, 8 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.authority-totp-confirmed.v1 | 1 mutation UTF8, 2 user UINT64, 3 before-confirmed BOOL, 4 after-confirmed BOOL, 5 nullable pending-generation UUID, 6 before auth-security-epoch UINT64, 7 after auth-security-epoch UINT64, 8 before Vault-authority-epoch UINT64, 9 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.authority-role-ref.v1 | 1 protected numeric role ID UINT64, 2 immutable protected-role code UTF8 |
| nexum.vault.subject.authority-permission-ref.v1 | 1 protected numeric permission ID UINT64, 2 immutable permission code UTF8 |
| nexum.vault.subject.authority-user-role.v1 | 1 mutation UTF8, 2 user UINT64, 3 role-ref BYTES, 4 before-present BOOL, 5 after-present BOOL, 6 before auth-security-epoch UINT64, 7 after auth-security-epoch UINT64, 8 before Vault-authority-epoch UINT64, 9 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.authority-role-permission.v1 | 1 mutation UTF8, 2 role-ref BYTES, 3 permission-ref BYTES, 4 before-present BOOL, 5 after-present BOOL, 6 affected-user-set digest BYTES(32) |
| nexum.vault.subject.authority-direct-permission.v1 | 1 mutation UTF8 fixed delete, 2 user UINT64, 3 permission-ref BYTES, 4 before-present BOOL fixed true, 5 after-present BOOL fixed false, 6 before auth-security-epoch UINT64, 7 after auth-security-epoch UINT64, 8 before Vault-authority-epoch UINT64, 9 after Vault-authority-epoch UINT64 |
| nexum.vault.entry.authority-state.v1 | 1 authority epoch UINT64, 2 candidate-roster digest BYTES(32), 3 governance-ready-roster digest BYTES(32), 4 global-authority-facts digest BYTES(32), 5 control phase UTF8, 6 quorum state UTF8, 7 nullable quorum-lock UUID, 8 nullable pending-authority-witness UUID, 9 nullable pending-quorum-mutation UUID, 10 nullable cooling-start UTC_INSTANT, 11 nullable recovery-not-before UTC_INSTANT, 12 nullable root-incident-reason UTF8, 13 nullable root-incident-event UUID |
| nexum.vault.subject.authority-state.v1 | 1 mutation UTF8, 2 before entry.authority-state BYTES, 3 after entry.authority-state BYTES |
| nexum.vault.subject.quorum-state.v1 | 1 operation UTF8, 2 lock UUID, 3 before entry.authority-state BYTES, 4 after entry.authority-state BYTES, 5 cooling-start UTC_INSTANT, 6 nullable eligibility UTC_INSTANT |

UserSecurity subject bytes are also closed and secret-free:

| Inner family | Exact numbered fields |
| --- | --- |
| nexum.vault.subject.user-security.password-credential-changed.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64 |
| nexum.vault.subject.user-security.password-rehash-on-login.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64, 4 reason UTF8 fixed password_hash_policy_upgrade |
| nexum.vault.subject.user-security.remember-token-transition.v1 | 1 user UINT64, 2 trigger-kind UTF8 exact password_login|external_nextcloud_hmac|logout_cycle|logout_other_devices|password_reset_completion, 3 before auth-security-epoch UINT64, 4 after auth-security-epoch UINT64 |
| nexum.vault.subject.user-security.totp-generation-transition.v1 | 1 user UINT64, 2 nullable prior generation UUID, 3 current generation UUID, 4 before state UTF8, 5 after state UTF8, 6 before auth-security-epoch UINT64, 7 after auth-security-epoch UINT64, 8 nullable before Vault-authority-epoch UINT64, 9 nullable after Vault-authority-epoch UINT64 |

For `totp-generation-transition`, field 2 is exactly the current generation row's nullable
`replaces_generation_id`; it is not a caller-selected provenance pointer. The lowercase token
`absent` is permitted only as the before-state of a new runtime generation row. The complete v1
transition set is `absent -> pending`, `pending -> confirmed`, `pending|confirmed -> superseded`,
and `pending|confirmed -> disabled`; no transition leaves a terminal state. Reset/replacement uses
two subjects in one execution: the old row becomes superseded and the new row records
`absent -> pending` with the old ID as its replacement pointer. Confirmation uses
`pending -> confirmed` plus the separate TOTP-confirmation subject. Disable uses the terminal
generation transition plus a TOTP-confirmation subject only when readiness changes true to false.
The nullable Vault-authority epoch pair is present exactly when confirmed readiness changes;
otherwise both values are NULL. A `migration_backfill` row is a migration-owned baseline and never
uses the runtime UserSecurity execution or subject contract.

| nexum.vault.subject.user-security.totp-confirmation-transition.v1 | 1 user UINT64, 2 generation UUID, 3 before-confirmed BOOL, 4 after-confirmed BOOL, 5 before auth-security-epoch UINT64, 6 after auth-security-epoch UINT64, 7 before Vault-authority-epoch UINT64, 8 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.user-security.recovery-codes-rotated.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64 |
| nexum.vault.subject.user-security.recovery-code-consumed.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64 |
| nexum.vault.subject.user-security.session-security-reset.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64 |
| nexum.vault.subject.user-security.login-identifier-changed.v1 | 1 user UINT64, 2 before auth-security-epoch UINT64, 3 after auth-security-epoch UINT64 |
| nexum.vault.subject.user-security.account-status-transition.v1 | 1 user UINT64, 2 before status UTF8 exact `PENDING_INVITE|ACTIVE|DISABLED`, 3 after status same exact enum, 4 before auth-security-epoch UINT64, 5 after auth-security-epoch UINT64, 6 before Vault-authority-epoch UINT64, 7 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.user-security.human-system-classification-transition.v1 | 1 user UINT64, 2 before-system BOOL, 3 after-system BOOL, 4 before auth-security-epoch UINT64, 5 after auth-security-epoch UINT64, 6 before Vault-authority-epoch UINT64, 7 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.user-security.new-user-insert.v1 | 1 pre-bind target NULL fixed, 2 expected status UTF8 exact `PENDING_INVITE|ACTIVE|DISABLED`, 3 expected system BOOL, 4 nullable canonical system-key UTF8, 5 initial auth-security-epoch UINT64 fixed 1, 6 initial Vault-authority-epoch UINT64 fixed 1 |
| nexum.vault.subject.user-security.standard-role-bootstrap.v1 | 1 pre-bind target NULL fixed, 2 role-code UTF8 exact Admin|Superuser|Tech|Viewer, 3 guard UTF8 fixed web |
| nexum.vault.subject.user-security.new-user-role-assignment.v1 | 1 pre-bind user target NULL fixed, 2 protected role ID UINT64, 3 before-present BOOL fixed false, 4 after-present BOOL fixed true, 5 before auth-security-epoch UINT64, 6 after auth-security-epoch UINT64, 7 before Vault-authority-epoch UINT64, 8 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.user-security.user-role-assignment.v1 | 1 user UINT64, 2 protected role ID UINT64, 3 before-present BOOL, 4 after-present BOOL, 5 before auth-security-epoch UINT64, 6 after auth-security-epoch UINT64, 7 before Vault-authority-epoch UINT64, 8 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.user-security.role-permission-assignment.v1 | 1 protected role ID UINT64, 2 protected permission ID UINT64, 3 before-present BOOL, 4 after-present BOOL, 5 affected-user-set digest BYTES(32) |
| nexum.vault.subject.user-security.role-permission-affected-user-epoch.v1 | 1 user UINT64, 2 protected role ID UINT64, 3 protected permission ID UINT64, 4 before auth-security-epoch UINT64, 5 after auth-security-epoch UINT64, 6 before Vault-authority-epoch UINT64, 7 after Vault-authority-epoch UINT64 |
| nexum.vault.subject.user-security.direct-permission-assignment.v1 | 1 user UINT64, 2 protected permission ID UINT64, 3 before-present BOOL, 4 after-present BOOL, 5 before auth-security-epoch UINT64, 6 after auth-security-epoch UINT64, 7 before Vault-authority-epoch UINT64, 8 after Vault-authority-epoch UINT64 |
| nexum.vault.entry.fresh-install-bootstrap-fact.v1 | 1 fact-kind UTF8 exact permission|role|role_permission|first_superuser|totp_generation|system_actor|readiness, 2 nullable role UINT64, 3 nullable permission UINT64, 4 nullable user UINT64, 5 nullable generation UUID, 6 nullable canonical identity-code UTF8, 7 nullable persisted status UTF8 exact `PENDING_INVITE|ACTIVE|DISABLED`, 8 nullable present BOOL, 9 nullable auth-security-epoch UINT64, 10 nullable Vault-authority-epoch UINT64, 11 nullable confirmed-TOTP BOOL |

The UserSecurity pre-state snapshot's subject-set digest is an exact `nexum.vault.set.v1` document
with set-kind `user-security-subjects`, company scope `(0,NULL)`, epoch equal to the locked
UserSecurity gate revision, and one complete applicable family above per subject. Nullable Vault-
authority epoch pairs are both NULL exactly when the TOTP generation transition leaves authority
readiness unchanged; otherwise both are non-null and old-to-old+1. new-user-insert and new-user-role-
assignment are immutably encoded with user target NULL; their rows have separate initially-NULL
target_user_id columns that only the same context-bound user AFTER INSERT trigger may bind once to
NEW.id. standard-role-bootstrap likewise encodes NULL and binds its separate target_role_id only from
the matching role AFTER INSERT trigger. The finalizer verifies each binding and generated row against
the already-digested expected fields without changing or re-encoding the snapshot. A caller
therefore cannot select or post-bind a generated identity. Role-permission assignment requires one
role-permission-affected-user-epoch subject for every locked role member and the complete set must
match its affected-user digest. No family contains password, hash, secret, factor,
recovery code, session value, arbitrary scalar or free-form field name.

Fresh-install fact entries use the same complete-entry byte sort/dedupe rule. Their fact-kind NULL
matrix is exhaustive: permission uses fields 3/6/8; role uses 2/6/8; role_permission uses 2/3/8;
first_superuser uses 2/4/6/7/8/9/10/11; totp_generation uses 4/5/8/9/10/11; system_actor uses
4/6/7/8/9/10/11; readiness uses 3/4/6/7/8/9/10/11; every unlisted field is NULL. first_superuser fixes role/identity to the protected
Superuser ID/code, status to exact `ACTIVE` and present=true; its confirmed-TOTP bit is false only at
first_superuser_created and true thereafter. totp_generation fixes present=true and points to the
current same-user retained generation. system_actor fixes identity-code to `vault-control-plane`,
status to exact `DISABLED`, present=true, both epochs to 1 and confirmed-TOTP=false; it carries no
role, permission or generation. Each readiness fact names exactly policy_manage or
approval_decide and fixes ACTIVE/present/confirmed-TOTP true. Identity codes are only the six frozen
permission codes, four standard role codes, or the one canonical system-actor key and never a
mutable display name.

Every stage transition stores a new complete cumulative live fact multiset; facts are never
implicitly inherited from a previous transition. permissions_installed has exactly six permission
facts. roles_installed has those six plus four role facts and eleven role_permission facts
(Superuser six, Admin five, Tech/Viewer zero). first_superuser_created adds exactly one
first_superuser fact. first_superuser_totp_confirmed has the same first-superuser fact with confirmed
true plus exactly one totp_generation fact. vault_control_plane_actor_ready adds exactly one
system_actor fact, for 24 cumulative facts. provisioning_ready adds exactly two readiness facts, one
for policy_manage and one for approval_decide; completed has the identical 26 live facts but its own
stage and revision. Missing, extra, duplicate, stale-ID or out-of-stage facts deny. The empty-state
digest is raw SHA-256 of exactly
`nexum.vault.fresh-install-empty-state.v1`; each stage-snapshot digest is raw SHA-256 of exactly
`nexum.vault.fresh-install-stage-snapshot.v1`. No generic set/digest or live reclassification may
substitute.

Before is NULL only for create/add; after is NULL only for terminal revoke/delete. Exact mutation and
operation enums determine that rule. Role/permission codes are allowlisted identity assertions, but
authorization and protection use numeric IDs plus byte-exact model_type and an existing
user_management row; mutable Spatie names, collation, events, teams, wildcard/cache behavior, and
missing model FKs are never trusted. Every subject set uses nexum.vault.set.v1 with set-kind
graph-subjects, authority-subjects, or quorum-subjects and the same sort/dedupe/count rules.
The graph-execution subject's complete plan-subject bytes are the exact applicable
graph-group|graph-collection|graph-item|graph-membership|graph-collection-item|graph-grant family;
target kind and UUID must match that embedded subject. There is no generic or unnamed scalar-set
encoding.

### V1 Golden Vectors

These lowercase hex values are normative. Short fixtures freeze the entire encoded document:

~~~text
empty.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e766563746f722e656d7074792e763100

nonempty.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e766563746f722e6e6f6e656d7074792e76310002000000036162630000000000010000000004000000010103000000080000000100000000

name.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e6e616d652d6b65792e7631000200000008c3986b6f6e6f6d69

session.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e73657373696f6e2d62696e64696e672e763100060000001001890f000000700080000000000000010300000008000000000000002a010000000a73657373696f6e2d3031

candidate.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e63616e6469646174652d726f737465722e763100060000001001890f000000700080000000000000010300000008000000000000000303000000080000000000000001010000005f4e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e63616e6469646174652e7631000300000008000000000000002a030000000800000000000000020300000008000000000000000703000000080000000000000009

governance_ready_roster.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e676f7665726e616e63652d72656164792d726f737465722d7365742e7631000200000010676f7665726e616e63652d7265616479060000001001890f00000070008000000000000001030000000800000000000000030300000008000000000000000101000000bc4e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e676f7665726e616e63652d72656164792d726f737465722e763100010000005f4e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e63616e6469646174652e7631000300000008000000000000002a03000000080000000000000002030000000800000000000000070300000008000000000000000903000000080000000000000002040000000101040000000101040000000101

reachability_set.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e7365742e763100020000000c72656163686162696c697479060000001001890f000000700080000000000000010200000006636c69656e740300000008000000000000001103000000080000000000000011030000000800000000000000050300000008000000000000000101000000814e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e72656163686162696c6974792e7631000200000004757365720300000008000000000000002a000000000002000000046974656d060000001001890f0000007000800000000000000200000000000200000010766563746f722e6f7065726174696f6e

group_epoch_set.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e7365742e763100020000001167726170682d706c616e2d67726f757073060000001001890f000000700080000000000000010200000006636c69656e740300000008000000000000001103000000080000000000000011030000000800000000000000050300000008000000000000000101000000744e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e67726f75702d65706f63682e763100060000001001890f000000700080000000000000020300000008000000000000000501000000202424242424242424242424242424242424242424242424242424242424242424

collection_epoch_set.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e7365742e763100020000001667726170682d706c616e2d636f6c6c656374696f6e73060000001001890f000000700080000000000000010200000006636c69656e740300000008000000000000001103000000080000000000000011030000000800000000000000050300000008000000000000000101000000794e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e636f6c6c656374696f6e2d65706f63682e763100060000001001890f000000700080000000000000040300000008000000000000000701000000202525252525252525252525252525252525252525252525252525252525252525

quorum_facts.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e71756f72756d2d617574686f726974792d66616374732e763100060000001001890f00000070008000000000000001060000001001890f00000070008000000000000005010000002044444444444444444444444444444444444444444444444444444444444444440100000020454545454545454545454545454545454545454545454545454545454545454501000000204646464646464646464646464646464646464646464646464646464646464646

post_review_blocker_set.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e706f73742d7265766965772d626c6f636b65722d7365742e7631000200000014706f73742d7265766965772d626c6f636b657273060000001001890f000000700080000000000000010300000008000000000000000903000000080000000000000001010000006a4e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e706f73742d7265766965772d626c6f636b65722e763100060000001001890f00000070008000000000000007050000000800000000000f424003000000080000000000000002040000000100

post_review_gate_baseline.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e706f73742d7265766965772d626c6f636b65722d7365742e7631000200000014706f73742d7265766965772d626c6f636b657273060000001001890f000000700080000000000000010300000008000000000000000103000000080000000000000000

fresh_install_empty_state.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e66726573682d696e7374616c6c2d656d7074792d73746174652e763100060000001001890f00000070008000000000000001030000000800000000000000000300000008000000000000000003000000080000000000000000030000000800000000000000000300000008000000000000000003000000080000000000000000040000000100040000000100

fresh_install_stage_snapshot.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e66726573682d696e7374616c6c2d73746167652d736e617073686f742e763100060000001001890f00000070008000000000000001060000001001890f0000007000800000000000000e02000000157065726d697373696f6e735f696e7374616c6c65640300000008000000000000000103000000080000000000000001010000009a4e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e66726573682d696e7374616c6c2d626f6f7473747261702d666163742e763100020000000a7065726d697373696f6e00000000000300000008000000000000000b0000000000000000000002000000127661756c742e6772616e745f6d616e6167650000000000040000000101000000000000000000000000000000

foundation_audit_trust_manifest.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e666f756e646174696f6e2d61756469742d74727573742d6d616e69666573742e763100060000001001890f00000070008000000000000001060000001001890f0000007000800000000000000f0300000008000000000000000101000001264e4558554d2d5641554c54006e6578756d2e7661756c742e656e7472792e666f756e646174696f6e2d61756469742d74727573742d6d616e69666573742e76310003000000080000000000000007060000001001890f000000700080000000000000100200000006636c69656e740300000008000000000000001103000000080000000000000011000000000000000000000300000008000000000000002a020000000568756d616e020000001172656164696e6573735f636865636b656402000000097375636365656465640200000005726561647902000000117661756c742e6865616c74685f7669657700000000000000000000060000001001890f00000070008000000000000011050000000800000000000f4240050000000800000000001e8480

password_rehash_on_login_subject.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e7375626a6563742e757365722d73656375726974792e70617373776f72642d7265686173682d6f6e2d6c6f67696e2e7631000300000008000000000000002a0300000008000000000000000303000000080000000000000004020000001c70617373776f72645f686173685f706f6c6963795f75706772616465

remember_token_transition_subject.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e7375626a6563742e757365722d73656375726974792e72656d656d6265722d746f6b656e2d7472616e736974696f6e2e7631000300000008000000000000002a020000000e70617373776f72645f6c6f67696e0300000008000000000000000303000000080000000000000004

graph_plan.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e67726170682d706c616e2e763100060000001001890f00000070008000000000000001060000001001890f000000700080000000000000020300000008000000000000002a0100000020349e4397bacedb2e65813236b0314e9ebd462394495e64decf790012b10ab6ec030000000800000000000000030200000007737465705f7570060000001001890f000000700080000000000000030200000011636f6c6c656374696f6e2e72656e616d650200000006636c69656e7403000000080000000000000011030000000800000000000000110100000020111111111111111111111111111111111111111111111111111111111111111102000000084e6574747665726b010000002051ffa1f468e58d224a5891b8aad121d4d4a76b35cbec9b22979fe1afe4665c6d020000000e706c616e6e65645f6368616e676501000000202222222222222222222222222222222222222222222222222222222222222222010000002023232323232323232323232323232323232323232323232323232323232323230300000008000000000000000501000000202424242424242424242424242424242424242424242424242424242424242424010000002025252525252525252525252525252525252525252525252525252525252525250100000020c6c30fef14e7efcf0a06bc9f0824f48ee7c853d077e31c11a21d9375a307555e01000000202626262626262626262626262626262626262626262626262626262626262626010000002027272727272727272727272727272727272727272727272727272727272727270300000008000000000000000901000000202828282828282828282828282828282828282828282828282828282828282828050000000800000000000000000500000008000000006b49d200

authority_plan.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e617574686f726974792d706c616e2e763100060000001001890f00000070008000000000000001060000001001890f000000700080000000000000040300000008000000000000002a0100000020349e4397bacedb2e65813236b0314e9ebd462394495e64decf790012b10ab6ec030000000800000000000000030200000007737465705f7570060000001001890f000000700080000000000000030200000017617574686f726974792e757365725f726f6c655f616464010000002031313131313131313131313131313131313131313131313131313131313131310100000020c6c30fef14e7efcf0a06bc9f0824f48ee7c853d077e31c11a21d9375a307555e0100000020323232323232323232323232323232323232323232323232323232323232323201000000203333333333333333333333333333333333333333333333333333333333333333010000002034343434343434343434343434343434343434343434343434343434343434340300000008000000000000000901000000203535353535353535353535353535353535353535353535353535353535353535020000000e706c616e6e65645f6368616e6765050000000800000000000000000500000008000000006b49d200

quorum_plan.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e71756f72756d2d706c616e2e763100060000001001890f00000070008000000000000001060000001001890f000000700080000000000000050300000008000000000000002a0100000020349e4397bacedb2e65813236b0314e9ebd462394495e64decf790012b10ab6ec030000000800000000000000030200000007737465705f7570060000001001890f00000070008000000000000003020000001271756f72756d2e706565725f756e6c6f636b060000001001890f0000007000800000000000000605000000080000000000000000050000000800000000000f4240010000002041414141414141414141414141414141414141414141414141414141414141410100000020c6c30fef14e7efcf0a06bc9f0824f48ee7c853d077e31c11a21d9375a307555e010000002042424242424242424242424242424242424242424242424242424242424242420100000020434343434343434343434343434343434343434343434343434343434343434301000000cb4e4558554d2d5641554c54006e6578756d2e7661756c742e71756f72756d2d617574686f726974792d66616374732e763100060000001001890f00000070008000000000000001060000001001890f000000700080000000000000050100000020444444444444444444444444444444444444444444444444444444444444444401000000204545454545454545454545454545454545454545454545454545454545454545010000002046464646464646464646464646464646464646464646464646464646464646460300000008000000000000000901000000204747474747474747474747474747474747474747474747474747474747474747020000000f72657669657765645f756e6c6f636b0400000001010500000008000000000000000005000000080000000011e1a300

user_security_pre_state_snapshot.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e757365722d73656375726974792d7072652d73746174652d736e617073686f742e763100060000001001890f00000070008000000000000001060000001001890f00000070008000000000000008020000001470617373776f72642d72657365742d746f6b656e000000000000000000000100000020515151515151515151515151515151515151515151515151515151515151515101000000205252525252525252525252525252525252525252525252525252525252525252030000000800000000000000030300000008000000000000000200000000000500000008000000000012d687

post_review_authorization_snapshot.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e706f73742d7265766965772d617574686f72697a6174696f6e2d736e617073686f742e763100060000001001890f00000070008000000000000001060000001001890f0000007000800000000000000a060000001001890f000000700080000000000000020100000020308ecd1d19596d7273f12017a04307527c8328dca397d3e087894939bff3133d02000000056772617068060000001001890f0000007000800000000000000b0300000008000000000000002a0200000007737465705f7570060000001001890f000000700080000000000000030100000020349e4397bacedb2e65813236b0314e9ebd462394495e64decf790012b10ab6ec03000000080000000000000003030000000800000000000000030100000009636c69656e743a3137030000000800000000000000050100000020222222222222222222222222222222222222222222222222222222222222222201000000202626262626262626262626262626262626262626262626262626262626262626010000002027272727272727272727272727272727272727272727272727272727272727270300000008000000000000000901000000202828282828282828282828282828282828282828282828282828282828282828060000001001890f0000007000800000000000000c050000000800000000000f42400500000008000000006b49d200

authority_emergency_plan.document_hex =
4e4558554d2d5641554c54006e6578756d2e7661756c742e617574686f726974792d706c616e2e763100060000001001890f00000070008000000000000001060000001001890f0000007000800000000000000d0300000008000000000000002a0100000020349e4397bacedb2e65813236b0314e9ebd462394495e64decf790012b10ab6ec030000000800000000000000030200000007737465705f7570060000001001890f00000070008000000000000003020000001e617574686f726974792e656d657267656e63795f64656163746976617465010000002061616161616161616161616161616161616161616161616161616161616161610100000020c6c30fef14e7efcf0a06bc9f0824f48ee7c853d077e31c11a21d9375a307555e0100000020c6c30fef14e7efcf0a06bc9f0824f48ee7c853d077e31c11a21d9375a307555e01000000206262626262626262626262626262626262626262626262626262626262626262010000002063636363636363636363636363636363636363636363636363636363636363630300000008000000000000000901000000203535353535353535353535353535353535353535353535353535353535353535020000001f656d657267656e63795f73656375726974795f646561637469766174696f6e050000000800000000000000000500000008000000006b49d200
~~~

The fixtures and expected digests are:

| ID | Exact ordered input | Document bytes | Expected SHA-256 hex |
| --- | --- | ---: | --- |
| empty | family nexum.vault.vector.empty.v1, no fields | 40 | 18fe9da0d7f7f71dca8aa8115cb4325ca809d5bad3b93afb9b2a243d58dd3dc8 |
| nonempty | family nexum.vault.vector.nonempty.v1; UTF8 abc, NULL, empty BYTES, BOOL true, UINT64 2^32 | 80 | ef32b8a104734931b90bc5471519e872f0bc734c2977f491881241a45bbe446c |
| name | name-key; NFC UTF8 Økonomi | 49 | 5951c6acf586c3cc2d79c388337143e72af992d949d29a5ee47767652d3177a0 |
| session | installation I, actor 42, raw session BYTES session-01 | 92 | 349e4397bacedb2e65813236b0314e9ebd462394495e64decf790012b10ab6ec |
| candidate | installation I, epoch 3, one candidate entry: user 42, roles 7 and 9 | 191 | c6c30fef14e7efcf0a06bc9f0824f48ee7c853d077e31c11a21d9375a307555e |
| governance-ready-roster | roster-kind governance-ready, installation I, epoch 3, candidate above at Vault-authority epoch 2 with all three readiness facts true | 316 | 3710d0a112d230662703571dcdcc430ca1ad447b46ceada6eee85deb22492316 |
| reachability-set | set-kind reachability, Client 17, epoch 5, user 42 to item G for fixture-only vector.operation | 266 | a666fc43439bc8258ac41f600ff445ceefa6e26a8ba5f0a00e4d36e933c16d7f |
| group-epoch-set | set-kind graph-plan-groups, Client 17, epoch 5, group G epoch 5 and digest bytes 24 | 258 | c31820cc4450a7d3948635adc8b32ad9ac24432ba489973c200c44cec4bc3a51 |
| collection-epoch-set | set-kind graph-plan-collections, Client 17, epoch 5, collection A epoch 7 and digest bytes 25 | 268 | 4ca87d7a06435c92288bd8cdd41dd543b15e46b20fc55f2f227d2d6fa8e8bad9 |
| quorum-authority-facts | installation I, plan Q, before/current/proposed digests bytes 44/45/46 | 203 | 3a3ed15b50706bbefd792f105d81f34c5c76a716edf95d7a7fb05155a1b9d794 |
| post-review-blocker-set | set-kind post-review-blockers, installation I, gate epoch 9, obligation O due at UTC micros 1000000 with two unresolved findings and not overdue | 234 | 84ad13c8b9f93b4a4e75521999015ba9aea67271d3b3808ce73c22548d883392 |
| post-review-gate-baseline | set-kind post-review-blockers, installation I, gate epoch 1, entry count 0 | 123 | bc6c0218530f901d0b6b5f7fc7a000305aeac64d14b28102a3c10d393dd8f264 |
| fresh-install-empty-state | installation I; zero users, roles, role/direct-permission pivots, relevant role-permission pivots, and Row04 state; both new-permission-present flags false | 164 | 9d2320b08e69d94d6dce4aa38e71a634a66392ff1e882c03f20fc1b7f4541c9f |
| fresh-install-stage-snapshot | encoder-only fixture: installation I, bootstrap run B, permissions_installed, revision 1, one permission fact for numeric permission 11 and vault.grant_manage present; not a valid live stage multiset | 309 | 5731eab096652ed98c8e1e9cd55442f2f6a6ecceb2e1e6091c573a35d55e6d86 |
| foundation-audit-trust-manifest | installation I, cutover T, one immutable pre-cutover foundation audit row: base ID 7/event V, Client 17, actor 42, readiness_checked/succeeded/ready, vault.health_view, correlation C2, UTC micros 1000000/2000000 | 413 | 4475650bf5605e4939c379290ca119a208f94c3016112b2fd9a329d26aef1135 |
| password-rehash-on-login subject | user 42, auth-security epoch 3 to 4, fixed reason password_hash_policy_upgrade | 146 | d85f7fdbabd4f9daf4fbe613e11be2c91619e2bba7ad9e6c5d4f4369c482a024 |
| remember-token-transition subject | user 42, trigger password_login, auth-security epoch 3 to 4 | 133 | ec2975051ad911dee69431b43cc98dd0e5d0735613d4091c701cdf4fbf09a599 |
| user-security-pre-state-snapshot | installation I, execution U, password-reset-token, actor/session NULL, affected/subject digests bytes 51/52, pre-epochs 3/2, witness NULL, captured UTC micros 1234567 | 255 | ac5ae107ef9f09d5e9ab0cf1455dc71f05cc560c938ebfe18f0bbf9f90da620d |
| graph-plan | graph fields G below | 689 | 308ecd1d19596d7273f12017a04307527c8328dca397d3e087894939bff3133d |
| post-review-authorization-snapshot | graph origin R, operation X, graph-plan G/digest, requester/proof/session, exact scope/authority/gate digests, witness W, captured/expiry instants below | 537 | dd52e85c90a819306cd0cf87e9845a2518b7d265ac0b0faf662136783e0540e9 |
| authority-plan | authority fields A below | 488 | 855dbe2a4b19d688b1890f9d0ea9d8b1ce23fbb8d129ab81ca61778bf191607b |
| authority-emergency-plan | authority plan E with authority.emergency_deactivate and exact emergency_security_deactivation reason | 512 | 9bf8d2f503179c60e70fcf49f22af8dfd87ab7b17b1634da613b6eb84645f184 |
| quorum-plan | quorum fields Q below | 705 | 454b680fb54bf787b2c8d1d011df143357c331a29e87bf6a7f51b6c11dee99c6 |

Shared fixture constants are I=01890f00-0000-7000-8000-000000000001,
G=...0002, P=...0003, A=...0004, Q=...0005, quorum lock L=...0006, and obligation
O=...0007. UserSecurity execution U=...0008, post-review origin R=...000a, operation
X=...000b, consumed pre-state witness W=...000c, emergency plan E=...000d, fresh-install
bootstrap run B=...000e, audit-trust cutover T=...000f, manifest event V=...0010, and manifest
correlation C2=...0011.
S is the raw session-vector digest above; C is the raw candidate-vector digest above; N is
SHA-256(name-key(Nettverk)) =
51ffa1f468e58d224a5891b8aad121d4d4a76b35cbec9b22979fe1afe4665c6d.

- graph fields in order are I,G,42,S,3,step_up,P,collection.rename,client,17,17, 32 bytes 11,
  Nettverk,N,planned_change, 32 bytes 22, 32 bytes 23,5, 32 bytes 24, 32 bytes 25,C,
  32 bytes 26, 32 bytes 27,9, 32 bytes 28, UTC micros 0, UTC micros 1800000000.
- authority fields are I,A,42,S,3,step_up,P,authority.user_role_add, 32 bytes 31,C, 32 bytes 32,
  32 bytes 33, 32 bytes 34,9, 32 bytes 35,planned_change, UTC micros 0,
  UTC micros 1800000000.
- quorum fields are I,Q,42,S,3,step_up,P,quorum.peer_unlock,L, UTC micros 0, UTC micros 1000000,
  32 bytes 41,C, 32 bytes 42, 32 bytes 43, complete quorum-authority-facts document using
  32-byte digests 44/45/46,9, 32 bytes 47,reviewed_unlock,true,
  UTC micros 0, UTC micros 300000000.
- user-security snapshot fields are I,U,password-reset-token,NULL,NULL, 32 bytes 51, 32 bytes 52,
  3,2,NULL, UTC micros 1234567.
- post-review snapshot fields are I,R,G,SHA-256(graph-plan),graph,X,42,step_up,P,S,3,3,
  BYTES client:17,5, 32 bytes 22, 32 bytes 26, 32 bytes 27,9, 32 bytes 28,W,
  UTC micros 1000000, UTC micros 1800000000.
- emergency authority fields are I,E,42,S,3,step_up,P,authority.emergency_deactivate,
  32 bytes 61,C,C, 32 bytes 62, 32 bytes 63,9, 32 bytes 35,
  emergency_security_deactivation, UTC micros 0, UTC micros 1800000000.

Whitespace in the fixture display is not encoded. Tests must also freeze UINT64 and signed UTC
boundary vectors, every inner family, null versus empty, list sort/dedupe, and cross-family
rejection before the encoder is considered ready.

## UserManagement Authentication And Authority Epoch

UserManagement adds independent unsigned auth_security_epoch and vault_authority_epoch values, both
with initial value 1, plus a nullable unsigned vault_totp_last_accepted_counter and a nullable
server-owned two_factor_generation_id current pointer. AuthenticationSecurityMutationBoundary is the single
UserManagement-owned write boundary for identity/security changes; it is distinct from
VaultAuthorityMutationGuard.

The authentication boundary locks the user and advances auth_security_epoch by exactly one with a
password, login-identifier, reset, invitation, recovery, bootstrap, session-security reset, TOTP-secret enrollment/
replacement, confirmed-state, status, protected-role/permission or human/system-classification
mutation. Password/reset/invite/recovery/bootstrap and
TOTP-secret enrollment while unconfirmed need their authoritative UserManagement checks, but never
invent a Vault actor/session/proof/plan/witness that those flows cannot possess. The verifier-owned
vault_totp_last_accepted_counter uses a separate monotonic compare-and-set, may jump to the newly
accepted counter, and neither advances auth_security_epoch nor consumes an authority witness.

vault_authority_epoch changes exactly once only when candidate/governance facts change: active
status, human/system classification, protected Admin/Superuser role, protected Vault permission, or
confirmed-TOTP readiness including confirm, disable, reset or replacement of a confirmed factor.
Those mutations advance both per-user epochs; password/reset/recovery/session changes with unchanged
authority facts advance auth_security_epoch only. Proofs, requests and decisions bind the relevant
actor auth_security_epoch. Candidate/global-authority facts bind vault_authority_epoch, never
auth_security_epoch, so actorless authentication recovery cannot make the global authority anchor
stale. Authority-fact mutations update the installation authority epoch/digests through their
existing Vault fence when enforced.

Only when control phase is enforced does a status/classification change, protected
Admin/Superuser-role pivot, protected Vault-permission pivot, or TOTP-confirmed transition that
changes candidate/governance facts additionally require the pre-apply Vault authority
plan/proof/approval/witness contract. Dormant keeps existing UserManagement paths operational while
still advancing epochs and protecting canonical role/permission identities. Role-wide protected
permission changes lock all affected users and advance every affected epoch. Removing and re-adding
authority therefore cannot resurrect an old proof or approval.

Approved clarification by Svein, 2026-09-18: while enforced, acceptance for a pending human
with the exact web Admin/Superuser role only establishes the password and consumes the invitation.
The existing actorless invitation-token flow has one password_credential_changed subject; only the
authentication epoch advances. Status, roles and authority epochs remain unchanged, and no login
occurs. Recheck phase and roles inside the owned transaction; deny a stale branch decision.
Ordinary invitations retain their paired password/status flow. Dormant behavior is unchanged.
An existing administrator later activates the account through the guarded account-status-change,
with current plan, proof, required approval/sole-admin exception and reciprocal audit. An invitation
receipt is not an authority approval. See ADR 2026-09-18-vault-privileged-invitation-activation.

TOTP lifecycle operations are exhaustive. Beginning a first unconfirmed enrollment is a
UserManagement password+generation flow and needs no impossible Vault step-up; it advances only the
authentication epoch until confirmation. authority.totp_confirm is pending-to-confirmed for the
exact persisted generation. authority.totp_reset is confirmed-to-a newly persisted pending
generation, supersedes the old generation, clears confirmed readiness, and advances both epochs.
authority.totp_disable is confirmed-to-no factor/pointer, terminally disables the old generation,
and advances both epochs. Reset and disable are governance-readiness reductions subject to the exact
floor, approval/quorum, fence and audit rules; no generic update or alias may represent them.

AuthenticationSecurityMutationBoundary itself uses a UserManagement-owned one-use execution in every
control phase. Its closed flow_kind registry is exactly:

- `password-update`, `password-reset-token`, `password_rehash_on_login`, `invitation-token`, and
  `customer-portal-invitation`;
- `remember_token_password_login`, `external_nextcloud_hmac_session_start`,
  `remember_token_logout_cycle`, and `logout_other_devices`;
- `recovery-codes-rotate`, `recovery-code-consume`, and `session-security-reset`;
- `totp-generation-change`, `totp-confirmation-change`, `account-status-change`,
  `human-system-classification-change`, and `login-identifier-change`;
- `user-role-change`, `role-permission-change`, and `direct-permission-change`; and
- `self_registration_pending`, `bootstrap_admin`, `system_actor_bootstrap`, and
  `fresh_install_role_bootstrap`, and `vault_control_plane_provision`.

Unknown values and spelling aliases deny. The immutable execution header has a UUIDv7, closed trusted flow kind, DB time,
  short expiry, the closed stage lifecycle below, and actor_id; actor may be NULL only for the enumerated
password-reset-token, invitation-token, customer-portal-invitation, `self_registration_pending`, `bootstrap_admin`,
system_actor_bootstrap, `fresh_install_role_bootstrap`, and `vault_control_plane_provision` flows. Exact
typed subjects come only from the closed safe subject-kind register below. Each applicable kind
contains its own exact OLD-to-NEW epoch pair; a role-permission change additionally uses the closed
role_permission_affected_user_epoch kind once per affected User. Credential, presented-factor, hash and arbitrary scalar
values never enter a subject. The connection-local UserSecurityMutationContext implements
the exact registry below: it binds the singleton gate revision, execution, current subject, expected
OLD/NEW row or epoch, closed stage, and optional UNIQUE authority witness to one statement. MariaDB
session variables and SQLite UDF+IMMEDIATE transaction triggers atomically consume only an exact
active subject. Its whole context family is cleared before/after, in `finally`, and on connection
checkout/return. Finalization requires every expected
subject consumed or the transaction rolls back.

A retained UserManagement-owned `user_security_mutation_gate` closes the committed-intermediate
window across multi-statement mutations. Its baseline is revision 1 with no pending execution. The
execution header is precreated, and claiming that exact execution is the first protected write; no
protected UserManagement column, Spatie pivot, factor-generation, or typed subject carrying an
epoch transition may be consumed before the gate claim.
While `pending_execution_id` is non-null, every new or unrelated Row04 Vault step-up, proof
activation, plan, approval, authority action, PDP decision, and readiness success denies, while
ordinary Nexum login remains governed by UserManagement and is not blocked. The sole exception is
the exact typed owner-resume/finalizer context whose execution ID equals the gate pointer and whose
subject/snapshot IDs were precommitted; it may finish only its owned subjects and
auth_security_invalidated evidence/audits, never authorize a new action. Execution stages are
claimed, effects_started, subjects_complete, and finalized. Immediately
before the first guarded subject, a CAS revalidates the immutable pre-state snapshot and changes
claimed-to-effects_started. After that point TTL expiry cannot strand the exact execution: it is
resume-only through all exact subjects/epochs to subjects_complete. The LAST finalizer locks the
gate, verifies all subjects consumed plus every expected OLD-to-NEW epoch, changes to finalized,
increments gate revision exactly once, and clears the pointer by null-safe CAS. Abort is permitted
only from claimed with zero subject, protected DML, or epoch consumption; it atomically changes to
aborted and clears the still-owned pointer. A forced commit after any later statement therefore
leaves Vault fail closed and the typed execution resumable, never exposes partial state to a Row04
consumer, and never leaves an old proof usable for a Row04 decision.

Role-permission changes snapshot and lock every current role member, bind the sorted affected-user
set digest, and include one role_permission_affected_user_epoch subject for each member.
Membership-set drift denies before any
write. In dormant this security execution and exact epoch algorithm are mandatory but no Vault
proof/plan/witness is invented. In enforced, the UserManagement execution is created first. An
execution affecting Vault authority must then be referenced by exactly one Vault authority witness,
and the authority mutation binds both rows plus their identical plan/subjects; there is deliberately
no reverse execution-to-witness FK. The UserSecurity database context finds the witness only through
its UNIQUE user_security_execution_id and requires it for protected authority DML. The two fences
compose in the same transaction. Generic role changes that affect neither authentication security
nor protected Vault authority remain outside this boundary. Listeners and Spatie events are never
the enforcement mechanism.

The implementation must route and test all current mutation paths through
AuthenticationSecurityMutationBoundary rather than event listeners, including:

- Fortify CreateNewUser, ResetUserPassword, and UpdateUserPassword;
- the app-owned Fortify `authenticateUsing` callback and every remaining stateful
  SessionGuard/EloquentUserProvider password-validation entry point that can invoke automatic hash
  policy rehash;
- Fortify TOTP enrollment, confirmation, disablement, recovery-code generation/use, and security
  reset paths, including every assignment, replacement, or clear of server-owned generation
  metadata;
- UserManagement AcceptInviteController, ProfileSecurityController, StoreUser,
  SyncUserRoles, UpdateUserStatus, role/permission actions, RolePermissions Livewire, and admin/API
  user mutations;
- CustomerPortal AcceptCustomerPortalInvitation; and
- system-actor and bootstrap user creation.

The two existing login-email writers, Fortify UpdateUserProfileInformation::update and
UserManagement UpdateUserProfile::handle, use the same boundary. A persisted change to
user_management.email, including a byte/case change that storage actually retains, consumes one
`login_identifier_changed` subject and advances auth_security_epoch exactly once. The subject binds
only user ID and the expected OLD-to-NEW epoch; neither email value nor an email-derived hash enters
Vault evidence. A no-op email does not consume a subject or advance an epoch. Name, telephone,
avatar, contact/profile metadata and email_verified_at remain outside the Row04 epoch boundary unless
the same transaction also changes login identifier, role, status, TOTP readiness or system/human
classification.

Secure framework password rehash remains enabled. After a password has validated for an exact active
human and before login completion, the app-owned Fortify authentication callback invokes the
`password_rehash_on_login` UserSecurity flow when the configured hasher reports `needsRehash`; a
decorated UserManagement provider routes any other stateful provider rehash entry through that same
boundary and forbids the framework's hidden `forceFill(password)->save()` path. The provider's forced
logoutOtherDevices rewrite is not mislabelled as a policy upgrade: after password validation it is
combined with that action's remember-token change in the single `logout_other_devices`
session-security-reset execution, with closed password_credential_changed and
remember_token_transition subjects and one auth-epoch advance. The boundary locks and
fresh-loads the user, revalidates the presented password as a `#[SensitiveParameter]`, rechecks
`needsRehash`, and then atomically persists the replacement hash, advances auth_security_epoch
exactly once, terminalizes every pre-existing active Vault proof through the execution-owned
invalidation subjects, records its typed evidence/audit, and clears the UserSecurity gate before the
guard may establish a logged-in session. It binds only user ID, expected OLD-to-NEW auth epoch,
reason `password_hash_policy_upgrade`, and the server-derived pre-authentication Laravel-session
correlation digest. It stores no password, old/new hash, algorithm, cost, factor, or value-derived
digest. A concurrent winner or the second Fortify callback invocation re-locks and becomes a no-op
when `needsRehash` is false; no execution, epoch change, or audit is emitted for that no-op. Any
boundary, hashing, evidence, or finalization failure rolls back and fails authentication before any
partial session is established. This login-authentication provenance requires no Vault step-up.

`user_management.remember_token` is a protected session-security field. The app-owned Eloquent user
provider routes every `updateRememberToken` call through one active UserSecurity execution; an
unknown or missing call context fails closed. Its exact source map is: initial remembered password
login uses `remember_token_password_login` after the same successful password validation; the
Nextcloud integration uses `external_nextcloud_hmac_session_start`; ordinary logout token rotation
uses `remember_token_logout_cycle` with the current human/session; `logout_other_devices` requires
the current human plus its freshly validated `#[SensitiveParameter]` password; and password-reset
completion consumes a remember_token_transition subject inside the existing actorless
password-reset-token execution rather than a second commit. Each actual token change advances
auth_security_epoch once and invalidates active Vault proofs before gate clear. A disabled remember-
me feature or byte-identical no-op consumes no subject and advances no epoch. Token bytes and
token-derived hashes never enter a subject, event, audit, session payload, log, or output.

The existing root Nextcloud HMAC entry delegates to a Nextcloud-owned authentication action instead
of calling `Auth::login(..., true)` directly. That action retains the current signature and timestamp
validation, then requires the target row to be an exact `ACTIVE` human, non-system identity with
effective `warroom.view` tech access and no portal-only fallback. It runs the typed
external_nextcloud_hmac_session_start execution for any remember-token write, rotates the Laravel
session only after finalization, and never counts the HMAC as Vault step-up, confirmed TOTP, or
approval. Missing/invalid signature, stale timestamp, inactive/system/portal-only user, missing tech
permission, execution failure, or evidence failure denies before login. Moving the legacy root route
to domain route ownership remains separate; the protected write cannot wait for that cleanup.

Fortify two-factor challenge state stores only challenged user ID, exact auth_security_epoch and the
server-owned current TOTP generation UUID. Challenge completion locks and rechecks exact `ACTIVE`
human/non-system state, unchanged epoch/generation and confirmed factor before session login; drift
invalidates the challenge without revealing which fact changed. Vendor Fortify two-factor routes and
the custom profile controller use the same boundary. Recovery-code consumption is wrapped so the
raw code remains `#[SensitiveParameter]` inside UserManagement verification and is never dispatched
or logged in `RecoveryCodeReplaced`; only a safe typed event carrying user ID, UserSecurity execution
ID and closed outcome may leave the verifier.

The boundary recognizes only the exact persisted UserManagement status literals
`PENDING_INVITE|ACTIVE|DISABLED`; there is no lowercase alias or implicit mapping, and a candidate is
active only when the stored value is exactly `ACTIVE`. Unknown or future status values deny.
Schema/readiness requires the authoritative User model table
to be exactly user_management. It does not infer a model table, Spatie guard, or identity from
configuration drift.

New auto-increment users use one precommitted `new_user_insert` subject whose target_user_id is NULL
and whose safe expected status, human/system classification, canonical system key when applicable,
and initial epoch values are exact. The context-bound AFTER INSERT trigger verifies the protected
NEW fields, atomically binds generated NEW.id to that subject once, and consumes it; callers cannot
post-bind or guess an ID. The still-pending UserSecurity gate owns every remaining subject/finalizer.
When the same flow also assigns a role, each precommitted `new_user_role_assignment` subject has its
separate target_user_id bound to that same NEW.id by the same trigger and is later consumed only by
the exact pivot INSERT; a generic user_role_assignment with an unknown guessed ID cannot substitute.
Wrong expected values, a second bind, concurrent insert, or a forced commit before completion leaves
Vault consumers fail closed and only the exact execution may resume.

Existing Fortify self-registration remains available through the one actorless
`self_registration_pending` flow. It may create exactly one human row with persisted status
`PENDING_INVITE`, is_system_actor=false, system_actor_key NULL, no role or direct permission, no
confirmed TOTP, and both epochs exactly 1. The generated ID is bound by the same AFTER INSERT
protocol, and the finalized UserSecurity execution plus subjects are the retained security evidence;
the existing UserManagement registration audit completes without inventing a Vault actor. Any
ACTIVE/system-actor classification, role/permission pivot, confirmed factor, second user, or other
side effect makes finalization fail and leaves the UserSecurity gate fail closed. The flow creates no
candidate and grants no Vault authority.

Raw writes that change a protected identity/security field without the required monotonic epoch
transition fail on MariaDB and SQLite. Session-security reset advances the epoch even when no other
user column changes. New human/system users start at epoch 1 through the same boundary. A privileged
raw DB writer that forges both the source field and correct next epoch remains inside the documented
database-administrator trust boundary; the authority witness does not claim to solve arbitrary DBA
compromise.

The generation ID is canonical lowercase UUIDv7 metadata, never derived from the encrypted secret
and never accepted from a request. It references a retained UserManagement-owned
user_two_factor_generations row containing no secret. Creating or replacing a non-null
two_factor_secret creates a new pending generation with `Str::uuid7()` and explicit UTC time, moves
the current pointer, and terminally supersedes the previous generation in the same locked
UserManagement transaction. Confirmation locks the user, loads the pointed pending generation
server-side, transitions it once to confirmed, and preserves the pointer. Disabling TOTP terminally
marks the current generation disabled and clears secret, recovery codes, confirmed time, and pointer
together. Generation rows and their IDs are never deleted, reused, restored, or reassigned, so
approval/proof FKs remain durable after replacement or disable. Both driver guards reject partial
combinations, caller-selected IDs, stale generation confirmation, and invalid transitions. Existing
non-null TOTP secrets receive fresh retained UUIDv7 metadata in an additive bounded migration,
classified confirmed when confirmed_at is non-null and pending otherwise, without decrypting,
hashing, logging, or deriving anything from the secret; empty secrets retain a NULL pointer.

## Cross-Domain Vault Authority Mutation Guard

When control phase is enforced, one VaultAuthorityMutationGuard brokers every mutation that can add
or remove effective
vault.policy_manage, vault.grant_manage, vault.approval_decide, or vault.key_provider_manage;
Admin/Superuser candidate eligibility; confirmed-TOTP governance readiness; or active-human status.
Generic role editors cannot directly toggle migration-managed vault.* permissions in any phase.

In enforced phase the guard is mandatory for web, Livewire, API, command, seeder-after-deployment,
and direct service paths. Missing actor/session/adapter context denies; Admin and Superuser have no
bypass. It locks the
installation authority row and affected users/roles/pivots, computes exact before/after candidate
roster and global-authority-facts digests,
advances every affected user epoch and the installation authority epoch, invalidates stale proofs,
then writes typed audit in the same transaction.

Before any protected INSERT/UPDATE/DELETE on user_management or Spatie user-role,
role-permission, or direct-permission pivots, the service creates one expiring
vault_authority_write_witness plus exact typed subject rows. Each witness binds actor, fresh proof,
pre-apply plan, required approval/exception when applicable, target table/row, before/after value,
and active_slot=1. Driver-specific triggers require the one matching unconsumed witness and consume
its subject atomically. Replay, raw DML, partial subject application, or an expired witness fails;
transaction rollback restores both domain row and witness. Final applied evidence is permitted only
after every expected subject is consumed. Unrelated non-Vault role writes remain unaffected.

VaultAuthorityWitnessContext is a narrow connection-scoped bridge, never an authorization
decision. It carries the exact witness, current subject, optional required UserSecurity execution,
authority mutation, primary audit event and closed stage for one guarded statement. For emergency
deactivation the primary event value is also the preallocated root-incident event used by the state
subject; there is no untyped second event slot. MariaDB reads the reserved context family and SQLite
reads equivalent registered zero-argument functions. Both are cleared before/after, in `finally`,
and on connection checkout/return. A trigger atomically changes only the matching pending subject when witness, plan, actor,
proof, target kind, mutation kind, and every OLD/NEW value match. It requires unexpired DB time except
for the exact snapshot-bound post-review-origin owner resume after effects_started described above;
that branch requires matching coordinator, consumed witness, immutable snapshot and still-owned
authority/gate fences and cannot authorize any new action. Affected-row
count other than one aborts. No public controller/action receives or sets the bridge value.

The complete authority transaction locks in this order: installation authority row, protected role
and permission IDs in byte order, affected user IDs in numeric order, relevant pivot identities,
action plan/request/decision evidence, witness, then witness subjects in sequence order. It sets the
pending-witness fence, applies each guarded statement, verifies every expected subject consumed,
advances exact epochs/digests, inserts mutation and audit evidence, finalizes the witness, and clears
the fence before commit. The last guarded operation step binds the primary audit and finalizes its
trust projection; until then even the preinserted base event is not visible to normal audit readers.
Any service error rolls back all steps. A wrongly committed partial
transaction cannot become effective Vault authority because the uncleared fence makes every
expansion, approval, exception, and recovery decision fail closed; health, safe audit, and
separately reviewed incident recovery remain possible.

For a self-affecting authority mutation, freshness is validated once under the locked OLD
user/authority state before any write. The one-use witness stores actor_pre_auth_epoch, exact proof
UUID and authorized_at; the plan and witness subject bind the exact OLD-to-NEW epoch. Guarded DML
consumes that witness, advances the epoch, and terminally stales/revokes the proof. The resulting
mutation and audit may reference the now-stale proof only through that consumed witness as evidence
of pre-state freshness for this exact self-mutation. There is no general stale-proof exception.
Non-self paths still require the actor proof live at apply. Rollback restores epoch, proof state,
witness and all evidence; replay fails. This covers self TOTP confirmation and self role/permission
reduction without allowing a mid-transaction epoch change to erase its authorization provenance.

`user_management.id` UPDATE and every explicit DELETE of a user row are rejected unconditionally by
database guards in every Row04 phase. Nexum deactivates a user through the guarded exact status
transition; existing Eloquent, web, API, admin, command, or system-actor delete entry points must
reject or direct the operator to deactivation and may not receive a generic delete context.
Transaction rollback of an uncommitted insert remains legal. Any later retention/GDPR destruction
requires a separate approved RFC and migration protocol.

The canonical Admin and Superuser role identities and all six migration-managed Vault permission
rows are immutable by exact numeric identity: UPDATE of `id`, `name`, or `guard_name` and DELETE are
rejected. Their names and `guard_name='web'` are compared byte-for-byte under binary semantics;
collation folding, case-spoofed names, mutable cache state, or a recreated row never establishes the
protected identity. Direct user assignment of these Vault permissions is rejected in Slice 04; a
later direct-assignment model needs its own approved contract. Eligibility uses protected role IDs
plus exact effective permissions, not mutable role-name text alone.

Both-driver guards sit directly on the Spatie roles and permissions root tables as well as their
pivots. A primary-key update or delete on an otherwise unprotected root row also rejects whenever
its OLD/NEW identity is protected or any affected pivot is protected; no PK move can route a
protected pivot around the guard. Root rejection occurs before a MariaDB or SQLite FK cascade can
remove child pivots, and enforcement never assumes a cascading DELETE will fire child-table
triggers. Allowed nonprotected root/pivot changes still use the UserManagement security execution
whenever they affect an enumerated identity/security fact. Delete/reinsert ABA of a protected
identity is rejected.

- Authority expansion requires requester step-up. When the candidate roster contains another
  identity, a different governance-recovery-eligible candidate who is decision-ready for that exact
  plan must approve it. If the other candidate lacks the durable permission/TOTP eligibility or
  cannot present a fresh proof for the decision, expansion denies; it never falls through to
  sole-admin.
- A planned candidate-roster reduction is not an ordinary access reduction. When the pre-state
  roster is greater than one it requires approval by a different pre-state governance-recovery-
  eligible candidate who is decision-ready for that exact plan.
  The identity that would become sole admin cannot approve that same transition. A planned
  transition to zero candidates is rejected.
- A planned permission/TOTP/group reduction that does not shrink the candidate roster needs fresh
  step-up, typed reason, epoch invalidation, and audit but no second approval only when the locked
  post-state still satisfies required governance readiness. A planned transition below that floor
  denies until a replacement is ready.
- Remove then re-add always advances epochs and creates new authority evidence; it cannot revive a
  step-up, approval, or exception.

An authority.emergency_deactivate may proceed immediately even if it shrinks governance readiness
and the candidate roster. It requires a human actor whose persisted status is exactly `ACTIVE`, with a direct current user.update
decision, a fresh step-up proof, explicit
emergency_security_deactivation reason, and the exact target; it does not use a route/Admin fallback
or pretend that urgency is an approval. In that same transaction the guard stores exact before/after
roster and global-authority-facts digests, advances the installation and
affected-user epochs, invalidates all outstanding approvals/exceptions/step-ups, and sets a terminal
until-reviewed approver_quorum_lock. Its post-state must retain at least one human candidate whose
persisted status is exactly `ACTIVE`;
both driver guards reject candidate count zero. While locked, all expansion and every sole-admin
exception are denied; health, safe audit, and further reduction/deactivation that still retains one
candidate remain available. A further emergency deactivation while already locked uses only the
continued locked-to-locked state variant above; it preserves the original lock identity, incident
reason, cooling start, and recovery-not-before exactly.

quorum.peer_unlock requires a different pre-change governance-recovery-eligible candidate who is
decision-ready for that exact plan and
an exact plan. If no such identity remains, quorum.cooling_recovery may run only after a 24-hour
database-derived cooling period. It covers zero governance-recovery-eligible identities but never
zero candidates. It
requires current password plus current confirmed TOTP, or the special password+new-enrollment-code
proof when eligibility is zero, exact before/current/proposed candidate and governance-recovery-
eligible roster
digests, warning acknowledgement, typed reason, single-use evidence with at most five-minute expiry,
a mandatory post-review obligation, and append-only audit. It may only restore governance readiness
or the protected candidate roster; it can never create a Vault grant or another content expansion.
Zero candidates is a permanent fail-closed state for this slice and requires a separately approved
out-of-band UserManagement incident-recovery ADR before Vault recovery can start.

A narrow notifier port records attempted, succeeded, failed, or unsupported notification metadata
for quorum recovery without message content. It never claims delivery from an attempt, and missing
delivery cannot weaken cooling-off, step-up, lock, audit, or post-review controls. No new UI or
notification channel is introduced here.

Deployment distinguishes a genuinely empty fresh installation from an authority-bearing upgrade and
never reuses emptiness after state has changed. The exact fresh predicate is evaluated once under
locks: zero human or system user_management rows, zero roles, zero model_has_roles and
 model_has_permissions rows, zero relevant role_has_permissions pivots, no Row04 authority/evidence
 state other than the exact completed audit-trust cutover baseline, and neither new permission row.
 Unrelated permission-catalog rows and the four ungranted
foundation Vault permission rows are allowed. Any user, role, relevant pivot, new permission,
unjournaled partial Row04 state, or mismatched count makes this not fresh; it cannot be adopted by a
heuristic retry.

Only the final DML-only classification migration may create the installation-bound one-use
`vault_fresh_install_bootstrap_runs` projection when that pre-state is exact. It binds the canonical
empty-state digest, an immutable run UUID/classification, stage, revision, expected source pointers,
current canonical stage-snapshot digest, and DB timestamps. After every DDL object has independently
passed exact catalog/read-back verification, that single locked DML transaction creates the two new
permission rows and untouched dormant singletons without grants, then records the initial stage
`permissions_installed`. A matching append-only typed transition records the exact classification-
migration source, previous empty digest and locked new snapshot. If the DML transaction cannot
complete, it creates neither authority facts nor a reusable run; partial DDL alone is never
classification evidence.

The only later stages are `roles_installed -> first_superuser_created ->
first_superuser_totp_confirmed -> vault_control_plane_actor_ready -> provisioning_ready ->
completed`. Each RoleSeeder, `nexum:bootstrap-admin`, UserManagement TOTP-confirmation,
EnsureSystemActor, provision-command and completion-readback
step must name the same run and expected prior stage/revision, consume a one-use stage pointer, append
its exact typed transition/source evidence, recompute the DB-locked fact snapshot, and advance by one
CAS. A committed crash resumes only the same run at its exact expected next stage. Reordered calls,
skips, reverse/reset, a second run, generic cleanup, fabricated context, changed expected facts, or
unrelated intervening user/role/pivot/factor state fail closed and require documented operator
recovery; they are never silently adopted.

At `permissions_installed`, the ordinary PermissionSeeder must leave the two owned rows byte-equal.
RoleSeeder's existing one-time no-web-role bootstrap then runs only inside the actorless
`fresh_install_role_bootstrap` UserSecurity execution carrying the run pointer. It creates the
standard roles from precommitted standard_role_bootstrap subjects; each role AFTER INSERT binds its
generated numeric ID once before the fact snapshot records it. It then
grants the frozen table above to the newly created Admin and Superuser roles; Tech/Viewer remain at
zero Vault permissions. The local-console-only `nexum:bootstrap-admin` command uses the actorless
`bootstrap_admin` execution plus the same run pointer for the first human Superuser whose persisted
status is exactly `ACTIVE`. Beginning and confirming that user's TOTP remains a UserManagement
operation while dormant, but its confirmation may advance this exact run only for the persisted
generation and expected user.

Immediately after that exact confirmed-TOTP stage, the journal invokes UserManagement's existing
`EnsureSystemActor` through the actorless `system_actor_bootstrap` execution and advances only when
the exact persisted actor has deterministic key `vault-control-plane`, status `DISABLED`,
`is_system_actor=true`, initial epochs 1, no role/direct permission/TOTP/login capability, and the
same numeric user ID on locked retry. The stage transition stores its execution, actor pointer and
canonical system-actor fact. Missing, duplicate, conflicting, recreated, or differently identified
actors fail closed; a crash resumes that same execution/fact and never creates another actor.

Only after `vault_control_plane_actor_ready` may the CLI-only
`nexum:vault-provision-control-plane` command use `vault_control_plane_provision` plus the same run
pointer to validate the migration-managed role/permission pivots and live governance readiness. It
advances to provisioning_ready and then completed only after a second locked read-back. Completed is
terminal and means only that a later separately approved activation slice has a verified basis; it
does not enter the reserved control_phase `provisioning` or `enforced`. A rerun verifies the retained
completed facts and returns an exact no-op. The command has no HTTP/UI entry, never changes dormant
authority state or either runtime flag, and never provisions a content operation, secret, provider,
or key.

An existing authority-bearing installation never receives or consumes this fresh-install journal.
Before approval_decide exists its preflight must find at least one prospective governance-recovery-
eligible candidate: an `ACTIVE` human Admin/Superuser with confirmed TOTP, effective policy_manage,
and a protected role that the reviewed permission migration will later receive approval_decide
through the provision command. It locks exact numeric role/permission identities and pivots,
preserves every explicit removal of the four existing Vault grants, and rejects partial/conflicting
new-permission state. The dormant classification migration creates the two new ungranted permissions.
The existing-install path then invokes the same `EnsureSystemActor` implementation through one
`system_actor_bootstrap` UserSecurity execution and records the exact numeric actor and execution in
the typed, durable existing-install provisioning evidence below. That evidence is not a fresh-install
journal: its closed steps are only `vault_control_plane_actor_ready` and `provisioning_ready`, and
each `(installation_id, step)` identity is one-use. A committed building step resumes the same
execution/fact; finalized re-entry only verifies the same actor and cannot recreate it. The
provisioning-ready step must prove at least one actual governance-recovery-eligible identity by exact
locked read-back, and its finalized evidence is mutually bound to the dormant authority-state
pointer before success. Missing, duplicate, conflicting or malformed actor/evidence, a different
numeric actor on retry, or a partially existing step fails closed. Enforced-phase activation
later requires the full min(2,candidate-count) governance-recovery-eligible roster and a separately
reviewed transition. Scope/actor readiness may remain false while dormant. Any failed prospective,
actual, or re-entry check stops while Vault remains disabled; there is no zero-roster bootstrap
bypass and the journal is not a general installation engine.

### Quorum State One-Use Fence

Peer unlock and cooling recovery never write `vault_authority_state` merely because an application
service has reached the line of code. Under the installation lock, the service first precreates one
UUIDv7 `vault_quorum_mutation` in pending state. It binds the exact quorum plan and semantic
quorum-state subject, actor/proof, plan digest/expiry, locked before state, open after state, and the
closed authorization mode approval_required|recovery_authorization; recovery_authorization also
binds the already-existing exact authorization. Peer-unlock approval consumption is created only
after this pending mutation and points to it through its typed unique FK, so there is no reciprocal
future-row FK. The authority row then carries the exact
`pending_quorum_mutation_id`; every expansion, decision, exception, and recovery denies while that
fence is non-null except the same mutation's guarded continuation.

`VaultQuorumMutationContext` exposes mutation UUID plus closed stage install|apply|finalize to one
connection and is cleared in `finally` and on connection checkout/return. MariaDB uses reserved
session variables. SQLite uses registered zero-argument functions inside the IMMEDIATE write
transaction. Authority-state triggers permit exactly three changes: install only the matching
pending fence while all semantic fields stay byte-equal; apply the plan's exact locked-to-open
authority-state subject and atomically advance mutation pending to state_consumed; then clear only
the fence after required mutation/recovery/post-review/audit evidence exists and atomically advance
state_consumed to applied. Expiry, wrong stage/connection/actor/proof/plan/subject/before value,
replay, extra column change, or affected-row count other than one aborts.

quorum.peer_unlock uses this fence and no UserManagement authority witness. A
quorum.cooling_recovery uses the same fence and may additionally activate the exact authority
witness only when typed UserManagement/Spatie subjects change; both fences, all subjects, the open
state, mutation/recovery evidence, notification outcome, post-review obligation, and audit must
finalize in the same transaction. Any error or process failure before commit rolls everything back.
A wrongly committed non-null fence remains fail-closed and cannot be replayed or silently cleared;
only health/audit and a separately reviewed incident recovery remain possible.

## UserManagement Credential Verifier

UserManagement owns VerifiesVaultStepUpCredentials. Its standard method receives only the persisted
actor ID and #[SensitiveParameter] presented password/TOTP. It loads and decrypts the confirmed
stored TOTP secret internally, verifies the current password, and checks a six-digit TOTP in a tight
plus/minus one 30-second counter window. Its separate enrollment method receives actor ID and the
presented password/code, then loads the exact pending TOTP generation from the locked
UserManagement row. No request or caller asserts which generation is current; an opaque challenge
reference may only locate server state and must constant-time match the persisted generation. The
method cannot use a confirmed-secret precondition. Neither method may call User::verifyTwoFactorCode.
Laravel Crypt may be used only inside this UserManagement verifier for existing encrypted
TOTP/recovery fields, never as Vault key/material cryptography.

The verifier atomically accepts only a counter greater than vault_totp_last_accepted_counter and
updates that counter under the user lock. It returns a closed typed result containing success or a
safe reason, proof_kind step_up|totp_enrollment, actor ID, accepted counter when successful,
auth_security_epoch, pending-generation identity only for enrollment, and verification time.
It never returns or exposes password hash, TOTP secret, recovery material, presented values, or a
derived offline verifier. Raw locals use sensitive parameters and best-effort zeroization.

## Vault Step-Up Contract

- VAULT_STEP_UP_TTL_SECONDS is an integer with default 600 and allowed range 60 through 900.
  Missing, boolean, float, malformed, zero, negative, or out-of-range values fail closed. The
  verifier captures `verified_at` from DB time and a successful proof stores exactly
  `expires_at = verified_at + VAULT_STEP_UP_TTL_SECONDS` using the checked UTC `DATETIME(6)`
  arithmetic above; proof-row creation/finalization time is not a second expiry base.
- One combined attempt supplies password and confirmed TOTP. A failure never reveals which factor
  failed and counts once against both applicable rate buckets.
- The verifier may return a closed internal `VaultStepUpDenialReason` for rate enforcement, durable
  typed audit, and privileged diagnostics. That value is sensitive, non-serializable, and may never
  cross a controller, route, API/MCP response, exception message, log, trace, session flash, or
  redirect. Only the typed trusted-audit/privileged-diagnostic repository may read it, and neither
  repository exposes a secret or presented factor.
- One external adapter maps invalid credentials, invalid/unconfirmed TOTP, replay, rate limit,
  lockout, epoch drift, and missing/invalid context to the identical public result
  `step_up_failed` with byte-identical HTTP status, body, message, redirect and JSON shape. It
  exposes no internal reason, retry count, counter, factor, or lock detail. The verifier may
  best-effort equalize work, but this slice does not claim a provably constant-time HTTP boundary.
- Five failed combined attempts in a rolling 15-minute actor-plus-session-digest window or 20 in a
  rolling 15-minute actor window creates a 15-minute lockout. Counters and locks use DB time and
  locked rows so concurrent workers cannot undercount.
- When an applicable lock is active at attempt start, the boundary skips password/TOTP verification,
  does not read or advance the TOTP replay counter, creates no proof, inserts no rate-failure row,
  and does not extend `locked_until`. It still writes one durable denied attempt/audit with internal
  reason `rate_limited` and factor-evaluation `skipped`; the external response remains the same
  `step_up_failed`. At `DB_UTC_NOW >= locked_until` normal evaluation resumes and existing failure
  rows participate only through the exact rolling-window predicate.
- A successful server-side proof is bound to actor, SHA-256-sized Laravel-session digest,
  auth_security_epoch, the locked installation authority_epoch, accepted TOTP counter, verified_at,
  and derived expires_at.
- A proof is reusable only within the same Laravel session digest and unchanged actor-security plus
  installation-authority epochs before its
  derived expiry. It satisfies freshness only; it never authorizes content or a mutation alone.
- Proof state is active or terminal revoked. Expiry is derived, not a mutable state. Before a new
  proof can occupy the active slot, any expired or stale active row is locked, terminally revoked
  through its own append-only proof-transition source/evidence commit and audited in the same
  transaction. The proof-creation commit/audit is never reused for that later lifecycle event.
  Logout, session rotation, either bound epoch changing, deactivation, loss of confirmed TOTP,
  explicit revocation, or expiry denies. An emergency/global authority mutation advances the
  installation epoch and thereby makes every earlier proof stale; each stale active proof is still
  locked and terminally revoked before its slot is reused.
- Recovery codes and WebAuthn/passkeys are not accepted. They require separately approved work.
- The presented-value call path is synchronous and trusted-internal only; no queue, event, job,
  model, cache, session payload, audit record, dump, trace, or log may receive those values.
- Slice 04 has no HTTP caller for this service and cannot enter enforced phase. A later activation
  slice must add a UserManagement-owned stateful-session modal/challenge to existing protected
  mutation surfaces. It must locate the server-side proof from current actor+session digest without
  accepting a proof ID from the client, put every factor field in dontFlash, and reject bearer-token
  API mutations. Until then, dormant phase leaves the existing surfaces epoch-tracked but not
  falsely proof-gated.

## Candidate, Eligibility, And Decision Readiness

The durable sole-admin candidate roster is computed independently of Vault permission, confirmed
TOTP, login, Laravel session, or step-up. It contains every distinct current human user whose
persisted status is exactly `ACTIVE` and who has the protected Admin or Superuser role identity.
System/workload users and every other status are
excluded. Role/status changes, not readiness changes, grow or shrink this roster.

An approval-eligible identity is a candidate with effective live mapped `vault.approval_decide`,
confirmed TOTP, and the relevant company scope or exact Client visibility. It is an eligibility fact,
not proof possession: no active session or step-up is required to count it. `vault.policy_manage` is
deliberately not part of ordinary graph/grant approval eligibility. A decision-ready actor is the
operation's required eligible identity plus a fresh active, non-revoked, same-session/actor/auth-
epoch and installation-authority-epoch-bound step-up proof referenced by the exact plan, request and
concrete decision. Standard step-up is reusable until TTL expiry or revocation and has no consumed
state; only a `totp_enrollment` proof is one-use and consumed. Global and exact scope
authority facts are locked and re-read before decision and consumption. Any decision-ready approval-
eligible identity distinct from requester may decide an ordinary graph/grant request; no policy
permission is smuggled into that rule.

Governance recovery uses a separate, stricter concept. A governance-recovery-eligible identity is an
approval-eligible candidate that also has effective live `vault.policy_manage`. Quorum, readiness-
floor, cooling and recovery counts use this eligible set and never depend on whether anyone happens
to hold a current proof. Such an identity becomes decision-ready for a policy, quorum-unlock or
recovery operation only with that operation's normal fresh proof. The persisted historical
`governance_ready_roster_digest` and `governance-ready-roster` canonical family encode this durable
company/global governance-recovery-eligible roster (no login, session, fresh proof, or Client
lookup); a client-scoped decision still re-evaluates exact Client visibility. The historical schema
name does not mean a proof is active. Required governance-recovery eligibility is
min(2,candidate_roster_count). A planned policy/readiness change that leaves fewer such identities
than that value denies until a replacement is eligible. A planned reduction from more than one to
one also requires a different pre-state governance-recovery-eligible candidate who is
decision-ready for the exact plan.

If candidate count is greater than one but no different candidate is approval-eligible for an
ordinary request, expansion denies until another is eligible and can become decision-ready for that
decision; it never becomes a sole-admin case. The sole-admin
exception is available only when the locked candidate-roster count is exactly one, that identity is
requester, and requester is decision-ready with the mapped approval eligibility. Candidate roster
zero always denies. Exact policy-governance, quorum-unlock and recovery operations require a
governance-recovery-eligible identity who is decision-ready for that exact operation instead.

The installation `vault_authority_state.global_authority_facts_digest` is always the exact
company/global family: installation, authority epoch, candidate identities, protected role/
permission facts, per-user vault_authority_epoch values, and governance capability only. It contains no Client ID,
Client visibility, session, or fresh-proof fact. A client-scoped graph plan stores its separate
scope_authority_facts_digest and the decision service recomputes Client visibility live under the
Client, user, and protected Spatie locks. Neither digest may substitute for the other; the scope
anchor remains structural graph truth only.

Before enforced phase, ordinary UserManagement TOTP enrollment/confirmation remains available under
its own epoch-tracked security boundary. Once enforced, initial confirmation uses a single-purpose
totp_enrollment proof: current password plus the code from the exact pending generation, the same
rate/leakage controls, a maximum five-minute lifetime, and a closed purpose_operation. The only
  purposes are authority.totp_confirm for the same actor's factor or quorum.cooling_recovery when the
  locked roster has zero governance-recovery-eligible identities and recovery must restore that
  actor's factor. A proof
instance is bound to exactly one purpose and cannot cross-use or satisfy normal step-up. The plan
binds proof_kind, proof ID, pending-generation UUID and accepted counter, never the code or secret.
With multiple candidates, authority.totp_confirm consumes an exact preapproved enrollment-generation
plan decided by another governance-recovery-eligible candidate who is decision-ready for that exact
plan. With one candidate it uses a typed
totp_enrollment_exception, warning acknowledgement, one-use application and mandatory post-review;
that exception can confirm only the requester's pending factor for authority.totp_confirm and can
never authorize quorum recovery or a grant. quorum.cooling_recovery instead uses only its dedicated
quorum plan and recovery authorization, even when its requester proof_kind is totp_enrollment.

## Approval Request And Lifecycle State

VAULT_APPROVAL_TTL_MINUTES defaults to 30 and accepts only integer values from 5 through 60. Invalid
configuration denies request creation. A request is immutable and binds a versioned,
length-prefixed SHA-256 plan digest over requester/session/epoch, closed operation, canonical scope,
all subject/target IDs, proposed safe values, typed reason, before/after reachability, scope/group/
collection epochs and set digests, candidate-roster digest, global- and scope-authority-facts
digests, creation time, and expiry.

Each immutable request has exactly one mutable
`vault_approval_request_lifecycle` projection. It is initialized pending in the request transaction
and has the closed states pending|approved|rejected|cancelled|expired|invalidated|consumed. The
projection, not a precedence guess across independently inserted evidence tables, is authoritative.
The only transitions are pending-to-approved by exactly one independent approval XOR sole-admin XOR
TOTP-enrollment exception; pending-to-rejected; pending|approved-to-cancelled;
approved-to-consumed; and pending|approved-to-expired|invalidated. Rejected, cancelled, expired,
invalidated, and consumed are terminal. Cancellation is allowed only by the requester while pending
or approved-but-unconsumed; it can never overtake a completed consumption.

Every trusted standalone source crosses one uniform `vault_evidence_commits` gate. The service
preallocates source and audit UUIDv7 values, inserts a building commit bound to closed source kind/id
and exact expected event_type/outcome, then inserts the source referencing that commit and exactly
one typed audit row referencing both commit and now-existing source. audit.evidence_commit_id and
source.evidence_commit_id are independently UNIQUE. A finalizer verifies exact source, audit type,
outcome, reason and required/forbidden references before changing building to finalized. Consumers
and DB triggers accept only a finalized commit whose exact audit-trust projection is also finalized
and whose owner-specific postcondition holds. A committed building row is inert and may only be
terminally aborted/audited by a separate finalized cleanup commit; it is never repaired into,
reused as, or retroactively treated as trusted evidence.

The sole deferred-trust branch is auth_security_invalidated inside the exact gate-owning
UserSecurity execution. Its per-proof source commits may finalize while their audit-trust rows remain
pending so the proof revocations can complete before the aggregate execution. The UserSecurity
finalizer then verifies the complete precommitted transition set, finalizes those trust rows, marks
the execution finalized and clears its gate LAST in one transaction. No consumer accepts the source
or audit during that interval; no other source kind may defer trust.

VaultEvidenceCommitContext carries the commit, closed source kind/ID, preallocated audit event,
closed stage source|audit|reserve|finalize|abort, and, when the source changes a projection, the exact
projection kind/ID, expected revision/state, target state, reservation commit ID and expected
last-evidence identity. This is the sole approval/obligation/finding lifecycle context; no separate
or implicit lifecycle context/mapping exists. After creating a building commit, its
`reserve` stage performs one null-safe CAS on the locked projection, setting the pending commit and
target while leaving current state unchanged. Its `finalize` stage byte-checks the exact source and
matching pending audit/trust row, applies the closed transition, increments revision once, records
transition time and last-evidence kind/ID, clears the reservation, and finalizes that audit trust as
the last guarded write. MariaDB uses the reserved evidence context family
cleared on checkout/return; SQLite uses the equivalent registered zero-argument functions inside the
IMMEDIATE transaction. Affected-row count other than one signals/raises and rolls back.

This reservation serializes every cross-table race: approval versus rejection, decision versus sole
exception, rejection versus cancellation, cancellation versus consumption, expiry or invalidation
versus any other transition, and duplicate/replayed evidence. No specialized evidence row may be
inserted without the exact building commit/reservation/context, and no transition may exist without
its finalized typed source+audit. Rollback restores source, audit, commit, reservation, lifecycle
revision and domain state together.

The service locks the request and lifecycle row, then re-evaluates live permission, TOTP, Client
visibility, candidate membership, canonical digests, target state and DB time before installing
context. Those live facts are never falsely delegated to a DB trigger. Any bound drift transitions
an eligible pending/approved request to invalidated through typed evidence; DB-time expiry does the
same to expired. Changed plans create new requests; the immutable request row is never updated.
requester_step_up_id is recorded on every plan/request whose creation required freshness, and
executor_id must equal requester_id with the current exact proof at consumption.

## Sole-Admin Exception And Post-Review

The ordinary sole-admin exception has maximum TTL 5 minutes and can never outlive its request. It
requires the exact candidate-roster count/digest of one, requester equal to the only candidate, fresh
step-up, typed reason, explicit warning acknowledgement, exact plan digest, and no quorum lock.

Sole-admin evidence, its post-review obligation, and post-review evidence are separate immutable
source records behind evidence commits. An exception/recovery origin uses one staged composite
finalizer because immediate FKs cannot point to future applied records. Under all normal domain
locks, the service first creates a post-review-origin execution in `assembling` without claiming the
global post-review gate. It precreates the pending typed graph execution, authority witness, or
quorum mutation, the building approval-consumption or recovery-authorization-consumption source,
and the building obligation source. Each child points to the coordinator and exact preallocated IDs;
the obligation points to the existing pending operation plus exactly one existing building
consumption source. After the obligation exists, a one-time pending-only update binds
operation.required_obligation_id back to it. Only after every inert child and reciprocal binding is
present does one guarded transaction lock the complete set, claim
post_review_gate.pending_origin_execution_id, and CAS the coordinator assembling-to-claimed. A crash
while assembling owns no global/domain allow state and may be cleaned only by the stage-aware whole-
origin abort protocol over whatever inert subset exists; no child can be finalized or consumed.
Claimed therefore always means complete assembly, and no effects may start before that claim.

The origin coordinator advances only through assembling, claimed, effects_started, obligation_finalized,
consumption_finalized, and completed. Immediately before its first target/evidence subject, one
guarded CAS revalidates the exact plan, proof, Client, authority and gate under their locks, stores
the immutable authorization snapshot and effects_started_at, consumes the exact pre-state witness,
and moves claimed-to-effects_started. From that point recovery is resume-only and abort is forbidden.
Later proof/request/plan expiry or actor auth-epoch drift cannot strand the exact snapshot-bound
action and is not reinterpreted as freshness for any other action; completion uses only the consumed
witness and exact-ID CAS. Guarded domain DML runs while the scope/authority/quorum fence, origin execution and global
gate all remain pending, so every decision service denies their intermediate state. The service
preinserts only the operation, consumption and obligation audit events; each audit INSERT trigger
atomically creates its own pending trust projection. In `post_review_origin_mode`, the
obligation-created audit and commit may finalize against the exact effects-complete but still-
pending/fenced operation, building lifecycle-reserved consumption or recovery transition,
coordinator and gate pointer; it does not require an applied operation or finalized consumption.
The obligation commit/open lifecycle finalizes first. The approval-consumed or recovery-
authorization-consumed audit and commit then finalize against that exact finalized open obligation
while the operation remains pending/fenced. Ordinary approval consumption outside this mode
continues to require its operation already applied.
The typed operation becomes applied LAST. That one finalizer requires the finalized open obligation,
finalized consumption, exact reciprocal IDs/audits/gate pointer and complete subjects; it binds the
primary operation audit, applies/clears the scope/authority/quorum fence, advances the post-review
gate epoch/digest/counts, clears pending_origin_execution_id, and finalizes the origin execution.
Any commit or crash after an earlier stage leaves the operation ineffective and the global plus
domain fence fail closed; effects_started and later may only resume to completion. An assembling
origin or a complete claimed origin may instead be aborted only before any target/evidence subject
is consumed through the typed origin transition below. Generic evidence cleanup may not detach an
origin child: assembling and claimed cleanup is whole-origin only, and effects_started or later is
exact resume-only. For sole-admin and
TOTP-enrollment exceptions the consumption identifies the exception actually used. Cooling recovery
uses its recovery-authorization consumed transition, never an approval-consumption alias. Approval
lifecycle makes one finalized approval consumption effective. Post-review evidence requires
vault.approval_decide and fresh step-up; it records only reviewer, typed outcome/reason, reviewed
digest, and time. It never edits the exception or obligation.

VAULT_POST_REVIEW_DUE_HOURS defaults to 24 and accepts only integer values 1 through 72. Invalid
configuration fails closed before sole-exception, TOTP-enrollment-exception, or quorum-recovery
consumption. due_at is the DB-time consumption timestamp plus that value. An obligation has
origin_type approval_consumption|recovery_authorization_consumption, exact typed consumption FK XOR,
and exact pending graph-execution XOR authority-witness XOR quorum-mutation FK. For
approval_consumption, the building row must identify exactly one sole-admin or TOTP-enrollment
exception; an ordinary independent decision cannot create this obligation. Recovery authorization
consumption must identify the same pending cooling-recovery quorum mutation.
Finalized evidence is a sequence of finding, remediation, and confirmed records; multiple immutable
source rows may reference one obligation. resolves_evidence_id on an immutable remediation source is
deliberately not UNIQUE because a crashed building source must not reserve a finding forever. Each
finalized finding owns one mutable lifecycle projection; remediation reserves that projection and
its evidence-commit finalizer alone CASes open-to-remediated. The obligation lifecycle projection,
rather than a source-level terminal slot that an aborted building commit could occupy, permits
exactly one finalized terminal confirmation. Confirming is allowed only when every finalized finding
projection is remediated.

The service locks the installation-wide post-review gate and then the obligation before every
evidence insert; MariaDB uses locking reads and SQLite uses the enclosing IMMEDIATE transaction. A
remediation must be the next sequence number and
reference one earlier open finding in the same obligation. It reserves both obligation sequence and
finding revision under the same commit/context; abort clears both reservations. Confirmation locks
all finding projections and must be the next sequence number. Application checks and both driver triggers reject every finding, remediation, or
second confirmation after a confirmed row exists, including concurrent races. A confirmed
obligation is permanently terminal and cannot be reopened, updated, or deleted. Overdue-observation
finalization requires the lifecycle still open and DB time at or after due_at under both the
obligation and gate locks; a concurrent confirmation and overdue observation share one reservation
CAS, so exactly one wins and the loser cannot later finalize.

One retained installation-wide post-review gate serializes obligation creation/evidence and every
access-expanding graph, authority or quorum finalizer. Every action plan binds its locked gate epoch
and blocker-set digest. Before an expansion, the service locks the gate and queries the complete
indexed set of open obligations and finding lifecycles at authoritative DB time; it never trusts the
cached blocking_count alone. Any unconfirmed obligation, and any unresolved finding, blocks expansion
and new sole-admin exceptions immediately; due_at is the review SLA/overdue marker, not the onset of
the block. If DB time has newly crossed due_at, the operation first commits the one-shot typed
overdue-observation evidence/audit and updated gate snapshot, then denies and requires a fresh plan.
Obligation/evidence finalization recomputes the same canonical set under the gate lock. A one-use
typed pending pointer plus connection context fences each gate update, and the gate epoch advances
exactly once even when only overdue classification changes. This prevents cross-scope
obligation-insert-versus-expansion write skew on both drivers. It never rolls back an already
completed mutation. No code may mark post-review complete merely because a notification was
attempted, tests passed, cached counts were zero, or time elapsed.

`VaultPostReviewGateContext` is normative. It carries the exact installation key, pending kind and
ID, stage `claim|finalize|abort`, before and after gate epoch, blocker digest, all four counts and
pending-pointer values, plus the exact subject/execution ID. MariaDB exposes only reserved
`@nexum_vault_post_review_*` session variables; SQLite exposes equivalent registered zero-argument
UDFs inside its IMMEDIATE transaction. Each trigger byte-checks the complete context, requires one
affected row, and consumes one stage once. The wrapper clears every field before setting context,
after the one affected statement, in `finally`, and again on connection checkout and return.
Missing, stale, replayed, wrong-stage, cross-connection, exception-left, or pool-reused context
denies without changing the gate.

### Database Fence Context Registry

The following registry is exhaustive; no generic context key or cross-family alias exists:

| Context | Exact carried identity | Closed stages |
| --- | --- | --- |
| UserSecurityMutationContext | installation, gate revision, execution, current subject, expected OLD/NEW row or epoch, optional UNIQUE authority witness, exact owned proof-transition set, and mapped primary audit event/trust when required | claim, mutate, epoch, finalize, abort |
| VaultAuthorityWitnessContext | installation, witness, current subject, UserSecurity execution when applicable, authority mutation, primary audit event/trust, and emergency root event when applicable | subject, state, finalize, abort |
| VaultEvidenceCommitContext | installation, commit, source_kind/source_id, audit event/trust, exact approval/obligation/finding projection kind/ID, reservation commit, expected revision/state and target state when applicable | source, audit, reserve, finalize, abort |
| VaultAuditEventInsertContext | installation, preallocated event UUID, closed owner_kind, exact typed owner/source ID, expected event type/outcome/reason, actor/proof/ref shape and pending trust state | insert |
| FoundationAuditTrustBackfillContext | installation, cutover UUID, expected header stage/revision, base numeric audit ID plus event UUID, exact manifest count/digest, validated foundation event type/outcome/reason/ref shape and desired trust state | header_create, manifest_item, manifest_finalize, trust_backfill, stage_advance |
| VaultGraphExecutionContext | installation, execution, current claim/target/finalize subject, scope key, graph mutation and primary audit event/trust | claim, target, finalize |
| VaultQuorumMutationContext | installation, mutation, exact state subject, consumption/recovery transition, obligation when required, and primary audit event/trust | install, apply, finalize |
| VaultPostReviewGateContext | installation, pending kind/ID, subject/execution, complete before/after gate snapshot | claim, finalize, abort |
| VaultFreshInstallBootstrapContext | installation, bootstrap run, transition, source kind/typed source ID, exact from/to stage, expected revision, before/after snapshot digests and current fact subject | claim, fact, finalize, resume |
| VaultExistingInstallProvisioningContext | installation, exact step, evidence row, expected prior step, UserSecurity execution, system actor, authority-state pointer and locked before/after read-back | claim, finalize, resume |

MariaDB uses only the reserved families `@nexum_user_security_*`,
`@nexum_vault_authority_*`, `@nexum_vault_evidence_*`, `@nexum_vault_audit_*`,
`@nexum_vault_graph_*`,
`@nexum_vault_quorum_*`, `@nexum_vault_post_review_*`,
`@nexum_vault_fresh_bootstrap_*`, and `@nexum_vault_existing_provision_*`; the last two families are
used only by their respective dormant deployment provenance.
The migration-only trust cutover additionally reserves
`@nexum_vault_foundation_trust_backfill_*`, which is unavailable after the permanent direct-insert
guard is installed. Its one-use stage/row identity is consumed by the temporary trust guard or
cutover-header CAS for exactly one statement; no wrapper assertion substitutes for that database
guard.
SQLite registers an exact zero-argument
read UDF for every corresponding field plus a family-specific consume UDF; the consume UDF succeeds
once for the current statement only. Every wrapper clears its complete family before set, requires
affected-row count one, consumes/clears after the statement, clears in `finally`, and clears again
on connection checkout and return. Exceptions before or inside a trigger, rollback/retry, pooled
reuse, stale values, wrong family/stage and cross-connection use all deny. Controllers, jobs, and
callers cannot provide these values.

SQLite never relies on Laravel's ordinary `DB::transaction`, which begins DEFERRED in the supported
Laravel/PDO runtime. Every top-level Vault fence runs through `VaultImmediateTransaction`; the
UserManagement security boundary uses the equivalent `UserSecurityImmediateTransaction`. Each owns
one PDO connection, rejects nesting, executes exact `BEGIN IMMEDIATE TRANSACTION`, performs its
context-bound work, executes COMMIT only after finalization, and issues a guarded ROLLBACK on any
failure. File-backed SQLite tests use two independent PDO connections and prove the second writer
blocks or fails until the owner commits. The same wrappers clear every UDF context before checkout
and after commit/rollback; no configuration flag or in-memory single-connection test substitutes.

## Exact Persistence Contract

All new tables use InnoDB on MariaDB and equivalent SQLite guards. Every Slice 04-owned graph,
plan, proof, execution, mutation, approval, exception, witness, and evidence ID is an
application-generated canonical lowercase RFC-4122 UUIDv7 with RFC variant, byte-exact collation,
and SQLite TEXT storage-class checks. Foundation/external UUID FKs retain their owning schema's
validated contract. Query-builder inserts call and validate `Str::uuid7()` explicitly. Digests are
exactly 32 binary bytes. All temporal columns use the canonical UTC contract above: MariaDB
`DATETIME(6)`, SQLite 26-byte TEXT in a declared DATETIME column, explicit values, and no implicit
default or ON UPDATE. Every actor/user FK references user_management with RESTRICT; Client FKs reference
clients with RESTRICT. Every scope repeats and enforces the foundation rule: company=(scope_id 0,
client_id NULL), client=(scope_id=client_id>0).

Every persisted Slice 04 epoch, replay counter, sequence and referenced numeric identity is an
integer from 0 through `PHP_INT_MAX` (9223372036854775807) on both drivers; fields whose semantic
minimum is one retain that stricter minimum. MariaDB uses BIGINT UNSIGNED plus an explicit upper
CHECK. SQLite requires `typeof(value)='integer'` plus the same range and rejects REAL coercion.
An exact +1 or monotonic-CAS transition from max-1 to max is allowed; any transition beyond max
fails closed with `vault_epoch_exhausted`, never wraps, resets, saturates, or changes storage class.
Any authoritative user, role, permission or Client numeric ID above this bound makes Slice 04
schema/governance readiness fail closed. The canonical encoder's full-UINT64 capability and vectors
remain a protocol property and do not broaden persisted database values.

`vault_installations` is the DDL-only, non-secret parent for installation-bound Row04 records. It
contains only `installation_id` (canonical UUIDv7 primary key; MariaDB `CHAR(36)` with ASCII/binary
collation and SQLite TEXT with UUIDv7/storage-class checks) and explicit canonical `created_at`
`DATETIME(6)`. It contains no generator, phase, authority, runtime, credential, or business state.
The bounded cutover DML validates the configured `VAULT_INSTALLATION_ID`, creates or byte-verifies
exactly one row before creating the first FK-dependent cutover header, and rejects missing,
malformed, duplicate, or conflicting state. Every installation singleton/header uses RESTRICT.

The canonical protected non-login UserManagement system actor for Row04 is `vault-control-plane`,
created only through the existing `EnsureSystemActor` flow with its deterministic system key,
`is_system_actor=true`, persisted status exactly `DISABLED`, no roles or direct permissions, and no login capability.
It is never a candidate, approver, requester, or human origin. Readiness requires that exact inert
identity. Deterministic cleanup and overdue observation use it only with
`executor_kind=system_cleanup`; the originating human remains in its own typed source column. A
missing or malformed actor makes the source transaction fail closed and is never repaired ad hoc.

The additive migrations introduce these exact records and invariants:

- user_management adds independent auth_security_epoch and vault_authority_epoch UNSIGNED BIGINT
  NOT NULL DEFAULT 1 on MariaDB (INTEGER NOT NULL DEFAULT 1 on SQLite), with explicit integer
  1..PHP_INT_MAX CHECKs and bounded existing-row backfill to 1, plus
  vault_totp_last_accepted_counter UNSIGNED BIGINT NULL, and
  two_factor_generation_id canonical
  UUIDv7 NULL with a RESTRICT FK to its same-user retained generation. Security-field guards require exact
  auth_security_epoch+1; authority-fact guards additionally require exact vault_authority_epoch+1.
  The verifier-owned counter is an independent monotonic CAS that may jump forward and does not
  itself advance either epoch. None may decrease or reset. Non-null
  two_factor_secret requires a pointed pending|confirmed same-user generation; NULL secret requires
  a NULL pointer. Replacement moves the pointer atomically, confirmation preserves it, and disable
  clears all factor fields/pointer. A bounded migration backfills retained metadata for existing
  non-null secrets without reading secret values.
  Candidate status/classification, protected role/permission, and TOTP readiness/generation changes
  advance vault_authority_epoch exactly once through the UserManagement fence in dormant and the
  coupled Vault witness in enforced; password/recovery/session-only changes do not. Exhaustion uses
  the common vault_epoch_exhausted rule and rolls back every coupled write.
- user_two_factor_generations is UserManagement-owned retained metadata: id UUIDv7, user_id,
  state pending|confirmed|superseded|disabled, nullable replaces_generation_id same-user,
  source migration_backfill|runtime, created_at, confirmed_at, terminal_at, and terminal reason.
  Insert is pending except the bounded
  existing-confirmed backfill; only pending-to-confirmed, pending|confirmed-to-superseded, or
  pending|confirmed-to-disabled is allowed. State/provenance transitions are guarded, row delete/
  restore/reassignment rejects, and Vault enrollment-proof/plan/evidence FKs use RESTRICT.
- user_security_mutation_gate is the UserManagement-owned installation singleton: installation_id
  primary/FK, revision integer >=1, nullable pending_execution_id UNIQUE FK, and updated_at. Migration
  seeds exactly one baseline row with revision=1, pending_execution_id=NULL and canonical UTC time.
  The execution exists before claim, but no protected subject may be consumed until one exact
  connection-context claim installs its ID. Every new/unrelated Row04 Vault consumer denies while
  non-null; only the exact matching precommitted owner-resume/finalizer context may progress. The
  finalizer verifies complete subject/epoch consumption, increments revision once and clears the
  pointer in the same guarded CAS; abort may clear only a still-owned claimed execution with zero
  consumed subjects/DML/epochs. DELETE,
  restore, unguarded update, a second claim and exhaustion reject. Missing, duplicate or malformed
  baseline fails readiness; ordinary UserManagement login does not consult this gate.
- vault_fresh_install_bootstrap_runs is a deployment-journal projection, not runtime authority:
  installation_id primary/RESTRICT FK, run_id UUIDv7 UNIQUE, classification fixed fresh_install,
  empty_state_digest BINARY(32), stage permissions_installed|roles_installed|
  first_superuser_created|first_superuser_totp_confirmed|vault_control_plane_actor_ready|
  provisioning_ready|completed, revision
  integer 1..PHP_INT_MAX, current_snapshot_digest BINARY(32), nullable pending_transition_id UNIQUE,
  nullable last_transition_id UNIQUE, nullable role_seeder_execution_id/bootstrap_admin_execution_id/
  totp_confirmation_execution_id/system_actor_execution_id/provision_execution_id typed RESTRICT
  FKs, nullable first_superuser_id, totp_generation_id and system_actor_id typed RESTRICT FKs,
  created_at, updated_at and nullable
  completed_at. The migration may create exactly one row only from the canonical empty-state
  predicate. Insert order is cycle-free: create the run with pending/last transition NULL inside the
  migration transaction, insert the initial transition FK to that run, then the same context-bound
  CAS initializes revision=1/stage=permissions_installed and binds last_transition_id before commit;
  no reader accepts a run whose last transition is NULL or not finalized. Each later stage CAS increments revision by one, updates the canonical snapshot and
  fills only its expected pointer; completed requires all pointers non-null and exact. No second row,
  reset, delete, restore, skip, reverse, pointer replacement or use as control_phase is legal.
- vault_fresh_install_bootstrap_transitions is append-only typed evidence: id UUIDv7, run_id FK,
  from_stage nullable only for the migration initial transition, to_stage, expected_revision,
  source_kind permission_migration|role_seeder|bootstrap_admin|totp_confirmation|system_actor_ready|
  provision_command|completion_readback, nullable user_security_execution_id/superuser_id/
  totp_generation_id/system_actor_id with the
  exact source-kind XOR, before_snapshot_digest, after_snapshot_digest, state building|finalized|
  aborted, nullable finalized_slot, created_at, and terminal time/reason. finalized_slot=1 exactly
  when state=finalized and NULL for building|aborted; UNIQUE(run_id,to_stage,finalized_slot) selects
  one finalized winner while crashed building/aborted attempts do not occupy the winner. A
  transition is precreated building, claimed by run.pending_transition_id, and finalized
  only by a stage CAS that verifies the locked database snapshot, exact source and expected previous
  digest. A committed building attempt is inert and can resume only the same stage/source; it cannot
  be generically adopted, retargeted or used by a second run.
  The source-kind matrix is exhaustive: permission_migration is the NULL-from initial transition
  and forbids all three nullable source IDs; role_seeder requires only a finalized
  fresh_install_role_bootstrap UserSecurity execution; bootstrap_admin requires a finalized
  bootstrap_admin execution plus its generated superuser and forbids generation; totp_confirmation
  requires its finalized UserSecurity execution, that same superuser and exact retained generation;
  system_actor_ready requires only its finalized system_actor_bootstrap UserSecurity execution and
  the exact canonical disabled system actor, and forbids superuser/generation;
  provision_command requires the finalized vault_control_plane_provision execution, same superuser
  and generation plus the exact system actor; completion_readback reuses that exact already-finalized
  provision execution and same identities as read-only provenance, writes no security subject, and requires the immediately
  preceding provisioning_ready transition. Every other NULL/non-NULL combination, flow kind, actor,
  source reuse at another stage, or mismatched run pointer denies.
- vault_fresh_install_bootstrap_facts is retained append-only typed stage evidence: id UUIDv7,
  transition_id FK, sequence, fact_kind and the exact nullable columns/NULL matrix of
  `nexum.vault.entry.fresh-install-bootstrap-fact.v1`, created_at, and UNIQUE transition+sequence.
  A finalizer recomputes the stage snapshot from the complete sorted facts and locked live rows; no
  credential, secret, email, free text, JSON or value-derived hash is stored.
  These journal transitions/facts are deployment provenance, not a Vault authorization source or an
  event in the runtime audit matrix; they deliberately do not use vault_evidence_commits before a
  system actor exists. Only the bootstrap migration/seeder/CLI readers accept them, and only when the
  projection, finalized winning transition, canonical facts and locked live snapshot all agree.
  PDP, approval, proof, audit export and control_phase readiness never treat the journal as authority.
- vault_existing_install_provisioning_evidence is narrow retained deployment provenance and never a
  fresh-install journal: id UUIDv7, installation_id RESTRICT FK, step exactly
  vault_control_plane_actor_ready|provisioning_ready, UNIQUE(installation_id,step), nullable
  previous_step_id RESTRICT FK required only for provisioning_ready, system_actor_id RESTRICT FK,
  system_actor_execution_id RESTRICT FK, nullable provision_execution_id required only for
  provisioning_ready, authority_state_installation_id RESTRICT FK fixed equal installation_id,
  state building|finalized, revision fixed 1, created_at and finalized_at. Actor-ready requires the
  exact finalized system_actor_bootstrap execution and canonical actor; provisioning-ready requires
  that same actor/execution, the finalized actor-ready row and finalized
  vault_control_plane_provision execution. Building rows are inert but permanently own their unique
  step so crash recovery must resume the same exact IDs; they cannot be replaced, aborted, adopted or
  reclassified. The provisioning-ready finalizer locks both rows and the dormant authority state,
  verifies live governance recovery eligibility, CASes
  vault_authority_state.existing_install_provisioning_evidence_id from NULL to its own ID, and changes
  itself to finalized in the same transaction. The evidence row's installation FK and trigger
  require the reciprocal authority-state pointer before either side is trusted. A finalized rerun is
  read-only and proves the same numeric actor, executions and state pointer.
- user_security_mutation_executions is UserManagement-owned and active in all Vault phases: id
  UUIDv7, closed flow_kind, actor_id with the exact actorless-flow allowlist above, DB-time
  authorized_at/expires_at, expected/consumed subject counts, stage claimed|effects_started|
  subjects_complete|finalized|aborted, immutable pre_state_snapshot_digest exactly raw SHA-256 of
  `nexum.vault.user-security-pre-state-snapshot.v1`, effects_started_at,
  active_slot, nullable primary_audit_event_id UNIQUE, finalized/aborted provenance, and created_at.
  `authorized_at` and `expires_at` obey the exact
  `VAULT_USER_SECURITY_EXECUTION_TTL_SECONDS` formula above; UserSecurity-bound evidence commits
  must store the same expiry byte-for-byte.
  primary_audit_event_id is required exactly for password_rehash_on_login and points to its one
  `user_security_password_rehashed` event; it is forbidden for other flows unless their exact event
  mapping is added in a later reviewed slice. For that flow, the base audit INSERT after
  subjects_complete creates its pending trust child by trigger. One guarded finalizer verifies the
  same execution/subject/epoch/proof-invalidation set, binds the event, marks execution finalized,
  clears the UserSecurity gate, and changes trust to finalized as its last write in the same
  transaction; login/session completion occurs only after COMMIT. claimed/effects_started/
  subjects_complete require active_slot=1; finalized/aborted require NULL. Only claimed with zero
  consumed subjects/DML/epochs may abort. effects_started and later are exact-snapshot resume-only,
  and subjects_complete-to-finalized is the only success transition. It has no reverse Vault-witness FK:
  dormant executions require every Vault plan/witness/mutation/audit reference absent, while the
  enforced witness points one way to this already-existing execution.
- user_security_mutation_subjects is typed one-use evidence: id UUIDv7, execution_id, sequence,
  subject_kind exactly password_credential_changed|password_rehash_on_login|remember_token_transition|totp_generation_transition|
  totp_confirmation_transition|recovery_codes_rotated|recovery_code_consumed|
  session_security_reset|login_identifier_changed|account_status_transition|
  human_system_classification_transition|new_user_insert|standard_role_bootstrap|
  new_user_role_assignment|user_role_assignment|role_permission_assignment|
  role_permission_affected_user_epoch|direct_permission_assignment, exact safe typed identity/epoch columns,
  state pending|consumed, active_slot, consumed_at, and created_at. There is no `user_field` or
  arbitrary field/value subject. Password/recovery/session kinds store user ID, the closed change kind
  and exact OLD-to-NEW auth-security epoch only; they never store a password, credential hash,
  recovery code, session value or value-derived digest. password_rehash_on_login additionally fixes
  reason=password_hash_policy_upgrade. remember_token_transition fixes one of its five closed trigger
  kinds and never stores the token or a token-derived value. login_identifier_changed stores only user ID
  and exact OLD-to-NEW auth-security epochs; triggers verify a persisted email change but neither
  email value nor a derived key/hash is stored. TOTP kinds store only retained generation
  UUIDs, exact pending|confirmed|superseded|disabled state or confirmed-state booleans, and applicable
  OLD-to-NEW auth/Vault-authority epochs; they never store the secret, code or encrypted value.
  account_status_transition stores exact `PENDING_INVITE|ACTIVE|DISABLED` before/after plus both
  epochs. human_system_classification_transition stores only before/after system booleans and both
  epochs. Role/direct-permission kinds store only numeric protected identities, presence booleans and
  per-user epochs. role_permission_assignment additionally requires the sorted affected-user-set
  digest and one exact role_permission_affected_user_epoch subject per member. `new_user_insert` and
  new_user_role_assignment store target_user_id NULL until
  the context-bound AFTER INSERT trigger verifies the generated row's safe expected status,
  human/system classification, optional canonical system key and initial epoch values, then binds
  NEW.id once; only new_user_insert is consumed by that INSERT, while the assignment remains pending
  for its exact pivot. standard_role_bootstrap uses the equivalent one-time target_role_id bind from
  the exact role INSERT. Every kind has a required/forbidden NULL matrix; generic
  value/blob/credential columns, JSON, column-name text, SQL fragments and compressed user lists are
  absent and rejected. Each protected DML/epoch statement consumes its exact subject only when the
  corresponding protected column or pivot actually changes; triggers compare the database row but
  never copy OLD/NEW credential content into evidence.
- vault_authority_state has one installation-bound row: installation_id UUID primary key,
  authority_epoch UNSIGNED BIGINT>=1, candidate_roster_digest BINARY(32),
  governance_ready_roster_digest BINARY(32), global_authority_facts_digest BINARY(32),
  install_classification fresh_install|existing_install fixed at creation,
  control_phase dormant|provisioning|enforced, quorum_state open|locked, nullable quorum_lock_id,
  quorum_cooling_started_at, quorum_recovery_not_before, root incident reason/event references,
  nullable pending_authority_witness_id, nullable pending_quorum_mutation_id, nullable
  existing_install_provisioning_evidence_id UNIQUE, and updated_at. The evidence pointer is NULL for
  fresh_install and initially NULL for existing_install; only the exact existing-install
  provisioning finalizer above may set it once. Open
  requires every quorum field NULL except a transaction-local pending quorum fence during its exact
  apply/finalize stages;
  locked requires all of them non-null and recovery_not_before exactly 24 hours after cooling start.
  Epoch and digest change only through guarded authority transactions; locked-to-open requires exact
  recovery. Production creation requires control_phase=dormant and the immutable-phase trigger
  rejects every later phase change. Operational guards deny Row04 operations while phase is dormant;
  when the isolated test assembler installs the same guards over a pre-inserted enforced row, only
  non-phase state changes with the exact operational fence are permitted. A later reviewed activation
  migration must replace the immutable-phase contract atomically; Slice 04 supplies no transition.
  pending_authority_witness_id is a fail-closed write fence, not an authorization cache. Migration
  inserts exactly one untouched baseline with the locked classification, authority_epoch=1,
  control_phase=dormant,
  quorum_state=open, canonical current candidate/governance/global digests, all quorum/root-event/
  pending-fence fields NULL, and explicit canonical UTC time. Missing, duplicate, malformed, or
  digest-mismatched baseline fails readiness.
- vault_authorization_scopes has canonical scope columns with exact UNIQUE(scope_type,scope_id),
  auth_epoch UNSIGNED
  BIGINT>=1, five BINARY(32) set digests for groups/collections/memberships/item-links/grants, and
  nullable pending_graph_execution_id plus created_at/updated_at. Company/client CHECKs enforce the
  nullable client_id rule. It stores only structural graph truth, never Client visibility/global
  authority facts. Every DELETE, restore or scope-identity change is rejected unconditionally,
  whether or not another row currently references the anchor; first-use is the only INSERT path and
  delete/reinsert ABA is impossible. A non-null pending execution is a fail-closed fence for every
  graph decision in that scope.
- vault_access_groups has id UUID, immutable scope, name VARCHAR(120), name_key BINARY(32), state
  active|disabled, active_slot 1|NULL, auth_epoch>=1, member_set_digest BINARY(32), creator/time,
  last rename actor/time, disabled actor/time/reason, and non-null created/last graph-execution-
  subject FKs. Name is trimmed safe UTF-8 without control characters; uniqueness is
  scope+name_key+active_slot. Only witnessed audited rename or terminal disable may update the row.
- vault_access_group_memberships has id UUID, group_id, user_id, repeated exact scope, state
  active|revoked, active_slot 1|NULL, reachability_expanded boolean, nullable approval_consumption_id
  required exactly when expansion was true, added actor/time, terminal revoke actor/time/reason, and
  non-null created/last graph-execution-subject FKs. One active group+user row is allowed; re-add
  uses a new UUIDv7.
- vault_collections mirrors group identity/lifecycle with id UUID, immutable scope, safe name/name_key,
  state/active_slot, auth_epoch>=1, item_set_digest BINARY(32), creator/rename/disable provenance, and
  non-null created/last graph-execution-subject FKs.
- vault_collection_items has id UUID, collection_id, vault_item_id, repeated exact scope, state
  active|revoked, active_slot, reachability_expanded, conditional approval_consumption_id, add
  provenance, terminal revoke provenance, and non-null created/last graph-execution-subject FKs.
  One active collection+item row is allowed.
- vault_grants has id UUID, exact non-null scope_key, subject_type user|access_group with
  subject_user_id XOR access_group_id, target_type item|collection with vault_item_id XOR
  collection_id, operation_code VARCHAR(64), effect fixed allow, state active|revoked, active_slot,
  non-null byte-exact subject_key and target_key, non-null
  approval_consumption_id for creation, grant actor/time, terminal revoke actor/time/reason, and
  non-null created/last graph-execution-subject FKs.
  subject_key is exactly `user:<canonical-decimal-id>` or
  `group:<canonical-lowercase-uuid>`; target_key is exactly
  `item:<canonical-lowercase-uuid>` or `collection:<canonical-lowercase-uuid>`. Triggers derive
  and byte-compare the key against the XOR type/FK pair and never trust caller text or collation.
  scope_key is byte-exact ASCII `company` or `client:<canonical unsigned decimal client ID without
  leading zeroes>`. MariaDB stores it VARBINARY(32); SQLite requires TEXT storage, exact ASCII byte
  length and alphabet. Triggers derive it from scope_type/scope_id/client_id and byte-compare it;
  caller text and database collation are never trusted. active_slot is one exactly when active and
  NULL when revoked; UNIQUE(scope_key,subject_key,target_key,operation_code,active_slot) permits one
  active operation-specific grant. Changing capability revokes the old row and creates a new UUIDv7.
  Scope must match every
  referenced subject/target. Slice 04 installs an insert guard that rejects
  every production grant because no content operation is enabled; later operation slices replace it
  only with a closed allowlist plus exact global-permission mapping.
- vault_step_up_proofs has id UUID, evidence_commit_id UNIQUE, actor_id, session_digest BINARY(32),
  auth_security_epoch, installation_authority_epoch,
  accepted_totp_counter UNSIGNED BIGINT, state pending|active|revoked, active_slot, verified_at, expires_at,
  terminal_transition_id, revoked_at/by/reason, and created_at. TTL must be 60..900 seconds,
  expires_at is exact derived time,
  byte-exact actor+session_digest+active_slot is unique, active requires active_slot=1, pending/revoked
  require NULL, and only its creation-commit finalizer may activate while only exact transition
  finalization may revoke it.
- vault_totp_enrollment_proofs has id UUID, evidence_commit_id UNIQUE, actor_id,
  pending_generation_id, purpose_operation fixed
  authority.totp_confirm|quorum.cooling_recovery, session_digest,
  auth_security_epoch, installation_authority_epoch, accepted_totp_counter,
  state pending|active|consumed|revoked, active_slot, verified_at,
  issued_at and expires_at exactly 300 seconds later by authoritative DB time, terminal_transition_id, consumption/revocation
  provenance, and created_at. It contains no
  factor/secret/hash, can be referenced only by the exact same-purpose plan for the same actor and
  generation, and cannot satisfy any other requester/executor/approver proof FK.
  active requires active_slot=1; pending/consumed/revoked require NULL. Every identity component is non-null
  and byte-exact; UNIQUE(actor_id,session_digest,purpose_operation,active_slot), plus driver-parity
  state/slot triggers, permits one live enrollment proof per purpose/session. A changed generation
  must terminally revoke the old proof before replacement.
- vault_step_up_proof_transitions is append-only terminal source evidence: id UUIDv7,
  evidence_commit_id UNIQUE, proof_kind step_up|totp_enrollment, step_up_proof_id XOR
  totp_enrollment_proof_id, terminal_kind consumed|revoked, trigger_kind
  totp_consumed|expired_on_retry|auth_security_invalidated|authority_invalidated|building_abort, executor_kind
  human|system_cleanup, nullable initiating_actor_id XOR system_actor_id, and nullable closed typed
  reference columns: replacement_pending_step_up_proof_id XOR
  replacement_pending_totp_enrollment_proof_id, authority_mutation_id,
  user_security_execution_id, authority_witness_id, quorum_mutation_id, or
  target_building_creation_commit_id as required by trigger_kind. It also stores closed reason,
  transitioned_at, and created_at. Standard step_up permits revoked only; totp_enrollment permits
  consumed exactly by its same-purpose confirmation/recovery or revoked.
  Consumed reason is exactly totp_enrollment or recovery matching purpose; revoked reason is exactly
  epoch_mismatch|user_deactivated|totp_reset|expired|suspected_compromise|stale_plan|policy_denied for step_up and
  epoch_mismatch|totp_reset|expired|stale_plan|policy_denied for totp_enrollment.
  totp_consumed is valid only for TOTP-enrollment consumption and requires human executor plus the
  subject actor. For authority.totp_confirm it requires the exact same-actor authority mutation,
  UserSecurity execution and UNIQUE authority witness; for quorum.cooling_recovery it instead
  requires the exact quorum mutation plus its UserSecurity execution/authority witness. Exactly one
  operation branch is non-null. expired_on_retry is revoke-only, requires human executor equal to
  the current proof actor, DB time at/after proof expiry, and one exact same-kind/purpose pending
  replacement proof whose building creation commit is in the same transaction; it forbids every
  mutation FK. auth_security_invalidated is revoke-only for either proof kind and requires the exact
  UserSecurity execution plus its OLD-to-NEW auth-epoch and per-active-proof transition subjects; it
  forbids every Vault authority witness/mutation. That execution locks and enumerates all active
  proofs across every session before its epoch write. A human execution actor is copied as initiating
  actor; an enumerated actorless reset/recovery flow uses executor_kind=system_cleanup and the
  canonical system actor while preserving exact flow provenance through the execution. Building
  transition sources/audits are prepared while the exact gate-owning execution is effects_started or
  subjects_complete. Each transition evidence-commit finalizer may terminally revoke only its
  enumerated proof while that same execution still owns the gate; it verifies the precommitted
  auth-security OLD-to-NEW epoch and proof-transition subject and leaves the audit trust projection
  pending. After every enumerated transition commit is finalized, one coordinated UserSecurity
  finalizer first finalizes those exact audit-trust projections while they remain unobservable due to
  the still-unfinalized owner execution, then atomically changes the execution to finalized and
  clears its gate as the LAST guarded CAS. No Row04 post-state consumer or trusted audit reader accepts the transition
  until that execution is finalized. TOTP-counter-only CAS is excluded. Missing one proof or a
  new-proof race denies; a fresh same-session proof can activate only after the gate clears.
  authority_invalidated is revoke-only and requires human executor equal to the exact
  authority mutation actor plus that mutation;
  when it changed UserManagement/Spatie it also requires the mutation's exact execution/witness.
  building_abort is revoke-only for a pending proof, requires executor_kind=system_cleanup plus the
  canonical vault-control-plane system_actor_id and exact inert creation commit, and forbids human,
  proof, plan, replacement and operation references. Every nullable column not required by the
  selected trigger kind must be NULL; no generic stale-proof path exists. For replacement,
  the transaction finalizes the old revocation before the new creation commit activates the pending
  proof, so active uniqueness never creates an insert cycle.
  Finalizing its own exact evidence commit/audit CASes the referenced proof active-to-terminal (or
  pending-to-revoked for building_abort) and
  stores terminal_transition_id. The target proof's null-to-transition-ID CAS is the single effective
  terminal claim; creation commit/audit is never reused. Competing/duplicate terminal sources can
  remain inert building/aborted but only one can finalize.
- vault_step_up_attempt_evidence is durable append-only evidence: id UUID,
  evidence_commit_id UNIQUE, actor_id,
  attempt_kind step_up|totp_enrollment, session_digest BINARY(32), auth_epoch, nullable exact
  pending_generation_id required only for enrollment, safe internal failure reason,
  factor_evaluation evaluated|skipped, and occurred_at. skipped is required exactly for an
  already-active rate lock with reason rate_limited; every evaluated factor result requires
  evaluated. It stores one
  non-secret row per failed combined attempt, is the only failure row referenced by permanent audit,
  and is never pruned.
- vault_step_up_rate_failures is separate non-evidence rate telemetry: id UUID, actor_id,
  session_digest BINARY(32), auth_epoch, occurred_at, and the exact evidence_id UNIQUE. It is written
  atomically with the durable evidence but is never referenced by audit or another durable record.
- vault_step_up_rate_limit_anchors has actor_id, bucket_kind actor|actor_session,
  session_key BINARY(32) NOT NULL, locked_until, and updated_at, with
  UNIQUE(actor_id,bucket_kind,session_key). The actor bucket requires exactly 32 zero bytes; the
  actor_session bucket requires the canonical nonzero session digest. Each attempt locks both
  anchors, captures one DB time, inserts the failure at that instant, then counts only rate-failure
  rows satisfying `occurred_at > DB_UTC_NOW - 15 minutes` and
  `occurred_at <= DB_UTC_NOW` at that same captured instant. The lower bound is exclusive and upper
  bound inclusive on both drivers. It sets a 15-minute lock when either
  actor_session post-insert count is >=5 or actor post-insert count is >=20. This is a true rolling
  window, not a resettable fixed bucket. A bounded prune may delete only rate-failure telemetry older
  than 24 hours after the current attempt/lock transaction; durable attempt evidence remains, and
  pruning can never affect its audit FK, the active window, or a lock.
  When either locked_until is later than DB time at attempt start, both anchors are locked and the
  exact already-locked branch above emits durable skipped evidence only: no rate-failure INSERT,
  factor verifier, replay-counter access, proof, or lock extension is permitted. Equality is not
  locked. Concurrent locked attempts therefore cannot lengthen the original lock.
- vault_action_plans is the immutable base: id UUID, evidence_commit_id UNIQUE,
  plan_type graph|authority|quorum, requester_id,
  requester_session_digest BINARY(32), requester_auth_epoch,
  requester_proof_kind step_up|totp_enrollment, requester_step_up_id XOR
  requester_totp_enrollment_proof_id, closed
  operation_code, post_review_gate_epoch, post_review_blocker_set_digest BINARY(32), plan_version
  fixed 1, plan_digest BINARY(32), requested_at, and expires_at.
  Requester proof must match actor/session/epoch and be fresh when the plan is created;
  totp_enrollment is permitted only for authority.totp_confirm or quorum.cooling_recovery and must
  match the proof's purpose_operation. Exactly one
  same-ID 1:1 extension row must match plan_type and operation family; zero or multiple extensions
  are rejected before an approval request, witness, exception, or mutation can reference the plan.
- vault_graph_mutation_plans shares its primary key/FK with vault_action_plans and stores exact scope,
  proposed safe name/name_key, typed reason, before/after reachability, scope/group/collection epochs
  and set digests, candidate-roster/global-authority-facts digest, and separate exact
  scope-authority-facts digest. vault_graph_plan_subjects enumerates
  every group, collection, item, membership, link, or grant identity plus exact before/after state
  under a unique sequence. Per-operation guards require and forbid exact subject kinds.
- vault_authority_mutation_plans shares its primary key/FK with vault_action_plans and stores the
  ordered subject-set digest, exact before/after candidate-roster digests, exact before/after
  global-authority-facts digests, and typed reason from the frozen 18-field authority-plan
  document. Governance-ready eligibility is already committed inside each corresponding global-
  authority-facts document and is deliberately not copied into an unsigned side field on the
  authority plan.
  vault_authority_plan_subjects enumerates every affected user, protected role, protected permission,
  status/classification/TOTP-confirmed fact, pivot identity, and exact before/after value under a
  unique sequence. Role-wide changes enumerate every affected user; a digest is never a subject list.
  Each affected user reuses the canonical
  `nexum.vault.subject.user-security.role-permission-affected-user-epoch.v1` family in both the
  authority plan and the later UserSecurity witness, so the two boundaries compare byte-identical facts.
  authority.emergency_deactivate requires reason exactly `emergency_security_deactivation` plus the
  exact status and semantic authority-state subjects; no `emergency_change`, free text or other
  reason alias is accepted for that operation.
  its applied mutation must also reference the consumed pending-fence install/clear witness subjects.
- vault_quorum_mutation_plans shares its primary key/FK with vault_action_plans and stores the exact
  quorum-lock identity/times, before/current/proposed candidate/governance-ready roster and global-
  authority-facts digests, cooling and
  warning facts, and typed reason. Each plan starts transaction-local draft and becomes immutable
  finalized only after exactly one same-ID `vault_quorum_plan_state_subjects` row records its complete
  canonical locked-before/open-after quorum-state subject. Approval, recovery authorization and
  execution reject an unfinalized plan. A pending quorum mutation has a non-null FK to that exact
  plan/state-subject identity. Optional `vault_quorum_plan_authority_subjects` enumerate only the
  protected candidate-roster/status/role changes needed by cooling recovery; peer unlock has none.
  Any duplicate/missing/swapped state subject or grant, group, collection, item or content subject
  rejects and rolls the incomplete plan back.
- vault_audit_trust_cutovers is retained migration provenance, not authorization: installation_id
  primary/FK, cutover_id UUIDv7 UNIQUE, stage manifest_building|manifest_captured|backfilled|
  guard_installed|completed, manifest_count integer, manifest_digest BINARY(32), exact expected
  normalized trigger names/body version codes, revision, created_at and updated_at.
  vault_audit_trust_cutover_items has cutover_id/base_audit_id primary+RESTRICT FKs,
  event_id retaining the canonical foundation UUID domain (versions 1-8) with
  UNIQUE(cutover_id,event_id), sequence fixed to ascending base_audit_id order,
  event_type, outcome, reason, typed-reference
  columns copied exactly from the four foundation event families under their existing required/NULL
  matrix, and created_at. Items are immutable and exactly cover the locked pre-cutover audit
  rows; the header count, canonical manifest digest and ordered base-ID/event-UUID set are verified
  directly against them. The DML cutover creates the header as manifest_building, inserts each item
  through the exact one-use context, and may CAS to manifest_captured only after re-reading the whole
  locked base set and recomputing the exact dedicated manifest family. Zero base rows require an
  explicit finalized count-zero manifest; an absent header is not an empty manifest. Trust backfill
  accepts only a manifest_captured header and an exact listed item.
  A stage advances once only after exact catalog/data read-back and never skips/reverses. The
  backfill transaction owns manifest_captured-to-backfilled; a named post-guard DML verification
  owns backfilled-to-guard_installed after permanent-guard catalog read-back; a final named DML
  verification owns guard_installed-to-completed only after the base freeze has been removed and the
  1:1 trusted view re-read. DDL never writes these stages. Missing/divergent partial state
  leaves audit frozen/readiness false and resumes that same cutover ID; no second cutover or mutable
  maximum-ID watermark exists. These tables are populated only by the closed cutover above and never
  by runtime services.
- vault_evidence_commit_sequences has installation_id primary/FK and next_sequence integer in
  1..PHP_INT_MAX. Commit creation locks this row, allocates its current value exactly once and
  advances by one; exhaustion denies before any source/audit insert. Both drivers enforce one
  sequence value per installation and never derive ordering from wall-clock or UUIDv7 lexical order.
  Migration seeds exactly one baseline row with next_sequence=1; missing, duplicate or malformed
  baseline fails readiness.
- vault_evidence_commits is the uniform trust gate: id UUIDv7, installation_id,
  commit_sequence integer allocated under one locked installation counter, source_kind closed enum,
  source_id UUIDv7, expected_event_type, expected_outcome, expected_audit_event_id UUIDv7,
  state building|finalized|aborted, active_slot, created_at/expires_at, finalized_at, and nullable
  aborted_at/reason/aborted_by_commit_id. UNIQUE(source_kind,source_id),
  UNIQUE(installation_id,commit_sequence), UNIQUE(expected_audit_event_id), and null-safe
  active constraints apply. Building-to-finalized verifies one exact source and one exact typed audit;
  it also verifies that audit's exact pending trust projection and finalizes trust as its last write.
  Building-to-aborted is terminal cleanup through a separate finalized cleanup evidence/audit and
  requires aborted_by_commit_id to reference that exact cleanup commit.
  Ordinary commits use `VAULT_EVIDENCE_BUILD_TTL_SECONDS`; UserSecurity- and origin-bound commits
  inherit the exact owning execution/origin expiry, and migration/cutover commits use the fixed
  same-unit 900-second rule. No row may choose a different expiry from its closed source kind.
  Ordinary update/delete/restore and every other transition reject.
- vault_evidence_commit_aborts is the immutable cleanup source: id UUIDv7,
  evidence_commit_id UNIQUE referencing its own building cleanup commit,
  target_building_commit_id referencing a different older inert building commit,
  target_source_kind, target_source_id, nullable request_id and approval_lifecycle_request_id,
  trigger_kind operator_cancel_pre_effects|initiating_human_failure|db_time_expired|
  orphan_repair|system_repair|abort_lost_race, executor_kind human|system_cleanup,
  nullable human_actor_id, system_actor_id and step_up_proof_id, closed abort reason, and occurred_at.
  Human execution requires human_actor_id plus that actor's fresh active, non-revoked, same-session
  step_up proof and requires both system fields NULL. It is legal only for
  operator_cancel_pre_effects, or initiating_human_failure when the exact target source already names
  that initiating actor/proof and no effects started. System cleanup requires the exact canonical
  vault-control-plane system_actor_id and both human/proof fields NULL; db_time_expired additionally
  requires DB time at/after target expiry, while orphan_repair, system_repair and abort_lost_race
  require their exact inert/repair predicate. Reason mapping is exhaustive:
  operator_cancel_pre_effects=requester_cancelled; initiating_human_failure=stale_plan|policy_denied;
  db_time_expired=expired; orphan_repair=orphaned_building_evidence; and
  system_repair|abort_lost_race=cleanup_repair. Human cancellation never aliases approval
  invalidation. Every other trigger/executor/reason/NULL combination rejects.
  Exact order is cleanup commit building, abort source, typed audit
  referencing cleanup commit+abort source+target, then one finalizer transaction that locks both
  commits, changes target building-to-aborted with aborted_by_commit_id=cleanup commit, changes the
  target event trust pending-to-aborted, and changes cleanup building-to-finalized plus its audit
  trust to finalized LAST. The target commit_sequence must be strictly less than the cleanup
  commit_sequence; self-reference and cycles reject without relying on wall-clock precision.
  target_building_commit_id is deliberately not UNIQUE: concurrent
  or retry cleanup attempts may target the same inert commit, and the locked target-state CAS selects
  exactly one winner. The only no-source state exception is a losing cleanup commit: after locking
  and observing that the original target CAS was already won, it may atomically become aborted/inert
  with the closed abort_lost_race code, or a later strictly higher-sequence cleanup attempt may abort
  that loser. It never reserves the original target or becomes trusted evidence.
  Wrong target, already-final/aborted target, replay or partial transition rejects and rolls back.
- source_kind is exactly action_plan|approval_request|approval_decision|approval_cancellation|
  approval_expiration|approval_invalidation|approval_consumption|sole_admin_exception|
  totp_enrollment_exception|step_up_proof|totp_enrollment_proof|step_up_proof_transition|
  step_up_attempt_evidence|quorum_recovery_authorization|
  quorum_recovery_authorization_transition|post_review_origin_transition|post_review_obligation|post_review_evidence|
  post_review_overdue_observation|evidence_commit_abort. No alias/future kind is
  shape-valid. Abort handling is source-specific and fail-closed. The cleanup transaction locks the
  target commit, its specialized source, exact pending audit-trust row, and any lifecycle/active-slot
  projection before doing any work, and verifies the source is still unusable because its own commit
  and audit trust are pending. Every row also terminalizes that exact trust projection to aborted; a
  finalized, absent, or mismatched trust row denies cleanup. This matrix is
  exhaustive; every pointer clear is a null-safe CAS requiring that it still names the target commit:

  | Target source_kind | Required release before target commit may become aborted |
  | --- | --- |
  | action_plan | No operational pointer may exist; plan/subtype stays inert and retry uses a fresh plan UUID. |
  | approval_request | Clear its exact pending reservation, transition its 1:1 lifecycle to invalidated, and require a fresh plan/request. |
  | approval_decision, approval_cancellation, approval_expiration, approval_invalidation, sole_admin_exception, totp_enrollment_exception | Clear only that exact lifecycle reservation; prior lifecycle state remains and a fresh source may retry. |
  | approval_consumption | When not origin-bound, revoke the referenced still-pending graph execution/authority witness/quorum mutation, clear its active slot and exact lifecycle reservation, transition the request to invalidated, and require a fresh plan/request/operation. When origin-bound, generic cleanup is forbidden: assembling/claimed uses only whole-origin abort, and effects_started or later is exact resume-only. |
  | step_up_proof, totp_enrollment_proof | Finalize a revoke-only vault_step_up_proof_transition with its own commit/audit; the cleanup-only path may inspect the exact building creation commit but can never consume, and it clears the proof active slot. |
  | step_up_proof_transition | The target proof is unchanged because a building transition has not finalized; no proof slot is reserved and a fresh transition may retry. |
  | step_up_attempt_evidence | No durable slot/reservation; linked rate telemetry may remain until bounded prune and cannot block another attempt. |
  | quorum_recovery_authorization | Finalize a revoke-only authorization transition with its own commit/audit and clear the lock's authorization active slot; retry uses a fresh plan/authorization. |
  | quorum_recovery_authorization_transition | When not origin-bound, the authorization is unchanged because a building transition has not finalized and a fresh transition may retry. When origin-bound, assembling/claimed uses only whole-origin abort and effects_started or later is resume-only. |
  | post_review_origin_transition | The assembling/claimed origin is unchanged because a building abort transition reserves nothing before finalization; abort this inert transition commit and retry with a fresh transition source. No transition cleanup may alter an effects_started-or-later origin. |
  | post_review_obligation | Never retry within the same origin. If its origin is assembling or claimed, the typed whole-origin abort terminalizes the inert composite; once effects_started, generic cleanup is forbidden, exact resume is mandatory, and the building obligation remains fail closed. |
  | post_review_evidence | Clear the exact obligation reservation, permanently advance past the inert sequence, and for remediation also clear the exact finding-lifecycle reservation. |
  | post_review_overdue_observation | No lifecycle pointer is set before finalization; the inert source reserves nothing and a fresh observation may race/retry. |
  | evidence_commit_abort | Apply the strictly ordered winner/loser cleanup rule above; it never reserves the older original target. |

  Every row is additionally gated by the origin rule: no generic target cleanup may release,
  terminalize, or detach an origin-owned operation, consumption, recovery transition, obligation,
  lifecycle reservation, scope/authority/quorum fence or post-review-gate pointer. Only the whole-
  origin abort owns those changes before effects_started; after effects_started none may be cleared
  except by exact coordinator resume/finalization. The required specialized transition/slot/lifecycle change, cleanup audit+commit finalization, and
  target building-to-aborted CAS are one transaction. Only then may the target commit become aborted.
  This makes every crash point recoverable without making building evidence trusted or allowing a
  source-level unique/active slot to block safe retry forever.
- vault_approval_requests has id UUID, evidence_commit_id UNIQUE, action_plan_id UNIQUE, requester_id,
  requester_session_digest BINARY(32), requester_auth_epoch, requester_proof_kind,
  requester_step_up_id XOR requester_totp_enrollment_proof_id,
  candidate-roster/global-authority-facts digests, nullable scope-authority-facts digest required
  exactly for graph plans, plan_version fixed 1, plan_digest BINARY(32),
  requested_at, and expires_at. Every duplicated field must match the base plan byte-for-byte, and
  its closed operation must require independent approval or the exact sole-admin alternative. The
  row and referenced plan are immutable.
- vault_approval_request_lifecycle is the sole mutable 1:1 projection: request_id primary/FK,
  state pending|approved|rejected|cancelled|expired|invalidated|consumed, revision integer,
  transitioned_at, last_evidence_kind/id, and nullable pending_evidence_commit_id+
  pending_target_state. It is initialized pending with revision 1 and the finalized request-created
  identity. Every later source kind and UUID must name the exact specialized evidence row; driver
  triggers reserve one building commit then byte-check finalized source+audit before one null-safe
  revision CAS. Only the closed reserve/finalize transitions in the lifecycle section may update it;
  ordinary update/delete/restore rejects.
- vault_approval_expirations and vault_approval_invalidations are append-only typed cleanup
  evidence. Each has id UUIDv7, evidence_commit_id UNIQUE, request_id, lifecycle_request_id fixed
  equal request_id, trigger_kind, executor_kind fixed system_cleanup, human_actor_id and
  step_up_proof_id fixed NULL, system_actor_id fixed to the canonical vault-control-plane actor,
  occurred_at, and created_at. Expiration permits only trigger_kind=db_time_expired, reason=expired,
  and occurred_at at or after the locked request expiry. Invalidation permits only
  actor_auth_epoch_drift|actor_status_drift|permission_drift|totp_drift|client_visibility_drift|
  scope_epoch_drift|authority_epoch_drift|digest_drift|target_state_drift|governance_floor_drift;
  it stores the matching exact typed epoch/digest/identity anchor. Reason mapping is exhaustive:
  actor_auth_epoch_drift|authority_epoch_drift=epoch_mismatch;
  actor_status_drift|permission_drift|totp_drift=policy_denied;
  client_visibility_drift=client_access_denied; scope_epoch_drift=scope_missing;
  digest_drift=digest_mismatch; target_state_drift=stale_plan; and
  governance_floor_drift=governance_floor. The physical drift anchor is exactly three required
  fields: `anchor_family`, bounded `anchor_entry`, and `anchor_digest`. `anchor_family` maps one-to-one
  from the closed trigger to
  `nexum.vault.approval-invalidation.<hyphenated-trigger>.v1`; `anchor_entry` is the matching
  canonical binary-v1 document and is 1 through 4096 bytes; `anchor_digest` is the 32-byte SHA-256
  digest of those exact bytes. Named constructors close the ten family-specific identities and
  before/observed facts: actor auth epoch, actor status, effective permission, confirmed TOTP
  generation, Client visibility, scope epoch, installation authority epoch, named plan digest,
  plan-subject target state, or governance-floor counts/roster/authority facts. The locked
  invalidation guard recomputes and byte-compares the family, entry, and digest. It stores no JSON,
  free-form context, caller-selected field list, or opaque unvalidated blob.
  Actorless/disabled-user drift therefore remains attributed to the
  deterministic system cleanup actor while the request and drift anchor identify the human/source
  fact. Human cancellation remains only vault_approval_cancellations. Their INSERT triggers use the
  EvidenceCommitContext lifecycle reservation and exact source/audit reciprocity; every human
  executor, proof, wrong trigger/reason, early DB-time or missing drift anchor rejects.
- vault_approval_decisions has id UUID, evidence_commit_id UNIQUE, request_id, approver_id,
  approver_auth_epoch, approver_step_up_id, decision approve|reject, typed reason, decided_at, and
  created_at. It is append-only and requester cannot be approver.
- vault_approval_cancellations has id UUID, evidence_commit_id UNIQUE, request_id,
  requester_id, requester_proof_kind,
  requester_step_up_id XOR requester_totp_enrollment_proof_id, typed reason, cancelled_at, and
  created_at. It is append-only; requester and proof purpose must match the request.
- vault_sole_admin_exceptions has id UUID, evidence_commit_id UNIQUE, request_id,
  requester_id, requester_step_up_id,
  installation_authority_epoch, candidate_roster_count fixed 1, candidate_roster_digest,
  global_authority_facts_digest, warning_acknowledged
  fixed true, typed reason, issued_at, expires_at, and created_at. issued_at is DB time and expires_at
  is exactly the minimum of DB time plus 300 seconds and every bound request/plan/proof expiry; a
  nonpositive result rejects. Its finalized creation commit transitions approval lifecycle to
  approved; later use is represented only by the distinct approval_consumption source/commit/audit.
  The creation commit/audit is never renamed or reused as consumption.
- vault_totp_enrollment_exceptions has id UUID, evidence_commit_id UNIQUE, request_id,
  requester_id,
  requester_totp_enrollment_proof_id, pending_generation_id, installation_authority_epoch,
  candidate_roster_count fixed 1, candidate/global-authority-facts digests,
  warning_acknowledged fixed true, typed reason, issued_at, expires_at, and created_at. issued_at is
  DB time and expires_at is exactly the minimum of DB time plus 300 seconds and every bound
  request/plan/enrollment-proof expiry; a nonpositive result rejects. It is valid only for the
  matching authority.totp_confirm plan, is single-use
  through a later distinct approval consumption, and creates mandatory post-review. Its own
  finalized commit/audit means exception created/approved, never consumed; the consumption
  source/commit/audit alone represents use.
- vault_quorum_recovery_authorizations has id UUID, evidence_commit_id UNIQUE,
  action_plan_id UNIQUE, requester_id,
  requester_proof_kind, requester_step_up_id XOR requester_totp_enrollment_proof_id, quorum-lock
  identity, exact current/proposed candidate/governance-ready
  roster and global-authority-facts
  digests, cooling_started_at, eligible_at, warning_acknowledged fixed true, typed reason, issued_at,
  expires_at, state active|consumed|revoked, active_slot,
  nullable terminal_transition_id, consumed_at/by_quorum_mutation_id XOR revoked_at/reason, and
  created_at. It is available
  only when
  no different pre-change candidate can approve and DB time has passed the 24-hour cooling boundary.
  issued_at is DB time and expires_at is exactly the minimum of DB time plus 300 seconds and every
  bound plan/enrollment-or-standard-proof expiry; a nonpositive result rejects.
  active requires active_slot=1 and all terminal fields NULL; consumed requires active_slot NULL plus
  consumed_at and by_quorum_mutation_id; revoked requires active_slot NULL plus revoked_at/reason.
  UNIQUE(quorum_lock_id,active_slot) permits one active authorization per lock. Only guarded
  active-to-consumed or active-to-revoked is allowed. Consumption occurs atomically with the exact
  quorum mutation, authority-state transition, recovery evidence, obligation and audit. An expired
  active authorization is first locked, terminally revoked and audited before retry; it is never
  reused, restored, deleted, or silently replaced. It cannot authorize content or a grant.
- vault_quorum_recovery_authorization_transitions is append-only terminal source evidence: id
  UUIDv7, evidence_commit_id UNIQUE, recovery_authorization_id, terminal_kind consumed|revoked,
  trigger_kind consumed|human_revoked|expiry_cleanup|building_abort, executor_kind human|system_cleanup,
  nullable initiating_actor_id XOR system_actor_id, nullable actor_step_up_id,
  quorum_mutation_id, target_building_creation_commit_id, and
  post_review_origin_execution_id as closed typed refs. consumed requires human executor, terminal
  consumed, exact pending cooling-recovery quorum mutation and its matching actor/proof plus the same
  pending post-review-origin coordinator; it forbids retry/abort refs and finalizes only after the
  exact obligation is open. human_revoked requires terminal revoked, a current governance candidate
  as human executor and fresh step-up before expiry; it forbids mutation/origin/abort refs.
  expiry_cleanup requires terminal revoked, executor_kind=system_cleanup, canonical
  vault-control-plane system_actor_id, exact authorization/quorum-lock identity and authoritative DB
  time at/after expires_at; it forbids every human, proof, new-plan, mutation, origin and abort ref.
  The retry flow may invoke this deterministic cleanup before creating a fresh enrollment proof or
  recovery plan, including with zero governance-ready users. building_abort requires terminal
  revoked, executor_kind=system_cleanup, canonical vault-control-plane system_actor_id, and only the
  exact building creation commit; all human/proof/plan/mutation/origin refs are NULL. It stores closed
  closed reason: recovery exactly for consumed; expired exactly for expiry_cleanup;
  stale_plan|epoch_mismatch|digest_mismatch|policy_denied exactly for human_revoked; and
  expired|stale_plan|policy_denied exactly for building_abort. It stores transitioned_at and
  created_at. Every reference column is nullable at DDL level but each
  trigger kind requires the exact set above and requires every other typed column NULL. Its
  finalized evidence commit/audit CASes the authorization active-to-terminal (or the building
  authorization to revoked for building_abort) and stores terminal_transition_id. Consumed
  finalization is coupled atomically to the exact quorum execution/state transition;
  expiry/replacement uses revoked. The authorization's
  null-to-transition-ID CAS is the single effective terminal claim. Creation evidence is never
  reused; concurrent consume/revoke sources may be building but only one can finalize.
- vault_post_review_gate is the retained installation-wide serialization row: installation_id
  primary/FK, gate_epoch integer >=1, blocker_set_digest BINARY(32), open_obligation_count,
  unresolved_finding_count, overdue_open_count, blocking_count, nullable pending_graph_execution_id
  XOR pending_authority_witness_id XOR pending_quorum_mutation_id XOR
  pending_evidence_commit_id XOR pending_origin_execution_id, and updated_at. Counts are bounded
  integers; blocking_count is the
  number of distinct open obligations and is never trusted without the indexed full-set query.
  The five typed pending FKs name an already-existing operation/commit/origin coordinator and exactly
  one remains durably non-null for the whole claimed operation, including across a committed crash.
  Connection-local context is required for each claim/finalize/abort statement but its clearing never
  clears or legitimizes the durable pointer. Claim preserves epoch/digest/counts and
  installs the pointer. Finalize recomputes the complete canonical blocker set, advances gate_epoch
  exactly one, stores all counts/digest, and clears the pointer in one guarded UPDATE. DELETE,
  restore, unguarded update, a second pending claim, epoch exhaustion, and partial clear reject.
  Migration inserts exactly one row with installation PK, gate_epoch=1, the canonical digest of the
  empty post-review-blocker-set.v1 document at epoch 1, all four counts zero, every pending pointer
  NULL, and explicit canonical UTC time. The installation PK/constant identity prevents a duplicate;
  missing, duplicate or malformed baseline fails schema readiness. Every action plan stores the exact
  locked pre-claim epoch/digest. Every obligation/evidence
  finalizer and every access-expanding graph/authority/quorum finalizer must own this gate; other
  graph reductions do not change it but still recheck their bound snapshot before apply.
- vault_post_review_origin_executions is the one-use composite coordinator: id UUIDv7,
  action_plan_id, origin_kind approval_consumption|recovery_authorization_consumption, expected
  operation_kind graph|authority|quorum, preallocated expected_operation_id and
  expected_consumption_id UUIDv7 values, stage assembling|claimed|effects_started|obligation_finalized|
  consumption_finalized|completed|aborted, active_slot, nullable authorization_snapshot_digest
  exactly raw SHA-256 of `nexum.vault.post-review-authorization-snapshot.v1`,
  effects_started_at, nullable terminal_transition_id UNIQUE, expires_at byte-equal to the bound
  action-plan/approval-request expiry and later than DB time at creation, finalized/aborted
  provenance, and created_at. It is inserted as assembling without a gate claim. Its expected IDs
  deliberately have no forward FKs to future rows; instead the later typed
  operation, consumption and obligation each hold a non-null FK back to this coordinator and triggers
  byte-check those preallocated IDs. This is the only insert-order exception and cannot authorize a
  row by itself. assembling through consumption_finalized require active_slot=1; completed/aborted
  require NULL. UNIQUE(action_plan_id,active_slot) and closed kind/ID/context guards prevent a second
  live coordinator. Once the operation, consumption, obligation and reciprocal obligation binding
  all exist inert, the post-review gate claim finalizer verifies that exact complete assembly and
  changes assembling-to-claimed. Neither an assembling row nor any child may be consumed or trusted.
  Immediately before the first target/evidence subject, all plan/proof/Client/
  authority/gate facts are revalidated under their locks, the exact consumed pre-state witness and
  immutable authorization snapshot are stored, and claimed changes once to effects_started. Later
  plan/proof TTL expiry or actor-auth-epoch drift cannot strand that exact action: subsequent stages
  accept only the consumed witness/snapshot and exact-ID CAS, never a new or different action. The
  domain/gate fences prevent competing Vault graph/authority changes; PDP still rechecks live Client
  visibility when access is later used. Only the last composite finalizer may change
  consumption_finalized-to-completed. Typed whole-origin abort may change assembling-to-aborted over
  the exact existing inert subset, or claimed-to-aborted over the required complete child set, only
  while every target/evidence subject count remains zero; effects_started and later are resume-only.
- vault_post_review_origin_transitions is append-only standalone source evidence: id UUIDv7,
  evidence_commit_id UNIQUE, origin_execution_id, transition_kind fixed aborted,
  executor_kind human|system_cleanup, nullable initiating_actor_id XOR system_actor_id, nullable
  actor_step_up_id required only for a human, closed reason requester_cancelled|expired|stale_plan|
  policy_denied, transitioned_at, and created_at. Its finalizer locks an assembling or claimed
  coordinator and every child that actually exists. For assembling it requires no gate pointer and
  accepts only the exact inert subset named by the coordinator's preallocated IDs; for claimed it
  requires the complete pending typed operation, building consumption/obligation sources, approval
  or recovery lifecycle, post-review gate and every domain fence. It proves zero target/evidence
  subjects and exact owned pointers, then in one transaction CASes the coordinator to aborted,
  terminalizes all existing inert child rows, releases every still-owned slot/reservation/fence,
  clears the gate pointer when claimed, and finalizes the transition commit/audit. system_cleanup requires the canonical vault-control-plane actor and
  forbids human/proof refs; human requires the origin actor plus fresh step_up and forbids the system
  actor. Multiple building or aborted transition attempts may reference one coordinator and remain
  inert; finalization CASes coordinator.terminal_transition_id NULL-to-the-winning finalized
  transition, so exactly one may terminalize it. Standalone cleanup of an origin-bound child is
  forbidden in assembling/claimed and effects_started-or-later alike; the former use this whole-
  origin transition and the latter can only resume. Abort from effects_started or later, wrong owner,
  replay, partial release, delete or restore rejects. Crash/evidence-abort retry and two-transition
  races retain every losing source without blocking a fresh attempt. Retained aborted rows cannot
  collide with a fresh plan/request/origin retry.
- vault_post_review_obligations has id UUID, evidence_commit_id UNIQUE,
  post_review_origin_execution_id non-null UNIQUE,
  origin_type approval_consumption|recovery_authorization_consumption,
  approval_consumption_id XOR recovery_authorization_transition_id with each typed FK UNIQUE,
  graph_execution_id XOR authority_witness_id XOR quorum_mutation_id with each typed FK UNIQUE,
  nullable request_id copied only from approval consumption, originating_actor_id,
  required_plan_digest, required_at, due_at, and created_at. It is append-only.
  A building obligation source is inert. Its finalizer verifies the exact origin coordinator/gate
  claim, pending typed operation with effects complete, building consumption source and preinserted
  typed audits; for approval consumption the authority source must be a sole-admin or TOTP-enrollment
  exception, and recovery authorization consumption must target the same cooling-recovery mutation.
  It then initializes open lifecycle, finalizes only the obligation commit, and CASes the coordinator
  effects_started-to-obligation_finalized while every operation/consumption/domain/gate fence
  remains pending. The consumption finalizer runs second, requires that exact finalized open
  obligation, and CASes obligation_finalized-to-consumption_finalized. The operation finalizer runs
  last and is the only transition that can make the origin effective, update gate blocker state,
  clear all fences and CAS the coordinator consumption_finalized-to-completed. One origin cannot
  create two obligations. Evidence
  may have many rows for one obligation; there is deliberately no UNIQUE constraint on
  evidence.obligation_id.
- vault_post_review_obligation_lifecycle is the sole mutable 1:1 projection: obligation_id primary/FK,
  state open|confirmed, next_sequence, nullable pending_evidence_commit_id/pending_sequence/
  pending_evidence_type, confirmed_evidence_id, nullable overdue_observed_commit_id,
  nullable reviewed_at, and updated_at.
  Evidence creation reserves exactly
  the next sequence under the same evidence-commit context. Finalization advances next_sequence and
  clears the reservation; confirmation also changes open-to-confirmed and sets reviewed_at from the
  finalized confirmation's canonical DB time. `confirmed_evidence_id` and `reviewed_at` are both NULL
  iff state=open; both are non-null iff state=confirmed, and the ID/time must equal the one finalized
  confirmation source/commit for this obligation. Mixed NULL pairs, a building/aborted confirmation,
  or a different obligation rejects on both drivers. Abort clears the reservation and advances past the inert source's sequence.
  No transition from confirmed is allowed.
- vault_post_review_finding_lifecycles is one mutable projection per finalized finding: finding_id
  primary/FK, state open|remediated, revision, nullable pending_evidence_commit_id,
  finalized_remediation_id, finalized_commit_id, and updated_at. A remediation commit reserves the
  exact open revision and obligation sequence; finalization CASes open-to-remediated and records its
  immutable source/commit. Both finalized IDs are NULL iff state=open; both are non-null iff
  state=remediated and must identify the same finalized remediation source and its exact finalized
  evidence commit. Mixed NULL pairs, cross-finding IDs and building/aborted commits reject on both
  drivers. Building or aborted remediation rows never own the resolution. Cleanup
  clears only the matching pending reservation, enabling a fresh remediation; ordinary update,
  delete, reopen or second finalized remediation rejects.
- vault_post_review_overdue_observations is one-shot immutable source evidence: id UUIDv7,
  evidence_commit_id UNIQUE, obligation_id, observer actor_id bound to an existing human or the
  canonical exact-`DISABLED` system actor, and observed_at from DB time. DB time at/after due_at is the only
  time eligibility rule, but its finalizer also requires lifecycle=open while holding obligation and
  post-review-gate locks. It CASes lifecycle.overdue_observed_commit_id from NULL to this commit and
  advances the gate epoch/digest/counts. Building/aborted competitors reserve nothing and only one
  observation finalizes; confirmation-versus-overdue races share the lifecycle reservation and one
  winner. Expansion denial is derived directly from due_at/open state even when no observation has
  yet been written.
- vault_post_review_evidence has id UUID, evidence_commit_id UNIQUE, obligation_id,
  sequence_number, reviewer_id,
  reviewer_step_up_id, evidence_type finding|remediation|confirmed, nullable
  resolves_evidence_id for remediation only, reviewed_plan_digest, typed reason, non-null
  reviewed_at, and created_at. Unique obligation+sequence plus obligation/finding lifecycle CAS
  constraints permit many building attempts but one finalized remediation per finding and exactly
  one finalized confirmation. resolves_evidence_id has no source-level UNIQUE. It is
  immutable source evidence; only its commit/lifecycle projection changes trust state.
- vault_approval_consumptions has id UUID, evidence_commit_id UNIQUE, request_id,
  mutation_type graph|authority|quorum, graph_execution_id XOR authority_witness_id XOR
  quorum_mutation_id with the selected typed FK UNIQUE, nullable post_review_origin_execution_id
  required exactly for sole-admin or TOTP-enrollment-exception use, executor_id, executor_proof_kind,
  executor_step_up_id XOR executor_totp_enrollment_proof_id, plan_digest,
  decision_id XOR sole_exception_id XOR totp_enrollment_exception_id, consumed_at, and created_at.
  Enrollment proof and enrollment exception in an approval consumption are permitted only for the
  same-purpose authority.totp_confirm plan. A cooling-recovery enrollment proof is consumed only by
  the separate quorum recovery authorization/mutation transition and never appears as an enrollment
  exception or approval consumption. Executor must equal request requester;
  insertion and mutation are one transaction. The selected execution/witness/quorum mutation already
  exists pending. The consumption commit remains building and lifecycle approved-to-consumed remains
  reserved while that exact operation executes. Only the same connection/transaction execution
  context may use this building reservation; every unrelated consumer rejects it. For ordinary
  independently approved work, the graph/authority/quorum mutation is applied with its operation
  audit before the exact consumption audit/commit finalizes lifecycle. For sole-admin or TOTP-
  enrollment-exception use, the selected operation and consumption both reference the same pending
  post-review-origin coordinator; the exact obligation finalizes open first, then the consumption
  audit/commit transitions lifecycle to consumed while all domain/global fences remain installed,
  and the operation becomes applied last. A consumed lifecycle behind a pending origin coordinator
  is never an authorization allow. Orphan pending operations, building commits, wrong-family FKs and
  partial commits are inert and never become authorization.
- The consumption relationship is deliberately one-way and cycle-free. A graph execution,
  authority witness, or quorum mutation is first inserted pending with action_plan_id plus a closed
  authorization_mode, but no reverse approval_consumption_id. The later consumption row points to
  exactly one existing pending typed row; each typed FK is individually UNIQUE when non-null and the
  DB verifies identical plan/request. For approval_required mode, every target/apply/finalize trigger
  requires that exact building/finalized consumption plus a valid lifecycle reservation. The
  operation and consumption finalizers prove the relation through typed FK+UNIQUE+plan and never
  through an unenforced polymorphic/future ID.
- vault_graph_write_executions has id UUIDv7, action_plan_id UNIQUE, validated scope identity with no
  FK to a future first-use anchor, actor_id,
  actor_step_up_id, authorization_mode none|approval_required fixed by the graph plan, expires_at,
  expected_subject_count,
  consumed_subject_count, state active|consumed|revoked, null-safe active_slot, finalized_at,
  revoked_at/reason, nullable post_review_origin_execution_id and required_obligation_id, and
  created_at. Those two FKs are both NULL for an ordinary operation; a post-review origin requires
  coordinator first and then exactly one pending-only NULL-to-obligation bind after the obligation
  exists. It must have one exact claim and one exact finalize scope subject
  before claim; activation/finalization consume them while setting/clearing the exact scope anchor's
  pending_graph_execution_id under lock.
- vault_graph_write_execution_scope_subjects has exactly two rows per execution, kind claim|finalize,
  unique execution+kind, exact scope identity, exact OLD/NEW epoch, five set digests and pending
  execution UUID, state pending|consumed, active_slot, consumed_at, and created_at. Claim preserves
  epoch/digests and only installs the fence; finalize requires old+1 plus post-state digests and clears
  it. First-use claim carries the canonical empty before-state and inserts the claimed anchor. No
  arbitrary column map or JSON is accepted.
- vault_graph_write_execution_subjects is append-only except for one pending-to-consumed transition:
  id UUIDv7, execution_id, plan_subject_id, sequence, target_kind access_group|collection|
  membership|collection_item|grant, mutation_kind insert|rename|disable|add|revoke, preallocated
  target UUIDv7, exact typed scope and OLD/NEW scalar values, state pending|consumed, null-safe
  active_slot, consumed_at, and created_at. The subject does not FK to its not-yet-created target.
  UNIQUE execution+sequence, execution+plan_subject, and typed target-operation keys prevent hidden
  or duplicate statements; no JSON, free-form column name, or SQL fragment is accepted.
- vault_graph_mutations has id UUIDv7, immutable graph plan, graph execution UNIQUE, actor,
  actor_step_up_id, exact graph row and execution-subject references, before/after digests, optional
  approval consumption required exactly for expansion, typed reason, state pending|applied,
  nullable primary_audit_event_id, applied_at, and created_at. Pending requires audit/applied_at NULL;
  the one finalizer transition requires both non-null and primary_audit_event_id UNIQUE, then
  finalizes that event's pending trust projection as its last guarded write.
  Each graph row's created/last execution-subject references and this mutation's reverse references
  form the exact create/rename/disable/add/revoke provenance without an FK cycle.
- vault_authority_mutations has immutable action/authority plan, optional request/consumption,
  actor_id, actor_proof_kind, actor_step_up_id XOR actor_totp_enrollment_proof_id,
  nullable witness_id and user_security_execution_id, before/after candidate/governance-ready-roster and
  global-authority-facts
  digests, expansion|reduction|emergency type, exact epochs, typed reason, state pending|applied,
  nullable primary_audit_event_id, applied_at, and created_at. Pending requires audit/applied_at NULL;
  the one finalizer transition requires both non-null and primary_audit_event_id UNIQUE and
  finalizes that event's pending trust projection last. A mutation
  with any UserManagement/Spatie subject requires both witness_id and user_security_execution_id as
  non-null UNIQUE FKs; the witness's own UNIQUE execution FK, identical plan and consumed subject set
  must match. A state-only mutation forbids both. No JSON or free-form context.
- vault_authority_mutation_subjects has one immutable row per affected user, protected role, or
  protected permission with before/after membership/effect and epoch. Role-wide changes enumerate
  every affected user under lock; no JSON or compressed ID list is accepted.
- vault_quorum_mutations has id UUIDv7, action_plan_id UNIQUE, operation
  peer_unlock|cooling_recovery, actor_id, actor_proof_kind and exact proof XOR, exact semantic
  authority-state subject, before/after quorum state, candidate/governance-ready roster/global-
  authority-facts digests and epochs, plan digest, expires_at byte-equal to the bound plan expiry,
  authorization_mode
  approval_required|recovery_authorization, nullable recovery_authorization_id required only for
  cooling recovery, and nullable authority_witness_id plus user_security_execution_id permitted and
  required together only when cooling recovery changes UserManagement/Spatie rows. The witness's
  UNIQUE execution FK and identical plan/subjects must match. It also has nullable
  post_review_origin_execution_id and required_obligation_id; both are required for cooling recovery,
  both are forbidden for ordinary peer unlock, and required_obligation_id has the same one-time
  pending NULL-to-exact-obligation bind. State is
  pending|state_consumed|applied|revoked with a null-safe active
  slot, nullable primary_audit_event_id, state_consumed_at, applied_at, revoked_at/reason, and
  created_at. Pending/state_consumed/revoked require primary_audit_event_id NULL; applied requires it
  non-null and UNIQUE. It is precreated pending,
  the authority-state trigger performs only the exact pending-to-state_consumed transition, and
  final fence clearing performs only state_consumed-to-applied after required typed audit/evidence
  and binds that exact event as primary_audit_event_id.
  The same guarded finalizer changes the preinserted event's trust projection pending-to-finalized
  LAST; no state_consumed or pending quorum row can expose a trusted success/unlock event.
  It is the sole one-use applied record and execution fence for a quorum plan.
- vault_quorum_recoveries is a 1:1 extension of a cooling_recovery mutation with exact cooling/
  eligibility facts, before/current/restored roster digests, notification outcome
  attempted|succeeded|failed|unsupported plus safe provider code, state pending|applied, and
  post-review source identity. It is inserted pending before the obligation. The recovery-
  authorization consumption transition and obligation finalize while the quorum mutation remains
  state_consumed; the last quorum finalizer changes both mutation/recovery to applied only after their
  exact reciprocal obligation/audits pass. It cannot target any grant, group, collection, item, or
  content operation.
- vault_authority_write_witnesses has id UUID, action_plan_id UNIQUE,
  user_security_execution_id non-null UNIQUE, actor_id,
  actor_proof_kind step_up|totp_enrollment, actor_step_up_id XOR
  actor_totp_enrollment_proof_id,
  authorization_mode none|approval_required|quorum_recovery, nullable
  quorum_recovery_authorization_id required only for quorum_recovery, expires_at,
  expected_subject_count,
  consumed_subject_count, state active|consumed|revoked, active_slot, finalized_at/revoked_at/reason,
  nullable post_review_origin_execution_id and required_obligation_id, and created_at. Those two FKs
  are both NULL for an ordinary operation; a post-review origin requires coordinator first and then
  exactly one pending-only NULL-to-obligation bind after the obligation exists. The UserSecurity
  execution exists first; its plan, actor and exact UM/Spatie subjects must match,
  and no reverse execution FK exists. Activation sets
  vault_authority_state.pending_authority_witness_id under lock. UserSecurity/Spatie triggers locate
  the witness only by this UNIQUE execution identity and fail closed if phase is enforced and the
  pair/context does not match.
- vault_authority_write_witness_subjects is append-only except for one pending-to-consumed transition:
  id UUID, witness_id, sequence, target_kind user|model_has_roles|role_has_permissions|
  model_has_permissions|vault_authority_state, mutation_kind insert|update|delete, exact typed target
  IDs, non-secret closed plan-subject identity and applicable status/presence/epoch before/after
  fields, state pending|consumed, active_slot, consumed_at, and created_at. Credential/hash/value
  payload columns do not exist. Unique
  witness+sequence and target-operation identities prevent duplicate or hidden subjects; no JSON,
  free-form column name, SQL fragment, or compressed ID list is accepted.

All active_slot constraints are null-safe. Each table names its one slot-bearing live state: active
always requires active_slot=1; a building/pending state requires 1 only where that table explicitly
declares the pending record to own a live claim. Pending proof creation above is inert and requires
NULL until its creation commit activates it. Every finalized/consumed/revoked/aborted terminal state
requires NULL.
Every active uniqueness is enforced with a unique key and driver-parity trigger. All lifecycle rows
reject INSERT in a terminal state, ordinary UPDATE, restore, and DELETE. Scope/group/collection/
installation epochs must increase exactly once with their guarded mutation. DB guards enforce exact
digest shape and required digest change; application services alone recompute and constant-time
verify canonical bytes under the locked rows before any decision or finalization.

## Typed Audit Extension

`vault_audit_events` remains immutable, but every event has a one-to-one
`vault_audit_event_trust` projection: event_id primary/RESTRICT FK, state pending|finalized|aborted,
owner_kind exactly foundation_existing|foundation_direct|evidence_commit|graph_mutation|
authority_mutation|quorum_mutation|user_security_execution, nullable evidence_commit_id,
graph_mutation_id, authority_mutation_id, quorum_mutation_id and user_security_execution_id typed
RESTRICT FKs, nullable finalized_at, and nullable
aborted_at/reason/aborted_by_commit_id. Each typed owner FK is UNIQUE when non-null.
foundation_existing requires all five owner FKs NULL and starts finalized only in the cutover-
manifest backfill. foundation_direct also requires all five owner FKs NULL, but only for one of the four
existing foundation event types with its exact existing typed-reference/reason mapping; the
preallocated event itself plus those references are its closed persisted source form. Every other
owner_kind requires exactly its matching one typed FK non-null and all other owner FKs NULL; a
generic polymorphic owner_id does not exist.

The application never inserts or omits a trust child separately. An AFTER INSERT trigger on
`vault_audit_events` requires the one-use `VaultAuditEventInsertContext`, verifies the
preallocated event/type/outcome/reason/actor/proof/reference shape and already-existing typed owner,
and atomically inserts exactly one pending trust child with the matching owner binding. The entire
base INSERT statement rolls back when context or binding is absent/wrong or the child cannot be
created. Before any trust-table DML, a temporary BEFORE INSERT guard is installed on
`vault_audit_event_trust`. It accepts only (a) the audit AFTER INSERT trigger's consumed,
one-statement `VaultAuditEventInsertContext` for one matching pending child, or (b) one consumed
`FoundationAuditTrustBackfillContext` at stage trust_backfill for one finalized child whose exact
event and cutover item belong to a manifest_captured header. Direct, missing, replayed, cross-event,
wrong-state, or out-of-manifest INSERT rejects. The permanent guard later removes branch (b) and is
catalog-verified before the temporary guard is replaced; there is never an unguarded trust INSERT
window. Duplicate trust, base INSERT without context, and a committed base orphan reject on SQLite
and MariaDB. `RecordVaultAuditEvent` uses the same closed context for new
foundation_direct events. The authoritative evidence-commit, graph/authority/quorum operation, or
foundation-direct finalizer verifies reciprocal typed references and marks trust finalized
atomically as its LAST guarded write; graph/authority/quorum applied mutations and a
password_rehash_on_login UserSecurity execution bind the same event through UNIQUE
primary_audit_event_id. An abort finalizer may only change an orphaned pending trust
row to aborted with exact typed cleanup provenance. No audit event or trust row is updated otherwise,
restored or deleted. Existing foundation audit rows are trust-backfilled only through the finalized
immutable pre-cutover manifest and one-use FoundationAuditTrustBackfillContext while the temporary
base-audit freeze and temporary trust guard remain installed. That context verifies each exact base
numeric ID, event UUID, manifest count/digest and existing event/reason/typed-reference shape before
inserting foundation_existing/finalized; it cannot create a
pending row, trust an event outside the manifest, or run after the permanent child guard is active.
The currently empty table still follows the same zero-entry manifest, 1:1 read-back and cutover
order. No permanent migration bypass or direct application trust INSERT exists.

The trust projection's NULL/state matrix is exact: pending requires every terminal field NULL;
finalized requires finalized_at non-null and every abort field NULL; aborted requires aborted_at,
closed reason and aborted_by_commit_id non-null while finalized_at is NULL. Only pending-to-finalized
or pending-to-aborted is legal, owner kind and typed FK never change, and both drivers reject a missing,
duplicate, cross-kind or already-terminal projection.

Ordinary audit repositories, health, export, retention, API and any future UI must read only the
typed `vault_trusted_audit_events` view/repository, which requires trust.state=finalized and every
owner-specific postcondition. Base, pending and aborted rows are internal repair/diagnostic state and
are never evidence, success, unlock, readiness or authorization truth. The special
auth_security_invalidated aggregation additionally requires its referenced UserSecurity execution
finalized before the trusted repository returns the event, even though its transition evidence
commit was finalized earlier behind the still-owned UserSecurity gate. Direct base-table audit reads
outside the migration/repair repository fail the public contract.

The existing append-only vault_audit_events receives nullable typed FK columns for evidence_commit,
evidence_commit_abort, aborted_evidence_commit, action_plan,
access_group, membership, collection, collection_item, grant, step_up_proof,
totp_enrollment_proof, step_up_proof_transition, step_up_attempt_evidence, actor_step_up_proof, graph_execution,
graph_execution_subject, graph_mutation, approval_request, approval_decision,
approval_cancellation, approval_expiration, approval_invalidation, approval_consumption,
sole_exception, totp_enrollment_exception,
post_review_origin_transition, post_review_obligation, post_review_evidence, post_review_overdue_observation,
user_security_mutation_execution, authority_mutation, authority_witness,
quorum_mutation, quorum_recovery_authorization, quorum_recovery_authorization_transition, and
quorum_recovery. No JSON, arbitrary context,
secret, password/presented-factor-derived hash, raw exception, path, or presented factor is
accepted. Named structural/session/authorization digests remain only in their typed source tables
and are never copied into audit.

Audit actor mapping is null-safe and exhaustive. `executor_kind=human` requires the exact active
human actor and operation proof while forbidding system_actor_id. `executor_kind=system_cleanup`
requires only the canonical disabled `vault-control-plane` system actor and forbids initiating-human
and proof columns. When a system cleanup or overdue event has a human-originated source, that human
appears only through the exact source FK/originating_actor_id; it is never mislabeled as executor.
Both drivers reject missing, swapped, dual, login-capable, role-bearing, or permission-bearing system
actors.

The four foundation event types and their existing reason/outcome mappings remain unchanged. Slice
04 extends the application enums and both driver CHECK/trigger allowlists with exactly the values
below. Each listed event type has the one outcome and only the reason codes on its row; grouping
means that every named event has that same closed mapping. The corresponding proof, mutation,
failure, revocation and post-review source rows use these same reason_code values. No alias,
unlisted event/outcome/reason, arbitrary human text, factor detail, or raw exception can persist.

| Exact event_type value(s) | Exact outcome | Allowed reason_code values |
| --- | --- | --- |
| graph_plan_created | success | operational_need, access_required |
| authority_plan_created | success | role_change, user_deactivated, permission_correction, totp_enrollment, totp_reset, employment_change, emergency_change, emergency_security_deactivation |
| quorum_plan_created | success | quorum_lock, recovery, emergency_change |
| access_group_created, access_group_renamed, collection_created, collection_renamed | success | operational_need, access_required |
| access_group_disabled, collection_disabled | revoked | operational_need, permission_correction, suspected_compromise |
| membership_added | success | access_required, operational_need, role_change |
| membership_revoked | revoked | employment_change, permission_correction, suspected_compromise, role_change |
| collection_item_linked | success | access_required, operational_need |
| collection_item_revoked | revoked | operational_need, permission_correction, suspected_compromise |
| grant_created | success | access_required, operational_need |
| grant_revoked | revoked | permission_correction, suspected_compromise, employment_change |
| user_security_password_rehashed | success | password_hash_policy_upgrade |
| step_up.proof_issued | success | operational_need |
| step_up_denied | denied | invalid_credentials, invalid_totp, totp_replay, rate_limited, policy_denied, epoch_mismatch |
| step_up_revoked | revoked | epoch_mismatch, user_deactivated, totp_reset, expired, suspected_compromise, stale_plan, policy_denied |
| totp_enrollment.proof_issued | success | totp_enrollment, recovery |
| totp_enrollment_proof_consumed | consumed | totp_enrollment, recovery |
| totp_enrollment_proof_revoked | revoked | epoch_mismatch, totp_reset, expired, stale_plan, policy_denied |
| totp_enrollment_confirmed | success | totp_enrollment |
| totp_enrollment_denied | denied | invalid_credentials, invalid_totp, totp_replay, rate_limited, stale_plan |
| totp_enrollment_exception_created | success | totp_enrollment, post_review_required |
| approval_requested | success | operational_need, access_required, role_change, totp_enrollment, emergency_change, recovery |
| approval_approved | success | operational_need, access_required, role_change, totp_enrollment, recovery |
| approval_rejected | rejected | approver_rejected, policy_denied, governance_floor, client_access_denied, scope_missing |
| approval_cancelled | cancelled | requester_cancelled |
| approval_expired | expired | expired |
| approval_invalidated | invalidated | epoch_mismatch, digest_mismatch, stale_plan, client_access_denied, scope_missing, policy_denied, governance_floor |
| approval_consumed | consumed | operational_need, access_required, role_change, totp_enrollment, recovery |
| approval_sole_exception_created | success | post_review_required |
| authority_mutation_applied | success | role_change, user_deactivated, permission_correction, totp_enrollment, totp_reset, employment_change |
| authority_emergency_deactivation | locked | emergency_security_deactivation |
| quorum_peer_unlocked | unlocked | recovery, quorum_lock |
| quorum_recovery_authorized | success | recovery |
| quorum_recovery_authorization_consumed | consumed | recovery |
| quorum_recovery_authorization_revoked | revoked | expired, stale_plan, epoch_mismatch, digest_mismatch, policy_denied |
| quorum_recovered | unlocked | recovery, post_review_required |
| post_review_origin_aborted | invalidated | requester_cancelled, expired, stale_plan, policy_denied |
| post_review_obligation_created | success | post_review_required |
| post_review_finding | finding | post_review_finding |
| post_review_remediation | remediated | permission_correction, post_review_finding |
| post_review_confirmed | confirmed | post_review_confirmed |
| post_review.overdue_observed | locked | post_review_required, expired |
| evidence_commit_aborted | invalidated | requester_cancelled, expired, stale_plan, policy_denied, orphaned_building_evidence, cleanup_repair |

`authority_plan_created` has one further closed operation predicate: an
`authority.emergency_deactivate` plan requires exactly `emergency_security_deactivation`; that token
is forbidden for every other operation, and `emergency_change` or any other row-level allowed reason
cannot substitute for it. The same predicate is enforced by the plan row, plan-created audit insert,
audit-trust finalizer and applied emergency mutation.

Authority operation-to-primary-event mapping is exhaustive. `authority.totp_confirm` uses only
`totp_enrollment_confirmed`; `authority.emergency_deactivate` uses only
`authority_emergency_deactivation`; `authority.totp_reset`, `authority.totp_disable`, and every
other ordinary `authority.*` operation use only `authority_mutation_applied`. The generic event
therefore explicitly excludes confirm and emergency. Each pending mutation can bind exactly one
primary event under its operation mapping, and both event and mutation triggers reject a second or
cross-mapped success event.

The complete Row04 outcome enum is
success|denied|rejected|cancelled|expired|invalidated|consumed|revoked|locked|unlocked|finding|
remediated|confirmed. The complete Row04 reason enum is exactly the union shown above:
operational_need, access_required, role_change, user_deactivated, suspected_compromise,
employment_change, permission_correction, totp_enrollment, totp_reset, requester_cancelled,
approver_rejected, invalid_credentials, invalid_totp, totp_replay, rate_limited,
client_access_denied, scope_missing, policy_denied, epoch_mismatch, digest_mismatch, stale_plan,
governance_floor, quorum_lock, emergency_change, emergency_security_deactivation, recovery,
password_hash_policy_upgrade, orphaned_building_evidence, cleanup_repair, post_review_required,
post_review_finding, post_review_confirmed, and expired.

The application enum and both driver triggers additionally share this exhaustive
required/forbidden reference matrix:

The table describes the postconditions for a finalized trusted event. At base-event INSERT, the
closed operation branch may reference its exact existing pending mutation or building evidence source
and must create trust=pending; it cannot claim the applied/finalized postcondition. The owning
finalizer rechecks every required reference/state and only then finalizes trust. Thus wording such as
"applied mutation" or "finalized source" below is a trust-finalization requirement, not an impossible
preinsert requirement. Forbidden references apply at both insertion and finalization.

Every standalone source event in this matrix also requires its exact building evidence_commit,
source.evidence_commit_id, and audit.evidence_commit_id while being assembled; the uniform finalizer
then requires that exact audit, marks the commit finalized, and marks its pending audit-trust
projection finalized LAST. auth_security_invalidated uses only the deferred aggregate-finalizer
branch defined above. The other exception is
evidence_commit_aborted, which uses a different finalized cleanup commit and references the inert
building commit it terminalizes. Applied graph, authority, emergency and quorum mutation audits use
their existing transaction fence/preinsert-audit protocol rather than a standalone evidence commit,
but their finalizer likewise verifies the exact pending audit/trust row exists, sets the mutation's
primary_audit_event_id to that event, changes the mutation to applied, and finalizes audit trust LAST
before clearing the enclosing fence.
For these rows audit.event_id must equal mutation.primary_audit_event_id; no other audit can satisfy
the reciprocal binding.

| Event family | Required references | Forbidden references |
| --- | --- | --- |
| graph plan created | evidence_commit+exact action_plan+requester actor_step_up_proof | mutation, decision, consumption, and content refs |
| authority plan created | evidence_commit+exact action_plan+requester proof-kind XOR matching proof | mutation, decision, consumption, and content refs |
| quorum plan created | evidence_commit+exact finalized quorum action_plan+state subject+requester proof-kind XOR matching proof | mutation, decision, consumption, and content refs |
| access_group/collection created, renamed, or disabled | action_plan+graph execution+consumed execution subject+applied graph mutation whose primary audit is this event+actor_step_up_proof+exact graph row; approval request+consumption only if classified expansion | unrelated graph, authority, quorum, and content refs |
| membership or collection_item added/revoked | action_plan+graph execution+consumed execution subject+applied graph mutation whose primary audit is this event+actor_step_up_proof+exact parent and relationship; approval request+consumption iff expansion | unrelated graph, authority, quorum, and content refs |
| grant created/revoked | action_plan+graph execution+consumed execution subject+applied graph mutation whose primary audit is this event+actor_step_up_proof+exact grant/subject/target; creation always request+consumption | unrelated graph, authority, quorum, and content refs |
| UserSecurity password hash rehashed | user_security_execution(flow password_rehash_on_login)+its consumed no-secret subject+same human actor+safe pre-auth correlation digest+primary audit/trust; execution may finalize the event only after successful locked password revalidation, protected hash write, exact auth epoch advance and all old proof invalidations | password/hash/cost, step-up proof, Vault action plan/witness/mutation, system actor, graph/quorum/content refs, and every other UserSecurity flow |
| step_up proof issued | evidence_commit+actor+new step_up_proof | plan, graph, authority, approval, and content refs |
| step_up denied | evidence_commit+actor+durable step_up_attempt_evidence, no proof ID or prunable rate-failure ref | plan, graph, authority, approval, and content refs |
| step_up revoked | evidence_commit+actor or canonical system actor per executor kind+step_up_proof_transition(kind step_up, revoked)+exact proof, trigger-kind XOR refs, and closed reason; auth_security_invalidated source finalization requires the exact gate-owning UserSecurity execution in effects_started|subjects_complete plus its epoch/proof subjects and forbids Vault witness/mutation, while trusted read/post-state use additionally requires that execution finalized and gate cleared; creation commit is forbidden | every typed transition ref forbidden by its trigger kind, unrelated plan/graph/content refs, enrollment-proof and recovery-authorization refs |
| TOTP-enrollment proof issued | evidence_commit+actor+new totp_enrollment_proof+exact retained pending generation and purpose | plan, graph, authority mutation, approval, step_up_proof, and content refs |
| TOTP-enrollment proof consumed/revoked | evidence_commit+actor or canonical system actor per executor kind+step_up_proof_transition(kind totp_enrollment, exact terminal/trigger kind)+exact enrollment proof/generation/purpose; authority consumption requires exact authority mutation+UserSecurity execution+witness; quorum consumption requires exact quorum mutation and, iff its typed plan changes TOTP readiness, its exact UserSecurity execution/witness together; actor replacement requires exact pending replacement proof; auth-security invalidation source finalization requires only its exact gate-owning UserSecurity execution in effects_started|subjects_complete plus epoch/proof subjects, and trusted read/post-state use requires that execution finalized; authority invalidation requires exact mutation | every typed transition ref forbidden by its trigger kind, ordinary step_up proof, unrelated plan/mutation, grant, and content refs |
| TOTP enrollment confirmed | exact authority.totp_confirm action_plan+UserManagement security execution+applied authority mutation whose sole primary audit is this event+witness+consumed enrollment-proof transition for the same actor/generation/purpose | generic authority_mutation_applied success event, unconsumed/revoked proof transition, unrelated approval, grant, graph, and content refs |
| TOTP enrollment denied | evidence_commit+actor+durable step_up_attempt_evidence(kind totp_enrollment)+exact pending generation, with no proof ID | plan, graph, authority mutation, approval, and content refs |
| TOTP enrollment exception created | evidence_commit+authority.totp_confirm action_plan+request+exception+matching active enrollment proof/generation; lifecycle becomes approved, not consumed | independent decision, sole exception, quorum-recovery, unrelated mutation, and content refs |
| approval requested | evidence_commit+action_plan+request+requester proof-kind XOR matching proof | decision, cancellation, consumption, exception, and mutation refs |
| approval approved/rejected | evidence_commit+action_plan+request+decision+approver actor_step_up_proof | cancellation, consumption, exception, and mutation refs |
| approval cancelled | evidence_commit+action_plan+request+cancellation+requester human actor+fresh active same-session step_up proof | system actor, expiration/invalidation, decision, consumption, exception, and mutation refs |
| approval expired | evidence_commit+action_plan+request+expiration(trigger db_time_expired, executor system_cleanup)+canonical vault-control-plane actor+DB time at/after expires_at | human actor/proof, cancellation/invalidation, decision, consumption, exception, and mutation refs |
| approval invalidated | evidence_commit+action_plan+request+invalidation(closed drift trigger, executor system_cleanup)+canonical vault-control-plane actor+exact typed drift anchor | human actor/proof, cancellation/expiration, untyped/free-form drift, decision, consumption, exception, and mutation refs |
| approval consumed | evidence_commit+action_plan+request+consumption+executor proof-kind XOR matching proof plus exact decision XOR sole exception XOR TOTP-enrollment exception. Ordinary mode requires the exact applied typed operation and forbids post-review refs. post_review_origin_mode requires the exact finalized open obligation+coordinator/gate pointer and exact effects-complete still-fenced typed operation; the operation is not yet applied. | cancellation, unrelated decision/exception; post-review refs in ordinary mode; missing obligation/coordinator or an already-applied operation in post_review_origin_mode |
| sole_exception created | evidence_commit+action_plan+request+sole_exception+requester actor_step_up_proof | independent decision and unrelated mutation refs |
| post_review origin aborted | evidence_commit+post_review_origin_transition+exact assembling XOR claimed coordinator+executor kind XOR exact human proof or canonical system actor. assembling requires no gate and the exact inert subset of operation/consumption/obligation children that currently exists; claimed requires gate+the complete pending operation/building consumption/obligation set. | any consumed target/evidence subject, incomplete claimed assembly, gate on assembling, effects_started-or-later coordinator, applied operation, unrelated decision/content refs, and every actor/proof ref forbidden by its executor kind |
| post_review obligation created | evidence_commit+obligation+exact post_review_origin_mode coordinator/gate+effects-complete still-fenced pending typed operation+building lifecycle-reserved approval consumption XOR recovery-authorization transition+originating human actor; approval consumption must use sole_exception XOR totp_enrollment_exception | applied operation, finalized consumption, ordinary-decision origin, unrelated decision, factor, and content refs |
| post_review finding/remediation/confirmed | evidence_commit+obligation+evidence+reviewer actor_step_up_proof+exact source | graph, authority mutation, decision, and content refs |
| post_review overdue observed | evidence_commit+obligation+open lifecycle+overdue observation+exact human/system observer+claimed post-review gate and DB time at/after due_at | proof, graph, authority mutation, decision, grant, confirmed obligation, and content refs |
| authority mutation applied | ordinary non-confirm/non-emergency action_plan+applied authority_mutation whose sole primary audit is this event+witness and requester proof-kind XOR matching proof; exact UserSecurity execution is required iff any UserManagement/Spatie subject exists; request+consumption iff approval-bound | authority.totp_confirm, authority.emergency_deactivate, second/cross-mapped success event, missing/mismatched execution-witness pair, secret material, unrelated graph, quorum, and content refs |
| authority emergency deactivation/root lock | authority action_plan with reason exactly emergency_security_deactivation+applied authority_mutation whose primary audit is this event+authority witness+exact UserSecurity execution+actor proof and exact authority-state transition/root incident event | every other reason including emergency_change, grant, content, and unrelated graph/quorum refs |
| quorum peer-unlocked | quorum action_plan+applied quorum_mutation whose primary audit is this event+actor proof+request+consumption and exact authority-state transition; no UserManagement execution/witness | grant, content, and unrelated graph/authority refs |
| quorum recovery authorized | evidence_commit+quorum action_plan+recovery authorization+matching requester proof-kind XOR proof | recovery-authorization transition, recovery mutation, grant, content, decision, and unrelated graph refs |
| quorum recovery authorization consumed/revoked | evidence_commit+recovery authorization+authorization transition+exact terminal/trigger kind/reason. Ordinary consumed mode requires its exact applied cooling-recovery mutation. post_review_origin_mode requires the finalized open obligation+coordinator/gate and exact effects-complete still-fenced pending mutation. Human revocation requires a current governance candidate+fresh step-up; expiry_cleanup requires the canonical system actor, exact lock, and DB time at/after expiry; building abort requires the exact creation commit. | every typed transition ref forbidden by its trigger kind, cross-mode applied/pending mismatch, early/system expiry cleanup, human refs on system cleanup, grant, content, decision, and unrelated graph refs |
| quorum recovery applied | quorum action_plan+applied quorum_mutation whose primary audit is this event+recovery+consumed recovery-authorization transition+finalized post-review obligation; exact UserSecurity execution+authority witness required together only for UserManagement/Spatie subjects | revoked authorization transition, missing/mismatched execution-witness pair, grant, content, decision, and unrelated graph refs |
| evidence commit aborted | finalized cleanup evidence_commit+evidence_commit_abort+exact aborted_evidence_commit target+closed trigger/executor/reason. operator_cancel_pre_effects and permitted initiating_human_failure require the exact human actor+fresh active same-session proof and forbid system actor; db_time_expired, orphan_repair, system_repair and abort_lost_race require the canonical vault-control-plane actor and forbid human/proof. | wrong executor/trigger/reason, early expiry, mismatched origin actor/proof, untyped repair, graph/authority/quorum/content refs other than the typed inert target |

Each event has one allowed outcome/reason set and one exact proof rule. The
user_security_password_rehashed event's human actor is authenticated by the just-validated password
inside its exact UserSecurity execution and therefore requires no Vault step-up; no other audit event
inherits that exception. Required values use explicit
IS NULL defenses against SQL three-valued logic; all unrelated typed columns must be NULL. Proof
actor/session/epoch must match the referenced plan/evidence and be fresh at that action's DB time,
except that a completed self-affecting authority event may reference its terminally stale proof only
through the exact consumed witness that captured pre-state freshness and OLD-to-NEW epoch.
The only other freshness exception is a resume audit in `post_review_origin_mode` after
effects_started. Its obligation, consumption, and final operation audits may reference the now-
expired or auth-epoch-stale proof only when each requires the same coordinator UUID, immutable
authorization_snapshot, exact consumed pre-state witness, matching actor/plan/operation IDs, and a
coordinator stage at or after effects_started. Both drivers verify that the proof was fresh at the
snapshot's authoritative DB time. Before effects_started, for a different proof/plan/action, for a
new decision, or outside that origin, stale proof always denies.
Obligation creation inherits proof provenance from its exact source mutation/consumption, and
terminal proof revocation is the only other listed no-new-proof case.
Existing foundation events keep their current exact mapping. Audit insertion occurs in the same
transaction as the evidence or mutation it describes.

## Planned Implementation Shape

After named approval, implementation may add additive migrations and these boundaries:

- Contracts/VaultAuthorizationDecisionPoint, VaultScopeVisibility,
  VaultActorContextProvider, VaultAuthorityMutationGuard, and VaultSecurityNotifier;
- Adapters/Clients/CurrentClientVisibilityAdapter and a UserManagement-owned
  VerifiesVaultStepUpCredentials implementation;
- Data/VaultAuthorizationRequest, VaultAuthorizationDecision, VaultApprovalPlan,
  VaultReachabilitySnapshot, and redacted step-up result DTOs;
- Services/CentralVaultAuthorizationDecisionPoint, VaultGraphMutationService,
  VaultStepUpService, VaultApprovalService, VaultAuthorityMutationService, and
  VaultQuorumRecoveryService;
- a CLI-only, idempotent `nexum:vault-provision-control-plane` command that implements only the
  dormant typed role/permission provisioning context and safe live readiness read-back against the
  already-locked migration classification, fresh journal or existing-install evidence
  above; it exposes no phase transition or Vault runtime operation;
- closed enums for control operation, principal, target, lifecycle, decision, factor method,
  mutation type, audit event/outcome/reason, and denial reason;
- narrow repositories/actions that select explicit safe metadata columns and never use select-all
  for session digests, proof counters, or approval digests.

The central decision separates management from future content decisions. Slice 04 management calls
require mapped global permission, Client visibility when scoped, step-up, and approval per matrix;
they never require an existing content grant. A content decision always returns
unsupported_operation because the content operation catalog is empty.

app/Modules/Vault/routes.php remains empty. No provider/key/material code is invoked.

## Scope

- Add the metadata, integrity guards, permissions, services, adapters, cross-domain authority
  boundary, typed audit, and tests described above.
- Extend foundation schema readiness to require all Slice 04 tables, columns, indexes, FKs, and
  exact driver-specific guards before authorization health can be ready.
- Update current UserManagement/Fortify/CustomerPortal mutation paths to use the epoch/authority
  boundaries without adding a Vault UI.
- Keep VAULT_ENABLED=false and VAULT_RUNTIME_APPROVED=false; add only the strict bounded lifecycle
  configuration enumerated by this slice:
  `VAULT_STEP_UP_TTL_SECONDS`, `VAULT_APPROVAL_TTL_MINUTES`,
  `VAULT_IMMEDIATE_PLAN_TTL_MINUTES`, `VAULT_OPERATION_FENCE_TTL_SECONDS`,
  `VAULT_EVIDENCE_BUILD_TTL_SECONDS`, `VAULT_USER_SECURITY_EXECUTION_TTL_SECONDS`, and
  `VAULT_POST_REVIEW_DUE_HOURS`. Fixed/inherited lifetimes expose no additional configuration.
- Add explicit Composer platform requirements for ext-intl and ext-mbstring, without unrelated
  dependency upgrades, and make canonical-encoder readiness require their exact normalization and
  Unicode capability. ext-sodium remains the foundation requirement.
- Update Vault developer and Knowledge documentation after implementation.

## Out Of Scope

- Any secret item/version write, activation, verification, key provisioning/lifecycle, provider
  mutation, real credential, or migration of legacy credentials.
- Discover, metadata view, reveal, copy, launch, use-without-reveal, TOTP generation, attachment,
  import/export, recovery, destruction, or break-glass content operation.
- Any Vault page, menu, HTTP/API/MCP/portal route, Agent/Tool/Script/Automation/Connection consumer,
  queue consumer, or customer publication.
- WebAuthn/passkey or recovery-code step-up.
- Nested/external/general groups, Vaultwarden synchronization, typed source-record relationship
  adapters beyond current Client visibility, protected search, or Automation standing authority.
- A generic emergency break-glass that grants content. Quorum recovery restores only an approver
  roster under the explicit locked/cooling contract.

## Tests

- Default-deny decision matrix for missing/unknown operation, adapter, permission, Client, scope,
  epoch, digest, step-up, approval, actor, group, collection, grant, and lifecycle state.
- CurrentClientVisibilityAdapter: company rule, exact Client existence, direct client.view allow,
  inactive Client parity with current domain behavior, missing record/permission denial, and proof
  that active_client_id, route middleware, empty-Admin, and Superuser fallbacks are ignored.
- Permission deployment/read-back: original four grants unchanged; grant_manage and approval_decide
  added only to Admin/Superuser; Tech/Viewer zero; explicit removal survives both seeders; no content
  permission exists.
- Generic web/API/Livewire/action/raw paths cannot UPDATE/DELETE user_management.id/user rows,
  mutate/delete/reinsert protected Admin/Superuser or six Vault-permission IDs/names/binary
  guard_name=web identities, case-spoof them, move a protected pivot through an unprotected root PK,
  trigger a parent cascade, directly assign control permissions, or bypass
  VaultAuthorityMutationGuard. Existing delete surfaces reject/direct to deactivation.
- User role/direct-permission/role-permission/status/TOTP mutations advance affected epochs;
  remove-then-readd never revives evidence; role-wide changes cover all members under lock.
  Schema/read-back tests require vault_authority_epoch default/backfill 1, integer
  1..PHP_INT_MAX guards, exact +1 only for authority/readiness facts, no bump for password-only
  changes, exhaustion rollback, and concurrent transition parity on both drivers.
- Password, login-identifier, reset, invite, recovery, TOTP, security-reset, customer invitation,
  bootstrap, and system-user paths satisfy the same DB epoch contract on SQLite and isolated
  MariaDB. Fortify UpdateUserProfileInformation and UserManagement UpdateUserProfile both consume
  login_identifier_changed only for a persisted email change; no-op email and name/telephone/avatar/
  profile/email_verified_at-only changes retain the epoch. Raw bypass and email-value/hash evidence
  reject.
- Login-rehash tests exercise the current app-owned Fortify authenticateUsing callback's two-call
  path when confirmed 2FA is absent, its one pre-challenge call when confirmed 2FA is present, and
  decorated provider entry points for attempt, once, attemptWhen and
  logoutOtherDevices. An outdated cost/algorithm triggers exactly one password_rehash_on_login
  execution after locked successful validation, one hash write, one auth-epoch advance, all old
  proof invalidations and one trusted no-secret audit before session completion; the second callback,
  current-cost login and concurrent loser are no-ops. Hashing/evidence/finalization failure rolls
  back and denies authentication without a partial session, password/hash/cost trace or leaked value.
- Remember-token tests cover password-login remember, external Nextcloud-HMAC session start,
  ordinary logout rotation, logout-other-devices, and password-reset completion in its existing
  execution. Unknown context/raw provider write denies; byte-identical/config-disabled no-op does not
  advance an epoch. Nextcloud tests retain signature/time validation and additionally require exact
  ACTIVE human/non-system state plus warroom.view, reject portal/system/inactive users, and prove its
  HMAC is never Vault step-up/TOTP/approval. Fortify challenge tests bind/recheck user, auth epoch and
  TOTP generation before login; recovery-code events contain no raw code and both vendor/profile
  mutation paths consume the same safe boundary.
- UserManagement one-use execution tests cover every closed trusted flow, actor-required/actorless
  XOR, every exact closed safe subject kind, absence of generic user_field/value/credential payloads,
  pivot subjects with embedded old-to-old+1 epochs, closed
  role_permission_affected_user_epoch rows, role-wide member snapshot/digest,
  dormant operation without Vault proof, enforced composition with the exact Vault witness,
  concurrent member drift, raw DML, replay, partial subject failure, finalization and rollback on
  both drivers. Gate tests seed revision 1/NULL, claim before any protected write, force COMMIT after
  claimed, effects_started, each field/pivot/typed epoch-bearing subject and subjects_complete, prove every Row04
  new/unrelated consumer denies while pending, while the matching owner-resume succeeds and CAS-
  clears last. Wrong execution/subject/snapshot IDs deny. claimed abort with zero effects
  succeeds; abort after every later stage denies. Missing/duplicate/malformed gate, stale context,
  pooled reuse and partial clear deny without breaking ordinary Nexum login.
  New-user tests precommit target_user_id NULL, exercise the context-bound AFTER INSERT generated-ID
  bind once, and reject wrong safe fields, caller post-bind, two inserts, concurrent inserts and
  forced commit before remaining subjects/finalization on both drivers. Fortify self-registration
  tests allow only one actorless self_registration_pending human at PENDING_INVITE with no role,
  direct permission, confirmed TOTP or authority; ACTIVE/system/role/permission/second-user and raw
  escalation attempts fail and leave no trusted candidate.
- Step-up: every internal credential/TOTP/unconfirmed/replay/rate/lock/epoch/context denial reason is
  retained only in the sensitive typed audit/privileged-diagnostic boundary and maps to one byte-
  identical external step_up_failed response, redirect and JSON shape; no controller/log/exception/
  session output reveals reason or retry count. Unconfirmed TOTP denies; current and plus or minus
  one counter may succeed once; wider counters and replay deny; accepted counter is atomic. Timing
  tests exercise best-effort verifier equalization without claiming perfect HTTP constant time.
- Step-up TTL accepts 60 and 900 seconds, defaults 600, and rejects every other type/out-of-range
  value. Rate tests prove five actor-session failures or 20 actor failures in the exact trailing
  interval `(DB_UTC_NOW - 15 minutes, DB_UTC_NOW]` lock for 15 minutes under concurrent attempts;
  a failure exactly at the lower bound is excluded and one microsecond later is included. An
  already-active lock skips both factors/counter, writes one
  durable skipped/rate_limited denial, writes no rate failure/proof, and never extends locked_until;
  equality resumes normal evaluation. Durable attempt evidence/audit survives pruning while only
  unreferenced rate-failure telemetry older than 24 hours is removed.
- Lifecycle-time tests cover approval-plan 5/30/60-minute, immediate-plan 1/5/10-minute,
  operation-fence 1/30/60-second, evidence-build and UserSecurity 60/300/900-second configuration
  boundaries and reject non-integer/out-of-range values. They cover exact DB-clock addition,
  DATETIME(6) round-trip, equality-as-expired, overflow, the mandatory operation-fence/parent-expiry
  minimum including a parent with less than fence_ttl remaining, fixed 300-second
  enrollment/exception/recovery objects, quorum
  plan equality, origin/UserSecurity inheritance, pre-effects expiry cleanup, post-effects exact
  owner resume, expiry races, and both drivers.
- Proof tests cover same-session reuse only, session rotation, epoch drift, actor/TOTP state drift,
  expiry, installation-authority epoch drift after global/emergency authority change, locked terminal
  revoke before replacement, pending-to-active creation, exact creation-versus-transition commits/
  audits, every closed trigger-kind/XOR reference, actor+pending-replacement-proof revocation,
  exact authority/quorum consumption, auth-security and authority invalidation, one effective terminal
  transition, active-slot concurrency, abort cleanup, and no restore/delete.
  auth_security_invalidated tests cover password, login-identifier, reset, recovery and session-security flows,
  multiple active sessions, actorful/actorless provenance, TOTP-counter exclusion, forced-commit
  resume, and concurrent fresh-proof activation only after every old proof is terminal and the gate
  clears.
- TOTP-enrollment proof/exception tests bind the exact server-side pending-generation UUID and closed
  purpose; reject wrong generation, authority/recovery cross-use, replay, expired proof, rate/lockout
  bypass, normal-step-up use, and factor/secret persistence. They cover independent multi-candidate
  confirmation, sole-candidate enrollment exception, zero-governance-recovery-eligible cooling
  recovery, and
  all three post-review source XORs.
  Transition tests separately cover proof creation, same-purpose consumption, expiry/replacement
  revocation, creation-commit non-reuse, active composite uniqueness, concurrent consume-versus-
  revoke/double-terminal races, and full rollback on SQLite and isolated MariaDB.
- UserManagement TOTP-generation tests cover new enrollment, replacement, confirmation, disable,
  recovery reset, and existing-secret metadata backfill without decrypting/deriving from the secret.
  Both drivers reject noncanonical/caller-selected/reused generation IDs, partial secret/ID/time
  combinations, stale generation confirmation, and any path that fails to advance the required auth
  epoch.
- Candidate-roster count is independent of permission/TOTP/session/step-up. A distinct candidate
  must be approval-eligible for the exact scope and decision-ready to decide a graph/grant request;
  policy_manage is absent from that predicate. Policy/readiness-floor/quorum counts use governance-
  recovery eligibility without requiring an active proof, while a concrete decision additionally
  requires decision-ready proof. A second non-decision-ready candidate blocks sole-admin fallback;
  requester self-decision denies; exact-one requester uses only explicit sole exception; zero denies.
- Approval TTL accepts 5 and 60 minutes, defaults 30, and fails malformed. State precedence,
  cancellation, rejection, expiry, invalidation, single consumption, executor=requester, changed
  digest/epoch, and decision/application races are covered. Consumed/cancelled/rejected evidence
  remains terminal even after later live drift or time expiry.
- Uniform evidence-commit tests cover every source kind: missing/wrong/duplicate audit, wrong
  event/outcome/reason/source refs, orphan committed-building rows, denied downstream consumption,
  source-specific terminal cleanup and active-slot/lifecycle release, crash at every cleanup stage,
  monotonic commit-sequence allocation/exhaustion, retry chains, two-aborter races, losing cleanup
  behavior, rollback and replay. Approval lifecycle
  tests force approval/rejection,
  decision/sole-exception, reject/cancel, cancel/consume, expiry/decision and
  invalidation/consumption races; one reservation/finalized source wins and a losing source cannot
  persist. Consumption tests use each typed graph-execution/authority-witness/quorum-mutation FK,
  reject generic/future/cross-family/orphan pending mutation identity, verify cycle-free insert order
  and exact one-way UNIQUE typed binding for all three families, reject wrong/replayed/partial binds,
  and prove lifecycle remains approved until exact operation+operation audit+consumption audit
  finalize atomically.
- Approval cleanup tests prove expiration is system_cleanup by the canonical system actor only at
  DB expiry, every invalidation is system_cleanup with one exact closed drift trigger/anchor, and
  requester cancellation remains the distinct human source. Evidence-abort tests cover every
  trigger/executor/reason XOR: human operator/initiating failures require the exact fresh active
  same-session proof before effects, while expiry/orphan/repair/lost-race cleanup requires only the
  canonical system actor and exact objective predicate. Disabled/actorless drift remains correctly
  attributed, wrong/early/cross-executor rows reject, and audit/source reciprocity is exact.
- Database-context registry tests cover every family and closed stage, exact carried fields,
  emergency root event, wrong/cross-family context, exception before a trigger, affected-row zero or
  more than one, rollback/retry, and mandatory clearing before set, after consumption, in finally,
  and at connection checkout/return on both drivers.
- Applied-mutation audit tests precreate each graph/authority/emergency/quorum mutation pending,
  insert its exact typed audit under VaultAuditEventInsertContext, verify the INSERT trigger creates
  exactly one pending trust projection, and prove only the one finalizer may set
  applied plus the non-null UNIQUE primary_audit_event_id and then finalize audit trust LAST.
  Missing/wrong context, direct/duplicate trust INSERT, committed base-orphan attempts,
  missing/wrong/duplicate audits, second success audit, reciprocal-FK mismatch, partial transaction
  and replay reject on both drivers. Forced commits before application prove the
  base event is absent from trusted audit reads; abort marks it internal-only, and normal audit,
  health, export, retention/API repositories never expose pending/aborted rows. The bounded existing-
  foundation-event trust backfill and trusted-view predicates are tested on both drivers.
- Action-plan tests prove every closed graph/authority/quorum operation has exactly one matching
  immutable subtype and complete typed subjects; missing, extra, wrong-family, unknown-operation,
  digest-only, and post-apply-only plans reject before approval or mutation.
- Fresh-proof provenance tests require requester proof at every plan/request, approver proof at every
  decision, requester proof at cancellation/exception, executor proof at consumption, and actor
  proof at every no-approval graph/authority/quorum mutation and matching audit event.
- Group/collection creation and rename do not request independent approval. Grant creation is always
  classified as expansion. Member/item-link addition is approved only when locked post-state
  reachability expands. Every revocation/disable uses step-up+reason+same-transaction audit.
- Two concurrent membership/link/grant plans cannot exploit write skew. Tests force stale scope,
  group, collection, pool, and set digests on SQLite and isolated MariaDB.
- One-use graph-execution tests cover preallocated UUIDv7 create IDs; every graph INSERT/rename/
  disable/add/revoke trigger; exact claim+target+finalize subjects, actor, proof, authorization,
  scope and OLD/NEW match; first-use claimed anchor, raw clear, digest-only update, double epoch,
  wrong stage, replay, wrong or reused connection context, claim races, missing target/audit,
  partial subjects, finalization, pending-scope fence, epoch exhaustion, and full transaction
  rollback on SQLite and isolated MariaDB. Query-builder paths cannot admit a non-v7 or
  noncanonical ID.
- Grant identity tests cover all four user|group by item|collection variants, byte-exact
  scope_key/subject_key/target_key derivation, operation_code identity, malformed/case/NUL/collation
  keys, same-operation duplicate-active concurrency, different-operation separation, terminal revoke,
  and new-ID regrant on both drivers.
- Planned candidate-roster shrink from more than one requires a different pre-state decision-ready
  governance-recovery-eligible candidate; the future sole
  admin cannot approve it; transition to zero denies. Emergency shrink atomically sets quorum lock,
  invalidates evidence, and blocks expansion/sole exception while allowing further reduction.
  Both-driver concurrency tests cover initial open-to-locked and continued locked-to-locked
  emergency transitions, exact preservation of lock/cooling/root-incident fields, monotonic
  epoch/digest updates, rejection of cooling reset/change, and retention of the final candidate.
  Initial lock tests also prove preallocated emergency audit insert order, exact root-event FK,
  wrong/missing event denial, partial rollback, replay and concurrent event substitution.
- Quorum recovery denies before 24 hours, on digest/epoch drift, or when a pre-change approver is
  available. It restores only roster authority, never a grant; notification attempt is not treated
  as delivery; post-review is mandatory. Recovery-authorization tests cover one active row per lock,
  active-to-consumed/revoked XOR fields, separate creation and terminal transition commits/audits,
  exact atomic mutation consumption, every closed terminal trigger-kind/XOR reference, current
  actor+step-up human revocation, exact DB-time system expiry cleanup including zero-ready cleanup
  before a fresh retry, early-cleanup denial/race, building abort, concurrent
  consume-versus-revoke and double-terminal races, expiry lock+audited revocation before retry,
  rollback, replay and no restore/delete on both drivers.
- Quorum one-use-fence tests cover peer unlock and cooling recovery on SQLite and isolated MariaDB:
  pending creation, install/apply/finalize connection stages, exact locked-before/open-after subject,
  session-variable/UDF clearing, raw state DML, wrong context/plan/proof/subject/before value,
  replay/expiry, concurrent attempts, optional authority-witness coupling, evidence/audit ordering,
  partial failure rollback, and fail-closed committed-fence behavior.
- Post-review tests cover sole-exception, TOTP-enrollment-exception, and quorum-recovery source XOR,
  exact staged approval-consumption XOR recovery-authorization origin, obligation evidence-commit
  finalization, the per-finding open-to-remediated projection, crash/building-abort-to-retry, competing
  remediations, denial of confirmation while any finding is unresolved, remediation/confirmation
  races, overdue-observation/confirmation races, open-versus-reviewed_at constraints, exactly one
  terminal confirmation, denial of every evidence insert after confirmation including a forced
  race, and expansion blocking from the complete unconfirmed/unresolved set rather than a cached
  single obligation. Installation-gate tests bind every plan epoch/digest, force cross-scope
  obligation-insert-versus-expansion races, reject wrong/replayed pending gate context, verify every
  expansion and evidence finalizer updates the full-set digest/counts once, and prove a DB-clock due
  crossing is lazily audited/committed before denial even when cached blocking_count was stale.
  Composite-origin tests force COMMIT during assembling after each possible inert child, after the
  complete assembling-to-claimed CAS, effects_started, each target/evidence
  subject, obligation finalization, consumption finalization, preinserted primary audit and final
  operation application on both drivers. They prove wrong reciprocal bindings and cross-mode audit
  predicates reject; expiry or actor-epoch drift before effects_started denies, while the immutable
  consumed authorization snapshot permits only the exact resume after effects_started. Forced-
  commit tests advance TTL and auth epoch after each later stage and prove obligation, consumption,
  and final-operation audits accept only the same coordinator/snapshot/witness/actor/plan/operation;
  a different or new decision stays denied. They advance graph-execution, authority-witness, quorum-
  mutation and owned evidence-commit TTLs after effects_started and prove only exact snapshot-bound
  owner resume bypasses expiry. assembling/claimed whole-origin abort races claim/resume, requires the
  exact stage-specific child set and human/system XOR, releases every owned pointer, rejects old-
  origin retry and allows a fresh plan/request/origin; generic child cleanup, effects_started abort,
  double terminal and partial release reject.
- Canonical encoder tests cover all 23 frozen representative documents plus exact-byte fixtures for
  every remaining inner/subject family, including
  reachability, group/collection epoch sets, governance-ready roster, post-review blocker set,
  quorum authority facts, UserSecurity pre-state snapshot, post-review authorization snapshot,
  fresh-install empty-state/stage-snapshot documents, and the emergency authority plan with exact
  emergency_security_deactivation reason; domain tag, field order/type, four-byte
  length, NULL versus empty, UINT64 endianness, UUID bytes, UTC microseconds, Unicode NFC/name bounds,
  set sorting/duplicate rejection, session rotation, and raw 32-byte SHA-256. Shape-valid false
  digests and cross-family substitutions deny in both drivers. Non-zero microseconds round-trip
  through MariaDB DATETIME(6) and SQLite canonical 26-byte TEXT with identical digest/stale-boundary
  recomputation; clock padding never invents precision and over-precision rejects.
- One-use authority-witness tests cover each protected user/role/permission/pivot INSERT, UPDATE, and
  DELETE; exact before/after matching; session-variable/function context clearing; replay, expiry,
  raw DML, wrong actor/proof/plan, partial subject failure, complete finalization, transaction
  rollback restoration, and fail-closed pending-witness fence. Enforced tests create UserSecurity
  execution first and require the one-way UNIQUE execution-to-witness-to-authority-mutation binding,
  exact shared subjects/plan, action-specific audit references, and both rows absent for state-only
  operations; dormant execution rejects every Vault reference. Unrelated generic role changes remain
  unaffected.
- Canonical UUID/text storage class, scope/Client parity, XORs, active_slot, exact digests,
  monotonic epochs, per-operation required/forbidden columns, terminal transitions, append-only
  evidence/audit, UTC DATETIME(6), and rollback refusal have SQLite/MariaDB parity. Persisted
  epoch/counter/sequence/ID tests cover max-1-to-PHP_INT_MAX, next-step
  vault_epoch_exhausted denial, SQLite REAL coercion rejection and Maria unsigned upper CHECK.
- Production grant INSERT and every future/content operation reject while the operation registry is
  empty. The isolated enforced fixture alone registers `test.vault.synthetic_read` to exercise the
  generic graph approval-consumption success path; positive real content-operation coverage is
  deferred to the first content-operation slice. Production configuration, migrations, routes,
  services and enum parsing reject the synthetic operation.
- Password/TOTP/session/counter/digest canaries are absent from validation, exceptions, traces,
  logs, audit, events, jobs, queues, cache, sessions, model serialization, dumps, and command output.
- Empty routes, no UI/API/portal/runtime consumer, strict disabled guards, no key-file I/O, no secret
  material, and no real credential.
- Dormant/provisioning tests reject production INSERT and every control_phase UPDATE on both drivers,
  and prove services plus raw guards deny every Vault proof/attempt/rate/proof-transition/evidence
  source or commit and every graph/authority/approval/quorum/grant/content-runtime row before factor
  verification. Readiness requires zero such rows. The exact UserSecurity/bootstrap/deployment/
  cutover allowlist continues through its epoch/evidence boundary without creating a Vault proof;
  first pre-enforcement TOTP confirmation uses only the UserManagement verifier/counter/generation
  execution, and unknown/wrong-phase/source combinations deny. The isolated
  schema assembler proves operational state updates with phase fixed enforced and the identical
  immutable phase plus generic operational-guard implementation, with only its isolated synthetic
  registry differing. No Slice 04 service can change phase.
- Migration tests prove every ordered DDL step is restartable and schema-only: missing objects are
  created, exact catalog/normalized-trigger bodies are accepted, divergent or partially rebuilt
  guards fail, identifiers stay within 64 bytes, and no DDL-only or pretend path creates permission,
  authority, singleton, journal or evidence rows. MariaDB tests assert the `mariadb` and `mysql`
  branches, no `DB::transaction` around DDL, no `LOCK TABLES`, crash after every DDL object, and
  retry without destructive guard recreation. SQLite audit-rebuild tests use an owned IMMEDIATE DDL
  transaction and read back every foundation/Row04 trigger after rebuild.
- Audit-trust cutover tests install the temporary base-audit freeze first, then its tables/AFTER
  trigger and temporary trust INSERT guard before trust DML. Under full locks they create/resume one
  manifest_building header, insert every exact base-ID/event item, recompute the dedicated count/
  digest, finalize an explicit empty or nonempty manifest, backfill only finalized-manifest
  foundation events under the one-use context, overlap/catalog-verify the permanent trigger-only
  guard, advance each named stage by DML CAS, verify exact 1:1 state, and remove the base freeze last.
  Crash/re-entry at every marker, empty-manifest spoof, an event outside the manifest, wrong/replayed
  context, duplicate/unknown/malformed child, post-install direct child INSERT and a concurrent
  ordinary audit writer all stay frozen/pending or deny; trusted readers never see them.
- Classification-race tests run with stopped-worker maintenance assumptions and two real connections.
  Before any DDL, MariaDB information_schema tests require InnoDB and exact PK/index leading
  columns, types, unsignedness and collation for every existing locked/reference table; MyISAM and
  wrong/reordered-index fixtures leave zero Row04 objects or facts, and every new Row04 table is
  rechecked InnoDB before DML. SQLite verifies exact schema/index/PK and foreign_keys=ON.
  MariaDB then asserts REPEATABLE READ and fully consumes each exact FORCE INDEX(PRIMARY) range/gap lock;
  a concurrent user/role/permission/pivot/audit/singleton INSERT blocks until classification commits.
  File-backed SQLite uses two independent PDOs and exact top-level BEGIN IMMEDIATE, not Laravel's
  DEFERRED transaction. Crash rolls back all DML facts/baselines, while prior partial DDL remains
  non-authoritative and safely restartable.
- Deployment tests cover both locked classifications. On an exact empty installation the migration
  alone creates the one-use bootstrap run at permissions_installed with canonical empty-state and
  stage-snapshot digests. PermissionSeeder verifies the owned rows; typed
  fresh_install_role_bootstrap RoleSeeder, interactive bootstrap_admin, dormant UserManagement TOTP
  confirmation, exact system_actor_bootstrap/EnsureSystemActor and
  `nexum:vault-provision-control-plane` advance only the same run through every
  closed stage to completed without activating Vault. Tests force a crash/commit between every stage
  and prove same-run resume; reordered, skipped, duplicate, fabricated-context, partial-unrecorded,
  second-run and changed-snapshot attempts deny. A completed rerun is an exact read-only no-op and
  both runtime flags/control phase stay off/dormant. The actor-ready stage verifies the same numeric
  canonical DISABLED roleless/non-login vault-control-plane actor across crash/resume. Existing
  non-empty state receives no journal and instead finalizes its two narrow one-use provisioning-
  evidence steps plus mutual authority-state pointer; it resumes identical building IDs, preserves
  removed grants, and rejects missing/duplicate/conflicting actor/evidence, direct grants, partial
  identities/pivots and a false fresh-install claim on both drivers.
- Scope-anchor tests reject DELETE, restore, identity change and delete/reinsert ABA before or after
  references exist, including a stale live plan and an inactive Client, on both drivers.
- Run focused Vault/UserManagement/Client/CustomerPortal tests, both-driver contract and concurrency
  tests, migration pretend/actual/read-back, scoped Pint/diff checks, and full Nexum suite.

## Documentation

This docs-only planning step updates the RFC/ADRs, completion matrix, TODO, and Pending
HR-2026-09-04-003. After implementation, update this Slice with exact evidence, the Vault README,
Vault Knowledge documentation, the permission catalog, and the same status records. Automated tests
never mark human review complete.

## Migration And Deployment Gate

This approved Slice authorizes implementation on Dev. It does not authorize migration, runtime
activation, merge, push, Main promotion, deployment, or production change.

Before the first Row04 DDL statement, a read-only catalog preflight must pass. On MariaDB it verifies
through `information_schema` that `user_management`, `roles`, `permissions`,
`model_has_roles`, `model_has_permissions`, `role_has_permissions`, `vault_audit_events`,
`clients`, `vault_items`, and `vault_secret_versions` are InnoDB and that every PRIMARY/covering
index has the exact expected leading columns, datatype, unsigned property, length and binary/text
collation required by the predicates below. In particular, `vault_audit_events` has numeric unsigned
PRIMARY `id` plus separately unique event UUID, while `user_management`, roles and permissions have
numeric unsigned PRIMARY `id`; each Spatie compound PRIMARY order must match its full-range query.
The cutover validator locks the exact Client/item/version reference ranges it reads as well. Only
after that proof may DDL start. The same preflight later proves every new Row04 table is InnoDB before
classification DML.

SQLite preflight verifies exact table, PK and index shapes plus `PRAGMA foreign_keys=ON` before its
owned transaction. Missing, nontransactional, reordered, unsigned/type/collation-divergent, or
uncovered index state stops before DDL, DML, journal, or fact creation. Isolated-driver tests change
one MariaDB table to MyISAM and independently change one index shape, and prove both failures leave
zero Row04 objects or facts.

### Restartable DDL And Audit-Trust Cutover

Implementation progress (2026-09-10, retained read-back): the separate internal
FoundationAuditManifestReadback consumes full root/header/item/trust primary-key ranges under
the owned cutover transaction and compares the retained manifest_captured header with independently
read base/items. The exact header parser closes identities, stage/revision, every expected guard
name/body version, canonical UTC times, count and opaque digest. Missing/foreign/building or later
stages deny; this checkpoint does not confer trust or verify completed cutover readiness.
Guarded provenance writes and the full trust/backfill/permanent-guard sequence remain outstanding.

Implementation progress (2026-09-10, manifest): the internal foundation-manifest reader now
consumes the full locked base-audit and user/client/item/version reference ranges and validates
the four existing event families before computing a dedicated typed digest. Its separate
FoundationAuditCutoverTransaction explicitly sets next-transaction REPEATABLE READ on MariaDB
and owns BEGIN IMMEDIATE on SQLite. An ordinary Vault transaction is not sufficient ownership.
Both MariaDB driver names are tested with a READ COMMITTED session and a competing insert into
the locked supremum gap. No header, trust child, authority fact, permission, or runtime is written.
The later DML coordinator must additionally lock its cutover header/item/trust ranges and perform
guarded manifest persistence/backfill; this reader does not claim to implement that cutover.

Manifest encoding is frozen as canonical v1 documents: the outer
nexum.vault.foundation-audit-manifest.v1 contains installation UUID then cutover UUID; each
nexum.vault.foundation-audit-manifest.item.v1 contains contiguous uint64 sequence then BYTES of
one nexum.vault.foundation-audit-manifest.entry.v1; a final
nexum.vault.foundation-audit-manifest.end.v1 contains the exact uint64 count, including zero.
Entry fields follow the explicit FoundationAuditManifestEntry::COLUMNS order and typed null,
uint64, UUID, UTF8, BYTES and UTC_INSTANT encodings, never JSON or database column order.
SHA-256 consumes those documents in ascending numeric base-audit ID order. Duplicate identities,
missing/extra fields, reordered/truncated read-back, unknown families or non-null Row04 references
on a foundation event reject. Independent Python-derived empty and single-entry vectors are
retained in the unit test. Legacy second-precision timestamps are represented as the same UTC
instant with six fractional digits; original audit rows are never updated.

Compatibility correction: foundation event, correlation, item and version references retain
their existing canonical UUID versions 1-8; new cutover/owner identities remain UUIDv7. Requiring
UUIDv7 for copied historical references contradicted the owning foundation contract. The regression
failed before the schema correction. No already-deployed schema was altered by these tests.

Implementation progress (2026-09-10): `VaultAuditFreezeGuardInstaller` implements only the first
audit INSERT freeze. Its tests use actual foundation migrations and a second PDO on SQLite and
both MariaDB driver names. It requires disabled runtime gates, rejects MariaDB DDL transactions,
and requires the exact PDO of an owned immediate transaction on SQLite. Existing divergent
triggers are never replaced; rollback preserves pre-existing audit evidence. It offers no thaw
method, migration or operator command. Maintenance/worker coordination and the remaining cutover
must be implemented and verified before this internal component is called on an operational DB.

After named approval, schema installation is a sequence of small, ordered, restartable DDL-only
migrations, the bounded audit-trust cutover DML described below, and one separate final DML-only
classification migration. A DDL migration may create
or alter schema objects only; it may never create a permission row, authority fact, singleton
baseline, classification, bootstrap run or provisioning evidence. Laravel reports schema
transactions unsupported for the supported MariaDB and SQLite connections, and records a migration
only after `up()` returns. MariaDB DDL therefore is never wrapped in `DB::transaction` and expected
partial DDL is never mistaken for rollback. Each step reads the exact catalog and normalized trigger
body, creates only a missing object, accepts an exact object, and fails closed on a divergent object;
it never drops/recreates a guard to make progress. Every identifier is at most 64 bytes. Static
tests reject `LOCK TABLES`, DML in DDL migrations, authority-state creation in pretend/partial-DDL
paths, and driver dispatch that recognizes `mysql` but not `mariadb`. Catalog validation also
rejects reciprocal FKs whose inserts would require a future row; every such relationship below uses
one existing-row FK plus an exact typed trigger/fence and finalizer instead.

The first restartable DDL step creates and catalog-verifies `vault_installations` before any table
that references it. The audit cutover's locked DML step then validates the configured installation
UUID and creates or byte-verifies its sole non-secret root row before the same transaction creates or
resumes `vault_audit_trust_cutovers`. The later final classification DML creates the authority,
UserSecurity gate, evidence-sequence, and post-review singleton baselines against that existing root.
No DDL step inserts the root or any singleton; no cutover/classification path generates or falls back
to an installation UUID.

Audit-trust installation is a closed cutover rather than an ordinary open migration. Under the
deployment maintenance gate with web workers, queues and scheduler stopped, its first restartable
MariaDB DDL step installs an exact temporary BEFORE INSERT freeze trigger on
`vault_audit_events`; every ordinary audit write then fails closed. Later restartable DDL steps
create and verify the cutover header/item tables, trust table and audit AFTER INSERT trigger, then
install and catalog-verify the temporary trust-table BEFORE INSERT guard before any trust DML.

One explicit MariaDB REPEATABLE READ DML transaction then locks and fully consumes the ordered
primary-key ranges for base audit, trust, cutover header/items, and every foundation reference table
used by event validation. Through the one-use `FoundationAuditTrustBackfillContext` it creates or
resumes exactly one header at manifest_building, snapshots every currently locked base event into
one immutable item carrying base numeric ID, event UUID and its exact validated foundation
type/outcome/reason/reference shape, and rejects anything outside the four foundation families.
After inserting all rows it re-reads the base and item sets, recomputes the dedicated canonical
manifest count/digest, and CASes manifest_building-to-manifest_captured. An empty base requires the
explicit count-zero/digest header and no item; an absent or empty guessed item set never passes.

Only the finalized manifest may drive trust backfill. The same transaction uses one consumed
trust_backfill context per item to insert only its missing `foundation_existing/finalized` child
through the temporary trust guard, verifies exact 1:1 base/manifest/trust sets and digest, CASes
manifest_captured-to-backfilled, and commits. An event absent from the manifest, unknown/malformed
event, duplicate/conflicting child, or count/set/digest mismatch stops cutover and is never
auto-finalized.

The next restartable DDL-only step installs the permanent trust INSERT guard alongside the temporary
guard and catalog-verifies it before dropping the temporary guard, so no direct-INSERT window exists.
The permanent guard permits only the audit AFTER INSERT trigger's consumed
`VaultAuditEventInsertContext` path; the backfill branch is gone. A named locked DML verification
then CASes backfilled-to-guard_installed. The temporary base-audit freeze is removed LAST in its own
restartable DDL step after exact 1:1 trusted-view read-back, and a final locked DML verification
CASes guard_installed-to-completed. Each CAS uses the cutover context with exact prior
stage/revision/catalog and cannot run from DDL. A crash at any cutover marker resumes that same
header/stage and leaves ordinary audit writes frozen or pending and readiness false, never an
uncovered audit- or trust-write window.

SQLite performs its owned audit-table rebuild, every foundation and Row04 trigger reinstall,
temporary trust guard, locked manifest materialization, backfill and permanent-guard cutover inside
one dedicated top-level `BEGIN IMMEDIATE TRANSACTION` and verifies
the full normalized trigger catalog before COMMIT. It does not rely on Laravel migration
transactions or `ALTER TABLE` preserving triggers. MariaDB audit-validator replacement remains
behind the temporary freeze. Both drivers use `dateTime(name, 6)` or exact equivalent temporal DDL,
bind canonical UTC `Y-m-d H:i:s.u` values, use MariaDB `UTC_TIMESTAMP(6)`, and use SQLite DB-clock
milliseconds padded with three zero digits; Laravel's default date binding and SQLite
`CURRENT_TIMESTAMP` are forbidden because they lose required precision.

### Locked DML Classification

Only after every table, column, index, FK, CHECK and normalized trigger has exact read-back does the
final DML-only migration classify and create baselines. Deployment maintenance and stopped workers/
scheduler remain mandatory. MariaDB explicitly starts and asserts REPEATABLE READ before any
predicate read. For each numeric-ID table (`user_management`, `roles`, `permissions` and
`vault_audit_events`) it executes and fully consumes
`SELECT ... FORCE INDEX(PRIMARY) WHERE id >= 0 ORDER BY id FOR UPDATE`. For Spatie's compound
primary keys it executes the corresponding full lower-bounded primary-key scan and orders every key
column: `model_has_roles WHERE role_id >= 0 ORDER BY role_id,model_id,model_type`,
`model_has_permissions WHERE permission_id >= 0 ORDER BY permission_id,model_id,model_type`, and
`role_has_permissions WHERE permission_id >= 0 ORDER BY permission_id,role_id`, each with
`FORCE INDEX(PRIMARY) ... FOR UPDATE`. `clients` uses its verified numeric primary range;
`vault_items` and `vault_secret_versions` use their verified byte-exact UUID primary ranges.
Audit trust uses
`WHERE event_id >= '' ORDER BY event_id`; cutover items use their verified
`WHERE cutover_id >= '' ORDER BY cutover_id,base_audit_id` primary range plus the separately
validated event-UUID UNIQUE index; every new installation singleton/journal table uses
`WHERE installation_id >= '' ORDER BY installation_id`, again with
`FORCE INDEX(PRIMARY) ... FOR UPDATE` over its exact primary key, including the empty
supremum gap. No aggregate, MAX, LIMIT, join or auto-increment observation substitutes. A concurrent
relevant INSERT on another connection must block until commit. All predicates are recomputed only
after every range lock is held.

SQLite uses one dedicated top-level `VaultImmediateTransaction` and exact
`BEGIN IMMEDIATE TRANSACTION` for the classification DML; nesting is denied. DML either commits the
complete classification, permission rows, exact singleton baselines and permitted fresh bootstrap
provenance, or rolls all of them back. A DDL interruption creates no facts, and retry first
catalog-verifies/repairs only missing exact DDL before any new DML classification attempt.

Preflight must prove both runtime gates false, the
foundation schema complete, PHP's required intl/mbstring/sodium capability, exact user_management
ownership and `PENDING_INVITE|ACTIVE|DISABLED` status contract, no conflicting table/trigger/
permission names, no pre-existing direct user assignment of any of the six migration-managed
vault.* permissions, and the current UserManagement mutation-path inventory. Any direct assignment
stops before DDL; it is not silently converted or deleted.

The final locked DML migration classifies the exact state once as `empty_authority_install` or
`existing_authority_install` using the exhaustive predicate above. For exact empty state, only that
migration may create the one-use `vault_fresh_install_bootstrap_runs` journal, the two new
permission rows, and untouched dormant baselines without grants. The initial finalized transition
sets permissions_installed at revision 1 and binds the canonical pre-state/stage snapshot. The
required later sequence is the ordinary PermissionSeeder verification, RoleSeeder inside
`fresh_install_role_bootstrap`, interactive `nexum:bootstrap-admin`, UserManagement TOTP
enrollment/confirmation, `EnsureSystemActor` through `system_actor_bootstrap`, then explicit
`nexum:vault-provision-control-plane` provision and locked
completion read-back. Each writer consumes the same run's exact next-stage pointer; a committed
crash resumes only that run/stage, and completed reruns are read-only no-ops. No empty-state
reclassification, second run, skip, reset, reverse, or heuristic adoption is allowed.

An existing-authority migration creates no fresh-install journal. It instead requires the
prospective governance-recovery-eligible preflight above, preserves explicitly removed existing
grants, and defers the actual eligibility proof until the typed existing-state provision command has
completed. Before that command can report provisioning-ready it must finalize the exact
`vault_existing_install_provisioning_evidence` actor-ready and provisioning-ready rows and their
mutual dormant authority-state pointer. Partial/mixed state, a missing/mismatched protected identity,
duplicate/conflicting system actor or evidence, or an ambiguous missing pivot denies; no command
guesses intent. Every command write uses its exact UserSecurity flow and, for fresh install only, the
journal context and pointer.

All paths remain dormant. Neither migration nor either CLI command can write provisioning/enforced,
activate a runtime gate, or install a content operation. Enforced-phase activation remains a later
reviewed slice. Run the empty and existing upgrade matrices on SQLite and isolated MariaDB before
migrate --pretend and actual Dev migration.

Read back the exact empty/existing classification and migration provenance, tables, columns, types,
defaults, FKs, indexes, triggers, zero content operations/grants, empty routes, disabled runtime, no
key-file access, and exactly one well-formed untouched authority-state, evidence-sequence, post-
review-gate, and UserSecurity-gate singleton at their documented baseline values immediately after
migration. On exact empty state also read back one bootstrap run at permissions_installed/revision 1,
its one finalized migration transition, canonical empty/stage digests and exact typed facts; on
existing state require no journal row. After any seed/bootstrap/provision sequence, separately read
back every journal stage/revision/source/snapshot pointer, permission/role grants,
the actual live governance-recovery-eligible roster, dormant phase with no pending fence, and the
exact finalized UserSecurity execution provenance. Fresh installs retain a NULL existing-evidence
pointer; existing installs require the exact finalized provisioning-evidence back pointer after that
command. The live roster must not be persisted into or mislabeled as the migration baseline. Read
back the completed audit-trust cutover marker/manifest, permanent guards, audit-trust table,
finalized-only trusted view/repository contract, and every validated foundation-event trust
backfill. Extend the
foundation schema-readiness contract only after all Slice 04 guards are present.

Rollback enumerates every Row04 table and added UserManagement field before dropping a guard. It
refuses when any access group, membership, collection, item link, grant, action plan/subject,
evidence commit/abort, proof/transition/attempt/rate row, approval/lifecycle/exception/consumption,
post-review origin/obligation/finding lifecycle/evidence/overdue observation, graph execution/mutation,
  UserSecurity execution/subject, fresh-install bootstrap transition/fact beyond the permitted
  untouched migration baseline, existing-install provisioning evidence, authority witness/mutation,
  quorum plan/mutation/recovery/authorization transition, non-cutover audit reference/trust
  projection, noninitial auth_security_epoch or vault_authority_epoch,
or replay-counter evidence exists. Down may proceed only when every dependent row is absent and the
  four retained singleton rows are their exact untouched baselines: authority state is dormant/open at
epoch 1 with its canonical migration snapshot and no quorum/root/fence refs; evidence sequence is
next_sequence=1; post-review gate is epoch 1 with the documented canonical empty digest, zero counts
and NULL pointers; UserSecurity gate is revision 1 with NULL pointer. Missing, duplicate or any
  nonbaseline singleton refuses. An existing-authority install must have no fresh-install journal.
  An exact empty-install rollback may additionally retain only the untouched migration-created
  bootstrap baseline: one run at permissions_installed/revision 1, one finalized
  permission_migration transition, its exact canonical initial facts/snapshot, no pending pointer and
  no later stage/source/user/generation reference. Down removes that initial journal evidence only
  after proving all other baselines and dependencies; any missing, duplicate, mutated, later-stage,
  building/aborted, or externally referenced journal row refuses.

The completed audit-trust cutover is a second bounded migration baseline. Down may remove it only
when its one retained cutover header is completed, every immutable manifest item still byte-matches
the unchanged pre-cutover foundation audit row, every and only manifest event has one finalized
foundation_existing trust child, no post-cutover/pending/aborted audit or other trust owner exists,
and both permanent/freeze-trigger catalogs match the expected completed state. It removes those
baseline trust children and cutover rows only after that locked proof; any unknown event, changed
count/reference, unfinished marker, additional audit/trust row or missing/divergent guard refuses.

Untouched TOTP metadata created by up is the sole data exception. Down may clear a current generation
pointer and remove its retained row only when source=migration_backfill, both user epochs remain 1,
there is no transition/new generation/Vault plan/proof/evidence/FK, and trigger-enforced current
encrypted secret plus confirmed_at still exactly match the backfilled pending-or-confirmed state.
The secret and confirmed_at are never read into output, changed, decrypted, or hashed. Any
source=runtime row, epoch change, pending transition, factor-state drift or reference refuses down.
Both drivers test up-to-down with pre-existing confirmed and pending TOTP plus refusal after runtime
change. Never disable
foreign keys or delete evidence to make rollback pass. Any partial DDL failure stops for bounded,
read-only state review before a separately approved recovery.

Before Main/production, HR-2026-09-04-003 must be Reviewed by a named human. Runtime activation and
all real credentials remain blocked by later slices and their own deployment checks.

## Done Criteria

Implementation note, 2026-09-10: pending migration
`2026_09_10_010180_assemble_vault_authorization_core.php` assembles 31 remaining core tables.
The closed component manifests remain authoritative. MariaDB creates real tables with all
columns, indexes, checks and external/base FKs, then completes only intra-stage FK edges in
manifest order. Every retained table is checked before further DDL; every added FK is read back.
Only an exact FK prefix is restartable. Arbitrary missing constraints, extra columns/indexes,
weakened checks, unexpected triggers, and DDL inside an active MariaDB transaction are refused.
SQLite creates missing objects and performs complete component assertions in one transaction;
a later divergent table rolls back earlier new objects. No stub, FK disable, or data insertion
is part of this stage. The two runtime gates must remain strictly false.

References to deployed `vault_items.id` and `vault_audit_events.event_id` use the foundation's
existing utf8mb4_unicode_ci collation on MariaDB, while keeping their canonical UUID checks.
New Row04 identities stay ascii_bin. No existing foundation column or data is rewritten.
Graph execution and authority-witness manifests explicitly declare all FK covering indexes
rather than relying on MariaDB-generated names. A static contract checks every FK in the new
31-table stage. Exact CHECK normalization preserves string-literal case, escaped quotes and
backticks; a regression test failed before that correction. Graph CHECKs use MariaDB's native
SUBSTR/negation spelling and an explicit UTF-8 character cast, without relaxing their semantics.
Bounded MariaDB assembly tests use actual Vault/UserSecurity migrations and external-owner fixtures.

The foundation migration explicitly uses CHAR(36) on both mysql/mariadb driver names; MEDIUMBLOB
and existing guard installation/read-back now recognize both. This is equivalent to the deployed
mysql-driver catalog and does not rewrite any already-installed column. A previously divergent
native-UUID alias installation is not automatically repaired. The full historical application
migration chain under the mariadb alias is not certified: an unrelated older Supplier Order
migration rejects that alias. The actual Dev driver remains mysql.

The two audit-trust event-reference columns likewise retain the foundation event collation.
A MariaDB regression with the deployed column type failed before correction; the final audit-trust
SQLite/MariaDB run passed 7 tests / 196 assertions. This is compatibility evidence for its DDL,
not an integrated audit cutover or backfill test. The actual cutover remains unimplemented.

Audit-trust tables/view and their freeze/backfill/guard cutover are excluded. Full core catalog
success is not runtime readiness: coordinated guards, repositories, cutover, classification,
and final authorization verification remain outstanding. Automatic down refuses any retained
core schema, including empty or partial schema; teardown needs a separately reviewed recovery
plan. No ordinary Dev migration, activation, or human approval is implied by isolated tests.

- [ ] A named reviewer approves this Feature Slice before code work starts.
- [ ] UserManagement/Vault/Client ownership and CurrentClientVisibilityAdapter are exact and
  default-deny.
- [ ] Six control-plane permissions have the exact role map; no content permission or direct-user
  control grant exists.
- [ ] Every authority mutation path uses the locked cross-domain guard and monotonic epochs.
- [ ] Every graph/authority/quorum action has an immutable pre-apply base plan, exactly one typed
  subtype, enumerated subjects, and action-specific requester/executor proof provenance.
- [ ] Every Vault-owned graph DML statement consumes one exact typed graph-execution subject under a
  connection-local context; preallocated UUIDv7 creates, replay/raw/wrong-subject/concurrency, and
  rollback/fence behavior pass on both drivers.
- [ ] Existing UserManagement/Spatie protected writes require one-use DB witness subjects; replay,
  raw or partial mutation cannot become effective and rollback restores all state.
- [ ] Flat groups/collections, allow-only dormant grants, terminal revocation, no creator grant, and
  no future/content operation are DB-guarded on both drivers.
- [ ] Step-up uses password plus confirmed TOTP with plus/minus one counter, atomic replay defense,
  600-second default/60-900 bounds, exact dual rate limits, and session/epoch binding.
- [ ] Independent approval, exact state derivation, 30-minute default/5-60 bounds, same-transaction
  unique consumption, and stale-plan invalidation pass.
- [ ] Sole-admin exception is exact-one-requester only, at most five minutes, single-use, separately
  audited, and coupled to source-typed append-only post-review finding/remediation/confirmation.
- [ ] Pool shrink, emergency quorum lock, 24-hour no-alternative recovery, notification honesty, and
  full-set post-review expansion blocking pass concurrency tests.
- [ ] Typed audit mappings and the finalized-only audit-trust projection/view, schema readiness,
  rollback refusal, leakage, SQLite/MariaDB concurrency,
  canonical UUIDv7/query-builder enforcement, migration/read-back, focused tests, and full suite
  pass.
- [ ] Exact empty installs retain one immutable one-use bootstrap journal through every ordered
  seed/admin/TOTP/provision stage; crash resume, reorder/duplicate/fabrication denial, completed no-op,
  and existing-install no-journal behavior pass on both drivers.
- [ ] No route, UI, API, portal, writer, key provisioning, crypto/runtime consumer, or credential is
  introduced; both runtime switches remain false.
- [ ] Developer/Knowledge/TODO/matrix documentation is current.
- [ ] HR-2026-09-04-003 remains Pending until a named human completes it.
