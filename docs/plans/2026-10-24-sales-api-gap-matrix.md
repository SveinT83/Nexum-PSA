# Sales Browser/API Gap Matrix

Status: Implemented on Dev; automated acceptance and human review tracked in the verification report.
Date: 2026-10-24. Owner: Codex. Approval owner: Svein Tore.
Parent: ../rfc/2026-10-24-sales-full-api-parity.md.
Inventory: 2026-10-24-sales-api-inventory.json.

## Historical baseline and completion definition

Authoritative Dev has 53 browser routes: 52 controller operations and one compatibility redirect.
The API has 18 route rows / 19 HTTP method/path operations excluding HEAD. Eight Sales-source
OpenAPI operations match; PUT and ten template methods lack a matching Sales-source attribute.
The inventory separately records the current generated document. Registration is not runtime proof.
No Sales Livewire class or wire action was found in the current Sales views.

Every supported row needs equivalent actor/record/field authorization, complete input/side effects,
typed success/error/read-back, OpenAPI, meaningful tests and employee documentation. Partial means
a corresponding API exists but full parity has not passed these gates. Endpoint counts are not a
coverage percentage. Redirects map to their destination, not an invented independent workflow.

## Every registered browser route

| Browser method/path | Action | Baseline | Implemented API | Required behavior/gap at baseline |
|---|---|---|---|---|
| `GET /portal/quotes` | `PortalSalesQuoteController@index` | Missing | `GET /api/v1/sales/portal/quotes` | Existing portal identity/membership; company scope and site restrictions. |
| `GET /portal/quotes/{quote}` | `PortalSalesQuoteController@show` | Missing | `GET /api/v1/sales/portal/quotes/{version}; POST /api/v1/sales/portal/quotes/{version}/viewed` | Customer-safe JSON/receipt/history; effective expiry; explicit lifecycle command and pure GET. |
| `POST /portal/quotes/{quote}/accept` | `PortalSalesQuoteController@accept` | Missing | `POST /api/v1/sales/portal/quotes/{version}/accept` | Same confirm/selections/quantities/acknowledgements and immutable snapshot; verified identity, audit and owner side effects. |
| `POST /portal/quotes/{quote}/decline` | `PortalSalesQuoteController@decline` | Missing | `POST /api/v1/sales/portal/quotes/{version}/decline` | Same reason/terminal-state/lifecycle behavior and exact scoped receipt. |
| `POST /portal/quotes/{quote}/question` | `PortalSalesQuoteController@question` | Missing | `POST /api/v1/sales/portal/quotes/{version}/questions` | Exact quote-bound activity/unread state; identity/audit from current customer context. |
| `POST /quote/accept/{token}` | `PublicQuoteController@accept` | Missing | `POST /api/v1/sales/public/quotes/{token}/accept` | Same confirm/selections/quantities/acknowledgements and immutable snapshot; verified identity, audit and owner side effects. |
| `POST /quote/decline/{token}` | `PublicQuoteController@decline` | Missing | `POST /api/v1/sales/public/quotes/{token}/decline` | Same reason/terminal-state/lifecycle behavior and exact scoped receipt. |
| `GET /quote/pdf/{token}` | `PublicQuoteController@pdf` | Missing | `GET /api/v1/sales/public/quotes/{token}/pdf` | Same customer PDF/snapshot/ETag; no staff-only fields. |
| `POST /quote/question/{token}` | `PublicQuoteController@question` | Missing | `POST /api/v1/sales/public/quotes/{token}/questions` | Exact quote-bound activity/unread state; identity/audit from current customer context. |
| `GET /quote/view/{token}` | `PublicQuoteController@view` | Missing | `GET /api/v1/sales/public/quotes/{token}; POST /api/v1/sales/public/quotes/{token}/viewed` | Customer-safe JSON/receipt/history; effective expiry; explicit lifecycle command and pure GET. |
| `GET /tech/admin/settings/sales/quote-templates` | `SalesSettingsController@workflows` | Partial | `GET /api/v1/sales/quote-templates; GET /api/v1/sales/reports/quotes` | Existing template list; reporting counts missing. |
| `POST /tech/admin/settings/sales/quote-templates` | `SalesSettingsController@storeTemplate` | Partial | `POST /api/v1/sales/quote-templates` | Existing shared workflow; actor/admin, strict fields and durable replay. |
| `GET /tech/admin/settings/sales/quote-templates/create` | `SalesSettingsController@createTemplate` | Partial | `GET /api/v1/sales/quote-template-catalog` | Existing catalog; current actor/admin, pure reads and typed schemas needed. |
| `PUT /tech/admin/settings/sales/quote-templates/{template}` | `SalesSettingsController@updateTemplate` | Partial | `PUT /api/v1/sales/quote-templates/{template}` | Existing workflow; field/schema/stale-edit contracts. |
| `DELETE /tech/admin/settings/sales/quote-templates/{template}` | `SalesSettingsController@destroyTemplate` | Partial | `DELETE /api/v1/sales/quote-templates/{template}` | Preserve soft deletion; avoid read reseeding; exact receipt. |
| `POST /tech/admin/settings/sales/quote-templates/{template}/acknowledgements` | `SalesSettingsController@storeTemplateAcknowledgement` | Partial | `POST /api/v1/sales/quote-templates/{template}/acknowledgements` | Existing workflow; field/parent rights and exact persisted result. |
| `DELETE /tech/admin/settings/sales/quote-templates/{template}/acknowledgements/{acknowledgement}` | `SalesSettingsController@destroyTemplateAcknowledgement` | Partial | `DELETE /api/v1/sales/quote-templates/{template}/acknowledgements/{acknowledgement}` | Existing parent guard; complete actor/schema/replay coverage. |
| `GET /tech/admin/settings/sales/quote-templates/{template}/edit` | `SalesSettingsController@editTemplate` | Partial | `GET /api/v1/sales/quote-templates/{template}; GET /api/v1/sales/quote-template-catalog` | Existing detail/catalog; complete rights/revision/schema coverage. |
| `POST /tech/admin/settings/sales/quote-templates/{template}/lines` | `SalesSettingsController@storeTemplateLine` | Partial | `POST /api/v1/sales/quote-templates/{template}/lines` | Existing line/group workflow; catalog rights, nested input and typed read-back. |
| `DELETE /tech/admin/settings/sales/quote-templates/{template}/lines/{line}` | `SalesSettingsController@destroyTemplateLine` | Partial | `DELETE /api/v1/sales/quote-templates/{template}/lines/{line}` | Parent guard exists; actor/schema/replay/read-back needed. |
| `GET /tech/admin/settings/sales/rules` | `SalesSettingsController@rules` | Missing | `GET /api/v1/sales/settings/approval-policy` | Admin plus sales.manage_settings; no initialization during query. |
| `POST /tech/admin/settings/sales/rules` | `SalesSettingsController@updateRules` | Missing | `PATCH /api/v1/sales/settings/approval-policy` | Enabled/discount/margin/total/manual-line thresholds and read-back. |
| `GET /tech/admin/settings/sales/workflows` | `Closure` | Compatibility redirect | `GET /api/v1/sales/quote-templates` | Old URL redirects to template page; no independent business action. |
| `GET /tech/sales` | `SalesController@index` | Partial | `GET /api/v1/sales/opportunities; GET /api/v1/sales/dashboard` | Lost defaults, unread/overdue ordering, stats and bounded filters differ. |
| `POST /tech/sales` | `SalesController@store` | Partial | `POST /api/v1/sales/opportunities` | Current actor rights, strict fields, durable replay and full read-back. |
| `POST /tech/sales/clients/quick-store` | `SalesController@quickStoreClient` | Missing | `POST /api/v1/sales/clients/quick-create` | Client-owned creation; provider-authoritative numbering and unknown-outcome reconciliation. |
| `POST /tech/sales/clients/{client}/contacts/quick-store` | `SalesController@quickStoreContact` | Missing | `POST /api/v1/sales/clients/{client}/contacts/quick-create` | Canonical Contact owner/legacy Sales bridge; no portal identity creation. |
| `GET /tech/sales/create` | `SalesController@create` | Missing | `GET /api/v1/sales/context` | Permitted clients/sites/contacts/owners/types/statuses/next actions; pure query. |
| `GET /tech/sales/leads` | `LeadsController@index` | Missing | `GET /api/v1/sales/leads; GET /api/v1/sales/leads/context` | Contract exclusion, marketing summary, filters, grouping and sorting. |
| `GET /tech/sales/leads/{lead}` | `LeadsController@show` | Missing | `GET /api/v1/sales/leads/{lead}` | Same Client-backed lead semantics with safe relationships. |
| `PATCH /tech/sales/leads/{lead}/classification` | `LeadsController@updateClassification` | Missing | `PATCH /api/v1/sales/leads/{lead}/classification` | Category/temperature/website and Sales-module tag behavior. |
| `POST /tech/sales/leads/{lead}/start` | `LeadsController@start` | Missing | `POST /api/v1/sales/leads/{lead}/start` | Same StoreSalesOpportunity and follow-up engine. |
| `GET /tech/sales/{sale}` | `SalesController@show` | Partial | `GET /api/v1/sales/opportunities/{opportunity}; GET /api/v1/sales/opportunities/{opportunity}/context` | Current resource lacks quote/version, stakeholder, approval and conversion detail. |
| `PATCH /tech/sales/{sale}` | `SalesController@update` | Partial | `PUT/PATCH /api/v1/sales/opportunities/{opportunity}` | Strict omitted/null fields, stale writes, contact ownership and follow-up parity. |
| `POST /tech/sales/{sale}/activities` | `SalesController@storeActivity` | Partial | `POST /api/v1/sales/opportunities/{opportunity}/activities; POST /api/v1/sales/opportunities/{opportunity}/emails/preview; POST /api/v1/sales/opportunities/{opportunity}/emails` | email_out and internal-note notify_user_id are missing; freeze recipients and content. |
| `POST /tech/sales/{sale}/activities/{activity}/read` | `SalesController@markActivityRead` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/activities/{activity}/read` | Parent guard and opportunity unread recomputation. |
| `POST /tech/sales/{sale}/lost` | `SalesController@markLost` | Partial | `POST /api/v1/sales/opportunities/{opportunity}/lost` | Existing shared action; add complete actor/replay/stale-state contract. |
| `POST /tech/sales/{sale}/quote` | `SalesController@ensureQuote` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/draft` | Use existing draft/revision/additional-quote owner action. |
| `POST /tech/sales/{sale}/quote/approval/approve` | `SalesController@approveQuote` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/approval/approve` | Current sales.quote.approve plus explicit token ability. |
| `POST /tech/sales/{sale}/quote/approval/changes` | `SalesController@requestQuoteChanges` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/approval/changes` | Same approver authority; changes_requested and reason. |
| `POST /tech/sales/{sale}/quote/approval/reject` | `SalesController@rejectQuote` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/approval/reject` | Same approver authority, audited note/state and safe replay. |
| `POST /tech/sales/{sale}/quote/approval/request` | `SalesController@requestQuoteApproval` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/approval/request` | Recalculate/evaluate policy; return audited pending/not_required. |
| `POST /tech/sales/{sale}/quote/conversion-plans/{plan}` | `SalesController@updateConversionPlan` | Missing | `GET /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/conversion-plans; PATCH /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/conversion-plans/{plan}` | Status/reference/operator note only; no fabricated downstream delivery. |
| `PATCH /tech/sales/{sale}/quote/details` | `SalesController@updateQuoteDetails` | Missing | `PATCH /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/details` | Customer text/expiry/acknowledgement; draft-only and approval reset. |
| `POST /tech/sales/{sale}/quote/lines` | `SalesController@addQuoteLine` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/lines` | Catalog/custom sources, group/options, quantity bounds, acknowledgement and recalculation. |
| `PATCH /tech/sales/{sale}/quote/lines/{line}` | `SalesController@updateQuoteLine` | Missing | `PATCH /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/lines/{line}` | Exact version/parent, CPQ fields, omitted/null semantics and approval reset. |
| `DELETE /tech/sales/{sale}/quote/lines/{line}` | `SalesController@deleteQuoteLine` | Missing | `DELETE /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/lines/{line}` | Draft-only guarded delete, recalculation and exact receipt. |
| `POST /tech/sales/{sale}/quote/revise` | `SalesController@reviseQuote` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/revise` | Same negotiation/sent rule; old version immutable and superseded. |
| `POST /tech/sales/{sale}/quote/send` | `SalesController@sendQuote` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/send-preview; POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/send; GET /api/v1/sales/operations/{operation}` | Approval gate; freeze version/content/recipient/provider; business and delivery state separate. |
| `POST /tech/sales/{sale}/quote/template` | `SalesController@applyQuoteTemplate` | Missing | `POST /api/v1/sales/opportunities/{opportunity}/quote/versions/{version}/template` | Active template snapshot; replace_existing; immutable sent/accepted history. |
| `POST /tech/sales/{sale}/read` | `SalesController@markRead` | Partial | `POST /api/v1/sales/opportunities/{opportunity}/read` | Existing all-activity read; add actor/strict-input/exact-result checks. |
| `POST /tech/sales/{sale}/reopen` | `SalesController@reopen` | Partial | `POST /api/v1/sales/opportunities/{opportunity}/reopen` | Existing shared action; preserve reason/history/calendar semantics. |
| `POST /tech/sales/{sale}/stakeholders` | `SalesController@storeStakeholder` | Missing | `GET/POST /api/v1/sales/opportunities/{opportunity}/stakeholders` | Client-bound contact, primary change, role and notes. |

## Field and read contracts beyond routes

- Discover permitted clients/sites/canonical contacts and legacy bridge IDs, owners, types/statuses,
  next actions, cadences, CPQ choices, templates and Commercial/Storage catalog sources with bounds.
- Add explicit paginated activity, stakeholder, quote list, version list/detail, acceptance snapshot,
  conversion-plan, lifecycle and delivery-operation reads. Current opportunity resource omits these.
- Staff quote JSON/PDF preview is separate from public/portal customer projection. Customers must
  not receive cost/margin, supplier/provider data, private notes, approval internals, storage paths
  or unrestricted activity metadata. Public links remain capability-bearing data.
- GET/context/PDF must not seed settings/templates/permissions or record viewed/expired state.
  Project effective expiry and readiness; use explicit guarded lifecycle/setup commands instead.
  Preserve the browser's existing lifecycle through shared commands.
- Cover every supported browser CPQ field: sources/snapshots; groups/min/max; required/optional/
  recommended/default flags; quantity bounds; line/quote acknowledgements; cadences/totals;
  customer copy/expiry; replace_existing; approval reset; conversion reference/note.
- Strict mutation fields, including nested arrays; document omitted/null/false/empty semantics.
  Reject actor, secure-token, ownership, audit, snapshot, calculated-total and downstream-ID overrides.

## Acceptance evidence

Final implementation: all 53 browser rows above map to registered API paths.
The frozen contract in `app/Modules/Sales/Docs/api-contract.json` contains 75
HTTP operations: 62 staff and 13 public/portal operations. Supplementary discovery,
read-back, preview and delivery recovery methods complete the non-route workflows.
Typed input/output schemas live in `api-schemas.json`; contract tests compare every
method, middleware and closed mutation field against registration and generated Swagger.
See [verification](2026-10-24-sales-api-verification.md) for runtime evidence.
Human acceptance remains HR-2026-10-24-SALES-API; Main/production is a separate gate.

The following paragraph retains the agreed completion criteria:

Freeze route/method/field contracts after RFC approval. Compare all rows to registered operations,
generated Swagger and actual response/error behavior; include non-route view behavior and any later
UI extension in the owning slice. No missing/partial/unauthorized/undocumented supported workflow
may remain when claiming full coverage. The existing Sales suite passes 29 tests / 453 assertions;
that baseline does not certify the proposed API. Main/production availability is a separate claim.

## Human acceptance (2026-10-25)

Svein Tore approved the delivered scope and Main merge in chat: "Godkjent. Merg til main".
HR-2026-10-24-SALES-API is Reviewed. The earlier pending-review wording records the
2026-10-24 implementation handoff; this acceptance clears the Main merge gate.
Production deployment, provider activation and real message delivery remain separate.
