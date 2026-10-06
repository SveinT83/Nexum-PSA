# Vault MariaDB Trigger-Memory Rework

Status: Structural implementation and native parity verification under approved Slice 04
Date: 2026-09-13
Owner: Codex
Human review: HR-2026-09-04-003 remains Pending / not practical-review ready
Scope: the internal runtime-disabled Vault proof/evidence guard assembly, not production or real credentials.

## Current base-proof verification (2026-09-14)

- The raw UserSecurity matrix 4195 failed after mysql 6 passed / 17 failed (196 assertions,
  213.42s), losing private PID 456340 at the unchanged 4 GiB address-space cap.
- Retry 9769 again failed raw terminal UPDATE with allocation error (1 failed / 53
  assertions, 56.08s); private replacement PID 652985 also crashed. Neither run is waived.
- Explicit per-case disconnect/drop of exact owned synthetic schemas prevents retaining
  six complete trigger graphs until teardown. Corrected direct terminal contract passes
  mysql 1 / 102 (71.04s), session 30158, covering human/system and all three proof purposes.
- Native writer/resume/parent run 38824 ends 1 failed / 2 passed (342 assertions, 209.88s):
  fresh writer and parent ordering pass, but retained resume still fails allocation.
- The existing cached full manifest cannot substitute for the older base fixture.
  A closed eight-trigger base representation now reuses the same compiler and exact
  catalog, preserving all original predicates and declaring its lower capability set.
  Requiring UserSecurity, attempts, aborts or action plans must deny on that base.
  No runtime/caller catalog bypass or resource/TTL relaxation is allowed.
- Missing-routine/mixed-representation probes exposed an unhelpful diagnostic from a
  different missing-table variant (63655, 1 failed / 14 assertions, 44.18s). The catalog
  now prefers a complete candidate's actual drift. Corrected retained-resume verification
  98978 PASS 1 / 162 (387.02s), without a server reset. Corrected sequence 91142 completed:
  mysql fresh/resume 2 / 426 (461.85s), mariadb terminal/fresh/resume/parent 4 / 606
  (626.50s). Repeated drift probes are once per typed matrix; every case still receives
  full live attestation. Whole native/product resource acceptance remains open.
- Broad after the representation extension passes 876 / 32720 (120.32s); compiler plus
  proof contracts pass 75 / 4097 (54.44s). Initial native mysql proof-creation/reviewer/
  obligation consumers PASS 26 / 4959 (350.17s). Full thirteen-trigger creation-cleanup
  compatibility is still running in session 4584 (15:26 UTC: isolated migrations through
  authorization core, synthetic proof fixtures beginning). No duplicate full run is needed.
  Subsequent authority snapshot/timing verification ends successfully: mysql 2 / 1397,
  mariadb shared consumers 26 / 4977, broad 876 / 32756. Those are source-reader checks,
  not substitutes for the still-running complete thirteen-trigger lifecycle regression.
- Current private server PID 654206, socket /tmp/tdpsa-vault-maria.CESC7v/server.sock,
  skip_networking=1, MALLOC_ARENA_MAX=2, 4 GiB address-space ceiling and 64 MiB buffer pool.
  Launch session 32200 remains attached. Operational MariaDB PID 692 is untouched.
  Retain earlier diagnostic schemas/logs. Resume current verification without a reset
  solely to hide accumulated memory. All runtime/HR/product gates remain open.

## Confirmed failure

The complete creation-cleanup flow passes SQLite (corrected fixture: 1 test / 251 assertions)
and the MySQL Laravel driver against the private MariaDB server (1 / 251, 43:21.718).
The MariaDB driver run ended after 26:03.106 with 12 assertions, a failure and a risky result:
the private server disappeared during the pending-proof terminal commit UPDATE. The risky
handler cleanup followed the lost connection; this is not a passing or deferred check.
The prior broad run passes 836 / 26,983, but does not resolve this driver/resource failure.

Only /tmp/tdpsa-vault-maria.CESC7v and its owned synthetic test schemas are involved,
including retained crash fixture tdpsa_vault_audit_64fbdaa90e00. No operational database,
runtime activation, credential, Main, production, commit or push was changed.

The failed transaction was recovered and rolled back. The original private fixture was read
back after restoration: nine creation commits, nine audit rows, zero proof transitions and
zero abort sources. These are synthetic retained pending creation facts.

## Bounded reproductions already completed

- Fresh private server, original exact guard catalog, native prepared statements and an
  8 GiB process address-space ceiling: the exact terminal UPDATE fails Out of memory after
  44.182 seconds. Resident peak was about 6.3 GB; address space reached the ceiling.
  The creation remains building after rollback.
- Fresh private server with diagnostic SESSION optimizer_search_depth=1: still fails
  after 33.586 seconds. The server trace includes parse_sql,
  Table_triggers_list::check_n_load, open_tables and Prepared_statement::prepare, followed by
  an allocation-error cleanup crash. This is not resolved by a smaller join-order search.
  No optimizer setting was changed in application code or operational configuration.
- Factoring the repeated abort owner/audit envelope reduced generated SQL but still fails
  Out of memory after 28.596 seconds. The temporary implementation was fully reverted.
  SQLite could not use the deeper factored SELECT shape without parser-stack overflow;
  the original shallow assembly parser again passes 1 / 7 after the revert.
- Original exact catalog with PDO emulated prepares also fails Out of memory after
  31.208 seconds. No PDO option was changed in application code.
- Six test-only, read-only SQL SECURITY INVOKER functions wrapping just the abort predicates
  reduced proof BEFORE/AFTER and trust trigger bodies to roughly 97/106/130 KB, but still
  failed after 25.786 seconds. This was an isolated diagnostic, not an acceptance test.
  An in-memory first-party writer clone replaced only its catalog assertion because the
  experimental manifest was not an application-supported catalog. No such clone or bypass
  was saved in the repository or bound to runtime.
  All six diagnostic functions were subsequently removed, and the original exact generated
  trigger catalog was restored and asserted.

The original trigger files in this synthetic schema are approximately:
728 KB evidence commits, 413 KB audit events, 396 KB audit trust and 118 KB abort sources.
The observed failure is in loading/parsing the cross-table trigger graph during prelocking.
The exact multiplying path still needs isolation; do not declare a complete root-cause fix.

## Structural implementation checkpoint

- Original zero-row UPDATE also failed in 21.173 seconds before any row mutation.
- Wrapping the four largest whole predicates still exhausted a 4 GiB process ceiling.
  Splitting top-level AND/OR conditions into statement blocks also failed; neither
  diagnostic was adopted. Original generated guards were restored.
- Caching large guards across eleven triggers, including the audit AFTER INSERT
  predicate, completes the zero-row probe. The initial 9.251-second experiment used
  ACTION_STATEMENT and is not a source-equivalence or security result.
- The repeated prototype used original SHOW CREATE source throughout, preserved exact
  row types/collations and completed in 4.582 seconds. Its diagnostic writer clone
  completed/read back in 0.761 seconds and was intentionally rolled back.
- The actual implementation now has a pure compiler, a closed eleven-trigger/fourteen-
  routine manifest and read-only full attestation. Row04 and UserSecurity consumers
  recognize only that exact representation. No application writer/catalog bypass is
  present. Only the owned private test assembly installs the functions.
- Application-backed probe 79624 PASS: compiled catalog 3.830s, full original consumer
  catalog 51.698s, zero-row UPDATE 1.863s, unmodified writer/read-back/rollback 86.126s.
  All functions were then removed and exact original trigger source restored.
- Repeated full-manifest verification was subsequently reduced to one proof-catalog
  invocation, without retaining results across operations. This optimization still
  needs native timing and full-flow verification.
- Pure compiler and existing catalog/parser tests PASS 15 / 66. After the attestation
  optimization plus the complete SQLite creation cleanup: PASS 16 / 317, 83.62s,
  exec 28594 finished. No SQLite predicate or eligibility rule changed.
- Native creation cleanup now includes missing/body/security/data-access/determinism/
  parameter/extra-routine/partial-trigger drift cases with exact restoration.
- ADR: docs/adr/2026-09-13-vault-cached-mariadb-proof-guard-predicates.md.
  CREATE ROUTINE/EXECUTE and binary-log deployment constraints are explicit; no server
  trust, durability, permission or runtime setting was relaxed.

These probes resolve the immediate reproduction but do not yet close full driver,
cold-connection resource, provenance, rollback or shared-consumer verification.

## Native live-fixture timing correction

Exec 7683 ended with one failed test / 11 assertions after 30:14.19, not OOM.
The pre-call live check passed, but the catalog/transaction setup crossed the five-minute
fixture expiry before the writer's actual DB-time check. The writer checks expiry itself;
extending a product TTL would be the wrong correction.

Only native live-denial scenarios now pin their owned connection's session timestamp
to the fixture instant, verify exact UTC microseconds and restore/check the real clock
in finally. Expired cleanup, creation, audit, rollback and performance scenarios remain
unfrozen. The test also no longer catches its own assertion failure as a replay rejection.
Private MariaDB confirmed that UTC_TIMESTAMP(6) respects/restores this session-local clock.
Corrected complete SQLite cleanup PASS 1 / 260, 76.81s, exec 5054 finished.
No product clock, TTL, eligibility rule or guard predicate changed.

The corrected full MariaDB-driver run now PASSES 1 / 283, 1:16:42.10, including all
native routine-catalog drift cases, three proof purposes, exact provenance, write-boundary
rollback, fresh cleanup, retained resumption, replay denial and staged-loser repair.
No skipped/deferred checks or OOM. The first stage of exec 83489 finished successfully;
its test schema was removed. MySQL-driver verification started automatically afterward.
Observed server peaks through this stage: VmPeak 3659032 kB, VmHWM 1667044 kB,
within the unchanged 4194304 kB address-space cap. Cold-runtime acceptance remains open.

## Current resource and process checkpoint

- Probes 15055, 62925, 32077, 55938, 79624, 49981 and 29500 have ended.
  The retained synthetic fixture now contains the application-supported compiled
  thirteen-trigger/nineteen-function assembly, NOT the original raw representation.
  Diagnostic vault_diag functions remain absent.
- Current private server PID: 456340 (allocator experiment below); socket
  /tmp/tdpsa-vault-maria.CESC7v/server.sock, network-disabled, 4 GiB address-space ceiling.
  Prior PIDs 396667, 412778 and 413092 were gracefully stopped after completed runs.
  PID 413092 was stopped only after all six cold probes and SQLite verification ended.
  Six fresh-connection full writer/read-back/rollback probes passed after cold start:
  mariadb 51.721s / 41.759s / 40.612s; mysql 41.742s / 41.905s / 41.900s.
  Each compared all eight synthetic tables after rollback and used the unfrozen DB clock.
  VmPeak 3073084 kB, VmHWM 1621280 kB. This remains resource-heavy, not a complete fix.
- Full MariaDB exec 7683 ended with the timing failure above; its owned schema
  tdpsa_vault_audit_4babb7f1429b was removed by teardown.
  Log: /tmp/vault-routine-creation-mariadb-20260913.log.
- Sequential exec 83489 ENDED exit 1. MariaDB PASS 1 / 283 (4602.10s);
  MySQL FAIL 1 / 36 (1596.36s), SQLSTATE HY000 / 5 Out of memory (Needed 16352 bytes)
  during the actual terminal evidence-commit UPDATE. The expected synthetic rollback
  exception was replaced by the database error. Broad tests did not start.
  Both test-owned schemas were removed by teardown. Server peak reached exactly
  4194304 kB address space and 2090776 kB resident high-water mark.
  Logs: /tmp/vault-routine-creation-clock-mariadb-20260913.log,
  /tmp/vault-routine-creation-clock-mysql-20260913.log,
  /tmp/vault-routine-broad-20260913.log.
- Retain this server/data/log for the same bounded diagnosis. Verify its actual PID/socket,
  exact private schema and skip_networking before any further diagnostic mutation.
- /tmp/tdpsa-vault-maria.CESC7v/server.log contains allocation errors and the server backtrace.
- Full-run logs: /tmp/vault-creation-cleanup-live-order-{mysql,mariadb}-20260913.log.
- The original fixture-order failure is separately retained in
  /tmp/vault-creation-cleanup-mysql-20260913.log and has already been corrected.
- No operational guard/function installation or application binding changed.

## Next bounded work

1. Verify the direct compiled native test assembly: selected routines and trigger bodies
   are installed once while the audit freeze holds, rather than first loading/attesting
   the entire raw abort graph and replacing it. The final complete catalog still runs.
   No runtime guard, predicate, catalog rule, operational installer or SQLite path changed.
   Test-only setup compilation is not yet proven to resolve the repeated-run memory failure.
   Corrected command (the first command had a nonexistent Feature test path and ran no
   tests): compiler plus full SQLite creation cleanup PASS 9 / 287, 77.18s, exec 39412.
   Pint, PHP syntax and git diff --check pass.
   Sequential exec 57013 ENDED exit 1: MySQL PASS 1 / 282 (3336.14s), then MariaDB
   FAIL 1 / 37 (1312.92s), server gone during the first actual provenance rollback case.
   Private PID 415280 crashed under the unchanged 4 GiB cap; broad did not start.
   The first driver schema was removed; the crashed second schema
   tdpsa_vault_audit_a398c57dee70 remains for bounded postmortem/owned cleanup.
   Direct test assembly avoids redundant DDL but did not solve repeated-run memory.
   Logs: /tmp/vault-direct-compiled-creation-mysql-20260913.log,
   /tmp/vault-direct-compiled-creation-mariadb-20260913.log,
   /tmp/vault-direct-compiled-broad-20260913.log.
   Existing every-ten-minute heartbeat ferdigstill-vault-til-human-review is ACTIVE
   for the same task and full approved product; no new user continuation is required.
2. Verify cold compiled-catalog memory/latency separately from the one-time original
   catalog installation cost. Do not claim concurrency/resource acceptance from a warm probe.
3. Preserve every authorization/audit predicate, typed argument, literal and transaction
   boundary. Do not relax TTLs, catalogs, memory limits or durability to pass tests.
4. Record final results here and in TODO/human-review tracking; finish the remaining
   approved Slice 04 work before dependent product work. No review invitation yet.
5. Never touch operational MariaDB PID 692. Keep real credentials, operational cutover,
   Main, production, commit and push out of this checkpoint.

## Expanded conditional compiler checkpoint

The closed manifest now also includes user BEFORE UPDATE and UserSecurity subject BEFORE
UPDATE: thirteen triggers / nineteen functions. Generated raw conditions remain authoritative.
The pure compiler recognizes IF/ELSEIF statement headers without rewriting nested SQL IF/CASE
expressions, preserves quoted row references and exact ENUM labels/order, and normalizes SQL
truth with IF((condition),1,0) before returning TINYINT. Assignment and statement-state/side-
effect functions are forbidden inside compiled conditions; statement effects stay inline.
Catalog type normalization now preserves enum literal case/order.

Pure regression PASS 13 / 62. Native decimal/enum/NULL truth parity passes independently
for both Laravel driver names using a separately owned empty schema per driver.
Application-supported retained-fixture probe 3980 passes: thirteen/nineteen manifest,
exact routine catalog 4.965s, full catalog 25.392s, zero-row terminal UPDATE 4.421s.
The retained fixture currently has this expanded compiled representation installed.
Compiler/parser/full SQLite creation cleanup PASS 15 / 329, 77.49s, exec 11634 finished.
Native parity helper creates only owned synthetic probe tables/functions and removes them.
Full repeated writer probe exec 35559 ENDED PASS after a clean restart. Three fresh
connections per driver use unmodified revoke/preparation/finalization and unfrozen DB time,
full read-back and forced rollback with all eight tables compared.
MariaDB 93.629 / 92.559 / 94.447s; MySQL 90.875 / 91.139 / 94.043s.
VmPeak 2745412 kB / VmHWM 1371016 kB. The thirteen-trigger assembly reduces native
memory but repeated PHP source compilation/normalization adds unacceptable latency.

Pure compilation is now memoized by every original source byte and column-contract field,
bounded to 32 entries / 16 MiB serialized payload. No database attestation or authority is
cached; live schema/catalog checks remain. Pure tests PASS 14 / 68. Timed real-flow probe
92698 ENDED PASS: mariadb 65.738s, mysql 62.766s, complete rollback/read-back.
Fresh identical SQL bytes now bypass only unnecessary tokenization, not read-back or comparison;
different bytes still take the original canonical comparison. Regression PASS 15 / 71.
Full real-flow probe 73874 ENDED PASS: mariadb 38.889s, mysql 38.326s. This is a latency
improvement, not user-facing runtime/readiness acceptance.

Private PID 429395 was gracefully stopped after both probes. Current PID 433171 starts
with the same 4 GiB cap and network disabled. Sequential exec 40988 ENDED exit 1
at the broader ALL Vault/UserManagement directory run: 1233 passed, 15 failed, 89 skipped,
47873 assertions, 2618.54s. Both native creation-cleanup stages did NOT start.
The 89 opt-in native contracts were not enabled by this SQLite run; this is not zero-skips
evidence or a replacement for driver coverage. This directory scope is larger than the older
836-test checkpoint and exposed additional existing test/consumer incompatibilities.
Logs: /tmp/vault-thirteen-routine-broad-20260913.log,
/tmp/vault-thirteen-routine-creation-mysql-20260913.log,
/tmp/vault-thirteen-routine-creation-mariadb-20260913.log.

The fifteen failures reduce to three causes: two outdated partial-manifest diagnostic
expectations (first execution guard now rejects before the gate); thirteen StoreUser cutover
cases all fail in setup because the old six-column authority fixture omits now-required
digests/classification/time; and the profile wrong-password error uses Fortify's named bag
while the existing form reads the default bag.
Corrections preserve strict rejection and use actual migrated authority rows with typed
synthetic binary digests. The profile controller maps validation feedback only, leaving
Fortify and the security boundary authoritative. The test now verifies rendered feedback,
unchanged password and no password old-input flash. Knowledge guidance is updated.
Targeted exec 10502 ENDED PASS: 40 / 898, 467.16s, covering TwoFactorAuthentication,
FortifyUserSecurityActions, VaultRow04CoreAssembly and StoreUserSecurityCutover.
Log: /tmp/vault-expanded-regression-fixes-20260913.log. Pint and git diff --check pass.

Sequential exec 70540 ENDED exit 1. MySQL PASS 1 / 298, 3376.67s, including native
decimal/enum/NULL parity and routine drift checks. MariaDB FAIL 1 / 53, 1361.30s:
the first provenance rollback received SQLSTATE HY000 / 5 Out of memory (Needed 24544
bytes) instead of the synthetic rollback. Corrected broad did not start in that sequence.
Private PID 433171 stayed alive, with VmPeak exactly 4194304 kB / VmHWM 2056576 kB.
It was gracefully stopped after failure; this is not repeated-driver acceptance.
Logs: /tmp/vault-thirteen-corrected-creation-mysql-20260913.log,
/tmp/vault-thirteen-corrected-creation-mariadb-20260913.log,
/tmp/vault-thirteen-corrected-broad-20260913.log.

## Allocator and cache diagnosis

Private profiling PID 455152 used the same data/socket, networking disabled and unchanged
4 GiB address-space ceiling, with performance_schema memory instruments enabled only for
diagnosis. Unmodified real-flow probe 33817 PASS 51.315s, with complete eight-table rollback.
Peak tracked sp_head allocation was 950145408 bytes and TABLE allocation about 157.6 MB.
After disconnect, 783770264 bytes of sp_head objects remained with 1643 open tables but only
50 table definitions. Diagnostic FLUSH TABLES, with no active application flow, reduced
tracked sp_head allocation to zero and TABLE allocation to 9184 bytes; process RSS still
remained about 1.295 GB with 2.552 GB virtual size. This distinguishes retained SQL caches
from allocator retention; it does not yet establish the full second-schema failure cause.

MariaDB documents the cache-clearing behavior of
[FLUSH TABLES](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/flush-commands/flush).
GNU documents the allocator's process-start
[arena limit](https://sourceware.org/glibc/manual/2.31/html_node/Malloc-Tunable-Parameters.html).
No FLUSH, SQL-cache tuning, larger resource limit, TTL relaxation or security bypass has
been added to application code or tests.

PID 455152 was gracefully stopped. Current private PID 456340 starts with process-only
MALLOC_ARENA_MAX=2 plus the same profiling, networking and 4 GiB limits. The first complete
unmodified rollback probe 55576 PASS 44.887s, VmPeak 1851596 kB / VmHWM 1294356 kB.
This is a bounded allocator experiment, not an accepted deployment requirement or product fix.

Full sequential native verification exec 35363 ENDED exit 0. MySQL PASS 1 / 298,
3520.61s; MariaDB PASS 1 / 299, 3610.78s, on the same private server without restart
or cache flush, with separately migrated schemas. Both full cleanup/provenance/rollback/
resume/replay and routine drift suites pass under the process-only allocator limit.
Final VmPeak 2990928 kB / VmHWM 2525500 kB: below the unchanged 4 GiB ceiling.
This proves the controlled test configuration, not default-allocator stability or an
operational deployment change. The source/runtime gates remain unchanged. Corrected
SQLite ALL Vault/UserManagement regression exec 41507 ENDED exit 0: 1248 passed,
89 opt-in native contracts skipped, 48064 assertions, 2587.01s. All fifteen previously
failing tests now pass; there are no deferred failures in this run. The deliberate
native skips are not driver coverage and do not close the still-running native sequence.
Tests use separate private databases/process configuration; no tested code is edited.
Logs: /tmp/vault-allocator-two-creation-mysql-20260913.log,
/tmp/vault-allocator-two-creation-mariadb-20260913.log,
/tmp/vault-allocator-corrected-broad-20260913.log.
At the 54-minute first-driver checkpoint, private PID 456340 remains alive:
VmPeak 1991704 kB / VmHWM 1534724 kB, below the unchanged 4 GiB address-space ceiling.
The allocator experiment explains why the repeated-schema test can hit an address-space
ceiling while its tracked live SQL allocations remain well below that ceiling. It is not
permission to alter operational MariaDB or treat the remaining latency as acceptable.
Further source/owner/cutover and product slices still precede human review.

## Invocation-local UserSecurity attestation

The complete proof catalog already attests its compiled representation once, but its
UserSecurity consumer independently reattested the same thirteen-trigger/nineteen-function
set three times inside one invocation. Read-only probe 15930 observed three inventory
reads in 11.679s. Explicit regression 42252 failed (expected one, actual three).
An initial direct PHPUnit helper probe lacked PHPUnit CLI configuration when formatting
that failure; it was replaced by the explicit non-PHPUnit assertion, not counted as a test pass.

UserSecurityMutationGuardInstaller now retains the freshly verified manifest only in a
local variable inside assertInstalled. Its private trigger checker reuses that manifest
for original-source equivalence and still rereads each compiled trigger with SHOW CREATE.
No caller can pass an accepted manifest; nothing survives the invocation, and a missing
or changed routine outside UserSecurity still denies the complete assembly.

Regression 94459 now PASS: one inventory read / 8.390s. The test helper invokes the same
catalog object twice and proves two independent complete inventory reads; 81617 PASS
14.168s. Compiler/normalization tests PASS 19 / 112 (0.39s), Pint/syntax/diff checks pass.
The native adversarial helper now also invokes UserSecurity directly after a previously
successful attestation, covering every existing missing/body/security/data-access/
determinism/parameter/extra/partial-representation case.
Probe 84940 ended on an overly narrow diagnostic expectation: deliberately requesting the
old non-integrated assembly was rejected first by vault_r04_user_sec_exec_bu, correctly.
After accepting that exact alternative denial only for that specific case, probe 84467
PASS on mysql (71.302s) and mariadb (69.181s): nine denials each, complete catalog restored
and all eight synthetic tables unchanged.

An additional in-call DDL probe replaced the later user BEFORE UPDATE trigger with its
raw original after the complete compiled set had been attested. Probe 70536 failed:
the later raw-trigger fallback accepted the partial representation. The private checker now
requires every manifest-selected later trigger to remain compiled even when its current
body no longer contains the routine prefix. Probe 84903 PASS; exact source restored.
The permanent native helper includes this late-replacement test. Its actual read-count and
late-replacement methods passed on both drivers in probe 63558, with full final catalog and
eight-table read-back. No application authority/catalog bypass was used.

Whole unmodified two-driver writer/read-back/rollback probe 7663 ENDED PASS:
mariadb 33.419s, mysql 30.975s. Every synthetic table is restored by transaction rollback.
Final sequence 13919 ENDED exit 0: nine direct UserSecurity drift denials each on mysql
(69.458s) and mariadb (71.646s), exact catalog restored. Focused SQLite creation,
UserSecurity/Fortify/profile/core/compiler/normalization PASS 60 / 1270 (559.09s).
Logs: /tmp/vault-user-catalog-drift-final-20260913.log,
/tmp/vault-user-catalog-consumers-20260913.log.
The sequence finished before new source work began. Prior full driver/broad results
precede the last narrow optimization; final focused consumer and whole-flow checks now pass.
This closes the bounded optimization, not the default-allocator/operational resource gate.

## Next source boundary (2026-09-14)

VaultActionPlanSourceGuardDefinitions and VaultActionPlanRequesterProofPredicate implement
only immutable pending base creation through exact one-use source identity, building commit,
post-review/security gates and fresh finalized requester proof provenance. They approve no
effects, construct no trusted finalized plan and are not installed or runtime-bound.
Actual action-plan base DDL and these guards pass SQLite 5 / 344 with intentionally synthetic
external facts: five plan/proof families, mismatched identity/fences/proof/trust/owner denial,
raw/replay denial, retention and caller rollback. Expanded base/child guards now PASS 5 / 642
on each driver, with no skips. Decoder/hydrator and subsequent source work are tracked in
2026-09-14-vault-action-plan-source-readback.md; that file is the current source-work checkpoint.
Subtype/subject assembly, canonical source finalization and coordinated plan/approval stores
remain in the same active Slice 04. HR-2026-09-04-003 remains Pending, not review-ready.
Private PID 456340 is retained for bounded native checks; operational PID 692 is untouched.
Implementation remains on authoritative Dev. No new go-ahead from Svein is needed.
