# Nexum PSA Live Security Assessment Notes

Original live assessment: 2026-05-16 against `https://portal.tronderdata.no`  
Updated: 2026-05-31  
Status: historical findings retained for context; new live verification should be performed on a dedicated Linux copy.

This document is no longer the source of truth for current codebase status. Use `docs/SECURITY-AUDIT.md` for current code review status and `docs/SECURITY-REMEDIATION-PLAN.md` for the active remediation and pentest plan.

## Historical Live Findings

The 2026-05-16 live assessment reported:

- Telescope exposed at `/telescope`.
- Horizon exposed at `/horizon`.
- Vite dev server URLs leaking in production HTML.
- `_boost/browser-logs` accepting unauthenticated input.
- Session cookies missing `Secure`.
- Missing security headers.
- Missing explicit CORS configuration.
- Redis/Horizon queue errors exposing internals.

These findings were valid for the tested live state at that time.

## Current Codebase Status

As of the current repository state:

- Horizon is not registered.
- Telescope package discovery is disabled and Telescope is only registered in local environment.
- Production session cookies are forced secure and encrypted by `config/session.php`.
- Global security headers middleware exists.
- `public/hot` is not present in the working tree and `public/build` exists.
- `_boost/browser-logs` still appears when `APP_ENV=production` if dev dependencies are installed locally.
- `config/cors.php` is still missing.
- Several P1 XSS/upload hardening items remain open.

## Live Verification Not Completed In This Review

The current review could not verify `portal.tronderdata.no` live headers or endpoints from this shell because outbound name resolution/network access failed.

Do not assume production is fixed only because code is fixed. Production must be verified after deployment.

## New Pentest Target

The next proper live assessment should be performed against a separate Linux server copy of Nexum.

Purpose:

- Verify deployment hardening.
- Verify public routes.
- Verify headers and cookies.
- Verify built assets.
- Test login, 2FA, authorization, uploads, XSS handling, and API behavior.
- Avoid disrupting the active/live portal.

## Pre-Pentest Deployment Requirements

The Linux copy should be deployed like production:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
```

Environment:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=database
```

Ensure:

- `public/hot` does not exist.
- `public/build/manifest.json` exists.
- Web server document root points to `public`.
- Storage directories are not public.
- HTTPS is enabled if the test includes cookie/HSTS validation.

## Quick Live Checks For The Linux Copy

Run after deployment:

```bash
curl -sI https://test-host.example/login
curl -sI https://test-host.example/telescope
curl -sI https://test-host.example/horizon
curl -sI https://test-host.example/_debugbar
curl -sI https://test-host.example/.env
curl -sI https://test-host.example/.git/config
curl -s https://test-host.example/login | grep -i "5173\\|@vite\\|nexum-psa.local"
```

Expected:

- Security headers present.
- `/telescope`, `/horizon`, and `/_debugbar` not available.
- `.env` and `.git` blocked or not found.
- Login HTML does not contain Vite dev server URLs.

Check Boost:

```bash
curl -s -o /tmp/boost-check.txt -w '%{http_code}\n' \
  -X POST https://test-host.example/_boost/browser-logs \
  -H 'Content-Type: application/json' \
  --data '{"level":"error","message":"security-check","stack":"none"}'
```

Expected after Boost hardening:

- 404 or another non-success response.

## Rules Of Engagement For Future Pentest

- Use the separate Linux copy, not the active production portal.
- Notify stakeholders before starting.
- Avoid denial-of-service testing.
- Avoid high-speed scanners unless explicitly approved.
- Do not exfiltrate data.
- Document every test, request, result, and fix.
- Re-test after remediation.

## Result Recording

Create a new dated report when the Linux copy is tested, for example:

```text
docs/SECURITY-ASSESSMENT-LINUX-COPY-YYYY-MM-DD.md
```

Keep this file as historical context only.
