# tdPSA – Module & Domain Architecture Standard (STRICT)

## ⚠️ STRICT RULES (MANDATORY)

These rules override ALL Laravel defaults.

### ROUTES

- DO NOT create any route files in Laravel's default `routes/` directory.
- DO NOT create files like:
    - `routes/client.php`
    - `routes/clients.php`
    - `routes/ticket.php`
    - `routes/api-client.php`
- DO NOT register module routes in `bootstrap/app.php`.
- DO NOT add route loading logic in `bootstrap/app.php`.
- ALL domain/module routes MUST live inside the module:
    - `app/Modules/{Domain}/routes.php`
- The only allowed Laravel route files are the existing application entry files:
    - `routes/web.php`
    - `routes/api.php`
- These files may ONLY load module route files. They must NOT contain domain routes.

### CONTROLLERS

* MUST be located in: `app/Modules/{Domain}/Controllers/`
* MUST NOT be placed in `app/Http/Controllers`

### VIEWS

* MUST be located in: `app/Modules/{Domain}/Views/`
* MUST NOT be placed in `resources/views`

### MODULE STRUCTURE

* One domain = one module
* Domain names MUST be singular (Client, Ticket, Email)

### FORBIDDEN

* Do NOT create workaround routes in Laravel default folders
* Do NOT duplicate logic outside modules
* Do NOT bypass module structure

### FAILURE RULE

If code does not follow this structure, it is INVALID and must be rewritten.

---

## 1. CORE CONCEPT

### Domain Model

A domain is a business area of the system.

Examples:

* Client
* Ticket
* Email
* User

### Module

A module is the code representation of a domain.

Rule:

> One domain = one module

---

## 2. ROOT STRUCTURE

All modules MUST be placed in:

```
app/Modules/
```

Examples:

```
app/Modules/Client/
app/Modules/Ticket/
app/Modules/Email/
app/Modules/User/
```

---

## 3. MODULE STRUCTURE (MANDATORY)

Each module MUST follow this structure:

```
{Domain}/
    Controllers/
        Tech/
        Admin/
        Client/

    Views/
        Tech/
        Admin/
        Client/

    Actions/
    Queries/

    Livewire/    (optional)
    Menus/       (optional)
        SideBar/
    Tests/       (mandatory)
        Feature/
        Unit/

    Workflows/   (optional)
    Pipelines/   (optional)

    Events/
    Listeners/

    DTOs/

    routes.php
```

Rules:

* Controllers and Views are split by UI (Tech/Admin/Client)
* Business logic is shared across all UIs
* No duplication allowed between UI layers

### 3.1 LIVEWIRE STRUCTURE
* Livewire components SHOULD be placed in `app/Modules/{Domain}/Livewire/`.
* Components MUST follow the same UI separation if applicable (Tech/Admin/Client).
* Views for Livewire components MUST be placed in `app/Modules/{Domain}/Views/Livewire/`.

### 3.2 TESTING STRUCTURE
* Every module MUST have a `Tests/` directory.
* Feature tests MUST cover the primary UI interactions.
* Unit tests MUST cover business logic in Actions or Queries.
* Run tests with: `php artisan test --testsuite=Modules` (if configured).

### 3.3 MENUS & UI LOGIC
* Sidebar menus and other domain-specific UI logic MUST be placed in `app/Modules/{Domain}/Menus/`.
* Sidebar specific menus belong in `app/Modules/{Domain}/Menus/SideBar/`.
* This ensures that each domain's navigation logic is encapsulated within its respective module.

---

## 4. ARCHITECTURE RULES

### Separation of Concerns

* Controllers = UI entry points only
* Actions = business operations
* Queries = data retrieval
* Workflows = state logic
* Pipelines = processing flows

### HARD RULES

* NO business logic in Controllers
* NO business logic in Views
* NO duplication per role (Tech/Admin/Client)
* ONE source of truth per module

---

## 5. LARAVEL INTEGRATION

Laravel does NOT support this structure natively.
Manual configuration is REQUIRED.

---

## 6. ROUTE LOADING (MANDATORY)

Each module MUST contain:

```
app/Modules/{Domain}/routes.php
```

Routes MUST be loaded explicitly:

```php
require app_path('Modules/Client/routes.php');
// require app_path('Modules/Ticket/routes.php');
```

Dynamic loading (glob) is NOT recommended for AI compatibility.

---

## 7. CONTROLLERS

Controllers are autoloaded via PSR-4.

Example:

```
namespace App\Modules\Client\Controllers\Tech;
```

No additional configuration required.

---

## 8. VIEWS (MANDATORY REGISTRATION)

Views MUST be registered manually.

In `AppServiceProvider`:

```php
use Illuminate\Support\Facades\View;

public function boot()
{
    View::addLocation(app_path('Modules/Client/Views'));
}
```

Usage:

```php
return view('Tech.index');
```

---

## 9. ROUTING PRINCIPLE

Each module owns its own routes.

Examples:

```
/tech/clients
/client/clients
/admin/clients
```

Rules:

* Same module
* Different controllers per UI
* Shared logic

---

## 10. WHAT IS NOT USED

The following Laravel defaults are NOT used:

* app/Http/Controllers (global usage)
* resources/views (domain views)
* app/Service/SideBarMenus (legacy location for menus)
* Service/Helper patterns for business logic

---

## 11. SUMMARY

* Modules represent domains
* Each module is isolated
* Laravel is adapted to support modules
* Business logic is centralized
* UI is separated

This structure is REQUIRED for all development in tdPSA.
