Open **Admin > Integrations > Tripletex** and use **Customer synchronization**. Customer-number
synchronization is independent of time synchronization.

## Enable and pause

The connection must be verified for the intended company and the Tripletex integration must be
available. Turn on **Synchronize customers with Tripletex** and click **Save customer
synchronization**. This saved setting also authorizes customer creation in Tripletex; no additional
customer-write environment setting or server-administrator action is required.

Turn the same switch off and save to pause customer synchronization and stop new customer writes.
The customer setting is off by default. Saving account settings pauses both synchronization types;
verifying the company alone starts neither. Enabling customers never enables time transfer.
Expected validation/conflict failures return to settings with a visible message and preserve the
stored settings. If the entire Tripletex integration is disabled at server level, its existing
global stop still applies; a saved customer setting can still be paused.

With customer synchronization off, new Clients use the existing local five-digit allocator.
Pausing preserves existing numbers and provider links. A form opened before a mode change must
be reloaded; Nexum does not silently switch its creation destination on submission.

## Create a Client

When enabled, **New Client** shows a read-only Tripletex number suggestion. The suggestion starts
after the highest assigned Tripletex customer number and skips numbers occupied in Nexum or by
Tripletex suppliers. Active and inactive provider records are included. It is an unreserved
suggestion, not a reservation or a claim to reproduce a private Tripletex numbering setting.

Search **Existing Tripletex customer** by name, customer number or organization number to explicitly
select an existing customer. Selection fills the provider name, organization number and number.
Its detailed lookup also suggests Billing Email and Site address. Ordinary email/phone and a unique
matching Contact may suggest the initial primary contact. Invoice email never fills primary-contact
email. Manually edited inputs are preserved; review all suggestions before saving.
The identity is rechecked at save. A possible duplicate is never automatically linked by name.

Otherwise Nexum creates a customer in Tripletex with the recalculated number, then reads back the
exact provider identity and number before saving the Client and related records locally. Only the
new customer's name, organization number, supplied Billing Email and Site address are transferred.
Primary-contact details and local notes are not exported.

Provider outages and conflicts stop creation with a visible message. They never choose an unrelated
local fallback number. A timeout may mean the provider created the customer; Nexum stores that
attempt and does not repeat its POST blindly. If provider creation is verified but local creation
fails, resubmitting the original form can finish the local part without another provider customer.

## Existing Clients and number differences

**Review customer links** requires integration-management, Client view and Client update access.
Choose the existing Nexum Client ID and Tripletex customer ID, review both names/org numbers/numbers,
then explicitly accept the Tripletex number. An occupied local number or a changed preview stops
the action. An established provider identity cannot be reassigned through this screen.

A linked number cannot be changed by ordinary UI, API or import updates, including while paused.
Pausing preserves the link; it does not turn the provider identity into an editable local number. Use the review action to check and explicitly adopt a changed provider number, even while customer
synchronization is paused. Enabling synchronization refuses existing links whose numbers differ. Other Client
fields remain editable, including records with six-digit provider numbers.

The list shows saved links and attempts; it is not continuous proof that provider data is unchanged.
Use **Review current numbers** for a fresh number read. The separate profile status reports the
latest Billing Email/Site address reconciliation. Names, recurring Contacts, historical automatic
matching and deletion propagation are not synchronized.

## Billing Email and Site address

While the same customer-sync switch is enabled, linked customers are checked in bounded batches
every five minutes. On first synchronization of an existing link, Tripletex supplies Billing Email
and the selected Site address. Nexum chooses the single default Site, or the only Site if there is
no default; an ambiguous choice requires an administrator to select a Site under the link review.
The business address is preferred; postal address is used when business address is empty.

The saved Site and address type stay bound even if another Site becomes the default. The managed
fields are Billing Email, street address, address line 2, postal code, city and country. Site name
remains locally managed after creation. Customer invoiceEmail maps only to Client Billing Email.
No primary-contact name, email, phone or role is synchronized after creation.

Changes made only in Nexum are sent to Tripletex; changes made only in Tripletex are imported.
If both systems changed the same field, Tripletex wins. Independent field changes can merge.
An explicit blank can clear a managed value after the first baseline. Other Sites and unmapped
provider addresses remain unchanged. Country names/ISO codes must match the provider country list;
use an ISO two-letter code in New Client. Postal codes retain leading zeroes and foreign formats.

**Review customer links** shows status, last check and any attention code. **Sync billing and
address** requests an immediate attempt. A busy connection reports that it has not run. Missing
or moved Sites, number/company differences and provider failures stop updates rather than choosing
a replacement. Correct the cause and retry. Rebinding a Site requires explicit confirmation and
starts again from Tripletex values; it is blocked while a write outcome is unresolved.

An interrupted provider update retains encrypted evidence. The next attempt reads the provider
before retrying or resolving a conflict. Pause keeps baselines, pending writes and local changes.
Larger customer lists are processed across successive batches; five minutes is the scan cadence,
not a guaranteed per-customer completion time.

## Recover an unknown creation outcome

An administrator must first verify the exact customer in Tripletex. Use **Reconcile an unknown
creation outcome**, supplying the attempt ID, provider customer ID and the attempted number.
Nexum reads that customer and rejects a mismatched number or an already used identity.
The original creator then resubmits the original form with unchanged customer data. Nexum checks the
saved payload and provider name/org/number before finishing. Recovery does not resend the POST.

If the original form/key has been lost but the provider identity is verified, an administrator can
pause customer synchronization, create the intended local Client, and explicitly adopt the verified
provider identity through the comparison screen. This finishes the existing attempt and retains its
evidence; it never creates another provider customer.

If the outcome cannot be established, leave the attempt unresolved. Do not delete the evidence,
blindly create another customer, or treat absence from a filtered list as proof of no creation.

## API and imports

Existing Client create/update permissions and API abilities still apply. With customer sync active,
POST /api/v1/clients requires tripletex_number_mode: true and a stable tripletex_request_key
UUID. Reuse that key and unchanged data after a recoverable failure; different data is rejected.
Optional tripletex_customer_id explicitly selects an existing provider customer. Supplied
client_number is a suggestion only in provider mode, and the response contains the verified number.
After completed creation, repeating the same attempt returns a validation error identifying an
already completed attempt, without creating a duplicate.

Provider-bound creates cannot run inside the generic Data Exchange batch transaction. Create/link
those Clients through the Client UI or API first, then import supported local fields.
The preview explains this restriction; commit rechecks it, including stale previews.

## Operations and rollout

Apply 2026_10_08_120000_create_tripletex_customer_links.php before enabling customer sync.
Customer writes follow the saved customer-sync GUI setting. The former
TRIPLETEX_CUSTOMER_WRITES_ENABLED flag is no longer used, including if an old value remains in .env
or cached configuration. The existing global Tripletex runtime and time-write setting are unchanged.
Apply 2026_10_08_180000_create_tripletex_customer_profiles.php for Billing Email/address sync.
The existing external runner must execute php artisan schedule:run every minute; it starts
tripletex:sync-customers every five minutes. No new queue worker or frontend build is required.
Refresh Blade/opcache during deployment. Check profile timestamps to verify actual execution;
schedule:list alone only proves registration.
Preserve both synchronization tables on rollback: nonempty tables refuse automatic reversal.
The human checklists HR-2026-10-08-TRIPLETEX-CUSTOMERS and HR-2026-10-08-TRIPLETEX-PROFILES
must be completed before their Main/production rollout.
