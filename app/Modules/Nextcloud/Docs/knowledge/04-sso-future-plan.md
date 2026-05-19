Nextcloud integration must stay compatible with a future single sign-on model for Nexum, Nextcloud,
and other services.

## Design principle

Nextcloud is not the identity-provider domain. It discovers users and groups from Nextcloud and maps
them to Nexum concepts, but a broader Identity/Auth domain should own SSO providers, login flows,
tenant login policy, and external identity linking.

## Expected SSO model

The likely future direction is one shared identity layer with provider types such as:

- OIDC
- SAML
- LDAP
- Nextcloud-backed login where appropriate

Nextcloud connections should then reference an identity provider or tenant configuration instead of
implementing login directly inside the Nextcloud module.

## External identity mapping

Nextcloud user mappings already support `identity_model_type`, `identity_model_id`, and metadata.
This should remain because it allows the same remote account to point at a technician user, client
contact, portal user, or future external identity record.

Future work should add a provider-neutral external identity table with fields such as:

- provider id
- provider type
- tenant/client scope
- remote subject id
- username
- email
- linked local model
- last login and last sync timestamps

Nextcloud user mappings can then either migrate into that table or reference it.

## Group and role provisioning

Nextcloud group mapping should be treated as provisioning input, not authentication truth. If SSO
later also supplies groups or claims, role assignment must have clear precedence rules.

Recommended precedence:

1. Explicit Nexum admin assignment.
2. Tenant SSO group/claim mapping.
3. Nextcloud group mapping for the connected customer server.
4. Default client contact role.

## Service credentials after SSO

Even with SSO, service account credentials may still be needed for background sync. User login and
background sync are separate concerns.

SSO may reduce the need for per-user app passwords, but it does not remove the need for a secure
server-side credential model for scheduled sync and folder browsing.

## Required follow-up decisions

Before implementing SSO, decide:

- whether Nexum will be the central login entry point or whether external IdPs initiate login
- whether client portal users can authenticate through their own tenant provider
- how tenant domains map to clients
- how group claims map to client roles and technician roles
- how account deprovisioning is handled across Nexum and Nextcloud

