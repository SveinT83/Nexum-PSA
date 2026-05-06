# User, Roles & Permissions – tdPSA

## Purpose

This document defines how users, roles, and permissions are structured and should be used in tdPSA. It is intended as a quick reference for developers.

---

## Core Principles

* Roles define WHAT a user can do
* Scope defines WHERE they can do it
* Permissions are ALWAYS checked in code
* Roles are just bundles of permissions

---

## ❗ Important Rule

DO NOT hardcode roles like:

```php
User::where('role', 'tech')
```

This is WRONG.

Instead:

* Use permissions
* Use role relationships
* Use scopes

---

## Data Model

### Users

Table: `users`

Basic fields:

* id
* name
* email
* password

---

### Roles

Table: `roles`

Examples:

* superadmin
* technician
* ticket.admin
* sales.admin

---

### Permissions

Table: `permissions`

Examples:

* ticket.view
* ticket.create
* ticket.edit
* user.admin
* email.rules.manage

---

### Pivot Tables

* `model_has_roles`
* `role_has_permissions`

(Spatie Laravel Permission)

---

## Permission Naming Convention

Format:

```
<domain>.<action>
```

Examples:

* ticket.view
* ticket.create
* ticket.delete
* user.create
* user.admin
* email.rules.manage

---

## Role Examples

### Technician

* ticket.view
* ticket.create
* ticket.edit

---

### Ticket Admin

* ticket.*
* ticket.rules.manage
* ticket.workflow.manage

---

### Superadmin

* * (all permissions)

---

## Usage in Code

### ✅ Correct

Check permission:

```php
$user->can('ticket.view')
```

In Blade:

```blade
@can('ticket.create')
    <button>Create Ticket</button>
@endcan
```

---

### ❌ Wrong

```php
if ($user->role == 'admin')
```

This breaks scalability.

---

## Getting Users by Role

### Correct way

```php
use Spatie\Permission\Models\Role;

$users = User::role('technician')->get();
```

---

## Getting Users by Permission

```php
$users = User::permission('ticket.view')->get();
```

---

## Scope (IMPORTANT)

Users can belong to:

* System (internal)
* Client
* Site

Future structure:

* user_client_roles
* user_site_roles

---

## Best Practices

* NEVER hardcode roles in logic
* ALWAYS use permissions
* KEEP roles flexible
* LOG all changes (audit)

---

## Example Refactor

### ❌ Before

```php
$users = User::where('role', 'tech')->get();
```

### ✅ After

```php
$users = User::role('technician')->get();
```

OR (better):

```php
$users = User::permission('ticket.view')->get();
```

---

## Future Expansion

* Role per client
* Role per site
* API scopes
* Advanced RBAC UI

---

## Summary

* Roles = grouping
* Permissions = truth
* Scope = access boundary

If you follow this, the system will scale cleanly.
