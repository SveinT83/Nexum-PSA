# Permissions Management

This module handles the administration of system permissions. It allows administrators to create, edit, and delete permissions that can then be assigned to roles.

## Controller
`App\Http\Controllers\Tech\Admin\UserManagement\PermissionManagementController`

## Views
- `resources/views/tech/admin/user_management/permissions/index.blade.php`: List of all permissions.
- `resources/views/tech/admin/user_management/permissions/form.blade.php`: Create/Edit form for permissions.

## Routes
All routes are prefixed with `admin.user_management.permissions`.

- `index`: GET `/admin/user_management/permissions`
- `create`: GET `/admin/user_management/permissions/create`
- `store`: POST `/admin/user_management/permissions/store`
- `edit`: GET `/admin/user_management/permissions/edit/{id}`
- `update`: POST `/admin/user_management/permissions/update/{id}`
- `destroy`: DELETE `/admin/user_management/permissions/destroy/{id}`

## Breadcrumbs
Breadcrumbs are configured in `config/breadcrumbs.php` and use the route names.
