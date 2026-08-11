Shipping Carriers is the fixed transport-provider register in the Documentation workspace. It is
master data used by Storage purchase shipments and is not a free-form Documentation template.

## Opening The Register

Open **Knowledge > Documentations > Shipping Carriers**. The list can be searched by name, code,
legal name, or website and filtered by lifecycle.

Users with `documentation.view` can inspect carrier profiles. Creating or editing a carrier requires
`documentation.carrier_manage`.

## Carrier Profiles

Each profile represents one transport brand or division. Keep profiles separate when tracking
systems differ. In particular:

- Posten and Bring are separate profiles even though a future connector may be shared.
- DHL Express, DHL eCommerce, DHL Freight, and DHL Global Forwarding are separate profiles.
- DSV is active while DB Schenker remains a legacy transition profile as long as separate tracking
  choices are still needed.

Lifecycle values are:

- **Active:** available for new shipments.
- **Legacy / transition:** retained for existing or transitional shipment flows.
- **Inactive:** kept for history but not offered as a normal new-shipment default.

Changing a carrier profile does not rewrite the carrier configuration already snapshotted on a
Storage shipment.

## Tracking Methods

Nexum resolves a tracking link in this order:

1. A provider-generated link stored on the shipment.
2. A verified URL template with the tracking number safely URL-encoded.
3. The generic carrier tracking page.
4. Plain tracking-number text when no safe link is available.

A URL template must contain exactly one `{tracking_number}` placeholder. Do not guess query
parameters from browser behavior. Use a template only when the carrier documents a stable public
link format.

A snapshotted template is used only when the shipment also records the carrier method as **Verified
URL template**. Changing a carrier to a manual or provider-generated method therefore cannot leave
a stale template active on later shipments.

Some carriers require the recipient's email, phone number, or an authenticated carrier session.
Use the link-visibility field to make that limitation clear beside each tracking link on the
purchase-order page. Never store carrier usernames, passwords, API keys, or bearer links in a carrier profile.

## Link Safety

Clickable tracking links must:

- Use HTTPS.
- Have no embedded username or password.
- Use the normal HTTPS port.
- Match an allowed tracking hostname or its subdomain.

Allowed hosts contain hostnames only, one per line. Do not enter a scheme, path, port, or wildcard.
Carrier URLs are rendered as browser links only. Nexum does not fetch them from the server.

A shipment-specific direct URL is still checked against the carrier snapshot's host allowlist.
Unsafe or mismatched links remain plain text rather than becoming clickable.

## Verification

Every profile records an official source, verification state, and verification date. Review the
official source before marking a profile verified. Use **Needs review** when the carrier supplies
recipient links but the current link host could not be confirmed safely.

The seeded list adds missing profiles only. Running the seeder again does not overwrite names,
URLs, lifecycle choices, or other changes made by an administrator.

## Seeded Norway Profiles

The curated starting list includes Posten, Bring, PostNord, separate DHL divisions, UPS, FedEx,
DSV, legacy DB Schenker, GLS, Helthjem, Instabox, Porterbuddy, and inactive Budbee. It covers common
domestic, international, parcel, express, and freight deliveries into Norway without claiming that
every possible carrier is preconfigured.

Carrier websites and tracking surfaces change. Reverify a profile when a link stops working, the
carrier changes ownership, or a division moves to another tracking system.
