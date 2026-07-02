# Feature Slice: Nexum Relationship Foundation

Status: Completed
Date: 2026-07-01
Parent: `docs/rfc/2026-07-01-nexum-relationship-and-vendor-provider-routing.md`
Owner: Codex

Implementation note: completed on 2026-07-02 as part of the full Discussion #150
workstream. The delivered implementation includes this foundation plus ticket
sync, selected attachment sync, documentation/Knowledge sync, admin UI, audit
events, migration, permission seed updates, and tests.

## Goal

Create the foundation for NexumRelationship configuration after the parent RFC is approved. The
target branch must already have the WorkContext foundation from Discussion #149.

This slice gives Nexum a safe place to store relationship identity, linked Client/Vendor, credential
metadata, capability contract, and local health state. It does not sync Tickets or Documentation.

## User-Visible Behavior

Admins can manage relationship records in a compact settings surface:

- relationship name,
- direction,
- related Client or Vendor,
- remote Nexum base URL,
- remote organization identity,
- local status,
- notes/health summary.

No ticket escalation button, sync button, documentation sync toggle, attachment sync setting, or
retry control is visible in this slice unless the behavior behind it is fully implemented in the same
slice.

## Scope

- Create the singular `Relationship` module under `app/Modules/Relationship`.
- Add `nexum_relationships`.
- Add a `NexumRelationship` model.
- Add relationship constants/enums for direction, type, status, health status, and capability keys.
- Add admin routes, controllers, and Bootstrap views inside the Relationship module.
- Add permissions such as:
  - `relationships.view`
  - `relationships.manage`
- Add validation:
  - `we_are_provider` requires a Client.
  - `we_use_provider` requires a Vendor.
  - remote base URL must be a valid URL.
  - active relationships require remote organization identity.
- Store outgoing API token and webhook signing secret encrypted when present.
- Store incoming API/webhook token as a hash, not plaintext.
- Add local health fields for configuration and later sync status.
- Add code-level capability defaults with all behavior disabled by default.
- Add tests for permissions, validation, safe secret storage, and UI honesty.
- Update TODO/RFC/ADR links as needed.

## Out Of Scope

- No Ticket queue creation.
- No service-area default routing.
- No automatic escalation.
- No manual "Escalate to provider" action.
- No inbound or outbound ticket sync.
- No documentation or Knowledge sync.
- No remote health check button unless a real authenticated endpoint exists.
- No pairing/invite flow.
- No API ability exposure for relationship sync.
- No webhook receiver.
- No `nexum_sync_links` or `nexum_sync_events` tables yet unless the implementation proves they are
  required for honest local health behavior.
- No Client or Vendor profile panels unless they only show already implemented relationship records
  without exposing unfinished actions.

## Data Touched

New table:

- `nexum_relationships`

Existing tables referenced:

- `clients`
- `vendors`
- user/permission tables used by the current authorization setup

No existing Client, Vendor, Ticket, Documentation, Knowledge, Email, Commercial, Economy, or Report
data is migrated in this slice.

## Permissions

Add relationship web permissions only:

- `relationships.view`: list and view configured relationships.
- `relationships.manage`: create, update, pause, disable, and rotate local credentials for
  relationships.

Do not add API abilities for relationship sync until a matching API/webhook behavior slice exists.

## Tests

- Admin without permission cannot access relationship settings.
- Admin with `relationships.view` can list/view relationships but cannot save changes.
- Admin with `relationships.manage` can create/update relationship identity.
- `we_are_provider` validation requires `client_id`.
- `we_use_provider` validation requires `vendor_id`.
- `collaboration` cannot be activated in this slice.
- Outgoing token and webhook signing secret are not stored as plaintext.
- Incoming token is stored only as a hash.
- UI does not show ticket sync, documentation sync, retry, or escalation controls.
- Relationship status and health summary render without implying sync is active.

## Documentation

- Keep the parent RFC and ADR linked from this slice.
- Add Relationship module developer documentation during implementation.
- Add Knowledge documentation only if the admin settings surface is implemented.
- Update Integration API management documentation later, when real relationship API/webhook behavior
  exists.

## Done Criteria

- Relationship module follows `docs/module-architecture.md`.
- Relationship records can be created and edited by authorized admins.
- Relationship records safely link either Client or Vendor according to direction.
- Secrets are stored safely.
- Capability defaults exist in code but no unimplemented capability control is exposed in UI.
- Local health/status fields exist and render honestly.
- Tests cover validation, permissions, secret storage, and UI honesty.
- No Ticket, Documentation, Knowledge, Email, or sync behavior is hidden inside this slice.
