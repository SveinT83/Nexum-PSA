# Routing & Middleware Overview

This document describes how the routing structure and middleware work in the project.

## File Structure

Standard Laravel + extra modular route files:

- `routes/web.php` – public and authentication related routes (login, logout, landing page)
- `routes/tech.php` – technical interface (prefix `tech`, name prefix `tech.`)
- `routes/client.php` – client portal (prefix `client`, name prefix `client.`)
- `routes/api.php` – API routes (Sanctum / future endpoints)
- `routes/console.php` – artisan command closures

Registration of these happens in `bootstrap/app.php`:

```php
return Application::configure(...)
	->withRouting(
		web: __DIR__.'/../routes/web.php',
		commands: __DIR__.'/../routes/console.php',
		health: '/up',
		then: function () {
			Route::middleware('web')
				->prefix('tech')
				->as('tech.')
				->group(base_path('routes/tech.php'));

			Route::middleware('web')
				->prefix('client')
				->as('client.')
				->group(base_path('routes/client.php'));
		},
	)
	->withMiddleware(...);
```

## Prefix & Name

| File           | URL prefix | Name prefix | Example URL             | Example name          |
|----------------|------------|-------------|-------------------------|-----------------------|
| web.php        | (none)     | (none)      | `/login`                | `login`               |
| tech.php       | `tech`     | `tech.`     | `/tech/dashboard`       | `tech.dashboard`      |
| client.php     | `client`   | `client.`   | `/client/dashboard`     | `client.dashboard`    |

Use `route('tech.dashboard')` to generate the URL to the technical dashboard.

## Authentication & Roles

Authentication is handled by Laravel Fortify + the standard `Auth::attempt()` call inside the login route in `routes/web.php`.

Roles are provided by Spatie Permission. We assume at minimum these roles:

- `Superuser`
- `Tech`
- (Future) `ClientUser`

## Middleware

Custom middleware for technical access: `App\Http\Middleware\TechAccess`.

Source (`app/Http/Middleware/TechAccess.php`):
```php
class TechAccess {
	public function handle($request, Closure $next) {
		$user = $request->user();
		if (!$user) {
			return redirect()->route('login');
		}
		if (! $user->hasRole('Superuser') && ! $user->hasRole('Tech')) {
			abort(403, 'No access');
		}
		return $next($request);
	}
}
```

Alias registered in `bootstrap/app.php`:
```php
$middleware->alias([
	'tech' => \App\Http\Middleware\TechAccess::class,
]);
```

Usage in `routes/tech.php`:
```php
Route::middleware(['auth','tech'])->group(function() {
	Route::get('/dashboard', fn() => view('Tech.dashboard'))->name('dashboard');
	// ... additional technical routes
});
```

## Login Flow

1. POST `/login` (in `web.php`) validates email/password.
2. `Auth::attempt()` + session regeneration.
3. Redirect to `route('tech.dashboard')` on success.
4. On failure: throws ValidationException with message.

Login route (simplified):
```php
Route::post('/login', function (Request $request) {
	$request->validate(['email'=>'required|email','password'=>'required']);
	if (Auth::attempt($request->only('email','password'), $request->boolean('remember'))) {
		$request->session()->regenerate();
		return redirect()->route('tech.dashboard');
	}
	throw ValidationException::withMessages(['email' => 'Incorrect email or password.']);
})->name('login');
```

Logout:
```php
Route::post('/logout', function (Request $request) {
	Auth::logout();
	$request->session()->invalidate();
	$request->session()->regenerateToken();
	return redirect()->route('welcome');
})->name('logout');
```

## Naming Conventions

- Use the `tech.` namespace for internal operations/support functionality.
- Use the `client.` namespace for client-facing pages.
- API endpoints in `routes/api.php` will typically use `Route::middleware('auth:sanctum')`.

## Route Inspection

Useful artisan commands:
```bash
php artisan route:list --path=tech
php artisan route:list --name=dashboard
php artisan route:list
```

## Error Handling / 403

403 occurs when the user lacks a required role in `TechAccess`. Customize by creating a view:
`resources/views/errors/403.blade.php` and handle via the `render()` method in your exception handler or rely on Laravel defaults.

## Future Work

- Separate middleware for clients (`ClientAccess`).
- Granular permissions (e.g. `view tickets`, `edit tickets`).
- API routes with versioning (`/api/v1/...`).
- Role-based rate limiting.

---
Updated: {{ date('Y-m-d') }}
