# Economy Browser/API Gap Matrix

Status: All E01-E14 Done On Dev; HR-2026-10-25-ECONOMY-API Reviewed by Svein Tore, 2026-10-25.
Date: 2026-10-25
Owner: Codex. Product/release owner: Svein Tore.
RFC: ../rfc/2026-10-25-economy-full-api-parity.md
Runtime inventory: 2026-10-25-economy-api-inventory.json

14 Economy-owned browser routes and seven Economy staff API routes are registered.
Do not turn this route ratio into a coverage percentage. Existing endpoints have field,
authorization, concurrency and side-effect gaps. Post-implementation evidence is recorded below.

| ID | Current supported browser workflow | API at initial audit | Initial gap / acceptance contract |
| --- | --- | --- | --- |
| E01 | GET tech/economy: orders, totals, status counters and generation period form | GET economy/orders | Shared authorized query, effective totals, all counters, bounded filters/paging, stable sort and discoverable period/action context; pure read. |
| E02 | GET tech/economy/orders/{order}: client/period/status/lines/totals/actor/source links | GET economy/orders/{order} | Complete typed staff fields, effective versus stored totals, publication state, authorized source links/actions, bounded complete line access and revision. |
| E03 | POST tech/economy/generate | POST economy/orders/generate | Shared all-source workflow, read-only impact preview, current generator policy, durable idempotency, native concurrency and exact truthful affected-record summary. |
| E04 | POST orders/{order}/ready: draft -> ready plus portal status notification | POST orders/{order}/ready | Shared state action, fresh revision/parent lock, authenticated actor, existing audience/preferences, deduplicated notification and operation read-back. |
| E05 | POST orders/{order}/draft: ready -> draft plus notification | POST orders/{order}/draft | Same guards/side-effect parity; cannot revert approved/exported/manual-invoiced. |
| E06 | POST orders/{order}/invoiced: ready/approved -> manual_invoiced | Missing | Explicit action/current order_manage, actor/read-back/notification; no external export/invoice/payment claim. |
| E07 | POST orders/{order}/portal-visibility: publish/hide | Missing | Client-only, strict boolean, exact publication author/time, shared audit, deduplicated new-publication notification and current rights. |
| E08 | DELETE orders/{order}: empty draft/ready | DELETE orders/{order} | Atomic empty/state check, current delete policy, race protection and scoped durable deletion receipt/replay. |
| E09 | DELETE orders/{order}/lines/{line}: draft source unlock/recalculate/remove empty order | DELETE orders/{order}/lines/{line} | Parent binding/current delete policy; all source types, source-owner reconciliation, preserve stock/financial customer, atomic totals/last-order deletion and tombstone. |
| E10 | POST tech/economy/export: existing Economy Orders Data Exchange profile | Generic Data Exchange profile/run/file APIs only | Economy context/preview/command for exact existing profile/filter behavior, actor/token/export checks, run/file/checksum/read-back, secure owner downloads, retry; no false exported state. |
| E11 | GET tech/admin/settings/economy | Missing | Admin + manage_settings, effective read-only fallback, every field/default/enum/discoverable capability and revision. |
| E12 | PATCH tech/admin/settings/economy | Missing | Every supported setting; shared validation, omission/false/zero/null semantics, safe retry/audit and exact settings read-back. |
| E13 | GET portal/orders | Missing | Existing portal-session identity/current membership/client scope, explicitly visible only, site exclusion, customer-safe totals/active lines/paging/context. |
| E14 | GET portal/orders/{order} | Missing | Exact same portal access, 404 for unavailable, safe reference/line/status/VAT projection; no staff metadata or mutations. |

API paths above omit /api/v1; browser order action paths omit tech/economy where unambiguous.
Every write/action includes strict allowlisted fields, explicit scope plus current actor/object rights,
revision/preview where appropriate, retry evidence and regression of equivalent browser behavior.

## Complete Settings Contract

Five booleans: create_orders_from_resolved_ticket_time,
create_orders_from_closed_ticket_time, include_unresolved_ticket_time_in_period_close,
create_orders_from_picked_ticket_costs, auto_pick_ticket_costs_on_resolved_or_closed_ticket.
Other fields: time_order_line_grouping (per_entry), order_line_text_format (ticket_date_text),
order_prefix (max 20), default_vat_rate (0..100, current nullable/default fallback semantics).
No invented unsupported grouping/text modes. PATCH omission preserves values; browser unchecked
checkboxes remain false through an explicit form adapter. Reading settings cannot persist defaults.

## Source And Financial Contracts

Generation includes Ticket time/cost, quick-timebank overuse and CloudFactory BillingPeriod sources.
Preserve recorded financial-client allocation after Ticket move/merge, chronological timebank use,
covered/partial/yearly periods, exact source/rate/currency/VAT snapshots and existing no-billing
Ticket outcomes. Deletion cannot reverse stock. Non-Ticket source eligibility/unlock must be
verified with the owner domain before changing semantics; source identity is never caller supplied.

CalculateOrderTotals currently computes effective web totals with default VAT fallback;
EconomyOrderResource emits stored totals. Expose both explicitly and reuse effective calculations
without rewriting historical values. Staff fields include status/publication/creator/updater and
all supported line fields. Customer fields contain only the browser-safe active-line projection.

## Permission And Ownership Map

- Staff read/status/visibility: economy.order_manage plus appropriate economy token ability.
- Generation: economy.generate_orders plus economy.create; installation-wide current scope must
  be explicit, with no token-only or implicit cross-client escalation.
- Deletion: economy.delete_orders plus economy.delete.
- Settings: Admin plus economy.manage_settings and dedicated tested API ability.
- Export: economy.view AND data_exchange.run, appropriate token abilities, scoped run/file read
  and data_exchange.download permission/ability for actual files; no owner/API bypass.
- Portal: existing CustomerPortal middleware/context and PortalEconomyAccess; no staff PAT access.
- Six browser Rates/Units routes contain economy in their URL but belong to Commercial. Discover
  their canonical owner APIs; do not duplicate mutations or count redirects as missing behavior.
- Ticket/Storage own registration/picking; Commercial owns rates/timebank consumption;
  Integration owns provider state; Data Exchange owns profiles/runs/files/delivery. No new invoice
  provider or unsupported approved/cancelled/exported write is invented for parity.

## Evidence And Completion Gate

Current Economy baseline passes 17 tests / 105 assertions. Existing tests do not prove full parity.
API ready/draft lacks web notifications; API list can persist settings/default VAT on GET.
Existing firstOrCreate counters and source/order creation must be tested under repeat/concurrency.
Dirty GenerateOrders changes from another workstream are preserved and fingerprinted in inventory.
GitHub search found no Economy-owned open Issue. No external update or application change occurred.

All rows are Done On Dev with evidence below. BookStack content/employee scope is verified.
Named human review is complete; the Main merge is authorized, production remains separate.

## Completed Acceptance Evidence

Every row is implemented in the exact method/path contract (23 operations, 34 schemas).
See economy-api-current-coverage.json, economy-api-test-evidence.json and the verification handoff.

| Row | Implemented acceptance / meaningful regression evidence |
| --- | --- |
| E01 | Pure staff reads; filtered stats/sort and bounded list/101-line paging; browser list regression. |
| E02 | Effective/stored totals, exact decimals, safe metadata, ETag/current revision/actions and full line paging. |
| E03 | Pure/stale preview, exact durable generation receipt; all four sources; quick/licence repeat/correction, no-billing and contract-anniversary allocation. |
| E04 | Shared ready state/actor/notification intent, stale revision and safe replay. |
| E05 | Shared draft transition, current rights and locked-state refusal. |
| E06 | Explicit manual-invoiced action; actor, current state/header checks and unchanged external export state. |
| E07 | Preview/publish/hide, audit, deduplicated intents and own operation/unknown notification evidence. |
| E08 | Empty-state guard, authorized durable deletion replay after parent removal. |
| E09 | Correct parent, draft-only, atomic last-order removal, all source correction and moved-client preservation; cost regression proves no stock reversal/repick. |
| E10 | Existing configured profile/checksum/file, stale/failed/replay/canonical owner rights; custom Economy profiles retain supported owner export; download revocation denied. |
| E11 | Pure effective defaults/current delegated settings permission; full fields/enums and revision. |
| E12 | Every setting, PATCH omission/false/zero/null, strict inputs, freshness/key and exact durable read-back. |
| E13 | Existing auth:web identity/current membership, visible client-only paging; site/revoked account denied. |
| E14 | Exact current publication/parent scope; customer-safe fields/active lines/totals and no staff PAT substitution. |

Final scoped suite 60 tests / 640 assertions, API administration 5 / 313 and broad affected 384 / 4324
pass. Production-mode Swagger scan passes; exact route/contract and all schemas are asserted.
Native mutex 1205, receipt/source uniqueness 1062, trusted HTTPS and exact Swagger bytes pass.
BookStack six actual pages and inherited staff scope are verified. No real customer operation or
production state is inferred. The HR checklist is retained for named human acceptance before Main/release.
