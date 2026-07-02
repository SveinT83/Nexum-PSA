# WorkContext Module

WorkContext owns the shared internal/client context contract for Nexum PSA.

This foundation does not change domain behavior by itself. Domain modules adopt WorkContext one
slice at a time after their current `client_id`, visibility, reporting, API, permission, and billing
rules have been reviewed.

## Contract

- `internal`: no Client selected; work belongs to the owning organization.
- `client`: Client selected; work belongs to an external customer.

Relationship or sync state is intentionally not a WorkContext type. Nexum-to-Nexum routing belongs
to a separate relationship module/RFC.

## Owned Files

- `Models/WorkContext.php`
- `Support/WorkContextType.php`
- `Actions/EnsureWorkContextDefaults.php`
- `Actions/ResolveWorkContext.php`
- `Tests/Feature/WorkContextModuleTest.php`

## Adoption Rules

- New domain slices may add `work_context_id` only inside the adopting module.
- Existing `client_id` fields remain while they are part of current reporting, API, or billing
  behavior.
- Historical `client_id = null` records must not be blindly treated as internal unless the adopting
  module already documents that rule.
- Internal work must not flow into customer-facing reports, APIs, quotes, contracts, or Economy
  orders unless a later approved slice explicitly defines a safe internal reporting path.
