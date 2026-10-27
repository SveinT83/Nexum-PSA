# Feature Slices: Full System Browser/API Parity

Status: Done On Dev / Merged To Main / Human Review Pending
Date: 2026-10-27
Parent: ../rfc/2026-10-27-system-full-api-parity.md
Owner: Codex. Product owner and intended reviewer: Svein Tore.

Complete sequentially under Svein Tore approval "Godkjent", 2026-10-27.
Every operation requires positive/negative tests and exact successful read-back.

## SY-S1: Authority, Discovery And Version Status
Status: Done On Dev
Goal: SY01-SY03, SY05, SY08 and SY12 read/context contracts with current authority.
User-visible behavior: authorized JSON discovery, complete settings context, cached
version states and truthful driver-aware queue metadata; preserve browser behavior.
Scope: native/token policy, resources, read filters, authorized navigation, fixed
GitHub cache/client, safe production route loading and existing permission fallback.
Out of scope: writes, OS health implementation, other domains' management APIs.
Data touched: existing settings/queue metadata reads and version cache only.
Permissions: matrix scopes/native permissions; current active actor and token.
Tests: success/denial/revocation, safe projection, stale/outage/cache, missing tables,
non-database drivers and no false worker health or GitHub call on local hub load.
Documentation: initial Swagger schemas, guide and coverage matrix evidence.
Done criteria: verified paths/schema/read-back, no native-right expansion; baseline
SSO/login rework assigned to owning boundary and resolved before final completion.

## SY-S2: Complete Settings And Branding Commands
Status: Done On Dev
Goal: SY04, SY06-SY07, SY09-SY11 and SY18-SY19 receipts.
User-visible behavior: every company/branding/logo/preset/reset/preview operation
through JSON or real multipart, with exact settings and public image read-back.
Scope: shared validation/actions, aggregate locks, versions, additive safe command
storage, staged files/cleanup, strict input, side-effect-free preview and replay.
Out of scope: personal theme/avatar and new branding capabilities.
Data touched: common_settings, company-branding public files and new receipt data;
exact additive migration finalized after approval; no business backfill.
Permissions: settings.read/write plus system.settings_manage; receipt source scope/
native permission plus operations.read, bound to actor/token. No extra system.view.
Tests: every field/preset/default, three upload/remove slots and fallback bytes,
real multipart, invalid input, file/DB failure, first/existing aggregate races,
stale state, revoked replay and receipt isolation. Regress downstream consumers.
Documentation: company/branding Knowledge source and API guide/matrix.
Done criteria: successful supported settings operations, no lost profile/logo,
no duplicate effects and native concurrency evidence, not only SQLite tests.

## SY-S3: Complete Guarded Queue Maintenance
Status: Done On Dev
Goal: SY13-SY17 and exact queue-command receipts.
User-visible behavior: explicit preview/confirmation, typed restart/clear/retry-one/
retry-all/flush outcomes, with API execution disabled by default after installation.
Scope: fixed command allowlist, scopes/native rights, backend/cohort binding,
confirmation expiry, shared claims, verified driver support and exit-code handling.
Out of scope: live queue maintenance, process/scheduler changes and #262.
Data touched: new attempts/claims; fakes or isolated synthetic jobs in tests only.
Permissions: per-action scope, system.queue_manage, bound confirmation and switch.
Tests: four actions and both retry modes, unsupported driver/exit failure, changed
cohorts, expired/tampered/revoked confirmation, post-dispatch crash/unresolved/no-repeat,
cross-action and concurrent web/API claims; no raw payload/errors in results/audit.
Documentation: queues/workers Knowledge, risks and reconciliation instructions.
Done criteria: positive supported outcomes on verified adapters, not denial-only
coverage. Do not declare 100% on unverified supported drivers. No live backlog change.

## SY-S4: Complete Contracts, Regressions And Verified Handbook
Status: Done On Dev
Goal: demonstrate all 13 browser rows and 19 proposed HTTP operations end to end.
User-visible behavior: accurate full Swagger and actionable employee/admin guidance.
Scope: exact runtime/Swagger/schema matrix, catalog checks, successful HTTPS/multipart/
logo requests, native concurrency and affected downstream regression coverage.
Out of scope: Main/production promotion, runtime activation and real job processing.
Data touched: docs/Swagger/Knowledge and supported BookStack publish/read-back.
Permissions: preserve existing handbook audience/access; no grants or widening.
Tests: full System including resolution of both baseline failures; affected
UserManagement, Commercial/Booking/CustomerPortal/PWA/Notification and Integration
boundaries; precise syntax/Pint/whitespace. Run broader tests only when justified.
Documentation: actual BookStack content/access, RFC/ADR/slice/TODO/review evidence,
public-safe website handoff marked Dev-only/do not publish until availability verified.
Done criteria: no missing coverage/evidence; relevant verification green or explicit
approved deferral; publication verified or concrete blocker/owner/action recorded.
HR-2026-10-27-SYSTEM-API stays Pending until named human confirms checks. Dev,
Main and production remain separate. No automatic promotion or test-induced activation.


## Verification And Remaining Gates

SY-S1 through SY-S4 are Done On Dev. Final affected suite: 121 tests / 1529 assertions;
19/19 typed HTTP operations, 44 schemas, native two-writer MySQL, encrypted/unique
receipt/rollback, trusted HTTPS, real multipart decoder and 24-file syntax/Pint pass.
All live maintenance switches stay off. Exact migration ran in Dev batch 43.
Actual handbook pages 366/367/438/546 were read back with original employee access;
standalone API guide is included as a complete section in 366. Native Dev repository
records remain pending_push because no BookStack integration exists; owner Svein Tore/
Integration must reconcile mappings before configuring native push. This does not
invalidate independent actual BookStack publication/read-back. No tests were silently
deferred. Manual HR-2026-10-27-SYSTEM-API stays Pending; Main/production are separate.

## Verified Main Promotion

Main promotion verified on 2026-10-28: PR #361,
https://github.com/SveinT83/Nexum-PSA/pull/361, merge
893ab45e00c65472c6691b9917a726b5fccee285. Merge tree
406e1c13437a38b208deee442c09a6e82b17231b exactly matches the tested candidate;
19 System operations / 44 schemas are present. The additional upstream integration
run passes 44 tests / 1420 assertions; earlier 169-test evidence is a separate run.
Production is not deployed, its migration has not run, API maintenance stays default-off.
HR-2026-10-27-SYSTEM-API remains Pending; all unchecked manual scenarios gate
production deployment/release. Native Knowledge mapping remains owned by Svein Tore /
Integration; existing BookStack publication remains independently verified.
