# RFC: CloudFactory Partner Integration, Licensing And Token Authorization

Status: Approved
Date: 2026-07-16
Owner: Svein Tore / Codex
Change level: Level 3 external integration
Source verification date: 2026-07-20
Product direction: Approved by Svein Tore on 2026-07-16
Implementation approval: Approved by Svein Tore on 2026-07-20

## Executive Conclusion

CloudFactory does not support external API clients, a Nexum callback, Authorization Code with PKCE,
audiences, or scopes. Nexum must therefore connect through a dedicated CloudFactory Portal service
account. A Partner Admin creates that account and assigns the roles required for the enabled
capabilities. An administrator then completes CloudFactory's interactive Auth0 login and MFA flow
and transfers the issued refresh token once into a masked Nexum secret field. This is a
provider-mandated bootstrap step, not an ordinary recurring login flow.

Nexum exchanges the refresh token through the current
`POST /v1/users/authentication/exchange-refresh-token` JSON-body operation with
`isCustomer = false`, encrypts both tokens server-side, never displays them again, and renews access
tokens automatically. Nexum must never receive or store the Portal password, TOTP seed, MFA recovery
code, browser session, or one-time MFA code.

Therefore:

- a dedicated least-privilege Portal service account is the approved provider identity;
- a one-time masked refresh-token bootstrap is required because CloudFactory offers no external
  client authorization flow;
- storing the CloudFactory password, TOTP seed, or recovery code is not an accepted setup method;
- browser automation or password grant is not an accepted substitute for OAuth;
- read synchronization and the ordinary licence architecture can proceed through RFC approval;
- production connection uses the published bearer-authenticated `RevokeAllTokens` operation for
  the dedicated service account and never places a refresh token in a URL;
- notification webhooks use CloudFactory's documented `X-API-KEY` shared-header contract and
  deterministic retry deduplication, while scheduled polling remains mandatory.

A local proof-of-concept established that the current bearer token can call Partner Self and that the published legacy refresh exchange works on 2026-07-16. Those credentials are not an input to the Nexum integration and must not be copied into this repository.

The public capability review was repeated after CloudFactory published its consolidated Partner API
documentation. The provider now publishes a non-deprecated
`POST /v1/users/authentication/exchange-refresh-token` operation with the refresh token in a JSON
request body. This resolves the unsafe legacy refresh-path concern for normal renewal. The same
public contract confirms customer create/read/update/patch, catalogue cost and sale prices,
Microsoft tenant/subscription/renewal operations, a broad Adobe customer/product/order/subscription
API, invoice reads, notification webhooks, and activity logs.

CloudFactory also confirmed that all calls resolve to production, no sandbox exists, no hard general
rate limit is currently defined, catalogue `price.sale` is MSRP, and permissions are controlled by
Portal roles. Production writes must therefore be introduced through allowlisted, explicitly
approved rollout steps. Absence of a published hard rate limit is not permission to send unbounded
traffic; Nexum must self-limit, back off, and honor `Retry-After` when returned.

## Context

Trønder Data uses CloudFactory for CloudFactory customers, Microsoft CSP tenants and subscriptions, product catalogue data, renewal decisions, billing context, notifications, and partner activity. Nexum should become the operational interface for this work without becoming a credential vault for CloudFactory login credentials.

The first useful workflow is customer onboarding after a signed contract and a confirmed order. The integration must also support read-only reconciliation before write operations are enabled.

Nexum already has:

- an Integration module;
- an `integrations` table;
- encrypted secret helpers based on Laravel `Crypt` and the installation `APP_KEY`;
- queue, scheduler, audit, permission, Client, Commercial, Economy, Notification, and Knowledge foundations.

The CloudFactory integration should extend these existing patterns rather than introduce a parallel integration framework.

## Goals

- Let an authorized Nexum administrator connect one CloudFactory partner account using a dedicated
  Portal service account and the provider-supported token bootstrap.
- Complete the first refresh-token issuance at CloudFactory, including MFA, without Nexum receiving
  the password or MFA secret.
- Store provider tokens encrypted and never display them after receipt.
- Discover the authorized partner identity and CloudFactory roles immediately after connection.
- Synchronize CloudFactory customers, catalogue products, Microsoft tenants, subscriptions, renewal state, and billing context.
- Link CloudFactory customers explicitly to Nexum clients.
- Support audited customer and subscription onboarding after a contract and order are confirmed.
- Separate read permissions, integration administration, customer writes, subscription writes, and renewal writes.
- Make retries, polling, idempotency, token renewal, rate-limit handling, and failure recovery explicit.
- Use current CloudFactory v2 APIs where available.
- Keep every provider write traceable to a Nexum user, client, source record, confirmation, request fingerprint, and provider result.
- Synchronize client master data automatically in both directions with three-way field conflict
  detection and manual linking/conflict resolution only when automation cannot decide safely.
- Synchronize the full provider catalogue into a staging catalogue while exposing only actively
  licensed or explicitly sellable products as ordinary Nexum Services.
- Represent the actual manufacturer as Vendor, CloudFactory as integration source and procurement
  channel, and create one Nexum Service for each distinct CloudFactory commitment and billing
  variant.
- Support Microsoft, Adobe, and later CloudFactory provider families through capability adapters
  under one CloudFactory connection.
- Keep recurring licence charges synchronized with Commercial contracts and Economy billing drafts.

## Non-Goals

- Do not reuse a personal Portal user's refresh token or import a token supplied by Datanora or
  another unrelated workstation/session.
- Do not ask for an access token, password, MFA code, TOTP seed, or recovery code. Accept only the
  dedicated service account's refresh token in the one-time masked connect/replace flow.
- Do not automate CloudFactory Auth0 login with a headless browser.
- Do not use OAuth resource-owner password grant.
- Do not use `customer=true`; this integration authenticates as a partner account.
- Do not grant Partner Admin to the base operational service account. Notification and activity-log
  capabilities remain optional and require a separately approved privileged identity design.
- Do not use a weak or ambiguous match to merge a Nexum client with a CloudFactory customer. Safe
  deterministic matches are automatic; ambiguous matches require manual linking.
- Do not provision products before a contract and exact order are confirmed.
- Do not enable Microsoft user administration, password resets, Azure role assignment, or unrelated vendor APIs in the first implementation.
- Do not create Economy invoices automatically in the first implementation.
- Do not treat an HTTP 2xx response as proof that a Microsoft operation is complete.

## Approved Product Decisions

The following product rules were explicitly approved in conversation on 2026-07-16. They are the
target state and replace any older read-only or manual-link assumptions in this draft.

### Automatic Client Synchronization

- Client data synchronizes automatically in both directions.
- Exact stable identifiers, organization/VAT number, verified Microsoft tenant ID, and other
  deterministic identifiers drive automatic linking.
- Name-based matching may run automatically only when the normalized match is unique and has enough
  corroborating data to avoid linking two legal entities incorrectly.
- A CloudFactory customer without a Nexum match creates a Nexum Client automatically when required
  fields are available.
- A Nexum client without a CloudFactory match is created automatically in CloudFactory when an
  approved licence workflow requires it.
- Manual linking remains available when no safe automatic decision is possible.
- Each linked field keeps a common-ancestor snapshot. A change made on only one side synchronizes to
  the other side. If the same field changed on both sides since the last common snapshot, only that
  field enters conflict review while unrelated fields continue synchronizing.
- The terms and data-processing agreement are the customer-facing authorization basis for this
  automatic exchange. The system still records purpose, source, time, and outcome for every change.

### Vendor, Source And Service Catalogue

- `Vendor` means the manufacturer or product owner, for example Microsoft or Adobe.
- `Source` means the system/integration that supplied the product data, for example CloudFactory or
  Nexum.
- `Procurement channel` identifies where the licence is purchased; CloudFactory is the procurement
  channel for CloudFactory offers.
- Vendors are matched automatically by stable provider ID where available and otherwise by a unique
  normalized identity. Missing Vendors are created automatically; ambiguous matches go to manual
  review without blocking other sync work.
- The CloudFactory catalogue category ID is the stable external Vendor-mapping identity. Microsoft
  category families such as NCE, CSP, SPLA, Azure, software subscriptions, and perpetual licences all
  reuse the single canonical Microsoft Vendor.
- Named provider categories reuse a unique exact or normalized Vendor and otherwise create a canonical
  Nexum Vendor with a deterministic CloudFactory code.
- Generic categories such as IaaS and ambiguous local matches remain unresolved. Nexum does not guess
  from product display text when the category mapping requires review.
- An administrator can map or remap a category to an existing Nexum Vendor. The audited decision
  propagates to every offer in that category and to Services already linked to those offers.
- The complete CloudFactory catalogue synchronizes into an integration catalogue.
- A provider product becomes an ordinary Nexum Service when a client already owns an active licence
  or when an administrator enables `We sell this product`.
- Products not offered for sale remain searchable in the integration catalogue and do not clutter
  the normal Services list.
- Disabling `We sell this product` blocks only new sales. Existing licences, contract lines,
  renewals, reductions, cancellation and billing remain visible and manageable.
- Each distinct CloudFactory commitment and billing combination becomes its own Nexum Service.
  The generated Service SKU is the provider SKU plus deterministic commitment and billing suffixes,
  for example `CFQ7TTC0LH18:0001-C12-B1` and `CFQ7TTC0LH18:0001-C12-B12`.
- One CloudFactory offer links to one Service and one Service links to at most one CloudFactory
  offer. This prevents incompatible commitments from sharing cost, price, contract, or licence state.
- The exact CloudFactory offer remains stored on every contract and licence line. Existing licences
  never change Service variant or source without an approved migration.
- The Services list must support sortable/filterable Vendor and Source columns. CloudFactory appears
  as a source badge only when the integration is active and the service has a CloudFactory offer.

### Pricing

- The provider cost price synchronizes into the source offer on every price run.
- The CloudFactory `sale` value is the manufacturer's suggested retail price (MSRP). Store and label
  it as MSRP, separate from source cost and Nexum's calculated or manually overridden sale price.
- Default sale price is rule driven. Supported rules are:
  - follow CloudFactory MSRP directly;
  - CloudFactory MSRP plus a percentage or fixed markup;
  - cost price plus a percentage or fixed markup;
  - fixed manual sale price.
- Rule hierarchy is CloudFactory default, Vendor override, Service override, then contract-line
  override where permitted.
- Rule-based prices recalculate when source prices change. Manual prices are not overwritten.
- Price checks run monthly by default. Frequency, day, time, enabled state and manual `Check prices
  now` are Integration settings.
- If the source price is already NOK, Nexum uses it directly. Currency conversion and an optional
  currency-risk buffer are settings used only when source and contract currencies differ.
- Active licence contract lines are dynamic by default. New calculated prices apply from the next
  billing period, are never retroactive, and retain old price snapshots for audit and invoice
  history.

#### Cost normalization and Commercial Cost ownership

- CloudFactory catalogue prices remain stored as raw commitment-term totals on the source offer.
- Before a source price is used as a Nexum Service price or cost, it is normalized to the Service's
  supported billing interval. Monthly Services use one month of the commitment total and yearly
  Services use twelve months of the commitment total.
- Every active or sellable offer has one externally managed record in the Commercial Costs catalogue.
  CloudFactory owns its synchronized amount and metadata; administrators cannot manually edit or
  delete it.
- Each offer's managed Cost is linked only to that offer's dedicated Service. Alternative commitment
  and billing variants therefore cannot be summed into the same Service.
- Manually maintained Nexum Costs may still be linked to a variant Service and are added to its one
  provider Cost.
- The generated Service and Cost are ordinary Nexum Commercial records with a generic nullable source
  Integration relationship. Their normal Cost relation is the same relation used by manual records.
- CloudFactory marks both records as externally managed and owns synchronized pricing and identity
  fields only while its Integration is active.
- Active owned records are visibly marked, link to the active CloudFactory settings, and reject manual
  editing and deletion at the server boundary.
- Revoking, disabling, or deleting CloudFactory preserves the Service, Cost, their relation, contract
  snapshots, and accounting history. The retained records become editable Nexum data.
- A future provider such as Pax8 may take ownership through a controlled mapping workflow by replacing
  the generic source Integration relationship; historical source provenance remains auditable.
- The catalogue must show enough raw and normalized price context to make the selected billing and
  commitment variant auditable.

### Contracts, Quantity And Binding

- A licence create or quantity-increase request from Nexum requires an active/approved contract that
  permits licence purchases.
- A licence operation creates an append-only dated contract amendment/order record. It never silently
  rewrites the already accepted contract artifact.
- Contracts have separate settings for allowing new licence products, quantity increases, and
  quantity reductions during the contract period.
- Increases may execute immediately when the contract and provider allow them.
- Reductions execute immediately only when both the contract and provider allow them. Otherwise they
  are scheduled for the earliest provider-supported date, normally commitment end.
- Every licence line stores provider commitment start/end, billing term, cancellation/refund window,
  renewal deadline and chosen renewal action.
- Renewal policy is inherited from settings and may be overridden by contract or licence line:
  renew to a new term, cancel at commitment end, or move to Extended Service Term when supported.
- Reminder timing is settings driven, and no licence may remain without an explicit renewal policy.
- Existing CloudFactory licences without a matching contract line synchronize as active but
  `uncontracted`. Nexum creates a proposed contract amendment with quantity, price, binding and
  effective date. Billing starts only after the proposal is approved.

### Versioned Legal Documents And Portal Ordering

- A synchronized legal document is a logical Term with immutable versions. A changed provider title,
  issuer, version, content, effective date or source URL creates a new version and checksum.
- Provider versions are never overwritten or deleted when later synchronization changes or omits
  them. Omitted current documents are marked `not_returned` and remain available as evidence.
- The extractor accepts only explicit legal-document, agreement and terms fields. Short commercial
  values are ignored, and missing provider content is shown as Not supplied by provider.
- A live 2026-07-22 Dev catalogue run processed 10,898 offers and found no supported legal-document
  field in the current catalogue product contract, including the observed Microsoft 365 Business
  Premium payload. Nexum therefore records the check as `not_supplied` and does not synthesize terms.
  Customer-specific Microsoft MCA attestation and partner-level Terms of Service remain separate
  provider concepts and are not misrepresented as product documents.
- Provider documents attach to the CloudFactory offer and its exact one-to-one Service variant.
- Provider documents are read-only. Additional Nexum documents may be selected from the approved
  legal library without inline Service editing.
- Catalogue synchronization checks legal documents in the existing monthly catalogue run. Enabling
  an offer links stored documents immediately and queues a current catalogue check.
- Sending a contract captures the exact term-version rows for each Service line while retaining the
  existing combined text snapshots.
- Only a client-level Customer admin can use portal licence ordering. Eligible products must already
  exist as the exact CloudFactory Service/offer pair on a won, active contract.
- Every portal issue, quantity change and renewal-policy change requires explicit confirmation and
  stores append-only evidence: portal identity, contract/line, offer/Service/subscription, document
  IDs and checksums, product, quantity, price, commitment, time, IP address and user agent.
- Existing contract authorization covers unchanged automatic renewal. A portal user changing renewal
  behavior explicitly confirms the transaction again.
- Microsoft MCA or other externally hosted provider acceptance remains an external blocking step;
  Nexum never claims to accept it for the customer.

### Automated Onboarding And Provider-Originated Changes

- After contract validation, Nexum automates customer matching/creation, Microsoft tenant
  creation/attachment, MCA attestation delivery and polling, subscription provisioning, final
  synchronization and contract linkage as far as the provider APIs allow.
- Missing required customer data, ambiguous matching, provider validation failure and required MCA
  acceptance are valid pause states. They do not roll back unrelated successful synchronization.
- Routine writes execute immediately for a user with the required permission when contract rules
  allow them.
- Optional second approval is settings driven by cost threshold, commitment length, product risk,
  cancellation or reduction impact.
- Changes made directly in CloudFactory or its customer portal always synchronize into Nexum.
- When the contract permits a provider-originated change, Nexum updates contract quantity and billing
  automatically. Otherwise Nexum keeps the real provider state, records a divergence and proposes a
  contract amendment. It never automatically reverses the provider-side action.

### Billing

- A pending provider operation creates a non-billable pending contract amendment.
- Billing begins only when CloudFactory reports the licence active and uses the provider's effective
  activation date.
- Failed or cancelled operations never become billable.
- Commercial owns the contract/licence entitlement. Economy owns recurring order generation and
  accounting handoff. Integration must not create a CloudFactory-specific invoice engine.
- Active licence lines automatically create or update recurring Economy order lines for the billing
  period. They are draft by default; automatic approval/export is an opt-in Economy setting.
- Mid-period billing follows the provider's actual charging model. Provider-prorated charges are
  prorated in Nexum; full-period provider charges create full-period sale charges. Later provider
  invoices are reconciled against the generated lines.

## Verified Provider Facts

### Authentication And Authorization

CloudFactory states that its Portal APIs use OAuth 2.0 through Auth0.

- No static API key is available.
- CloudFactory does not register external API clients and does not support a Nexum callback or PKCE
  client flow. There are no integration audience or scope values.
- The identity is a CloudFactory Portal user. CloudFactory explicitly supports a dedicated service
  account created in the Portal and assigned roles by a Partner Admin.
- The first refresh token is issued through CloudFactory's interactive Auth0 login flow, either from
  the Utility API or Swagger UI. Because the result returns to CloudFactory rather than Nexum, the
  refresh token must be transferred once into Nexum's masked secret setup.
- Access tokens are bearer tokens.
- Access-token lifetime is documented as 86,400 seconds, or 24 hours.
- Refresh tokens are documented as having infinite lifetime until revoked.
- A refresh token represents a full login and must be protected like account credentials.
- CloudFactory activity logs do not distinguish a user login from refresh-token use.
- Service accounts are created in the Partner Portal and assigned roles by a Partner Admin.
- The live Utility OpenAPI marks `GET /Authenticate/ExchangeRefreshToken/{refreshtoken}` as
  deprecated even though the older authentication article still recommends it.
- The consolidated Partner API replaces normal renewal with
  `POST /v1/users/authentication/exchange-refresh-token` and a JSON body. Nexum must use this POST
  contract, not the token-bearing legacy URL.
- The current POST response returns a new access token and expiry but no rotated refresh token. Nexum
  continues using the stored refresh token until replacement or revocation.
- The published legacy revoke endpoint still places the refresh token in the URL path. Nexum must
  not assume it is the supported consolidated revocation contract.
- Partner API exchange must send `isCustomer = false`. The `customer=true` mode is only for
  end-customer Portal identities.
- Permissions are derived entirely from the Portal account's roles.

### Current Login Contract

The live Utility OpenAPI publishes:

| Method | Path | Documented purpose |
| --- | --- | --- |
| GET | `/Authenticate/Login?customer=false` | Return the interactive Auth0 login URL |
| GET | `/Authenticate/Token?code={code}&customer=false` | Fixed CloudFactory callback that returns a token payload |
| GET | `/Authenticate/ExchangeRefreshToken/{refreshtoken}` | Exchange refresh token; currently marked deprecated |
| GET | `/Authenticate/RevokeToken/{refreshtoken}` | Revoke one refresh token |
| GET | `/Authenticate/RevokeAllTokens` | Schedule revocation of all tokens |
| GET | `/Authenticate/Roles` | Return roles and permissions |
| POST | `/v1/users/authentication/exchange-refresh-token` | Current consolidated refresh exchange with JSON body |

The login operation only publishes the optional `customer` flag. CloudFactory confirmed on
2026-07-20 that the fixed Portal-user token flow is the supported integration contract and that no
external client registration/callback alternative is available.

### API Families In Scope

| API | Base path | Primary use |
| --- | --- | --- |
| Partner v2 | `https://portal.api.cloudfactory.dk/v2/partners` | Authorized partner identity and partner IDs |
| Customer v2 | `https://portal.api.cloudfactory.dk/v2/customers` | CloudFactory customers and Microsoft attachment metadata |
| Catalogue v2 | `https://portal.api.cloudfactory.dk/v2/catalogue` | Product, SKU, term, billing cycle, price, and attributes |
| Microsoft v2 | `https://portal.api.cloudfactory.dk/v2/microsoft` | Tenants, agreements, subscriptions, options, schedules, upgrades, and renewals |
| MCA v1 | `https://portal.api.cloudfactory.dk/v1/mca` | Microsoft Customer Agreement attestation workflow |
| Billing | `https://portal.api.cloudfactory.dk/billing` | Partner invoices and invoice types |
| Notification | `https://portal.api.cloudfactory.dk/notification` | Webhook/email registration and delivery execution logs |
| Activity Log | `https://portal.api.cloudfactory.dk/activity-logs` | Provider-side operational history |
| Utility | `https://portal.api.cloudfactory.dk` | Authentication only; business operations are being phased out |

### Confirmed Provider Authentication Contract

CloudFactory's public authentication article and written Partner Care response now form one usable
contract:

1. a Portal service account is the integration identity;
2. interactive Auth0 login issues the initial refresh token at CloudFactory's own callback;
3. the administrator transfers that refresh token once to Nexum;
4. Nexum uses `POST /v1/users/authentication/exchange-refresh-token` with a JSON body for automatic
   access-token renewal;
5. the same refresh token remains the long-lived credential and grants the account's full roles;
6. a Partner Admin manages the service account and its roles.

The remaining authentication uncertainty is revocation. The public article documents only the
legacy token-in-path revoke operation, while the consolidated API does not publish a replacement.
Production authorization remains gated on a verified, operationally safe revocation procedure.

### Consolidated Partner API Verification

CloudFactory's consolidated `GET /v1/openapi.json` contract was reverified on 2026-07-20 and confirms
that the target integration is technically feasible with the provider-supported token model.

Verified capabilities:

- **Authentication:** non-deprecated refresh exchange in a POST body with a documented 86,400-second
  access-token example. The response does not return a rotated refresh token.
- **Customers:** list, create, read, full update, JSON Patch, delete, validate, currency discovery,
  and external-service lookup. These operations support the approved two-way client sync.
- **Catalogue:** categories, products, cost, sale, currency, customer adjustments, promotions,
  recursion term, billing term, attributes, deprecation and purchasability.
- **Microsoft:** the separate live Microsoft OpenAPI publishes tenant validation/creation/status,
  agreements, subscriptions, quantity/status updates, upgrades, schedules, dedicated renewal,
  cancel-at-end and EST operations.
- **MCA:** the dedicated MCA API documents validation, attestation creation, customer-facing
  Microsoft acceptance, status polling, partner agreement lists and issue records. Completion can
  take up to five minutes to appear.
- **Adobe:** customer/reseller creation and update, product and MSRP/cost discovery, order preview and
  placement, order status/refunds, subscriptions, renewal updates, upgrades, transfers and migration.
- **Other providers:** the consolidated API also publishes provider families such as Keepit and
  Exclaimer. These belong in later capability-adapter slices rather than the Microsoft/Adobe first
  delivery.
- **Notifications:** partner subscriptions support `email` and `webhook` channels, webhook URL,
  a `secretKey`, event definitions in `service.type.action` form, delivery logs and resend.
- **Billing:** invoice list/detail/type reads are available. No provider invoice-creation operation
  is published, so Nexum Economy remains the billing owner.
- **Activity log:** partner activity, IP-location and activity-area/section reads are available in the
  consolidated API.

Confirmed by CloudFactory Partner Care on 2026-07-20:

- no OAuth client, callback, PKCE client, audience, or scopes are supported;
- authentication uses a Portal user refresh/access token and Portal roles;
- no sandbox exists and every API call resolves to production;
- no hard general rate limit is currently defined, but CloudFactory may introduce one;
- `price.sale` is MSRP;
- Customer/Catalogue require `Partner`, Microsoft/MCA require `Microsoft Full Access`, Adobe requires
  `Adobe`, invoices require `Finance`, and Notification/Activity Log require `Partner Admin`;
- Microsoft, Adobe, Keepit, Exclaimer, and other published provider APIs use the same partner
  authorization, subject to the account's roles;
- the dedicated MCA attestation response supplies the `attestationId` used for the customer-facing
  Microsoft acceptance link.

Still open:

- a secret-safe supported revocation procedure for the long-lived refresh token;
- the exact webhook `secretKey` transport/signature, canonical payload, timestamp/event identifier,
  and replay window. CloudFactory Partner Care has already escalated this to its development team;
- the recommended production-only validation procedure for customer, tenant, Microsoft subscription,
  and Adobe order writes.

## Remaining Provider Security Gates

The lack of an external OAuth client is no longer an unanswered blocker; it is a confirmed provider
constraint and the architecture must accommodate it. The following gates remain:

1. Before a real production refresh token is stored, CloudFactory must confirm whether
   `/Authenticate/RevokeToken/{refreshToken}` is still the supported production revocation operation
   and how Nexum can invoke it without exposing the token in proxy/provider request logs, or provide a
   replacement using a request body or authorization header.
2. Webhook registration and receipt processing remain disabled until CloudFactory documents the
   signature/secret and replay contract.
3. Provider writes remain disabled until the applicable Feature Slice defines an allowlisted,
   human-approved production validation case and rollback/cancellation procedure.

### Provider Clarification Record

The original twelve questions were submitted to CloudFactory Partner Care and answered by Christian
Svensson on 2026-07-20. His answers are summarized above. The only provider follow-up needed now is
limited to revocation, webhook verification, and safe production-only write validation. Nexum should
not repeat the already answered OAuth, role, sandbox, rate-limit, price, MCA, or provider-coverage
questions.

## Proposed Change

### Ownership

The Integration module owns:

- CloudFactory connection configuration;
- provider-supported token bootstrap, validation, replacement, and revocation;
- token encryption, caching, renewal, and revocation;
- CloudFactory HTTP clients;
- health checks;
- role discovery;
- synchronization jobs;
- webhook registration and receipt validation;
- provider request/response masking;
- operation ledger and provider correlation IDs.
- provider capability adapters for Microsoft, Adobe and later CloudFactory provider families;
- provider staging records and external identifier links.

The Clients module owns:

- the client-facing CloudFactory tab;
- automatic two-way client synchronization policy, normalized client writes and client conflict UI;
- manual CloudFactory-customer linking when automation cannot decide safely;
- client onboarding context.

Vendor master data must have one domain owner. Vendor records currently live in the Documentation
module, which is not a durable finished-product ownership model for supplier/manufacturer master
data. Before the Vendor sync slice, an ADR must either:

- establish a singular `Vendor` module and migrate the existing Vendor surface without breaking
  Documentation and Storage relations; or
- explicitly retain the current owner with a documented cross-domain contract.

The chosen Vendor owner owns manufacturer identity, normalized matching and the Vendor UI. It does
not own CloudFactory credentials or provider calls.

The Commercial module owns:

- Nexum Services, Vendor association, generic multi-source offers and preferred-source policy;
- sell/not-sell decisions and Services Vendor/Source filtering;
- pricing-rule hierarchy and dynamic contract-line price snapshots;
- licence contract amendments, quantity permissions, commitment and renewal policy;
- links from provider subscriptions to Services, source offers, contracts and contract items.

The Economy module owns:

- recurring draft order generation from active licence contract lines;
- provider-charge proration and invoice reconciliation context;
- accounting approval/export settings and handoff;
- no direct provider invoice posting before the Economy accounting RFC is approved.

The Notification module may consume normalized webhook events but must not own CloudFactory credentials.

The System/Audit surface owns user-facing audit discovery. It must not duplicate the provider client.

### Target Architecture

~~~mermaid
flowchart LR
    P["Partner Admin"] -->|"Create service account and roles"| C["CloudFactory Portal"]
    A["Nexum administrator"] -->|"Interactive login and MFA"| C
    C -->|"Issue refresh token at CloudFactory callback"| A
    A -->|"One-time masked token transfer"| B["Integration module"]
    B -->|"POST exchange with isCustomer false"| E["CloudFactory token endpoint"]
    E -->|"Access token and expiry"| F["Encrypted Integration secrets"]
    B -->|"Encrypt refresh token"| F
    F --> G["CloudFactory token manager"]
    G --> H["CloudFactory API client"]
    H --> I["Partner and role health"]
    H --> J["Two-way customer sync"]
    H --> K["Catalogue and price sync"]
    H --> L["Microsoft and Adobe capability adapters"]
    H --> M["Billing and notification sync"]
    J --> N["Automatic Nexum client link and field conflicts"]
    K --> V["Vendor and multi-source offer model"]
    V --> O["Nexum Services and pricing rules"]
    L --> O
    O --> Q["Contract licence amendments"]
    Q --> P["Recurring Economy order drafts"]
    M --> P
    M --> R["Audit and reconciliation"]
~~~

### Connection Lifecycle

Allowed states:

- `unconfigured`
- `awaiting_security_contract`
- `connecting`
- `active_read_only`
- `active`
- `degraded`
- `reauthorization_required`
- `revoking`
- `revoked`
- `disabled`

Flow:

1. Admin opens Admin → System → Integrations → CloudFactory.
2. Nexum explains that CloudFactory supports only Portal-user tokens and provides a link to the
   official token-generation instructions.
3. A Partner Admin creates a dedicated service account and assigns only the roles required for the
   first enabled capabilities.
4. The administrator completes the interactive CloudFactory/Auth0 login and MFA flow outside Nexum.
5. CloudFactory returns the refresh token through its own response page.
6. The administrator selects `Connect CloudFactory` and enters that refresh token once in a masked,
   non-autocompleting secret field.
7. Nexum immediately exchanges it through the current POST-body endpoint with
   `isCustomer = false`.
8. Nexum calls Partner Self and Roles and rejects a customer identity, unexpected partner, missing
   base `Partner` role, or unusable token.
9. Nexum encrypts the refresh token and cached access token before persistence and clears all request
   and form references.
10. Nexum stores only non-secret identity and capability metadata in configuration.
11. Connection becomes `active_read_only` only when partner identity, roles, and token renewal are
    verified and the revocation runbook is available.
12. Initial read-only sync is queued.
13. Write capabilities remain disabled until the separate permissions, feature flags, production
    allowlist, and approved Feature Slice are enabled.

Replacement authorization repeats the one-time token entry, validates the new identity before an
atomic swap, and revokes the old token through the verified provider procedure. The old token must
remain recoverable only until the new connection has passed health checks and revocation is attempted.

### Account Configuration UI

The page must show:

- connection name;
- environment, fixed to `production` because CloudFactory provides no sandbox;
- CloudFactory API base URL, fixed to the approved production host;
- link to CloudFactory's official refresh-token generation instructions;
- authorization state;
- authorized account email or subject when returned by the provider;
- partner name;
- CloudFactory partner GUID;
- legacy/debitor partner ID;
- CloudFactory roles discovered from the API;
- connected by and connected at;
- access-token expiry;
- last successful refresh;
- last health check;
- last successful sync by resource;
- last sanitized error;
- enabled read capabilities;
- enabled write capabilities;
- a one-time masked `Connect with refresh token` or `Replace refresh token` flow;
- `Test connection`, `Sync now`, verified `Revoke and disconnect`, and `Disable` actions when their
  underlying behavior is implemented.

The page must never contain fields for password, TOTP seed, one-time code, recovery code, or access
token. The refresh-token field exists only in the privileged connect/replace request, uses password
input behavior with autocomplete disabled, is never prefilled, and is never redisplayed after submit.

## Credential And Secret Boundary

### Reuse Existing Integration Storage

Create one `integrations` record with:

- `type = cloudfactory`;
- `name` chosen by the administrator;
- `server = https://portal.api.cloudfactory.dk`;
- `status` using the connection lifecycle;
- non-secret metadata in `config`;
- encrypted provider material in `secrets`.

The existing `Integration::setSecret()` pattern encrypts individual secret values with Laravel `Crypt` and the installation `APP_KEY`. The first implementation may reuse it, but the migration/ADR must document APP_KEY backup and rotation because losing the key makes the CloudFactory authorization unrecoverable.

### Non-Secret Configuration

Store in `config`:

- provider environment;
- partner GUID;
- legacy/debitor partner ID;
- partner display name;
- authorized account subject/email when available;
- discovered CloudFactory role names and IDs;
- token expiry timestamp;
- authorization timestamps;
- last refresh and health timestamps;
- sync settings;
- feature flags;
- webhook registration IDs;
- API documentation version/verification date.

### Encrypted Secrets

Store only when issued:

- refresh token;
- cached access token;
- outbound webhook shared-header secret.

Do not store:

- CloudFactory account password;
- Authenticator seed;
- MFA one-time codes;
- MFA recovery codes;
- CloudFactory Portal browser sessions or cookies;
- raw Authorization headers;
- token-bearing URLs.

### Logging Redaction

The application, reverse proxy, APM, queue failure storage, exception reporter, HTTP debug stack, and audit formatter must redact:

- `Authorization`;
- `Cookie` and `Set-Cookie`;
- `code`;
- `state`;
- `nonce`;
- `refresh_token`;
- `refreshToken`;
- `access_token`;
- `id_token`;
- `client_secret`;
- legacy refresh/revoke URL path segments.

No request URL containing a token may be logged. Nexum must use the consolidated POST-body refresh
operation and must not fall back to the deprecated path-based refresh endpoint. Revocation remains
disabled until CloudFactory confirms a non-token-bearing production contract or explicitly documents
the only safe supported alternative.

## CloudFactory Role Model

The connected service account must have the least roles needed for enabled features. CloudFactory
confirmed this mapping:

| Capability | Required CloudFactory role |
| --- | --- |
| Customer and Catalogue | `Partner` |
| Microsoft and MCA | `Microsoft Full Access` |
| Adobe | `Adobe` |
| Invoice reads | `Finance` |
| Notification and Activity Log | `Partner Admin` |

The base operational service account should use `Partner` plus only the provider and Finance roles
that are enabled. `Partner Admin` is intentionally excluded from that credential because the refresh
token has infinite lifetime and represents a full user login. Notification and Activity Log remain
disabled in the initial slices. A later ADR may introduce a second, separately stored privileged
service-account credential for those capabilities; using one broadly privileged identity must be an
explicit security decision, not a default.

Additional product-specific roles such as customer-portal account creation or Microsoft high-risk
operations must be discovered and documented before those optional capabilities are enabled.

After authorization, Nexum must call `GET /Authenticate/Roles` and persist a capability snapshot. Missing roles must disable only the affected capability and show a remediation message. Nexum permissions cannot compensate for a missing CloudFactory role.

## Endpoint Inventory

### Partner Identity

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/v2/partners/Partners/Self?includeBrandingQuote=false&includeBrandingLogo=false&includeBrandingTermsOfService=false` | Health check and partner GUID/legacy ID discovery |
| GET | `/Authenticate/Roles` | Provider role and permission discovery |

Partner Self returns, among other fields, `id`, `name`, `debitorId`, `externalServices`, and partner contact fields. Nexum should persist only the fields needed for operation.

### CloudFactory Customers

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/v2/customers` | Paginated customer sync |
| POST | `/v2/customers` | Create CloudFactory customer |
| GET | `/v2/customers/{customerId}` | Read one customer |
| PUT | `/v2/customers/Customers/{customerId}` | Update one customer and external service mapping |

Important identifiers:

- `customerId` is a CloudFactory customer GUID;
- `legacyId` is the older customer identifier;
- `partnerId` is the CloudFactory partner GUID;
- `partnerLegacyId` is the older/debitor partner ID;
- `externalServices.MICROSOFT` stores the linked Microsoft tenant ID;
- CloudFactory customer GUID is not the Microsoft tenant ID.

Customer creation and update payloads include name, email, VAT ID, phone, address, tags, customer reference, display currency, and invoice currency. Nexum must validate country code and required commercial fields before presenting a final confirmation.

### Catalogue

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/v2/catalogue/Categories` | Catalogue category discovery |
| GET | `/v2/catalogue/Categories/{categoryGuid}` | Read category |
| GET | `/v2/catalogue/Products` | Paginated product sync |
| GET | `/v2/catalogue/Products/{productGuid}` | Read one product |
| GET | `/v2/catalogue/ByAttribute/{attribute}` | Attribute-based discovery when needed |

For Microsoft NCE products, CloudFactory documents category:

`db584fbc-8a3a-4c68-b486-d9c8764dc10e`

Recommended query:

`GET /v2/catalogue/Products?PageIndex=1&PageSize=250&Filter.CategoryIds=db584fbc-8a3a-4c68-b486-d9c8764dc10e`

Store:

- catalogue/product GUID;
- category GUID;
- SKU;
- name and description;
- deprecated flag;
- purchasable status;
- commitment/recursion term;
- billing term;
- product attributes, including quantity constraints;
- currency and price;
- promotion data;
- last source update/checksum.

The consolidated catalogue price model explicitly publishes `cost`, `sale`, currency, discounts,
customer adjustments and promotions. Store `cost` and `sale` separately. Commercial pricing rules
use `cost` or `sale` as the configured calculation base. CloudFactory confirmed that `sale` is MSRP;
Nexum labels it `Recommended retail price (MSRP)` and keeps the calculated Nexum sale price separate.

Every provider product remains in the Integration-owned staging catalogue. Commercial creates or
links an ordinary Service only when a current subscription requires it or the product is explicitly
enabled for sale. Provider product removal/deprecation never deletes a Service or historical contract
line.

### Microsoft Tenant And Agreement

Current Microsoft v2 OpenAPI includes:

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/v2/microsoft/tenants` | Paginated tenant discovery; PageSize maximum 250 |
| POST | `/v2/microsoft/tenants/validate` | Validate tenant creation input |
| POST | `/v2/microsoft/tenants` | Create tenant |
| GET | `/v2/microsoft/tenants/{id}` | Read tenant |
| GET | `/v2/microsoft/tenants/{tenantId}/status` | Poll tenant state |
| PUT | `/v2/microsoft/tenants/{id}/billingprofile` | Update billing profile |
| GET | `/v2/microsoft/customer/{customerId}/domains` | Read customer domains |
| GET | `/v2/microsoft/customer/{customerId}/agreements` | Read agreements |
| POST | `/v2/microsoft/customer/{customerId}/agreements/{agreementType}/signatories` | Create agreement signatory |

The separately published MCA API documents:

| Method | Endpoint | Use |
| --- | --- | --- |
| POST | `/v1/mca/CustomerAgreement/validate?customerId={customerId}` | Validate duplicate signatory details |
| POST | `/v1/mca/CustomerAgreement/customer/{customerId}` | Create agreement and return attestation ID |
| GET | `/v1/mca/CustomerAgreement/customer/{customerId}` | Check agreement status |
| GET | `/v1/mca/CustomerAgreement/partner/{partnerId}` | List partner agreements |
| POST | `/v1/mca/CustomerAgreement/customer/{customerId}/force-sync` | Force agreement status sync |
| GET | `/v1/mca/CustomerAgreement/{partnerId}/records` | List tenants with MCA issues |

The dedicated MCA v1 attestation operation is the authoritative onboarding path. Its response returns
an `attestationId`; Nexum builds the customer-facing Microsoft link as
`https://cdn.partner.microsoft.com/mca/?attestationid={attestationId}`. The customer must complete
Microsoft's acceptance component. Nexum cannot silently accept or sign the MCA for the customer and
must not mark it complete until CloudFactory reports `Valid`.

This legally required Microsoft acceptance is an explicit exception to the general rule that routine
two-way synchronization does not ask the customer to confirm each change. Official Microsoft guidance
also describes the enhanced attestation flow as interactive customer acceptance followed by API status
and agreement handling; it is not a headless signature operation.

### Microsoft Subscriptions

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions` | Read all subscriptions |
| POST | `/v2/microsoft/customer/{customerId}/subscriptions` | Provision subscription cart lines |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}` | Read operation state |
| PUT | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}` | Quantity, billing cycle, or status update |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/Options` | Valid renewal, refund, status, and upgrade options |
| PATCH | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/Upgrade/Validate` | Validate upgrade |
| PATCH | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/Upgrade` | Execute upgrade |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/ImmediateTransitions` | Read immediate transitions |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/ScheduledTransitions` | Read scheduled transitions |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions/Schedule` | Read scheduled transactions |
| POST | `/v2/microsoft/customer/{customerId}/subscriptions/Schedule/Provisioning` | Schedule provisioning |
| GET | `/v2/microsoft/customer/{customerId}/subscriptions/Schedule/{id}` | Read scheduled provisioning |
| PUT | `/v2/microsoft/customer/{customerId}/subscriptions/Schedule/{id}/Provisioning` | Update scheduled provisioning |
| DELETE | `/v2/microsoft/customer/{customerId}/subscriptions/Schedule/{id}` | Delete scheduled provisioning |

Subscription fields required by Nexum include:

- subscription ID;
- catalogue ID and SKU;
- name and nickname;
- quantity;
- status;
- trial flag;
- billing cycle;
- term duration;
- creation/effective/commitment dates;
- promotion ID;
- parent subscription;
- refundable quantity and deadlines;
- renewal product and quantity;
- scheduled actions;
- provider attributes.

CloudFactory states that Microsoft allows one subscription property to be updated at a time. Nexum must split multi-property changes into separately confirmed operations and poll each to a stable state before sending the next.

### Adobe Customers, Products, Orders And Subscriptions

The consolidated Partner API publishes a broad Adobe contract that is suitable for a dedicated
CloudFactory provider capability adapter.

Verified operation groups:

- Adobe customer create/read/update and submission-status polling;
- reseller create/update/read and migration;
- all-products and customer-relevant-products reads;
- product `cost`, `msrp`, currency, market segment, product family and eligibility metadata;
- order preview, place, list/read, update status and refund;
- subscription create/read/list and renewal update;
- mid-term upgrade paths, flexible discounts, transfers and open acquisitions.

Adobe writes use caller-supplied submission IDs and expose asynchronous submission/status records.
Nexum must use its operation UUID as the stable submission ID where the provider schema permits it,
poll to a stable result and keep the same contract/billing gates as Microsoft. Adobe `msrp` may be
used as a recommended sale-price base; generic catalogue `sale` remains separately labelled until
CloudFactory confirms its meaning.

Keepit, Exclaimer and other provider APIs are later adapters. The core data model must not hard-code
Microsoft-only field names into generic Service, source-offer, contract or billing tables.

### Renewal Breaking Change

Effective 2026-03-03, the general subscription update endpoint rejects these renewal fields with HTTP 400:

- `autoRenewEnabled`;
- `renewal`;
- `scheduledActions`.

Use:

| Method | Endpoint | Use |
| --- | --- | --- |
| PUT | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/renewal` | Set renewal product, quantity, term, billing cycle, and scheduled action |
| POST | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/cancel-renewal` | Cancel at commitment end |
| POST | `/v2/microsoft/customer/{customerId}/subscriptions/{subscriptionId}/renew-to-est` | Move to Extended Service Term |

End-of-term choices:

| Outcome | autoRenewEnabled | scheduledActions |
| --- | --- | --- |
| Renew to new term | true | `RenewToNewTerm` |
| Cancel at expiration | false | `Cancel` |
| Extended Service Term | false | `RenewToExtendedServiceTerm` |

The CloudFactory article states that subscriptions without an explicit decision may move to Extended Service Term, with a 3% uplift and month-to-month cancellation. Nexum must surface an explicit renewal decision and deadline; it must not silently infer one.

### Billing

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/billing/accounts/{partnerGuid}/invoices` | Invoices for the last two years |
| GET | `/billing/accounts/{partnerGuid}/invoices/{invoiceNo}` | One invoice and its available details |
| GET | `/billing/accounts/{partnerGuid}/invoices/invoicetypes` | Invoice type discovery |

First release: synchronize billing metadata and reconciliation context. Do not create or post Nexum invoices automatically.

### Notifications And Webhooks

CloudFactory's current Notification API publishes these operations:

| Method | Endpoint | Use |
| --- | --- | --- |
| GET | `/notification/Events` | Discover event names and supported delivery types |
| GET | `/notification/WebhookRegistration?partnerId={partnerGuid}` | List partner webhook registrations |
| POST | `/notification/WebhookRegistration` | Create one event registration |
| PUT | `/notification/WebhookRegistration/{registrationId}` | Refresh its name and headers |
| DELETE | `/notification/WebhookRegistration/{registrationId}` | Remove one registration |
| GET | `/notification/EventExecutionLog/{eventId}` | Inspect delivery execution history |

CloudFactory confirmed in writing on 2026-07-20 that the configured secret is not used for a
cryptographic signature. It is sent verbatim in the `X-API-KEY` header over HTTPS. The payload
contains `EventKey`, `CreatedAt`, `SentAt`, and `PartnerGuid` as UTC data, but no unique event
identifier. Failed deliveries reuse an identical payload for approximately 24 hours.

Nexum therefore creates a random 64-character key, stores it encrypted, compares the header in
constant time, verifies the connected partner, applies request-size and rate limits, and deduplicates
on `EventKey + CreatedAt + PartnerGuid`. It validates both timestamps but does not apply a short
freshness window to `SentAt`. The webhook stores only minimized event metadata and queues the normal
authenticated reconciliation instead of applying payload data directly. Scheduled polling remains
mandatory as the recovery and completeness mechanism. See
`docs/adr/2026-07-20-cloudfactory-notification-webhook-authentication.md`.

### Activity Log

Use the consolidated Partner API activity-log operations:

- `GET /activity-logs/{partnerId}`;
- `GET /activity-logs/{partnerId}/iplocations`;
- `GET /activity-logs/types/areas-and-sections`;
- `GET /activity-logs/types/areas-and-sections/{area}`.

Do not build new functionality on Utility `/Log/v1` or `/Log/v2` because Utility is legacy.

## HTTP Client Contract

Create:

- `CloudFactoryAuthenticationClient`;
- `CloudFactoryTokenManager`;
- `CloudFactoryApiClient`;
- `CloudFactoryRoleGuard`;
- `CloudFactoryResponseRedactor`;
- provider-specific DTOs for writes;
- tolerant read mappers that preserve unknown enum values.

Client rules:

- use Laravel HTTP client;
- set `Authorization: Bearer {accessToken}`;
- set `Accept: application/json`;
- set a Nexum versioned User-Agent;
- use bounded connect and request timeouts;
- apply a conservative configurable per-connection concurrency and request budget because
  CloudFactory has no published hard general rate limit and reserves the right to introduce one;
- refresh only when the token is within five minutes of expiry;
- guard refresh with a distributed lock keyed by integration ID;
- retry one time after a 401 only when refresh succeeds;
- never retry 400, 403, 404, 409, 410, or 423 automatically;
- use exponential backoff with jitter for 429, 502, 503, and 504;
- honor `Retry-After`;
- cap every retry sequence;
- sanitize provider error bodies before persistence;
- attach a Nexum correlation ID to local logs and operation records;
- never log request bodies containing personal or agreement signatory data;
- parse JSON returned as a quoted JSON string where required by legacy Utility responses;
- treat unknown enums as `unknown:{providerValue}` instead of failing synchronization.

Provider status handling:

| Status | Nexum behavior |
| --- | --- |
| 400 | Validation failure; show sanitized field/context error |
| 401 | Refresh once; then mark reauthorization required |
| 403 | Capability/role failure; disable affected operation |
| 404 | Reconcile missing provider record; never recreate automatically |
| 409 | Conflict; stop and require refresh/review |
| 410 | Operation no longer available; refresh provider state |
| 423 | Provider resource locked; poll with bounded delay |
| 429 | Backoff using Retry-After |
| 502/503/504 | Bounded transient retry |
| Other 5xx | Record degraded state and retry only when classified safe |

CloudFactory does not document idempotency keys for the core writes. Nexum must prevent duplicates using its own operation ledger, unique request fingerprint, database lock, and provider-state reconciliation before any retry.

## Synchronization Design

### Initial Read-Only Sync

Run after connection:

1. Partner Self and roles.
2. CloudFactory customers.
3. Vendors/manufacturers inferred from stable provider metadata.
4. Complete CloudFactory catalogue and price staging.
5. Microsoft tenants, agreements, subscriptions and renewal options.
6. Adobe customers, products, orders and subscriptions when the capability is enabled.
7. Billing metadata when Finance is available.
8. Notification definitions/subscriptions and Activity Log only when the separately approved
   Partner Admin credential is configured.

The first post-connection run is read-only at the provider boundary, but it may create normalized
Nexum Client, Vendor and Service staging/link records under the approved automatic-sync rules. No
provider write is performed by this initial job.

### Recommended Default Schedule

| Resource | Default |
| --- | --- |
| Partner health and roles | Daily and on every admin test |
| Customers | Every 30 minutes |
| Tenants | Every 30 minutes |
| Catalogue metadata | Daily |
| Catalogue prices | Monthly by default, settings controlled |
| Subscriptions | Every 15 minutes |
| Adobe orders/subscriptions | Every 15 minutes when enabled |
| Pending/Waiting operations | Every 1–2 minutes, maximum 60 minutes |
| Renewal risk | Daily |
| Billing | Nightly |
| Webhook health | Hourly |
| Full reconciliation | Nightly |

All schedules, including monthly price-check day/time, must be configurable. A manual `Check prices
now` action uses the same job and audit path. Webhooks reduce latency but do not replace
reconciliation.

Pagination must follow provider metadata and references. Page size must not exceed the documented maximum of 250. Synchronization uses upserts, source checksums, and soft stale markers; absence from one partial page is not deletion proof.

## Customer Linking

Create an explicit but automatically managed link between a Nexum Client and a CloudFactory
customer. Matching may use:

- organization number/VAT ID;
- exact normalized organization name;
- customer reference;
- verified Microsoft tenant ID.

Automatic linking is allowed only for a deterministic unique result. A normalized-name match without
corroboration is not enough when more than one legal entity or candidate exists. If no safe match
exists, automatic inbound sync creates a Nexum Client when required data is available. Ambiguous or
incomplete candidates enter manual linking review without blocking other records.

Store:

- Nexum client ID;
- CloudFactory customer GUID;
- legacy CloudFactory customer ID;
- Microsoft tenant ID when present;
- matching method;
- confidence/explanation;
- automatic/manual link source and actor when manual;
- last verified at;
- last Nexum and provider snapshots/checksums;
- last common-ancestor field snapshot;
- last inbound/outbound sync timestamps;
- conflict count and link health.

Two-way sync uses three-way comparison:

1. compare current Nexum values with the last common snapshot;
2. compare current CloudFactory values with the same snapshot;
3. apply a one-sided change to the unchanged side;
4. create a field conflict if both sides changed that field;
5. advance the common snapshot only for fields that reconciled successfully.

Manual conflict resolution chooses Nexum, CloudFactory or an entered value for the field and then
resumes automatic sync. Other fields and other clients continue normally.

Prevent one CloudFactory customer from linking to multiple Nexum clients unless a later approved business rule permits it.

## Customer Onboarding Workflow

The workflow remains blocked until the provider contract and write slices are approved, but its
target behavior is automatic:

1. Validate an active/approved contract, licence-purchase permission and exact requested product,
   quantity, price rule, commitment and renewal policy.
2. Select the contract contact using settings; fall back to the Client primary contact; stop only if
   a required verified contact is missing.
3. Run automatic CloudFactory customer matching.
4. Link a deterministic match or create the CloudFactory customer automatically.
5. Validate name, VAT ID, email, phone, address, country, currencies and customer reference before
   the provider write.
6. Create an idempotent operation and pending non-billable contract amendment.
7. Validate Microsoft tenant input and create or attach the tenant when required.
8. Update `externalServices.MICROSOFT` on the CloudFactory customer.
9. Create the MCA attestation, send the customer-facing link through an approved Nexum communication
   workflow and poll until CloudFactory reports `Valid`.
10. Preview current product constraints, source cost/sale context, quantity, billing cycle,
    commitment, refund window and renewal action.
11. Apply settings-driven second approval only when a configured risk rule matches.
12. Provision the subscription and poll until `Active` or a stable failure; `Pending` or `Waiting` is
    not completion.
13. Mark the contract amendment billable from the provider effective date and generate/update the
    Economy draft line.
14. Synchronize the final customer, subscription, contract and billing state.
15. Store the complete audit trail.

No real customer name or personal information belongs in this RFC.

## Write Safety

Every write action must have:

- a dedicated Nexum permission;
- an integration feature flag;
- current CloudFactory role validation;
- current provider state loaded immediately before confirmation;
- client and contract context;
- human-readable before/after diff;
- price and commitment impact;
- explicit confirmation for the initiating user action and settings-driven second approval only when
  the configured risk policy requires it;
- a database transaction for local records;
- a unique operation fingerprint;
- immutable initiating user and timestamp;
- provider response status and sanitized error;
- polling state;
- final reconciliation.

High-impact operations include:

- create customer;
- create or attach tenant;
- create MCA signatory;
- provision subscription;
- increase/decrease quantity;
- suspend/reactivate/delete;
- upgrade;
- schedule/cancel provisioning;
- change renewal;
- cancel at expiration;
- move to EST.

Licence, tenant, agreement and renewal writes must never be invented or executed from a general sync
job. Approved two-way Client field synchronization is executed by its dedicated customer-sync job,
and provider-originated subscription changes may update contract/billing state only under the
approved contract rules.

## Data Model

### Existing `integrations` Record

Reuse the existing model for connection state and encrypted tokens.

Add validation and model helpers specific to `type = cloudfactory`. Do not expose the raw `secrets` cast in resources, API responses, debug pages, or audit payloads.

### `cloudfactory_client_links`

- UUID;
- integration ID;
- Nexum client ID;
- CloudFactory customer GUID;
- CloudFactory legacy customer ID;
- Microsoft tenant ID;
- match method, confidence and explanation;
- link source: automatic or manual;
- manual actor/timestamp when applicable;
- Nexum snapshot and checksum;
- provider snapshot and checksum;
- common-ancestor field snapshot;
- inbound/outbound sync timestamps;
- conflict count and health state;
- last verified at;
- timestamps;
- unique integration/customer GUID;
- unique active Nexum client link.

### `cloudfactory_products`

- UUID;
- integration ID;
- CloudFactory product/catalogue GUID;
- category GUID;
- SKU;
- name;
- description;
- deprecated/purchasable status;
- commitment term;
- billing term;
- currency;
- source cost and source recommended-price fields, with CloudFactory `sale` classified as MSRP;
- provider family/manufacturer identity hints;
- sell-enabled flag and decision actor/time;
- linked Nexum Service/source-offer IDs when materialized;
- promotion summary;
- normalized attributes;
- sanitized raw provider JSON;
- source checksum;
- synced at;
- unique integration/product GUID.

### `cloudfactory_subscriptions`

- UUID;
- integration ID;
- client link ID;
- provider family, for example Microsoft or Adobe;
- provider subscription ID;
- source offer ID;
- Nexum service, contract, contract amendment and contract-item IDs;
- catalogue GUID;
- SKU;
- name/nickname;
- quantity;
- status and raw status;
- trial flag;
- billing cycle;
- term duration;
- promotion ID;
- commitment/effective/creation dates;
- refundable quantity/details;
- renewal plan;
- scheduled actions;
- parent subscription ID;
- source channel: Nexum, CloudFactory partner portal, CloudFactory customer portal or provider sync;
- provider activation and billing-effective dates;
- billing state and latest Economy source reference;
- sanitized raw provider JSON;
- source checksum;
- synced at;
- unique integration/subscription ID.

### `cloudfactory_sync_conflicts`

- UUID;
- integration ID;
- resource type and link ID;
- field path;
- common-ancestor, Nexum and CloudFactory values with sensitive-value masking;
- detected at;
- state: open, resolved_nexum, resolved_cloudfactory, resolved_custom, ignored;
- resolution value, actor, reason and timestamp;
- outbound/inbound follow-up state;
- unique open conflict per integration/resource/field.

### Vendor External Links

The selected Vendor owner stores a generic external-link record rather than adding CloudFactory-only
columns to Vendors:

- Vendor ID;
- integration/source type;
- provider family and stable external Vendor ID when available;
- normalized source name;
- matching method and health;
- source snapshot/checksum and last sync;
- unique source/external identity.

### Commercial `service_source_offers`

This is a generic Commercial table shared by future distributor integrations:

- UUID;
- Service ID and Vendor ID;
- integration ID/source type;
- provider family and external product ID;
- SKU/source name;
- cost, source recommended price, price classification, currency and optional converted cost;
- commitment, billing and cancellation/refund metadata;
- promotion and eligibility metadata;
- purchasable/deprecated/stale states;
- sell-enabled and preferred-source flags;
- pricing policy/override reference;
- source snapshot/checksum and sync timestamp;
- unique integration/external product ID.

A manually created Nexum Service has source `Nexum` even when it has no external offer. A Service with
several offers renders all active source badges and uses its selected offer for provisioning.

### Commercial Pricing Policies

Store generic rule records scoped to Integration, Vendor, Service or contract line:

- base: source recommended price, source cost or manual;
- adjustment type: none, percentage, fixed amount;
- adjustment value;
- optional currency-conversion and currency-buffer policy;
- rounding policy;
- dynamic/fixed behavior;
- priority/scope and effective dates;
- actor and audit metadata.

### Contract And Contract-Item Extensions

Contracts require settings/snapshots for:

- allow new licence products during term;
- allow quantity increases during term;
- allow quantity reductions during term;
- default dynamic pricing and approval policy;
- default renewal policy and reminder lead time.

Licence contract lines/amendments require:

- source offer and provider subscription references;
- dynamic pricing rule and price-history snapshots;
- pending/active/non-billable/billable/divergent states;
- requested, provider-effective and billing-effective dates;
- commitment start/end, billing term and refund/cancellation deadline;
- renewal policy, decision deadline and scheduled action;
- provider-originated source channel;
- recurring Economy generation cursor/idempotency reference.

### Versioned Legal Document Records

`terms` stores the logical Nexum or provider document, source Integration, external identity,
issuer, current status, source URL, last check time, and current version reference.

`term_versions` stores immutable name, type, issuer, version label, content, source URL, effective
and publication dates, provider metadata, and a deterministic checksum.

`cloudfactory_offer_term` links every discovered provider document to its source offer and retains
inactive/not-returned links. The ordinary `service_term_pivot` links the same logical document to
the exact Nexum Service.

`contract_term_snapshots` captures the exact version and full display fields per contract line when
the contract is sent.

`legal_acceptance_events` is append-only evidence for contract acceptance and portal licence
transactions, including portal identity, commercial context, term-version IDs, normalized evidence
JSON, evidence hash, request metadata, and the resulting CloudFactory operation.

### `cloudfactory_operations`

- UUID;
- integration ID;
- client ID/link ID;
- operation type;
- resource type and provider ID;
- request fingerprint;
- request summary without secrets;
- before/after summary;
- initiated by/at;
- confirmed by/at;
- provider HTTP status;
- provider correlation ID when available;
- state: draft, confirmed, sending, pending, completed, failed, timed_out, cancelled;
- attempts;
- next poll at;
- sanitized error;
- completed at;
- timestamps;
- unique active fingerprint.

### `cloudfactory_sync_runs`

- UUID;
- integration ID;
- resource;
- mode;
- started/completed timestamps;
- cursor/page metadata;
- created/updated/skipped/failed counts;
- sanitized error;
- correlation ID.

### `cloudfactory_webhook_receipts`

- UUID;
- integration ID;
- provider event ID or deterministic event fingerprint;
- event type;
- received at;
- signature/header validation result;
- processing state;
- sanitized payload;
- processed at;
- unique integration/event fingerprint.

Provider raw JSON is optional and must be minimized. Never retain tokens, Authorization headers, passwords, MFA material, or unnecessary personal data.

## Nexum Routes And Classes

Connection, provider sync, operation and webhook routes stay in
`app/Modules/Integration/routes.php`. The Client licence/conflict UI belongs in
`app/Modules/Clients/routes.php`; Service/source/pricing routes belong in
`app/Modules/Commercial/routes.php`; recurring billing routes belong in
`app/Modules/Economy/routes.php`; Vendor routes belong to the owner selected by the Vendor ADR.

Recommended routes:

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/admin/system/integrations/cloudfactory` | Settings/status |
| POST | `/admin/system/integrations/cloudfactory/connect` | Validate and store a one-time submitted refresh token |
| POST | `/admin/system/integrations/cloudfactory/test` | Partner/role health check |
| POST | `/admin/system/integrations/cloudfactory/sync` | Queue read sync |
| GET | `/admin/system/integrations/cloudfactory/sync/{run}` | Read sanitized durable progress for a queued manual sync |
| POST | `/admin/system/integrations/cloudfactory/reauthorize` | Validate and atomically replace the refresh token |
| POST | `/admin/system/integrations/cloudfactory/revoke` | Revoke through the verified provider procedure and disconnect |
| POST | `/admin/system/integrations/cloudfactory/disable` | Disable jobs and writes |
| POST | `/integrations/cloudfactory/webhook/{integration}` | Public provider webhook endpoint with shared-header validation |

Recommended code placement:

- `Controllers/Admin/CloudFactoryIntegrationController.php`
- `Controllers/Public/CloudFactoryWebhookController.php`
- `Services/CloudFactory/CloudFactoryAuthenticationClient.php`
- `Services/CloudFactory/CloudFactoryTokenManager.php`
- `Services/CloudFactory/CloudFactoryApiClient.php`
- `Services/CloudFactory/CloudFactoryRoleGuard.php`
- `Services/CloudFactory/CloudFactoryResponseRedactor.php`
- `Actions/CloudFactory/LinkCloudFactoryCustomer.php`
- `Actions/CloudFactory/ReconcileCloudFactoryCustomer.php`
- `Actions/CloudFactory/ResolveCloudFactorySyncConflict.php`
- `Actions/CloudFactory/ConfirmCloudFactoryOperation.php`
- `Actions/CloudFactory/ExecuteCloudFactoryOperation.php`
- `Jobs/CloudFactory/SyncCloudFactoryCustomers.php`
- `Jobs/CloudFactory/SyncCloudFactoryCatalogue.php`
- `Jobs/CloudFactory/SyncCloudFactorySubscriptions.php`
- `Jobs/CloudFactory/SyncCloudFactoryAdobe.php`
- `Jobs/CloudFactory/SyncCloudFactoryVendors.php`
- `Jobs/CloudFactory/SyncCloudFactoryPrices.php`
- `Jobs/CloudFactory/PollCloudFactoryOperation.php`
- `Jobs/CloudFactory/ReconcileCloudFactoryBilling.php`
- `Views/Tech/Admin/System/Integrations/cloudfactory/settings.blade.php`
- `Tests/Feature/CloudFactoryIntegrationTest.php`
- `Tests/Unit/CloudFactoryTokenManagerTest.php`
- `Tests/Unit/CloudFactoryResponseRedactorTest.php`

The Clients module may add its own client tab and tests, but it receives normalized read models/actions from Integration. It must never call CloudFactory directly.

## Nexum Permission Model

Keep existing `integration.view` for general discoverability and add specific permissions before exposing controls:

- `integration.cloudfactory.view`;
- `integration.cloudfactory.manage`;
- `integration.cloudfactory.sync`;
- `integration.cloudfactory.customer.write`;
- `integration.cloudfactory.customer.conflict.resolve`;
- `integration.cloudfactory.tenant.write`;
- `integration.cloudfactory.subscription.write`;
- `integration.cloudfactory.subscription.approve`;
- `integration.cloudfactory.renewal.write`;
- `integration.cloudfactory.billing.view`;
- `integration.cloudfactory.audit.view`.

Default assignment:

- superadmin: all;
- integration admin: view/manage/sync, no commercial writes by default;
- finance: billing view;
- service/commercial approver: explicit customer/subscription/renewal permissions as approved;
- technician: read-only client context when client permissions allow;
- customer portal viewer or site admin: no CloudFactory write access;
- client-level customer admin: portal licence issue, quantity and renewal changes only for exact
  variants already covered by an eligible won contract and only with explicit confirmation.

CloudFactory provider roles and Nexum permissions are both required.

## UI Surfaces

All surfaces follow `docs/ui-guidelines.md`: compact operational tables, search plus secondary
filters, sortable headers, shared Bootstrap components, responsive behavior and no unfinished
controls.

### Admin Integration Page

- connection and provider-support status;
- dedicated-service-account and token-bootstrap guidance;
- partner/account identity;
- role/capability matrix;
- token expiry and last refresh without token values;
- health and sync cards;
- one-time connect/replace-token and verified revoke controls;
- read/write feature flags;
- webhook registration status;
- sanitized error history;
- operation and sync links.

### Nexum Client Tab

- link state;
- automatic-sync health and field conflicts;
- CloudFactory customer identity;
- Microsoft tenant identity/status;
- MCA status;
- Microsoft and Adobe subscriptions/licences;
- quantity, term, billing cycle, commitment end;
- refundable quantity deadline;
- renewal decision and risk;
- source channel, including CloudFactory customer portal;
- linked Service, source offer, contract amendment and billing state;
- uncontracted/divergent warnings and proposed contract amendments;
- last sync;
- add, increase, reduce, renew and cancel actions only when implemented, contract-allowed and
  permitted.

### Customer Portal Licences

- visible only to a client-level Customer admin, never a site-scoped member or viewer;
- exact active CloudFactory variants already present on an eligible won contract;
- current provider and Nexum document names, versions and source links;
- explicit confirmation on issue, quantity and renewal-policy writes;
- transaction status and immutable acceptance evidence;
- no control when Integration writes or the Client's write scope is disabled.

### Commercial Context

- Integration catalogue with `We sell this product` control;
- Services list Vendor and Source columns with sorting and filters;
- CloudFactory source badge when the active integration supplies the Service;
- current provider cost, CloudFactory MSRP and calculated Nexum sale price;
- pricing-rule inheritance and next price-check status;
- all source offers, preferred source and optional cheapest-eligible-source comparison;
- linked Nexum service/contract item;
- quantity divergence;
- term divergence;
- unlinked subscriptions;
- renewal exposure.

### Vendor Context

- manufacturer identity and normalized external links;
- CloudFactory and later source badges;
- offered Services and provider families;
- ambiguous Vendor matches requiring review;
- no CloudFactory credentials or token data.

### Economy Context

- recurring licence order lines by billing period;
- provider effective date, proration/full-period basis and price snapshot;
- draft/approved/exported status under normal Economy controls;
- CloudFactory invoice reconciliation and variances;
- automatic approval/export only when the matching Economy setting is enabled.

### Operation Confirmation

- action;
- client/customer;
- SKU/product;
- current and requested state;
- immediate and renewal effect;
- price/commitment context;
- refund/cancellation window;
- warnings;
- required permission;
- final confirmation.

Do not render unfinished buttons or toggles.

## Audit And Observability

Record:

- connection started/completed/failed;
- reauthorization;
- token refresh success/failure without token values;
- role/capability changes;
- health checks;
- sync start/end/counts;
- queued, running, retrying, completed, and failed manual sync state;
- real processed/total, created, updated, conflict, and Client/provider-source counts per selected
  sync category;
- automatic/manual client linking, field synchronization and conflict resolution;
- Vendor/product/Service/source-offer linking and sell decisions;
- pricing-rule evaluation and applied price changes;
- contract amendment and recurring Economy generation;
- operation draft/confirmation/send/poll/result;
- webhook registration and receipt validation;
- revoke/disable;
- user, integration, client, provider resource, correlation ID, timestamps, and sanitized outcome.

Metrics:

- connection health;
- seconds to token expiry;
- refresh failures;
- sync age by resource;
- sync failure count;
- pending operations by age;
- webhook receipt and rejection count;
- subscription divergence;
- renewal decisions due;
- CloudFactory 429/5xx counts.

Alerts:

- reauthorization required;
- refresh failure;
- sync stale;
- provider role removed;
- pending/Waiting beyond 60 minutes;
- webhook unhealthy;
- renewal decision missing before configured deadline;
- write failed after confirmation.

## Security And Privacy

- Require HTTPS for CloudFactory API and webhook URLs.
- Accept the refresh token only through the privileged connect/replace request; disable browser
  autocomplete, never echo it, and exclude the request from body capture and debug tooling.
- Use a dedicated CloudFactory service account, never a normal employee account.
- Encrypt provider secrets with Laravel Crypt.
- Back up APP_KEY securely and document rotation.
- Never return secret fields in APIs or serialization.
- Never place tokens in queue payloads; jobs receive integration IDs.
- Decrypt as late as possible and clear references after use.
- Do not persist authentication pages, screenshots, browser storage, or cookies.
- Apply least privilege in both Nexum and CloudFactory.
- Keep Partner Admin off the base operational service account. Do not enable Notification or Activity
  Log until the privileged-identity and webhook-security decisions are approved.
- Treat every provider call as production and keep writes disabled by default.
- Minimize CloudFactory contact/address data stored in normalized tables.
- Apply data retention to sanitized raw provider payloads and webhook receipts.
- Treat billing and customer data as organization-confidential.
- Require explicit authorization for every external write.
- Revoke authorization immediately on suspected compromise.

## Failure And Recovery

### Access Token Expired

Refresh under a distributed lock. Retry the original request once.

### Refresh Rejected

Mark `reauthorization_required`, stop all writes, pause provider-dependent sync, preserve local read models, and notify integration admins.

### CloudFactory Role Removed

Mark affected capabilities unavailable. Read-only features that still pass should remain usable.

### Provider Unavailable

Keep last successful data with a stale badge. Queue bounded retries. Do not perform speculative writes.

### Pending Microsoft Operation

Poll provider state until stable, failed, or 60 minutes. After timeout, mark `timed_out` but continue low-frequency reconciliation because the provider may finish later.

### Token Compromise

Disable jobs and writes, revoke the token, rotate any webhook secret, preserve audit evidence, contact CloudFactory support, and require reauthorization.

### APP_KEY Loss

Tokens cannot be decrypted. Reauthorization is required. No recovery path may ask for the old token.

## Testing Plan

### Unit Tests

- one-time refresh-token input handling and redaction;
- partner-mode enforcement with `isCustomer = false`;
- token expiry calculation;
- distributed refresh locking;
- single refresh after 401;
- role/capability mapping;
- deterministic Client and Vendor matching;
- three-way field reconciliation and per-field conflict isolation;
- multi-source Service matching by Vendor/SKU/source product ID;
- pricing-rule hierarchy, currency behavior and dynamic price history;
- response redaction;
- retry classification;
- idempotency fingerprint;
- tolerant provider enum parsing;
- subscription one-property update guard;
- renewal endpoint routing after 2026-03-03;
- webhook shared-header/replay validation;
- contract quantity/commitment rule evaluation;
- provider-effective billing date and proration calculation;
- recurring Economy line idempotency.

### Feature Tests

- integration page permissions;
- no password, access-token, or MFA fields;
- refresh-token input is masked, connect/replace-only, never prefilled, and never redisplayed;
- invalid, customer-mode, wrong-partner, or missing-role token rejection;
- provider exchange and validation errors are sanitized;
- successful connection and Partner Self verification;
- encrypted secret persistence;
- serialized responses never expose secrets;
- reauthorization replaces authorization safely;
- revoke and disable;
- read-only sync;
- automatic two-way Client sync, automatic create and manual fallback linking;
- field conflict review without blocking unrelated sync;
- automatic Vendor linking/create;
- integration catalogue staging and sell/not-sell behavior;
- Services Vendor/Source sorting and filtering;
- preferred-source and pricing-policy settings;
- write confirmation and permission gates;
- settings-driven second approval;
- dynamic contract amendments and uncontracted subscription proposals;
- provider-originated customer-portal changes;
- recurring Economy draft generation and invoice reconciliation;
- audit entries;
- pending operation polling;
- renewal warnings;
- webhook registration and receipt.
- immutable provider and Nexum legal versions;
- provider terms remain read-only when linked to a Service;
- contract send captures exact document-version snapshots;
- customer portal access rejects site-scoped and non-admin memberships;
- portal licence writes require an exact eligible contract line;
- issue, quantity and renewal confirmations retain document checksums and commercial evidence;
- a provider document removed from the latest payload remains available as historical evidence.

### Contract Tests

Use sanitized fixtures derived from current OpenAPI/knowledge examples for:

- Partner Self;
- roles;
- customer pages/details;
- catalogue pages/products;
- tenants/status;
- MCA/agreements;
- subscriptions/options;
- renewal actions;
- Adobe customer/product/order/subscription flows;
- billing;
- notification events/registrations.

Never record live tokens, contact data, addresses, invoice contents, or customer payloads in fixtures.

### Integration Verification

1. Sanitized contract fixtures and mocked write responses; CloudFactory has no sandbox.
2. Verified revocation runbook before a real refresh token is retained.
3. Dedicated production service account with only the base read roles.
4. Read-only Partner Self, Roles, customer, catalogue, tenant, and subscription reads.
5. Forced access-token expiry and supported POST-body refresh.
6. Secret-safe revocation and replacement-token recovery.
7. Webhook registration and validation only after CloudFactory documents the verification contract.
8. One allowlisted, explicitly approved, low-risk production write with a documented reversal path.
9. Poll to stable provider state and reconcile the resulting contract/billing state.
10. Manual human review recorded in `docs/human-review.md`.

## Rollout Plan

### Slice 0: Provider Contract And Security Gates

- record the confirmed Portal service-account and one-time refresh-token bootstrap;
- confirm the supported safe revocation procedure;
- receive the pending webhook verification contract;
- define the production-only validation and rollback rules;
- update this RFC;
- create the Integration/Vendor/Commercial ownership and credential-boundary ADRs.

Done when a dedicated production service account can be connected read-only, refreshed, replaced,
and revoked without leaking a secret. Webhook delivery may remain a separately gated later capability.

### Slice 1: Account Connection

- settings page;
- guided one-time refresh-token bootstrap;
- encrypted token storage;
- Partner Self/Roles health;
- refresh/revoke/reauthorize;
- tests and Knowledge.

Read-only only.

### Slice 2: Two-Way Customer Sync

- customer staging and external links;
- deterministic automatic matching and automatic Client create;
- three-way field synchronization;
- field-conflict review and manual linking fallback;
- audit and client sync-health context.

### Slice 3: Vendor, Catalogue And Multi-Source Services

- Vendor ownership ADR outcome;
- automatic Vendor link/create;
- complete catalogue staging;
- generic Commercial source offers;
- sell/not-sell workflow;
- Services Vendor/Source columns, sorting and filtering;
- preferred-source policy.

### Slice 4: Pricing Rules And Monthly Price Sync

- cost/source-sale storage;
- Integration/Vendor/Service pricing-rule hierarchy;
- monthly settings and manual price check;
- NOK direct handling and optional currency policy;
- dynamic price history without retroactive mutation.

### Slice 5: Microsoft And Adobe Read Models

- tenants and MCA/agreements;
- Microsoft subscriptions/options/renewal risk;
- Adobe customers/products/orders/subscriptions;
- client Licences tab;
- uncontracted/divergent reconciliation proposals.

### Slice 6: Contract Licence Lifecycle And Recurring Economy Billing

- contract permissions for new products/increases/reductions;
- append-only licence amendments and binding/renewal snapshots;
- dynamic contract pricing;
- active/non-billable/billable states;
- recurring Economy order drafts;
- provider charging-model proration and invoice reconciliation;
- this slice depends on approval of the relevant Economy recurring-billing RFC scope.

### Slice 7: Automated Customer Onboarding Writes

- CloudFactory customer create/update;
- tenant create/attach;
- MCA attestation delivery and polling;
- automatic pause/resume states;
- operation ledger and settings-driven approvals.

### Slice 8: Subscription Lifecycle Writes

- Microsoft and Adobe provision/order;
- quantity/status, upgrades and schedules;
- provider-originated customer-portal changes;
- provider-effective billing activation;
- polling and recovery.

### Slice 9: Renewal Writes

- inherited/overridden renewal policy;
- dedicated Microsoft renewal, cancel-at-end and EST;
- Adobe renewal updates;
- deadline/risk UI and settings-driven approval.

### Slice 10: Notifications, Billing Metadata And Reconciliation

- optional separately privileged Partner Admin credential when approved;
- verified webhook registration and receipt processing after the provider security contract arrives;
- notification delivery health;
- CloudFactory invoice metadata;
- nightly reconciliation, variances and observability;
- later provider-family adapters such as Keepit and Exclaimer only through separately approved
  Feature Slices.

### Slice 11: Versioned Legal Documents And Portal Ordering

- immutable Nexum and provider term versions;
- conservative CloudFactory legal-document extraction in catalogue synchronization;
- provider read-only and additional Nexum term Service UI;
- contract document-version snapshots;
- Customer-admin portal ordering for exact contracted variants;
- explicit issue, quantity and renewal confirmation evidence;
- migration, tests, Knowledge and pending human review.

Each completed slice requires tests, Knowledge documentation, and a pending human-review entry. No slice may expose controls for later slices.

## Impact Analysis

Affected modules:

- Integration: primary owner;
- Clients: automatic linking, two-way field sync, conflicts and Licences tab;
- Vendor owner selected by ADR: manufacturer master data and external links;
- Commercial: Services, source offers, pricing, contract amendments, quantity/binding/renewal rules;
- Economy: recurring licence order generation, accounting handoff and invoice reconciliation;
- Notification: normalized provider events;
- System/Audit: audit and health discovery;
- UserManagement: permissions and role seeding;
- Knowledge: operator documentation.

Affected platform areas:

- routes;
- controllers;
- services;
- actions;
- jobs and scheduler;
- queue configuration;
- migrations;
- encrypted secrets;
- permissions;
- admin navigation;
- client UI;
- API resources if external Nexum access is later approved;
- deployment APP_KEY and queue prerequisites.

Risks:

- provider-mandated manual token bootstrap and clipboard exposure;
- long-lived full-user refresh token and unresolved secret-safe revocation;
- accidental fallback to legacy token-bearing URLs;
- duplicate commercial writes;
- duplicate recurring Economy lines;
- stale provider state;
- automatic customer mis-linking or conflicting field overwrite;
- dynamic price or currency changes applied to the wrong period;
- wrong Vendor/SKU merge across source offers;
- Microsoft one-property update rules;
- asynchronous Adobe submission failures;
- pending/Waiting operations;
- renewal/EST financial impact;
- provider-portal/customer-portal changes without matching contract authority;
- incomplete Vendor domain ownership migration;
- role drift;
- future provider rate limits despite none being defined today;
- incomplete webhook authenticity guarantees;
- APP_KEY loss.

## Data And Migration Plan

Proposed migrations:

1. extend integration validation/config conventions for `cloudfactory`;
2. create client links and field conflicts;
3. add/migrate generic Vendor external links under the approved Vendor owner;
4. create CloudFactory product staging;
5. create generic Commercial service source offers and pricing policies;
6. extend contracts/contract items and add append-only licence amendments;
7. create provider-family subscriptions/orders;
8. create operations and sync runs;
9. create webhook receipts;
10. add recurring Economy source/idempotency fields required by the approved Economy slice;
11. seed CloudFactory and domain permissions only when matching routes and tests exist.

Migration requirements:

- UUID primary keys;
- foreign keys with explicit delete behavior;
- unique provider identifiers scoped by integration;
- indexes for client, customer, Vendor, Service, source offer, tenant, provider family, status,
  commitment date, next poll and sync age;
- no token columns outside encrypted integration secrets;
- nullable fields for forward-compatible provider responses;
- raw JSON retention policy;
- rollback must disable jobs and writes before dropping tables;
- token revocation must be an explicit operational step, not hidden in migration rollback.

No migration is approved until Slice 0 is complete and the RFC is approved.

## Documentation Plan

When implementation begins:

- keep this RFC current;
- add an ADR for provider ownership, token authorization/storage, privileged identity separation,
  sync, and write ledger;
- create one Feature Slice document per rollout slice;
- update Integration README;
- add CloudFactory administrator Knowledge;
- add operator runbook;
- add customer onboarding Knowledge;
- add renewal/EST Knowledge;
- update API/security documentation;
- add a human-review entry for each implemented slice.

## Open Questions

The remaining production-validation question is:

1. Since no sandbox exists, what production-only test procedure and reversible/low-risk test data or
   products does CloudFactory recommend for customer, tenant, Microsoft subscription, and Adobe order
   writes?

The revocation gate is resolved by CloudFactory's published Utility API. Nexum uses
`GET /Authenticate/RevokeAllTokens?isCustomer=false` with the current bearer token. A `202` response
means revocation is scheduled and a `204` response means it is complete. The legacy operation that
places a refresh token in the path is prohibited.

The webhook gate is resolved by CloudFactory's written confirmation that `X-API-KEY` carries the
configured shared key, retries preserve an identical payload for approximately 24 hours, and
`EventKey + CreatedAt + PartnerGuid` is the recommended deterministic identity. Notification setup
requires Partner Admin. Whether that capability uses the same dedicated Portal account or a second
dedicated account remains an operational least-privilege choice, not a protocol blocker.

The major Nexum product decisions are approved in `Approved Product Decisions`. Remaining detailed
settings, labels, defaults, retry limits and UI composition follow existing Nexum conventions and
must be documented in the relevant Feature Slice before implementation.

## Approval

This report records the requested target direction:

- configure CloudFactory against an account;
- authorize with a dedicated Portal service account and the one-time provider-supported refresh-token
  bootstrap;
- never reuse another user's token and never store Portal passwords or MFA secrets;
- include the information required to build the integration.
- automatic two-way Client and Vendor synchronization with manual fallback;
- multi-source Services, dynamic settings-driven pricing, contract-gated licensing and recurring
  Economy billing;
- automated Microsoft/Adobe onboarding and lifecycle management as far as the provider allows.

The product direction was approved by Svein Tore on 2026-07-16. CloudFactory's written responses on
2026-07-20 replace the planned OAuth-client/PKCE connection with its supported one-time Portal-token
bootstrap and define the notification webhook contract. Svein Tore explicitly approved that revised
credential boundary and the complete write-capable integration on 2026-07-20. The published
bearer-authenticated `RevokeAllTokens` operation resolves the revocation gate. Webhooks are enabled
through the documented shared-header contract, while polling and reconciliation remain mandatory.

Implementation is approved in complete Feature Slices. Because CloudFactory has no sandbox, all
provider writes remain disabled until an administrator both enables writes and allowlists the
fictitious test Client used for the first production validation.

## Official Sources

Authentication and API index:

- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/4381343752
- https://portal.api.cloudfactory.dk/docs/
- https://portal.api.cloudfactory.dk/v1/openapi.json
- https://portal.api.cloudfactory.dk/swagger/index.html
- https://portal.api.cloudfactory.dk/swagger/v1/swagger.json
- https://auth.cloudfactory.dk/.well-known/openid-configuration
- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/2752151560

Partner, Customer, Catalogue:

- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/2780463122
- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/2780626950
- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/2780626957
- https://portal.api.cloudfactory.dk/v2/partners/swagger/index.html
- https://portal.api.cloudfactory.dk/v2/customers/swagger/index.html
- https://portal.api.cloudfactory.dk/v2/catalogue/swagger/index.html

Microsoft and renewal:

- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/2779873319
- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/2780659730
- https://partnercare.knowledge.cloudfactorygroup.com/servicedesk/customer/portal/1/article/4268064774
- https://portal.api.cloudfactory.dk/v2/microsoft/swagger/index.html
- https://portal.api.cloudfactory.dk/v2/microsoft/swagger/v1/swagger.json
- https://portal.api.cloudfactory.dk/v1/mca/swagger/index.html
- https://learn.microsoft.com/en-us/partner-center/customers/confirm-customer-agreement
- https://learn.microsoft.com/en-us/partner-center/customers/integrate-sandbox-confirm-customer-accept

Adobe and other provider families:

- https://portal.api.cloudfactory.dk/v1/openapi.json
- https://portal.api.cloudfactory.dk/keepit/swagger/index.html
- https://portal.api.cloudfactory.dk/exclaimer/swagger/index.html

Billing, Notification, Activity Log:

- https://portal.api.cloudfactory.dk/billing/swagger/index.html
- https://portal.api.cloudfactory.dk/notification/swagger/index.html
- https://portal.api.cloudfactory.dk/notification/swagger/v1/swagger.json
- https://portal.api.cloudfactory.dk/activity-logs/swagger/index.html
