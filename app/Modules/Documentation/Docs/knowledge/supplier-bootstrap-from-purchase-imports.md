Supplier bootstrap lets the Storage supplier-order workflow resolve or create canonical supplier
master data without writing directly to the Documentation Vendor table. Documentation remains the
owner of every Vendor and independently verifies the immutable import evidence before a supplier is
reused or created.

## Configuration

The global mode is selected in **Storage > Purchase Order Automation**. A supplier profile may only
narrow the global behavior. The available modes are:

- **Existing only:** an import must resolve one existing supplier. Unknown or ambiguous identities
  stop for review and no Vendor is created.
- **Review candidate:** an unknown supplier may become a new inactive, review-flagged Vendor. The
  import remains in attention until a human reviews the master data and resolves the workflow.
- **Create active:** an unknown supplier may become active only after all automatic trust and policy
  gates pass and an explicitly configured active actor is authorized.

A mode does not bypass Storage's runtime mode. In particular, shadow processing records extraction
and validation only and does not create Vendor, Item, Purchase Order, receipt, or stock records.

## Trusted Identity

The visible `From` address and supplier display name are not sufficient identity. Active bootstrap
requires canonical sender-authentication facts captured by Email, including passed authentication,
alignment, authenticated identity and domain, and the configured authentication-service result.
Documentation compares those facts with the immutable import ledger and rejects altered evidence or
a mismatched source fingerprint.

A trusted identity claim is stored as bounded provenance and a stable identity hash. Reprocessing
the exact same claim is idempotent. A different claim, duplicate identifier, or conflicting source
is an exception for review rather than an automatic merge.

Nexum never merges suppliers by similar names. Exact `vendor_code`, organization number, email, or
URL values can expose a conflict, but they do not authorize overwriting or joining records. URLs
cannot contain credentials, and raw email bodies, arbitrary headers, tokens, and secrets are not
stored in Vendor provenance.

## Actors And Permissions

Creating an active supplier requires an explicit active user with `documentation.create`. The
Storage automation policy also requires the configured actor and its Storage permissions before an
automatic Purchase Order can be finalized.

A review candidate may be created through the bounded service path only when the policy permits it;
the service identity is recorded and the Vendor remains inactive. Interactive supplier/profile
administration stays protected by its Documentation and Storage permissions.

## Reviewing A Candidate

When an import reports a supplier candidate:

1. Open the supplier-order import and compare the sanitized source card with the extracted identity.
2. Open the linked Vendor and verify name, organization number, contact details, URL, and source
   provenance against a trusted source.
3. Resolve any conflict explicitly. Do not activate or merge a supplier based only on a display
   name or an email-body claim.
4. Map unresolved supplier SKUs or create distinct Items through Storage's reviewed actions.
5. Retry or manually finalize the import only when all blocking reasons are cleared.

Manual decisions remain visible in the import, profile, mapping, Vendor provenance, and Purchase
Order source trace. Changing the Vendor later does not rewrite the source evidence that justified
the original import decision.

## Safety Boundary

Supplier bootstrap only manages supplier master data. It does not:

- place an order with a supplier;
- create or merge Items by product-name similarity;
- confirm shipment or delivery;
- create a receipt, Stock Unit, or stock Movement;
- change on-hand inventory;
- follow supplier links or fetch remote email content.

Inventory changes only when an authorized user posts a goods receipt through the Storage receiving
workflow.
