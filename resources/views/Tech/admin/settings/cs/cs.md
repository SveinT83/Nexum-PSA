# Contract & Service (CS) System – General Overview

**Date:** 2025-10-23  
 **Scope:** Contract and Service Core System  
 **Controllers:**

- `App\\Http\\Controllers\\Tech\\Admin\\Settings\\CS\\ServicesController`
- `App\\Http\\Controllers\\Tech\\Admin\\Settings\\CS\\ContractsController`  
   **Status:** In progress  
   **Difficulty:** Medium  
   **Estimated time:** 5.0 hours

---

## 1) Purpose

The **Contract & Service (CS)** subsystem defines how tdPSA manages all billable and non-billable services offered to clients, and how these services are bound by contracts. It ensures consistency between catalog items, contractual terms, binding durations, indexing rules, and notifications.

This layer serves as the foundation for billing, timebank, and SLA modules. It connects operational systems (Tickets, Workflows, SLAs) to commercial logic (Contracts, Services, Indexing).

---

## 2) Structure Overview

| Component       | Description                                                                                                                |
|-----------------|----------------------------------------------------------------------------------------------------------------------------|
| **Service Catalog** | Central repository of all service definitions (SKU, name, pricing model, SLA defaults). Used across clients and contracts. |
| **Contracts**       | Agreements defining how services apply to a specific client, including terms, duration, and indexing rules.                |
| **Bindings**        | Logical link between a contract and the services it includes. Tracks start, end, indexing, and deprecation.                |
| **Indexing Engine** | Periodic adjustment of service prices under contract rules.                                                                |
| **Notifications**   | Alerts for renewal, termination, and indexing.                                                                             |

---

## 3) Service Catalog (ServicesController)

**URL:** `/tech/admin/settings/cs/services`  
 **Access:** `service.settings.manage`, `tech.admin`, `superuser`

### Purpose

Defines how services are structured, versioned, and reused across clients. Prevents duplicates and maintains SKU integrity.

### Core Features

- SKU auto-generation (prefix, numeric, padding, collision policy)
- Category & taxonomy manager (activate/deactivate, default category)
- Default billing model (per user / per asset / fixed / tiered)
- Default SLA profile and visibility (Active/Hidden)
- Binding & downgrade defaults (allow binding, binding duration, downgrade rules)
- Indexing defaults (max %, allow decreases)
- Deprecation & visibility policy (hide deprecated, require reason)
- Admin notifications on create/edit/deprecate

### Livewire Components

- `ServiceSettingsPanel`
- `SkuFormatEditor`
- `CategoryManager`
- `DefaultsPanel`
- `BindingRulesPanel`
- `IndexingDefaultsPanel`
- `DeprecationVisibilityPanel`
- `NotificationsPanel`

### Data Integrity

- Each service is unique by SKU and Name.
- Services can be globally active or hidden per tenant.
- Deprecation rules ensure backward compatibility for existing contracts.

---

## 4) Contracts (ContractsController)

**URL:** `/tech/admin/settings/cs/contracts`  
 **Access:** `contract.settings.manage`, `tech.admin`, `superuser`

### Purpose

Controls how service bindings form legal and operational contracts. Defines renewal, binding, indexing, and approval logic.

### Core Features

- Global binding and renewal rules (auto-renew, must-renew, or floating)
- Default binding duration and downgrade policies
- Indexing rules (max %, allow decreases, post-binding index)
- Workflow integration (approval expiry, edit permissions during approval)
- Notification policies (renewal, termination, indexing)
- Service-level enforcement (respect service binding, prevent bound service removal)
- Data retention and purge rules (terminated contracts, audit logs)

### Livewire Components

- `ContractSettingsPanel`
- `IndexingRulesPanel`
- `NotificationsPanel`
- `WorkflowPanel`
- `ServiceBindingPanel`
- `DataRetentionPanel`

---

## 5) Contract & Service Relationship

The **binding layer** defines how services exist within contracts. Each binding maintains lifecycle data:

| Field                 | Description                                          |
|-----------------------|------------------------------------------------------|
| contract_id           | Parent contract reference                            |
| service_id            | Linked catalog item                                  |
| quantity              | Units applied (per user, asset, or fixed)            |
| start_date / end_date | Effective period                                     |
| binding_months        | Duration before renewal or change allowed            |
| current_price         | Last indexed unit price                              |
| indexing_allowed      | Boolean; follows global or service-level setting     |
| downgrade_allowed     | Boolean; may differ from service default             |
| deprecated_flag       | Marked if service is deprecated under catalog policy |

Bindings are immutable once expired but auditable for history and reporting.

---

## 6) Dependencies and Cross-Modules

| Module                | Integration                                                             |
|-----------------------|-------------------------------------------------------------------------|
| **Tickets**               | Pulls SLA and cost center defaults from active contract bindings.       |
| **Workflows**             | Uses contract type or SLA profile for validation rules.                 |
| **Billing / Timebank**    | Reads binding and indexing data to calculate usage and invoices.        |
| **Reports**               | Aggregates active services, binding expiries, and indexing adjustments. |
| **Audit / Notifications** | Logs all policy changes and contract events.                            |

---

## 7) Validation & Audit

- Numeric ranges validated (binding months, percentages, etc.)
- SKU and service name uniqueness enforced globally.
- Changes in defaults instantly update creation templates for new services/contracts.
- All saves trigger audit entries with before/after diffs.
- Warnings for changes affecting active contracts (e.g., binding removal, indexing toggle).

---

## 8) Edge Cases

- Reducing SKU padding or prefix may collide with existing SKUs → require confirmation.
- Changing binding defaults while active contracts exist → show warning modal.
- Disabling indexing while jobs queued → must confirm and cancel queue.
- Deprecating active services → blocked or warned per configured policy.

---

## 9) QA Scenarios

1. Create a new service, verify SKU auto-generation preview.
2. Bind service in contract, test renewal and indexing rules.
3. Toggle downgrade disallowed → attempt downgrade → expect block.
4. Modify deprecation policy to "warn" and attempt to deprecate bound service.
5. Adjust default billing model → confirm reflection in `tech.cs.services.create`.

---

## 10) Security & Permissions

| Action                            | Permission                                         |
|-----------------------------------|----------------------------------------------------|
| View settings                     | `tech.admin`                                         |
| Edit/Save                         | `service.settings.manage` / `contract.settings.manage` |
| Reset to defaults                 | `superuser`                                          |
| Approve binding or purge archives | `superuser`                                          |

---

## 11) Future Extensions

- **Billing integration:** automatic invoice generation from bindings.
- **Indexing scheduler:** tenant-based cron for price adjustments.
- **Contract versioning:** maintain previous term snapshots for audits.
- **Client portal visibility:** allow clients to see active contract bindings and renewals.

---

## 12) Summary

The Contract & Service system unifies catalog definition, contract policy, and service binding under one governance model. It provides traceable, predictable behavior across all client agreements, ensuring that operational and financial automation (tickets, billing, SLAs) share consistent data and rules.