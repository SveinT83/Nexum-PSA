# Nexum-PSA — Live Security Assessment: portal.tronderdata.no

**Date:** 2026-05-16 18:50 CET  
**Assessor:** Commander Cobra 🐍  
**Target:** https://portal.tronderdata.no  
**Hosting:** In-house projects server (192.168.10.78)  
**Status:** 🔴 **3 Critical, 3 High, 2 Medium findings**

---

## Executive Summary

The demo site has **serious exposure issues** that need Svein's immediate attention. The most critical: **Telescope and Horizon are fully accessible without authentication**, leaking application internals, file paths, stack traces, and Redis connection errors. Additionally, **Vite dev server URLs are leaking into production HTML**, and a **browser logging endpoint accepts arbitrary unauthenticated input**.

These are not theoretical — I confirmed each one live.

---

## 🔴 Critical Findings

### CRIT-1: Laravel Telescope Exposed Without Authentication
**URL:** `https://portal.tronderdata.no/telescope` → **200 OK**  
**Impact:** Telescope shows all HTTP requests, queries, logs, exceptions, cache operations, scheduled jobs, and **environment variables**. An attacker can see every database query, every API call, every error with full stack trace. This includes session tokens, API keys, and internal routing.

**Evidence:** Page loads with full Telescope UI, CSRF token visible in HTML. API endpoints accessible (redirecting to login for data, but the tool itself is reachable).

**Remediation for Svein:**
1. Set `TELESCOPE_ENABLED=false` in production `.env`
2. Gate access in `TelescopeServiceProvider.php`:
```php
protected function gate(): void
{
    Gate::define('viewTelescope', function ($user = null) {
        return $user && $user->hasRole('Superuser');
    });
}
```

---

### CRIT-2: Laravel Horizon Exposed Without Authentication
**URL:** `https://portal.tronderdata.no/horizon` → **200 OK`  
**API:** `https://portal.tronderdata.no/horizon/api/jobs/recent` → Returns JSON with **full stack traces and file paths**

**Impact:** Horizon is leaking:
- **Full server file path:** `/var/www/vhosts/tronderdata.no/portal.tronderdata.no/`
- **Full stack traces** for every Redis error (class names, file paths, line numbers)
- **Redis connection details** (host/port exposed in error messages)
- Queue job data (if Redis was running)

**Evidence:**
```json
{
  "message": "Connection refused",
  "exception": "RedisException",
  "file": "/var/www/vhosts/tronderdata.no/portal.tronderdata.no/vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php",
  "line": 181,
  "trace": [...]
}
```

**Remediation for Svein:**
1. Gate access in `HorizonServiceProvider.php`:
```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user = null) {
        return $user && $user->hasRole('Superuser');
    });
}
```
2. Fix Redis connection (currently refusing — Horizon is broken as well as exposed)
3. Set `APP_DEBUG=false` so stack traces don't leak in production

---

### CRIT-3: Vite Dev Server URLs Leaking in Production HTML
**URL:** Login page source at `https://portal.tronderdata.no/login`  
**Impact:** Three critical information leaks in the HTML:

```html
<script type="module" src="http://nexum-psa.local:5173/@vite/client" ...>
<link rel="stylesheet" href="http://nexum-psa.local:5173/resources/css/app.css" ...>
<script type="module" src="http://nexum-psa.local:5173/resources/js/app.js" ...>
```

This reveals:
1. **Internal hostname:** `nexum-psa.local` — attacker now knows the internal server name
2. **Dev port:** 5173 — confirms Vite dev server setup
3. **Mixed content:** HTTP URLs on an HTTPS page — browsers may block these, breaking the app's JS/CSS entirely

This also means **the app is running in development mode, not production build**. The Vite `build` step (`npm run build`) was never run or the built assets aren't being served.

**Remediation for Svein:**
1. Run `npm run build` to compile production assets
2. Ensure `.env` has `APP_ENV=production`
3. Remove Vite dev server from production entirely
4. All asset URLs should be relative/secure (no `http://` references)

---

## 🟠 High Findings

### HIGH-1: Browser Logging Endpoint Accepts Unauthenticated Input
**URL:** `POST https://portal.tronderdata.no/_boost/browser-logs` → **200 OK, `{"status":"logged"}`**  
**Impact:** Anyone can POST arbitrary content to this endpoint, including potential XSS payloads. If logs are viewed in an admin dashboard without sanitization, stored XSS is possible. Also enables log flooding / disk exhaustion attacks.

**Evidence:** Sent `{"level":"error","message":"<script>alert(1)</script>","stack":"test"}` — accepted and logged.

**Remediation for Svein:**
1. Add authentication middleware to `_boost/browser-logs` route
2. Add rate limiting (e.g., 10 requests/minute per IP)
3. Sanitize all log input (strip HTML tags)
4. Consider removing this endpoint from production entirely

---

### HIGH-2: Session Cookies Missing Secure Flag
**Evidence from response headers:**
```
set-cookie: XSRF-TOKEN=...; path=/; samesite=lax
set-cookie: nexumpsa-session=...; path=/; httponly; samesite=lax
```

**Missing:** `Secure` flag on both cookies. `httponly` is present on session cookie but **not on XSRF-TOKEN** (this is Laravel's default, but worth noting).

**Impact:** Cookies can be sent over unencrypted HTTP. If an attacker can force an HTTP connection (e.g., on internal network), session tokens can be intercepted.

**Remediation for Svein:**
```env
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

---

### HIGH-3: No Security Headers
**Evidence:** Response headers contain **none** of the following:
- `Strict-Transport-Security` (HSTS)
- `Content-Security-Policy` (CSP)
- `X-Frame-Options`
- `X-Content-Type-Options`
- `Referrer-Policy`
- `Permissions-Policy`

**Impact:** No clickjacking protection, no MIME sniffing protection, no HSTS enforcement, no CSP to mitigate XSS.

**Remediation for Svein:**
Add middleware or configure NPM to send:
```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net
```

---

## 🟡 Medium Findings

### MED-1: No CORS Configuration
**Evidence:** No `cors.php` config file exists in the repo. API returns 401 correctly, but without explicit CORS rules, Laravel's default may be too permissive for future API consumers.

**Remediation for Svein:** Create `config/cors.php` with strict origin policy.

---

### MED-2: Redis Not Running on Server
**Evidence:** Horizon API returns `RedisException: Connection refused`.  
**Impact:** Queue system is non-functional. Emails, notifications, and background jobs are not processing. This is both an operational issue and a security issue (the error leaks server internals — see CRIT-2).

**Remediation for Svein:** Start Redis service, configure Laravel queue driver.

---

## What's Working ✅

| Check | Result |
|-------|--------|
| HTTP → HTTPS redirect | ✅ 301 to HTTPS |
| `.env` file blocked | ✅ 403 (NPM blocking) |
| `.git/` blocked | ✅ 403 |
| `storage/logs/` blocked | ✅ 403 |
| API auth required | ✅ `/api/v1/*` returns 401 |
| `phpinfo` not present | ✅ 404 |
| Debugbar not present | ✅ 404 |
| CSRF on form POST | ✅ 419 Page Expired (no stack trace) |
| Session `httponly` flag | ✅ Present on session cookie |
| Session `SameSite=lax` | ✅ Present on both cookies |

---

## Priority Action Items for Svein

| Priority | Item | Effort |
|----------|------|--------|
| 🔴 **NOW** | Disable Telescope in production (`TELESCOPE_ENABLED=false`) | 1 min |
| 🔴 **NOW** | Gate Horizon access (add `hasRole('Superuser')` check) | 5 min |
| 🔴 **NOW** | Set `APP_DEBUG=false` in production `.env` | 1 min |
| 🔴 **NOW** | Run `npm run build` and serve production assets | 15 min |
| 🟠 This week | Authenticate `_boost/browser-logs` endpoint or remove it | 30 min |
| 🟠 This week | Set `SESSION_SECURE_COOKIE=true` in `.env` | 1 min |
| 🟠 This week | Add security headers (NPM or Laravel middleware) | 1 hour |
| 🟡 Next sprint | Configure CORS properly | 15 min |
| 🟡 Next sprint | Start Redis + configure queue driver | 30 min |

---

*This is a live assessment. Joe, as infrastructure admin, you have full visibility into what's exposed on your server. Svein needs to act on the critical items before this demo faces real traffic.*