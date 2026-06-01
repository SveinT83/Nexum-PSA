# Email Rules — General Documentation

**URL namespace:** `tech.admin.settings.email.rules.*`

**Access & permissions:**

- View list: `email.rules.view.admin`
- Create/Edit/Delete: `email.rules.manage.admin`

**Creation date:** 2025-10-23  
 **Controller path:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Email\\RulesController`  
 **Status:** In progress  
 **Difficulty:** Medium  
 **Estimated time:** 3.5 hours

---

## 1) Purpose

The email rules system automates handling of **incoming and outgoing emails** before they reach other subsystems (such as tickets, sales, or alerts).  
 It ensures predictable routing, categorization, and filtering based on defined conditions, while maintaining a clean and traceable audit trail.

This layer acts as a **preprocessor** for all messages that arrive through the configured IMAP accounts or are queued for SMTP delivery.  
 Its purpose is to:

- Apply conditions and actions before tickets or leads are created.
- Filter, reject, forward, or tag emails according to internal logic.
- Maintain operational transparency through clear logging and testable configuration.

---

## 2) Rule Model

**EmailRule**

- `id`, `name`, `description`
- `status` (Active / Inactive)
- `trigger` (OnInbound / OnOutbound / OnError)
- `conditions` (collection)
- `actions` (collection)
- `weight` (integer priority; lower number = higher precedence)
- `stop_processing` (boolean)

Rules are evaluated **in order of weight**, stopping when `stop_processing` is true and the rule has executed successfully.

---

## 3) Rule Triggers

- **OnInbound:** executed when an incoming email is fetched and parsed.
- **OnOutbound:** executed before an outgoing message is sent.
- **OnError:** executed when delivery or parsing fails.

Each trigger type has its own subset of valid actions and conditions.

---

## 4) Conditions

Conditions evaluate message metadata and headers before action execution. Supported condition types:

- Sender address / domain match
- Recipient (To / Cc / Bcc) match
- Subject or body contains text or regex
- Attachment presence or file type
- Size (greater/less than defined limit)
- Header or flag (e.g., auto-reply, spam, DKIM result)
- Account or mailbox origin

Conditions can be combined using logical **AND/OR** grouping.

---

## 5) Actions

Actions are executed in sequence when conditions evaluate to true. Supported actions:

- Forward email to another address
- Drop (ignore silently)
- Reject (bounce with message)
- Move to IMAP folder
- Mark as read/unread
- Apply tag (for later parser processing)
- Set priority level for downstream ticket creation
- Assign to internal queue (e.g., tickets, leads, alerts)

Multiple actions can be stacked per rule.

---

## 6) Controller Responsibilities

**Controller:** `RulesController`

**Routes:**

- `index()` — list and manage existing rules
- `create()` — render creation form
- `store(Request)` — save new rule
- `edit(Rule)` — edit existing rule
- `update(Rule, Request)` — save changes
- `destroy(Rule)` — delete rule
- `test(Rule, Message)` — test execution against stored or live sample email

**Business rules:**

- Validate trigger and action compatibility.
- Enforce unique weight per rule (auto-adjust if collision).
- Never modify email body content unless explicitly requested by an action.
- Respect processing order and `stop_processing` logic.

---

## 7) Email Processing Flow

1. **Inbound Email Received** via IMAP account.
2. Message parsed → metadata extracted.
3. Rules with `trigger=OnInbound` evaluated by weight.
4. First matching rule executes its actions.
5. If `stop_processing=true`, stop; else continue.
6. Message passes to subsystem (Ticket/Sales/Alerts) or is dropped/forwarded.

Outbound and error flows mirror the same logic with appropriate triggers.

---

## 8) Error Handling & Testing

- Rules can be manually tested with stored sample emails.
- Failed evaluations are logged in `email_rule_logs` with rule ID, message ID, and error.
- When an action fails (e.g., invalid forward address), subsequent actions are skipped and error recorded.

**System feedback:**

- Index view shows rule health (OK / Warning / Error).
- Errors reflected in the side info panel for troubleshooting.

---

## 9) Security & Auditing

- All configuration changes recorded with user ID and timestamp.
- Rules cannot leak or expose email content outside allowed actions.
- Execution logs stored with redacted header/body samples.

---

## 10) UI Elements & Layout Notes

- Index shows: Rule name, trigger, weight, status, and quick indicators (active, error).
- Create/Edit shares same layout; conditions and actions are added dynamically in scrollable sections.
- Buttons: Save, Test, Close.
- Right panel: latest test result and example message metadata.

---

## 11) Non‑functional Requirements

- Must process messages in under 1 second per rule chain (target).
- Must run asynchronously (queued worker) to avoid blocking IMAP/SMTP pipelines.
- All actions and logs are tenant‑isolated.
- Fully traceable, predictable, and configurable without code changes.