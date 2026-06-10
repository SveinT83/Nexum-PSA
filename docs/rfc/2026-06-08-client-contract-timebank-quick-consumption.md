# RFC: Client Contract Timebank Quick Consumption

Status: Approved
Date: 2026-06-08
Owner: Codex

## Context

Production client work has exposed a practical service desk workflow gap. When a customer calls, walks
up to the counter, or needs a very small piece of help, technicians sometimes need to consume contract
time immediately without first creating a Ticket or Task. Today, included contract time is only visible
indirectly through contracts and only consumed through Ticket/Task-driven time registration that later
settles through Economy.

The Client profile already has a `Contracts` tab. That is the right operational surface for showing
whether the Client has contract hours, how much is included for the active period, how much has been
used, whether they are overconsuming, and whether a technician is allowed to register quick time.

## Goals

- Show active Client contract timebanks on the Client profile `Contracts` tab.
- Show total included minutes/hours, used minutes/hours, remaining minutes/hours, and overused
  minutes/hours per relevant contract line and period.
- Render a compact progress bar where normal usage is neutral/success styling and overuse is red.
- Let authorized technicians quickly register time consumption from a modal without creating a Ticket
  or Task.
- Make the workflow settings-based:
  - Whether quick timebank consumption is enabled.
  - Whether quick consumption is allowed only while included time remains.
  - Whether overconsumption may be registered directly.
  - Whether a note/explanation is required.
- Enforce permissions separately from settings.
- Keep all registrations auditable with actor, work date, minutes, client, contract, contract item,
  note, and whether the entry caused overuse.

## Non-Goals

- Replacing normal Ticket/Task time registration.
- Creating automatic Tickets for every quick entry.
- Building a full time approval workflow.
- Building automatic invoice export for overused quick time in this first slice.
- Changing how contract line timebank periods are defined.
- Changing existing Ticket time entry behavior.

## Current Behavior

- Services can define `timebank_enabled`, `timebank_minutes`, and `timebank_interval`.
- Contract items point to Services. The current Economy settlement code calculates included minutes
  from `contractItem->service->timebank_minutes * contractItem->quantity`.
- Ticket time entries can point to `contract_id`, `contract_item_id`, and `contract_item_time_rate_id`.
- Economy order generation creates `ticket_time_entry_allocations` and calculates:
  - included minutes,
  - covered minutes,
  - billable minutes,
  - allocation period.
- `ticket_time_entries` and `ticket_time_entry_allocations` both require a Ticket, so they are not a
  good storage target for a deliberate no-ticket/no-task quick workflow.
- Client profile `Contracts` currently lists contracts, but does not expose timebank usage or a quick
  consumption action.

## Proposed Change

Add a Commercial-owned quick timebank consumption feature with Client UI integration.

### Timebank Balance Source

Create a Commercial service/action that calculates contract timebank balances for a Client:

- Find active/won/approved contracts for the Client.
- Include contract items whose related Service has `timebank_enabled = true`.
- Use the same period rules as Economy:
  - `monthly`: current calendar month.
  - `yearly`: yearly period from contract start date.
  - `one_time`: contract start through contract end, or open-ended fallback.
- Included minutes are `service.timebank_minutes * contract_item.quantity`.
- Used minutes are the sum of:
  - existing Ticket allocation `covered_minutes + billable_minutes` for the same contract item/period,
    or alternatively Ticket time entry minutes for pending entries when allocation has not run yet;
  - new quick consumption entries for the same contract item/period.
- Remaining is `included - used`, never below zero.
- Overused is `used - included`, never below zero.

The first implementation should make the read model conservative and transparent: it may show pending
Ticket time as used before Economy has settled it, so technicians do not overpromise remaining hours.

### Quick Consumption Storage

Add a new table, owned by Commercial:

```text
client_contract_time_consumptions
```

Suggested fields:

- `id`
- `client_id`
- `contract_id`
- `contract_item_id`
- `user_id`
- `work_date`
- `minutes`
- `note`
- `source` default `quick_client`
- `period_start`
- `period_end`
- `included_minutes_snapshot`
- `used_before_minutes_snapshot`
- `overused_minutes`
- `contract_item_time_rate_id`
- `time_rate_id`
- `rate_name`
- `rate_code`
- `rate_type`
- `rate_unit`
- `rate_amount_ex_vat`
- `rate_currency`
- `created_at`
- `updated_at`

This avoids fake Tickets while preserving auditability and period-based accounting. It can later be
included in Economy billing if overuse should become an order line.

### Settings

Add Commercial settings under the existing Commercial/admin settings area:

- `quick_timebank_enabled`: default `false`.
- `quick_timebank_require_remaining`: default `true`.
- `quick_timebank_allow_overuse`: default `false`.
- `quick_timebank_require_note`: default `true`.
- `quick_timebank_max_minutes`: optional safety cap, default `120`.

Recommended behavior:

- If `quick_timebank_enabled` is false, the Client Contracts tab shows the timebank balances but no
  quick registration button.
- If remaining time is zero and overuse is not allowed, the quick registration button is disabled.
- If overuse is allowed, the modal must clearly indicate that the entry will overconsume contract
  time.

### Permissions

Add permissions:

- `commercial.timebank.view`: see timebank usage and bars.
- `commercial.timebank.quick-consume`: register quick time consumption while time remains.
- `commercial.timebank.overconsume`: register quick overconsumption when settings allow overuse.

Admins should have all permissions. Tech users may get view by default, but quick-consume should be
explicitly granted or seeded according to current role policy.

### Client UI

In the Client profile `Contracts` tab:

- Add a compact timebank summary above or inside the contracts card.
- For each active timebank contract line, show:
  - Contract number/id and service/line name.
  - Current period.
  - Included, used, remaining, and overused values.
  - A progress bar:
    - used within included time: normal/success segment;
    - overuse: red segment or red overflow indicator.
  - `Use time` action when settings and permissions allow it.

The modal should include:

- selected contract line,
- selected time rate,
- current remaining/overused context,
- work date,
- minutes,
- note/explanation,
- confirmation state when the entry will cause overuse.

### Audit Trail

Each quick entry should record actor and snapshots. The Client profile should show recent quick
entries for the current period or expose them below the bar.

## Impact Analysis

Affected modules:

- `Commercial`: owns contracts, timebanks, settings, permissions, and the new quick consumption
  records.
- `Clients`: renders the Client profile `Contracts` tab and calls Commercial read/action services.
- `Ticket`: existing ticket time entries remain unchanged, but balance calculation must include them.
- `Task`: existing task completion time behavior remains unchanged.
- `Economy`: no billing generation in the first slice, but future overuse billing must account for
  quick consumption entries.
- `UserManagement/permissions`: new permissions must be seeded and tested.

Routes:

- Add a POST route in `app/Modules/Commercial/routes.php`, for example:
  - `POST /tech/clients/{client}/contracts/timebank-consumptions`
  - or a Commercial path under `/tech/contracts/timebank-consumptions`

UI:

- Client show `Contracts` tab gains a timebank summary and modal.
- Commercial admin settings gain quick timebank policy controls.

Data:

- New table for quick consumption.
- Existing ticket allocation data is read but not changed.

Queues/scheduler:

- None in the first slice.

Integrations/API:

- No API in the first slice.

## Data And Migration Plan

1. Create `client_contract_time_consumptions`.
2. Add model under Commercial.
3. Add indexes:
   - `client_id, period_start, period_end`
   - `contract_item_id, period_start, period_end`
   - `user_id, work_date`
4. No backfill required.
5. Existing Ticket/Task time stays as-is.
6. Rollback deletes only quick consumption records. Existing contract/ticket data is unaffected.

## Testing Plan

Feature tests:

- Client Contracts tab shows timebank line with included/used/remaining values.
- Progress bar shows overuse state when used exceeds included.
- Quick consumption button is hidden/disabled when settings disable it.
- Quick consumption succeeds when settings and permissions allow it.
- Quick consumption is blocked when remaining is insufficient and overuse is disabled.
- Quick overconsumption succeeds only when both setting and permission allow it.
- Quick consumption requires note when setting requires note.

Unit/action tests:

- Balance calculation includes ticket time allocations.
- Balance calculation includes pending ticket time entries conservatively.
- Balance calculation includes quick consumption entries.
- Monthly/yearly/one-time periods match Economy's existing timebank period behavior.

Regression tests:

- Existing Ticket time registration tests still pass.
- Existing Economy order generation tests still pass.
- Existing Client profile tests still pass.

## Documentation Plan

- Update Commercial Knowledge docs for contract timebanks and quick consumption.
- Update Client Knowledge docs for the Contracts tab behavior.
- Update admin/settings docs for the quick timebank policy.
- Add deploy commands in implementation summary:
  - `php artisan migrate --force`
  - `php artisan db:seed --class=PermissionSeeder` or the actual permission seeder if changed
  - `php artisan optimize:clear`
  - `php artisan queue:restart`

## Economy Amendment

Approved follow-up on 2026-06-09: quick registrations must store the selected time rate, and Economy
`Generate orders` must create draft order lines for quick timebank overuse. Only the overused minutes
caused by each quick entry should be billed, using the rate snapshot stored on that entry. Normal
Economy approval and export remain unchanged.

## Open Questions

1. Should quick overconsumption create billable Economy order lines in this first slice, or only record
   auditable overuse for later billing review?

Decision: record auditable overuse only in the first slice. Billing automation should be a separate
slice because it affects invoices/orders and customer-facing financial output.

## Approval

Approved by Svein on 2026-06-08.
