# Commercial Browser/API Gap Matrix

Status: Merged to Main via PR #313; Reviewed by Svein Tore. Date: 2026-10-25.
Source: authoritative Dev `/var/Projects/tdPSA`, branch `Dev`, HEAD `36918edbcaae1ce7b1208ba34f84a3a1719f0e98`.
Parent: [RFC](../rfc/2026-10-25-commercial-full-api-parity.md). Machine-readable evidence: [inventory](2026-10-25-commercial-api-inventory.json).

## Coverage Rule

100% means every supported browser workflow, field, lookup, validation, authorization, persisted result and relevant side effect has an official typed API, meaningful tests, exact read-back and verified documentation. Route presence is insufficient.

Baseline: 73 browser entries, 18 API entries / 22 HTTP operations excluding HEAD, six Livewire components. Entries include forms, aliases, hubs and a dead SLA delete declaration. These totals are not a coverage percentage. Existing endpoints remain partial until field/behavior/authorization evidence passes.

## Implementation and verification

All C01-C18 have typed domain API counterparts. D01 was resolved by removing the unused dead SLA
delete route; no SLA deletion was invented. Unit delete is supported by the actual browser update
action and is implemented. Route/schema contract contains 103 methods, including the two separately
controlled worklog exports. New web controller routes must appear in the frozen inventory test.

Shared actions cover catalogue persistence/composition, Contract metadata/lines/source defaults,
legal review/capture, exact customer economics/PDF, manual/legacy approval, customer acceptance,
timebank policy/consumption and durable reviewed delivery. Local-only form state maps to pure context/
preview inputs, not no-op mutation APIs. See the endpoint manifest and generated schema artifacts.

Automated: 129 Commercial/portal tests / 1898 assertions; 113 broader affected tests / 1104 assertions.
BookStack exact source/section and inherited staff scope read back for eight pages.
The earlier database outage is resolved. Exact migration batch 23, native locking/uniqueness,
seven authenticated trusted HTTP reads and eight internal Knowledge source matches pass. Both delivery
rollout switches remain off. HR-2026-10-25-COMMERCIAL-API is Reviewed: Svein Tore accepted the completed delivery
on 2026-10-25. Production/provider verification remains separate.

The following original Current API evidence column describes the pre-change baseline, retained for
traceability; it is superseded by the implementation manifest and verification report.

## Workflow Matrix

| ID | Workflow | Current API evidence | Full target |
| --- | --- | --- | --- |
| C01 | Contract list/filter/sort, context and metadata | Partial: list/detail/create/update only | Full fields and typed context, client-only scope, draft-only delete and receipt. |
| C02 | Contract line editor and pricing preview | Missing | Add/update/remove, service/offer selection, quantity/defaults, exact economics/currency/customer wording, SLA/rate snapshots and explicit draft cadence adoption. |
| C03 | Contract terms review and versions | Missing | Discover sources/versions/readiness; explicit refresh/save with exact fingerprints, manual wording and stale-review denial. |
| C04 | Send quote/agreement and resend | Missing | Frozen reviewable recipient/CC/content/provider, explicit commands, deduplication, truthful delivery evidence and recovery. |
| C05 | Manual approval and legacy attestation | Missing | Current named actor, original evidence, fresh preview fingerprint, explicit type/note/confirmation, immutable snapshot and receipt. |
| C06 | Staff PDF/customer-document output | Partial: JSON projection exists; PDF API absent | Existing exact calculator/projector/snapshot/PDF, pure readiness and honest unavailable evidence. |
| C07 | Public view and accept | Missing | Exact current bearer capability, safe projection, explicit viewed event, bounded accept and receipt; no staff impersonation. |
| C08 | Portal list/detail/accept | Missing | Current portal session/CSRF/account/membership/contact/client/site, safe document, legal/audit/notification effects and receipt. |
| C09 | Service catalogue/composition | Partial: basic read/create/update | Full fields/context/filter/sort/delete, cost/legal/rate links, add-on/audience defaults and calculated previews; active Integration locks. |
| C10 | Packages/composition | Missing | Read/context/create/update/delete, services/terms/inherited terms, per-unit and aggregate pricing/margin previews. |
| C11 | Cost catalogue | Missing | Full fields/context/filter/sort/read/create/update/delete; vendor/unit/recurrence and active source/in-use guards. |
| C12 | Legal terms and versions | Missing | Read/context/create/update/delete, version creation, kind/source/reference, provider and in-use guards. |
| C13 | SLA and default selection | Partial: read/create/update exist | All fields/context/default invariants and Ticket inheritance regressions. Declared delete is D01. |
| C14 | Time rates | Partial: read/create/update exist | All fields/context/enums, customer visibility/application/active/sort semantics; no invented deletion. |
| C15 | Unit administration | Missing | Read/create/update/delete under the actual administrative policy and economy.manage_settings; supported browser deletion and database references retained. |
| C16 | Timebank policy and settings hubs | Missing | Actual quick policy read/update under Admin plus contract_manage; canonical capability discovery for hubs and redirect aliases. |
| C17 | Client timebank options/balances/registration | Missing staff operations; coordinator export is separate | Eligible lines/rates/period balance, quick consumption policy/note/limit/overuse, exact snapshots and safe retries; reuse supported owner time-correction APIs. |
| C18 | Controlled worklog and contract-link export | Existing two coordinator reads; preserve distinct boundary | Workload/pseudonym/context/source limits, totals/truncation and separate direct consumption versus billing allocations; no operational access widening. |
| D01 | Declared SLA delete without method | Defect: SlaController::destroy absent | No matching control found in SLA views. Verify callers, remove unused dead route with regression; no invented cascade/default/history rules. |

## Every Registered Browser Route

All 73 entries map below. Forms and aliases map to context/canonical operations rather than duplicate or no-op APIs. D01 is explicit and cannot be counted as implemented.

| Workflow | Methods | Browser URI | Route name |
| --- | --- | --- | --- |
| C07 | POST | `/contract/accept/{token}` | `contracts.public.accept` |
| C07 | GET / HEAD | `/contract/view/{token}` | `contracts.public.view` |
| C08 | GET / HEAD | `/portal/contracts` | `customer-portal.contracts.index` |
| C08 | GET / HEAD | `/portal/contracts/{contract}` | `customer-portal.contracts.show` |
| C08 | POST | `/portal/contracts/{contract}/accept` | `customer-portal.contracts.accept` |
| C16 | GET / HEAD | `/tech/admin/settings/cs/timebank-policy` | `tech.admin.settings.cs.timebank-policy` |
| C16 | PUT | `/tech/admin/settings/cs/timebank-policy` | `tech.admin.settings.cs.timebank-policy.update` |
| C14 | GET / HEAD | `/tech/admin/settings/economy/rates` | `tech.admin.settings.economy.rates` |
| C14 | POST | `/tech/admin/settings/economy/rates` | `tech.admin.settings.economy.rates.store` |
| C14 | PUT | `/tech/admin/settings/economy/rates/{rate}` | `tech.admin.settings.economy.rates.update` |
| C15 | GET / HEAD | `/tech/admin/settings/economy/units` | `tech.admin.settings.economy.units` |
| C15 | POST | `/tech/admin/settings/economy/units` | `tech.admin.settings.economy.units.store` |
| C15 | POST | `/tech/admin/settings/economy/units/update/{unit}` | `tech.admin.settings.economy.units.update` |
| C17 | POST | `/tech/clients/{client}/contracts/timebank-consumptions` | `tech.clients.contracts.timebank-consumptions.store` |
| C01 | GET / HEAD | `/tech/contracts` | `tech.contracts.index` |
| C01 | GET / HEAD | `/tech/contracts/create` | `tech.contracts.create` |
| C01 | DELETE | `/tech/contracts/delete/{contract}` | `tech.contracts.destroy` |
| C01 | GET / HEAD | `/tech/contracts/edit/{contract}` | `tech.contracts.edit` |
| C01 | GET / HEAD | `/tech/contracts/show/{contract}` | `tech.contracts.show` |
| C01 | POST | `/tech/contracts/store` | `tech.contracts.store` |
| C01 | PUT | `/tech/contracts/update/{contract}` | `tech.contracts.update` |
| C05 | POST | `/tech/contracts/{contract}/approve-manual` | `tech.contracts.approve-manual` |
| C05 | POST | `/tech/contracts/{contract}/customer-document/attest-legacy` | `tech.contracts.customer-document.attest-legacy` |
| C06 | GET / HEAD | `/tech/contracts/{contract}/pdf` | `tech.contracts.pdf` |
| C04 | POST | `/tech/contracts/{contract}/resend` | `tech.contracts.resend` |
| C04 | POST | `/tech/contracts/{contract}/send-contract` | `tech.contracts.send-contract` |
| C04 | POST | `/tech/contracts/{contract}/send-quote` | `tech.contracts.send-quote` |
| C02 | GET / HEAD | `/tech/contracts/{contract}/services` | `tech.contracts.services.edit` |
| C02 | POST | `/tech/contracts/{contract}/services` | `tech.contracts.services.update` |
| C03 | GET / HEAD | `/tech/contracts/{contract}/terms` | `tech.contracts.terms` |
| C03 | POST | `/tech/contracts/{contract}/terms` | `tech.contracts.terms.update` |
| C03 | POST | `/tech/contracts/{contract}/terms/refresh` | `tech.contracts.terms.refresh` |
| C11 | GET / HEAD | `/tech/costs` | `tech.costs.index` |
| C11 | GET / HEAD | `/tech/costs/create` | `tech.costs.create` |
| C11 | DELETE | `/tech/costs/delete/{cost}` | `tech.costs.delete` |
| C11 | GET / HEAD | `/tech/costs/edit/{cost}` | `tech.costs.edit` |
| C11 | POST | `/tech/costs/store` | `tech.costs.store` |
| C11 | POST | `/tech/costs/update/{cost}` | `tech.costs.update` |
| C11 | GET / HEAD | `/tech/costs/{cost}` | `tech.costs.show` |
| C12 | GET / HEAD | `/tech/legal` | `tech.legal.index` |
| C12 | GET / HEAD | `/tech/legal/create` | `tech.legal.create` |
| C12 | DELETE | `/tech/legal/delete/{term}` | `tech.legal.delete` |
| C12 | GET / HEAD | `/tech/legal/edit/{term}` | `tech.legal.edit` |
| C12 | GET / HEAD | `/tech/legal/show/{term}` | `tech.legal.show` |
| C12 | POST | `/tech/legal/store` | `tech.legal.store` |
| C12 | PUT | `/tech/legal/update/{term}` | `tech.legal.update` |
| C10 | GET / HEAD | `/tech/package/create` | `tech.packages.create` |
| C10 | DELETE | `/tech/package/delete/{package}` | `tech.packages.delete` |
| C10 | GET / HEAD | `/tech/package/edit/{package}` | `tech.packages.edit` |
| C10 | GET / HEAD | `/tech/package/show/{package}` | `tech.packages.show` |
| C10 | POST | `/tech/package/store` | `tech.packages.store` |
| C10 | PUT | `/tech/package/update/{package}` | `tech.packages.update` |
| C10 | GET / HEAD | `/tech/packages` | `tech.packages.index` |
| C14 | GET / HEAD | `/tech/rates` | `tech.rates.index` |
| C14 | POST | `/tech/rates` | `tech.rates.store` |
| C14 | PUT | `/tech/rates/{rate}` | `tech.rates.update` |
| C09 | GET / HEAD | `/tech/services` | `tech.services.index` |
| C09 | POST | `/tech/services` | `tech.services.store` |
| C09 | GET / HEAD | `/tech/services/create` | `tech.services.create` |
| C09 | DELETE | `/tech/services/destroy/{service}` | `tech.services.destroy` |
| C09 | GET / HEAD | `/tech/services/edit/{service}` | `tech.services.edit` |
| C09 | POST | `/tech/services/update/{service}` | `tech.services.update` |
| C09 | GET / HEAD | `/tech/services/{service}` | `tech.services.show` |
| C13 | GET / HEAD | `/tech/sla` | `tech.sla.index` |
| C13 | GET / HEAD | `/tech/sla/create` | `tech.sla.create` |
| D01 | DELETE | `/tech/sla/delete/{sla}` | `tech.sla.delete` |
| C13 | GET / HEAD | `/tech/sla/edit/{sla}` | `tech.sla.edit` |
| C13 | GET / HEAD | `/tech/sla/show/{sla}` | `tech.sla.show` |
| C13 | POST | `/tech/sla/store` | `tech.sla.store` |
| C13 | PUT | `/tech/sla/update/{sla}` | `tech.sla.update` |
| C16 | GET / HEAD / POST / PUT / PATCH / DELETE / OPTIONS | `/tech/admin/settings/cs/contacts` | `tech.admin.settings.cs.contracts.legacy` |
| C16 | GET / HEAD | `/tech/admin/settings/cs/contracts` | `tech.admin.settings.cs.contracts` |
| C16 | GET / HEAD | `/tech/admin/settings/cs/services` | `tech.admin.settings.cs.services` |

## Livewire And Embedded Workflows

ContractItemsEditor includes add/save/remove, Service/CloudFactory offer selection, quantity/defaults, SLA/rate snapshots, exact totals and explicit Service cadence adoption. Other components cover Package services/terms/inheritance/per-unit pricing and Service legal/cost selection/recommended-price/commission previews. Local UI state maps to input/context; persistence and calculations must be usable without a Livewire session. mount/render/computed helpers do not each need endpoints.

| Component | Workflow | Public methods for traceability |
| --- | --- | --- |
| `app/Modules/Commercial/Livewire/Tech/Contracts/ContractItemsEditor.php` | C02 | `mount`, `loadItems`, `addItem`, `updatedItems`, `syncBillingIntervalFromService`, `calculateLineTotals`, `calculateBillingTotals`, `saveItem`, `removeItem`, `calculateTotalCost`, `calculateTotalDiscount`, `calculateAnnualProfit`, `render` |
| `app/Modules/Commercial/Livewire/Tech/PackageLegal.php` | C10 | `mount`, `updateServices`, `allTerms`, `selectedTerms`, `termsFromServices`, `toggleTerm`, `removeTerm`, `render` |
| `app/Modules/Commercial/Livewire/Tech/PackagePricing.php` | C10 | `mount`, `updateServices`, `selectedServices`, `pricingByUnit`, `pricingRows`, `totalCostPrice`, `totalServiceSalesPrice`, `currentSalesPrice`, `marginAmount`, `marginPercentage`, `render` |
| `app/Modules/Commercial/Livewire/Tech/ServiceLegal.php` | C09 | `mount`, `allTerms`, `selectedTerms`, `providerTerms`, `selectedNexumTerms`, `toggleTerm`, `removeTerm`, `render` |
| `app/Modules/Commercial/Livewire/Tech/ServicePicker.php` | C10 | `mount`, `allServices`, `selectedServices`, `toggleService`, `removeService`, `render` |
| `app/Modules/Commercial/Livewire/Tech/ServicePricing.php` | C09 | `mount`, `checkRecommendedPrice`, `getTotalCostProperty`, `getCommissionProperty`, `updatedSelected`, `remove`, `render` |

## Current API Baseline

| Methods | URI | Name |
| --- | --- | --- |
| GET / HEAD | `/api/v1/commercial/contracts` | `api.v1.commercial.contracts.index` |
| POST | `/api/v1/commercial/contracts` | `api.v1.commercial.contracts.store` |
| GET / HEAD | `/api/v1/commercial/contracts/{contract}` | `api.v1.commercial.contracts.show` |
| PUT / PATCH | `/api/v1/commercial/contracts/{contract}` | `api.v1.commercial.contracts.update` |
| GET / HEAD | `/api/v1/commercial/services` | `api.v1.commercial.services.index` |
| POST | `/api/v1/commercial/services` | `api.v1.commercial.services.store` |
| GET / HEAD | `/api/v1/commercial/services/{service}` | `api.v1.commercial.services.show` |
| PUT / PATCH | `/api/v1/commercial/services/{service}` | `api.v1.commercial.services.update` |
| GET / HEAD | `/api/v1/commercial/slas` | `api.v1.commercial.slas.index` |
| POST | `/api/v1/commercial/slas` | `api.v1.commercial.slas.store` |
| GET / HEAD | `/api/v1/commercial/slas/{sla}` | `api.v1.commercial.slas.show` |
| PUT / PATCH | `/api/v1/commercial/slas/{sla}` | `api.v1.commercial.slas.update` |
| GET / HEAD | `/api/v1/commercial/time-rates` | `api.v1.commercial.time-rates.index` |
| POST | `/api/v1/commercial/time-rates` | `api.v1.commercial.time-rates.store` |
| GET / HEAD | `/api/v1/commercial/time-rates/{rate}` | `api.v1.commercial.time-rates.show` |
| PUT / PATCH | `/api/v1/commercial/time-rates/{rate}` | `api.v1.commercial.time-rates.update` |
| GET / HEAD | `/api/v1/commercial/worklog/contract-links` | `api.v1.commercial.worklog.contract-links.index` |
| GET / HEAD | `/api/v1/commercial/worklog/time-consumptions` | `api.v1.commercial.worklog.time-consumptions.index` |

## Verified Gaps And Boundaries

- Web guards: commercial.contract_manage (also Legal), service_manage/package_manage/cost_manage/sla_manage/rate_manage; Admin plus economy.manage_settings for Units; Admin plus contract_manage for timebank policy. Consumption/overuse retain explicit quick-consume/overconsume rights.
- Existing staff API middleware declares Sanctum commercial.read/create/update, without the browser tech route-permission middleware. Actor/object/field equivalence must be implemented/tested, not assumed from a valid token.
- Service API omits cost/term/rate composition. Contract API covers metadata only. Several OpenAPI declarations have generic responses and only PATCH annotations despite registered PUT as well.
- Public Contract GET writes viewed metadata today. Proposed pure JSON reads plus an explicit viewed command preserve browser behavior without hidden API mutations.
- Send changes business state and may skip email without billing_email; SMTP submission is synchronous after that state transition. New evidence must distinguish business status, no recipient/not submitted, pending/processing, SMTP accepted, failure and unknown. No inbox-delivery inference.
- SLA delete points to missing destroy; no matching control was found in SLA views. Admin rate index/store/update redirect and do not mutate rates.
- HR-2026-08-24-003 is Reviewed in the authoritative review register despite older TODO overview wording; keep its accepted boundary. Historical readiness notes are not current production facts. Worklog HR-2026-09-27-WORKLOG is separately Pending; preserve its policy and activation gates.
- The original audit was planning-only. Implementation, automated tests and handbook publication are now recorded above. Native schema/activation and real provider/customer actions have not been performed.

## Evidence Required After Design Approval

Each workflow needs exact API methods/request/response/error schemas, actor allow/deny cases, field/null/omitted behavior, shared operation, persisted result, concurrency/retry/side effects and test evidence. D01 needs an explicit disposition/regression. Add checks detecting unmapped new web workflows. Generate Swagger and independently read trusted Dev HTTPS; verify Knowledge/BookStack content and employee scope. Main, production, real messages and employee assistant retrieval remain separate claims.
