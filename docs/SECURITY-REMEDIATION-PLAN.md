# Nexum PSA Security Remediation And Pentest Plan

Updated: 2026-05-31  
Scope: current codebase, local/dev verification, and future dedicated Linux copy pentest.

## Current Remediation Status

| Priority | Finding | Current Status | Dev-Testable |
| --- | --- | --- | --- |
| P0 | Session cookies not secure/encrypted | Fixed in code for `APP_ENV=production` | Yes |
| P0 | APP_DEBUG may be true in production | Environment/deploy verification required | Linux copy |
| P0 | Horizon exposed | Fixed in code, no route registered | Yes |
| P0 | Telescope exposed | Fixed for production mode; local-only registration | Yes |
| P0 | Vite dev server URLs in production | Fixed in current tree; deployment must build assets | Partial |
| P0 | Boost browser logs exposed | Still open if dev dependencies installed in production | Yes |
| P1 | Missing CORS config | Open | Yes |
| P1 | Missing security headers | Fixed in code and tested | Yes |
| P1 | QR code raw SVG | Open | Yes |
| P1 | Knowledge HTML output trusts stored HTML | Open | Yes |
| P1 | Email HTML sanitizer is placeholder | Open | Yes |
| P1 | Ticket attachment upload validation | Open | Yes |
| P2 | Invite tokens stored as raw tokens | Open | Yes |
| P2 | Account lockout beyond rate limiting | Open | Yes |
| P2 | Password breach detection | Open | Yes |
| P3 | Audit log UI | Open | Yes |

## Immediate Dev Remediation Tasks

These can be fixed and tested in the dev environment before preparing the separate Linux copy.

### 1. Harden Laravel Boost Production Exposure

Problem:

- `_boost/browser-logs` is registered under `APP_ENV=production` when dev dependencies are installed locally.

Fix:

- Add `laravel/boost` to `composer.json` `extra.laravel.dont-discover`.
- Register Boost only in local development if the project still needs it.
- Ensure production deployment uses `composer install --no-dev`.

Verification:

```bash
APP_ENV=production HOME=/tmp php artisan route:list --path=_boost
```

Expected:

- No matching routes.

### 2. Add Explicit CORS Config

Problem:

- `config/cors.php` is missing.

Fix:

- Add `config/cors.php`.
- Restrict paths to `api/*` unless other paths are explicitly needed.
- Restrict origins through `CORS_ALLOWED_ORIGINS`.
- Keep credentials behavior explicit.

Verification:

- Add a feature test for allowed and disallowed CORS preflight requests.

### 3. Fix QR Code Raw SVG Output

Problem:

- `app/Modules/UserManagement/Views/profile/security.blade.php` renders raw QR SVG.

Fix:

- Render the QR code as an image data URI or sanitize SVG output.

Verification:

- Feature/view test asserts raw `<svg` is not emitted directly.
- Manual 2FA setup still works and manual TOTP code remains available.

### 4. Sanitize Knowledge HTML

Problem:

- `app/Modules/Knowledge/Views/Tech/show.blade.php` trusts `body_html`.

Fix:

- Centralize HTML sanitization using HTMLPurifier or an equivalent robust sanitizer.
- Sanitize on save and/or output.

Verification:

- Feature test creates an article with malicious HTML and asserts script/event handlers are removed.

### 5. Harden Email HTML Rendering

Problem:

- `HtmlSanitizer` is a regex placeholder and email body is rendered raw after sanitization.

Fix options:

- Replace sanitizer with HTMLPurifier.
- Or render email HTML in a sandboxed iframe with carefully controlled `srcdoc`.
- Block external resources or plan proxy behavior deliberately.

Verification:

- Feature/unit tests for script tags, event handlers, `javascript:` URLs, and hostile HTML payloads.

### 6. Add Ticket Attachment Validation

Problem:

- Ticket upload action lacks explicit max size, extension, and real MIME validation.

Fix:

- Add validation in request/controller/action path.
- Enforce max size.
- Enforce extension/MIME allow-list.
- Use server-detected MIME for validation.
- Keep `local` disk storage.

Verification:

- `.php` upload rejected.
- Oversized upload rejected.
- Allowed text/PDF/image upload accepted.
- Stored path remains on non-public disk.

### 7. Add Dev Security Regression Tests

Create/extend tests for:

- Production route absence: Telescope/Horizon/Boost.
- Production session config.
- Security headers.
- CORS.
- QR output.
- Knowledge sanitizer.
- Email sanitizer.
- Ticket attachment validation.

## Future Linux Copy Pentest Plan

Use a separate Linux server copy for external-style pentesting. Do not use active production as the primary test target.

### Deployment Baseline

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

### Phase 1: Passive Recon

| Test | Expected |
| --- | --- |
| HTTP redirects to HTTPS | 301/302 to HTTPS |
| Security headers present | CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| Cookie flags | Secure, HttpOnly, SameSite |
| `.env` blocked | 403 or 404 |
| `.git` blocked | 403 or 404 |
| `storage/logs` blocked | 403 or 404 |
| `/telescope` unavailable | 404 or protected non-success |
| `/horizon` unavailable | 404 |
| `/_boost/browser-logs` unavailable | 404 or non-success |
| Login HTML has no Vite dev URLs | No `5173`, `@vite/client`, or local hostname |

### Phase 2: Authentication

| Test | Expected |
| --- | --- |
| Login brute force | Rate limited |
| Account enumeration | Same generic login error |
| Session fixation | Session changes after login |
| Logout | Session invalidated |
| 2FA bypass attempt | Redirect/challenge enforced |
| 2FA setup enforcement | Required roles redirected to setup |

### Phase 3: Authorization

| Test | Expected |
| --- | --- |
| Tech accesses admin route | 403 |
| Unauthenticated tech route access | Redirect to login |
| API without token | 401 |
| Admin route with tech user | 403 |
| Cross-user profile/security access | 403 or own-profile redirect |

### Phase 4: Input And Content

| Test | Expected |
| --- | --- |
| Reflected XSS in search fields | Escaped/no execution |
| Stored XSS in ticket/client/contact fields | Escaped/no execution |
| Knowledge malicious HTML | Sanitized |
| Email malicious HTML | Sanitized or sandboxed |
| SQL injection strings in search | No SQL error or broad bypass |
| `.php` ticket attachment | Rejected |
| Oversized attachment | Rejected |
| CSRF-less POST | 419 |

### Phase 5: Business Logic

| Test | Expected |
| --- | --- |
| Invite token reuse | Rejected |
| Invite token tampering | Rejected |
| Password reset for other user | No account takeover |
| Ticket merge authorization | Only authenticated tech actions |
| Ticket workflow bypass | Blocked by runtime checks |

### Phase 6: Infrastructure

| Test | Expected |
| --- | --- |
| Triggered 500 in production mode | No stack trace/env |
| Directory listing | Disabled |
| phpinfo paths | 404 |
| Default credentials | Rejected |
| Queue worker | Processes queued mail/jobs |

## Reporting

For the Linux copy, write a dated report:

```text
docs/SECURITY-ASSESSMENT-LINUX-COPY-YYYY-MM-DD.md
```

Each finding should include:

- Severity.
- Endpoint/file.
- Reproduction steps.
- Evidence.
- Impact.
- Fix.
- Re-test result.

## Rules Of Engagement

- Test only the approved Linux copy.
- No denial-of-service testing.
- No high-speed scanners without explicit approval.
- No testing unrelated services on the same host.
- No data exfiltration.
- Document and fix, then re-test.
