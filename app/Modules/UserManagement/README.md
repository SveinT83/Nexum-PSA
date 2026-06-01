# User Management Module

The User Management module owns the admin workflows for application users, roles, and permissions.

Important naming note:

- `user_management` is the established admin URL segment: `/tech/admin/user_management`.
- It is not the database table contract.
- The actual user table is resolved by `App\Models\Core\User`.
- In normal runtime the User model defaults to the `users` table.
- In tests, `phpunit.xml` currently sets `AUTH_USER_TABLE=user_management` because the test migrations create `user_management`.

## Structure

```text
app/Modules/UserManagement/
    Actions/
    Controllers/
        Admin/
    Livewire/
        Roles/
    Menus/
        SideBar/
    Queries/
    Tests/
        Feature/
    Views/
        Admin/
            Roles/
            Permissions/
        profile/
        Livewire/
            Roles/
    routes.php
```

## Routes

Routes live in `app/Modules/UserManagement/routes.php` and keep the existing route names:

- `tech.admin.user_management.index`
- `tech.admin.user_management.create`
- `tech.admin.user_management.store`
- `tech.admin.user_management.show`
- `tech.admin.user_management.roles.update-user`
- `tech.admin.user_management.roles.*`
- `tech.admin.user_management.permissions.*`
- `tech.profile.index`
- `tech.profile.preferences`
- `tech.profile.security`

The module routes are loaded inside the `/tech` group and add `admin` middleware locally.

## Responsibilities

- List application users with roles.
- Open an admin employee profile from the user list.
- Create users and assign an initial role.
- Update a user's role assignments from the employee profile.
- Store authenticated-user preferences such as timezone, default calendar view, and normal workday.
- List, create, update, and delete roles.
- List, create, update, and delete permissions.
- Assign permissions to roles through the module-local Livewire component.
- Own the authenticated-user profile shell and side menu.
- Keep account, preferences, security, notifications, and technician-facing settings in one
  coherent profile workspace.

## User Preferences

Authenticated users open the unified profile workspace from `/tech/profile`.

The main navigation user menu should link to this single profile entry instead of linking directly
to Preferences, Security, Notifications, or Ticket-owned assignment settings.

Existing profile routes remain available:

- `/tech/profile/preferences`
- `/tech/profile/security`
- `/tech/profile/notifications`
- `/tech/tickets/profile`

The profile side menu is owned by User Management and is reused by those existing pages while the
profile consolidation work is completed.

Authenticated users manage personal defaults from `/tech/profile/preferences`.

Current fields:

- Timezone.
- Default calendar view.
- Workday start.
- Workday end.

Calendar uses these preferences for display defaults and personal availability setup, but the
preference records themselves belong to User Management.

## Technician Profile Consolidation

User Management is the canonical owner for the real technician/user profile.

Canonical profile records are stored in `user_profiles`.

Current fields include:

- Avatar path.
- Work phone.
- Private phone.
- Timezone.
- Working hours.
- Availability notes.
- Profile notes.

The Ticket module owns only assignment-specific data, such as ticket assignability, ticket capacity,
ticket category matching, ticket tag matching, and ticket assignment notes.

Do not add new general technician profile features to Ticket. Add them to User Management unless
the feature is strictly ticket-assignment-specific.

Production upgrades should run:

```bash
php artisan migrate
php artisan user-profiles:backfill
```

The backfill command is idempotent. It creates missing `user_profiles` rows and copies existing
phone fields plus timezone, work hours, and notes from legacy `ticket_technician_profiles` when
that table is still present.

## Livewire

The role permission editor lives in:

```text
app/Modules/UserManagement/Livewire/Roles/RolePermissions.php
app/Modules/UserManagement/Views/Livewire/Roles/role-permissions.blade.php
```

`AppServiceProvider` registers the existing alias:

```php
tech.admin.user_management.roles.role-permissions
```

This preserves existing Blade usage.

## Developer Notes

- Keep routes in `app/Modules/UserManagement/routes.php`.
- Keep controllers in `app/Modules/UserManagement/Controllers`.
- Keep views in `app/Modules/UserManagement/Views`.
- Do not reintroduce `app/Http/Controllers/Tech/Admin/UserManagement`.
- Do not reintroduce `resources/views/tech/admin/user_management`.
- Do not hard-code `user_management` as a table name for users.
