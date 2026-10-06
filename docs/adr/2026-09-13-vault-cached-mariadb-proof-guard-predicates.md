# ADR: Cached MariaDB Predicates for the Vault Proof Guard Assembly

Status: Accepted
Date: 2026-09-13
Decision Makers: Codex, under Svein Tore's approved Vault implementation mandate
Related: approved RFC #279; Slice 04; HR-2026-09-04-003 (Pending)

## Context

The complete abort-enabled proof/audit/commit guard graph exhausts the private MariaDB
10.11.14 process while opening and parsing triggers, even for a zero-row UPDATE. An
8 GiB address-space ceiling is reached before a Vault row changes. Smaller optimizer
search, emulated prepares, shallow predicate factoring and a few cached predicates
did not resolve it. SQLite's separate parser limits also rule out indiscriminate
nesting of the SQL expressions.

The security predicates themselves must remain authoritative. Removing checks,
raising resource limits, extending proof lifetimes or silently accepting a partial
catalog is not an acceptable performance fix.

## Decision

For the complete native MariaDB/MySQL proof assembly with UserSecurity, attempts and
aborts, compile the existing large IF/ELSEIF conditions of thirteen named triggers into
read-only, separately cached SQL functions. Keep the original definition factories as
the predicate source of truth. SQLite predicates and every lifecycle/capability rule stay
unchanged. The 2026-09-14 extension below also provides a closed cached representation
for the existing base proof/audit assembly.

Each function:

- preserves the original condition, including its COALESCE/NOT/NULL rule, and returns
  IF((condition),1,0) so SQL truth is normalized before TINYINT narrowing;
- recognizes statement headers without rewriting nested SQL IF() / CASE expressions;
- has content-addressed identity including the original trigger and typed signature;
- takes only exact NEW/OLD row fields, using their column type, signedness, precision,
  character set, collation and enum labels/order rather than serializing them to JSON/text;
- preserves quoted literal bytes and does not rewrite literals that resemble row fields;
- is NOT DETERMINISTIC, READS SQL DATA and SQL SECURITY INVOKER;
- cannot assign session state or move statement-state/side-effect functions such as
  ROW_COUNT, FOUND_ROWS, LAST_INSERT_ID or advisory-lock operations into a predicate.

Trigger write order, one-use context consumption, audit/trust finalization order and
transaction ownership are not moved into the functions. Side-effect statements stay
at their original positions in the trigger.

The read-only catalog must verify the entire compiled representation: all thirteen
trigger bodies, exact function inventory, original predicate bodies, parameter order
and types, execution characteristics and matching SQL mode. Missing functions, extras,
partial trigger conversion and altered return expressions are rejected. SHOW CREATE
is used for source bytes; lossy ACTION_STATEMENT output is not a predicate source.
The existing complete table/schema and UserSecurity checks remain in place. Attestation
results are not cached across operations.

At this checkpoint only the already-owned private integration-test assembly installs
this representation. There is no operational installer, runtime activation, real
credential migration or production deployment in this decision.

## Rationale

The bounded prototype preserving original SHOW CREATE source completed the zero-row
prepare in 4.582 seconds and a rolled-back writer probe in 0.761 seconds. The first
application-backed catalog and unmodified writer probe also passed. This supports
structural reuse of parsed predicates; it is not a substitute for the complete
driver, rollback, provenance and shared-consumer suites.

## Consequences

Deployment must account for CREATE ROUTINE and the required EXECUTE privileges.
READS SQL DATA and INVOKER are verified properties, not permission grants. Binary-log
restrictions may require a separately reviewed deployment procedure; do not disable
them or change server trust settings automatically.

Exact function attestation adds catalog work. Verify it once per complete proof
catalog invocation where possible, without retaining a cross-operation authority
cache. Resource and latency checks remain part of native verification.

Pure PHP compilation may be memoized by the complete original SQL and column contract,
bounded to 32 entries and 16 MiB serialized output. The manifest still reads current column
metadata and every attestation still reads live functions/triggers. This is source reuse,
not a cached authorization decision or catalog acceptance. Byte-identical freshly read
SQL needs no tokenization; different bytes still receive the complete canonical comparison.
No live catalog result is retained across calls.

The UserSecurity installer may share one freshly attested manifest only among its private
trigger checks within a single assertInstalled invocation. The local variable is reset on
every call; callers cannot inject or retain an acceptance token. Every selected later trigger
still gets fresh SHOW CREATE verification and may not fall back to its raw representation
after the compiled set has been verified. Standalone UserSecurity attestation must continue
to reject drift in the entire routine set, including functions outside UserSecurity.

Function parameter contracts mirror the current row contract; they do not replace
the existing schema attestation. No new metadata or secret-bearing storage is added.

## Alternatives Considered

- Larger memory limits: masks growth and risks other Dev services.
- Reduced optimizer search or emulated prepares: reproduced the failure.
- Factoring only abort conditions or only four large triggers: insufficient.
- Removing predicates or bypassing catalog checks: changes the security contract.
- Rewriting the business predicates: unnecessary semantic and SQLite regression risk.

## Base Proof Assembly Extension (2026-09-14)

The retained UserSecurity proof-writer regression also reproduced allocation failure in the
older, otherwise valid base proof/audit assembly. Releasing each completed fixture bounded
the direct terminal test, but the raw resumed writer still exhausted the unchanged 4 GiB
private limit. The full thirteen-trigger representation could not honestly be used as a
substitute: that fixture does not have the complete UserSecurity/attempt/abort capabilities.

Use the same compiler for exactly eight existing base triggers: transition INSERT,
evidence-commit BEFORE/AFTER UPDATE, standard/enrollment proof UPDATE, audit validation/
trust creation and audit-trust UPDATE. The original base definition factories remain
authoritative. This is another complete, named code-defined representation, never an
arbitrary selection of live guards or a permission upgrade.

The manifest explicitly records its existing capabilities. The base representation has
UserSecurity, attempts, aborts and actionPlans all false. The finalization catalog rejects
any caller requiring one of those capabilities, and still checks the original complete
base inventory. Full manifests retain all their previous requirements. UserSecurity guard
attestation cannot borrow a base manifest for a missing protected-user/gate predicate.

All function inventory, exact types, SQL source, INVOKER/read-only semantics and live
read-back requirements remain. Missing functions or a mixture of raw and cached selected
triggers deny. Catalog diagnostics prefer a complete candidate's drift over an irrelevant
missing-table error from another representation. Only the owned native test fixture
installs this new representation while no transaction is active; no operational installer,
runtime enablement, new permission or production change is introduced.

The focused base matrix now passes mysql fresh/resume 2 / 426 and mariadb terminal,
fresh/resume/parent 4 / 606 without resetting the private server. Broad after the cache
extension passes 876 / 32720. Full-manifest compatibility and remaining consumer checks
continue in the linked plans. This internal representation decision is under the existing
implementation mandate and does not mark
the product, human-review gate or production resource acceptance complete.

## Follow-Up

Finish native tamper/rollback/provenance tests and broad Vault/UserManagement checks,
then account for this representation in the future guarded operational cutover.
Record current resources and results in
docs/plans/2026-09-13-vault-mariadb-trigger-memory-rework.md.
HR-2026-09-04-003 remains Pending and not practical-review-ready until the remaining
approved Vault product work is also implemented and verified.
