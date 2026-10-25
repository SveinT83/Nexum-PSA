# Economy Full API Coverage - Verification And Handoff

Status: Merged to Main; production not deployed. Human-review checklist HR-2026-10-25-ECONOMY-API: Reviewed by Svein Tore, 2026-10-25.
Date: 2026-10-25. Implementation: Codex. Reviewer/release owner: Svein Tore.
Authoritative implementation: /var/Projects/tdPSA, branch Dev. Original Dev verification preceded commits/Main promotion. Main promotion is verified in PR #315; production is not deployed.
Design approved by Svein Tore in chat: "Godkjent. Kjør på".

## Delivered behavior and scope

All E01-E14 current Economy browser workflows have typed official APIs: 23 HTTP operations and
34 explicit schemas. This is supported operational parity, not a percentage inferred from route
counts and not an invoice/payment-provider engine. Initial baseline was 14 Economy browser routes
and seven APIs; six legacy Economy URL aliases remain Commercial-owned Rates/Units.

Shared actions/queries cover list/detail/context/line paging, effective versus stored totals,
settings, all-source generation, exact ready/draft/manual-invoiced, publication/hiding/audit,
source-safe deletion and customer Portal projections. Data Exchange retains profile/run/file
ownership: canonical Economy export has preview/key/replay; custom Economy profiles keep their
supported owner workflow with the same current Economy/owner rights. No provider delivery is enabled.

Current actor rights AND token scope apply. New settings/manual-invoiced/publication require keys
and revisions; high-impact publication/export uses frozen previews. Existing mutations preserve
header-omission compatibility while enforcing supplied headers. Malformed/overflowing record IDs
return 404. Staff PATs do not establish Portal identity. Reads resolve defaults without writes.

One seeded native transaction mutex serializes Economy entry points. Durable source ownership
preserves the recorded financial client through Ticket moves and line correction/regeneration.
All four source types are tested; stock movements remain untouched by financial correction.
Generation reports actual created/updated/reused sources and avoids duplicate charges/empty drafts.
Yearly timebank allocation respects the contract anniversary across multiple billing months.
No-billing Ticket outcomes do not enter ordinary generation. Notification intents are durable,
after-commit, preference-aware and claimed once; unknown submissions never retry blindly.

## Files and dependency preservation

The exact 40 changed/new PHP paths and affected documentation are in economy-api-files.json.
They include Economy module routes/controllers/actions/queries/support/resources/tests, one additive
migration, the Integration ability catalog, shared API loader and the necessary Data Exchange API
owner guard. No views/assets, packages or frontend build changed.

Existing GenerateOrders financial-customer work was captured before edits and preserved. Shared
Integration/routes/docs dirty changes were extended narrowly. Nothing unrelated was reverted,
committed, pushed or broadly synchronized into the local Windows code tree.

## Automated verification

- Final Economy + Data Exchange suite: 60 tests / 640 assertions, all pass.
- API administration/least-privilege/catalog regressions: 5 tests / 313 assertions, all pass.
- Broad affected run: 384 tests / 4324 assertions, all pass. This includes Economy/DataExchange,
  all CustomerPortal and Commercial tests, TicketModuleTest, StorageModuleTest and
  CloudFactoryIntegrationTest. The final narrow compatibility/ID changes are covered by the
  final 60-test run; do not sum overlapping test sets as unique tests.
- Economy plus production-mode Swagger scan: 47 tests / 558 assertions, all pass.
- All 40 scoped PHP files pass syntax and Pint. Exact runtime method/path contract equals the
  source-controlled 23-operation contract; all 34 schemas exist in generated Swagger.
- Real bearer scope/revocation, active/current rights, strict fields, stale/replay, wrong parent,
  pure reads, settings false/zero/null, all source correction, annual/partial timebank, unknown
  notification, custom/canonical/failed export and Portal scope/private fields are covered.

Commands (umask 0002 was set before Artisan):

```sh
HOME=/tmp php artisan test app/Modules/Economy/Tests/Feature app/Modules/DataExchange/Tests/Feature
HOME=/tmp php artisan test app/Modules/Integration/Tests/Feature/IntegrationModuleTest.php --filter='admin_can_open_api_management|admin_can_create_scoped_api_key|api_key_creation_requires_explicit_scopes|ability_catalog_has_explicit_access_metadata|broad_existing_api_keys'
HOME=/tmp php artisan test app/Modules/Economy/Tests/Feature app/Modules/DataExchange/Tests/Feature app/Modules/CustomerPortal/Tests/Feature app/Modules/Commercial/Tests app/Modules/Ticket/Tests/Feature/TicketModuleTest.php app/Modules/Storage/Tests/Feature/StorageModuleTest.php app/Modules/Integration/Tests/Feature/CloudFactoryIntegrationTest.php
HOME=/tmp php artisan test app/Modules/Economy/Tests/Feature app/Modules/Integration/Tests/Feature/SwaggerProductionScanTest.php
```

Early new-test fixture/import issues and SQLite date-string assertions were corrected; final runs
have no failures or approved deferrals. Final JUnit summaries and scoped hashes are in
2026-10-25-economy-api-test-evidence.json. The complete repository suite was not run; the affected
cross-module set above was selected for this change.

## Native database and trusted HTTP

Exact additive migration 2026_10_25_160000_create_economy_command_records applied/read back as batch 24.
New tables: economy_write_mutex (2 columns, one seeded row/revision 0), economy_command_receipts
(7 columns, zero rows), economy_source_owners (5 columns, zero rows), economy_notification_intents
(8 columns, zero rows). Historical Economy/source counts stayed unchanged: no native orders,
lines, allocations or provider periods were created. The existing one settings row is unchanged.
The preflight schema snapshot is outside the repo in /tmp/nexum-economy-api-20261025-originals/.

Two independent native connections prove actual mutex contention with MySQL 1205. Scratch copies
of the real receipt/source-owner schemas reject duplicate keys with 1062; one synthetic row each,
then both scratch tables were dropped/read back absent. No native customer operation was used.

Five authenticated trusted HTTPS reads return 200 with data: orders, context, settings, generation
preview and export preview. Malformed order ID returns 404; anonymous orders returns 401. Swagger
/docs returns 200 with exactly the generated bytes. The temporary scoped token was revoked in
finally and the original token count restored. Source bodies and tokens were not printed.
All changed PHP is web-readable. No real customer billing, stock/provider mutation or send occurred.
Native empty data does not claim customer-specific production verification or actual inbox receipt.

Evidence: economy-api-native-preflight.json, economy-api-native-evidence.json,
economy-api-current-coverage.json and economy-api-test-evidence.json (all prefixed 2026-10-25-).

## Knowledge and BookStack

Scoped knowledge:sync-docs updated six native articles, internal/published and exact source.
Native API Management received only the Economy anchors, preserving unrelated pending differences.
Its source/full-body equality is deliberately not claimed. Six actual BookStack pages were updated
and independently read back: 494 revision 8, 390 revision 11, 395 revision 11, 514 revision 3,
515 revision 3 and scoped API Management 449 revision 129. Full stored content matches the expected
source or preserved-plus-scoped edit, including a paged complete read of page 449.

Book 339, chapters 373/374/512/513 and pages retain inherited scope. Current Editor role 2 maps to
/staff-internal and has book/chapter/page-view-all. No permission was expanded. Personal connector
publication was used after a transient gateway/search issue; no shared token or insecure TLS
fallback was used. Native pending_push metadata alone is not external publication evidence.
Employee OpenWebUI retrieval is a separate untested claim. See handbook/knowledge evidence JSON.

## Human review and release actions

HR-2026-10-25-ECONOMY-API is Reviewed by Svein Tore, 2026-10-25. He accepted the completed
Dev handoff and explicitly requested Main merge. The scenario checklist retains its history.
Production deployment and controlled external operations require separate authorization.
See 2026-10-25-economy-api-main-promotion.md for isolated candidate and merge evidence.

No Main merge, production deployment, source/provider activation or real-message acceptance was
performed. Existing workers were not blindly restarted and no scheduler was enabled. Worker/real
notification behavior remains part of controlled release verification, not an inbox-delivery claim.

After review and separate release authorization, in the intended environment:

```sh
umask 0002
php artisan migrate --path=database/migrations/2026_10_25_160000_create_economy_command_records.php --force --no-interaction
php artisan l5-swagger:generate
php artisan route:clear
php artisan queue:restart
```

Use the reviewed worker supervisor/service for a deliberate graceful refresh. No frontend build,
new cron/scheduler job or blanket Knowledge/BookStack push is required. Verify trusted HTTP and
exact schema/read-back after promotion; do not infer production state from Dev evidence.

Retain source-owner, command, financial and notification evidence on rollback. The migration
refuses destructive rollback once evidence exists. The global mutex deliberately favors beta
financial correctness over throughput; review larger-run latency before finer-grained locking.
Unknown notification submission needs investigation, not blind retry. Stored/effective totals and
mixed currencies stay explicit; no historical currency/VAT rewrite or conversion was introduced.

One public-safe editorial handoff is maintained in the agreed Content and Visibility queue, with
an explicit do-not-publish gate until human review, Main/release and production verification.

Fresh isolated Main candidate: 428 tests / 4904 assertions pass on 2026-10-25. See
2026-10-25-economy-api-main-promotion.md and main-candidate-tests.json for exact scoped evidence.

Main implementation merge: [PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315), 33eac1a3cba47ffe57f2253847dbaaf8f9b6d4e9; tested tree verified.
