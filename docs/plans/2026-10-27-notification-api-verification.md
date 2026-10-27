# Notification Full API Verification

Date: 2026-10-27. Owner: Codex. Approver/release owner: Svein Tore.
Status: Done On Dev / Merged to Main in PR #336. Production unchanged.
Approved instruction: "Godkjenner og merge til main".

## Verified Behavior And Coverage

All 14 Notification and five CustomerPortal browser route rows, bell actions,
preferences, own/admin devices, channels/tests, portal and Workday integration are
mapped. Thirty Notification HTTP operations / 39 schemas have exact runtime/Swagger
contracts and successful real-bearer response/persistence checks. New Workday detail
reuses its own authority; its complete consumer contract covers 37 work-plan/Workday
operations. Existing reminder/snooze/preference routes retain their scope and semantics.

Shared browser/API actions enforce current identity, native rights and token ability,
ownership and portal membership. Preferences commit atomically. The bell reports the
actual unread total. Source acknowledgement, notification reads and operational work
state remain separate. Unsafe/unauthorized targets fall back to an authorized surface.
Device/credential inputs are write-only. Scoped command claims/results are durable,
encrypted and actor/token bound; unknown sends cannot be blindly repeated.

## Automated Evidence

- Dev affected suite: 172 passed / 6615 assertions.
- Latest Dev permission/portal/Workday boundary suite: 47 / 4719.
- Latest token/provider safety: 6 / 39.
- Full Workday consumer contract: 5 / 347 on Dev and isolated Main.
- Isolated Main affected suite: 174 passed / 6629 assertions.
  The distinct five Workday contract cases make 179 affected cases overall.
- Suites overlap where indicated in JSON; do not sum overlapping assertion counts.
- No failure or skip deferred. Scoped syntax/Pint pass; 34 PHP files.
- Generated Swagger is restricted to Notification paths/schemas plus the one new
  Workday detail path for promotion; unrelated API contracts are preserved.

Exact commands and scoped file manifest are available in the Main promotion record.
Tests use the existing enforced SQLite :memory: isolation, no real provider sends.
Final scope includes negative/current-token revocation, native permissions, foreign
users/devices/portal membership, Workday generic-scope denial, transactional validation,
stale-state/idempotency, default-off/throttled tests, unknown provider outcome and
secret canaries. Every Notification success method is schema checked.

## Native Runtime

Only additive migration 2026_10_27_170000_create_notification_api_commands.php ran on
Dev. Native MySQL schema, unique replay guard, two-connection lock contention,
encrypted result/exact read-back and rollback with unchanged count pass. The synthetic
probe creates no durable business record. Seven trusted HTTPS read endpoints return
200, anonymous returns 401 and remote Swagger equals the local scoped contract.
Temporary token is revoked. NOTIFICATION_API_EXTERNAL_TESTS_ENABLED remains false.
No preference change, employee/customer send, VAPID, global channel, provider, scheduler
or worker activation occurred.

## Handbook And Source Read-Back

Five exact internal source articles: Notification API/Channels, Workday Reminders,
Customer Portal Overview and API Management. Actual BookStack pages 516/383 are exact
source read-backs; 514/449 preserve current existing content and contain the exact new
sections. Page 449 had a concurrent unrelated source update; the Notification addition
is still exact. Existing page/chapter/book inheritance is retained, not expanded.
Workday's complete guide is embedded and exact-read-back in Notification API 516:
the connector rejects create/search strings through a pattern validator, while updates
and exact-name lists work. This avoids leaving the relevant workflow undocumented.
Native pending_push records are not external publication evidence; the verified
personal BookStack publication is that evidence. Employee OpenWebUI retrieval is untested.
Existing automatic-sync/source-drift follow-up remains separate; no sync worker enabled.

## Human Review And Deployment Gates

Human-review checklist HR-2026-10-27-NOTIFICATION-API remains In Review, with original
manual checks unchecked. Svein Tore explicitly authorized Main after RFC approval;
this does not invent completed device/browser/receipt scenarios. These manual checks
continue to block production deployment/release. Existing related Workday/Web Push/
Email review gates retain their own state.

Future deployment: apply only the exact additive migration, publish the scoped Swagger,
clear normal caches and restart affected workers only within an authorized rollout.
Keep external API tests off until explicitly configured for a controlled test.
No production deployment is included. Preserve APP_KEY/receipts on rollback; disable
new entry points rather than dropping live command history. Reconcile uncertain sends.
#205 Talk redesign, #258 VAPID wizard and #262 shared runtime remain separate work.

## Verified Main Promotion

PR #336: https://github.com/SveinT83/Nexum-PSA/pull/336
Merge: f9efc4eee84e16984e4b6c44433ff9749dd8c2d2
Tested/merged tree: 34d850b305f2efaba06d9155d33ecc21603538f3
Fresh GitHub read-back and origin/main fetch confirm merged state and exact tree equality.
Shared Dev HEAD and staged index remain unchanged. Human-review checklist remains
In Review; production rollout and activation are not authorized by this merge.
