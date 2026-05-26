# Nexum-PSA — Security Remediation & Pen-Test Plan

**Created:** 2026-05-16  
**Demo site:** https://portal.tronderdata.no  
**Hosting:** In-house server (projects server, 192.168.10.78)

---

## Part 1: Remediation Plan (from Security Audit)

### 🔴 P0 — Must Fix Before Any External Exposure

| # | Finding | File/Config | Fix | Status |
|---|---------|-------------|-----|--------|
| 1 | Session cookies not secure | `.env`, `config/session.php` | Production forces secure and encrypted session cookies; `.env.example` documents secure attributes | ✅ 2026-05-25 |
| 2 | APP_DEBUG may be true | `.env` | Verify `APP_DEBUG=false` in production | 🔲 |
| 3 | Horizon gate empty | `HorizonServiceProvider.php` | Horizon removed; app uses the database queue driver | ✅ 2026-05-25 |
| 4 | Telescope gate open | `TelescopeServiceProvider.php` | Telescope package discovery disabled and providers registered only in `local` | ✅ 2026-05-25 |
| 5 | No CORS config | `config/cors.php` missing | Create with strict origin policy | 🔲 |
| 6 | No security headers | `SecurityHeaders` middleware | Global middleware adds CSP, HSTS on HTTPS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, and Permissions-Policy | ✅ 2026-05-25 |

### 🟡 P1 — Fix This Sprint

| # | Finding | File/Config | Fix | Status |
|---|---------|-------------|-----|--------|
| 7 | XSS: QR code raw SVG | `security.blade.php:108` | Base64-encode into `<img>` tag | 🔲 |
| 8 | XSS: Knowledge base HTML | `Knowledge/show.blade.php:34` | Add `mews/purifier`, sanitize on output | 🔲 |
| 9 | XSS: Email HTML body | `Email/view.blade.php:37` | Sandboxed iframe or HTML purifier | 🔲 |
| 10 | File upload: no MIME/size validation | `StoreTicketAttachment.php` | Whitelist MIME types + 10MB limit + real MIME check | 🔲 |

### 🟢 P2 — Next Sprint

| # | Finding | File/Config | Fix | Status |
|---|---------|-------------|-----|--------|
| 11 | Invite token timing attack | `AcceptInviteController.php` | Hash tokens with SHA-256 before storage/lookup | 🔲 |
| 12 | No account lockout | N/A | Add `login_attempts` + `locked_until` columns | 🔲 |
| 13 | No password breach detection | `PasswordValidationRules.php` | Add `Password::uncompromised()` | 🔲 |
| 14 | Audit log no UI | `spatie/laravel-activitylog` installed | Build audit log viewer in admin | 🔲 |

---

## Part 2: Pen-Test Plan — portal.tronderdata.no

**Scope:** The publicly accessible demo instance of Nexum-PSA at https://portal.tronderdata.no  
**Hosted on:** Projects server (192.168.10.78) — our infrastructure  
**Authorization:** Joe (boss) has approved testing against this instance  
**Rule:** This is our own server. We test to find weaknesses before attackers do. No external tools that could cause service disruption.

### Phase 1: Reconnaissance (Passive)

| Test | Method | Expected | Pass/Fail |
|------|--------|----------|-----------|
| **1.1** TLS configuration | Check SSL Labs grade (ssllabs.com/ssltest) | A or A+ | 🔲 |
| **1.2** HTTP → HTTPS redirect | `curl -I http://portal.tronderdata.no` | 301/302 to HTTPS | 🔲 |
| **1.3** Security headers present | `curl -sI https://portal.tronderdata.no` | HSTS, X-Frame-Options, CSP, X-Content-Type-Options, Referrer-Policy | 🔲 |
| **1.4** Server info leakage | Check response headers for `Server`, `X-Powered-By` | Should be absent or generic | 🔲 |
| **1.5** Cookie flags | Inspect `Set-Cookie` headers | `Secure`, `HttpOnly`, `SameSite` flags present | 🔲 |
| **1.6**robots.txt | `curl https://portal.tronderdata.no/robots.txt` | Disallows /tech, /admin, /horizon, /telescope, /api | 🔲 |
| **1.7** Well-known paths | Probe /.env, /.git, /storage/logs/laravel.log, /telescope, /horizon, /_debugbar | All should 404 or 403 | 🔲 |
| **1.8** API endpoint exposure | `curl https://portal.tronderdata.no/api/v1/...` | 401 Unauthorized (no public API) | 🔲 |

### Phase 2: Authentication Testing

| Test | Method | Expected | Pass/Fail |
|------|--------|----------|-----------|
| **2.1** Brute force protection | 10 rapid login attempts with wrong password | Rate limited (429) after 5 attempts | 🔲 |
| **2.2** Account enumeration | Login with nonexistent email vs wrong password | Same error message (no "user not found" vs "wrong password") | 🔲 |
| **2.3** Session fixation | Set custom session cookie before login, check if it changes after | Session ID regenerates on login | 🔲 |
| **2.4** Session timeout | Idle for >120 min, then request authenticated page | Redirected to login (session expired) | 🔲 |
| **2.5** Remember me token security | Login with "remember me", inspect cookie | Token is long random, HttpOnly, Secure | 🔲 |
| **2.6** Concurrent session handling | Login from two browsers, change password in one | Other session invalidated | 🔲 |
| **2.7** Logout completeness | Logout, then use back button / request authenticated page | Session invalidated, CSRF token regenerated | 🔲 |

### Phase 3: Authorization Testing

| Test | Method | Expected | Pass/Fail |
|------|--------|----------|-----------|
| **3.1** Vertical privilege escalation | Regular tech user accesses /tech/admin/* | 403 Forbidden | 🔲 |
| **3.2** Horizontal privilege escalation | Tech A accesses Tech B's profile/security page | 403 or redirect to own profile | 🔲 |
| **3.3** Force browsing | Unauthenticated user directly accesses /tech/tickets | 302 → login | 🔲 |
| **3.4** API auth bypass | Call API endpoints without Sanctum token | 401 Unauthorized | 🔲 |
| **3.5** IDOR on tickets | Tech accesses ticket belonging to different client (if RBAC per client exists) | 403 or filtered results | 🔲 |
| **3.6** Admin API protection | Call admin API endpoints with tech-level token | 403 Forbidden | 🔲 |

### Phase 4: Input Validation & Injection

| Test | Method | Expected | Pass/Fail |
|------|--------|----------|-----------|
| **4.1** Reflected XSS in search | `<script>alert(1)</script>` in ticket search, client search | Sanitized, no execution | 🔲 |
| **4.2** Stored XSS in ticket description | `<img src=x onerror=alert(1)>` in ticket body | Sanitized on display | 🔲 |
| **4.3** Stored XSS in client name | `<script>` in client name field | Sanitized in all views | 🔲 |
| **4.4** SQL injection in search | `' OR 1=1 --` in search fields | Parameterized query, no injection | 🔲 |
| **4.5** File upload — malicious extension | Upload `.php` file as ticket attachment | Rejected or stored as non-executable | 🔲 |
| **4.6** File upload — oversized file | Upload 100MB file as attachment | Rejected with size limit error | 🔲 |
| **4.7** Mass assignment | POST extra fields (e.g., `role=superadmin`) to user creation | Only `$fillable` fields accepted | 🔲 |
| **4.8** CSRF on state changes | Submit POST without CSRF token | 419 CSRF token mismatch | 🔲 |

### Phase 5: Business Logic

| Test | Method | Expected | Pass/Fail |
|------|--------|----------|-----------|
| **5.1** Invite token reuse | Use same invite link twice | Token marked as used, second attempt fails | 🔲 |
| **5.2** Invite token tampering | Modify token characters in URL | "Invalid or expired" message | 🔲 |
| **5.3** 2FA bypass attempt | Login with 2FA user, skip 2FA challenge URL | Redirected back to 2FA challenge | 🔲 |
| **5.4** 2FA enforcement bypass | 2FA-required role, user hasn't set up 2FA, try accessing other pages | Redirected to security settings | 🔲 |
| **5.5** Password reset for other user | Request password reset for another user's email | Only sends to that user's email (no account takeover) | 🔲 |
| **5.6** Ticket SLA manipulation | Manually set `first_response_due_at` to far future | Server validates, doesn't allow gaming SLA | 🔲 |

### Phase 6: Infrastructure

| Test | Method | Expected | Pass/Fail |
|------|--------|----------|-----------|
| **6.1** Debug mode check | Trigger 500 error, check response body | No stack trace, no env vars | 🔲 |
| **6.2** Directory listing | Access /storage/, /app/ via browser | 403 Forbidden | 🔲 |
| **6.3** Horizon access | https://portal.tronderdata.no/horizon | 404; Horizon is not installed or registered | 🔲 |
| **6.4** Telescope access | https://portal.tronderdata.no/telescope | 404 in production; available only in local development | 🔲 |
| **6.5** phpinfo exposure | Check for /phpinfo, /info.php | 404 | 🔲 |
| **6.6** Default credentials | Try admin/admin, admin/password | Login rejected | 🔲 |

---

## Execution Plan

1. **Apply P0 fixes first** (items 1-6) — 30 min of config changes
2. **Run Phase 1 (Recon)** against the demo site — establish baseline
3. **Apply P1 fixes** (items 7-10)
4. **Run Phases 2-6** systematically — document all findings
5. **Fix any new findings** discovered during pen-test
6. **Re-test** to confirm fixes
7. **Write final report** with before/after for each test

### Tools

- `curl` / `httpie` — header inspection, request crafting
- Browser DevTools — cookie inspection, XSS testing
- `nikto` (optional) — automated web server scanner
- Manual testing — most important, this is where logic bugs hide

### Rules of Engagement

- ✅ Test against portal.tronderdata.no (our server, approved)
- ✅ Test during business hours (notify Joe before starting)
- ✅ Document every test and result
- ❌ No denial-of-service testing (it's a shared server)
- ❌ No automated vulnerability scanners at high speed
- ❌ No testing of other services on the same host (Grafana, War Room, etc.)
- ❌ No data exfiltration even if found — report and fix only

---

*Last updated: 2026-05-25*
