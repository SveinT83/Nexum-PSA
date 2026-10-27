# UserManagement API Main Promotion

Status: Merged / Independently Verified.
Date: 2026-10-27. Owner: Codex. Authorization: Svein Tore.

PR: https://github.com/SveinT83/Nexum-PSA/pull/353
Merge: 65ffcb5e0491f5782b6c8d2873a3894fc1680cc4
Tested head: 0c15ac7ba1efa1070333c936be6850c47e41dbbe
Tree: 02df918f8479d4d5f711a24b6c6907b4d8ae1bc2

A fresh origin/main fetch proves the whole merged tree equals the tested candidate,
and all 65 owned files match their immutable tested hashes. Shared Dev remains on Dev;
unrelated working-copy changes and protected security/Vault adapters are preserved.
The subsequent status update changes documentation only.

## Verified behavior and scope

All 49 browser route rows and embedded role-permission actions map to 56 typed HTTP
operations with 77 dedicated schemas and retained Calendar/Work Plan types. Supported
reads, writes and actions include profile/avatar/preferences, user lifecycle/invitation,
authorization administration, MFA policy, existing session-bound password/factors/SSO,
and durable ordinary-command read-back. Native rights, scopes, session/local assurance,
first-party enrollment policy, encrypted replay, ETags and audit remain authoritative.

Dev broad regression: 236 / 3120; final MFA/session: 79 / 990.
Main affected: 185 passed / 2616 assertions, one existing optional protected-provider skip.
Latest Marketing integration: 90 passed / 2496, same one optional skip.
Final shared Relationship/UserManagement privacy: 24 / 916, no skips.
All suites have zero failures/errors. The separate optional provider is not installed
or activated by this work. All 56 successful HTTP operations are schema-checked on both
runtimes. Native MySQL receipt/encryption/rollback/mutex and trusted HTTPS are verified.
Pint, PHP syntax and whitespace checks pass; final bootstrap formatting has identical
executable PHP tokens. Source/contract/permission/test links are in the coverage matrix.

## Documentation, human review and production

Authoritative Knowledge sources and actual BookStack 517/384/449 are read back with
unchanged employee access. Main availability is recorded; production availability is
not claimed. Native pending_push is not remote publication, and assistant retrieval
is not tested. The existing connector-search/source-drift follow-up stays separate.

HR-2026-10-27-USERMANAGEMENT-API is Pending for Svein Tore's manual scenarios before
production. HR-2026-10-08-SSO-MFA and original HR-2026-10-05-SSO retain their existing
unchecked items. Explicit Main authorization does not mark human review complete.
No production deployment, provider activation, role grant or real invitation was made.

A separately authorized production deploy requires only:

```sh
php artisan migrate --path=database/migrations/2026_10_27_030000_create_user_management_api_commands.php --force
php artisan l5-swagger:generate
php artisan optimize:clear
```

The exact migration ran on Dev in batch 42. No unrelated migration/backfill, frontend
build, queue/scheduler replacement or auth cutover is required by this change. Preserve
APP_KEY and live receipts; reconcile unknown mail/provider outcomes before any retry.
Source file changes and immutable tested hashes are in the verification JSON. No new
runtime tests are required for the subsequent documentation-only status reconciliation.

Final BookStack read-back: guide 517 revision 6, profile 384 revision 7, API index 449 revision 162. All explicitly distinguish verified Main from undeployed production. One public-safe website handoff item is read back with status klar for vurdering and an explicit do-not-publish gate. No public publication was authorized or performed.
