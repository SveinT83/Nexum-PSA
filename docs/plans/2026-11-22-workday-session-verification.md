# Workday login session verification - 2026-11-22

Status: Done On Dev / Main Candidate Verified / Production Unchanged.
Owner: Codex. Policy and Main merge authorized by Svein Tore on 2026-11-22.
RFC: ../rfc/2026-11-22-workday-login-sessions.md.
ADR: ../adr/2026-11-22-bounded-workday-login-sessions.md.
Human-review checklist: HR-2026-11-22-WORKDAY-SESSIONS (Pending).

## Implementation and verification

Authoritative implementation: /var/Projects/tdPSA on Dev. Only owned changes are selected
for publication; other dirty/staged files and their owner work remain intact.
SSO_SESSION_MINUTES now defaults to 720 and the issuing service bounds valid integer
configuration to 1..720 minutes. Malformed/overflow input fails closed to one minute.
The shared Laravel idle default/example is 720; Dev's retained environment override was
explicitly updated from 120 to 720 and SSO_SESSION_MINUTES=720 added. No production change.

Regression-first evidence: before the fix, the 13 new workday cases produced 8 failures and
5 passes (29 assertions), including the fixed 60-minute expiry and real persisted-session
return after 8 hours. After implementation:

- InternalSsoTest: 48 tests / 465 assertions passed (81.31s).
- TwoFactorAuthenticationTest, UserManagementFullApiTest, UserManagementApiContractTest and
  all CustomerPortal feature tests: 53 tests / 1225 assertions passed (65.17s).
- Combined: 101 distinct tests / 1690 assertions; narrow affected authentication/API/portal
  coverage, not the whole repository suite.
- Targeted Pint: 4 PHP files passed; scoped whitespace verification passed.
- Trusted Dev HTTPS /login: 200. Actual session Set-Cookie has Max-Age=43200,
  Secure=true, HttpOnly=true, SameSite=Lax. XSRF token has its expected non-HttpOnly flag.
- Independently bootstrapped Dev effective config: database sessions, 720-minute idle,
  720-minute SSO policy, expire_on_close=false; no secret-bearing values were printed.
- Time-travel tests retain the fixed grant through 61, 480 and 719 minutes and deny at
  exactly 720. The persisted encrypted database-session test uses a fresh store and guard
  after eight idle hours. Existing signed logout, MFA and authorization tests remain green.

## Handbook

The existing Work Account Knowledge source and SSO operations runbook were updated first.
BookStack 612 revision 5 and 613 revision 4 were independently read back; their full Markdown
matches the expected update after BookStack's trailing-newline normalization. Existing content,
owners, parent book/chapter and tags were preserved. The pages explicitly state Dev verification
and pending production rollout; no 12-hour production availability is claimed.

The BookStack plugin has no permission-inspection tool. Supplemental read-only inspection
through Nexum's already configured native BookStack credential returned 200 for page 612/613,
chapter 611 and book 339 ACLs: both pages and the chapter inherit, without role overrides;
the book retains its existing named development/documentation role grants and inheritance.
No account/role/permission was changed. This is access-policy read-back, not employee OpenWebUI
retrieval. That separate acceptance remains unchecked in the human-review checklist.

## Deployment and rollback

No migration, schema backfill, frontend build, queue/scheduler change or provider mutation.
For a separately authorized production rollout, install the scoped Main change, explicitly
set SSO_SESSION_MINUTES=720 and SESSION_LIFETIME=720, then rebuild configuration with
umask 0002 / php artisan config:cache. Read back effective config and HTTPS cookie flags.
Do not flush sessions or app data. Employees sign in again to receive a new grant; old grants
are not extended. Current provider-client limits and real workday acceptance are unverified.
Rollback needs old code/config plus targeted revocation of the rollout cohort only if existing
12-hour grants must end immediately. Reverting code alone does not shorten persisted grants.

## Publication evidence

Dev-first scoped commit/push, exact Main-candidate verification and final merge references
will be recorded below. Main merge is explicitly authorized; no manual-review checkbox is
inferred complete. Production deployment has not been requested.

## Exact Main candidate verification

Scoped runtime candidate 1c8db8562fdf3623fc663dce606b2d15bdd04135 is based on Main9028f68f;
its diff contains exactly the 13 owned files. No other Dev implementation was included.
Isolated validation on the Dev server uses a private copied vendor/autoloader and synthetic
SQLite :memory: test environment, not a symlink that could load shared Dev application code.
PHP8.3: 100 passed / 1 explicitly skipped / 1673 assertions (69.85s), zero failures/errors/warnings.
The pre-existing optional protected UserSecurity-provider check is skipped because that
unpublished component is absent from Main; it passes in the 101-test authoritative Dev matrix.
An initial candidate run exposed missing test-only .env warnings; the isolated synthetic
fixture was supplied and the complete matrix rerun cleanly. No live credential was copied.
Candidate Pint and whitespace checks pass. The original shared staged index is unchanged.
Final documentation additions do not alter the tested runtime or tests.

Publication order: publish the scoped source history through an additive merge into remote Dev
before proposing the same source branch to Main. This preserves unrelated dirty working-copy
and staged changes and excludes them from both publications. No Main-first exception is used.
