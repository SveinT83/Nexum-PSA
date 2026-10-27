# UserManagement Browser/API Coverage

Status: Verified on Dev and merged to Main in PR #353; production not deployed; human review Pending.

All 49 original browser rows and embedded role-permission actions are explicitly mapped.
Current contract: 55 API route rows / 56 HTTP operations excluding HEAD; 77 domain schemas
plus the retained shared Calendar/Work Plan contracts. Baseline: 12 route rows / 13 operations.
Full support is workflow acceptance, not this endpoint count.

| ID | Browser method/path | API operation(s) | Slice |
| --- | --- | --- | --- |
| U01 | `GET|HEAD invite/{token}` | `users.invitations.show` | UM06 |
| U02 | `POST invite/{token}` | `users.invitations.accept` | UM06 |
| U03 | `POST sso/backchannel` | `users.auth.sso.backchannel` | UM05 |
| U04 | `GET|HEAD sso/callback` | `users.auth.sso.callback` | UM05 |
| U05 | `GET|HEAD sso/login` | `users.auth.sso`, `users.auth.sso.start` | UM05 |
| U06 | `GET|HEAD tech/admin/user_management` | `users.index` | UM01 |
| U07 | `GET|HEAD tech/admin/user_management/2fa-settings` | `users.settings.2fa` | UM04 |
| U08 | `POST tech/admin/user_management/2fa-settings` | `users.settings.2fa.update` | UM04 |
| U09 | `GET|HEAD tech/admin/user_management/create` | `users.context` | UM01 |
| U10 | `GET|HEAD tech/admin/user_management/permissions` | `users.admin.permissions` | UM03 |
| U11 | `GET|HEAD tech/admin/user_management/permissions/create` | `users.admin.permissions.store` | UM03 |
| U12 | `DELETE tech/admin/user_management/permissions/destroy/{id}` | `users.admin.permissions.destroy` | UM03 |
| U13 | `GET|HEAD tech/admin/user_management/permissions/edit/{id}` | `users.admin.permissions.show` | UM03 |
| U14 | `POST tech/admin/user_management/permissions/store` | `users.admin.permissions.store` | UM03 |
| U15 | `POST tech/admin/user_management/permissions/update/{id}` | `users.admin.permissions.update` | UM03 |
| U16 | `GET|HEAD tech/admin/user_management/roles` | `users.admin.roles` | UM03 |
| U17 | `GET|HEAD tech/admin/user_management/roles/create` | `users.admin.roles`, `users.admin.roles.store` | UM03 |
| U18 | `DELETE tech/admin/user_management/roles/destroy/{id}` | `users.admin.roles.destroy` | UM03 |
| U19 | `GET|HEAD tech/admin/user_management/roles/edit/{id}` | `users.admin.roles.show`, `users.admin.roles.permission-options`, `users.admin.roles.permission` | UM03 |
| U20 | `POST tech/admin/user_management/roles/store` | `users.admin.roles.store` | UM03 |
| U21 | `POST tech/admin/user_management/roles/update/{id}` | `users.admin.roles.update` | UM03 |
| U22 | `GET|HEAD tech/admin/user_management/sso` | `users.settings.sso` | UM05 |
| U23 | `POST tech/admin/user_management/sso` | `users.settings.sso.update` | UM05 |
| U24 | `POST tech/admin/user_management/store` | `users.store` | UM01 |
| U25 | `GET|HEAD tech/admin/user_management/users/{user}` | `users.show`, `users.context` | UM01 |
| U26 | `POST tech/admin/user_management/users/{user}/profile` | `users.update`, `users.avatar.update` | UM01 |
| U27 | `POST tech/admin/user_management/users/{user}/roles` | `users.roles.update` | UM01 |
| U28 | `POST tech/admin/user_management/{user}/invite` | `users.invitations.send`, `users.invite.send` | UM01 |
| U29 | `POST tech/admin/user_management/{user}/status` | `users.status.update` | UM01 |
| U30 | `GET|HEAD tech/invite/{token}` | `users.invitations.show` | UM06 |
| U31 | `POST tech/invite/{token}` | `users.invitations.accept` | UM06 |
| U32 | `GET|HEAD tech/profile` | `users.me.profile` | UM02 |
| U33 | `PATCH tech/profile` | `users.me.profile.update`, `users.me.avatar.update` | UM02 |
| U34 | `GET|HEAD tech/profile/integrations` | `users.me.shell` | UM02 |
| U35 | `GET|HEAD tech/profile/preferences` | `users.me.preferences` | UM02 |
| U36 | `PATCH tech/profile/preferences` | `users.me.preferences.update` | UM02 |
| U37 | `GET|HEAD tech/profile/security` | `users.security`, `users.security.material` | UM04 |
| U38 | `POST tech/profile/security/2fa/confirm` | `users.security.confirm` | UM04 |
| U39 | `POST tech/profile/security/2fa/disable` | `users.security.disable` | UM04 |
| U40 | `POST tech/profile/security/2fa/enable` | `users.security.enable` | UM04 |
| U41 | `POST tech/profile/security/password` | `users.security.password` | UM04 |
| U42 | `POST tech/profile/security/recovery-codes` | `users.security.recovery` | UM04 |
| U43 | `GET|HEAD tech/profile/view` | `users.me.shell`, `users.me.preferences` | UM02 |
| U44 | `GET|HEAD tech/profile/work-account` | `users.sso` | UM05 |
| U45 | `POST tech/profile/work-account/link` | `users.sso.link` | UM05 |
| U46 | `POST tech/profile/work-account/logout` | `users.sso.logout` | UM05 |
| U47 | `POST tech/profile/work-account/unlink` | `users.sso.unlink` | UM05 |
| U48 | `GET|HEAD tech/profile/work-plan` | `users.work-plan.show` | UM02 |
| U49 | `PATCH tech/profile/work-plan` | `users.work-plan.update` | UM02 |

## Embedded and cross-domain workflows

RolePermissions mount/search/toggle maps to exact role detail, role-scoped permission-options
and explicit desired membership PUT. Calendar's work-plan block and series operations retain
their existing official Calendar APIs and guards. Notification settings and API-key links
retain Notification/Integration ownership. Integrations/View expose their current placeholder
status only. No future personal integration system or new profile skills domain is claimed.

Public/authenticated invite aliases share the one-use capability action. OIDC endpoints retain
the native canonical redirect, original browser binding and verified provider protocol.
Local credential/factor/SSO mutations require the real first-party session and native local
verification, never a general bearer proof. The independently owned protected auth/Vault
cutover remains separate; no unsupported future owner action is counted as implemented.

Each JSON row retains exact API route defaults (native rights, abilities, fields, revision
and replay policy). Runtime/Swagger and actual successful HTTP-schema tests must pass
for every operation before the final completion claim. Native migration/HTTPS/handbook
and Main evidence are recorded in the sibling verification/promotion reports.
