The Sales API covers the existing Sales workflows for trusted integrations and authorized automation.

## Availability and prerequisites

The complete 75-operation interface described here is implemented on **Dev**. Svein Tore approved
the delivered scope and Main promotion on 2026-10-25; **HR-2026-10-24-SALES-API** is Reviewed.
Production deployment and provider activation remain separate. Confirm the target installation's Swagger before using a new operation;
this handbook update does not activate delivery or give an account additional access.

1. Ask an authorized administrator to open **Admin -> Integrations -> API**.
2. Create a key with only the required Sales abilities. Keep the token in approved secret storage.
3. Use an active employee account with the same Sales permissions required in the browser.
   A token ability does not replace current user/record/field rights.
4. Open **API documentation** and check the exact request, response and error schema.
5. Use a designated test customer/quote for acceptance. Real customer email or acceptance requires
   the explicit operational authorization for that customer and recipient.

Staff endpoints live below `/api/v1/sales` and require `Authorization: Bearer {token}` and
`Accept: application/json`. Existing public quote capabilities and Customer Portal sessions use
separate identities; a staff key cannot impersonate a customer.

## Abilities and current user permissions

| Work | Token ability | Current browser permission |
|---|---|---|
| Opportunities, dashboard and context | `sales.read` | `sales.view` |
| Create/update/lost/reopen opportunity | `sales.create`, `sales.update` | `sales.opportunity_manage` |
| Timeline/stakeholders/read actions | `sales.read`, `sales.update` | `sales.view` |
| Leads/classify/start | `sales.leads.read`, `sales.leads.manage` | `sales.lead_manage` |
| Sales inline customer/contact creation | `sales.clients.create`, `sales.contacts.create` | Existing Sales access plus the owning Client/Contact rules |
| Quote/version/PDF/snapshot reads | `sales.quotes.read` | `sales.view` |
| Draft/line/CPQ/revision/template/conversion plan | `sales.quotes.manage` | `sales.quote_manage` |
| Quote preview/send | `sales.quotes.send` | `sales.quote_manage` |
| Internal approval decisions | `sales.quotes.approve` | `sales.quote.approve` |
| Outbound timeline email preview/send | `sales.email.send` | `sales.view` |
| Own operation and delivery read-back | `sales.operations.read` | Current Sales and operation-specific authority |
| Dispatch a never-started queued attempt | `sales.delivery.dispatch` plus the relevant send ability | Current operation-specific authority |
| Approval settings/reporting | `sales.settings.read`, `sales.settings.manage`, `sales.reports.read` | `sales.manage_settings` through the current administrative policy |
| Quote template catalog/maintenance | `sales.quote_templates.read`, `sales.quote_templates.manage` | `sales.manage_settings` |

No role, key or runtime flag is expanded automatically. The administrative middleware follows current
permissions; the label Admin is not a new hardcoded requirement to hold a particular role name.

## Read and prepare a Sales operation

1. Read `GET /api/v1/sales/context` or the opportunity's `/context` for allowed types/statuses,
   next actions, active owners, customers/sites/contacts, templates and source catalog choices.
2. For bounded references use `client_page`, `owner_page`, `site_page`, `contact_page` and
   `template_page`. Read totals/completeness instead of assuming the first page contains everything.
   Lead and ordinary list APIs accept `page` and `per_page` (maximum 100).
3. Read the exact opportunity or quote version. Keep its `revision` for the next command.
4. Submit only supported fields. Unknown root or nested selection fields return 422.
5. Read the returned exact record and operation ID. Use a separate GET to verify the resulting state.

GET/context/PDF endpoints do not initialize data, grant permissions or record a quote view. Context
can return `setup_required`; an authorized operator can use the explicit `POST /sales/initialize`
data-only command when the installation needs defaults. Installation permission seeding remains a
separate existing setup responsibility.

## Keys, revisions and safe retries

New effectful commands require `Idempotency-Key` (8-200 characters). Use one stable key per reviewed
intent and keep it with the original input. Existing opportunity/activity/template paths retain
legacy keyless compatibility; supplying a key enforces replay. Never automatically retry a keyless
write. An internal-note notification is a new external effect and needs an explicit keyed intent.

Commands changing an existing opportunity/quote/lead require the current `If-Match` revision where
Swagger marks it required. Send previews and execution are bound to exact reviewed state. Repeating
the same key, path, input and original revision returns the original result identity without another
effect; changed input/key reuse or stale state returns 409. A missing required revision returns 428.
Re-read and review after 409; do not blindly replace the revision and repeat a commercial command.
Actor and object rights are checked again before replay.

PATCH updates document required fields and omitted/null/false/empty-list meanings. Omitting optional
draft acknowledgement/group/cost data preserves the existing value; explicitly clearing optional
text clears it. Calculated prices/totals, actors, approval evidence, customer identities, provider
credentials, secure tokens and accepted snapshots are not caller-writeable fields.

## Complete quote workflow

1. Find/start a lead or create an opportunity. Link a real customer and an eligible primary contact.
2. Read the opportunity revision; call `/opportunities/{opportunity}/quote/draft` with key/revision.
3. Read the returned version and revision. Add catalog/custom lines, section/downstream/cadence,
   quantities, prices/discount/VAT, required/optional/default selections, option groups and quantity
   limits through the version's `/lines` endpoint.
4. Edit `/details` for customer text, expiry and quote acknowledgement. Line fields can also carry
   line-specific acknowledgement text. Applying `/template` snapshots an active template; subsequent
   template edits do not rewrite the quote.
5. Request `/approval/request` when needed. An authorized approver uses `/approval/approve`,
   `/approval/reject` or `/approval/changes`. Changes to draft content invalidate a stale approval.
6. Call `/send-preview`; review the exact version, recipient/CC, subject/body and readiness reasons.
7. Submit `/send` with the returned `preview_id`, `preview_hash`, current revision and a new intent
   key. Changed content, recipient, approval policy or provider requires a new review.
8. Read the quote and `/operations/{operation_id}`. A commercial mark-as-sent, queue submission and
   confirmed SMTP submission are separate states. A missing recipient is reported explicitly.
9. Customers respond through their exact public quote link or active company-level portal membership.
   Options, quantities, required lines/groups and acknowledgements are validated on acceptance.
10. Read immutable acceptance and conversion-plan records. Updating a conversion plan's status,
    reference or note does not create or prove downstream fulfilment. Ticket/Storage/Commercial/
    Economy keep their own guarded delivery actions.

Sent/accepted quotes remain immutable. `/revise` follows the existing sent/negotiation rule and
creates a new current draft while preserving the old version. Superseded/expired/declined quotes
cannot be accepted. Existing Ticket-origin manual acceptance and void remain Ticket-owned actions.

## Activity email and internal notes

Use the opportunity's `/emails/preview` and `/emails` for reviewed outbound Sales email. The preview
accepts a customer-bound `recipient_contact_id` or explicit `to_email`, subject/body and a string of
valid comma/semicolon-separated CC addresses. Invalid addresses are rejected rather than discarded.
Frozen jobs use the reviewed recipient/content/provider even if contact data changes afterward.

Use `/activities` for journal/internal_note/email_in. An internal note can identify an active
`notify_user_id`; it remains internal and produces its own safe delivery status. Use the exact
activity `/read` or opportunity `/read` operation for the current shared Sales unread workflow.

## Delivery evidence and recovery

Read `state`, `error_code`, `started_at`, `finished_at` and `message_id` from the delivery result.
Possible states include preview, queued, processing, no_recipient, failed, unknown and smtp_accepted.
`inbox_receipt_verified` remains false: queued work or SMTP acceptance alone is not inbox delivery.
Preview content is encrypted at rest; provider credentials are never exposed through the API.

The explicit operation `/dispatch` command only redispatches a queued attempt that has never started.
Duplicate jobs claim the persisted attempt once. A started/failed/unknown attempt is not automatically
resent. For an unknown SMTP outcome, an authorized operator must reconcile the provider/mailbox and
record the outcome before deliberately reviewing a new send intent. Creating a new key alone is
not evidence that the first email failed. This API does not introduce automatic customer mail.

## Customer and provider boundaries

Public quote APIs are limited to one exact existing quote capability. Portal APIs reuse the existing
signed-in user, active membership/contact and company/site scope with normal CSRF protection on
mutations; no staff-supplied portal account/contact IDs are accepted. Customer JSON/PDF excludes
cost/margin, supplier/internal notes, approval internals, raw metadata and storage paths. Viewing via
API is a pure GET; the `/viewed` command explicitly records the existing lifecycle.

Sales inline creation delegates to Client/Contact. When provider-authoritative Tripletex numbering
is active, use `tripletex_number_mode=true`, one persistent `tripletex_request_key` UUID and omit
`client_number`. Unknown attempts remain durable and need reconciliation; never switch to a local
number or a new UUID to conceal an uncertain provider outcome. A contact email is not automatically
copied to Billing Email. Canonical Contact and stable Sales bridge IDs are returned together.

## Endpoint index

The exact closed request/response/error contracts are in Swagger. `{opportunity}` is the public
Sales opportunity key; version/line/plan IDs must belong to that exact parent. Pagination and safe
read-back apply throughout. The table lists every supported HTTP operation, excluding automatic HEAD.

| Method | Path below `/api/v1/sales` | Purpose |
|---|---|---|
| GET | `/context` | context |
| GET | `/dashboard` | dashboard |
| GET | `/opportunities` | opportunities index |
| POST | `/opportunities` | opportunities store |
| GET | `/opportunities/{opportunity}` | opportunities show |
| GET | `/opportunities/{opportunity}/context` | opportunities context |
| PUT | `/opportunities/{opportunity}` | opportunities replace |
| PATCH | `/opportunities/{opportunity}` | opportunities update |
| POST | `/opportunities/{opportunity}/lost` | opportunities lost |
| POST | `/opportunities/{opportunity}/reopen` | opportunities reopen |
| GET | `/opportunities/{opportunity}/activities` | opportunities activities index |
| POST | `/opportunities/{opportunity}/activities` | opportunities activities store |
| POST | `/opportunities/{opportunity}/read` | opportunities read |
| POST | `/opportunities/{opportunity}/activities/{activity}/read` | opportunities activities read |
| GET | `/opportunities/{opportunity}/stakeholders` | opportunities stakeholders index |
| POST | `/opportunities/{opportunity}/stakeholders` | opportunities stakeholders store |
| GET | `/leads` | leads index |
| GET | `/leads/context` | leads context |
| GET | `/leads/{lead}` | leads show |
| PATCH | `/leads/{lead}/classification` | leads classification |
| POST | `/leads/{lead}/start` | leads start |
| POST | `/clients/quick-create` | clients quick-create |
| POST | `/clients/{client}/contacts/quick-create` | contacts quick-create |
| GET | `/opportunities/{opportunity}/quotes` | quotes index |
| GET | `/opportunities/{opportunity}/quote/versions` | quote-versions index |
| GET | `/opportunities/{opportunity}/quote/versions/{version}` | quote-versions show |
| GET | `/opportunities/{opportunity}/quote/versions/{version}/customer-preview` | quote-versions customerPreview |
| GET | `/opportunities/{opportunity}/quote/versions/{version}/acceptance` | quote-versions acceptance |
| GET | `/opportunities/{opportunity}/quote/versions/{version}/conversion-plans` | quote-versions plans |
| GET | `/opportunities/{opportunity}/quote/versions/{version}/pdf` | quote-versions pdf |
| POST | `/opportunities/{opportunity}/quote/draft` | quote draft |
| PATCH | `/opportunities/{opportunity}/quote/versions/{version}/details` | quote-versions details |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/lines` | quote-versions addLine |
| PATCH | `/opportunities/{opportunity}/quote/versions/{version}/lines/{line}` | quote-versions updateLine |
| DELETE | `/opportunities/{opportunity}/quote/versions/{version}/lines/{line}` | quote-versions deleteLine |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/revise` | quote-versions revise |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/template` | quote-versions template |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/approval/request` | quote-versions approval request |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/approval/approve` | quote-versions approval approve |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/approval/reject` | quote-versions approval reject |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/approval/changes` | quote-versions approval changes |
| PATCH | `/opportunities/{opportunity}/quote/versions/{version}/conversion-plans/{plan}` | quote-versions conversion-plans update |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/send-preview` | quote-versions send-preview |
| POST | `/opportunities/{opportunity}/quote/versions/{version}/send` | quote-versions send |
| POST | `/opportunities/{opportunity}/emails/preview` | opportunities emails emailPreview |
| POST | `/opportunities/{opportunity}/emails` | opportunities emails emailSend |
| GET | `/operations/{operation}` | operations show |
| POST | `/operations/{operation}/dispatch` | operations dispatch |
| GET | `/settings/approval-policy` | settings approval-policy |
| PATCH | `/settings/approval-policy` | settings approval-policy update |
| GET | `/reports/quotes` | reports quotes |
| POST | `/initialize` | initialize |
| GET | `/quote-template-catalog` | quote-templates catalog |
| GET | `/quote-templates` | quote-templates index |
| POST | `/quote-templates` | quote-templates store |
| GET | `/quote-templates/{template}` | quote-templates show |
| PUT | `/quote-templates/{template}` | quote-templates update |
| DELETE | `/quote-templates/{template}` | quote-templates destroy |
| POST | `/quote-templates/{template}/lines` | quote-templates lines store |
| DELETE | `/quote-templates/{template}/lines/{line}` | quote-templates lines destroy |
| POST | `/quote-templates/{template}/acknowledgements` | quote-templates acknowledgements store |
| DELETE | `/quote-templates/{template}/acknowledgements/{acknowledgement}` | quote-templates acknowledgements destroy |
| GET | `/public/quotes/{token}` | public quotes show |
| GET | `/public/quotes/{token}/pdf` | public quotes pdf |
| POST | `/public/quotes/{token}/viewed` | public quotes viewed |
| POST | `/public/quotes/{token}/accept` | public quotes accept |
| POST | `/public/quotes/{token}/decline` | public quotes decline |
| POST | `/public/quotes/{token}/questions` | public quotes question |
| GET | `/portal/quotes` | portal quotes index |
| GET | `/portal/quotes/{version}` | portal quotes show |
| GET | `/portal/quotes/{version}/pdf` | portal quotes pdf |
| POST | `/portal/quotes/{version}/viewed` | portal quotes viewed |
| POST | `/portal/quotes/{version}/accept` | portal quotes accept |
| POST | `/portal/quotes/{version}/decline` | portal quotes decline |
| POST | `/portal/quotes/{version}/questions` | portal quotes question |

## Troubleshooting and release status

- 401: sign in with the correct identity or supply an installation-approved staff token.
- 403: check both token ability and current user/record/administrator/approver rights. Do not grant
  wildcard access to hide the failure.
- 404: verify the exact parent, quote capability or current portal company/site membership.
- 409/428: read the current record and review its state/revision; preserve the original command key.
- 422: read field errors, current context choices and required CPQ acknowledgements/quantity bounds.
- No delivery: distinguish no_recipient, missing account/template, provider binding change, queue
  execution and failed/unknown SMTP evidence. Never report inbox receipt from a queue response.

Dev requires the additive Sales command/delivery migration and current worker code. Production
rollout, named human acceptance and controlled real-provider/customer validation are separate gates.
No release, real mail, customer acceptance or production capability activation is claimed here.

Main merge confirmed on 2026-10-25: [Sales API PR #311](https://github.com/SveinT83/Nexum-PSA/pull/311).
The reviewed source is in Main. Production deployment, migrations and provider activation
have not been performed by this update.
