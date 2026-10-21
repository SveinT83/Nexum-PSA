# Mail API Main And Production Rollout - 2026-10-21

Status: Main merged; first production attempt safely rolled back; production Swagger fix verified before retry.
Operator: Codex, explicitly requested by Svein Tore on 2026-10-21.
Human review: HR-2026-10-20-MAIL-API Reviewed by Svein Tore on 2026-10-21.
Delivery source: /var/Projects/tdPSA; preserve the shared Dev branch and ordinary index.

## Exact Scope And Preservation

Base Main: 85650aebfe85597cf03d9377196fa1b41b7a4eab.
A separate Git index selects only Mail changes, Mail documentation and the email.* scope catalog.
Shared DomainApiAuthorization, RequireDomainApiPermission, DomainApiAudit and the additive
EnforceTechRoutePermission::allowsPermission helper are required Mail dependencies.
No Ticket/Task APIs or scopes, unfinished Vault files, migrations, package or JavaScript source
changes are selected. Existing production Workday and optional-Telescope corrections are preserved.

The API exposes 175 supported Mail HTTP operations: 66 reads and 109 writes/actions.
Ordinary Delete requests provider Trash. Existing token grants and runtime switches are unchanged.
The Main/production request supersedes the prior handoff's statement that promotion was unauthorized.

## Verification And Preparation

- Authoritative source and Main were refreshed through strict host-key-verified Dev SSH.
- Immutable release validation runs on the Dev host with isolated SQLite :memory:, array cache,
  synthetic configuration, its own autoloader and Vite assets; no real credentials are copied.
- Initial exported candidate exposed a missing Vite build and omitted shared API dependencies.
  These were release-assembly/setup defects. Vite was built, all four required shared files were
  selected. Final full Mail suite: 815 passed / 9,567 assertions; eight optional isolated MariaDB
  contracts skipped. No assertion failure remains. Related Integration/delegation package: 64 passed /
  734 assertions (overlaps Mail coverage). Vite build and final diff/Swagger checks pass.
- Published OpenAPI generation confirms all 175 Mail operations, including 109 writes/actions.
- All 75 selected PHP files passed syntax, including the four shared dependencies.
- Production preflight verified all 96 code/documentation/Swagger preimages equal the expected Main
  baseline (new files absent as expected); no production-code collision was found.
- Production PHP is 8.3.35; migration status has no Pending entries.
- Normal-TLS production /up, /login, /api/documentation and the Swagger JSON returned 200.
  The existing generated production Swagger has only 46 Mail operations and needs regeneration.
- The every-minute external schedule:run cron exists. Worker health has not been certified here.

## GitHub And Human Review Gates

Main ruleset 15836572 ordinarily requires one approving PR review. Its existing policy explicitly
allows repository administrators and Integration 1144995; the authenticated SveinT83 account has
admin rights. The user's explicit Main/production request and Reviewed Mail scope authorize a
normal PR merge through that existing policy. Do not change rulesets, force-update branches or
request an extra administrative bypass. Production waits for the actual merged Main SHA.

The delivered Mail API review is Reviewed. The following relevant entries are not Reviewed and
remain independent; their runtime activation is not part of this deployment:
HR-2026-09-03-002 (selected read), HR-2026-09-01-001 (account setup),
HR-2026-08-16-008 (private live), -009 (shared drafts), -010 (rules), -011 (compose/send),
-012 (advanced acknowledgement), -014 (correlation triage), -015 (not-Ticket/merge) and
-016 (Ticket compose/Sent). HR-2026-08-30-001 (private storage) and the older account-save/missing-mail
browser checks also retain their own history. Existing deployed baseline behavior is not re-approved
by this rollout. Default-off collaboration/live/advanced acknowledgement must remain off.

## Deployment Procedure After Main Merge

1. Read back the exact merged Main SHA and regenerate an immutable release manifest from it.
2. Recheck every production preimage, permissions, runtime flags and existing deployment fixes.
3. Take protected code/environment and consistent database backups outside the webroot.
4. Use maintenance mode and graceful worker restart; wait for reserved jobs to finish before file
   replacement. Do not retry, purge or drain unrelated jobs.
5. Deploy only the verified Main delta with existing owner/group and readable file/directory modes.
   This Mail change adds no migration, package, frontend source build or runtime activation.
6. Regenerate Swagger on production; clear/rebuild normal Laravel config/view caches with umask 0002,
   clear any stale route cache and restart relevant long-running workers.
7. Lift maintenance; independently compare deployed code hashes and all Mail method/path contracts.
   Verify trusted HTTPS health/login, Swagger and authenticated Mail authorization/read-only behavior.
8. Update this report, TODO, source Knowledge, BookStack and the unpublished website handoff with
   actual production evidence. A successful deployment does not prove real provider writes,
   recipient inbox delivery or employee OpenWebUI retrieval.

## Rollback

Restore the protected pre-release code files and remove only files introduced by this exact release,
then rebuild caches and restart workers. Retain database/audit/provider state. Do not run migration
rollback or restore a database merely to undo this code-only Mail change. No real IMAP/SMTP write
or provider/runtime activation is part of release preparation.

## Current State

Main PR #300 merged normally to a91331f71dd328b2d0db190b1dc5a46213ab3bc8.
Its tree exactly matched the fully tested candidate. The first production attempt was rolled back
automatically when l5-swagger:generate tried to reflect AssetIndexFilterTest without the development-only
Tests\TestCase parent. Code/environment were restored, maintenance was lifted, and trusted HTTPS /up
returned 200. No migration, token grant or Mail runtime activation occurred.

The correction excludes module Tests directories before OpenAPI reflection. Its child-process
regression poisons the development-only parent: it fails before the fix and passes afterward
(1 test / 2 assertions). The complete API/Swagger/production-scan regression package passes
23 tests / 1,487 assertions. No development dependency is installed into production.

Protected first-attempt backup:
 /var/www/vhosts/tronderdata.no/private/mail-api-release-20261021-a91331f/
- code-before.tar.gz: 578607 bytes; SHA-256 eb49521c9213f77e1c812f6fe2b8728e1efd8716924d5e87477d30a551efbfe7
- database-before.sql.gz: 191124405 bytes; SHA-256 edf2b61cd0212fff624b66fd227a87aaeb508ef1a78bd3a2d13b5e0626041011
The consistent full database dump and gzip integrity passed; the temporary database credential file
was removed. A retry may reuse this verified protected database snapshot because the code-only
attempt made no schema/data transition; take a fresh code/environment snapshot for the retry.
No restore rehearsal or real provider write is claimed.
Protected production preflight evidence:
 /var/www/vhosts/tronderdata.no/private/mail-api-preflight-20261021-final.json
The release tree and logs remain outside the repository under /tmp/nexum-mail-main-20261021.
