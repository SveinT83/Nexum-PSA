# Nexum PSA Security Audit

Updated: 2026-05-31  
Scope: current tdPSA/Nexum codebase in this repository  
Status: P0 exposure issues mostly addressed in code; several P1 hardening items remain open.

This document replaces the original 2026-05-16 audit snapshot. The original findings were useful, but several items are now historical because the codebase has changed.

## Current Summary

The application has a good authentication and authorization foundation:

- Laravel Fortify authentication.
- Role-based access through Tech/Admin/Superuser roles.
- Two-factor setup and enforcement middleware.
- Active-status checks.
- Login and 2FA rate limiting.
- Invite token expiry and invalidation behavior.
- Sanctum-protected API routes.
- Encrypted integration/notification secrets.

The most serious historical exposure findings have been addressed in code:

- Horizon is no longer registered.
- Telescope is only registered from `AppServiceProvider` when `APP_ENV=local`.
- Production session cookies are forced secure and encrypted when `APP_ENV=production`.
- Global security headers middleware exists and is covered by tests.
- `public/hot` is absent and `public/build` exists in the current working tree.

Remaining risk is now mainly around:

- Development-only packages/routes being present if production installs dev dependencies.
- Missing explicit CORS configuration.
- Raw or insufficiently sanitized HTML rendering.
- Ticket attachment upload validation.
- Production environment verification.

## Verified In Current Code

### Session Security

File: `config/session.php`

Current behavior:

- `session.encrypt` is forced `true` when `APP_ENV=production`.
- `session.secure` is forced `true` when `APP_ENV=production`.
- `SESSION_SAME_SITE` defaults to `lax`.
- `SESSION_HTTP_ONLY` defaults to `true`.

Dev verification command:

```bash
APP_ENV=production HOME=/tmp php artisan tinker --execute='dump(["session_encrypt" => config("session.encrypt"), "session_secure" => config("session.secure"), "session_same_site" => config("session.same_site")]);'
```

Expected:

- `session_encrypt => true`
- `session_secure => true`
- sane `same_site`, normally `lax`

### Security Headers

File: `app/Http/Middleware/SecurityHeaders.php`

Current behavior:

- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`
- `Content-Security-Policy`
- `Strict-Transport-Security` on HTTPS requests

Test coverage:

- `app/Modules/System/Tests/Feature/SecurityHeadersTest.php`

### Horizon

Current behavior:

- No `HorizonServiceProvider` exists in `app/Providers`.
- `php artisan route:list --path=horizon` returns no matching routes.

Status: fixed in code.

### Telescope

Files:

- `composer.json`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/TelescopeServiceProvider.php`

Current behavior:

- `laravel/telescope` is in `require-dev`.
- `composer.json` disables Telescope package auto-discovery.
- `AppServiceProvider` registers Telescope only when `APP_ENV=local` and the package class exists.
- `APP_ENV=production php artisan route:list --path=telescope` returns no matching routes in this codebase.

Remaining concern:

- `TelescopeServiceProvider` still contains an empty email allow-list gate. It should be hardened to Superuser if the provider is ever registered outside local by mistake.

Recommended fix:

```php
Gate::define('viewTelescope', function ($user = null) {
    return $user && method_exists($user, 'hasRole') && $user->hasRole('Superuser');
});
```

### Vite Production Assets

Current local working tree:

- `public/hot` is absent.
- `public/build/manifest.json` exists.

Production deployment still must ensure:

- `APP_ENV=production`
- no `public/hot`
- `npm run build` has been run
- rendered HTML uses `/build/assets/...`, not a Vite dev server URL

## Open Findings

### P0: Laravel Boost Browser Logs Route In Production If Dev Dependencies Are Installed

Observed locally:

```bash
APP_ENV=production HOME=/tmp php artisan route:list --path=_boost
```

Current result:

```text
POST _boost/browser-logs  boost.browser-logs
```

This means the Boost browser logging endpoint can exist in a production-like app boot if dev packages are installed. A correct production deploy should use:

```bash
composer install --no-dev --optimize-autoloader
```

Recommended code/config hardening:

- Add `laravel/boost` to `composer.json` `extra.laravel.dont-discover`.
- Register Boost only in local development if needed.
- Verify `APP_ENV=production php artisan route:list --path=_boost` returns no routes.

Risk:

- Unauthenticated log ingestion.
- Log flooding.
- Stored payload risk if logs are later viewed without escaping.

### P1: CORS Configuration Missing

File missing:

- `config/cors.php`

Recommended:

- Add explicit CORS config limited to `api/*`.
- Use configured allowed origins rather than wildcard behavior.
- Keep credentials behavior explicit.

Dev-testable:

- Assert `config('cors.paths')` exists.
- Assert CORS preflight behavior for allowed and disallowed origins.

### P1: Raw QR Code SVG Output

File:

- `app/Modules/UserManagement/Views/profile/security.blade.php`

Current pattern:

```blade
{!! $qrCodeUrl !!}
```

Risk:

- Raw SVG output is a stored/reflected XSS surface if upstream QR generation or input data is compromised.

Recommended:

- Render QR SVG as an image data URI, or sanitize the SVG before output.

### P1: Knowledge HTML Output Trusts `body_html`

File:

- `app/Modules/Knowledge/Views/Tech/show.blade.php`

Current pattern:

```blade
{!! $article->body_html ?: nl2br(e($article->body_markdown)) !!}
```

Risk:

- Stored XSS if article HTML is imported or generated from unsafe Markdown/HTML.

Recommended:

- Sanitize on save and/or output using a robust HTML sanitizer.
- The lockfile already contains `ezyang/htmlpurifier` through dependencies, so prefer centralizing sanitizer behavior rather than adding ad hoc regex.

### P1: Email HTML Sanitizer Is A Placeholder

Files:

- `app/Modules/Email/Services/HtmlSanitizer.php`
- `app/Modules/Email/Views/Tech/view.blade.php`

Current behavior:

- Regex removes script/style blocks and quoted event handlers.
- The code comment explicitly says it is a placeholder.
- Email view renders `{!! $message->body_html_sanitized !!}`.

Risk:

- Email HTML is hostile input. Regex sanitization is not enough long term.

Recommended:

- Replace with HTMLPurifier or render email HTML in a sandboxed iframe.
- Block external resources or proxy inline images deliberately.

### P1: Ticket Attachment Upload Validation Missing

File:

- `app/Modules/Ticket/Actions/StoreTicketAttachment.php`

Current behavior:

- Stores uploads on the `local` disk, which is good.
- Safe filename only strips `/` and `\`.
- No explicit max size validation in the action.
- No extension/MIME whitelist in the action.
- Uses client-reported MIME type for stored metadata.

Recommended:

- Add server-side validation in the action or request layer.
- Enforce max size.
- Enforce extension and real MIME checks.
- Keep storage on non-public disk.

### P2: Invite Token Hashing

Current assessment:

- Invite tokens expire and are invalidated on resend/use.
- Token lookup should eventually store and compare hashed tokens, similar to API tokens.

### P2: Account Lockout

Current assessment:

- Rate limiting exists.
- Account lockout after repeated distributed failures is not implemented.

### P2: Password Breach Detection

Current assessment:

- Password rules exist through Fortify.
- Add `Password::uncompromised()` if acceptable for deployment privacy and network behavior.

### P3: Audit Log UI

Current assessment:

- `spatie/laravel-activitylog` is installed.
- A dedicated admin audit log viewer is still missing.

## Dev Verification Checklist

These can be tested in local/dev without a separate pentest server:

```bash
APP_ENV=production HOME=/tmp php artisan route:list --path=telescope
APP_ENV=production HOME=/tmp php artisan route:list --path=horizon
APP_ENV=production HOME=/tmp php artisan route:list --path=_boost
APP_ENV=production HOME=/tmp php artisan tinker --execute='dump(["session_encrypt" => config("session.encrypt"), "session_secure" => config("session.secure")]);'
HOME=/tmp php artisan test app/Modules/System/Tests/Feature/SecurityHeadersTest.php
```

Expected:

- No Telescope routes in production mode.
- No Horizon routes.
- No Boost routes in production mode after the Boost hardening task is complete.
- Secure/encrypted session config in production mode.
- Security header tests pass.

Additional tests to add:

- CORS configuration tests.
- QR code output does not contain raw inline SVG.
- Knowledge article malicious HTML is sanitized.
- Email malicious HTML is sanitized or sandboxed.
- Ticket upload rejects `.php`, oversized files, and mismatched MIME content.

## Production / Linux Copy Pentest Scope

A separate Linux server copy should be used for future pentesting.

Before testing:

- Deploy with `composer install --no-dev --optimize-autoloader`.
- Set `APP_ENV=production`.
- Set `APP_DEBUG=false`.
- Run `npm ci` and `npm run build`.
- Ensure `public/hot` does not exist.
- Run `php artisan optimize:clear` then cache config/routes/views as appropriate.
- Run route checks for Telescope, Horizon, and Boost.
- Confirm queue worker setup.

The separate pentest should verify the deployed environment, not only the codebase.
