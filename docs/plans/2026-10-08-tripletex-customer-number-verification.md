# Tripletex customer-number verification - 2026-10-08

Status: Done On Dev; customer scope Reviewed by Svein Tore; scoped Git merge authorized.
Owner: Codex. Product approval: Svein Tore in the current chat.
Authoritative checkout: /var/Projects/tdPSA, branch Dev.
No commit, push, Main promotion or production deployment was performed.
Related completed bugs #239 and #297 remain completed; their local allocator behavior is preserved.

## GUI write authority revision - 2026-10-08

Approved by Svein: enabling customer sync in the GUI must permit customer writes without .env edits.
Removed the extra customer-write flag from configuration, settings validation, the Blade form,
creation orchestration and provider transport. The transport still re-reads the saved active
connection/customer setting before POST. Global integration availability, account locks, company
verification, number checks and existing permissions remain. Time-write configuration is unchanged.

The old customer-write flag is ignored even if false in existing environment/cached configuration.
GUI off stops new provider customer writes and preserves existing numbers/links. The server's
existing global Tripletex stop still applies to the integration as a whole.

Verification: the new GUI enable/create/pause regression first failed on the old server
warning. The final focused run passed **10 tests / 88 assertions** (302.98 seconds), covering this end-to-end with the legacy flag false, time writes off,
provider/local read-back and a stale transport instance after pause; it also covers global stop,
settings conflicts, permissions, number drift, stale forms and unknown outcomes.
Scoped Pint, PHP lint, strict UTF-8 and changed-line whitespace checks passed. The whole
repository suite was not rerun for this bounded change; external writes use synthetic HTTP.

Live read-back confirms enabled form controls and no obsolete warning. During this task, customer
sync was saved on through the application: audit event at 2026-10-08 16:22:29 Europe/Oslo. Both
customer/time settings are now on. The agent did not toggle them or create a provider customer;
the before/after connection fingerprint changed due to that separately observed application save.
Zero customer-link rows were observed at the latest read-back. This is not a live customer-write test.
Dev uses uncached config, so no config-cache invalidation was needed. Blade views were rebuilt.
No schema, queue, scheduler or frontend build changes. No commit/push/Main/production deployment.

Changed files in this revision: config/tripletex.php, TripletexClient, TripletexCustomerNumbers,
TripletexCustomerController, customer-sync Blade, TripletexCustomerNumberTest, customer-number
Knowledge, RFC, ADR, TODO, human-review entry and this report.

## Historical customer-sync Save feedback correction - 2026-10-08

The GUI authority revision above supersedes the extra server activation requirement in this section.

Svein reported the normal browser form showing a Laravel HttpException page at the runtime
guard. Live read-only inspection confirms Tripletex is enabled, customer writes/sync are off,
time sync is on, the provider environment is production, and the link table is empty.

Corrected on Dev:
- Customer settings explain server unavailability before submission and disable enabling.
- A saved enabled setting can still be paused when server runtime has been switched off.
- Runtime rejection, stale versions and existing-link drift return browser users to settings with
  visible validation messages. JSON clients retain 422 validation and 409 conflict responses.
- Permission checks, provider verification, locks and saved-setting integrity remain enforced.

Verification: the new browser-form regression first reproduced HTTP 422 instead of redirect.
Five focused cases then passed. The stale-form case initially compared an unrefreshed model
against a DB-loaded row; after correcting that test baseline to include DB defaults it passed
(1 test / 11 assertions). All six scoped cases now pass, including three new regressions and
existing enablement/time independence, access denial and provider-number drift coverage.
No whole-repository rerun was needed for this bounded settings correction.
Scoped Pint, PHP lint, strict UTF-8, whitespace checks and Blade compilation passed.
Live partial rendering confirms the notice and disabled switch/save button with current config;
trusted HTTPS returns the expected unauthenticated 302 to login. Authenticated HTML route tests
passed; a real browser visual retest by Svein is still pending.
The exact connection fingerprint and runtime flags are unchanged before/after the correction.

Changed in this follow-up: TripletexCustomerController, customer-sync Blade, customer-number tests
and Knowledge, plus this report, RFC, TODO and the same human-review entry.
No migration, new queue/scheduler or asset build. Dev view cache has been rebuilt with umask 0002.
No commit, push, Main/production change, provider call or provider customer write.
HR-2026-10-08-TRIPLETEX-CUSTOMERS is In Review; the reported UI defect needs human retest.
Runtime activation needs a separately authorized pilot because the saved Dev connection uses
a real Tripletex production account. Enabling the server write flag alone does not enable the
saved customer switch. Do not mark the wider human checklist Reviewed from this correction.

## Delivered behavior

- Independent customer-number synchronization setting beside time synchronization.
- Provider-backed, read-only, unreserved number suggestion when integration/customer sync are on.
- Both active and inactive customer/supplier records participate in number occupancy.
- New Client UI and API create use fresh provider allocation and exact customer GET read-back.
- Existing customer search/selection retains the provider ID and exact number.
- Explicit comparison/adoption links existing Clients; collisions and stale previews stop writes.
- Durable request keys, provider identity and outcomes prevent blind duplicate POSTs and allow recovery
  after remote success/local failure. Administrator review can resolve verified orphan attempts.
- Ordinary number edits are guarded across UI/API/imports, including preservation while paused.
- Local five-digit allocation is unchanged while customer sync is off.
- Enabling customer sync checks existing links for provider/company/number drift.
- Customer enablement does not start time transfer or make Workday claim time sync is running.
- Knowledge and Client-create OpenAPI source/published schema document the contract.

Full automatic name/address/contact synchronization, bulk historical matching and deletion
propagation remain future work, as requested during product discussion. Generic Data Exchange
batch transactions cannot create remote customers: preview/commit explain using Client UI/API first.

## Automated Dev evidence

All tests use isolated SQLite :memory: databases and synthetic HTTP responses; stray HTTP is denied.

| Verification run | Passing tests | Assertions |
| --- | ---: | ---: |
| Initial customer-number feature matrix | 21 | 84 |
| ClientTech, TripletexConnection, TripletexTimeSync, DataExchangeRuntime | 85 | 516 |
| Final customer boundary matrix: 9 additions and 4 targeted reruns | 13 | 38 |
| Final time-sync suite including customer-only Workday regression | 13 | 74 |

The four runs comprise **116 distinct passing tests** (132 executions including targeted reruns).
The complete repository suite was not run; verification is scoped to the changed integration,
Client/UI/API/import and time-sync boundaries.

The first customer test attempt failed because the fixture seeded demo Clients while asserting an
empty database. It was corrected to seed permissions only; the complete 21-case matrix then passed.
The final additional cases passed after the relevant recovery, company binding and paused-link edits.

PHP syntax, strict UTF-8 decoding, scoped Pint for new services/controllers/tests, tracked diff
whitespace checks, and Blade view compilation pass. New files/directories are readable by PHP-FPM.
Registered routes retain auth, Tech, local 2FA and permission middleware; admin mutations additionally
require admin/integration access, and link/recovery require Client view/update. Lookup is throttled.
The published OpenAPI JSON was updated only for the Client creation fields; unrelated schema was preserved.

## Real provider read-only check

Used the saved encrypted Nexum Dev connection and existing company-bound TLS-validating transport.
Company identity matched. Complete bounded reads returned 414 customers (1 inactive) and 157 suppliers.
No customer names, email addresses, raw responses, token values or customer data were retained here.
The connection row fingerprint was unchanged by the check.

Official contract inspected: https://tripletex.no/v2/openapi.json
Customer.customerNumber is an integer. GET /customer defaults isInactive=false, so separate active
and inactive scans are necessary. GET/POST /customer and GET /customer/{id} are documented.
No public next-number or reservation endpoint was found. The suggestion is computed from occupied
provider numbers and does not claim to reproduce an unexposed Tripletex numbering preference.

No live provider customer was created/updated/deleted, and no real Client number or link was changed.
Customer write runtime and customer sync remain false.

## Dev migration and application read-back

Applied only:
    php artisan migrate --path=database/migrations/2026_10_08_120000_create_tripletex_customer_links.php --force --no-interaction

Read-back: migration Ran in batch 18; customer-link row count 0.
Verified unique connection/request, connection/provider ID and Client binding indexes, plus
restrict-on-delete references to integrations and clients. The runtime setting is loaded.
No route/config cache was present. Blade templates were rebuilt with umask 0002.
Trusted HTTPS GET/HEAD to New Client returns 302 to login; this is reachability/auth-gate evidence.
Authenticated UI responses are covered by feature tests. The browser inventory tool timed out;
interactive visual review and real user interaction are not claimed.

## Human review and activation

Human-review checklist **HR-2026-10-08-TRIPLETEX-CUSTOMERS** in docs/human-review.md is **In Review**.
It blocks Main promotion and Nexum production migration/deployment. Svein explicitly approved
GUI-only write authorization on 2026-10-08: saving customer sync on authorizes customer writes
without .env editing or a separate server/pilot-activation step. This supersedes that extra gate.

Svein must still review New Client search/create, two stale forms, independent toggles, number
conflict adoption and unknown-outcome recovery. Automated synthetic tests do not prove real
provider write permissions or collision behavior. The Workday checklist stays open independently.

For an approved deployment: apply the scoped migration and refresh normal route/config/view/opcache
state. Use the customer GUI switch to activate/pause; the removed customer-only environment flag is
ignored. No new scheduler, queue worker or asset build is needed.
Rollback: pause customer sync and retain delivery evidence; migration down refuses a nonempty table.

## Changed files

- app/Http/Requests/Tech/Clients/ClientRequest.php
- app/Models/Clients/Client.php
- app/Modules/Clients/Actions/CreateClientRecord.php
- app/Modules/Clients/Actions/CreateClientWithDefaults.php
- app/Modules/Clients/Controllers/Api/V1/ClientController.php
- app/Modules/Clients/Controllers/Tech/ClientController.php
- app/Modules/Clients/Controllers/Tech/ClientSettingsController.php
- app/Modules/Clients/Docs/knowledge/client-domain-overview.md
- app/Modules/Clients/Support/ClientDataExchangeSource.php
- app/Modules/Clients/Views/Tech/Settings/edit.blade.php
- app/Modules/Clients/Views/Tech/create.blade.php
- app/Modules/DataExchange/Models/TripletexCustomerLink.php
- app/Modules/DataExchange/Services/SyncTripletexWorkdays.php
- app/Modules/DataExchange/Services/TripletexCustomerLinks.php
- app/Modules/DataExchange/Services/TripletexCustomerNumbers.php
- app/Modules/Integration/Controllers/Admin/TripletexController.php
- app/Modules/Integration/Controllers/Admin/TripletexCustomerController.php
- app/Modules/Integration/Docs/knowledge/tripletex-connection.md
- app/Modules/Integration/Docs/knowledge/tripletex-customer-numbers.md
- app/Modules/Integration/Services/Tripletex/TripletexClient.php
- app/Modules/Integration/Services/Tripletex/TripletexTimeMapping.php
- app/Modules/Integration/Tests/Feature/TripletexCustomerNumberTest.php
- app/Modules/Integration/Tests/Feature/TripletexTimeSyncTest.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customer-picker.blade.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customer-sync.blade.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customers.blade.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/index.blade.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/time-sync.blade.php
- app/Modules/Integration/routes.php
- config/tripletex.php
- database/migrations/2026_10_08_120000_create_tripletex_customer_links.php
- docs/TODO.md
- docs/adr/2026-10-08-tripletex-customer-number-authority.md
- docs/human-review.md
- docs/rfc/2026-10-08-tripletex-customer-numbers.md
- storage/api-docs/api-docs.json

## Follow-up state

Required remaining work is manual UI/provider acceptance under the named checklist, followed by
Svein's separate Main/deployment decision. Full profile synchronization is a future feature,
not active behavior of the customer-number switch. No unfinished code control is exposed.
Baseline backups and sanitized test logs are outside the repository under
/tmp/nexum-tripletex-customers-20261008; no scratch files were introduced into the repository.

## Human approval and merge - 2026-10-08

Svein Tore explicitly approved the delivered customer scope and requested issue closure and Git
merge. HR-2026-10-08-TRIPLETEX-CUSTOMERS and HR-2026-10-08-TRIPLETEX-PROFILES are Reviewed.
This supersedes their earlier pending-review gate statements; automated/live-check limitations
remain recorded above without inventing additional test execution. Production deployment is separate.
Merge evidence: 2026-10-08-tripletex-customer-merge.md in docs/plans.
