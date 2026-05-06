# Routing & Middleware Overview

This document describes how routing is structured in Nexum PSA, how middleware protection is applied today, and how new routes should be created.

## Route Files and Responsibilities

The application is split across dedicated route files:

- `routes/web.php` - public web routes (landing, iframe-based auto-login flow, public contract links).
- `routes/tech.php` - technician workspace routes.
- `routes/techAdmin.php` - technical administration routes.
- `routes/client.php` - client portal (`Minesider`) routes.
- `routes/api.php` - versioned API routes.
- `routes/console.php` - Artisan closure commands.

## How Route Files Are Registered

Route registration is handled in `bootstrap/app.php`:

- `web.php` is registered as the primary web routes file.
- `api.php` is registered as API routes.
- `tech.php` and `techAdmin.php` are loaded inside a shared group with:
  - URL prefix: `tech`
  - route-name prefix: `tech.`
  - middleware: `web`
- `client.php` is loaded inside a group with:
  - URL prefix: `client`
  - route-name prefix: `client.`
  - middleware: `web`

## URL Prefix Rules (Current Team Rule)

For view routes in the internal workspace, URLs must start with `tech`.

- Correct: `/tech/dashboard`, `/tech/contracts`, `/tech/admin/...`
- Not allowed for internal views: `/dashboard`, `/admin/...` without the `/tech` prefix

This is enforced by grouping both technician and tech-admin files under the `tech` prefix in `bootstrap/app.php`.

## Access Zones

### 1) Public Zone (`web.php`)

Typical routes:
- `/` (welcome page and signed iframe-login flow)
- `/contract/view/{token}`
- `/contract/accept/{token}`

Security characteristics today:
- No `auth` middleware required for these endpoints.
- The root route supports signed HMAC auto-login with:
  - `email`
  - `ts` (timestamp)
  - `sig` (HMAC-SHA256)
- Token age check is currently 300 seconds.

### 2) Technician Zone (`tech.php`)

All routes are protected by:
- `auth`
- `tech`

This means users must be logged in and pass `TechAccess` role checks.

### 3) Tech Admin Zone (`techAdmin.php`)

All routes are protected by:
- `auth`
- `tech`
- `admin`

This is a stricter layer on top of the technician zone for administration endpoints under `/tech/admin/...`.

### 4) Client Zone (`client.php`)

Current state:
- Mounted under `/client` with route names `client.*`.
- Currently only `web` middleware is guaranteed by route registration.
- No dedicated `client` middleware alias is configured today.

Recommended target state:
- Add explicit access middleware for portal users (for example `auth` + a dedicated `client` middleware).
- Ensure role/tenant checks are enforced for all `client.*` routes.

### 5) API Zone (`api.php`)

API routes are versioned under `/api/v1` and named `api.v1.*`.

Current protection:
- All exposed v1 endpoints are inside `Route::middleware('auth:sanctum')`.
- Unauthenticated requests receive 401 responses.

## Middleware Aliases in Use

Custom aliases are registered in `bootstrap/app.php`:

- `tech` => `App\Http\Middleware\TechAccess`
- `admin` => `App\Http\Middleware\AdminAccess`

### `TechAccess` behavior

`App\Http\Middleware\TechAccess` enforces:

1. If user is not authenticated: redirect to `login`.
2. If authenticated, require one of these roles:
   - `Superuser`
   - `Tech`
   - `Admin`
3. Otherwise, abort with HTTP 403.

### `AdminAccess` behavior

`App\Http\Middleware\AdminAccess` enforces:

1. If user is not authenticated: redirect to `login`.
2. If authenticated, require one of these roles:
   - `Superuser`
   - `Admin`
3. Otherwise, abort with HTTP 403.

## API Security (Documented Current State)

This section documents what is implemented now.

1. **Authentication**
   - API v1 endpoints require `auth:sanctum`.
   - Access depends on valid Sanctum authentication.

2. **Versioned endpoint structure**
   - API is grouped under `/api/v1`.
   - Route names use `api.v1.*`.

3. **Data exposure pattern**
   - Read-focused endpoints currently exposed (`index`, `show`, and client-assets relation).
   - Responses are transformed through API Resources (`ClientResource`, `AssetResource`).

4. **JSON error preference for API requests**
   - Exception rendering is configured to return JSON automatically for `api/*` paths.

> Note: OpenAPI annotations in API controllers currently describe bearer token auth (`bearerAuth`) while runtime enforcement is done by Laravel Sanctum middleware.

## Naming & Creation Conventions

When adding routes:

- Internal workspace view route URLs must remain under `/tech/...`.
- Put technician features in `routes/tech.php`.
- Put admin-only features in `routes/techAdmin.php`.
- Keep client portal features under `routes/client.php`.
- Keep API endpoints under `routes/api.php`, grouped by version.
- Prefer named routes consistently.

## Checklist for New Routes (Recommended Standard)

Use this checklist every time a new route is introduced:

1. Place the route in the correct route file.
2. Ensure URL prefix rule is respected (`/tech/...` for internal views).
3. Apply correct middleware:
   - technician route: `auth + tech`
   - tech admin route: `auth + tech + admin`
   - client portal route: current state vs target state decision
   - API route: `auth:sanctum` where required
4. Add/verify route name convention (`tech.*`, `client.*`, `api.v1.*`).
5. Add/update breadcrumb mapping in `config/breadcrumbs.php` if it maps to a view.
6. Verify registration with `php artisan route:list` filters.

## Useful Commands

```bash
php artisan route:list
php artisan route:list --path=tech
php artisan route:list --path=client
php artisan route:list --path=api/v1
php artisan route:list --name=tech.
php artisan route:list --name=client.
php artisan route:list --name=api.v1.
```

---
Updated: 2026-05-03
