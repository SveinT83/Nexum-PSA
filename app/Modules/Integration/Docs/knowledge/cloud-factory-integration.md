Cloud Factory-integration lets Nexum PSA synchronize Clients, catalogue products, Services, licences, contract changes, and billing data with the Cloud Factory partner portal.

## Provider Requirements

Cloud Factory does not support OAuth client registration, PKCE, scopes, or a sandbox. The connection therefore uses a dedicated Cloud Factory Portal service user and its refresh token.

Create a dedicated account with only the roles needed by the integration:

- Partner for customers and catalogue.
- Microsoft Full Access for Microsoft subscriptions.
- Adobe for Adobe subscriptions and orders.
- Finance for invoice-related provider data.
- Partner Admin for activity log and notification capabilities.

All Cloud Factory API calls are production calls. Nexum therefore starts provider writes in the allowlisted fictitious Client scope.

## Connecting Securely

Open Admin -> Integrations -> Cloud Factory. The right sidebar contains the same connection guide and links:

1. Use a dedicated Cloud Factory Portal account with the required roles.
2. Open [Cloud Factory - Get refresh token](https://portal.api.cloudfactory.dk/Authenticate/Login?customer=false).
3. Open the login address returned by Cloud Factory.
4. Sign in with the Portal account and complete MFA.
5. Copy only the Refresh Token from the token response, never the Access Token.
6. Return to Nexum, paste the value in the masked Refresh token field, and select Connect and verify.

The [official Cloud Factory Utility Portal API guide](https://portal.api.cloudfactory.dk/swagger/index.html) documents the Authenticate endpoints. Never send an access or refresh token by email or chat.

Paste the service user's refresh token into the connection form. Nexum exchanges it for an access token and verifies the partner identity. Refresh, access, and ID tokens are encrypted at rest and are never rendered back to the browser.

Cloud Factory does not include Portal roles in the access or ID token. Nexum therefore reads the
account's current roles from Cloud Factory's authenticated `/Authenticate/Roles` endpoint and stores
a capability snapshot. The snapshot is refreshed when the account is connected, whenever Nexum
renews the access token, and when an administrator selects **Refresh capabilities**. Replacing the
refresh token is not required when Portal roles change.

Before the first successful role check, capability badges show **Not checked**. After a successful
check, each badge shows **Available** or **Missing role** based on the roles returned by Cloud Factory.
The page also shows the discovered roles and the last check time.

The green API verified badge records the latest successful connection verification or synchronization. It is not a new live API request on every page view. A failed synchronization changes the badge to API check failed.

Use Revoke all tokens when the account or connection must be retired. Nexum sends an authenticated revocation request and clears every locally stored token after Cloud Factory accepts it.

## Write Safety And Validation

Provider writes have two scopes:

- Fictitious Client only allows writes only for the selected test Client.
- All Clients remains locked until Nexum has observed a confirmed provider operation for the allowlisted fictitious Client and an administrator records validation.

Disabling provider writes does not disable read synchronization. This allows operations and subscriptions to remain visible without allowing licence changes.

Every write uses a stable operation fingerprint and idempotency key. Repeating an already submitted or confirmed action does not send a duplicate order. Failed actions store their provider-safe error, can be retried, and remain visible in the integration activity list.

## Automatic Synchronization

The Laravel scheduler checks Cloud Factory every five minutes. The configured intervals decide whether work is due:

- Client synchronization defaults to hourly.
- Subscription synchronization defaults to every 15 minutes.
- Catalogue and price synchronization defaults to monthly on the configured day and time.

A queue worker must process the queued jobs. Manual sync actions use the same synchronization services and safety rules.

The administration page keeps Automation, pricing, and write safety and Conflicts and recent
activity collapsed by default. Open the first section to change synchronization, pricing, or write
settings. The second section retains separate cards for open conflicts, sync runs, provider
operations, and notification webhooks.

Selecting a manual synchronization action creates a queued run immediately and opens a live progress
window. Everything shows separate rows for Clients, Catalogue and prices, and Licences. Each row
shows its real status, processed item count, known total, created records, updated records, and
conflicts. No percentage is simulated.

Cloud Factory exposes licences per Client and provider rather than as one partner-wide count. While
that total is being discovered, the Licences row shows the real number of licences processed and the
number of Client/provider checks completed. Closing the progress window does not stop the queue job.

Return to the Cloud Factory page and select View current sync to resume watching an active run.

## Notification Webhooks

Cloud Factory confirms that notification requests are not cryptographically signed. Nexum generates a random shared key, stores it encrypted, and registers it as the `X-API-KEY` header over HTTPS. Enable registrations from the Cloud Factory administration page after connecting a Portal account with Partner Admin.

Each payload must contain `EventKey`, `CreatedAt`, `SentAt`, and the connected `PartnerGuid`. Nexum compares the header in constant time and deduplicates retries using `EventKey + CreatedAt + PartnerGuid`. Cloud Factory may retry the identical payload for approximately 24 hours, so Nexum validates the timestamps but does not reject a delivery merely because `SentAt` is old.

The receipt stores only the minimum event metadata. It never applies customer, licence, contract, price, or billing changes directly. Instead it queues the normal authenticated Cloud Factory reconciliation. Scheduled polling remains active as a safety net.

Disabling webhooks removes the known Cloud Factory registrations before deleting the encrypted shared key. Token revocation performs the same cleanup first. Recent accepted, processed, and failed deliveries are visible on the administration page; the shared key is never displayed.

## Client Matching And Two-Way Updates

Nexum first reuses an existing explicit Client link. New Cloud Factory customers are matched in this order:

1. Normalized organization number.
2. Exact normalized Client name plus billing email.

A unique match is linked automatically. An ambiguous match becomes a conflict and must be linked manually from the Cloud Factory administration page. One Nexum Client can link to only one Cloud Factory customer and vice versa.

When no match exists and automatic creation is enabled, Nexum creates the Client, its default Site, and its initial contact bridge. Customer address data is copied to the default Site.

After a link exists, Nexum compares the last synchronized snapshot with both sides:

- A change on only one side is copied to the other side.
- Different changes to the same field become a conflict.
- Local changes are pushed automatically when two-way Client updates are enabled.

When issuing a licence for an eligible Nexum Client without a Cloud Factory link, Nexum creates the Cloud Factory customer first and then submits the licence order.

## Catalogue, Vendors, And Services

Catalogue sync imports product cost and price.sale. Cloud Factory confirmed that price.sale is MSRP,
the manufacturer's suggested retail price.

Each Cloud Factory category is stored as a stable provider-to-Nexum Vendor mapping. The product then
stores the canonical Nexum Vendor ID as well as its separate Cloud Factory source identity.

During synchronization:

- All Microsoft catalogue families reuse the one canonical Microsoft Vendor.
- A unique exact or normalized Vendor match is reused automatically.
- A missing named Vendor is created automatically in the Nexum Vendor register.
- Generic or ambiguous categories remain unmapped and create a conflict instead of being guessed.

Open the collapsed **Vendor mappings** card above the catalogue to inspect these decisions. Its warning
badge shows when a category needs attention. An administrator can link it to an existing Nexum Vendor;
the selection is audited and propagated to every matching offer and any already linked Service.

The catalogue table displays the canonical Vendor but does not repeat a Source column because every
offer on this page comes from Cloud Factory. After an offer becomes an ordinary Service, the Services
list displays and can filter or sort its Cloud Factory source alongside Services from other sources.

Each offer also displays its provider commitment and billing cadence. Cloud Factory's
`recursionTerm` is the term duration, called `TermDuration` by Microsoft. Its `billingTerm` is the
invoice cadence, called `BillingCycle` by Microsoft. This distinguishes, for example:

- Monthly commitment with monthly billing.
- Annual commitment with monthly billing.
- Annual commitment with annual billing.

Imported products appear in the Cloud Factory catalogue before they become ordinary Nexum Services.
An offer labelled **Catalogue only** is therefore not shown in the normal Services list. It becomes a
Service after an administrator marks it **For sale**. An active subscription also retains or creates
the exact variant Service automatically so contract and billing history is not lost.

Each compact offer row shows this state directly. Use **Set up for sale** or **Edit resale** to expand
the price, For sale, and exclusion controls beneath only that product.
Use the separate **Commitment term** and **Billing term** filters to narrow the catalogue by the
provider terms. Select the **Commitment** or **Billing** table heading to sort the matching offers
in ascending or descending term order without losing the active search and filters.

An administrator can:

- Enable an offer for sale.
- Exclude a product that should not be offered.
- Choose MSRP, MSRP plus percentage, cost plus percentage, or manual price.
- Set an offer-specific percentage or manual price.

Products with an active subscription cannot be hidden in a way that removes their Service. This
protects contract and billing history.

When a product becomes a Service, Nexum reuses the category's canonical Vendor, records cloudfactory as
the source, stores cost, MSRP, currency, pricing mode, and sale price, and treats it like any other
Nexum Service. The Service list can be filtered and sorted by Vendor and source.

Monthly catalogue sync updates cost and MSRP. Automatic sale-price changes follow the global or offer-specific pricing rule unless manual pricing is selected.

### Cost price and commitment variants

Cloud Factory sends cost and MSRP as totals for the offer's commitment term. Nexum retains those raw
source totals and also shows the normalized amount used by the ordinary Service billing interval.
For example, the Business Basic annual commitment with monthly billing uses one twelfth of the annual
source total as its monthly Nexum cost and sale basis.

Enabling an offer creates an externally managed row in the ordinary Nexum Costs catalogue. This is
the amount used by existing Service, package, quote, contract, and profitability calculations.
Cloud Factory owns the synchronized amount, so the row is visible but cannot be edited or deleted
from the Cost page.

Each commitment and billing variant receives its own Nexum Service:

- The generated SKU appends the exact term identity, for example `-C12-B1` for annual commitment
  with monthly billing and `-C12-B12` for annual commitment with annual billing.
- One Cloud Factory offer owns one Service and one integration-managed Cost.
- Manual Nexum Costs linked to that Service are preserved and added to its provider Cost.
- A Service cannot be shared by another Cloud Factory offer.

### Service and Cost ownership lifecycle

The generated Service and Cost are ordinary Nexum Commercial records connected through the normal
Service-Cost relation. Both rows retain Cloud Factory as their source and store the exact source
Integration. This makes the Cost available to the existing profitability, contract, and future
accounting workflows rather than keeping it only inside the Integration module.

While the Cloud Factory Integration is active, both records are marked **Cloud Factory** and
**Managed**. The source badge links to the active Integration settings. Manual changes, price
updates, deletion, API updates, and removal of the managed Cost relation are rejected by the server.
Cloud Factory synchronization remains the only writer for those records.

Disabling, revoking, or deleting the Integration does not delete the Service, Cost, their relation,
contract snapshots, or accounting history. The retained records are shown as **Released to Nexum**
and become editable like ordinary Nexum data. A new contract selected after release uses the retained
ordinary Cost and does not attach the inactive Cloud Factory offer. A future provider such as Pax8
can take ownership through a controlled mapping workflow without recreating the commercial history.

When a variant-specific Service is added to a draft contract, Nexum automatically stores its exact
offer on the contract line. The line snapshots normalized cost, raw source prices, currency, and term
values. Later monthly catalogue updates do not rewrite the accepted cost snapshot. Licence issue and
incoming subscription reconciliation both require that exact Service and offer combination.

## Legal Documents And Portal Ordering

Cloud Factory catalogue synchronization checks explicit legal-document, agreement, and terms fields
alongside product and price data. This runs in the monthly catalogue job. Enabling a staged offer
also links any stored document immediately and queues a current catalogue check.

A provider document is read-only and keeps immutable versions. If Cloud Factory returns changed
content, version, issuer, title, effective date, or source URL, Nexum creates a new version. It never
rewrites the version already captured by a contract or portal action. If the latest payload no longer
contains the document, Nexum marks it **Not returned in latest sync** and retains it.

Open the Service's **Terms & Legal** section:

- **Provider terms** shows synchronized issuer, version, status, source link, and last check.
- **Additional Nexum terms** selects documents from the approved Nexum legal library.
- Provider content is not editable in the Service or legal library.
- Nexum terms are maintained in the legal library, where an edit creates a new version.

If Cloud Factory supplies no supported product legal document, the Service says **Not supplied by
provider**. This is not an error and Nexum does not invent legal content. The provider's commitment
and billing terms remain visible as commercial metadata.

Dev validation on 2026-07-22 processed all 10,898 catalogue offers. The current product object
contains catalogue and commercial attributes, but no legal-document, Terms of Service, agreement, or
EULA field. Microsoft 365 Business Premium therefore correctly shows **Not supplied by provider**.
Cloud Factory's separate Microsoft Customer Agreement flow is customer-specific external
attestation, not a product document, and Partner Terms of Service is not attached to individual
catalogue offers.

Sending a contract preserves the existing combined legal text and captures the exact document
versions for every Service line. Portal contract acceptance stores those version identities.

The customer portal **Licences** page is available only to a client-level Customer admin. It lists
only exact Cloud Factory variants already present on a won, active contract that permits the action.
Every issue, quantity change, and renewal-policy change requires an explicit confirmation. The
evidence includes the portal account, membership, contact, contract line, product, Service, current
document IDs and checksums, price, quantity, commitment, timestamp, IP address, user agent, and
resulting Cloud Factory operation.

An unchanged automatic renewal already authorized by the accepted contract does not prompt again.
A portal user changing renewal behavior must confirm the transaction. Microsoft MCA acceptance
continues on Microsoft's hosted page and cannot be accepted by Nexum.

## Licence And Contract Rules

The Client workspace contains a Licences tab. Authorized technicians can issue supported Microsoft and Adobe licences and manage supported quantity, renewal, and Microsoft status actions.

A new licence requires:

- An active Cloud Factory connection.
- Provider writes enabled for that Client.
- The required Cloud Factory role.
- A won and active Nexum contract.
- A contract line for the selected Service.
- Allow new licences enabled on the contract.

Contract settings separately control new licences, quantity increases, quantity decreases, and automatic price updates. Binding start, binding end, renewal date, and cancellation deadline from Cloud Factory are stored with the subscription and contract line.

Changes made in the Cloud Factory Client Portal are imported automatically. A change that conflicts with the contract policy remains visible but is marked blocked and is not applied to the contract or billing basis until resolved.

Cloud Factory does not publish a supported immediate Adobe quantity-decrease operation. Nexum therefore does not pretend that this action is available. Adobe increases, auto-renew changes, and new subscriptions use the documented provider operations.

## Microsoft Customer Agreement

Cloud Factory confirmed that Microsoft Customer Agreement acceptance can no longer be completed through the API. If Microsoft requires a new MCA attestation, Cloud Factory returns an attestation identifier and the agreement must be signed using the Microsoft-hosted link:

~~~text
https://cdn.partner.microsoft.com/mca/?attestationid=<attestationId>
~~~

The failed Nexum operation retains the provider message. After signing, retry the same Nexum action; the idempotent operation is reused.

## Billing

An active, contract-linked subscription creates one confirmed billing period per Client, subscription, and month. Economy converts that period into one draft order line with:

- Provider and subscription identity.
- Contract item.
- Quantity.
- Unit sale price excluding VAT.
- Currency.
- Billing period.

Repeated synchronization and order generation update the same source-backed line instead of creating duplicates. A subscription that has no eligible contract remains unlinked and does not become an automatic billing line.

## Troubleshooting

If synchronization fails:

- Confirm the integration is active and the refresh token has not been revoked.
- Select **Refresh capabilities** and confirm the discovered roles match the dedicated Portal account.
- If only one capability shows **Missing role**, assign that specific role in Cloud Factory and refresh again.
- Confirm the service user still has the required role.
- Confirm the scheduler and queue worker are running.
- For missing webhooks, confirm Partner Admin is present, registrations are enabled, and the public HTTPS callback is reachable.
- Repeated deliveries with the same event key, creation time, and partner are expected to appear only once.
- Check the latest sync run, conflict list, and operation list.
- Confirm a default Unit is selected before enabling catalogue products as Services.
- Confirm Clients have a billing email before Nexum creates them in Cloud Factory.
- Confirm the contract is won, active, and contains the selected Service line.
- For Microsoft MCA errors, complete the Microsoft-hosted attestation and retry.
