# Release Readiness Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Make Nexum PSA installable and deployable for self-hosters on Debian, Fedora, and Arch Linux, with a tested first-release checklist.

**Architecture:** A shell-based install script handles OS detection, dependency installation, and Laravel setup. A companion upgrade script handles future version bumps. Both are tested on clean VMs.

**Tech Stack:** PHP 8.2+, MySQL/MariaDB, Node.js (build only), Nginx, Supervisor, Redis (production)

---

## Phase 1: Documentation and Prerequisites

### Task 1: Create docs/INSTALL.md — full installation guide

**Objective:** Write a human-readable installation guide covering prerequisites, OS packages, database setup, and Laravel deployment steps.

**Files:**
- Create: `docs/INSTALL.md`

**Content outline:**
1. Prerequisites table (PHP 8.2+, MySQL/MariaDB, Node.js 18+, Redis recommended)
2. OS-specific package install commands (Debian, Fedora, Arch)
3. Database creation and `.env` configuration
4. Laravel setup commands (`composer install`, `npm run build`, `key:generate`, `migrate --seed`)
5. Nginx vhost config template
6. Supervisor config for `queue:work` and `schedule:run`
7. First-login checklist (change seeded password, configure mail, review permissions)
8. Production hardening section (APP_ENV=production, APP_DEBUG=false, HTTPS, etc.)

**Step 1: Create the file**

```bash
touch docs/INSTALL.md
```

**Step 2: Write the installation guide**

Include OS-specific sections for:
- **Debian 12/13**: `apt install php8.2 php8.2-{mysql,gd,xml,curl,mbstring,zip,bcmath,intl,redis} mariadb-server nginx supervisor`
- **Fedora 40/41**: `dnf install php php-{mysqlnd,gd,xml,curl,mbstring,zip,bcmath,intl,redis} mariadb-server nginx supervisor`
- **Arch**: `pacman -S php php-{gd,sqlite3,pdo_sqlite,bcmath,intl} mariadb nginx supervisor`

**Step 3: Commit**

```bash
git add docs/INSTALL.md
git commit -m "docs: add installation guide for Debian, Fedora, Arch"
```

---

### Task 2: Add .env.production.example — production-safe defaults

**Objective:** Create a production-ready `.env` template with secure defaults, commented alternatives for MySQL, Redis, etc.

**Files:**
- Create: `.env.production.example`

**Key differences from `.env.example`:**
- `APP_ENV=production`
- `APP_DEBUG=false`
- `DB_CONNECTION=mysql` (commented sqlite alternative)
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` placeholders
- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis`
- `SESSION_DRIVER=redis`
- `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` placeholders
- Production mail notes

**Step 1: Create the file**

```bash
cp .env.example .env.production.example
```

**Step 2: Edit production values**

Change: APP_ENV → production, APP_DEBUG → false, DB_CONNECTION → mysql (with sqlite commented), CACHE_STORE → redis, QUEUE_CONNECTION → redis, remove APP_VERSION (let deployers set it), add comments for each section.

**Step 3: Commit**

```bash
git add .env.production.example
git commit -m "docs: add production .env template with secure defaults"
```

---

## Phase 2: Install Script

### Task 3: Create install.sh — automated OS setup script

**Objective:** A single script that detects the OS, installs all required packages, creates the database, and runs Laravel setup.

**Files:**
- Create: `scripts/install.sh`

**The script must:**

1. Detect OS: check `/etc/os-release` for `debian`, `ubuntu`, `fedora`, `rhel`, `arch`, `cachyos`
2. Install OS packages (PHP 8.2+, MySQL/MariaDB, Nginx, Supervisor, Redis, Node.js, unzip, git)
3. Enable required PHP extensions
4. Install Composer
5. Clone the repo (or use current directory if already cloned)
6. `cp .env.production.example .env` and prompt for DB credentials, APP_URL, APP_KEY
7. `composer install --no-dev --optimize-autoloader`
8. `npm install && npm run build`
9. `php artisan key:generate`
10. `php artisan migrate --force`
11. `php artisan db:seed --force` (creates initial admin user, prints credentials)
12. Set storage/ and bootstrap/cache/ permissions
13. Print next steps: configure Nginx, Supervisor, and change admin password

**Step 1: Create scripts directory and install.sh**

```bash
mkdir -p scripts
touch scripts/install.sh
chmod +x scripts/install.sh
```

**Step 2: Write the script**

The script should:
- Use `set -euo pipefail`
- Have a banner with version info
- Color-coded output (green=ok, yellow=warning, red=error)
- Idempotent (safe to re-run)
- Log everything to `/var/log/nexum-install.log`
- Not run as root (check and refuse, sudo only for package installs)
- Prompt for: DB name, DB user, DB password, APP_URL
- Support `--non-interactive` flag with environment variables for CI

**Step 3: Test on local machine (dry run or syntax check)**

```bash
bash -n scripts/install.sh
```

**Step 4: Commit**

```bash
git add scripts/install.sh
git commit -m "feat: add automated install script for Debian, Fedora, Arch"
```

---

### Task 4: Create deploy.sh — production deployment/update script

**Objective:** Script for updating an existing Nexum PSA installation (git pull, migrate, rebuild assets, clear cache).

**Files:**
- Create: `scripts/deploy.sh`

**The script must:**

1. `git pull origin main` (or configurable branch)
2. `composer install --no-dev --optimize-autoloader`
3. `npm install && npm run build`
4. `php artisan migrate --force`
5. `php artisan config:cache`
6. `php artisan route:cache`
7. `php artisan view:cache`
8. `php artisan optimize:clear` then re-cache
9. `php artisan queue:restart` (restart workers after code changes)
10. Optionally bump APP_VERSION from git tag or ask interactively
11. Print deployment summary with version

**Step 1: Create scripts/deploy.sh**

```bash
touch scripts/deploy.sh
chmod +x scripts/deploy.sh
```

**Step 2: Write the script**

Same style conventions as install.sh (set -euo pipefail, colors, logging, version banner).

**Step 3: Commit**

```bash
git add scripts/deploy.sh
git commit -m "feat: add production deployment/update script"
```

---

## Phase 3: Server Configuration Templates

### Task 5: Add Nginx vhost template

**Objective:** Provide a tested Nginx configuration template for Nexum PSA.

**Files:**
- Create: `deploy/nginx/nexum-psa.conf.example`

**Template must include:**
- Listen 80 with redirect to 443
- SSL configuration (cert paths as placeholders)
- `root` pointing to `public/` directory
- Laravel-friendly `try_files` with index.php fallback
- PHP-FPM socket path (with OS variants in comments)
- Security headers (X-Frame-Options, X-Content-Type-Options, etc.)
- Client max body size for file uploads
- Gzip compression
- Static asset caching headers
- `.well-known` block for Let's Encrypt

**Step 1: Create template**

```bash
mkdir -p deploy/nginx
touch deploy/nginx/nexum-psa.conf.example
```

**Step 2: Write Nginx config**

**Step 3: Commit**

```bash
git add deploy/nginx/
git commit -m "feat: add Nginx virtual host configuration template"
```

---

### Task 6: Add Supervisor config template

**Objective:** Provide Supervisor config for `queue:work` and Laravel scheduler.

**Files:**
- Create: `deploy/supervisor/nexum-psa-worker.conf.example`
- Create: `deploy/supervisor/nexum-psa-scheduler.conf.example`

**Template must include:**
- Queue worker: `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600`
- Scheduler: `php artisan schedule:run` via Laravel's standard cron approach or a cron wrapper
- Autostart, autorestart, numprocs, redirect_stderr
- Path placeholders for the project directory and PHP binary
- Comments for OS-specific PHP paths (Debian: /usr/bin/php, Arch: /usr/bin/php-legacy)

**Step 1: Create templates**

```bash
mkdir -p deploy/supervisor
```

**Step 2: Commit**

```bash
git add deploy/supervisor/
git commit -m "feat: add Supervisor config templates for queue worker and scheduler"
```

---

### Task 7: Add systemd service template (optional — alternative to Supervisor)

**Objective:** Provide systemd unit files for environments that prefer systemd over Supervisor.

**Files:**
- Create: `deploy/systemd/nexum-worker.service.example`
- Create: `deploy/systemd/nexum-scheduler.timer.example`
- Create: `deploy/systemd/nexum-scheduler.service.example`

**Step 1: Create templates**

```bash
mkdir -p deploy/systemd
```

**Step 2: Write unit files**

- Worker service: runs `php artisan queue:work` with Restart=on-failure
- Scheduler timer: fires `php artisan schedule:run` every minute
- Scheduler service: the one-shot command the timer triggers

**Step 3: Commit**

```bash
git add deploy/systemd/
git commit -m "feat: add systemd unit file templates for queue worker and scheduler"
```

---

## Phase 4: Release Checklist and Version Tooling

### Task 8: Add CHANGELOG.md — start tracking releases

**Objective:** Create a CHANGELOG following Keep a Changelog format with the first entry for 0.1.0.

**Files:**
- Create: `CHANGELOG.md`

**Step 1: Create the file**

```markdown
# Changelog

All notable changes to Nexum PSA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-06-04

### Added
- Application version tracking via `APP_VERSION` in `.env` (config/app.php)
- Version display in admin footer and Talk bot responses
- Nextcloud Talk bot integration: outbound notifications and inbound webhook
- Talk bot commands: `!ping`, `!help`, `!status`
- Module API routes pattern (`app/Modules/{Domain}/api.php`)
- Talk webhook HMAC-SHA256 signature verification
- 9 feature tests for Talk webhook integration

### Changed
- `APP_NAME` default changed from "Laravel" to "Nexum PSA"
- Swagger/OpenAPI version now reads from `config('app.version')` instead of hardcoded value
- Talk webhook route moved from `routes/api.php` to `app/Modules/Nextcloud/api.php` (architecture compliance)

### Fixed
- `.env.example` `APP_NAME` now quoted to avoid dotenv parse errors
```

**Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add CHANGELOG.md starting at v0.1.0"
```

---

### Task 9: Add release checklist to docs/

**Objective:** A step-by-step checklist for cutting a release — version bump, build, tag, publish.

**Files:**
- Create: `docs/RELEASE-CHECKLIST.md`

**Checklist items:**
1. Update `APP_VERSION` in `.env` and `.env.example`
2. Update `CHANGELOG.md` with the new version
3. Run full test suite: `php artisan test`
4. Run Pint: `./vendor/bin/pint --test`
5. Build frontend: `npm run build`
6. Commit: `git commit -m "release: vX.Y.Z"`
7. Tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"`
8. Push: `git push origin main --tags`
9. Update GitHub Release notes from CHANGELOG
10. Deploy to production: `scripts/deploy.sh`
11. Verify: `!ping` in Talk shows new version, footer shows new version

**Step 1: Create the file**

**Step 2: Commit**

```bash
git add docs/RELEASE-CHECKLIST.md
git commit -m "docs: add release checklist"
```

---

## Phase 5: Installation Script Validation

### Task 10: Validate install.sh syntax and structure

**Objective:** Ensure the install script is syntactically valid and has the right structure before anyone runs it on a real server.

**Step 1: Syntax check**

```bash
bash -n scripts/install.sh
shellcheck scripts/install.sh  # if available
```

**Step 2: Dry-run test with --help flag**

```bash
bash scripts/install.sh --help
```

Expected: prints usage information and exits cleanly.

**Step 3: Commit any fixes**

---

### Task 11: Update README.md — add link to install script and production docs

**Objective:** Add links to the new install script and documentation in the README.

**Files:**
- Modify: `README.md`

**Add after the "Local Installation" section:**

```markdown
## Production Installation

For self-hosted production installs on Debian, Fedora, or Arch Linux, see:

- **[Installation Guide](docs/INSTALL.md)** — full step-by-step documentation
- **[Production .env Template](.env.production.example)** — secure defaults
- **[Install Script](scripts/install.sh)** — automated OS setup and Laravel deployment

Quick start:

```bash
curl -fsSLO https://raw.githubusercontent.com/SveinT83/Nexum-PSA/vX.Y.Z/scripts/install.sh
less install.sh
bash install.sh
```

Do not pipe install scripts directly from `Dev` into a shell. Production install
docs should point to a tagged release and let operators inspect the script before
running it.

## Releasing

See **[Release Checklist](docs/RELEASE-CHECKLIST.md)** and **[CHANGELOG.md](CHANGELOG.md)**.
```

**Step 1: Edit README.md**

**Step 2: Commit**

```bash
git add README.md
git commit -m "docs: add production installation and release links to README"
```

---

## Summary

| Task | Description | Phase |
|------|-------------|-------|
| 1 | INSTALL.md — full installation guide | Docs |
| 2 | .env.production.example — production defaults | Docs |
| 3 | install.sh — automated OS setup script | Script |
| 4 | deploy.sh — production update script | Script |
| 5 | Nginx vhost template | Config |
| 6 | Supervisor config templates | Config |
| 7 | systemd unit file templates | Config (optional) |
| 8 | CHANGELOG.md — start tracking releases | Docs |
| 9 | RELEASE-CHECKLIST.md — release process | Docs |
| 10 | Validate install.sh syntax | QA |
| 11 | Update README.md with links | Docs |

**Total: 11 tasks across 5 phases.**

Each task is self-contained and can be implemented independently. The install script depends on having the documentation and templates done first (Tasks 1-2, 5-7), but the templates can be written in any order.
