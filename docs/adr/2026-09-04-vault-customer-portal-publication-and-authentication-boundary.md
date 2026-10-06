# ADR 7: Customer Portal Vault Publication and Authentication Boundary

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Customers must be able to find credentials that Trønder Data explicitly shares with them through the Nexum customer portal. Customer access must not expose internal technician credentials, machine identities, unrelated contacts' items, provider Connections, or broader Client documentation.

The customer experience should be useful without turning the portal into an unrestricted credential-management or deletion surface.

## Decision

Customer access is based only on an explicit Vault-owned publication grant. Client membership, contract existence, Asset visibility, Ticket access, or knowing an item ID does not publish a credential automatically.

A publication grant names:

- the Vault Item and permitted active version selector;
- the Client and optional Site/related-record scope;
- exact customer contacts, portal roles, or Client groups;
- allowed operations: discover, reveal, copy, TOTP display, approved attachment download, or use-without-reveal where supported;
- start and expiry;
- review/rotation policy;
- publication reason and publisher;
- authentication and step-up requirement.

The portal shows safe context sufficient to identify the credential: title, related service/Asset/Site, username only when explicitly classified for customer display, last update/review date, publication status, and permitted actions. It never shows internal notes, internal relationships, operational Connections, audit details, other recipients, provider mappings, recovery data, or inaccessible item existence.

Customer reveal, copy, TOTP, and attachment download require an active portal account, current Client/contact association, strong authentication, current publication grant, and fresh step-up according to policy. Customer Vault access requires 2FA enrollment. Passkeys/WebAuthn are preferred; the supported fallback follows Nexum's strongest approved customer authentication policy. Sensitive views expire quickly and resist caching.

Customers may reveal/copy published values, use an approved action without reveal, and request access, correction, change, or rotation. They cannot edit secret content, change grants, republish, export the Client Vault, destroy an item, or change relationships. Authorized technicians handle those requests through the internal workflow.

Every customer access and denial is recorded in secret-safe audit. Rate, quantity, anomaly, and repeated-failure controls can temporarily block sensitive operations without hiding the reason from authorized administrators.

Publication is revoked immediately when its grant is removed, the contact is deactivated, Client association ends, the item/version is revoked or destroyed, required contract/dependency policy fails, or incident lock is active. Contract changes may trigger review rather than automatic shutdown unless the publication policy explicitly requires immediate revocation.

External Vaultwarden publication is a separate adapter path. A portal grant does not automatically create a Vaultwarden user/collection grant, and an external grant does not automatically publish in the Nexum portal.

Customer requests to delete unrelated contractual or business records belong to a separate privacy/offboarding workflow. The Vault portal does not offer deletion of company-owned credentials.

## Rationale

Explicit publication prevents normal Client access from becoming secret access. Operation-specific grants and strong step-up allow Nexum to deliver a practical customer password experience while preserving internal separation and accountability.

## Consequences

Positive:

- Customers can obtain approved credentials without contacting a technician for every reveal.
- Internal and machine-only secrets remain invisible.
- Publication can be contact-, role-, time-, and operation-specific.
- Customer access is auditable and revocable.
- The same Vault Item can have different internal and customer policy.

Negative:

- Technicians must manage recipients and review publication.
- Customer 2FA and step-up add onboarding and support work.
- The portal must avoid browser caching, notification, and analytics leakage.
- Contact and contract changes need reliable revocation/review signals.

## Alternatives Considered

- Publish all Client-related credentials automatically. Rejected because record relationship is not authorization.
- Let any Client portal user see all shared items. Rejected because customer contacts have different responsibilities.
- Let customers edit or delete Vault Items. Rejected because Nexum Vault Items are organization-controlled operational records.
- Email credentials or passwords to customers. Rejected because mail creates uncontrolled copies and weak revocation.
- Treat Vaultwarden grants and portal grants as identical. Rejected because the systems have different identity, capability, and synchronization boundaries.

## Follow-Up

- Define the customer Vault permission and step-up UX.
- Add publication preview showing exactly what each recipient can access.
- Test cross-contact, cross-Client, expired, revoked, guessed-ID, cache, and notification leakage.
- Add customer access, request, rotation, and offboarding documentation.
- Coordinate portal publication with the Vaultwarden synchronization ADR.
