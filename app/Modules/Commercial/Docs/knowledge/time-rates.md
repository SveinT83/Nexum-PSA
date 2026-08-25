Commercial time rates define the hourly price rules that can be reused by services and frozen into contracts.

## Purpose

Rates answer the question: what should one hour of this kind of work cost?

The global catalogue contains standard rates such as ordinary support labor, contract labor, and driving. Services can opt into those rates and override the default price. When a service is added to a contract, the selected rates are copied into the contract line as snapshots.

## Default Rates

The migration seeds these active defaults:

- `TIME_WITHOUT_CONTRACT`: NOK 1200 per hour.
- `TIME_WITH_CONTRACT`: NOK 650 per hour.
- `DRIVING`: NOK 520 per hour.

Technicians can add more rates or adjust the active standard rates from the Sales workspace rate catalogue.

## Service Defaults

Services may define which rates normally belong to that service. A managed service can include the normal contract support rate and driving, while another service can use a different labor rate.

Each catalogue rate has an explicit `is_customer_visible` setting. It defaults to false and is
copied with the rate into new editable Contract lines. Visibility must be deliberately enabled for a
rate that belongs in a customer document; rate names and codes are not visibility rules.

These rates are defaults for future contracts. Updating a service rate or its customer visibility
does not rewrite already negotiated contract terms.

## Contract Snapshots

Contract service lines copy the service rates into `contract_item_time_rates`.

The copied rates may be adjusted, disabled, or marked customer-visible before the contract is sent or
approved. This makes negotiated rates explicit in the Contract and protects old Contracts from later
global price or visibility changes.

Customer documents show visible rates once under
`Satser for arbeid utenfor avtalt omfang`. Equivalent snapshots are deduplicated by normalized
name, rate type, exact amount in minor units, currency, and unit. Rates with similar names but
different commercial values remain separate. If no rate is explicitly visible, the section is
omitted.

Legacy Contract rate snapshots default to customer-hidden. Nexum must not infer that an old rate was
customer-visible from its name, code, or operational use.

Before production promotion, a human must classify historical rates whose customer visibility is
unknown and record the authoritative decision. This readiness step does not authorize automatic
backfill of sent/accepted evidence; any historical correction needs its own reviewed scope.

## Ticket And Timebank Use

Ticket cost and timebank logic should resolve rates in this order:

1. Active rate snapshot on the active contract line.
2. Active global rate that is allowed without a contract.
3. Manual override if no matching rate exists.

Closing or invoicing ticket work should not depend on mutable service defaults once a contract has been accepted.
The `is_customer_visible` setting affects presentation only. It must not enable, disable, or reorder
Ticket, timebank, cost, or billing rate resolution.

## Future Rules

Quantity discounts and tiered rates should be added as explicit rules on services or contracts. They should calculate suggested prices before contract approval, then write the final agreed rates into the contract snapshot.
