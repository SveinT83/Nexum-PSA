# Tripletex Billing Email and Site synchronization - verification 2026-10-08

Status: Done On Dev; customer scope Reviewed by Svein Tore; scoped Git merge authorized.
Approved by Svein Tore in chat: suggestions at creation, ongoing two-way sync, Tripletex authority,
and primary contacts excluded from synchronization after creation.
Authoritative checkout: /var/Projects/tdPSA, branch Dev. Changes are uncommitted.
No Main commit, push, promotion or Nexum production deployment was performed.

## Delivered behavior

- Selecting an existing Tripletex customer suggests Billing Email and editable Site address fields.
  Ordinary customer email/phone can suggest the primary contact. Only a unique actual matching
  Tripletex Contact supplies a name; no guessed CEO role. Invoice email never fills contact email.
- Requests from a previously selected customer are discarded. User-edited values are preserved.
  Create new clears only automatic details and cancels stale responses.
- New Client saves the reviewed Site and Contact locally. New provider customers receive the
  supplied Billing Email/address, never the primary-contact email, phone or name.
- Ongoing synchronization covers exactly invoiceEmail <-> Client.billing_email and one bound
  Site's address, address line 2, postal code, city and country. Business address is preferred;
  postal address is chosen only at initial binding when business address is empty.
- Site identity/address type stay fixed, including when the default Site changes. Missing or moved
  Sites require attention. An administrator can explicitly rebind after confirming that the
  selected Site starts from Tripletex values; unresolved write evidence blocks rebinding.
- Local-only edits export; provider-only edits import. Tripletex wins same-field conflicts,
  independent fields merge, and conflict audit stores field names rather than customer values.
- Customer number/company drift stops profile updates. Provider versions, partial allowlists,
  encrypted baselines/pending evidence and GET read-back handle conflicts and ambiguous PUTs.
  Local edits made during an HTTP call are preserved for the next pass.
- Primary-contact name, email, phone and role have no ongoing sync path. Other Sites, Site names,
  unmapped provider addresses, customer names, notes and deletions are outside this extension.
- The existing saved customer GUI setting controls prefill, writes, scheduler and manual retry.
  No extra .env customer-write flag. Pausing preserves all links/baselines/pending evidence.
- Admin link review shows status, last check, safe attention codes, retry and explicit Site binding.
  Paused/busy retries give form feedback instead of claiming a fresh success.
- Postal codes are text, preserving leading zeroes and foreign formats.

## Automated verification on Dev

All Laravel tests use isolated SQLite databases and synthetic HTTP with stray requests prohibited.

| Test set | Distinct passing tests |
| --- | ---: |
| TripletexCustomerProfileTest | 25 |
| ClientTechTest | 38 |
| Focused TripletexCustomerNumberTest creation/GUI/API/recovery regressions | 7 |
| TripletexTimeSyncTest and TripletexConnectionTest | 41 |
| Total | 111 |

The final profile run passed 24 cases; the remaining postcode test's brittle HTML attribute-order
assertion was corrected and its isolated rerun passed. All 25 profile cases are verified; no
known failing scoped test remains. Earlier fixture-only contact object comparisons and the
durable-create test transaction wrapper were also corrected without weakening production guards.

Three JavaScript checks exercised the actual customer-picker script with deferred fetch responses:
stale selection rejection, preservation of edited inputs, and Create new invalidation/reset.
Scoped Pint, PHP lint, strict UTF-8 and changed-file whitespace checks pass. Blade cache rebuilt.
The whole repository test suite and interactive browser acceptance were not run.

## Live verification

Using the existing encrypted Dev connection, read-only GETs confirmed company identity and real
profile/address/country/contact response contracts. The saved connection fingerprint was unchanged.
The provider environment is production, while this code and schema were changed only on Nexum Dev.
No provider test customer was created, updated or deleted during the verification.

The installation had one linked customer. First inbound reconciliation ran under an HTTP guard
that prohibited all provider customer mutations. It returned synced, with all six managed fields
matching a subsequent independent provider GET. Hashes of contacts, contact_emails, contact_phones
and client_users were unchanged. There were zero customer-write attempts and no pending evidence.

Initial checked_at: 2026-10-08 15:20:26 UTC.
The existing OS cron runs schedule:run every minute with flock and umask 0002.
The registered five-minute customer command actually ran at 15:25:09 UTC and completed in 2 seconds
according to scheduler-runtime.log. Independent DB read-back at 15:25:12 UTC showed:
checked_at 15:25:11 UTC, status synced, error none, pending no.
This verifies real scheduler execution, not just schedule:list registration.

The bounded scanner handles at most 20 links per run, oldest checks first, with a soft 210-second
loop budget and the existing account lease. Five minutes is the scan cadence; larger accounts
take successive batches. Ongoing normal writes follow the already enabled GUI setting.

New runtime PHP files are 0644, projectusers-owned group, readable by PHP-FPM. Trusted HTTPS
New Client smoke returned 302 to login. Authenticated rendering/permissions are covered by feature
tests; no real-user browser screenshot or interactive acceptance is claimed.

## Schema and deployment

Applied on Dev only, verified Ran in batch 19:
    php artisan migrate --path=database/migrations/2026_10_08_180000_create_tripletex_customer_profiles.php --force --no-interaction

Requires the existing tripletex_customer_links migration. The additive profile table uniquely
references a link, retains a stable Site ID and encrypted baseline/pending data. Site ID intentionally
does not cascade on deletion; loss of the bound Site remains visible instead of auto-rebinding.
Nonempty evidence prevents automatic down migration.

For later approved Main/Nexum production deployment:
1. Complete the human checklists and normal release review.
2. Apply the scoped profile migration after the existing customer-link migration.
3. Refresh normal PHP/opcache and Blade views with project-compatible file permissions.
4. Verify an external schedule:run runner every minute and observed profile checked_at progress.
No new queue worker or frontend build. Customer sync remains controlled by the existing GUI switch.
Rollback: pause customer sync and preserve link/profile delivery evidence; do not drop pending rows.

Official provider contract inspected: https://tripletex.no/v2/openapi.json
Customer PUT is a partial object update with version; nested addresses include identity/version.
Live GETs and mocked PUTs do not prove real provider update entitlement or every account-specific
nested-address behavior. A real intended-customer update/read-back remains part of human review.

## Original human-review request (superseded by approval below)

HR-2026-10-08-TRIPLETEX-PROFILES is a manual-review checklist entry, Pending, owned for review by
Svein Tore. Project AGENTS.md requires this review before Main/production migration/deployment.
HR-2026-10-08-TRIPLETEX-CUSTOMERS remains In Review independently.
Automated evidence does not mark either checklist Reviewed.

Please verify on an intended customer:
- Select/create a Client and review address, Billing Email and initial primary-contact suggestions.
- Change Billing Email and Site address in each system and read back the other system.
- Confirm that primary-contact email/phone/name remain unchanged after subsequent synchronizations.
- Exercise same-field conflict versus independent edits, pause/resume and visible outage recovery.
- Review stable Site binding, missing/moved Site and company/number drift feedback.
- Confirm a real provider PUT/read-back and the visible scheduled update behavior.

The complete persistent checklist is in docs/human-review.md. No additional implementation approval
or customer-write environment switch is needed. Real provider PUT/UI acceptance remains unverified.

## Documentation and public handoff

Knowledge, Client domain guidance, RFC, ADR, TODO and human-review state are updated.
The existing public-safe website handoff item was updated; dated item count remains 27.
It stays not approved for publication pending manual review and production verification.
No website publishing, GitHub posting, commit or push was performed.

## Changed files

- app/Modules/Integration/Services/Tripletex/TripletexClient.php
- app/Modules/Integration/Controllers/Admin/TripletexCustomerController.php
- app/Modules/Integration/routes.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customer-picker.blade.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customer-sync.blade.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customers.blade.php
- app/Modules/Clients/Views/Tech/create.blade.php
- app/Modules/Clients/Actions/CreateClientWithDefaults.php
- app/Modules/DataExchange/Services/TripletexCustomerNumbers.php
- app/Http/Requests/Tech/Clients/ClientRequest.php
- app/Http/Requests/Tech/Clients/SiteRequest.php
- routes/console.php
- docs/TODO.md
- docs/human-review.md
- app/Modules/Integration/Docs/knowledge/tripletex-customer-numbers.md
- app/Modules/Clients/Docs/knowledge/client-domain-overview.md
- app/Modules/Integration/Tests/Feature/TripletexCustomerNumberTest.php
- app/Modules/Clients/Actions/CreateClientRecord.php
- app/Modules/Clients/Views/Tech/Sites/form.blade.php
- app/Modules/Integration/README.md
- app/Console/Commands/SyncTripletexCustomers.php
- app/Modules/DataExchange/Models/TripletexCustomerProfile.php
- app/Modules/DataExchange/Services/SyncTripletexCustomerProfiles.php
- app/Modules/Integration/Services/Tripletex/CustomerProfileData.php
- app/Modules/Integration/Tests/Feature/TripletexCustomerProfileTest.php
- database/migrations/2026_10_08_180000_create_tripletex_customer_profiles.php
- docs/rfc/2026-10-08-tripletex-customer-profiles.md
- docs/adr/2026-10-08-tripletex-customer-profile-baselines.md
- docs/rfc/2026-10-08-tripletex-customer-numbers.md
- docs/adr/2026-10-08-tripletex-customer-number-authority.md
- docs/plans/2026-10-08-tripletex-customer-profile-verification.md

## Human approval and merge - 2026-10-08

Svein Tore explicitly approved the delivered customer scope and requested issue closure and Git
merge. HR-2026-10-08-TRIPLETEX-CUSTOMERS and HR-2026-10-08-TRIPLETEX-PROFILES are Reviewed.
This supersedes their earlier pending-review gate statements; automated/live-check limitations
remain recorded above without inventing additional test execution. Production deployment is separate.
Merge evidence: 2026-10-08-tripletex-customer-merge.md in docs/plans.
