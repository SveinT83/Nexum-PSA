Economy owns internal order generation and billing handoff, not provider invoices/payments.

Current full browser/API parity is implemented on Dev under the approved 2026-10-25 Economy API RFC.
Human-review checklist HR-2026-10-25-ECONOMY-API is Pending. Main and production are separate.
The exact 23 HTTP operations and 34 schemas are in Docs/api-contract.json and generated Swagger.
All domain routes live in routes.php; api.php is the compatible loader. Portal uses auth:web.

Shared operations cover staff order list/detail/context/lines/effective totals, settings, all-source
generation, supported draft-line/empty-order deletion, ready/draft/manual-invoiced, publication and
customer-safe Portal reads, plus the canonical Data Exchange profile/run/file handoff. Current actor
permissions and token scopes both apply. New mutation keys/revisions/previews and durable receipts
are required as documented in Docs/knowledge/economy-api.md; old writes enforce supplied headers.

Ticket owns time/cost registration; Storage owns picking/stock; Commercial owns rate/timebank
consumption; Integration owns provider subscriptions/confirmed billing periods; Data Exchange owns
profile/run/files/delivery. No approval/cancel/exported/provider-invoice engine is invented.
Generation handles Ticket time/cost, quick overuse and confirmed local licence periods, preserving
chronological/annual timebank allocation, no-billing outcomes and recorded financial customer.
Unique source-owner evidence survives line correction and Ticket moves. Deletion never reverses
stock or deletes consumption/subscription records. API/read-only web pages do not persist defaults.

Every Economy writer shares a transaction mutex/revision; source deduplication and exact counters
prevent double charge and spurious drafts. Durable command evidence supports authorized replay
and deletion read-back independently of generic audit retention. After-commit notification intents
are claimed once with current audience/preferences and report unknown outcomes without blind retry.

Apply only database/migrations/2026_10_25_160000_create_economy_command_records.php for this change.
It adds mutex, receipts, source-owner and notification-intent tables; historical amounts are not
rewritten. Retain evidence during rollback. Generate Swagger and clear relevant route cache;
no frontend build or scheduler activation is needed. See the verification plan for exact evidence.
Future invoice/payment/accounting capabilities remain separate approved product work.
