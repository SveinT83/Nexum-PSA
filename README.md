# Nexum PSA

Nexum PSA is a self-hosted Professional Services Automation platform for IT
service providers. The product goal is to connect clients, tickets, assets,
contracts, time, stock, billing, documentation, notifications, and integrations
in one operational system without forcing the business into a closed PSA
ecosystem.

The project is built for Tronder Data first, but the architecture is intended to
support other MSPs and service businesses later. The codebase is currently being
prepared for the first beta release.

## What It Does

Nexum PSA gives technicians and administrators one place to run daily service
operations:

- Track tickets from email, manual creation, client context, workflow state, SLA
  policy, time registration, tasks, attachments, internal notes, and outbound
  customer replies.
- Manage clients, sites, contacts, assets, suppliers, documentation, and
  operational risk.
- Build services, contracts, contract items, SLA defaults, time rates, stock
  reservations, ticket costs, and economy order drafts for billing.
- Sync and use integrations such as Nextcloud, Nextcloud Talk notifications,
  BookStack knowledge sync, Nexum-to-Nexum relationships, IMAP/SMTP email, AI
  providers, queues, and health tooling.
- Give technicians a Warroom dashboard for a fast cross-module overview.

The system is intentionally modular. Each business domain owns its controllers,
views, routes, tests, actions, queries, and workflow code under `app/Modules`.

## Current Beta Scope

The current beta contains working foundations for:

- Warroom dashboard
- Ticket lifecycle, workflow transitions, SLA handling, assignment rules, email
  replies, time registration, storage reservations, tasks, and knowledge hints
- Client, site, contact, asset, vendor, documentation, risk, and taxonomy
  management
- Commercial service catalog, contracts, SLA policies, time rates, timebank
  handling, and economy order generation
- Storage inventory with warehouses, rooms, boxes, items, reservations, purchase
  orders, stock movements, and ticket picking
- Email ingestion and outbound email templates
- Notifications with mail, database, and Nextcloud Talk delivery
- Nextcloud connections, folder/user/group/calendar mapping, and sync logs
- BookStack knowledge synchronization
- Nexum relationships for signed ticket, documentation, and Knowledge exchange
  between independent Nexum installations
- AI provider/agent setup and right-side contextual AI chat
- User management, roles, permissions, invites, preferences, and 2FA enforcement
- Queue worker operations and security headers

Beta status means the application is functional enough to test as a complete
workflow, but schema history, deployment routines, and production hardening are
still being finalized.

## Technology

- PHP 8.2+
- Laravel 12
- Livewire 3
- Bootstrap 5
- Alpine.js
- Vite
- MySQL or MariaDB
- Spatie Laravel Permission
- Laravel Fortify
- Laravel Sanctum
- Laravel Boost for development assistance
- PHPUnit for automated tests

Redis is recommended for production queues and cache, but local development can
start with the database queue driver.

## Architecture Rules

This project does not use standard Laravel folders for domain work.

- Domain routes live in `app/Modules/{Domain}/routes.php`.
- Domain controllers live in `app/Modules/{Domain}/Controllers`.
- Domain views live in `app/Modules/{Domain}/Views`.
- Domain tests live in `app/Modules/{Domain}/Tests`.
- `routes/web.php` and `routes/api.php` may load module routes, but must not
  contain domain routes.
- Shared Blade components live in `resources/views/components`.

Read `AGENTS.md` before making code changes. It points to the detailed module
architecture and UI standards in `docs/`.

## Main Modules

| Module | Purpose |
| --- | --- |
| `Warroom` | Cross-module dashboard for technicians and admins. |
| `Ticket` | Tickets, workflow, SLA, time, assignment, replies, and rules. |
| `Task` | Standalone and ticket/client-owned tasks with checklists and estimates. |
| `Clients` | Clients, sites, contacts, client formats, and client context. |
| `Asset` | Assets and client/site asset visibility. |
| `Commercial` | Services, contracts, packages, costs, SLA policies, and time rates. |
| `Economy` | Draft billing/order generation from tickets, contracts, stock, and time. |
| `Storage` | Inventory, stock units, locations, reservations, picking, and purchase orders. |
| `Email` | IMAP ingestion, outbound SMTP, templates, health checks, and email rules. |
| `Knowledge` | Internal knowledge base, shelves, books, chapters, articles, and tags. |
| `Integration` | API, AI, BookStack, and integration settings. |
| `Relationship` | Nexum-to-Nexum relationship configuration, signed sync, health, and audit. |
| `Nextcloud` | Nextcloud connections, mappings, sync, and Talk bot configuration. |
| `Notification` | User notification settings and channel delivery. |
| `Sales` | Leads, opportunities, activity timeline, quotes, and sales settings. |
| `Risk` | Risk items, assessments, updates, links, and operational risk tracking. |
| `Taxonomy` | Shared categories and tags. |
| `UserManagement` | Users, roles, permissions, invites, preferences, and 2FA settings. |
| `System` | System operations such as queue worker administration and security tooling. |

## Local Installation

Clone the project and install PHP and Node dependencies:

```bash
git clone https://github.com/SveinT83/Nexum-PSA.git
cd Nexum-PSA
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure `.env` for your database, mail, queue, cache, and integrations. For a
local MySQL/MariaDB setup, create an empty database first, then run:

```bash
php artisan migrate --seed
npm run dev
php artisan serve
```

The application is then available at:

```text
http://127.0.0.1:8000
```

The local seed creates an initial admin user and prints the credentials in the
seeder output. Change that password immediately on any shared or internet-facing
environment.

## Useful Development Commands

Run the full test suite:

```bash
php artisan test
```

Run the project convenience script with server, queue listener, logs, and Vite:

```bash
composer run dev
```

Build frontend assets for deployment:

```bash
npm run build
```

Clear Laravel caches after configuration or deployment changes:

```bash
php artisan optimize:clear
```

## Version And Release Automation

Normal commits and merges do not require a manual version edit. Nexum keeps product releases and
exact build identity separate:

- `version.txt` contains the installed product version.
- Composer records the checked-out root-package commit during `composer install`.
- The Admin page compares that commit with the configured update branch.
- Optional `APP_VERSION` and `APP_COMMIT_SHA` values override automatic metadata only for packaged
  deployments that need them.

Pushes to `main` run Release Please. Conventional Commits such as `fix:`, `feat:`, and `feat!:` are
collected into one generated release pull request. Merging that pull request updates
`version.txt` and `CHANGELOG.md`, creates the semantic `v...` tag, and publishes the GitHub release.
No contributor or deployer calculates the next version manually.

Development installs should set `APP_UPDATE_BRANCH=Dev`; production defaults to `main`. The public
repository works without a GitHub token, while `APP_GITHUB_TOKEN` is an optional read-only override
for higher API limits or future private-repository use. Run `composer install` after checking out
the deployed commit and before Laravel configuration is cached; `composer dump-autoload` alone does
not refresh Composer's root-package source reference.

Run pending migrations:

```bash
php artisan migrate
```

## Production Notes

For beta and production-like installs:

- Use a clean database schema when testing the beta migration set.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and a trusted `APP_URL`.
- Use HTTPS and secure session cookies.
- Configure a real queue worker instead of relying on synchronous jobs.
- Configure mail accounts before enabling inbound/outbound ticket email flows.
- Configure Nextcloud and BookStack integrations only after the base install and
  migrations are complete.
- Run `npm run build` before serving the app without the Vite dev server.
- Change seeded credentials and review roles, permissions, 2FA enforcement, and
  notification channel secrets before exposing the system.

## Testing Standard

Every module should include tests for the workflows it owns. Existing module
tests use Laravel's standard PHPUnit runner and should pass before merging:

```bash
php artisan test
```

For risky changes, especially migrations, billing logic, ticket workflows,
notifications, integrations, or shared UI components, run the full suite rather
than only a single module test.

## Documentation

Project documentation lives in `docs/`.

- `AGENTS.md` is the mandatory AI/developer instruction entry point.
- `docs/module-architecture.md` defines module ownership and Laravel structure.
- `docs/ui-guidelines.md` defines Bootstrap UI and Blade layout standards.
- Integration notes and historical planning documents are kept in focused files
  under `docs/`.

Knowledge documentation for completed or materially changed domains should be
updated so it can be synced to BookStack. Repository-owned module
documentation is published into Knowledge with `php artisan knowledge:sync-docs`
and can be marked for BookStack push with `php artisan knowledge:sync-docs --push`.

## License

Nexum PSA is developed by Tronder Data.

The code is shared under a Tronder Data limited-source license.

Allowed:

- Self-host Nexum PSA for your own organization.
- Read, modify, and run the code for internal use.
- Contribute code, fixes, documentation, and improvements back to the project.

Not allowed without a separate written agreement from Tronder Data:

- Sell Nexum PSA hosting, managed hosting, SaaS access, support subscriptions,
  AI token resale, integration services, or other commercial services built
  around running Nexum PSA for third parties.
- Offer Nexum PSA as a competing hosted or managed commercial product.
- Re-license, package, or distribute Nexum PSA in a way that removes these
  restrictions.

Tronder Data reserves the commercial right to offer hosted Nexum PSA, support,
managed services, AI token services, and related paid platform services.
