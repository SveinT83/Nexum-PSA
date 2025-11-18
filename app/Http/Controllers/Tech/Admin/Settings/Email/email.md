# Email system — architecture, files, and extension guide

This document explains how the email subsystem works end‑to‑end: the goals and flows, the data model, which files do what, and how to extend or reuse pieces from other areas of the app.


## Scope and goals

- Support receiving email via IMAP and sending via SMTP.
- Store-first ingest: fetch, persist raw + metadata, then process (rules) asynchronously.
- Deduplicate by account + mailbox + IMAP UID.
- Sanitize HTML, extract text, store attachments to disk.
- Simple health testing from the UI and scheduled background checks.
- Configurable retention and delete-on-success behavior.
- Reusable services and jobs that other controllers can call.

Non-goals (for MVP):
- No provider-specific OAuth2 flows (password auth first). OAuth2 can be added later.
- No “append to Sent” on outbound for now.


## Key components at a glance

- Models: `EmailAccount`, `EmailMessage`, `EmailAttachment`, `EmailHealthCheck`, `EmailLog`
- Services: `ImapClient`, `EmailTestService`, `EmailTestResult`, `BodyNormalizer`, `HtmlSanitizer`
- Jobs: `PollActiveEmailAccounts`, `FetchImapAccount`, `StoreInboundMessage`, `ProcessInboundRules`, `EmailAccountHealthCheckJob`, `EmailRetentionPurgeJob`
- Controllers (Admin/Settings): `AccountsController`, `ConfigController`, `RulesController`
- Routes: declared in `routes/tech.php` under the `tech.admin.settings.email.*` namespace
- Scheduling: declared in `routes/console.php` (polling, health, retention)

Libraries:
- IMAP: `webklex/laravel-imap` (v6) — Facade in tests, ClientManager in ingest wrapper.
- SMTP: Symfony Mailer via Laravel (EsmtpTransport).


## Data model

### EmailAccount — `app/Domain/Email/Models/EmailAccount.php`
Backs the `email_accounts` table (`database/migrations/2025_11_11_000001_create_email_accounts_table.php`). Stores both IMAP and SMTP settings, plus health fields.

Important columns:
- Identity and defaults: `address`, `description`, `from_name`, `is_active`, `is_global_default`, `defaults_for (json)`
- IMAP: `imap_host`, `imap_port`, `imap_encryption`, `imap_username`, `imap_secret (encrypted)`, `imap_auth_type`
- SMTP: `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_secret (encrypted)`, `smtp_auth_type`
- Health: `last_test_at`, `last_test_result (OK|Warning|Error)`, `last_error_code`, `last_error_message`, `last_successful_fetch_at`, `last_successful_send_at`

Notes:
- Secrets are stored encrypted using Laravel `Crypt`.
- `defaults_for` is a JSON array for per-scope defaults (e.g., tickets, sales, alerts).

### EmailMessage — `app/Domain/Email/Models/EmailMessage.php`
Backs `email_messages` (`database/migrations/2025_11_11_000002_create_email_messages_table.php`). Represents stored inbound messages.

Key columns:
- Dedup key: `account_id + mailbox + imap_uid` (unique index)
- Metadata: `message_id`, `subject`, `from_name`, `from_email`, `to_json`, `cc_json`, `headers_json`, `in_reply_to`, `references`
- State: `received_at`, `size_bytes`, `is_oversize`, `state (enum)`, `labels_json`, `attachments_count`, `ticket_id`
- Content and files: `body_html_sanitized`, `body_text`, `raw_path`, `checksum_sha1`

### EmailAttachment — `app/Domain/Email/Models/EmailAttachment.php`
Backs `email_attachments` (`database/migrations/2025_11_11_000003_create_email_attachments_table.php`).
- Links to `message_id`; stores `filename`, `content_type`, `size_bytes`, disk `path`, inline flag `is_inline`, `cid`, `checksum_sha1`.

### EmailHealthCheck — `app/Domain/Email/Models/EmailHealthCheck.php`
Backs `email_health_checks` (`database/migrations/2025_11_11_000004_create_email_health_checks_table.php`).
- One row per periodic check: timestamps, IMAP/SMTP status strings, error code/message, and `durations_json` for timings.

### EmailLog — `app/Domain/Email/Models/EmailLog.php`
Backs `email_logs` (`database/migrations/2025_11_11_000005_create_email_logs_table.php`).
- General-purpose structured log for inbound/outbound events with `direction`, `scope`, `level`, optional `account_id` and `email_message_id`.


## Services

### ImapClient — `app/Domain/Email/Services/ImapClient.php`
A thin wrapper around Webklex to connect to a specific account and interact with a mailbox (currently INBOX).
- `connect()`: builds a `Client` via `ClientManager`, using `imap_host/port/encryption`, `username`, and decrypted `secret`.
- `fetchUnseen(limit)`: opens INBOX, fetches unseen messages up to a limit, returns lightweight arrays with header-level info and UID, without heavy parsing.
- `fetchByUid(uid)`: loads a specific message by IMAP UID from INBOX for full body/attachments.

Implementation notes:
- Encryption is passed through as configured (`ssl`|`tls`|`starttls`). Certificate validation is enabled.

### EmailTestService — `app/Domain/Email/Services/EmailTestService.php`
Runs a live connectivity test for both IMAP and SMTP and updates the account’s health.
- IMAP: Uses `Webklex\IMAP\Facades\Client::make([...])->connect()` with mapped encryption.
- SMTP: Uses `EsmtpTransport(host, port, sslFlag)`; for STARTTLS, `setTls(true)` is used. Implicit SSL uses the constructor’s boolean flag.
- Classifies and records errors via `imapErrorClassify()` / `smtpErrorClassify()` and populates `EmailTestResult`.
- Persists `last_test_at`, `last_test_result`, clears/sets error details, and updates `last_successful_fetch_at`/`last_successful_send_at` when relevant.

### EmailTestResult — `app/Domain/Email/Services/EmailTestResult.php`
Simple DTO for booleans, durations, and optional error codes/messages with an `overall()` status.

### BodyNormalizer — `app/Domain/Email/Services/BodyNormalizer.php`
Converts HTML to plain text: strips scripts/styles/tags, decodes entities, collapses whitespace.

### HtmlSanitizer — `app/Domain/Email/Services/HtmlSanitizer.php`
Basic sanitizer that removes risky tags/handlers. Intended to be replaced with HTMLPurifier integration later.


## Jobs and flows

### High-level ingest flow
1) Polling picks active accounts and dispatches fetch jobs.
2) `FetchImapAccount` connects, pulls unseen message heads, emits work units.
3) For each message:
	 - Oversize messages are flagged; normal-sized messages are handed to `StoreInboundMessage`.
4) `StoreInboundMessage` re-fetches full content by UID, stores raw EML and attachments, sanitizes/normalizes bodies, and upserts `EmailMessage` (+ attachments).
5) Optionally, message can be deleted/moved server-side after successful persistence (delete-on-success setting).
6) `ProcessInboundRules` runs async rules on persisted messages (tagging, triage, linking to tickets, etc.).

### Job catalog (paths referenced in codebase)
- `app/Domain/Email/Jobs/PollActiveEmailAccounts.php` — iterates active accounts; schedule every minute. (Dispatcher/entry job.)
- `app/Domain/Email/Jobs/FetchImapAccount.php` — connect via `ImapClient`, fetch unseen, dedupe by `account+mailbox+uid`, and dispatch `StoreInboundMessage` with a payload (marks oversize if > size limit).
- `app/Domain/Email/Jobs/StoreInboundMessage.php` — refetch full message by UID, write `.eml` + attachments to disk, sanitize body HTML and extract text via `BodyNormalizer`, upsert `EmailMessage`, create `EmailAttachment` rows, enqueue `ProcessInboundRules`.
- `app/Domain/Email/Jobs/ProcessInboundRules.php` — placeholder for rule engine; runs on stored messages.
- `app/Domain/Email/Jobs/EmailAccountHealthCheckJob.php` — runs connectivity checks and writes `EmailHealthCheck` rows.
- `app/Domain/Email/Jobs/EmailRetentionPurgeJob.php` — deletes old data past retention policy and cleans orphan files.

Scheduling: see `routes/console.php` for cron frequency; defaults are poll: 1m, health: 5m, retention: monthly.


## Controllers and views (Admin/Settings)

### AccountsController — `app/Http/Controllers/Tech/Admin/Settings/Email/AccountsController.php`
- `index()`: list accounts — view `resources/views/Tech/admin/settings/email/accounts/index.blade.php`.
- `create() / store()`: create account — shared form view `.../create.blade.php`.
- `edit(EmailAccount) / update(EmailAccount)`: update account — same shared form.
- `toggleActive(EmailAccount)`: quick activation toggle.
- `test(EmailAccount)`: runs `EmailTestService::run()` and flashes `email_test` data back to the form.

Validation: `validateData()` enforces required fields and uniqueness (`address`). Secrets are encrypted before save.

Views:
- Index: lists accounts, default badges, health icon, actions; routes prefixed with `tech.`.
- Create/Edit: unified form, includes a hidden POST form to trigger “Run Full Test” to the `test` action.

### ConfigController — `app/Http/Controllers/Tech/Admin/Settings/Email/ConfigController.php`
- Simple façade for global settings (poll interval, concurrency, batch size, delete-on-success, size limit, retention). Currently uses in-memory defaults; persistence TBD.

### RulesController — `app/Http/Controllers/Tech/Admin/Settings/Email/RulesController.php`
- Placeholder for listing/managing inbound rules once implemented.


## Routes and naming

Declared in `routes/tech.php` with the `tech.` name prefix. Key routes include:
- `tech.admin.settings.email.accounts` — index
- `tech.admin.settings.email.accounts.create` — form
- `tech.admin.settings.email.accounts.store` — POST create
- `tech.admin.settings.email.accounts.edit` — edit form
- `tech.admin.settings.email.accounts.update` — PUT/PATCH update
- `tech.admin.settings.email.accounts.toggle` — toggle active
- `tech.admin.settings.email.accounts.test` — POST run connection test
- Additional: `tech.admin.settings.email.config`, `tech.admin.settings.email.rules`

Note: The UI relies on these exact names; ensure the `tech.` prefix is present in views and redirects.


## IMAP and SMTP behavior

IMAP:
- Library: Webklex IMAP.
- Encryption mapping: accepts `ssl`, `tls`, or `starttls`. Certificates validated.
- Fetch strategy: INBOX only for now, unseen first; up to batch size per poll.
- Dedup: keyed by `account_id + mailbox + imap_uid`.

SMTP:
- Library: Symfony Mailer EsmtpTransport.
- Encryption mapping:
	- Implicit SSL (port 465 typically): `new EsmtpTransport(host, port, true)`.
	- STARTTLS (port 587 typically): `new EsmtpTransport(host, port, false)` and `setTls(true)`.
- No Sent folder append during MVP.


## Storage layout and sanitation

- Raw `.eml` files and attachments are stored on local disk (`storage/app` or configured disk). Paths are persisted in the DB (`raw_path` for messages; `path` per attachment).
- `HtmlSanitizer` removes risky tags/handlers; replace with HTMLPurifier later for full safety.
- `BodyNormalizer::toText()` produces a readable plaintext version for search and previews.


## Health testing and monitoring

- From the account form, “Run Full Test” POSTs to `AccountsController@test` which calls `EmailTestService`.
- Results are flashed to the session and rendered in the form view.
- Periodic health checks should populate `email_health_checks` via `EmailAccountHealthCheckJob`.
- Error classification uses short codes (e.g., `IMAP_AUTH`, `IMAP_TLS`, `SMTP_AUTH`, `SMTP_CONNECT`).


## Configuration knobs

- See `ConfigController@index()` for current defaults: `poll_interval`, `concurrency`, `batch_size`, `delete_on_success`, `size_limit_mb`, `retention_months`.
- A persistent settings store can be introduced later; wire jobs to read from it.


## Scheduler and cron (server setup)

- Email polling runs via Laravel Scheduler (see `routes/console.php`).
- Ensure a system cron runs the scheduler every minute:

```cron
* * * * * cd /var/Projects/tdPSA && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

- Queue processing:
	- Development: set `QUEUE_CONNECTION=sync` (no worker required).
	- Production: start a worker, for example:

```bash
php artisan queue:work --sleep=3 --tries=3
```

Notes:
- The scheduler dispatches the `PollActiveEmailAccounts` job every minute, which in turn enqueues `FetchImapAccount` per active account.
- Health checks and retention purges are also scheduled in `routes/console.php`.

## Manual inbox polling (on-demand)

The Tech Inbox view exposes a "Check now" button for immediate ingestion without waiting for the next cron tick.

Implementation summary:
- Route: `POST /tech/inbox/poll` named `tech.inbox.poll` (defined in `routes/tech.php`).
- Controller: `IndexController@poll` (`app/Http/Controllers/Tech/Inbox/IndexController.php`) loads all active `EmailAccount` rows and runs `FetchImapAccount` synchronously per account using `dispatchSync`.
- View: `resources/views/Tech/Inbox/index.blade.php` includes a CSRF-protected form that posts to the route.
- Feedback: Flash message indicates how many accounts were checked immediately.

Use cases:
- Force immediate fetch after adding an account or fixing credentials; results are visible right away on return to the inbox.
- Development convenience when a queue worker is not running.

Operational notes:
- Synchronous execution happens in the HTTP request; for many/slow accounts, consider using the scheduler + queue worker for better responsiveness.
- Safe to click multiple times; duplicate unseen messages are deduped by `account_id + mailbox + imap_uid`.
- In production, prefer the scheduler + worker for steady-state ingestion; keep the manual button for ad-hoc checks.


## Extending and reusing components

Common extension points:
- Rules engine: implement rule definitions and runners in `ProcessInboundRules`. Keep them idempotent and fast; operate on stored `EmailMessage` records.
- Sanitizer: replace `HtmlSanitizer` with a robust library (HTMLPurifier) and add CID image rewriting to signed URLs for inline display.
- Multi-mailbox: extend `ImapClient` to take mailbox names and update jobs to iterate folders beyond INBOX.
- Delete/move-on-success: when enabled, after `StoreInboundMessage` succeeds, delete or move the message server-side (e.g., to an Archive folder).
- OAuth2: add new `imap_auth_type` / `smtp_auth_type` handlers and token storage/refresh flow.

How other controllers/services can reuse this module:
- Use `EmailAccount` to select an account (global/subsystem default) and dispatch jobs:
	- Dispatch `FetchImapAccount` manually for on-demand ingest.
	- Use `EmailTestService` to validate connectivity before enabling an account.
- For outbound, centralize send logic (future `OutboundMailService` recommended) that logs to `EmailLog` and maps account defaults.
- For triage UIs, query `EmailMessage` with `state` and `labels_json`, eager-load `attachments`, and display `body_html_sanitized`.

Coding guidelines:
- Keep services stateless where possible; pass in `EmailAccount` explicitly.
- Prefer small, composable jobs with clear contracts and retry-safe behavior.
- Log failures to `EmailLog` with context for observability.


## Testing and troubleshooting

Functional checks:
- Use the Accounts Create/Edit view to run “Full Test” and confirm IMAP/SMTP connectivity.
- Verify that polling schedules are running (scheduler + queues) and that `email_messages` grows when new mail arrives.

Common issues:
- “Target class [imap] does not exist” — ensure Webklex package is installed and configured; tests use the Facade.
- IMAP TLS/SSL negotiation failed — verify port/encryption pair and certificates.
- SMTP auth or TLS errors — check `smtp_encryption` mapping: 465/ssl vs 587/tls (STARTTLS).
- Route name errors — confirm `tech.` prefix in route names and views.


## Roadmap (next steps)

- Implement `EmailAttachment` DB creation in `StoreInboundMessage` and update `attachments_count`.
- Implement delete/move on success (config-driven).
- Introduce HTMLPurifier and inline image rewriting.
- Flesh out the rules engine with a small DSL and async execution.
- Add index health badges and per-row “Run test”.
- Add outbound service with provider-specific nuances and logging.


## File index (quick reference)

- Models:
	- `app/Domain/Email/Models/EmailAccount.php`
	- `app/Domain/Email/Models/EmailMessage.php`
	- `app/Domain/Email/Models/EmailAttachment.php`
	- `app/Domain/Email/Models/EmailHealthCheck.php`
	- `app/Domain/Email/Models/EmailLog.php`
- Services:
	- `app/Domain/Email/Services/ImapClient.php`
	- `app/Domain/Email/Services/EmailTestService.php`
	- `app/Domain/Email/Services/EmailTestResult.php`
	- `app/Domain/Email/Services/BodyNormalizer.php`
	- `app/Domain/Email/Services/HtmlSanitizer.php`
- Jobs:
	- `app/Domain/Email/Jobs/PollActiveEmailAccounts.php`
	- `app/Domain/Email/Jobs/FetchImapAccount.php`
	- `app/Domain/Email/Jobs/StoreInboundMessage.php`
	- `app/Domain/Email/Jobs/ProcessInboundRules.php`
	- `app/Domain/Email/Jobs/EmailAccountHealthCheckJob.php`
	- `app/Domain/Email/Jobs/EmailRetentionPurgeJob.php`
- Controllers (Admin/Settings):
	- `app/Http/Controllers/Tech/Admin/Settings/Email/AccountsController.php`
	- `app/Http/Controllers/Tech/Admin/Settings/Email/ConfigController.php`
	- `app/Http/Controllers/Tech/Admin/Settings/Email/RulesController.php`
- Migrations: `database/migrations/2025_11_11_000001..000005_*.php`
- Routes: `routes/tech.php`, `routes/console.php`
- Views: `resources/views/Tech/admin/settings/email/accounts/*.blade.php`


---

If you ask for a change later, refer to the component above; this map shows where to edit behavior and how changes flow through the system.
