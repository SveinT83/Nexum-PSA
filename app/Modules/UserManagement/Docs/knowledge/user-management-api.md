UserManagement exposes the current user, profile, authorization, invitation and security workflows through the official API.

## Availability and prerequisites

This guide describes the Dev implementation dated 2026-10-27. Main promotion, production deployment and authentication/provider activation are separate actions. Confirm the installed release before relying on new operations in production. Existing Work Plan and Calendar preference gates still apply.

Use an active internal human account. Portal-only, disabled and protected system accounts cannot use internal UserManagement operations. Each protected operation requires its explicit API ability and the account's current ordinary permission. A token never grants a role or a permission. Account lifecycle is managed by status; there is no user-delete operation.

## Create a suitably scoped token

1. Open **Admin > Integrations > API** using an authorized administrator account.
2. Create a key with only the required UserManagement abilities. No scope is selected by default.
3. Store the token in the client's approved secret store. Send `Authorization: Bearer {token}` and `Accept: application/json` over trusted HTTPS.
4. Open **API documentation** at `/api/documentation` and select **UserManagement**. Check the request fields, required scope and response type for the exact HTTP method.
5. Read `GET /api/v1/users/context` with `users.context.read` for current capabilities and form choices. Its flags describe native permissions; the token must still include the operation's ability.

`users.read/create/update` retain their existing routes. The creation form offers role choices only to a current role manager. Other authorized creators can create an unassigned user for a role manager to complete. Role assignment additionally needs `user.manage_roles`; invitation submission additionally needs `user.invite`. The new `users.invite.execute` ability offers a narrow invitation action. Role and permission management have distinct `users.roles.read/write` and `users.permissions.read/write` abilities with the corresponding normal permissions. A role manager can use that role's `permission-options` lookup without gaining permission-administration rights.

## Read, change and verify a record

1. Read the exact user, role, permission or own-profile/preferences record. Keep the response's `ETag`.
2. For a new ordinary write, send a fresh `Idempotency-Key` containing 8-200 characters. Include the unchanged `ETag` in `If-Match` when updating or deleting a versioned record.
3. Submit only documented fields. Own profile and administrator profile support the same canonical profile action and avatar limits. Use multipart form data for an avatar, with a maximum image size of 2 MiB. The dedicated avatar endpoint accepts `avatar` or `remove_avatar`.
4. Read the exact result and affected record. A successful ordinary command returns `X-Operation-Id`. Keep that identifier with your workflow's evidence.
5. On a network retry, reuse the same key, body, method, URL and original `If-Match`. The same completed command returns its stored result with `X-Idempotent-Replay: true`. A changed request under the same key returns 409.
6. If the server returns 412, read the new record and review the intervening change before submitting a new command. If it returns 428, supply the required key or `If-Match`.
7. With `users.operations.read` plus the original operation's scope and current permissions, read `/api/v1/users/operations/{operation}` using the same token. Another token or user cannot read that result. Revoking ordinary rights also prevents replay/result access.

Legacy writes keep optional-key compatibility, but clients should send keys. They do not gain unrestricted fields or actor rights. Existing Work Plan and Calendar preference writes keep their own body `revision` contracts. Deleting a bound role/permission makes subsequent reads return 404; a replay after that record is gone may also return 404 and never recreates it.

A 202 result with `state: unknown` and `reconciliation_required: true` means a local commit or dispatch may already have happened. Read the owned operation and reconcile the exact record/job before retrying. Do not create a new key to force a second invitation. `delivery_status: queued` confirms queue submission only; it does not prove inbox receipt.

## Own profile and display preferences

Use `users.profile.read/update` for `/users/me/profile`, and `users.preferences.read/update` for full display defaults and theme. Phone aliases are normalized consistently. Uploading/removing an avatar preserves the old file until the database commit; failed command persistence restores the profile and cleans up the staged upload.

Preferences accept `company`, `system`, `light` or `dark`. Changing a theme or visible Calendar hours does not rewrite normal weekly working hours, planned Calendar availability or confirmed time. The own shell endpoint reports the existing Integrations/View placeholder status. It does not create future personal provider integrations.

## Password, local two-factor authentication and recovery material

These operations require the actual first-party Nexum session. A bearer token, API ability or successful SSO login alone is not a local-password/factor proof.

1. Sign in through the existing Nexum flow. Obtain the normal CSRF cookie from `/sanctum/csrf-cookie` and send the CSRF header on writes. The request Origin/Referer must match the installation's configured first-party Sanctum domains.
2. Read `/users/me/security` for factor state. It returns no factor material.
3. Submit the current local password and, when already configured, a fresh unused Nexum authenticator code for enrollment, material access, disablement or recovery-code rotation. Use JSON bodies, never credential query strings.
4. After enabling, request `/users/me/security/material` to display the manual setup key and recovery list under the same verification. Confirm a valid enrollment code. Save recovery codes in the approved private location.
5. Use a fresh authenticator code for each later sensitive action; replayed codes are rejected. Password updates require current password and matching new password/confirmation.
6. Read the resulting security state. Material responses are `Cache-Control: no-store`. Never copy setup keys, recovery lists or submitted credentials into ordinary logs, reports or operation receipts.

These secret-bearing operations do not use general durable command receipts. Existing protected UserSecurity/Fortify/database boundaries remain authoritative. Unsupported protected-owner states fail closed; this API does not activate the separate Vault/authentication cutover or implement its pending workflows. The current installed provider must support the same action through its existing owner.

## Work account and SSO

Use `users.sso.read` for own linked-account status. Linking, unlinking, explicit work-account logout and provider-setting writes require the current first-party session. Sensitive link/unlink/provider changes retain current local-password and configured local-factor verification. Provider settings reads return only metadata and `client_secret_configured`; blank secret preserves the saved credential.

1. Read `/users/auth/sso` for availability and canonical login/callback/back-channel URLs.
2. Start a login or link flow in the same browser session. Follow the returned `authorization_url` and complete the provider's authentication.
3. Keep state, nonce and PKCE/session binding intact. The provider's registered redirect remains the existing canonical Nexum callback; do not change the provider to an unregistered URL.
4. Read the linked-account status after the callback. The typed callback adapter can complete the same state/code in that original session. Callback success does not issue a general API token or provision another account.
5. Unlink explicitly with fresh local verification, or use work-account logout. Existing Nexum SSO grants are revoked through the same owner as web.

Only an exact verified issuer/subject links an account. Matching email never claims an existing user. Provider-owned login MFA does not replace local verification for account linking/settings or Vault step-up. The signed back-channel endpoint verifies the existing logout token and prevents replay. SSO configuration verification proves trusted discovery, not a completed employee sign-in or verified provider MFA policy. Runtime switches stay independently controlled.

## Invitation acceptance

The invitation URL is a one-use capability, not an administrative bearer grant. Inspection exposes only the intended invitation context. Acceptance checks expiry, locks the exact token and pending human account, validates a confirmed password and delegates to the existing account-security owner. Disabled/system targets cannot activate through an old invitation. Concurrent or repeated acceptance cannot establish another password.

The first-party acceptance flow uses normal CSRF/session protection and signs in an active accepted account. If the existing protected owner keeps privileged activation pending, the result states `activation_pending: true`; an administrator must complete the normal approval. No API shortcut activates privileged access. Submission of an invitation email and receipt by its recipient are separate facts.

## Supported HTTP operations

All paths below are under the normal Nexum origin. The Swagger request/success/error contracts are authoritative for field details. Session requirements below are additional to current account/object permissions.

| Method | Path | Ability / authority |
| --- | --- | --- |
| GET | `/api/v1/users/invitations/{token}` | `Invitation capability` |
| POST | `/api/v1/users/invitations/{token}` | `Invitation capability` |
| GET | `/api/v1/users/auth/sso` | `Existing OIDC protocol` |
| POST | `/api/v1/users/auth/sso/start` | `Existing OIDC protocol` |
| GET | `/api/v1/users/auth/sso/callback` | `Existing OIDC protocol` |
| POST | `/api/v1/users/auth/sso/backchannel` | `Existing OIDC protocol` |
| GET | `/api/v1/users` | `users.read` |
| GET | `/api/v1/users/roles` | `users.read` |
| POST | `/api/v1/users` | `users.create` |
| GET | `/api/v1/users/{user}` | `users.read` |
| PUT | `/api/v1/users/{user}` | `users.update` |
| PATCH | `/api/v1/users/{user}` | `users.update` |
| POST | `/api/v1/users/{user}/avatar` | `users.update` |
| POST | `/api/v1/users/{user}/status` | `users.update` |
| POST | `/api/v1/users/{user}/roles` | `users.update` |
| POST | `/api/v1/users/{user}/invitations` | `users.invite.execute` |
| POST | `/api/v1/users/{user}/invite` | `users.update` |
| GET | `/api/v1/users/context` | `users.context.read` |
| GET | `/api/v1/users/me/profile` | `users.profile.read` |
| PATCH | `/api/v1/users/me/profile` | `users.profile.update` |
| POST | `/api/v1/users/me/profile/avatar` | `users.profile.update` |
| GET | `/api/v1/users/me/preferences` | `users.preferences.read` |
| PATCH | `/api/v1/users/me/preferences` | `users.preferences.update` |
| GET | `/api/v1/users/me/shell` | `users.profile.read` |
| GET | `/api/v1/users/administration/roles` | `users.roles.read` |
| POST | `/api/v1/users/administration/roles` | `users.roles.write` |
| GET | `/api/v1/users/administration/roles/{role}/permission-options` | `users.roles.read` |
| GET | `/api/v1/users/administration/roles/{role}` | `users.roles.read` |
| PATCH | `/api/v1/users/administration/roles/{role}` | `users.roles.write` |
| DELETE | `/api/v1/users/administration/roles/{role}` | `users.roles.write` |
| PUT | `/api/v1/users/administration/roles/{role}/permissions/{permission}` | `users.roles.write` |
| GET | `/api/v1/users/administration/permissions` | `users.permissions.read` |
| POST | `/api/v1/users/administration/permissions` | `users.permissions.write` |
| GET | `/api/v1/users/administration/permissions/{permission}` | `users.permissions.read` |
| PATCH | `/api/v1/users/administration/permissions/{permission}` | `users.permissions.write` |
| DELETE | `/api/v1/users/administration/permissions/{permission}` | `users.permissions.write` |
| GET | `/api/v1/users/me/security` | `users.security.read; first-party session` |
| POST | `/api/v1/users/me/security/material` | `users.security.write; first-party session` |
| POST | `/api/v1/users/me/security/2fa/enable` | `users.security.write; first-party session` |
| POST | `/api/v1/users/me/security/2fa/confirm` | `users.security.write; first-party session` |
| POST | `/api/v1/users/me/security/2fa/disable` | `users.security.write; first-party session` |
| POST | `/api/v1/users/me/security/recovery-codes` | `users.security.write; first-party session` |
| PATCH | `/api/v1/users/me/security/password` | `users.security.write; first-party session` |
| GET | `/api/v1/users/administration/2fa-settings` | `users.settings.read` |
| PATCH | `/api/v1/users/administration/2fa-settings` | `users.settings.write` |
| GET | `/api/v1/users/me/work-account` | `users.sso.read` |
| POST | `/api/v1/users/me/work-account/link` | `users.sso.write; first-party session` |
| POST | `/api/v1/users/me/work-account/unlink` | `users.sso.write; first-party session` |
| POST | `/api/v1/users/me/work-account/logout` | `users.sso.write; first-party session` |
| GET | `/api/v1/users/administration/sso` | `users.settings.read` |
| PATCH | `/api/v1/users/administration/sso` | `users.settings.write; first-party session` |
| GET | `/api/v1/users/operations/{operation}` | `users.operations.read` |
| GET | `/api/v1/users/me/work-plan` | `users.work-plan.read`; existing employee/Calendar guards |
| PATCH | `/api/v1/users/me/work-plan` | `users.work-plan.update`; existing employee/Calendar guards |
| GET | `/api/v1/users/me/calendar-preferences` | `users.calendar-preferences.read`; existing employee/Calendar guards |
| PATCH | `/api/v1/users/me/calendar-preferences` | `users.calendar-preferences.update`; existing employee/Calendar guards |

## Troubleshooting

- **401:** sign in again or replace an expired/revoked key through the normal administrator flow.
- **403:** check both the token ability and current normal permission. For security/SSO writes, use the actual first-party session and fresh local verification. Do not disable MFA or change roles to work around a denied API call.
- **404:** the record/capability is unavailable, expired, used, deleted or outside the permitted scope.
- **409/412:** reconcile duplicate keys, protocol replay or concurrent changes; read the current result before resubmitting.
- **419:** refresh the first-party CSRF cookie/session and send the matching header.
- **422:** correct only the documented validation fields. An SSO failure must be investigated through the existing provider setup and trusted discovery path.
- **428:** include the required idempotency key or current ETag.
- **429:** wait for the documented throttle window before a new verified attempt.
- **503:** the administrator must check the exact required deployment/migration/runtime, without enabling unrelated capabilities.

Do not paste private credentials, recovery codes, session cookies or tokens into support tickets. Report the operation ID, timestamp, route and non-secret error instead. Human checklist HR-2026-10-27-USERMANAGEMENT-API remains separate from automated verification and production rollout. BookStack publication and retrieval by an employee's assistant are separate verification claims.
