# Tech.Admin.Billing – General Module Specification

**URL:** `admin/billing`
**Access:** `billing.view`, `billing.manage`, `billing.admin`
**Creation date:** 2025-11-06
**Status:** Not started
**Difficulty:** High
**Estimated time:** 7.5 hours

---

## Purpose

Centralized module for **collecting, reconciling and invoicing billable data** across all operational modules.

The Billing module itself does **not create** billing data; instead, it **aggregates and manages** records written by source modules such as Sales, Tickets, Contracts, and Timebanks.

Its core functions are:

* Gather all `billing_items` with status `pending`.
* Allow recalculation of billable data per contract, client or period.
* Build draft invoices from the current `billing_items`.
* Finalize and post invoices, locking the relevant items.
* Support credit notes and reversals.

---

## Billing Item Flow

### 1. Source Data

Each operational module writes billing-ready data into `billing_items` when relevant events occur:

| Source                   | Event                                       | Result                                                                    |
| ------------------------ | ------------------------------------------- | ------------------------------------------------------------------------- |
| **Tickets**              | Technician logs time                        | Write/refresh `billing_items` with duration, rate, and contract reference |
| **Contracts / Timebank** | Period activation or drawdown               | Generate recurring or deduction `billing_items`                           |
| **Sales / Orders**       | Shipment, activation or manual invoice line | Write product/service `billing_items`                                     |
| **Expenses / Purchases** | Marked as billable                          | Create corresponding `billing_items`                                      |
| **Credits / Notes**      | Adjustment or refund                        | Negative `billing_items` with link to original                            |

---

## Lifecycle and States

| State                | Meaning                                      |
| -------------------- | -------------------------------------------- |
| **pending**          | Created by source module, awaiting invoicing |
| **reserved (draft)** | Included in an invoice draft (locked)        |
| **invoiced**         | Finalized and posted to accounting           |
| **credited / void**  | Reversed or cancelled line                   |
| **superseded**       | Replaced by updated data from recalculation  |

---

## Reconciliation Logic

Billing items are not permanently tied to contracts or invoices until reconciliation occurs. Reconciliation:

* Matches each source (e.g. time entry) with the correct contract, rate card or timebank.
* Creates or updates `billing_items` accordingly.
* Marks older ones as `superseded` when an entry changes.

### Triggering Modes (Standard: Both)

1. **Immediate on save:** When a time entry or sales event is saved, billing data is updated instantly.
2. **On-demand recalculation:** Manual action available on contract, client or billing pages — recalculates selected scope.

Both modes can be active per tenant; manual recalculation always overrides and refreshes prior results.

---

## Periods and Invoicing

**Default period:** Calendar month (1–last day, Europe/Oslo, cut-off 23:59).
**Default filter:** Include all `pending` items regardless of period if they are not invoiced or reserved.

Process:

1. Select target period and clients.
2. Review and adjust included items.
3. Build draft invoice(s) → lock items (`reserved`).
4. Edit or approve drafts.
5. Post invoices → mark items as `invoiced`.

---

## Data Model (simplified)

**Table: billing_items**

| Column                  | Type       | Description                                          |
| ----------------------- | ---------- | ---------------------------------------------------- |
| id                      | bigint     | PK                                                   |
| source_type             | string     | e.g. `ticket_time`, `contract_period`, `sales_order` |
| source_id               | bigint     | Reference to origin record                           |
| client_id               | bigint     | Client reference                                     |
| contract_id             | bigint     | Optional, if linked to timebank/contract             |
| description             | text       | Item description                                     |
| qty                     | decimal    | Quantity or duration                                 |
| unit_price              | decimal    | Price per unit                                       |
| total                   | decimal    | Computed total                                       |
| status                  | enum       | pending/reserved/invoiced/etc.                       |
| invoice_id              | bigint     | Link to final invoice if applicable                  |
| reconciliation_run_id   | bigint     | ID of reconciliation batch                           |
| period_start / end      | datetime   | Optional period window                               |
| created_at / updated_at | timestamps |                                                      |

---

## Editing, Locking & Refresh

**Hard locking while in draft**

* Når `billing_items` er lagt i en run og tilknyttet et utkast (`status = reserved`), blir **kildepostene skrivebeskyttet** i UI og API.
* Forsøk på å redigere en tilhørende kilde (ticket time, expense, sales line) blokkeres med beskjed: *“Denne posten er låst av fakturautkast {invoice_no/run_id}. Fjern den fra utkastet eller slett kjøringen for å redigere.”*
* For å redigere må brukeren enten:

  1. **Fjerne linjen fra run/draft** → `billing_items.status` settes tilbake til `pending`, eller
  2. **Slette hele run** (kun når run = `open`).

**Etter posting (endelig faktura)**

* `billing_items` merkes `invoiced` og er **permanent låst**. Kildepostene er også skrivebeskyttet.
* Endringer håndteres alltid via **kreditering/debet** (nye `billing_items` lenket til opprinnelig).

**Refresh-regel**

* “Refresh from sources” er kun relevant for linjer som ble endret **før** de ble reservert. Når en post er reservert/draft-låst, kan den ikke endres i kilden; oppdatering skjer ved å frigjøre og rekalkulere.

UI-hint:

* Badge på låste rader: *Locked (Draft)* / *Locked (Posted)*.
* Hurtigknapp på draft-linjer: **Remove from run** (frigjør til `pending`).

---

## UI / UX Requirements

**Header zone:** Period selector, client filter, global actions (Recalculate, Build Drafts, Post).
**Main list:** Data table of billing items grouped by client and source. Columns: description, qty, unit, total, status, source ref.
**Right panel:** Quick info about selected client or invoice.

Widgets:

* Button: *Recalculate Billing*
* Button: *Build Draft Invoice*
* Button: *Post Invoices*
* Toggle: *Include prior periods*
* Indicator: *Stale draft* (red badge when outdated)

---

## Logging

Every reconciliation, draft build, and posting must log:

* Triggered by (user/system)
* Scope (client/contract/period)
* Affected item count
* Timestamp and result summary

---

## Summary

Billing is a **read-and-aggregate layer**, not a generator. All billable logic lives in source modules.
It supports **real-time + manual recalculation**, calendar-month periods by default, and flexible inclusion of all pending items for invoicing.
This ensures traceability, predictable reconciliation, and stable data even without cronjobs.
