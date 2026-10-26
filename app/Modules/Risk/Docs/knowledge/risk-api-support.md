This guide covers the full Risk API under /api/v1/risk.

## Availability

The full Risk API and approved workflow corrections were verified on Dev and merged to Main
on 2026-10-26 in PR #321. HR-2026-10-26-RISK-API is Reviewed by Svein Tore.
Production deployment was not performed as part of this work; do not assume the expanded
API is available there before separately authorized deployment and production verification.


## Prerequisites and API key procedure

1. Sign in with an active employee account authorized for the intended Risk task.
2. Open **Admin -> API Management** and create or select a key with only the required Risk abilities.
3. Keep the secret in approved credential storage; send it as Authorization: Bearer over trusted HTTPS.
4. Read GET /api/v1/risk/context and the target's context before choosing input.
5. Compare both actor_authorized and token_authorized, then read the exact target and its revision.
6. Execute the supported operation and inspect its persisted read-back. Capabilities may change
   between discovery and execution; the server checks current rights again.

Token abilities never grant employee permissions, Superuser status or record ownership.
Existing keys are not automatically widened.

| Operation | Required token ability | Current employee authority |
| --- | --- | --- |
| Read, context, Client/category lookup, PDF | risk.read | risk.view |
| Create assessment | risk.create | risk.create |
| Create item | risk.create | risk.update |
| Assessment metadata | risk.update | risk.update |
| Item descriptive edit | risk.update | risk.update plus Superuser or first remaining history creator |
| Add history | risk.update | risk.view |
| Approve | risk.approve | risk.view and current approval rule |
| Delete assessment/item | risk.delete | risk.delete plus Superuser |
| Delete history entry | risk.delete | risk.delete plus exact entry creator or Superuser |
| Read defaults | risk.settings.read | risk.manage_settings |
| Change defaults | risk.settings.update | risk.manage_settings |
| Read own command | risk.operations.read | original actor/token and current underlying scope/rights/ownership |

The create-item and Add Update permission distinctions intentionally match the existing browser
routes. A settings permission is checked directly; an Admin role label alone is insufficient.

## Complete operation catalogue

All paths below include the /api/v1/risk prefix. PUT/PATCH ordinary metadata retain their previous
supported URLs. The new action/settings operations require a key and revision.

| Method | Path after prefix | Key + revision required |
| --- | --- | --- |
| GET | /context | No |
| GET | /context/clients | No |
| GET | /context/categories | No |
| GET | /settings | No |
| PUT | /settings | Yes |
| PATCH | /settings | Yes |
| GET | /operations/{command} | No |
| GET | /assessments | No |
| POST | /assessments | No |
| GET | /assessments/{assessment} | No |
| PUT | /assessments/{assessment} | No |
| PATCH | /assessments/{assessment} | No |
| DELETE | /assessments/{assessment} | Yes |
| GET | /assessments/{assessment}/context | No |
| GET | /assessments/{assessment}/items | No |
| POST | /assessments/{assessment}/items | No |
| POST | /assessments/{assessment}/approve | Yes |
| GET | /assessments/{assessment}/pdf | No |
| GET | /items/{item} | No |
| PUT | /items/{item} | No |
| PATCH | /items/{item} | No |
| DELETE | /items/{item} | Yes |
| GET | /items/{item}/context | No |
| GET | /items/{item}/updates | No |
| POST | /items/{item}/updates | No |
| GET | /updates/{update} | No |
| DELETE | /updates/{update} | Yes |

## Discovery, filters and read-back

Context returns effective configured defaults, enums, scoring bounds, maximum page size, relevant
lookup URLs and current actor/token capabilities. Record context adds the revision and locked fields.
Client lookup returns active Client IDs/names only. Category lookup prefers risk/risk_item categories,
falling back to all categories when neither type exists.

Assessment list supports q, scope, client_id, work_context_id, context_type, status, sort, direction,
page and per_page. Sort supports created_at, updated_at, title and status. Conflicting explicit
scope/context identities are rejected. Use scope=internal/client to include legacy rows without a
saved Work Context; context_type filters the stored Work Context relationship.

Lists default to 15 entries, accept at most 100 per page and return total/last-page metadata.
Follow all pages for completeness. Independent item/history lists are available; the older embedded
item/history resources remain complete. History order is created_at then ID.

GET does not initialize defaults/Work Context, change a browser workspace, create receipts or start
provider work. Deleted parents close every alternative child read path.

## Create and update

Create an assessment with title, optional description, scope/client_id and a supported starting
status (new/open/in_progress). Omitted fields use configured defaults. A supplied Client without
scope implies client scope at creation; explicit internal scope cannot include a Client.
Client scope requires an active Client. Work Context is derived by Nexum.

Metadata PUT/PATCH supports title, description, scope and client_id. Omitted metadata is preserved;
send null to clear nullable fields. When switching to internal scope, send client_id=null.
Status, approval author/time, score and Work Context IDs cannot be injected through metadata.

Create items with title and optional description, recommended_actions, conclusion, category_id,
likelihood, impact, status and next_review_at. Likelihood and impact are 1-5; score is their product.
Creation writes exactly one initial history row. Omitted review date uses the configured default;
explicit null means no review date.

With history present, descriptive edits cannot change likelihood, impact or status. Changed locked
fields return 422 and none of that request is saved. Same-current hidden values are accepted.
Use POST items/{item}/updates with note, status and optional likelihood/impact/next_review_at for
historical changes. Omitted date remains unchanged; null clears it. A descriptive change records one
audit note; no-op editing and replay do not create duplicates. Unknown mutation keys are rejected.

## Approval, history deletion and soft deletion

Approve only a nonempty active item set where every status is mitigated or accepted. Open and unknown
legacy statuses block approval; Nexum does not silently normalize them. Already current approval
does not restamp the approver/time.

Changed item/history content reopens an approved assessment to in_progress. Its last stamp remains
historical evidence; check approval_valid and current status. All browser/API writes share the same
locking order to prevent approval racing a changed child.

History deletion selects newest remaining created_at/ID deterministically and restores that state.
Deleting the last history row keeps the current snapshot but leaves zero history. It invents no
baseline or owner. The response includes the remaining item snapshot when that parent is still active.

Assessment/item DELETE is soft only. No force-delete, cascade, restore or new retention policy is
provided. A replay of accepted deletion returns its identity/tombstone after current rights checks.
Children/history remain stored but cannot be read under a deleted parent.

## Revisions, stable command keys and safe retries

1. GET the target (or settings) and capture its revision.
2. Choose a new stable Idempotency-Key, 8-200 printable ASCII characters, for one logical command.
3. Send the revision as If-Match and keep the exact method, URL, body, revision and key together.
4. Inspect the returned data and operation.id/replayed. New approval/delete/settings commands require
   both headers. Legacy ordinary CRUD accepts them optionally.
5. After a transport uncertainty, retry that exact command/key. Never invent another key merely
   because the first response was lost.
6. If the first command succeeded, replay returns its operation identity and current authorized state,
   without another effect. GET operations/{command} exposes minimal recorded outcome/IDs to the same
   actor and token, with current original-operation authority.
7. A changed request under an existing key returns 409. A stale revision also returns 409: reload,
   inspect the concurrent change and deliberately choose a new logical command only when appropriate.

Revisions include related state and detect changes within the same second. Commands, history and
local effects are atomic. Without a supplied key, legacy CRUD has no durable replay guarantee.
A receipt does not bypass revoked rights, a deleted active parent or changed ownership. If the target
was later deleted, resource read-back can return 404; do not retry creation with a new key.

## PDF and settings

GET assessments/{assessment}/pdf returns application/pdf inline with a filename. It uses the same
snapshot, template, grouping, history and thresholds as browser export. No caller URL, path or
template is accepted. Errors are JSON.

Settings use the same six keys as **Admin -> Risk Settings**. PUT requires all six; PATCH preserves
omitted keys. Both require a key/revision. Changes affect future defaults only. See **Risk Settings**
for each range and the exact browser procedure.

## Troubleshooting

- 401: authenticate with a valid unexpired API key.
- 403: check active account, token ability, current employee permission and creator/Superuser rule.
  A capability discovered earlier is not a permanent grant.
- 404: verify identity, active parent and original actor/token ownership for command receipts.
- 409: inspect stale revision, conflicting command key or unaddressed approval items before retrying.
- 422: inspect structured field errors, unsupported keys, Client scope, score bounds or locked fields.
- 428: read the current target and supply If-Match.
- 503: the Risk command storage/schema is unavailable; ask the deployment owner to check the exact
  Risk migration. Do not delete receipts or bypass the lock.

Open Swagger from API Management for typed request/response/error schemas. No provider activation,
notification setup or scheduling is required for these local operations.
