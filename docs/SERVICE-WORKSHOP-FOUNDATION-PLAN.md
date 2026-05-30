# Service Workshop Foundation Plan

This plan captures the long-term idea behind Asset Service, Intake, and Maintenance without starting
implementation immediately. The goal is to build reusable platform foundations before adding a
service-specific workflow.

## Product Direction

Nexum should treat every ticket as a case handled by the same ticket engine. Different work patterns
should be configured through Ticket Types, Workflows, Custom Fields, Task Templates, and Ticket
Templates rather than being hardcoded as separate service modules.

Asset Service and equipment intake should become a configuration and extension of the existing
Ticket and Asset domains, not a parallel repair system.

## Core Principle

Ticket first.

A customer, contact, or technician may start a case before the physical equipment is fully
registered as an Asset. The Ticket is the service case. Intake Items represent physical objects
delivered for work. Assets become the long-term service history records when an item is linked or
created.

## Architecture Direction

Stable core concepts:

- Ticket
- Ticket Type
- Workflow
- Status
- Priority
- Queue
- SLA
- Task
- Comment and conversation timeline
- Time, costs, orders, and approvals
- Client, site, contact, and asset links
- Permissions and audit/events

Configurable layers:

- Custom Fields per model, Ticket Type, and later Workflow/status context.
- Task Templates that can be copied into real Tasks.
- Ticket Templates that can apply type, workflow, fields, tasks, defaults, and checklists.
- Intake Items enabled for specific Ticket Types.
- Required field rules.
- Closure validation.
- Approval requirements.
- Portal visibility.

## Recommended Build Order

### 1. Custom Fields Core

Create a generic Custom Fields foundation that can later support Tickets, Assets, Contacts, Clients,
and other records.

Initial capabilities:

- Field definitions.
- Field types such as text, textarea, number, date, datetime, select, multiselect, checkbox, email,
  phone, and URL.
- Field options for select-style fields.
- Values stored structurally, not only as JSON on the parent record.
- Scopes for model type and, later, Ticket Type or Workflow.
- Required rules for create/edit and later close/status transitions.

The first UI can focus on Tickets, but the storage model should not be ticket-only.

### 2. Task Templates

Task Templates are reusable task recipes that Ticket Templates can copy into real Tasks.

A Task Template may define:

- Title.
- Description.
- Checklist.
- Sort order.
- Estimated time.
- Assignee rule.
- Due offset.
- Required or optional behavior.

Task Templates should not be real Tasks until they are applied to a Ticket or another workflow.

### 3. Asset Readiness

Improve the Asset domain enough that service workflows can safely reference it.

Needed direction:

- Reliable asset type/category structure.
- Searchable manufacturer, model, and serial number.
- Clean create/edit experience.
- Service history surface on Asset show.
- Optional service interval fields later.
- Readiness for quick-create from a Ticket Intake Item.

### 4. Existing Ticket Type Extension

Nexum already has configurable `ticket_types`. This work should extend the existing Ticket Type
model and admin UI rather than creating a new concept.

Ticket Types should become stronger configuration drivers for different case families.

Examples:

- Incident.
- Service Request.
- Asset Service.
- Maintenance.
- Project Work.
- Sales Request.
- Internal Task.

In later phases, a Ticket Type may define or reference:

- Default Workflow.
- Default Queue.
- Default Priority.
- Default Category.
- SLA defaults.
- Custom Field groups.
- Whether Intake Items are enabled.
- Portal visibility defaults.
- Closure requirements.

The first related step is binding Custom Fields to existing Ticket Types so the selected Ticket Type
controls which extra fields appear on Ticket create/edit/show.

### 5. Ticket Templates

Ticket Templates are reusable case recipes.

Examples:

- New User Setup.
- Laptop Repair.
- Server Maintenance.
- Backup Failure.
- Security Review.

A Ticket Template may apply:

- Ticket Type.
- Workflow.
- Queue.
- Priority.
- Category.
- SLA.
- Subject pattern.
- Description body.
- Custom Field default values.
- Task Templates.
- Checklist.
- Intake Item defaults.
- Approval policy later.

### 6. Ticket Intake Items

Ticket Intake Items should be introduced after the configurable foundations exist.

An Intake Item represents a physical object delivered for service and belongs to a Ticket.

Minimum fields:

- Ticket ID.
- Optional Asset ID.
- Status.
- Description.
- Manufacturer.
- Model.
- Serial number.
- Notes.
- Received timestamp.
- Returned timestamp.
- Created by.
- Updated by.

An Intake Item may be linked to an existing Asset, create a new Asset, or remain unlinked.

### 7. Asset Service History

Assets should show a permanent service history based on linked Tickets and Intake Items.

The first version can derive history from existing Ticket and Intake Item records instead of copying
data into a separate service ledger. A dedicated service record table can be added later if reporting
or immutability requires it.

### 8. Service Scheduling

Service intervals should come after the Asset and Ticket Template foundations are stable.

Supported future interval examples:

- Every 12 months.
- Every 24 months.
- Every 15,000 km.
- Every 500 operating hours.
- Custom interval types.

The first scheduling pass should support manual "create maintenance ticket" behavior before fully
automatic ticket generation is enabled.

### 9. Receipts, Signatures, And Portal

These are valuable but should not be hardcoded into Asset Service.

Future platform capabilities:

- Reusable receipt/document generation.
- Reusable digital signature engine.
- Customer approval improvements.
- Customer portal visibility for service status, approvals, receipts, and service history.

## Explicit Non-Goals For The First Pass

- Do not build a separate repair module.
- Do not hardcode Asset Service as its own workflow engine.
- Do not create a second approval engine.
- Do not build digital signatures before the reusable signature concept is designed.
- Do not build asset hierarchy, loan management, or password vault references in version 1.

## Suggested Future Workstream Name

Service Workshop Foundation.

This name covers equipment intake, service work, maintenance, task recipes, templates, and asset
history without locking Nexum into a single industry such as IT repair.
