# RFC: Nexum Relationship And Vendor Provider Routing

Status: Approved
Date: 2026-07-01
Owner: Codex

## Context

GitHub Discussion #150 defines a future direction where two independent Nexum installations can
work together without sharing one database. A customer can run their own Nexum, escalate selected
work to us, and receive public replies, status updates, selected attachments, and shared
documentation back into their own Nexum.

This depends on the approved WorkContext contract in
`docs/rfc/2026-07-01-work-context-organization-scope.md`. WorkContext decides whether a local record
is internal or client-scoped. NexumRelationship decides whether selected work is exchanged with
another Nexum installation.

The current product already has pieces this plan should reuse:

- Documentation owns the canonical `vendors` master-data table for vendors, suppliers, and
  manufacturers.
- Clients are the external customer records.
- Ticket has queues, workflows, public/internal messages, an API surface, and idempotent external
  message sync based on source and external ID metadata.
- Knowledge and Documentation have source/sync metadata and API surfaces.
- Integration owns API token management, API ability catalog, and shared external integration
  conventions.

What is missing is a relationship container that can link a remote organization to either a Client
or a Vendor, hold remote Nexum connection settings, define service-area routing, and enforce safe
public/private sync boundaries.

## Goals

- Add an explicit NexumRelationship concept instead of putting API tokens, remote URLs, routing,
  capabilities, sync state, and health directly on `vendors` or `clients`.
- Support the first relationship pattern: customer-to-provider routing between two independent
  Nexum installations.
- Support both directions in the data model:
  - We are the provider for a Client.
  - We use an upstream provider represented by a Vendor.
- Support service areas such as `it`, `accounting`, and later custom areas, with default provider
  relationships per area.
- Let a provider relationship create or use a dedicated Ticket queue in later slices.
- Allow policy-controlled automatic escalation from selected queues and manual "Escalate to
  provider" actions.
- Prefer scoped API tokens and signed webhooks for product sync.
- Keep SSH keys out of the normal Nexum-to-Nexum product sync path.
- Sync only explicitly shared objects and fields.
- Sync public Ticket replies, selected shared attachments, and mapped status updates two ways when
  policy allows it.
- Sync Documentation and Knowledge content two ways only when the record is client-scoped or
  otherwise explicitly non-internal and the relationship capability allows it.
- Fall back to normal email when a public reply/update would normally be emailed but API sync fails,
  and record the sync failure on the relationship/sync audit trail.
- Preserve enough metadata for duplicate prevention, conflict handling, retries, health views, and
  audit evidence.

## Non-Goals

- Do not implement this in an environment where the WorkContext foundation is missing.
- Do not merge databases or tenants between Nexum installations.
- Do not replicate full CRM data.
- Do not sync internal notes, private workflow details, assignment, time, cost, margin, internal AI
  analysis, private contacts, credentials, or unreviewed internal documentation by default.
- Do not expose UI controls for ticket sync, documentation sync, or provider escalation before the
  corresponding behavior is implemented and tested.
- Do not replace the existing Client, Vendor, Ticket, Documentation, Knowledge, Integration, Email,
  Commercial, Economy, or Report domain ownership rules.
- Do not build general partner/collaboration/project relationship workflows in the first
  implementation slices, even though the data model should not block them later.

## Current Behavior

Vendor records are stored in the shared `vendors` table and exposed through the Documentation module.
They are used by Assets, Storage, Commercial costs, and supplier workflows. They do not store remote
Nexum connection state.

Client records represent external customers. Client profile/settings can link to RMM clients, but
there is no Nexum-to-Nexum relationship panel, capability policy, or sync health surface.

Ticket can already receive idempotent external messages through the Ticket API. That behavior stores
external source and ID metadata on Ticket messages, but it is not relationship-aware and does not
provide a complete two-way sync channel, status mapping, remote identity mapping, or email fallback
policy.

Knowledge and Documentation already have local content models, source metadata, and API endpoints.
BookStack sync exists through Integration/Knowledge, but Nexum-to-Nexum documentation exchange is not
implemented.

API tokens are managed through Integration and scoped abilities. There is no relationship-specific
machine connection, webhook signing secret, token rotation workflow, or relationship sync audit.

## Proposed Change

Create a singular `Relationship` module that owns Nexum-to-Nexum relationship configuration,
connection state, capability policy, sync identity mapping, and audit logs.

The module should live under:

```text
app/Modules/Relationship
```

It owns relationship infrastructure only. Domain behavior remains in the domain modules:

- Client owns Client records and Client profile surfaces.
- Documentation owns the Vendor master-data register.
- Ticket owns ticket creation, queues, workflows, public/private message boundaries, status mapping
  execution, attachments, and ticket tests.
- Documentation and Knowledge own documentation records, Knowledge articles, templates, rendering,
  visibility, and documentation sync eligibility.
- Integration owns API ability catalog conventions and general API key management patterns.
- Email owns outbound email delivery and delivery logs.

### Relationship Model

Add a `NexumRelationship` model with a table such as `nexum_relationships`.

Recommended fields:

```text
id
name
direction                 we_are_provider | we_use_provider | collaboration
relationship_type          customer_provider | future values
client_id                  nullable; set when we are provider for a Client
vendor_id                  nullable; set when we use an upstream provider
remote_base_url
remote_instance_id
remote_organization_name
remote_organization_identifier
status                     draft | active | paused | disabled
health_status              unknown | healthy | degraded | failing
capabilities               JSON
ticket_policy              JSON
documentation_policy       JSON
attachment_policy          JSON
status_mapping             JSON
service_areas              JSON
outbound_token_encrypted   nullable
webhook_secret_encrypted   nullable
inbound_token_hash         nullable
token_rotated_at           nullable
last_successful_sync_at    nullable
last_failure_at            nullable
failure_summary            nullable
created_by
updated_by
timestamps
```

Relationship direction rules:

- `we_are_provider` relationships reference a Client.
- `we_use_provider` relationships reference a Vendor.
- `collaboration` is reserved for later slices and must not expose behavior before it is built.

Capability flags should exist from the foundation, but UI controls for a capability should be shown
only after the matching behavior exists. Early records can store all capabilities disabled.

### Sync Identity And Audit

Later sync slices should add a generic remote identity table, for example `nexum_sync_links`:

```text
id
relationship_id
domain                    ticket | ticket_message | documentation | knowledge | asset | report | future
local_type
local_id
remote_type
remote_id
remote_version
remote_checksum
remote_updated_at
direction
sync_status               pending | synced | failed | conflict | skipped
conflict_status           nullable
last_synced_at
last_error
metadata                  JSON
timestamps
```

Sync events should be audited in a separate append-only log, for example `nexum_sync_events`, with:

- relationship ID,
- direction,
- capability,
- local and remote object references,
- event type,
- actor or machine identity,
- payload digest/checksum, not raw secrets,
- outcome,
- error code/message,
- timestamps.

### Authentication

The first implementation should use manual machine connection setup:

- outgoing scoped API token stored encrypted,
- incoming token stored as a hash,
- signed webhooks with encrypted signing secret,
- token rotation metadata,
- audit event for every token rotation and failed authentication.

A pairing/invite flow can be designed later after manual exchange is proven.

### Ticket Routing And Sync

Ticket routing should be implemented after the relationship foundation.

Rules:

- Provider relationships may create/use a dedicated Ticket queue.
- Service-area defaults choose the provider relationship for a given area.
- Automatic escalation can run only from explicit queue/policy rules.
- Manual escalation requires a dedicated permission and settings-enabled relationship.
- Remote tickets are matched through `nexum_sync_links` to prevent duplicates.
- Remote events do not directly execute arbitrary local workflow transitions. They are interpreted
  through relationship status mapping and Ticket-owned workflow rules.
- Public replies can sync both ways.
- Selected attachments can sync only when size, type, retention, and policy checks pass.
- Local status changes map to agreed remote status values.
- Private notes, local workflow internals, assignment, time, cost, margin, and internal analysis
  remain local.
- If API sync fails for a public update that should still reach the other party, Nexum falls back to
  the normal email path and records the sync failure. No separate notification workflow is required
  by default.

Initial ticket fields allowed for v1 sync:

- remote ticket identity and URL,
- subject/title,
- public description/request summary,
- public replies,
- mapped status,
- selected shared attachments,
- priority signal as input to local policy, not an unconditional local priority override,
- public solution/shared resolution text when explicitly marked shared.

### Documentation Sync

Documentation sync is part of the finished relationship plan, but should come after Ticket sync.

Rules:

- Internal documentation is never eligible by default.
- Only client-scoped, site-scoped, asset-scoped, or explicitly shared non-internal documentation can
  sync.
- Both sides keep their own database rows.
- Sync copies rendered content and editable source only when policy allows it.
- Each synced record keeps source instance, organization, remote ID, template key/version, checksum,
  sync status, and conflict state.
- Conflicts must be reviewed. No later update should blindly overwrite a locally diverged article
  or documentation record.
- Provider-authored read-only copies, customer-authored shared copies, proposed edits, and local
  overrides should be supported by policy over time.

### Client And Vendor Surfaces

Later UI slices should add compact relationship panels:

- Client profile: relationships where we are provider for that Client.
- Vendor profile: relationships where that Vendor is our upstream provider.
- Admin/Relationship settings: global relationship list, identity, credentials, capabilities, health,
  logs, retries, and service-area defaults.

UI must follow `docs/ui-guidelines.md`: compact operational settings, Bootstrap, no placeholder
actions, and no visible controls for behavior that is not implemented.

## Impact Analysis

- **Architecture:** new singular `Relationship` module under `app/Modules/Relationship`.
- **Client:** Client profile can show relationship panels after relationship behavior exists.
- **Documentation/Vendor:** Vendor remains master data; relationship config references vendors
  instead of adding sync fields to `vendors`.
- **Ticket:** queue defaults, manual escalation, remote ticket creation/update, public/private
  message boundaries, status mapping, attachment policy, email fallback, and tests.
- **Knowledge/Documentation:** two-way relationship documentation sync, conflict handling, template
  version metadata, visibility guardrails, and tests.
- **Integration/API:** API ability catalog may need relationship abilities and webhook/token
  conventions, but Integration does not own relationship business rules.
- **Email:** normal outbound ticket reply fallback is reused when API sync fails for an update that
  would otherwise be emailed.
- **Notification:** no new notification workflow by default; use existing email/log surfaces unless
  a later approved slice adds explicit notification behavior.
- **Report:** future reports can show sync health and relationship activity, but Report does not own
  relationship sync.
- **Commercial/Economy:** time, cost, margin, and billing data must not sync by default.
- **Permissions:** add relationship view/manage permissions and later ticket escalation permissions.
- **Queues/Scheduler:** sync send/retry jobs and webhook processing will require queue coverage in
  the relevant implementation slices.
- **Documentation:** RFC, ADR, feature slices, module docs, and Knowledge docs must be updated as
  slices are implemented.

## Data And Migration Plan

Foundation slice:

- Add `nexum_relationships`.
- Store relationship credentials safely:
  - encrypted outgoing API token,
  - hashed incoming token,
  - encrypted webhook signing secret.
- Add indexes for `direction`, `relationship_type`, `client_id`, `vendor_id`, `status`, and
  `health_status`.
- Do not migrate existing Client or Vendor records.
- Do not add ticket/documentation sync tables until the matching behavior slice.

Routing/sync slices:

- Add service-area default provider mapping when provider queue creation is implemented.
- Add `nexum_sync_links` before any ticket or documentation record can sync.
- Add `nexum_sync_events` before outbound/inbound sync is enabled.
- Add module-specific columns only when the owning module slice needs them.

Rollback:

- Deleting relationship tables should not alter existing Clients, Vendors, Tickets, Documentation,
  Knowledge, Email, Commercial, or Economy records.
- Sync slices must leave local records usable if relationship sync is disabled.
- No slice may delete remote records automatically during rollback.

## Testing Plan

Foundation:

- Feature tests for relationship create/update/list/show permissions.
- Validation tests for direction rules:
  - `we_are_provider` requires Client.
  - `we_use_provider` requires Vendor.
  - unsupported directions cannot activate behavior.
- Tests proving outgoing tokens/webhook secrets are encrypted and incoming tokens are hashed.
- Tests proving no visible UI exposes ticket/documentation sync controls before those behaviors are
  implemented.

Ticket routing/sync:

- Provider queue creation and default service-area routing tests.
- Manual escalation permission tests.
- Automatic escalation policy tests.
- Duplicate prevention tests using remote ticket IDs.
- Public reply two-way sync tests.
- Status mapping tests.
- Private-data protection tests for notes, assignment, time, cost, margin, and internal analysis.
- Attachment policy tests for allowed and blocked files.
- Email fallback tests when API sync fails.

Documentation sync:

- Eligibility tests for internal versus client/site/asset-scoped documentation.
- Source metadata and checksum tests.
- Two-way update tests.
- Conflict detection and review-state tests.
- Template key/version mapping tests.
- Private/internal documentation protection tests.

API/security:

- Scoped token ability tests.
- Signed webhook validation tests.
- Token rotation tests.
- Audit log tests for success, failure, retry, and conflict events.

## Documentation Plan

- Create Relationship module developer documentation when the module foundation is implemented.
- Add Knowledge documentation for admins after the first user-facing relationship settings slice.
- Update Client and Vendor documentation when profile panels are added.
- Update Ticket Knowledge documentation when escalation/sync is implemented.
- Update Knowledge/Documentation docs when documentation sync is implemented.
- Update Integration API management documentation when relationship API scopes or webhook setup are
  exposed.
- Link this RFC from the WorkContext RFC because WorkContext is the required local scope foundation.

## Feature Slices

1. `docs/feature-slices/2026-07-01-nexum-relationship-foundation.md`
   - Relationship module, model, permissions, credential storage, capability contract, and local
     health fields. No ticket or documentation sync yet.
2. Future slice: service-area defaults and provider queue creation.
3. Future slice: ticket queue auto-escalation and manual escalation.
4. Future slice: two-way public ticket sync with email fallback.
5. Future slice: two-way documentation sync and conflict handling.
6. Future slice: Client/Vendor relationship panels and broader collaboration readiness.
7. Future slice: richer domains such as reports, assets, approvals, and service/project updates.

## Open Questions

No open question blocks approval of this planning RFC.

Implementation remains blocked until this RFC is approved or explicitly authorized by Svein. The
WorkContext prerequisite from Discussion #149 is satisfied in the current dev branch; if this plan is
ported to another environment, verify that WorkContext exists before starting Relationship work.

Non-blocking choices for later slices:

- Which exact service-area vocabulary should be seeded first beyond `it` and `accounting`?
- Should pairing/invite replace manual token exchange after the first working implementation?
- Which relationship health metrics should be shown in the Report module later?

## Approval

Approved by Svein in conversation on 2026-07-02.

Svein approved building the full Discussion #150 capability as one complete implementation rather
than handing it off as separate delivery slices. The implementation must still keep internal
sequencing safe and must not expose unfinished behavior in the UI.
