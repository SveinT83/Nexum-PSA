# Vault delivery workflow

## Product boundary update (2026-10-01)

Svein removed the PSA-owned MCP server from the product direction. The separate
NexumMCP owns MCP; PSA retains its domain APIs. Secrets/Vault is not being built
as a backend for a future PSA MCP server. Technician/customer credential management
and actual PSA integration needs remain independently in scope. MCP-only work is
not a Vault completion prerequisite. Older MCP wording below is historical where
it conflicts with [the accepted boundary decision](../adr/2026-10-01-separate-nexummcp-ownership.md).

Status: Workflow repair Done On Dev; product Slice 04 Blocked on pending-admin 2FA onboarding decision
Owner: Codex / Svein
Approved: Svein, 2026-09-17, explicitly requested test/workflow repair and automation changes.
Scope: execution discipline and the active integrated delivery under the approved Vault RFC and Slice 04.
Product progress: **4 of 17 main parts Done On Dev**; 04 Blocked, 05..16 unfinished.
Human review: **HR-2026-09-04-003 Pending**. No practical Vault workspace review is requested.

## Current handoff - replace this section, do not append another status diary

- **BLOCKED on a new onboarding decision**, owner Svein, 2026-09-18. Automation is **PAUSED**
  and its saved status was read back. No test is running. Product stays **4/17**; HR remains Pending.
- The already accepted invitation decision is NOT reopened: protected recipients establish a
  password, remain PENDING_INVITE, and receive no normal login; an existing administrator later
  performs guarded activation. ADR 2026-09-18-vault-privileged-invitation-activation remains Accepted.
- **New integration deadlock:** activating the second protected administrator from one ready
  candidate makes candidate count=2 but governance-ready count=1 if the invitee has no confirmed TOTP.
  The approved floor min(2,candidate_count) rejects that exact activation. Standard enrollment
  requires an ACTIVE actor and server-owned authenticated session; pending recipients cannot obtain
  that context. The activation subject matrix allows user_status only, not a hidden TOTP change.
  A sole-admin approval exception does not bypass this post-state floor.
- Evidence is in the current normative Slice sections Candidate/Eligibility and Cross-Domain
  Authority Mutation, `VaultAuthorityPlanAssembler::assertRosterTransition`,
  `CurrentWebVaultActorContextProvider::current`, and
  `DatabaseVaultTotpEnrollmentProofReader::readLocked`. This is not a transient test/setup failure.
- New bounded regression plus existing assembler/actor tests: **18 / 72**, exit 0, 0.38s;
  `/tmp/vault-pending-admin-onboarding.xml`. Control activation with two ready users is accepted
  structurally; the same second-admin activation with permissions but missing confirmed TOTP is
  denied; a pending user with a purported session still receives no actor context. These tests
  document current denial, NOT a completed administrator-activation or approval pipeline.
- Proposed resolution awaiting approval: a strictly limited UserManagement onboarding session
  may set up/confirm the invitee's own TOTP while the account remains pending, with no ordinary
  login, admin or Vault access. Then the existing administrator performs the separately guarded
  activation. This needs an explicit supplement for identity, one-use enrollment and audit;
  do not invent a new proof purpose, weaken the floor, remove roles, or grant a temporary login.
  Resume only after Svein decides this new 2FA-before-activation boundary (or another explicit policy).

### Completed evidence - do not restart unchanged batches

- Internal privileged invitation acceptance is verified. Broad SQLite **909 / 31620**, exit 0,
  no failures/errors/skips in `/tmp/vault-invitation-acceptance.SfxDWz/`.
  Its native setup failed before the workflow (gate INSERT denied), retained as exit 2.
- The owned native fixture was corrected to attest/drop expected guards only for synthetic
  seeding, then restore and attest the complete UserSecurity/TOTP guard set before all assertions.
  Corrected SQLite **1 / 43**, 26.06s, `/tmp/vault-invite-fixture-sqlite.xml`.
  Native rerun `/tmp/vault-invitation-native.peaG8S/` is TERMINAL, exit **0**,
  ended **2026-09-18T10:28:25Z**: mysql **1 / 43**, 1719.60s; mariadb **1 / 43**, 1783.78s.
  JUnit has no failures/errors/skips; both manifests matched before subsequent diagnostic test edits.
  These are two PHP drivers against isolated MariaDB 10.11.14, not Oracle MySQL certification.
- Other completed unchanged evidence: confirmed reset SQLite **9 / 110**, bridge **8 / 132**,
  native mysql **1 / 22** in `/tmp/vault-confirmed-reset-native.te2k8g/`, mariadb **1 / 23**
  in `/tmp/vault-confirmed-reset-mariadb.Utd7Ax/`. The exact reset uses two real statements, not three.
  Atomic composition **1 / 239**, broader **894 / 31475** in
  `/tmp/vault-authority-composition.xqpBIb/`; its authority coordinator is deliberately synthetic.
  Pending lifecycle `/tmp/vault-pending-delivery.mjiBWP/`: SQLite **976 / 34947**, native **2 / 134**
  per driver. Recovery `/tmp/vault-recovery-delivery.S6FkLb/`: **973 / 34546**, native **2 / 326** each.
- Native migration reuse remains approved only for `VaultIntegratedPasswordRehashTest`.
  Cold invitation lanes measured 28.7-29.7 minutes; investigate actual progress above twice that
  duration rather than rerunning silent migrations. No migration is currently active.

### Resume implementation after the decision

- Complete the real authenticated authority coordinator, installed witness/source guards,
  current plan/proof and approval/sole-admin consumption, actual administrator activation and final
  reciprocal audit in one owned transaction. `UpdateUserStatus` reaches
  `DatabaseUserAdministrationSecurityMutationBoundary::updateStatus`, which currently denies
  enforced phase. Do not replace that denial with a direct write or bind the synthetic test port.
- Then finish confirmed TOTP through that same authority flow and retained generation/actorless
  history for 04-A. Continue 04-B..04-H; only the complete normative Row04 criteria advance to 5/17.
- Existing 04-A caller gap remains: CustomerPortal `AcceptCustomerPortalInvitation` performs
  direct password/status/verification DML and its public controller logs in unconditionally.
  Integrate its complete invitation/account/membership/audit transaction through the authoritative
  boundary and test ordinary/new/existing/protected/replay/rollback behavior. Internal invitation
  evidence does not prove this separate caller.
- No commit/push, operational migration, runtime activation, real credentials, production change,
  queue/cache/build action or human approval was performed. HR-2026-09-04-003 remains Pending;
  the paused decision is not a practical Vault workspace review invitation.

## Repair acceptance criteria

1. Each test gets a newly owned socket-only database; no user, proof, approval, consumed token or
   mutation from an earlier test survives. Restored FKs, CHECKs, triggers, stored functions and
   schema-qualified views work after the original database has been dropped.
2. The first selected native case executes the full real migration chain. Subsequent cases in the
   same PHP process may restore that pre-test foundation. An occupied database is rejected.
3. The real two-case account workflow passes with its existing business/guard assertions. Record separate capture
   and restore setup times; do not infer total-product speedup from a miniature fixture.
4. Default/cold verification still works; cache is opt-in, process-local and never persists to disk
   or another PHP run. No application, authorization, TTL, guard, memory-cap or runtime policy changes.
5. Current status and next action are reconciled with TODO, completion matrix and human review.
   The revised automation is read back after update.

## Verification lanes

- **Development feedback:** focused changed-behavior tests and relevant units. Batch related scenarios
  into a complete flow; reproduce a defect before fixing it where practical. Do not run the entire
  native matrix after every helper.
- **Native workflow batch:** one PHP process per driver and coherent test selection. For the integrated
  account class, set `TDPSA_VAULT_REUSE_MIGRATIONS=1`. The first case migrates normally; later cases
  restore the exact captured pre-test schema/data into a different owned database. All workflow,
  full guard catalog, positive and negative assertions still execute.
- **Cold acceptance:** unset `TDPSA_VAULT_REUSE_MIGRATIONS` for migration/catalog changes, existing-install
  and cutover tests, and the package exit matrix. Cached runs never replace migration/upgrade evidence.
  Run the agreed affected matrix once after integration, not after each intermediate component.
- The mysql and mariadb PHP drivers currently target the same private MariaDB 10.11.14 engine.
  They do not constitute Oracle MySQL certification.

Do not split related native cases into separate PHP invocations: each new process must build its
own first foundation. Reuse happens inside a coherent batch, never across process lifetimes.

The cache is enabled only in `VaultIntegratedPasswordRehashTest`. Other suites remain unchanged;
do not claim their database setup has been optimized. Its scope/driver/socket key is valid only while
source code stays frozen during that PHP process. Capture happens before any synthetic test user or
proof is created. Each consumer runs the existing assembly and catalog assertions normally.
The installed `mariadb-dump` and `mariadb` clients are test dependencies, not new production dependencies.

Example batch on the verified private socket (recheck the socket exists before use):

~~~bash
umask 0002
TDPSA_VAULT_MARIADB_CONTRACT=1 \
TDPSA_VAULT_MARIADB_SOCKET=/tmp/tdpsa-vault-maria.CESC7v/server.sock \
TDPSA_VAULT_AUDIT_DRIVER=mysql \
TDPSA_VAULT_REUSE_MIGRATIONS=1 \
TDPSA_VAULT_SETUP_TIMINGS=1 HOME=/tmp php artisan test \
  app/Modules/Vault/Tests/Integration/VaultIntegratedPasswordRehashTest.php \
  --filter=test_account_changes_preserve_their_exact_retained_proof_set
~~~

## Next integrated deliverable: complete 04-A, not another reader-only checkpoint

Outcome: a supported authentication/security change invalidates the correct proofs atomically;
its finalized audit and owner history remain verifiable after later account changes.

- Inventory remaining recovery-code consumption/rotation and session-security-reset entry paths
  against the approved UserSecurity contract and real Fortify callers. An enum alone is not an
  implemented path; do not invent a caller or claim upstream authentication has been tested.
- Implement required actual mutation adapters together with their retained-history consumers.
  Reuse working password/login/reset/logout/token paths; do not rewrite already verified families.
- Finish retained factor-generation and authority provenance, including same-instant ordering,
  actorless/multi-subject ownership and the exact invalidated proof census.
- Exercise entry/boundary -> guarded mutation -> epoch/proof invalidation -> finalized audit ->
  later account change -> historical read-back, plus replay, wrong actor/scope, partial ownership,
  missing source and rollback denials.
- Exit requires all 04-A rows mapped to real test methods, no unresolved in-scope failure, and one
  completed affected SQLite/native acceptance matrix. A helper, enum, schema or passing unit alone
  does not close the deliverable. Where 04-A needs a 04-C adapter, integrate that existing dependency
  in this delivery instead of cycling between packages.
- Then continue 04-B through 04-H from the existing bounded checklist. Only the normative Slice 04
  Done Criteria can advance the product counter to 5/17. This workflow does not reduce RFC scope.

## Continuation rules

1. Read TODO's current Vault pointer and this handoff, then reconcile live test terminal status and
   Dev changes. Do not reread entire historical diaries on every wake.
2. If no relevant test is active, implement toward the named complete deliverable. Do not end a
   productive turn after one helper merely because it can be tested separately.
3. For each launched batch record its selection, frozen inputs, start time, log/JUnit and exit-code
   paths, expected duration based on measurements, and next action on pass/fail.
4. While a relevant batch runs, do not edit its input sources or launch overlapping work. A bounded
   status read is allowed, but do not rewrite six documents or send unchanged progress updates.
   Work on genuinely independent in-scope documentation only; do not start a new product.
5. On completion read exit code and JUnit, account for failures/skips, then continue immediately
   from the next action. Reuse completed evidence only when the relevant source has not changed.
6. If runtime exceeds twice the recorded measured expectation, inspect process/database progress
   and output once. Diagnose a concrete stall; elapsed time or unchanged logs alone do not justify
   killing a process, weakening guards, restarting tests or declaring an external blocker.
7. If two completed implementation batches fail to close any acceptance item, stop splitting the
   work further. Identify the missing integration/dependency, correct the plan inside approved scope,
   and report the obstruction once. Pause only for a real authority/decision/dependency gate.
8. Notify on a completed deliverable, material failure, necessary human action or review readiness.
   Include X of 17, active deliverable, newly closed outcome and remaining work. No invented percentage
   or completion date. Human review stays separate.

## Status ownership and safety

TODO remains the authoritative WIP register; the completion matrix owns the 17 product rows;
the existing Slice 04 checklist owns packages A-H; human-review.md owns human sign-off.
This file owns only the short current execution handoff and test protocol. Update pointers/status
in the other records at meaningful transitions, not copied test diaries.
Older dated notes are historical evidence, not commands to restart completed runs.

No commit, push, Main/production deployment, operational migration, real credential migration,
runtime activation, TTL relaxation or human approval is authorized by this workflow change.
New test databases may be created/dropped only by their existing isolated owner.
No production code, user UI, migration, queue, scheduler or build action is required for this repair.

## Verified evidence preceding the repair

Sequence 35999 is complete, not running: SQLite units/account regression **941 / 33620**;
native mysql **1 / 118**, native mariadb **1 / 118**, all zero JUnit errors/failures/skips.
Logs are `/tmp/vault-retained-terminal.XPpz06/session-flow-*.{log,xml}`.
Earlier two-case account batch: mysql 2/67, 3508.31s; mariadb 2/67, 3097.49s.
Measured setup queries consumed about 79-80% of two native cases' wall time.
These establish the bottleneck and completed history, not completion of 04-A or permission to activate Vault.
