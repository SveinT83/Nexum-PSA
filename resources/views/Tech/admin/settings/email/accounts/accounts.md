# Email Accounts — General Documentation

**URL namespace:** `tech.admin.settings.email.accounts.*`

**Access & permissions:**

- View list: `email.accounts.view.admin`
- Create/Edit: `email.accounts.manage.admin`
- Toggle Active/Default: `email.accounts.manage.admin`
- Run tests: `email.accounts.manage.admin`

**Creation date:** 2025-10-22  
 **Controller path:** `App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController`  
 **Status:** In progress  
 **Difficulty:** Medium  
 **Estimated time:** 4.0 hours

---

## 1) Purpose

Manage inbound (IMAP) and outbound (SMTP) email accounts used across the platform. Provide predictable setup, continuous health monitoring, and clear surfacing of errors in both the index and the create/edit views.

- Single static layout (Bootstrap): Top header / Main content / Narrow right-side panel.
- Supports only standard IMAP/SMTP servers. Encryption is required; choice is user-selectable.
- No custom ports; users choose from predefined, sane defaults per encryption mode.
- Optional OAuth2 support when the server supports it.

---

## 2) Entities & Fields

**EmailAccount**

- `id`, `address`, `description`, `from_name` (optional), `is_active`, `is_global_default`
- `defaults_for` (multi-select: tickets, sales, alerts/system)
- IMAP: `host`, `port` (dropdown), `encryption` (dropdown: SSL/TLS, STARTTLS), `username`, `secret` (encrypted), `auth_type` (Password | OAuth2)
- SMTP: `host`, `port` (dropdown), `encryption` (dropdown), `username`, `secret` (encrypted), `auth_type` (Password | OAuth2)
- Health: `last_test_at`, `last_test_result` (OK | Warning | Error), `last_error_code`, `last_error_message`, `last_successful_fetch_at`, `last_successful_send_at`
- Audit: created/updated by + timestamps

**Constraints**

- Exactly one **global** default. Each subsystem (tickets, sales, alerts/system) can have at most one default (may be the same as global). Global falls back when a subsystem has no explicit default.

---

## 3) Views

### 3.1 Index — `tech.admin.settings.email.accounts.index`

**Goal:** List all accounts with quick state and default indicators; minimal but clear signaling of problems.

**Header:** Title, "Add account" button.

**List columns:**

- Address (with description as subtext)
- Status pill (Active/Inactive)
- Default badges: Global / Tickets / Sales / Alerts
- Health icon: OK (check), Warning, Error (triangle) — no extra text here; details via hover or side panel
- Actions: Edit, Activate/Deactivate (toggle)

**Right-side panel (contextual):**

- Selected account summary
- Last health check timestamps and error snippet
- Quick action: "Run Full Test"

**UX details:**

- Index is fast, two-line rows, icons for defaults; a single Edit button; separate Activate/Deactivate toggle.
- Health indicator updates in near‑real‑time from background jobs.

**State markers:**

- Problem states visually marked; do not color entire row. Only priority/health icon uses accent.

### 3.2 Create/Edit — `tech.admin.settings.email.accounts.create` (shared form)

**Goal:** Predictable, detailed form to configure both IMAP and SMTP in one place.

**Sections:**

1. General
   - Email address, Description, From name (optional)
   - Default assignment (checkboxes: Global, Tickets, Sales, Alerts)
   - Active toggle
2. IMAP (inbound)
   - Host, Encryption (required; dropdown), Port (dropdown preset by encryption), Username, Secret (password), Auth type
3. SMTP (outbound)
   - Host, Encryption (required; dropdown), Port (dropdown preset by encryption), Username, Secret (password), Auth type

**Buttons:**

- Save
- Run Full Test (tests both IMAP + SMTP end‑to‑end)
- Close/Exit

**Validation & errors:**

- If a test fails, show precise failure reason directly in the form, anchored to the relevant section (IMAP vs SMTP) and mirrored to the right rail.

**Right-side panel:**

- Inline documentation/tips (encryption required; no custom ports)
- Latest health state and timestamps

---

## 4) Controller — Responsibilities & Methods

**Controller:** `AccountsController`

**Routes:**

- `index()` — list accounts
- `create()` — render form (new)
- `store(Request)` — persist new
- `edit(Account)` — render form (existing)
- `update(Account, Request)` — persist changes
- `toggleActive(Account)` — enable/disable
- `setDefault(Account, scope)` — set default for Global/Tickets/Sales/Alerts (enforces uniqueness per scope)
- `runTest(Account)` — synchronous on-demand health test (IMAP login + list/select inbox + NOOP; SMTP login + EHLO + STARTTLS if applicable + NOOP/send‑simulation)
- `health(Account)` — returns latest cached health payload for UI

**Business rules:**

- Enforce single global default and one default per subsystem.
- Prevent saving without encryption or with unsupported port.
- Persist secrets encrypted at rest; never log secrets.

---

## 5) Background Health & Surfacing Errors

**Scheduler/Jobs:**

- `EmailAccountHealthCheckJob` (queued, per account)
  - Frequency: every 5–10 minutes (configurable). Stagger across accounts.
  - Tests both IMAP and SMTP; records `last_test_at`, `last_successful_fetch_at`, `last_successful_send_at`, and structured error codes/messages.
  - Emits domain events: `EmailAccountHealthFailed`, `EmailAccountHealthRecovered`.

**Surfacing:**

- Index shows only an icon (OK/Warning/Error). Hover/side panel reveals recent error.
- Create/Edit shows full, actionable error messages with section anchors.
- Related subsystems (tickets, sales, alerts) receive a non‑blocking toast/notification if their configured default account enters Error state.

**Failure propagation:**

- When a subsystem attempts to send/receive and the account is in Error, return a soft failure to the UI and log to `email_logs` with correlation to the subsystem entity (ticket id, etc.).

---

## 6) Security & Secrets Handling

- Passwords/secrets stored **encrypted at rest** (application‑level encryption). Keys are not stored in the database.
- Support OAuth2 where possible to reduce stored secrets.
- Redact secrets in all logs and responses.
- Role-based access control as above; all changes recorded in an audit log.

---

## 7) Logging & Auditing

- `email_accounts` change log (who/when/what).
- `email_health_checks` for background job results.
- `email_logs` for operational errors per subsystem (send/receive attempts).

---

## 8) Widgets & Icons (suggested)

- Health status chip (widget)
- Default scope badges (Global, Tickets, Sales, Alerts)
- Icons: envelope (account), shield/lock (encryption), triangle (error), check (ok), power (active), star (global default)

---

## 9) Non‑functional

- Fast to operate on desktop/mobile (PWA friendly).
- Real‑time updates (Livewire/WebSockets where appropriate) for health indicators.
- Tenant isolation respected across all data and jobs.