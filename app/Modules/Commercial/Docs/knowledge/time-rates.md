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

These rates are defaults for future contracts. Updating a service rate does not rewrite already negotiated contract terms.

## Contract Snapshots

Contract service lines copy the service rates into `contract_item_time_rates`.

The copied rates may be adjusted or disabled before the contract is sent or approved. This makes negotiated rates explicit in the contract and protects old contracts from later global price changes.

## Ticket And Timebank Use

Ticket cost and timebank logic should resolve rates in this order:

1. Active rate snapshot on the active contract line.
2. Active global rate that is allowed without a contract.
3. Manual override if no matching rate exists.

Closing or invoicing ticket work should not depend on mutable service defaults once a contract has been accepted.

## Future Rules

Quantity discounts and tiered rates should be added as explicit rules on services or contracts. They should calculate suggested prices before contract approval, then write the final agreed rates into the contract snapshot.
