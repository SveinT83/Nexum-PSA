<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'ticket.rule_retry',
        'ticket.rule_full_rerun',
    ];

    private const ROLES = ['Admin', 'Superuser'];

    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $permissionsTable = $tableNames['permissions'] ?? null;
        $rolesTable = $tableNames['roles'] ?? null;
        $rolePermissionsTable = $tableNames['role_has_permissions'] ?? null;
        $permissionPivot = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $rolePivot = $columnNames['role_pivot_key'] ?? 'role_id';

        if (! is_string($permissionsTable)
            || ! is_string($rolesTable)
            || ! is_string($rolePermissionsTable)
            || ! Schema::hasTable($permissionsTable)
            || ! Schema::hasTable($rolesTable)
            || ! Schema::hasTable($rolePermissionsTable)) {
            throw new RuntimeException('The permission schema must exist before Ticket Rule admin execution permissions are deployed.');
        }

        DB::transaction(function () use (
            $permissionsTable,
            $rolesTable,
            $rolePermissionsTable,
            $permissionPivot,
            $rolePivot,
        ): void {
            $now = now();

            foreach (self::PERMISSIONS as $permission) {
                DB::table($permissionsTable)->insertOrIgnore([
                    'name' => $permission,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds = DB::table($permissionsTable)
                ->where('guard_name', 'web')
                ->whereIn('name', self::PERMISSIONS)
                ->pluck('id', 'name');

            if ($permissionIds->count() !== count(self::PERMISSIONS)) {
                throw new RuntimeException('Ticket Rule admin execution permissions could not be deployed.');
            }

            $roleIds = DB::table($rolesTable)
                ->where('guard_name', 'web')
                ->whereIn('name', self::ROLES)
                ->pluck('id', 'name');

            foreach (self::ROLES as $roleName) {
                $roleId = $roleIds->get($roleName);

                // Fresh installations may run migrations before roles are seeded.
                if ($roleId === null) {
                    continue;
                }

                foreach (self::PERMISSIONS as $permission) {
                    $permissionId = $permissionIds->get($permission);
                    DB::table($rolePermissionsTable)->insertOrIgnore([
                        $permissionPivot => $permissionId,
                        $rolePivot => $roleId,
                    ]);

                    $granted = DB::table($rolePermissionsTable)
                        ->where($permissionPivot, $permissionId)
                        ->where($rolePivot, $roleId)
                        ->exists();
                    if (! $granted) {
                        throw new RuntimeException("The {$permission} grant for {$roleName} could not be verified.");
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only permission deployment: preserve reviewed grants.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
