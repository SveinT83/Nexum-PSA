# Feature Slices: Full UserManagement API Parity

Status: Done On Dev / parent RFC Approved; final broad rerun and Main promotion in progress. Date: 2026-10-27.
Parent: ../rfc/2026-10-27-usermanagement-full-api-parity.md
Owner: Codex. Intended human reviewer: Svein Tore.

Implement sequentially; preserve existing contributor work and native security owners.
Every slice includes typed context/results, scope plus current native authorization,
validation, shared side effects, exact read-back, negative tests and documentation.

| Slice | Goal / user-visible behavior | Data / permissions | Tests / done criteria |
| --- | --- | --- | --- |
| UM01 | User lifecycle, form context, legacy contracts and native rights | Users/profile/status/role assignment/invite; matching user.* permissions and users scopes; protected writers retained | Actual token CRUD/status/roles/invite, denied actors/scopes/fields, partial update, typed PUT/PATCH and exact read-back |
| UM02 | Own profile/avatar/full preferences/current shell status; preserve work-plan/Calendar | Own user/profile/preference/file; employee and own-record boundaries | Upload/remove, aliases/timezones/weekly hours/theme, Calendar revision and Workday/Ticket consumers |
| UM03 | Role/permission CRUD, searches and explicit permission membership | Roles/permissions/pivots, Spatie cache, Email invalidation; separate native role/permission rights | Current privileges, revocation effects, guard checks, stale writes and exact membership read-back |
| UM04 | Own password/TOTP/recovery and admin MFA policy API | Secret-bearing owner operations with existing local verification/protected execution; user.manage_2fa | Valid/denied legacy and enforced-cutover flows, sensitive-output exclusion/no-store, policy read-back, Fortify regressions |
| UM05 | SSO status/settings, protocol handoff/results, link/unlink/logout | Provider/identity/session; exact issuer/subject and native verification; no secret reads | PKCE/state/nonce/signatures, local step-up, provider MFA boundary, revoke/logout and callback results |
| UM06 | Invitation inspection/acceptance/resend | Single-use token/password/status and existing job dispatch; capability/native invite rights | Expiry/reuse/rollback, protected activation, throttles, duplicate dispatch prevention and exact read-back |
| UM07 | Eligible write receipts, concurrency and safe operation status | Additive sanitized command metadata if needed; actor/token/current authority; no secret persistence | Real token replay, collision/revoke/foreign lookup/stale/races, native DB uniqueness/atomicity; secret/protocol owner replay retained |
| UM08 | Full contract, regression, handbook and human-review handoff | Swagger/catalog/Knowledge/BookStack and coordination status only | Every matrix row + embedded action mapped; actual successful HTTP schemas, affected suites, trusted HTTPS and handbook read-back |

Out of scope: new profile concepts/integrations, auth/Vault activation, real customer
mutations, human permission grants, automatic human review, Dev commit/push, Main and
production. Planned review ID HR-2026-10-27-USERMANAGEMENT-API is not yet a review result.

Documentation: module developer/employee sources, API ability descriptions, coverage,
parent RFC/ADR when a decision is approved, TODO and human-review checklists. Each slice
is Done On Dev only after relevant runtime checks and required documentation are current;
commit/push and promotion are separate. Final acceptance requires every supported browser
workflow, not endpoints returning denials or documentation labels only.
