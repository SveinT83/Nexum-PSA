# Vault Completion Matrix

## Product boundary update (2026-10-01)

Svein removed the PSA-owned MCP server from the product direction. The separate
NexumMCP owns MCP; PSA retains its domain APIs. Secrets/Vault is not being built
as a backend for a future PSA MCP server. Technician/customer credential management
and actual PSA integration needs remain independently in scope. MCP-only work is
not a Vault completion prerequisite. Older MCP wording below is historical where
it conflicts with [the accepted boundary decision](../adr/2026-10-01-separate-nexummcp-ownership.md).

Status: Active plan
Date: 2026-09-04
Parent: `docs/rfc/2026-09-04-vault-domain-and-operational-credential-platform.md`

Vault is a complete product only when every required row is implemented, verified, documented, and
passes its stated human/security gate. Providing secrets to AI or Connections does not complete the
Vault product.

| Order | Capability / Feature Slice | State | Dependency / completion gate |
| --- | --- | --- | --- |
| 00 | Governance, canonical RFC/ADR set, threat model, credential inventory, completion matrix | Done On Dev | Canonical files, current-state corrections, dependency gates, TODO, and human review are reconciled. |
| 01 | Runtime-disabled Vault module and readiness/control-plane shell | Done On Dev / HR Pending | Runtime remains disabled; health is safe and non-zero; no real secret acceptance or route. |
| 02 | Independent key provider and envelope cryptographic suite | Done On Dev / HR Pending | Synthetic contracts and independent cryptographic review pass; no key provisioning or real credentials. |
| 03 | Item types, immutable encrypted versions, and append-only safe audit | Done On Dev / HR Pending | Empty guarded schema and exact permissions are migrated/read back; no writer, UI, or runtime consumer. |
| 04 | [Central authorization, grants, collections, Vault session/step-up, approvals](../feature-slices/2026-09-04-vault-central-authorization-grants-collections-step-up-approvals.md) | Approved / Blocked | Both pre-code reviews passed and Svein Tore authorized implementation on 2026-09-05 via explicit RFC/ADR approval and “Iverksett”. Implement the exact reviewed contract on Dev; runtime and all content operations stay disabled. |
| 05 | Technician item workspace and normal lifecycle actions | Draft | Requires Slice 04; only implemented controls may be visible. |
| 06 | Typed record relationships and protected metadata search | Draft | Requires central authorization and a reviewed search-field matrix. |
| 07 | Rotation/review schedules, dependency impact, revocation, recovery, notifications | Draft | Requires versions and grants; scheduler health must be real. |
| 08 | Backup, isolated restore, incident lock, key rotation, recovery custody | Draft | Must finish before any real credential migration. |
| 09 | Customer Portal publication and customer Vault workflow | Draft | Explicit portal grant and step-up; no implicit Client membership access. |
| 10 | Runtime use-without-reveal | Dependency implementation approved / Queued | Svein approved implementing the necessary #270 Connection Broker and #272 Execution/Approval parts in this Vault workstream on 2026-09-10. They remain unimplemented dependencies; finish active Slice 04 first. |
| 11 | Controlled import and encrypted recipient-bound export | Draft | Requires recovery and strongest step-up/approval. |
| 12 | Legacy migration framework and first non-production pilot | Draft | Requires Slice 08; one source/caller matrix and separate purge decision. |
| 13 | TOTP and encrypted/scanned attachments | Draft | Requires versions, grants, crypto, storage, and isolated scanner. |
| 14 | Vaultwarden adapter and conflict review | Dependency implementation approved / Queued | The necessary #270 transport dependency is included in Svein's 2026-09-10 approval; Integration owns transport and Vault owns authority/conflicts. External endpoint verification remains separate. |
| 15 | Remaining source migrations | Draft | One provider/source per Feature Slice and human review. |
| 16 | Completion, independent security/penetration review, production gate | Draft | Requires every mandatory RFC capability and production rehearsal. |

Current Slice 04 work (2026-09-14): authenticated planning and post-review evidence integration.

Current execution checkpoint (2026-09-18): **4 of 17 main parts Done On Dev**; 04 Blocked.
Decision owner Svein: pending-admin TOTP onboarding versus the second-candidate readiness floor.
See the delivery handoff for reproduced denial, proposed resolution and completed native tests.
Owner: Codex / Svein. Workflow repair is Done On Dev; next product deliverable is the complete 04-A flow.
See [the current delivery handoff](2026-09-17-vault-delivery-workflow.md) for the
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

Pending-proof creation cleanup (2026-09-13, in progress): source/phase tests PASS 4 / 144;
paired source/commit/proof/trust definitions and production audit test PASS 1 / 54, with
last-write rollback/retry across three purposes. Broad plus both drivers PASS 836 / 26,777,
no skips/deferred failures. Locked writer tests PASS 1 / 66; shared units and both writer
drivers PASS 822 / 25,729 combined, no skips/deferred failures. All runs finished.
Atomic creation-evidence cleanup and retained resumption/staged-loser repair are now connected.
Expanded migrated SQLite PASS 1 / 248 (1:17.387), including all purposes and write-boundary
rollback. Broad PASS 836 / 26,983. Corrected live-fixture ordering passes SQLite 1 / 251;
no runtime/TTL change. Private mysql/mariadb verification reruns sequentially (TODO, exec 86813).
No operational activation, product-row completion or practical human-review readiness is implied.

Inert replacement-transition cleanup (2026-09-13, verified bounded flow): the exact expired retry
source may be aborted without activating/revoking either proof. UserSecurity/owner-bound paths
remain excluded. Expanded migrated transition/source/expiry/staged-loser tests PASS 4 / 269
(2:06.321); parser/conjunction regressions PASS 4 / 20. The SQL construction fix preserves
all checks and NULL semantics while avoiding SQLite parser/AST limits. Broad PASS 829 / 26,471,
mysql PASS 1 / 124, mariadb PASS 1 / 125; combined 831 / 26,720, no skips/deferred failures.
All runs finished, final formatting/diff/permissions pass and private resources are cleaned.
Flags false and helpers unbound. No runtime activation or product-row completion is claimed.

Staged losing-cleanup repair (2026-09-13, verified bounded flow): the closed newer-commit path
now verifies the exact winning expiry cleanup and original aborted attempt before aborting
only its staged loser. Shared guards and coordinator preserve atomic trust order and winner
history. SQLite baseline source/expiry 2 / 88 and expanded loser 1 / 57 (32.123s) pass;
MySQL passes 1 / 57 (34:52.271); MariaDB passes 1 / 58 (30:03.894).
Broad regression passes 824 / 26,327 (8:20.105); combined 826 / 26,442, no skips/deferred
failures. All runs finished; flags false and helpers unbound. TODO tracks resource cleanup
and remaining source families. No runtime activation or change to product-row readiness.

Complete inert-attempt expiry flow (2026-09-13): two explicit commit CAS operations and their
coupled trust changes now run after preparation in one coordinator-owned transaction. Exact
shared catalogs admit only the complete new assembly. SQLite source/flow PASS 2 / 81, expanded
full rollback flow PASS 1 / 57 (30.110s). MySQL PASS 1 / 57 (34:47.701); MariaDB PASS 1 / 58
(29:56.582). Broad PASS 819 / 26,020 (5:39.222), new same-assembly consumer cases PASS 4 / 250
(2:11.206). Combined 825 / 26,385 with no skips/deferred failures; all runs finished.
Live flags false; coordinator/finalizer unbound. TODO tracks private-resource cleanup.
Other source cleanup/release and staged loser repair are still closed; no runtime activation.
Slice 04 In Progress and HR-2026-09-04-003 Pending/not practical-review ready.

Locked cleanup preparation (2026-09-13): exact guard catalogs and locked retained source/trust
now precede newer cleanup commit/source and actual typed pending audit. Sealed migrated SQLite
integration PASS 1 / 26 (27.898s), including full rollback and fail-closed missing guard/live
target cases. MySQL PASS 1 / 26 (32:50.149); MariaDB PASS 1 / 27 (26:26.092).
Final broad PASS 819 / 25,989 (5:53.699), combined 821 / 26,042, no skips/deferred failures.
All test processes finished; live flags false and preparer unbound. TODO tracks cleanup.
No target-abort/finalizer or runtime binding yet. Slice 04 In Progress and HR-2026-09-04-003
Pending/not practical-review ready; preparation is not complete cleanup or a complete product.

Expired-attempt abort source (2026-09-12): three database guards accept only exact expired,
inert attempt evidence under a newer cleanup commit and one-use canonical system context.
Actual migrated SQLite fixture PASS 1 / 31 (26.246s), including raw/invalid source denial,
rollback and immutable retention. MySQL-driver PASS 1 / 29 (30:10.631), verified 2026-09-13.
MariaDB PASS 1 / 30 (25:29.937); final broad PASS 818 / 25,963 (5:20.185), combined
820 / 26,022, no skips or deferred failures. All runs finished; TODO tracks cleanup.
Source-only implementation: target abort, typed
cleanup audit/finalizer and runtime assembly are still incomplete. No runtime activation.
Slice 04 remains In Progress and HR-2026-09-04-003 Pending/not practical-review ready.

Retained-history/writer integration (2026-09-12): actual migrated owner schemas and production
foundation recorder are now exercised together with the whole audit cutover. SQLite PASS
1 / 30 (35.570s); MySQL PASS 1 / 23 (46:55.925); MariaDB PASS 1 / 24 (40:08.635).
Final broad PASS 817 / 25,932 (4:43.708), combined 819 / 25,979, no skips/deferred failures.
No runtime activation or operational entry; remaining owner and product work precedes review.

Last-only thaw/completion (2026-09-12): internal exact-catalog/history checks now surround
freeze removal and the separate one-use stage-5 DML marker. SQLite PASS 2 / 264 after a
test-only replay assertion correction; full drivers PASS 6 / 768 and broad PASS 816 / 25,902,
combined 822 / 26,670. No runtime activation
or operational entry. Full-owner retained-history/writer integration and product work remain.

Frozen runtime-validator switch (2026-09-12): exact restart catalogs preserve the freeze,
permanent protection and full locked manifest. SQLite PASS 2 / 206; full drivers PASS 6 / 598
and broad PASS 816 / 25,902, combined 822 / 26,500. TODO tracks owned server/log.

Frozen guard-installed checkpoint (2026-09-12): exact permanent-only locked verification now
precedes the one-use revision-3-to-4 CAS, with independent retained-state read-back. SQLite
rollback/replay/empty/nonempty PASS 2 / 173; full drivers PASS 6 / 507, broad PASS 816 / 25,902.
Earlier permanent handoff verification completed at 822 / 26,286, no skips/deferred failures.
Thaw/completed cutover, remaining owner paths and product slices remain. Runtime off;
Slice 04 In Progress and HR-2026-09-04-003 Pending/not practical-review ready.

Frozen permanent-guard handoff (2026-09-12): exact catalog and full locked backfilled read-back
now enforce permanent CREATE/verify before temporary DROP, including exact overlap restart.
Three-driver rollback/restart and final broad verification PASS 822 / 26,286; see TODO/HR.
This earlier handoff checkpoint did not advance the header or remove the freeze.
No operational entry, runtime binding/activation or product readiness. Slice 04 In Progress;
HR-2026-09-04-003 Pending/not practical-review ready.

Owned foundation backfill coordinator (2026-09-12): retained identity, capture/resume, missing
trust children and stage CAS now share one owned transaction. Repeated completion proves exact
sets/view/catalog without writes. Three-driver contracts 6 / 268 and final broad regression
816 / 25,902 PASS: 822 / 26,170, no skips. All runs finished and private resources cleaned.
Permanent-guard/thaw and other owner/cutover paths still precede product slices. No runtime/
operational activation; Slice 04 In Progress and
HR-2026-09-04-003 Pending/not practical-review ready.

Full migrated factor-boundary verification complete (2026-09-12): mysql 1 / 103 and mariadb
1 / 104 PASS; final broad SQLite 816 / 25,902 PASS, totaling 818 / 26,109 with no skips.
All runs finished; owned schemas/server cleaned. Runtime TOTP rules remain unchanged.
Remaining typed owner/consumption/abort/cutover paths precede product slices. Slice 04 and
HR-2026-09-04-003 remain In Progress/Pending; no operational activation or practical-review readiness.

Guarded rate anchors (2026-09-12): exact one-use seed/threshold-lock contexts and the complete
internal anchor+telemetry manifest now protect rate preparation and pruning. Direct mutation,
replacement/deletion and active-lock extension/reset deny. Migrated SQLite and private-driver
worker verification PASS 820 / 26,139; see TODO/HR for exact runs and fixture limits. Resources
are cleaned and runtime remains off. Full migrated-Maria factor verification, remaining typed
owner/consumption/abort/cutover and product work still precede practical review. Slice 04 stays
In Progress and HR-2026-09-04-003 Pending.

Bounded telemetry retention (2026-09-12): guarded exact INSERT/no UPDATE and one-use old-only DELETE
now preserve permanent attempts/audit and active locks. A separate bounded top-level pruner is
unbound; the migrated factor boundary enables the explicit telemetry guards. Broad 816 / 25,891
plus private mysql/mariadb 1 / 25 each PASS: 818 / 25,941, no failed checks deferred. Resources
are cleaned and runtime stays off. Raw anchor guards/final assembly, full migrated-Maria factor
boundary and other typed owner/cutover paths still precede workspace/product slices and practical
human review. Slice 04 remains In Progress; HR-2026-09-04-003 Pending.

Combined attempt transaction (2026-09-12): standard and both enrollment purposes now use one
factor/rate/replay/proof/audit transaction, preserving standalone wrapper contracts. Real-factor
SQLite tests cover successful issuance/reuse, failures after CAS/proof finalization and locked
attempts without factor access or lock extension. Broad 815 / 25,862 plus independent worker
contracts 1 / 81 on each MariaDB driver pass: 817 / 26,024. Both rate thresholds are exercised
under contention with clocks captured after lock release. Pruning/raw rate guards, full migrated-
Maria factor-boundary verification and other typed owner/cutover paths remain before the UI.
Slice 04 stays In Progress, runtime disabled and HR-2026-09-04-003 Pending/not review-ready.

Durable attempts/rate persistence (2026-09-12): real immutable attempt/evidence/audit finalization
and optional exact trust-LAST guards are connected. The internal repository serializes exact
anchors and records/counts/locks in the same owned transaction. Tests cover fifth/twentieth failure,
rolling-window bounds, skipped attempts without lock extension, transaction-bound one-use state
and complete rollback. Migrated SQLite 1 / 129 and synthetic driver contracts 4 / 225 per driver
pass; final broad evidence is in TODO/HR. Private resources are cleaned, ports stay unbound and
flags stay false. The full factor/rate/replay/issuance transaction, concurrency/pruning/raw rate
boundaries and remaining owner/cutover paths still precede UI and practical review. Slice 04
remains In Progress and HR-2026-09-04-003 Pending; this is not product completion.

Standard/enrollment proof lifecycles (2026-09-12): real repository issuance, reuse without extension, expiry
replacement and atomic rollback are connected. Locked current/trusted read-back prevents accepting
detached snapshots. Root/authority/security lock-order regression is fixed. Actual bcrypt/TOTP/
replay-CAS integration passes migrated SQLite 2 / 64, including the exact pending generation and
both enrollment purposes without confirming TOTP or authorizing recovery. Final broad SQLite
passes 808 / 25,387 and repository parity passes 2 / 114 per MariaDB driver: 812 / 25,615, no skips.
Pint/diff/permissions checks pass and all private schemas/servers are cleaned. Ports stay unbound;
rate/attempt/HTTP, other credential/owner/cutover and the rest of Slice 04 remain before UI/review.

Primary rehash audit (2026-09-12): exact audit binding and trust-LAST now connect to the explicit
integrated UserSecurity store. The verified-login scope exposes identity only after locked password
preflight and never establishes an authenticated session. Actual bcrypt failure/rollback/success/
no-op and parent proof/audit rollback/denial/resume tests pass within 802 tests / 25,183 assertions,
no skips. Fresh per-run identity prevents later-transaction or PDO-reconnect reuse. Extra Feature
regression passes 29 / 306. Full migrated driver runs pass 1 / 236 and 1 / 237, completing
833 / 25,962 with no skips. All private schemas and the owned server were cleaned.
Ports/runtime stay unbound/off; remaining credential, owner/cutover and product slices still precede
workspace and practical review. HR-2026-09-04-003 remains Pending.

Proof/UserSecurity integration (2026-09-12): exact catalogs, dormant SQLite UserSecurity/replay
functions, frozen audit precision and runtime audit guards support the internal writers. An explicit
unbound port now connects all-session invalidation and exact retained-source resume to the real
UserSecurity store. The complete guard assembly is mandatory; default denial remains unchanged.
Actual migrated SQLite credential effect, rollback, forced commit and parent resume pass 1 / 163.
Synthetic writer contracts pass 6 / 384 on each MariaDB driver. Final broad SQLite passes
743 / 25,367; existing store/resume Feature tests pass 18 / 230. Full migrated-Maria integration
passes 1 / 163 and 1 / 164: completed checkpoint 777 / 26,698, no skips. TODO and HR-2026-09-04-003
record limits. All private schemas/server were cleaned; historical checkpoints
below are not current pending-run instructions. Remaining credential-flow coverage, rehash audit,
owner/cutover and the rest of Slice 04 still precede workspace/human review. No operational
installation or activation; bootstrap/factor facts remain synthetic.

## Cross-Cutting Gates

UserSecurity proof invalidation (2026-09-12): source/terminal guards now also have an explicit
parent proof-set finalizer that couples audit trust and execution/gate-LAST completion. Exact
catalog/UDF setup, internal writers, full enumeration/resume and rehash-primary audit remain before
the UserSecurity store can use the whole flow. Its denyActiveProofs remains fail-closed. See
HR-2026-09-04-003 for final evidence and synthetic-parent limits. Slice 04 and the whole product
remain unfinished; no operational installation, workspace or practical review readiness is implied.

Coupled expired replacement (2026-09-12): the explicit integrated proof assembly finalizes its
exact terminal commit, old proof and audit trust before activating the replacement. An internal
expiry finalizer, both standard/enrollment pending writers and the expiry source/audit writer
are implemented. Latest three-driver evidence totals 720 / 24,375, no skips; HR-2026-09-04-003
records exact runs and synthetic-fixture limits. Other typed terminal branches, the complete
credential/issuance repository and owner/origin/cutover integration still precede workspace and
practical review readiness. Replacement creation must stay inside the enclosing issuance transaction.

Coupled proof creation (2026-09-12): exact commit/source/trust finalization and its guarded
application finalizer are implemented in an explicit, non-operational assembly. Real audit
INSERT integration, rollback and foundation compatibility are tested. Final verification is
tracked in HR-2026-09-04-003. Terminal transitions, the full issuance repository and the other
source/owner/cutover paths still precede workspace and practical review. Runtime remains disabled.

Pending proof source guards (2026-09-12): one-use source-context INSERT and retention checks
are tested for both proof families. Final units/core plus isolated driver runs passed 688 /
22,361 without skips; the earlier broad Feature/core run passed 252 / 17,628. HR-2026-09-04-003
records limits. Source UPDATE, complete evidence/source/trust finalizers and guarded lifecycle
repositories still precede operational installation. Runtime stays disabled and review is not ready.

Live proof integration (2026-09-12): the real standard/enrollment readers and central freshness
adapter verify exact current security, purpose, source/audit/trust and database time under retained
operation locks. Final units plus two isolated driver runs passed 702 / 21,722 without skips.
HR-2026-09-04-003 records scope and limitations. Protected source DML, canonical planned values,
complete owner/origin writers, shared trust and cutover/repositories still precede workspace
and review readiness. Runtime and both public adapter bindings remain disabled.

Approval source/phase integration (2026-09-12): exact selected-source trust is verified at
691 / 43,091. Authority finalization now also enforces ordinary reservation versus exception
consumption/obligation ordering and reciprocal retained fences. Peer-approved enrollment works
in the pure lifecycle; units/SQLite pass 675 / 28,752 and final driver/core integration passes
17 / 16,750 (692 / 45,502 across the two final runs, no skips); see
HR-2026-09-04-003. Protected source DML, canonical snapshot/payload and live-proof checks,
complete coordinators, shared trust and cutover/repositories remain before workspace/readiness.
No operational migration or activation occurred. Automatic continuation remains active.

Approval relation binding (2026-09-12): authority finalization now matches its request,
consumption, witness mode, actor/proof/session and plan digests, with separate ordinary,
sole-admin and TOTP-exception shapes. Peer-approved TOTP is also covered. Verification and
remaining selected-source/lifecycle/fence limitations are in HR-2026-09-04-003. This does not
complete Slice 04 or open shared trust finalization, runtime, workspace or practical review.

Finalized plan-source provenance (2026-09-12): the authority finalizer now rejects unfinished,
aborted, untrusted or substituted action-plan evidence. Exact source/audit/trust and requester
proof correspondence passed combined three-driver verification at 683 / 30,844. See
HR-2026-09-04-003 for fixture limits. Integrated Slice 04 source/approval/operation writers,
shared trust finalization and cutover still precede workspace and practical human review.

Exact plan/subject identity (2026-09-12): authority finalization checks the extension reason
and shared digests plus a one-to-one plan/mutation subject set, including nullable typed
targets. A same-sized unrelated set is insufficient. HR-2026-09-04-003 records tests and
the remaining canonical-payload/domain-write/proof/approval limitations. Slice 04 and its
shared trust finalizers remain in progress; no workspace or human-review readiness is implied.

Authority binding checkpoint (2026-09-12): the operation guard now binds the exact primary
audit, plan requester/proof, consumed witness and finalized paired UserSecurity execution.
Physical subject sets and terminal times are checked independently of cached counters.
See HR-2026-09-04-003 for actual tests and fixture limits. Exact source values/proof/approval
provenance and shared trust finalization are still unfinished; do not move to the workspace
or mark review-ready. Automatic continuation remains active. No operational migration or
runtime/production change occurred.

Foundation writer adapter (2026-09-11): the existing action now has a tested optional guarded
recorder, preserving correlation IDs and model metadata without fallback after failure.
Combined contracts passed 701 / 25,077, including actual migrated core read-back. Production
binding remains absent pending full cutover. Row04 source/operation/abort finalizers and the
remaining Slice 04 integration still precede the workspace and human review.

Authority finalizer prerequisite (2026-09-11): nullable SQLite source references no longer
mask a mismatched context. All mandatory marker/identity fields and reciprocal audit/trust
IDs are checked null-safely on SQLite and both MariaDB drivers. Focused contracts passed
10 / 374; units plus authority/quorum contracts passed 671 / 20,932. See HR-2026-09-04-003
for the deliberately isolated fixtures and remaining source/finalization dependencies.
This does not enable Row04 trust UPDATE, runtime, workspace, or human-review readiness.

Atomic foundation finalization (2026-09-11): foundation_direct completes pending-to-finalized
inside the original one-use audit INSERT, with exact source/ref checks, guarded terminal state,
read-back and coupled rollback. The frozen backfill catalog requires its exact UPDATE guard.
Combined contracts passed 722 / 25,478. See HR-2026-09-04-003 for fixture limits. Row04 owner/source and abort
finalizers, public writer binding and full cutover remain. No operational/runtime change;
automatic continuation stays active and human review is not ready yet.

Evidence source binding (2026-09-11): closed source kind/ID/event-family checks now prevent
borrowing another building evidence commit at INSERT. The demonstrated three-driver regression
is fixed; final source/INSERT contracts passed 32 / 3,115 and real migrated SQLite integration
passed 1 / 15. See HR-2026-09-04-003. Trust UPDATE/source finalizers and full cutover remain;
no runtime activation or practical-review readiness. Automatic continuation is still active.

Shared foundation context and view correctness (2026-09-11): foundation_direct now uses the
same one-use context/sink and atomic pending-child path, without activating the public writer.
Legacy content identities remain valid; Row04 source/origin requirements remain closed. The
MariaDB foundation-code collation regression and the deferred-revocation source-trigger bug
were demonstrated and fixed. Exact nested view attestation, source preflight, and a real
migrated SQLite pending/invisible-child test protect the integration. Final combined contracts passed 737 / 25,082.
Next: trust UPDATE/source finalizers, complete RecordVaultAuditEvent adapter, final cutover,
remaining repositories/classification and integrated Slice 04 verification, then workspace
and subsequent approved product slices. Ordinary Dev runtime is disabled and the Row04 view/
audit guard remain absent. HR-2026-09-04-003 is Pending; automatic continuation remains active.

Trust retention and foundation validation (2026-09-11): permanent trust DELETE protection is
integrated with exact capture/backfill/read-back catalogs. Units and three-driver INSERT
contracts passed 661 / 7,728; final zero/nonzero backfill and real SQLite core flow passed
7 / 174. The legacy writer now shares an exact foundation vocabulary with manifest/schema
validation; five regression cases fail before and pass after the fix. Units plus foundation
audit/readiness feature tests passed 656 / 20,358; generated SQL stayed byte-identical.
Remaining: trust UPDATE/finalizers, foundation-direct context adapter, final cutover stages,
repositories/classification and integrated Slice 04 checks before the workspace. No ordinary
Dev migration or activation. HR-2026-09-04-003 remains Pending; continuation stays active.

Guarded backfill checkpoint (2026-09-11): exact legacy/freeze/new INSERT catalogs now precede
the typed application capture/backfill flow. Missing manifest children are written through
one-use contexts under complete locked set/digest/view reconciliation. The same owned DML
transaction can CAS to backfilled/revision-3 only after complete proof; interruption rolls
it all back. The freeze remains installed. Focused SQLite/mysql/mariadb flow and catalog
regressions passed 12 / 168. The combined unit/freeze/backfill/INSERT/context/core suite
passed 698 / 9,016, followed by final contract-aligned three-driver and real-core checkpoint
verification at 4 / 79. Ordinary Dev's legacy audit catalog also passed exact read-only
comparison; no operational guard was installed.
MariaDB exact source checks use SHOW CREATE rather than lossy ACTION_STATEMENT output.
Next: lifecycle finalizers/guards, foundation-direct adapter, permanent-guard stage/final
cutover coordination, remaining repositories/classification and integrated Slice 04 checks.
A representative-size backfill must also be measured before operational cutover; current
per-entry full reconciliation prioritizes exactness and is not a throughput claim.
No operational migration or activation. HR-2026-09-04-003 remains Pending, not review-ready.

Audit insert/backfill guard progress (2026-09-11): atomic pending-child and temporary
manifest-bound foundation-child definitions are implemented. One-use contexts, exact
event/owner/reference shape, inert actor identity and complete manifest metadata are enforced.
Temporary/permanent coexistence closes backfill without an unguarded INSERT window. SQLite
branch functions are prepared without installing authority. Final combined unit, three-driver
audit/backfill, existing MariaDB context and real SQLite core-assembly verification passed
654 / 8,247. Next: coordinated guard installation/catalogs and freshly locked typed backfill
writer, trust lifecycle/foundation-direct adapter and stage advancement, then repositories/
classification and final Slice 04 verification before the workspace. No operational migration
or activation. HR-2026-09-04-003 remains Pending and not ready for practical review. Automatic
continuation remains active; ordinary go-ahead from Svein is not required.

Trust reconciliation progress (2026-09-11): captured-checkpoint read-back now consumes every
trust field, rejects conflicting provenance, returns only exact ordered missing entries, and
checks actual trusted-view visibility. Stale MariaDB TEMPTABLE snapshots deny and require a
fresh owned transaction; neither isolation nor the non-updatable view is weakened. Combined
unit/integration verification passed 661 / 7,296, followed by expanded two-driver snapshot
coverage at 2 / 94. This does not perform trust backfill or complete Row 04. The next write
boundary remains the coordinated audit AFTER INSERT and temporary trust guards, followed by
backfill/stage advancement/permanent guards, repositories and classification. HR-2026-09-04-003
remains Pending and the workspace remains unavailable. No operational migration or activation.


Guarded capture progress (2026-09-11): the internal manifest store now creates or resumes
the exact configured installation/header, inserts the ordered immutable foundation items
through one-use database contexts, verifies the full set/digest, and CASes to manifest_captured.
It rejects skipped rows, changed metadata, replay, incorrect identity and partial/changed guards.
Historic DATETIME(0) values are normalized to the same UTC DATETIME(6) instant, not compared as
different text. Exact table/trigger/view checks preserve literal bytes; the trusted-audit view
now verifies the whole projection, join, owner subqueries and Boolean structure, not fragments.
This is still an internal cutover component. Audit trust backfill, permanent guards, coordinated
authorization stores, classification, final Slice 04 verification and the workspace remain.
Tests are recorded in HR-2026-09-04-003. No operational migration or activation occurred.


Retained-manifest checkpoint (2026-09-10): exact header parsing and independently locked
root/header/item/trust plus base-set read-back are implemented. The combined test run passed
604 / 7,181 on SQLite/mysql/mariadb. This proves the captured checkpoint only; guarded
provenance writes, trust backfill/permanent guards, coordinated authorization and UI remain
unfinished. No operational migration or activation occurred.

Dependency scope clarification (2026-09-10): Svein explicitly answered yes to implementing
the necessary parts of #270 and #272 as part of finishing Vault. Their published RFC comments
were read back at discussioncomment-18193445 and discussioncomment-18193452; both GitHub
comments still display Draft. This chat approval is bounded to Vault dependencies, not an
assertion that either entire child is implemented or that its GitHub status was edited.
Before their implementation, reconcile Integration Hub candidate code and record the required
technical ADRs/Feature Slices against those RFCs and the accepted Vault runtime-boundary ADR.
No competing Connection, Execution, approval, or credential store is allowed.

Included dependencies: Connection/version/capability/binding discovery, exact durable
Execution Step and approval binding, late revalidation, trusted secret-use execution,
sanitized verification/audit and the transport needed by Vaultwarden.
Not implicitly included: the full Agent/Chat redesign, Memory, Tool Builder, marketplace,
general Script/terminal authoring, or the Automation workflow designer.
Slice 04 remains the sole active implementation. Main, operational migration, runtime
activation, real credential migration and named human review remain separate gates.

Row 04 manifest progress on 2026-09-10: the exact audit freeze and locked foundation-manifest
reader are implemented. The reader validates historic event semantics and references without
reading credential fields, rejects non-null Row04 audit references, preserves historical UUID
versions and timestamp instants, and explicitly owns REPEATABLE READ / BEGIN IMMEDIATE.
This is not trust backfill or authorization readiness. Guarded header/item persistence,
audit/trust triggers and cutover, coordinated repositories, classification and final integrated
verification still precede the technician workspace. No practical human-review invitation yet.

Row 04 progress on 2026-09-10: pending 010180 assembles the remaining 31 core tables with
restartable MariaDB FK prefixes and atomic SQLite validation. Foundation UUID references retain
the deployed collation. Final bounded MariaDB core assembly/restart passed both driver names
(4 tests / 254 assertions); the SQLite foundation/assembly run passed 12 / 679.
Schema assembly is not runtime readiness: coordinated guards, repositories,
audit-trust cutover, classification and final integrated verification remain outstanding.
The ordinary Dev database is not migrated or activated by this implementation/test work.

Every Level 3 slice requires:

- an explicit TODO owner/status and stable human-review entry;
- relevant unit, feature, migration, MariaDB contract, and negative leakage tests;
- additive permission migration for existing installations where permissions change;
- deployment and rollback instructions without printing keys or secrets;
- queue, failed-job, session, cache, log, analytics, and error-path secret-negative checks;
- Knowledge/developer documentation kept current;
- preservation of unrelated dirty Dev work and WIP reconciliation;
- explicit human review for user-visible, permission, migration, and production behavior.

## Product Completion Definition

Do not mark Vault complete until:

- Nexum-native technician and authorized customer workflows are complete;
- key backup, isolated restore, compromise response, rotation, and destruction are proven;
- runtime use-without-reveal works through #270/#272 without exposing plaintext;
- Vaultwarden publication/conflict/offboarding works while Nexum stays authoritative;
- every in-scope legacy secret source has an explicit authority/cutover/purge state;
- import/export, TOTP, attachments, monitoring, and documentation are complete;
- independent review, penetration testing, and named human review pass;
- production activation and the first real credential migration receive separate approval.
