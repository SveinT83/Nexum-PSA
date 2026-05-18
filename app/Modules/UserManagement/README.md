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
        Livewire/
            Roles/
    routes.php
```

## Routes

Routes live in `app/Modules/UserManagement/routes.php` and keep the existing route names:

- `tech.admin.user_management.index`
- `tech.admin.user_management.create`
- `tech.admin.user_management.store`
- `tech.admin.user_management.roles.*`
- `tech.admin.user_management.permissions.*`

The module routes are loaded inside the `/tech` group and add `admin` middleware locally.

## Responsibilities

- List application users with roles.
- Create users and assign an initial role.
- Store authenticated-user preferences such as timezone, default calendar view, and normal workday.
- List, create, update, and delete roles.
- List, create, update, and delete permissions.
- Assign permissions to roles through the module-local Livewire component.

## User Preferences

Authenticated users manage personal defaults from `/tech/profile/preferences`.

Current fields:

- Timezone.
- Default calendar view.
- Workday start.
- Workday end.

Calendar uses these preferences for display defaults and personal availability setup, but the
preference records themselves belong to User Management.

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
