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
- Services: `ImapClient`, `EmailTestService`, `BodyNormalizer`, `HtmlSanitizer`, `InboundAttachmentPersister`, `InboundEmailSignalClassifier`, `InboundEmailRuleEngine`, `TrustedSenderAuthenticationFacts`
- Jobs: `PollActiveEmailAccounts`, `FetchImapAccount`, `StoreInboundMessage`, `ProcessInboundRules`, `EmailAccountHealthCheckJob`, `EmailRetentionPurgeJob`
- Controllers (Admin/Settings): `AccountsController`, `ConfigController`, `RulesController`
- Routes: declared in `app/Modules/Email/routes.php` under the `tech.admin.settings.email.*` namespace
- Scheduling: declared in `routes/console.php` (polling, health, retention)

Libraries:
- IMAP: `webklex/laravel-imap` (v6) — Facade in tests, ClientManager in ingest wrapper.
- SMTP: Symfony Mailer via Laravel (EsmtpTransport).


## Data model

### EmailAccount — `app/Modules/Email/Models/EmailAccount.php`
Backs the `email_accounts` table (`database/migrations/2025_11_11_000001_create_email_accounts_table.php`). Stores both IMAP and SMTP settings, plus health fields.

Important columns:
- Identity and defaults: `address`, `description`, `from_name`, `is_active`, `is_global_default`, `defaults_for (json)`
- IMAP: `imap_host`, `imap_port`, `imap_encryption`, `imap_username`, `imap_secret (encrypted)`, `imap_auth_type`
- SMTP: `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_secret (encrypted)`, `smtp_auth_type`
- Health: `last_test_at`, `last_test_result (OK|Warning|Error)`, `last_error_code`, `last_error_message`, `last_successful_fetch_at`, `last_successful_send_at`

Notes:
- Secrets are stored encrypted using Laravel `Crypt`.
- `defaults_for` is a JSON array for per-scope defaults (e.g., tickets, sales, marketing, alerts).

### EmailMessage — `app/Modules/Email/Models/EmailMessage.php`
Backs `email_messages` (`database/migrations/2025_11_11_000002_create_email_messages_table.php`). Represents stored inbound messages.

Key columns:
- Dedup key: `account_id + mailbox + imap_uid` (unique index)
- Metadata: `message_id`, `subject`, `from_name`, `from_email`, `to_json`, `cc_json`, `headers_json`, `in_reply_to`, `references`
- State: `received_at`, `size_bytes`, `is_oversize`, `state (enum)`, `labels_json`, `attachments_count`, `ticket_id`
- Content and files: `body_html_sanitized`, `body_text`, `raw_path`, `checksum_sha1`

### EmailAttachment — `app/Modules/Email/Models/EmailAttachment.php`
Backs `email_attachments` (`database/migrations/2025_11_11_000003_create_email_attachments_table.php`).
- Links to `message_id`; stores `filename`, `content_type`, `size_bytes`, disk `path`, inline flag `is_inline`, `cid`, `checksum_sha1`.

### EmailHealthCheck — `app/Modules/Email/Models/EmailHealthCheck.php`
Backs `email_health_checks` (`database/migrations/2025_11_11_000004_create_email_health_checks_table.php`).
- One row per periodic check: timestamps, IMAP/SMTP status strings, error code/message, and `durations_json` for timings.

### EmailLog — `app/Modules/Email/Models/EmailLog.php`
Backs `email_logs` (`database/migrations/2025_11_11_000005_create_email_logs_table.php`).
- General-purpose structured log for inbound/outbound events with `direction`, `scope`, `level`, optional `account_id` and `email_message_id`.


## Services

### ImapClient — `app/Modules/Email/Services/ImapClient.php`
A thin wrapper around Webklex to connect to a specific account and interact with a mailbox (currently INBOX).
- `connect()`: builds a `Client` via `ClientManager`, using `imap_host/port/encryption`, `username`, and decrypted `secret`.
- `fetchUnseen(limit, page)`: opens INBOX and returns a bounded unseen page for explicit diagnostics. Automatic polling deliberately does not interpret unread state as backlog work.
- `fetchRecent(limit)`: opens INBOX and returns the newest messages regardless of Seen state for explicit diagnostics and compatibility.
- `mailboxState()`: returns INBOX `UIDVALIDITY` and `UIDNEXT` so automatic polling can establish and verify a durable live boundary.
- `fetchAfterUid(uid, limit)`: fetches the oldest bounded batch after the stored live boundary regardless of Seen state.
- `fetchByUid(uid)`: loads a specific message by IMAP UID from INBOX for full body/attachments.

Implementation notes:
- Encryption is passed through as configured (`ssl`|`tls`|`starttls`). Certificate validation is enabled.
- Header metadata is parsed from Webklex's supported `getHeader()->raw` source. Folded values are unfolded while repeated `Received` and `Authentication-Results` fields retain top-to-bottom order. Missing or malformed authentication evidence remains empty and therefore fails closed.

### EmailTestService — `app/Modules/Email/Services/EmailTestService.php`
Runs a live connectivity test for both IMAP and SMTP and updates the account’s health.
- IMAP: Uses `Webklex\IMAP\Facades\Client::make([...])->connect()` with mapped encryption.
- SMTP: Uses `EsmtpTransport(host, port, sslFlag)`; for STARTTLS, `setTls(true)` is used. Implicit SSL uses the constructor’s boolean flag.
- Classifies and records errors via `imapErrorClassify()` / `smtpErrorClassify()` and populates `EmailTestResult`.
- Persists `last_test_at`, `last_test_result`, clears/sets error details, and updates `last_successful_fetch_at`/`last_successful_send_at` when relevant.

### EmailTestResult — `app/Modules/Email/Services/EmailTestResult.php`
Simple DTO for booleans, durations, and optional error codes/messages with an `overall()` status.

### BodyNormalizer — `app/Modules/Email/Services/BodyNormalizer.php`
Converts HTML to plain text: strips scripts/styles/tags, decodes entities, collapses whitespace.

### HtmlSanitizer — `app/Modules/Email/Services/HtmlSanitizer.php`
Basic sanitizer that removes risky tags/handlers. Intended to be replaced with HTMLPurifier integration later.


## Jobs and flows

### High-level ingest flow
1) Polling picks active accounts and dispatches fetch jobs.
2) `FetchImapAccount` connects, validates INBOX `UIDVALIDITY`, initializes a forward-only `UIDNEXT - 1` baseline on first activation, and then drains the oldest new UIDs after the greater of that baseline and the highest stored UID. Historical unread state is never automatic work, while bursts larger than one batch drain over later polls without UID gaps.
3) For each message:
	 - Oversize messages are flagged; normal-sized messages are handed to `StoreInboundMessage`.
4) `StoreInboundMessage` re-fetches full content by UID, stores raw EML and attachments, sanitizes/normalizes bodies, and upserts `EmailMessage` (+ attachments).
5) Optionally, message can be deleted/moved server-side after successful persistence (delete-on-success setting).
6) Explicit `preclassification` Email rules run first. They are opt-in and can stop later classification for narrow trusted handoffs.
7) `InboundEmailSignalClassifier` detects machine replies, delivery failures, and recognized vendor notifications. Matching messages become Signal records and are archived before normal ticket routing.
8) Remaining messages continue through `normal` Email rules and existing Ticket routing.
9) After routing completes, Email calls the Notification-owned inbound alert dispatcher. Notification
   creates at most one canonical notification per EmailMessage/user, handles Web Push fan-out for
   opted-in users, and owns source read synchronization without changing Email state or Ticket
   operational unread state.

Selected Email Rules can explicitly emit a Signal with the `emit_signal` action. This is for
admin-approved handoff cases such as vendor notices, monitoring messages, or security alerts. Email
still owns message parsing, tagging, archiving, thread linking, and ticket ingress. Signal owns
cross-module automation after the explicit handoff creates the normalized Signal.

### Job catalog (paths referenced in codebase)
- `app/Modules/Email/Jobs/PollActiveEmailAccounts.php` — iterates active accounts; schedule every minute. (Dispatcher/entry job.)
- `app/Modules/Email/Jobs/FetchImapAccount.php` — serialize fetches per account, establish/verify the forward-only UID namespace, select the oldest bounded new-UID batch, remove stored/soft-deleted UIDs, and dispatch `StoreInboundMessage` with a payload (marks oversize if > size limit). A changed `UIDVALIDITY` stops ingest and records an account error until an explicit re-baseline.
- `app/Modules/Email/Jobs/StoreInboundMessage.php` - refetch full message by UID, write raw EML, sanitize body HTML, upsert `EmailMessage`, and persist policy-accepted attachment metadata/checksums before queuing rules.
- `app/Modules/Email/Jobs/ProcessInboundRules.php` - run opt-in preclassification rules, machine/vendor classification, normal Email/Ticket routing, and the Notification-owned post-routing inbound alert dispatcher in that order.
- `app/Modules/Email/Jobs/EmailAccountHealthCheckJob.php` — runs connectivity checks and writes `EmailHealthCheck` rows.
- `app/Modules/Email/Jobs/EmailRetentionPurgeJob.php` — deletes old data past retention policy and cleans orphan files.

Scheduling: see `routes/console.php` for cron frequency; defaults are poll: 1m, health: 5m, retention: monthly.


## Controllers and views (Admin/Settings)

### AccountsController — `app/Modules/Email/Controllers/Admin/AccountsController.php`
- `index()`: list accounts — view `resources/views/Tech/admin/settings/email/accounts/index.blade.php`.
- `create() / store()`: create account — shared form view `.../create.blade.php`.
- `edit(EmailAccount) / update(EmailAccount)`: update account — same shared form.
- `toggleActive(EmailAccount)`: quick activation toggle.
- `test(EmailAccount)`: runs `EmailTestService::run()` and flashes `email_test` data back to the form.

Validation: `validateData()` enforces required fields and uniqueness (`address`). Secrets are encrypted before save.

Views:
- Index: lists accounts, default badges, health icon, actions; routes prefixed with `tech.`.
- Create/Edit: unified form, includes a hidden POST form to trigger “Run Full Test” to the `test` action.

### ConfigController — `app/Modules/Email/Controllers/Admin/ConfigController.php`
- Persists global ingest, retention, attachment policy, and trusted sender-authentication settings in `common_settings`.

### RulesController — `app/Modules/Email/Controllers/Admin/RulesController.php`
- Manages ordered inbound rules, including the explicit `normal` and `preclassification` routing phases.


## Routes and naming

Declared in `app/Modules/Email/routes.php` with the `tech.` name prefix. Key routes include:
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
- Fetch strategy: INBOX only; first activation establishes a forward-only UID baseline, then each poll fetches the oldest batch strictly after the live high-water mark regardless of Seen state.
- Dedup: keyed by `account_id + mailbox + imap_uid`, including soft-deleted rows. `UIDVALIDITY` changes fail closed instead of reusing an old UID namespace.

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

- See `ConfigController@index()` for ingest/retention defaults, attachment count/size/MIME policy, and trusted authserv/receiving-hop configuration.
- Settings are persisted as Email-owned `common_settings` values.


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
php artisan queue:work --queue=default,economy,email --sleep=3 --tries=3 --timeout=120
```

Notes:
- The scheduler dispatches the `PollActiveEmailAccounts` job every minute, which in turn enqueues `FetchImapAccount` per active account.
- `PollActiveEmailAccounts` updates the `email_last_poll_run` cache heartbeat when a real poll cycle starts.
- The Email Configuration `System Health` card reads active account count, ingest pause status, latest successful fetch timestamps, account errors, queue table backlog, failed jobs, and the poll heartbeat. If the queue driver is not `database` or the queue tables are unavailable, the card reports monitoring as unavailable instead of guessing.
- Health checks and retention purges are also scheduled in `routes/console.php`.

## Manual inbox polling (on-demand)

The Tech Inbox view exposes a "Check now" button for on-demand ingestion without waiting for the next cron tick.

Implementation summary:
- Route: `POST /tech/inbox/poll` named `tech.inbox.poll` (defined in `app/Modules/Email/routes.php`).
- Controller: `IndexController@poll` (`app/Modules/Email/Controllers/Tech/InboxController.php`) loads all active `EmailAccount` rows and queues `FetchImapAccount` per account.
- CLI: `php artisan email:poll` runs `FetchImapAccount` immediately for active accounts unless `--async` is supplied.
- View: `resources/views/Tech/Inbox/index.blade.php` includes a CSRF-protected form that posts to the route.
- Feedback: Flash message indicates how many accounts were queued for checking.

Use cases:
- Queue a fetch after adding an account or fixing credentials; results appear after the queue processes the jobs.
- Development convenience through `php artisan email:poll` when a queue worker is not running.

Operational notes:
- UI polling depends on queue processing; for direct troubleshooting use the CLI without `--async`.
- Safe to click multiple times; duplicate messages are deduped by `account_id + mailbox + imap_uid`.
- Existing unread mail is intentionally left untouched. More than one batch of genuinely new UIDs drains oldest-first over later polls; historical import requires a separate, explicit controlled workflow.
- If a trusted-source workflow reports missing authentication, verify that newly stored `headers_json` contains ordered `received` and `authentication-results` arrays. Nexum intentionally treats missing header evidence as untrusted.
- In production, prefer the scheduler + worker for steady-state ingestion; keep the manual button for ad-hoc checks.
- If automatic fetching stalls, check `/tech/admin/settings/email/config` first. `No heartbeat` means either `schedule:run` is not dispatching the poll job or the default queue worker is not processing it. Stale ready jobs or failed jobs in the health card point to queue-worker issues.


## Extending and reusing components

Common extension points:
- Rules engine: implement rule definitions and runners in `ProcessInboundRules`. Keep them idempotent and fast; operate on stored `EmailMessage` records.
- Inbound notifications: keep Email's responsibility to one post-routing call into
  `DispatchInboundEmailNotification`. Notification owns recipient resolution, channel preferences,
  Web Push payloads, canonical notification identity, and read synchronization.
- Signal handoff: use Email Rule `emit_signal` only for selected messages that should become
  cross-module operational events. Keep broad email routing local to Email and Ticket.
- Signal classification: extend `InboundEmailSignalClassifier` when new inbound e-post signal types should be detected before ticket routing. Keep matching conservative so real customer requests are not archived accidentally.
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

- Introduce HTMLPurifier and inline image rewriting.
- Add index health badges and per-row “Run test”.
- Add outbound service with provider-specific nuances and logging.


## File index (quick reference)

- Models:
	- `app/Modules/Email/Models/EmailAccount.php`
	- `app/Modules/Email/Models/EmailMessage.php`
	- `app/Modules/Email/Models/EmailAttachment.php`
	- `app/Modules/Email/Models/EmailHealthCheck.php`
	- `app/Modules/Email/Models/EmailLog.php`
- Services:
	- `app/Modules/Email/Services/ImapClient.php`
	- `app/Modules/Email/Services/EmailTestService.php`
	- `app/Modules/Email/Services/EmailTestResult.php`
	- `app/Modules/Email/Services/BodyNormalizer.php`
	- `app/Modules/Email/Services/HtmlSanitizer.php`
- Jobs:
	- `app/Modules/Email/Jobs/PollActiveEmailAccounts.php`
	- `app/Modules/Email/Jobs/FetchImapAccount.php`
	- `app/Modules/Email/Jobs/StoreInboundMessage.php`
	- `app/Modules/Email/Jobs/ProcessInboundRules.php`
	- `app/Modules/Email/Jobs/EmailAccountHealthCheckJob.php`
	- `app/Modules/Email/Jobs/EmailRetentionPurgeJob.php`
- Controllers (Admin/Settings):
	- `app/Modules/Email/Controllers/Admin/AccountsController.php`
	- `app/Modules/Email/Controllers/Admin/ConfigController.php`
	- `app/Modules/Email/Controllers/Admin/RulesController.php`
- Migrations: `database/migrations/2025_11_11_000001..000005_*.php`
- Routes: `app/Modules/Email/routes.php`, `routes/console.php`
- Views: `resources/views/Tech/admin/settings/email/accounts/*.blade.php`


---

If you ask for a change later, refer to the component above; this map shows where to edit behavior and how changes flow through the system.
