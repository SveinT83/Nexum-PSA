# tech.sales.leads\* — General Documentation

**Creation date:** 2025-10-29  
 **Access levels:** `sales.view`, `sales.manage`, `client.view`  
 **Controller namespace:** `App\\Http\\Controllers\\Tech\\Sales\\Leads\\*`  
 **Livewire namespace:** `App\\Livewire\\Tech\\Sales\\Leads\\*`  
 **Status:** In progress  
 **Difficulty:** Medium  
 **Estimated time:** 5.0 hours

---

## Purpose

The **Leads System** (`tech.sales.leads*`) provides an overview and management interface for **potential clients** who do not yet have an active contract. It serves as the first step in the sales pipeline, before conversion to a **prospect** or **order**.

A *lead* represents a business or individual that has been identified as a potential customer. Once sales activity begins (e.g., quotation, discussion, or contract draft), the lead can be converted into a **prospect**, which then transitions into the **Sales module**.

The goal is to centralize all possible sales opportunities, allowing sales teams to sort, filter, and prioritize clients based on company size, industry, asset volume, or previous contact history.

---

## Key Concepts

- **Leads** = clients without active or draft contracts.
- **Prospects** = leads that have progressed to having a contract proposal.
- **Orders** = confirmed or invoiced sales, handled in the `tech.sales.show` module.
- **Lead Priority** can be manually assigned to help focus on the most promising opportunities.
- **Lead History** displays past sales attempts or notes to avoid redundant follow-ups.

---

## Layout and Structure (Bootstrap)

Standard layout with **top bar**, **main content section**, and **right slim panel**.

Suggested icons: `user-search`, `list`, `tags`, `clock`, `star`, `note`, `chart-bar`.

---

## Related Views

### `tech.sales.leads.index`

- **Purpose:** Displays a sortable and filterable list of all leads.
- **URL:** `/tech/sales/leads`
- **Access:** `sales.view`
- **Controller:** `App\\Http\\Controllers\\Tech\\Sales\\Leads\\IndexController@index`
- **Reference:** Detailed documentation available in `tech.sales.leads.index` specification.

### `tech.sales.leads.show`

- **Purpose:** Displays detailed lead information, including linked client, notes, previous contact attempts, and shortcuts to create contracts or sales orders.
- **URL:** `/tech/sales/leads/{id}`
- **Access:** `sales.view`, `sales.manage`, `client.view`
- **Controller:** `App\\Http\\Controllers\\Tech\\Sales\\Leads\\ShowController@show`
- **Reference:** Detailed documentation available in `tech.sales.leads.show` specification.

---

## Integration & Workflow

- Automatically populated with **clients missing active contracts**.
- Supports manual prioritization and optional categorization (e.g., by industry or region).
- Can be filtered to include expired or draft contracts.
- Quick actions link directly to `tech/contracts/create` and `tech/sales/create`.
- Designed for fast navigation between potential clients and confirmed sales.

---

## Future Extensions

- Integration with marketing systems for automatic lead import.
- Rule engine automation to promote leads to prospects based on predefined conditions.
- KPI widgets showing conversion rates and pipeline metrics.

---

**Document note:** This is the general documentation for the Leads System. Detailed view specifications are maintained separately under `tech.sales.leads.index` and `tech.sales.leads.show`.