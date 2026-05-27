# Nexum-PSA — Security Audit

**Date:** 2026-05-16  
**Auditor:** Commander Cobra 🐍  
**Branch:** feature/user-invite-mfa

---

## Summary

The platform has a **solid authentication foundation** — Fortify with 2FA, rate limiting, active-status checks, and role-based access. But there are real gaps that need closing before production, especially around session security, debug exposure, admin tool access, and XSS vectors.

**Risk level: 🟡 Medium** — good bones, needs hardening.

---

## ✅ What's Good

### Authentication & Authorization
- **Fortify** with proper password validation (`Password::default()` = min 8, letters + numbers)
- **2FA enforcement** via `RequireTwoFactor` middleware — role-based, with redirect to setup page
- **Login rate limiting**: 5 req/min per email+IP combo (Fortify default, explicitly configured)
- **2FA rate limiting**: 5 req/min per session (configured in FortifyServiceProvider)
- **Active status check** in both `TechAccess` and `AdminAccess` middleware — inactive users get logged out + session invalidated + CSRF token regenerated
- **Role-based access**: TechAccess (Superuser/Tech/Admin), AdminAccess (Superuser/Admin)
- **Invite tokens**: expire after 72h, invalidated on re-send, marked as used
- **No unguarded models** — all models use `$fillable` (no `$guarded = []`)

### Data Protection
- **Encrypted secrets** in `Integration` and `NotificationChannel` models (`Crypt::encryptString`)
- **Password hashing**: Laravel default (bcrypt via `hashed` cast)
- **`.env` in `.gitignore`** ✅

### API
- **Sanctum** for API auth — token-based, proper middleware
- **API routes behind `auth:sanctum`** ✅

---

## 🔴 Critical — Fix Before Production

### 1. Session Security: `SESSION_SECURE_COOKIE` Not Enforced
**File:** `config/session.php`  
**Issue:** `SESSION_SECURE_COOKIE` defaults to `env()` — if not set, cookies sent over HTTP in cleartext. Same for `SESSION_ENCRYPT=false` by default.

**Fix:**
```env
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=strict  # or lax if you need cross-site navigation
```

In production, also set `SESSION_DOMAIN` to your exact domain to prevent subdomain session leaks.

### 2. APP_DEBUG Must Be False in Production
**Issue:** `APP_DEBUG=true` is the default in `.env.example`. If this leaks to production, Laravel exposes full stack traces, environment variables (including DB passwords, API keys), and request data to anyone who triggers an error.

**Fix:** Verify `.env` has `APP_DEBUG=false` in production. Add to deployment docs.

### 3. Horizon Gate — Empty Allow-List
**File:** `app/Providers/HorizonServiceProvider.php`  
**Issue:** The Horizon gate has an **empty email array**. In production, if `APP_ENV=local` is accidentally set, **anyone** can access the Horizon dashboard at `/horizon` and see all queue jobs, failed jobs, and potentially sensitive data.

**Fix:**
```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user = null) {
        return $user && $user->hasRole('Superuser');
    });
}
```

### 4. Telescope — No Access Gate in Production
**File:** `app/Providers/TelescopeServiceProvider.php`  
**Issue:** Telescope is enabled by default (`TELESCOPE_ENABLED=true`). The gate only checks for local environment. In production, if env is not properly set, Telescope exposes queries, logs, requests, exceptions, and **all environment variables**.

**Fix:** Add proper gate + disable in production:
```php
protected function gate(): void
{
    Gate::define('viewTelescope', function ($user = null) {
        return $user && $user->hasRole('Superuser');
    });
}
```
```env
TELESCOPE_ENABLED=false  # in production
```

### 5. No CORS Configuration
**Issue:** No `config/cors.php` exists. Laravel 12 uses a default that may be too permissive for API routes. If the API is exposed, any origin could make authenticated requests via browser.

**Fix:** Create `config/cors.php`:
```php
'paths' => ['api/*'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
'allowed_origins' => [env('APP_URL')],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

---

## 🟡 Important — Fix Soon

### 6. XSS: Unescaped QR Code SVG Output
**File:** `app/Modules/UserManagement/Views/profile/security.blade.php:108`  
```blade
{!! $qrCodeUrl !!}
```
**Issue:** `{!! !!}` outputs raw HTML. If the QR code SVG is tampered with (e.g., via a compromised `Google2FA` library or a malicious injection into the secret), this becomes an XSS vector.

**Fix:** Sanitize the SVG output or use a dedicated QR code library that renders to an `<img>` data-URI instead of raw SVG injection:
```blade
<img src="data:image/svg+xml;base64,{{ base64_encode($qrCodeUrl) }}" alt="2FA QR Code">
```

### 7. XSS: Knowledge Base HTML Output
**File:** `app/Modules/Knowledge/Views/Tech/show.blade.php:34`  
```blade
{!! $article->body_html ?: nl2br(e($article->body_markdown)) !!}
```
**Issue:** `body_html` is output unescaped. If articles can be created by non-superusers or imported from external sources, this is stored XSS.

**Fix:** Use a HTML purifier (e.g., `mews/purifier`) on save, or at minimum sanitize on output:
```bash
composer require mews/purifier
```
```blade
{!! Purifier::clean($article->body_html) !!}
```

### 8. XSS: Email HTML Body
**File:** `app/Modules/Email/Views/Tech/view.blade.php:37`  
```blade
{!! $message->body_html_sanitized !!}
```
**Issue:** "sanitized" in the variable name is hopeful, but `{!! !!}` trusts it completely. If sanitization is incomplete, this is XSS.

**Fix:** Same as above — run through HTML purifier, or render in a sandboxed iframe:
```blade
<iframe srcdoc="{{ $message->body_html_sanitized }}" sandbox="allow-same-origin" style="width:100%;border:none;min-height:300px"></iframe>
```

### 9. File Upload: No MIME Type Validation
**File:** `app/Modules/Ticket/Actions/StoreTicketAttachment.php`  
**Issue:** `safeFilename()` only strips `/` and `\`. There's no validation of:
- File extension (`.php`, `.phtml`, `.sh` could be uploaded)
- MIME type verification against actual content (vs. `getClientMimeType()` which comes from the client)
- File size limits

**Fix:**
```php
private function validateUpload(UploadedFile $file): void
{
    $allowedMimes = ['image/*', 'application/pdf', 'text/plain', 'application/zip',
                     'application/vnd.openxmlformats-officedocument.*'];
    $maxSize = 10 * 1024 * 1024; // 10MB

    if ($file->getSize() > $maxSize) {
        throw ValidationException::withMessages(['attachment' => 'File too large.']);
    }

    // Check real MIME, not client-reported
    $realMime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file->getRealPath());
    // ... validate against whitelist
}
```
Also ensure the storage disk is **not web-accessible** (use `storage:`, not `public:`).

### 10. No Security Headers
**Issue:** No Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Strict-Transport-Security, or Referrer-Policy headers.

**Fix:** Add middleware or use `fruitcake/laravel-cors` + a CSP package:
```bash
composer require spatie/laravel-csp
```
Minimum headers:
```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Strict-Transport-Security: max-age=31536000; includeSubDomains
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'
```

---

## 🟢 Minor — Fix When Convenient

### 11. Invite Token Timing Attack
**Issue:** Invite token lookup uses `where('token', $token)->first()`. This is a timing-varying comparison. For invite tokens (not session tokens), this is low-risk, but worth noting.

**Fix:** Use `where('token', hash('sha256', $token))` and store hashed tokens. Same pattern as API tokens in Laravel.

### 12. Two-Factor Recovery Codes Not Re-Encrypted
**Issue:** `two_factor_recovery_codes` are encrypted by Fortify but the key is `APP_KEY`. If `APP_KEY` leaks, all recovery codes are compromised. This is standard Laravel behavior but worth noting — store `APP_KEY` securely.

### 13. No Account Lockout
**Issue:** Rate limiting prevents rapid-fire logins but doesn't lock accounts. After 100 failed attempts (over 20 minutes at 5/min), the attacker can keep trying.

**Fix:** Add a `login_attempts` + `locked_until` column to users, or use `laravel/fortify`'s built-in `LockoutResponse`.

### 14. No Password Breach Detection
**Fix:** Add `Password::defaults()` with the `Password::uncompromised()` rule:
```php
Password::min(8)->uncompromised()
```
This checks against HaveIBeenPwned (offline via range query — no full hash sent).

### 15. Activity Log Not Exposed
**Issue:** `spatie/laravel-activitylog` is installed but I see no audit UI. Activity logs are critical for incident response and compliance.

**Fix:** Build the audit log viewer (1 day effort, already in the assessment P3 list).

---

## Security Checklist

| Item | Status | Priority |
|------|--------|----------|
| Authentication (Fortify) | ✅ | — |
| Two-Factor Authentication | ✅ | — |
| 2FA Enforcement (role-based) | ✅ | — |
| Rate Limiting (login + 2FA) | ✅ | — |
| CSRF Protection | ✅ | — |
| Role-Based Access Control | ✅ | — |
| Active Status Check | ✅ | — |
| Invite Token Expiry | ✅ | — |
| Encrypted Secrets (Integrations) | ✅ | — |
| Session Secure Cookie | 🔴 Missing | P0 |
| Session Encryption | 🔴 Missing | P0 |
| APP_DEBUG in Production | 🔴 Must verify | P0 |
| Horizon Gate | 🔴 Empty | P0 |
| Telescope Gate | 🔴 Open | P0 |
| CORS Configuration | 🔴 Missing | P0 |
| XSS: QR Code SVG | 🟡 Raw output | P1 |
| XSS: Knowledge Base HTML | 🟡 Unsanitized | P1 |
| XSS: Email HTML Body | 🟡 Unsanitized | P1 |
| File Upload Validation | 🟡 No MIME/size check | P1 |
| Security Headers (CSP, HSTS) | 🟡 Missing | P1 |
| Invite Token Hashing | 🟢 Timing attack | P2 |
| Account Lockout | 🟢 Missing | P2 |
| Password Breach Check | 🟢 Easy win | P2 |
| Audit Log UI | 🟢 Missing | P3 |

---

## Recommended Fixes (Ordered)

**Today (30 min):**
1. Set `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=strict` in `.env`
2. Fix Horizon gate → role-based
3. Fix Telescope gate → role-based + disabled in production
4. Verify `APP_DEBUG=false` in production `.env`

**This week:**
5. Create CORS config
6. Fix QR code XSS (base64 SVG in img tag)
7. Add HTML purifier for knowledge base + email views
8. Add file upload MIME + size validation
9. Add security headers middleware

**Next sprint:**
10. Hash invite tokens before storage
11. Add account lockout mechanism
12. Enable `Password::uncompromised()`
13. Build audit log viewer

---

*"Security isn't a feature. It's a practice."* — Someone who learned the hard way.