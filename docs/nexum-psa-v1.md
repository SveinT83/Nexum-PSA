# tdPSA – Phase 1 Plan (MVP)

## Scope

1. **Ticket list (read-only)** – show incoming tickets.
2. **Settings → Email accounts** – add/manage accounts to listen on.
3. **Settings → Incoming email parsing/routing** – parser profiles to map headers to fields (customer, site, asset, alert).
4. **Settings → Tickets → Queues** – configure queues.
5. **Settings → Tickets → Categories** – configure categories/issues.
6. **Settings → Tickets → Rules** – if/then rules for routing, categories, priority.
7. **Mail-listener** – read enabled accounts, parse, apply rules, dedup, append/create tickets.

---

## Defaults

* **Queue (unmatched):** `default`
* **Category (unmatched):** `Uncategorized`
* **Priorities:** `Low`, `Normal`, `High` (later DB-customizable)
* **Default priority:** `Normal`
* **Language:** English (V3 = email templates for customer replies)

---

## Data model (simplified)

* `email_accounts`
* `parser_profiles`
* `queues`
* `categories`
* `priorities`
* `rules`
* `tickets`
* `ticket_messages`
* `rule_executions`

---

## Features per area

### Tickets → List

* Columns: ID, Title, Queue, Category, Priority, Source, Updated.
* Filters: Queue, Category, Source.
* Default sort: Updated DESC.

### Settings → Email accounts

* CRUD for accounts.
* Fields: host, port, encryption, username, label, enabled.
* Function: **Test connection**.

### Settings → Parsers

* Multiple profiles per account.
* Match on: from, subject, header.
* Field-map: customer, site, asset, alert.
* Fallback: Generic parser.

### Settings → Queues

* CRUD.
* One marked as **default**.

### Settings → Categories

* CRUD (flat, later: parent/child).
* Default = `Uncategorized`, cannot be deleted.

### Settings → Rules

* List + drag-sort.
* Toggle on/off.
* Condition builder (field + operator + value).
* Actions: set queue, category, priority, tags, assign.
* Policy: **stop on match** when action = final.

### Settings → Incoming test (dry-run)

* UI: headers + body + subject + from (prefilled standard).
* Output:

  1. Parser matched
  2. Extracted fields
  3. Dedup check
  4. Thread match
  5. Rule trace (true/false)
  6. Outcome (queue, category, priority, new/append/suppressed)
* API endpoint also available for automation.

### Mail listener

* Load enabled accounts from DB.
* Flow:

  1. Dedup (5 min window)
  2. Thread match
  3. Parser
  4. Rules
  5. Create/Append ticket
* Dedup/merge policy:

  * Within 5 min → suppress, log.
  * After 5 min with same key → append note ("Duplicate merged").

### Observability

* `rule_executions` log.
* Raw message reference stored (for debug).
* Metrics: incoming, suppressed, new, appended.

---

## Acceptance criteria

* Email to enabled account → appears in list within polling cycle.
* Duplicate (same key within 5 min) → suppressed.
* Reply (In-Reply-To / [T#1234]) → appended to correct ticket.
* No parser match → Default queue/category/priority.
* Dry-run test produces same outcome as real intake.

---

## Open points for later

* Source-tagging visible in ticket list?
* Tags in v1 or push to v2?
* Dedicated “Untriaged view” for default queue?

---

## Next step

Create a **week 1 build todo**: day-by-day tasks (data, settings UI, listener skeleton, ticket list).
