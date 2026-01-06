**View:** tech.admin.settings.email.accounts.create
**Access:** superuser, tech.admin
**Controller:** App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController
**Status:** Not started
**Difficulty:** Medium
**Estimated time:** 3.0 hours
**URL:** /tech/admin/settings/email/accounts/create

---

### Purpose

This unified view handles both **creating** and **editing** email accounts for use across PSA modules (Tickets, Sales, Alerts/System). It ensures secure configuration, routing, and communication reliability.

---

### Layout

**Bootstrap template:**

* Top header: Title and breadcrumb navigation
* Main content: Form sections
* Right slim panel: Save/Test and Close actions

---

### Sections

#### 1. General Information

* **Email address** *(input, required)*
* **Display name (From name)** *(optional)*
* **Description** *(textarea or short text)*
* **Active / Inactive** *(toggle button)*
* **Default usage selectors:**

  * Checkbox group: `Global`, `Tickets`, `Sales`, `Alerts/System`
  * Only one account can be global default; per-system defaults are allowed.

#### 2. IMAP Settings

* **IMAP Server** *(input, required)*
* **Port** *(dropdown, prefilled common IMAP ports; no custom ports)*
* **Encryption** *(dropdown: SSL / TLS / STARTTLS)*
* **Username** *(input, required)*
* **Password** *(password input, required)*

  * Info note: *If your account uses 2FA, use an app-specific password.*

#### 3. SMTP Settings

* **SMTP Server** *(input, required)*
* **Port** *(dropdown, prefilled common SMTP ports; no custom ports)*
* **Encryption** *(dropdown: SSL / TLS / STARTTLS)*
* **Username** *(input, required)*
* **Password** *(password input, required)*

  * Info note: *If your account uses 2FA, use an app-specific password.*

---

### Actions

* **Save & Test** *(primary button)*

  * Tests both IMAP and SMTP connections after saving configuration.
  * Displays detailed error messages if any test fails (e.g., authentication, host not found, port closed).
  * Success message confirms valid configuration.
* **Close** *(secondary button)*

  * Returns to index view without saving.

---

### UI/UX Details

* Connection status shown inline after test (green check or red warning triangle).
* Input validation with inline hints for incorrect formats or missing fields.
* Pre-filled port/encryption combinations (e.g., IMAP SSL:993, SMTP SSL:465).
* Default selection rules enforced dynamically (radio logic on Global Default).
* All sensitive fields masked, with optional show/hide toggle for passwords.

---

### Smart UX Suggestions

* Auto-test connection immediately after first save.
* Display last successful connection timestamp.
* Optional auto-disable account if connection fails repeatedly.
* Tooltip hints on encryption and authentication method.

---

### Dependencies

* EmailAccount model (shared with index)
* EmailTestService (for live connection test)
* Settings repository for default management

---

### Validation Rules

* Email format required
* IMAP/SMTP host required
* Port must be integer and match encryption preset
* At least one default (global or per system) must exist overall
