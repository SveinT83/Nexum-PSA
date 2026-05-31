# Custom Fields & Metadata Layer Idea

This idea captures the direction for a generic Custom Fields capability in Nexum.

The feature must not be designed only for Tickets. It should become a platform capability that can
later be used by Tickets, Assets, Contacts, Clients, Sites, Vendors, Sales, Contracts, Orders, Intake
Items, Tasks, and future domains.

## Core Idea

Custom Fields should support two related use cases:

1. Human-facing custom form fields.
2. Configurable metadata for automation and lightweight integrations.

Examples of human-facing fields:

- Warranty status.
- Preferred support window.
- Equipment condition.
- Customer priority.
- Internal service notes.

Examples of integration/automation metadata:

- `mspmanager_client_id`
- `mspmanager_user_id`
- `tripletex_customer_id`
- `poweroffice_customer_id`
- `legacy_system_id`
- `n8n_sync_key`

This allows customers and partners to adapt Nexum to their own workflows without requiring a custom
hardcoded integration for every external system.

## Why This Matters

Many companies will need to sync or enrich Nexum records from external systems. For example, a
company may want to use n8n to sync clients and contacts from MSP Manager without waiting for a full
native MSP Manager integration.

If Custom Fields can store stable, searchable, unique external IDs, n8n or another automation tool
can:

- Find a Client by `mspmanager_client_id`.
- Find a Contact by `mspmanager_user_id`.
- Update existing records.
- Create missing records.
- Store future sync metadata.

This makes Nexum more flexible for real-world adoption.

## Platform Capability

Custom Fields should be treated as its own platform capability/domain.

The first user-facing implementation may be Ticket-focused, but the storage model and APIs should
not be Ticket-specific.

Expected foundation:

- Field definitions.
- Field options.
- Field values.
- Optional field groups.
- Model/domain scopes.
- Stable keys.
- Visibility rules.
- Required rules.
- Searchable fields.
- Unique fields.
- API read/write support.

## Purpose And Visibility

Not all custom fields are meant for the same audience.

Suggested future field purpose values:

- UI.
- Integration.
- Automation.
- System.

Suggested future visibility values:

- Visible.
- Admin only.
- Hidden.
- API only.

This allows fields such as `mspmanager_user_id` to be available for automation and API lookup without
being shown as a normal technician-facing form field everywhere.

## Custom Fields Versus External References

Custom Fields can cover lightweight integration scenarios.

However, stronger native integrations may later need a dedicated External References capability with
sync status, source system metadata, audit history, and conflict handling.

Recommended distinction:

- Custom Fields: flexible configurable metadata and form fields.
- External References: robust integration identity and sync tracking for native integrations.

The first Custom Fields implementation does not need to solve the full External References problem,
but it should not block that future capability.

## First Practical Consumer

Ticket should probably be the first consumer because the existing Ticket Types and future Ticket
Templates need dynamic fields.

Initial Ticket use:

- Select Ticket Type.
- Show base Ticket fields.
- Show Custom Fields configured for that Ticket Type.
- Store structured values.
- Validate required fields.

Nexum already has `ticket_types`. The Custom Fields work should bind to and extend that existing
concept rather than introducing a second ticket type system.

Later consumers:

- Assets.
- Contacts.
- Clients.
- Sites.
- Ticket Intake Items.
- Orders.
- Contracts.
- Tasks.

## Non-Goals For First Pass

- Do not build UI for every module at once.
- Do not build a full external integration framework.
- Do not replace native domain columns that are core to the domain.
- Do not store all custom values as unstructured JSON on the parent record.
- Do not make all metadata visible to normal users by default.

## Design Warning

Custom Fields must not become an uncontrolled dumping ground.

Fields need stable keys, scopes, ownership, validation, visibility, and search rules. Otherwise they
will be hard to use safely for templates, reporting, automation, and future AI/context workflows.
