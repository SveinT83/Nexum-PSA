**View:** tech.admin.settings.email.rules.create
**URL:** /tech/admin/settings/email/rules/create
**Access:** email.rules.manage
**Controller:** App\Http\Controllers\Tech\Admin\Settings\Email\RulesController
**Status:** Not started
**Difficulty:** Medium
**Estimated time:** 3.0 hours

---

### Purpose

This view allows administrators to create and configure **Global Email Rules** that apply to all incoming emails. Rules can filter, classify, enrich or mark emails for deletion before they are routed to modules such as Tickets or Leads. These rules are part of the Global Email Hub and run after parsing/classification but before module handover.

---

### Layout

* **Header section:**

  * Title: *Create Email Rule*
  * Breadcrumbs: Settings → Email → Rules → Create
  * Buttons: [Save] [Cancel]

* **Form sections:**

  1. **General Info**

     * Rule Name (text, required)
     * Description (textarea, optional)
     * Weight / Priority (integer, default 10)
     * Status (toggle: Enabled / Disabled; default Enabled)

  2. **Conditions Builder**

     * Add one or more conditions.
     * Each condition row:

       * Field dropdown → From, To, Cc, Subject, Sender domain, Body contains, Has subject token, Is reply
       * Operator dropdown → equals, not equals, contains, startsWith, endsWith, regex
       * Value (text input)
     * [+ Add condition] button appends more rows.
     * Conditions evaluated using logical AND; future extension for OR groups possible.

  3. **Actions Builder**

     * Add one or more actions.
     * Available actions:

       * set.client (dropdown: existing clients)
       * set.tags (text, comma separated)
       * set.priority (dropdown: low, normal, high)
       * route.module (dropdown: Tickets, Leads, Global Inbox)
       * mark.delete (checkbox, marks for deletion with 24h pending period)
     * [+ Add action] button appends more rows.

  4. **Flow Control**

     * Continue evaluation (checkbox, default checked). If disabled, rule execution stops here.
     * Note: Deleted or routed messages bypass remaining rules.

  5. **Audit Preview (read-only placeholder)**

     * Shown after save. Displays creation date, creator, and last update.

---

### Buttons & Behavior

* **Save:** Validates all required fields and saves immediately as Enabled.
* **Cancel:** Returns to rules.index without saving.
* **Validation:**

  * Rule Name required.
  * At least one condition and one action required.
  * If *mark.delete* selected, system enforces 24h pending deletion policy automatically.
* **Auto-order:** If weight not specified, defaults to 10. Rules sorted by weight ascending.

---

### UX Notes

* Same builder interaction model as Ticket Rules create view.
* Inline condition/action add/remove for quick composition.
* Auto-focus on next empty field when adding new condition/action.
* No test tool integrated (testing handled separately in rule list).
* Save immediately enables rule and publishes it to the global rules engine.
* Deleted items appear in Pending Deletion Inbox (visible under Email → Logs & Health).
* Inline notifications:

  * ✅ "Rule saved successfully."
  * ⚠️ "Invalid condition or missing action."
  * 🔒 Confirmation modal appears if mark.delete is used.

---

### Controller Requirements

`store()` in **RulesController** must:

1. Validate input (name, conditions, actions).
2. Serialize conditions/actions to JSON for storage.
3. Default status = enabled; default weight = 10.
4. Tag rule as global (no per-account scope).
5. Audit creation (who/when).
6. Trigger rule engine cache refresh after save.

---

### Related Views

* `tech.admin.settings.email.rules.index` – list and reorder existing rules.
* `tech.admin.settings.email.rules.edit` – modify existing rule definitions.

---

### Future Enhancements

* Support for per-account scoping (multi-account rule targeting).
* Visual test harness (simulate inbound email).
* Versioning of rule definitions with rollback support.
