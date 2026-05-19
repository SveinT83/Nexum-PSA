Commercial SLA inheritance controls which response policy applies when a client has several services with different operational expectations.

## Purpose

A client may have a strong default SLA for normal managed services, but a weaker or different SLA for services that depend on third parties. For example, email support through an external provider may need different response expectations than internal managed infrastructure.

## Inheritance Rules

SLA is resolved through this order:

1. Contract item SLA snapshot.
2. Contract default SLA.
3. Client default SLA when that exists.
4. System default SLA.

Service SLA is a template. It is used when a service is added to a contract, but tickets should not read mutable service defaults directly after a contract has been created.

## Service Defaults

Services may define a default SLA. If a service has a default SLA, the contract line inherits that SLA when the service is added to a contract.

If a service has no default SLA, the contract line is set to use the contract default SLA.

## Contract Line Overrides

Each contract line can either:

- Use the contract default SLA.
- Use a specific SLA selected for that service line.

When a specific SLA is selected, a snapshot is stored on the contract line. This protects the agreed contract behavior from later changes to the global SLA template.

## Ticket Use

When ticket-to-contract matching is implemented, ticket SLA resolution should use the contract item first. This allows different services under the same client contract to have different response policies.

Examples:

- Email service through a third-party provider can use a third-party email SLA.
- Backup service can use a backup SLA.
- General support can use the contract default SLA.
