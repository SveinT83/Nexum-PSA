# Existing Tripletex customer registration repair - 2026-11-18

Status: Approved, installed in production and verified on 2026-11-18. Earlier observations below are chronological.
Owner: Codex. Reporter, reviewer and release owner: Svein Tore.
Human-review checklist: HR-2026-11-18-TRIPLETEX-EXISTING-CUSTOMER (approved for release by Svein Tore).
Approval basis: existing approved customer-number RFC (2026-10-08) and full Clients API
parity RFC (2026-10-25). This repairs their explicit-selection workflow; no new matching,
permission, provider-write authority or architecture decision is introduced.

## Finding and resulting behavior

A duplicate preflight leaves a durable prepared attempt whose payload includes the selected
Tripletex customer ID. The browser previously retained that attempt UUID after the duplicate
error. Selecting the existing customer changed its payload, so the immutable-hash check
prevented registration. The API's existing provider lookup also ignored the search query and
listed inactive records even though inactive selection is rejected.

Production was inspected read-only over the approved strict-host-key SSH route. The picker
and explicit-ID creation path are already present. Its duplicate error is the old generic
client_number-only error. This is a reproduced workflow defect. At initial implementation handoff the reporter had not
supplied a particular customer/actor; the later customer-specific read-only check is recorded below.

A typed no-POST duplicate exception now supplies errors.tripletex_customer_id as well as
the legacy client_number error. Only that exception lets the browser prepare a fresh UUID,
preserve reviewed inputs and highlight the existing-customer picker. The old prepared record
remains intact. Generic validation, provider rejection, timeout, read-back failure and unknown
outcomes do not trigger key renewal. Enter in the search control runs lookup instead of submit.

The official API filters active choices by optional q (name, customer number, organization
number) before pagination. Explicit tripletex_customer_id selection still verifies provider
identity/number, local uniqueness, company/environment, permissions and sync state.
After a definitive no-write duplicate rejection, API callers choose the identity and submit
corrected input with fresh command and request keys. Unknown outcomes require reconciliation.
A successful identical keyed replay returns the same Client and creates no duplicate.

## Authoritative Dev state and scope

Working copy: /var/Projects/tdPSA, branch Dev, HEAD/origin/Dev f2e0d176 at initial inspection.
origin/main d093f34c. Fetch completed before changes. Shared working copy was already dirty.
No commit, push, Main promotion or production deployment was performed. Existing staged and
unrelated working-copy changes are preserved.

Runtime files:
- app/Modules/DataExchange/Exceptions/TripletexCustomerSelectionRequired.php (new)
- app/Modules/DataExchange/Services/TripletexCustomerNumbers.php
- app/Modules/Clients/Controllers/Tech/ClientController.php
- app/Modules/Clients/Controllers/Api/V1/ClientProviderController.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/customer-picker.blade.php
- app/Modules/Clients/Resources/Api/V1/ClientApiOpenApi.php

Tests:
- app/Modules/Integration/Tests/Feature/TripletexCustomerNumberTest.php (five new cases)
- app/Modules/Clients/Tests/Feature/ClientsApiContractTest.php (new contract assertions)

Documentation:
- app/Modules/Integration/Docs/knowledge/tripletex-customer-numbers.md
- app/Modules/Clients/Docs/knowledge/client-domain-overview.md
- app/Modules/Clients/Docs/knowledge/clients-api.md
- docs/TODO.md
- docs/human-review.md
- this verification record
- existing customer-number and Clients API RFC maintenance notes (parent approval retained)
- storage/api-docs/api-docs.json (generated through the existing Swagger contract tests)

No migration, provider configuration, permission grant, queue, scheduler, dependency or frontend
build changes are required. New exception directory/file modes are 2755/0644 and every parent
is readable/traversable by the web process. Temporary interaction harness was outside the repo
and removed after execution; verification logs remain under /tmp as named below.

## Verification

All provider writes use HTTP fakes, with stray requests prohibited. Database is isolated
SQLite :memory:, protected by the existing TestCase connection guard. No real customer,
Contact, provider record, invoice or email was created or sent.

- Four end-to-end recovery/search/unknown-outcome regressions: 4 tests / 65 assertions PASS.
- Existing recovery, explicit selection, local collision, immutable keys, safe provider-error
  replay and new API scope/current-permission/pause regression: 7 tests / 43 assertions PASS.
- Clients route/OpenAPI/typed-schema/creation contract: 3 tests / 432 assertions PASS.
- Total: 14 distinct relevant Laravel tests / 540 assertions PASS.
- Scoped Pint --test: all 7 affected PHP files PASS.
- Node execution of the actual picker script with a synthetic DOM: Enter searches, explicit
  selection fills identity, reviewed Contact data is preserved, Create new clears selection PASS.
- Targeted git diff --check PASS. Trusted Dev HTTPS /login HTTP200 (11019 bytes).
- Baseline duplicate browser/API cases fail with the old generic error, before the repair.
  The search defect was also established directly in the original controller source.

This is not a full repository-suite claim or a real browser/provider acceptance claim.
An accidental Pint --dirty --test invocation scanned the shared dirty checkout: 961 files,
22 style issues in unrelated Fortify/Calendar/Task/Ticket/Vault/bootstrap/route work. It was
read-only and changed no file; the explicit seven-file check above passes. Those unrelated
style findings remain their existing owners' work and are not reported as a clean global check.

Logs on Dev:
- /tmp/nexum-existing-customer-before-20261118.log (recorded old duplicate-error failures)
- /tmp/nexum-existing-customer-after-20261118.log (4 / 65)
- /tmp/nexum-existing-customer-regression-20261118.log (7 / 43)
- /tmp/nexum-existing-customer-contract-20261118.log (3 / 432)
- /tmp/nexum-existing-customer-pint-20261118.log
- /tmp/nexum-existing-customer-js-20261118.log

## Handbook publication and access

Authoritative Markdown sources were updated first. Existing BookStack content outside the new
bounded sections was preserved using full-body read/compare before writing. Independent read-back
verified exact expected content:
- Tripletex Customer Numbers, page617 revision4, book339/chapter374, owner1 unchanged.
- Client Domain Overview, page451 revision22, book339/chapter450, owner1 unchanged.

The new sections explicitly identify Dev-only recovery/q filtering and the existing production
picker/API behavior. Page/chapter inheritance and book339's existing documentation-role grants
are inspected without permission writes. Intended employee OpenWebUI retrieval is separate and
unverified. No wholesale Knowledge database import or general two-way synchronization was run.
No credential, owner, hierarchy or access scope was changed.

## Human review and release procedure

HR-2026-11-18-TRIPLETEX-EXISTING-CUSTOMER is a human-review checklist entry, Pending.
It blocks Main promotion and production release of this repair. Existing named approvals for
the parent feature and private-customer repair are retained; this new defect repair is not
marked Reviewed by an AI.

Svein should verify the browser and intended API workflow with approved test data and report
the result. No real production customer registration is performed as a probe. If a specific
reported production attempt is supplied, reconcile it read-only before an operational retry.
Existing Issue #392 operator/private-customer acceptance is separate and remains unchanged.

After the required review and explicit publication/deployment instruction, use scoped Dev
history first, then Main. Generate Swagger, refresh Blade and PHP-FPM opcode state using the
normal deployment process. No migration, queue/scheduler activation or frontend build is needed.
Preserve existing customer links, attempt/command evidence and unrelated production overlays.
Rollback restores scoped source/OpenAPI preimages and refreshes Blade/opcache; it never deletes
attempts, commands or Clients created after deployment.

Public-safe handoff: updated one existing Tripletex customer-number item (zero new items), with
this repair marked Dev verified / human review and production rollout pending. Exact UTF-8
read-back and unchanged item count verified. No public publication is authorized.

External gateway catalogue/consumer cache refresh was not performed; no claim is made that an
assistant currently exposes the provider-lookup operation. The official API is tested directly.

## Reporter-specific read-only follow-up - 2026-11-18

The reporter subsequently supplied the intended customer's identifying data. Strict-host-key
production SSH and the existing company-verified HTTPS Tripletex client confirmed one active
provider match with matching name/email/mobile, no exact-name/billing-email local Client,
no matching canonical Contact and no provider-identity link in Nexum. Personal data and the
provider customer identity are retained only in the private task evidence artifact outside
the repository; no public editorial handoff contains this customer-specific information.

The provider's postal address differs from the newly supplied address; its physical address
is blank. Do not silently choose or update an address during an operational registration.
Current creation prefill falls back to the provider postal address, while reviewed local form
edits are preserved. The profile baseline stores the provider address, so later normal
synchronization can export reviewed local differences. No profile synchronization was run.

One prepared production attempt exists today with no provider ID, number or local Client.
The durable row stores hashes rather than customer-identifying input, and no matching Telescope
request record is available. That row cannot safely be attributed to this customer or a named
Nexum user from the available evidence. It was not changed, retried or deleted.

All application database queries ran in READ ONLY transactions and rolled back. Provider calls
were customer GETs plus the existing short-lived authentication session exchange; no customer
POST/PUT, link/adopt action, registration, address update, command replay or production source
change occurred. The Dev repair and Pending human-review/deployment state are unchanged.

## Approved release and operational read-back

Svein Tore approved Main and production. Feature commit 5f8e5650 was first included on Dev at
02cbdfd2. Main was fast-forwarded directly; GitHub reported a pull-request rule bypass.
Five isolated regression tests passed with 70 assertions before the production overlay.
Six scoped application files were installed on VM169. All six production SHA-256 hashes matched
the verified postimages. The existing PRIVATE behavior was preserved. Maintenance mode was off,
PHP-FPM was active, login returned HTTP 200 and the regenerated live OpenAPI lists the q filter.
No migration, provider activation or queue change. Rollback copies are in /root/nexum-pair-1811/before.
An authorized real registration used the production API and existing provider ID. Independent
read-back verified one local record with the existing provider number, a linked identity and the
requested local Site address. No additional live command was issued to simulate a duplicate.
Handbook pages 451, 617 and 639 were updated and independently read back at revisions 23, 5 and 4.
Browser operator and employee-assistant checks remain separate from these production checks.
