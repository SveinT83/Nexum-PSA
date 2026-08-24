<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions introduced by the approved Mail security foundation.
     *
     * Keep this historical migration deterministic. Later permissions belong
     * in their own forward migrations instead of expanding this list.
     */
    private const PERMISSIONS = [
        'email.mailbox_sync_manage',
        'email.canonical_cutover_manage',
        'email.break_glass_activate',
        'email.break_glass_audit',
        'email.raw_source_view',
        'integration.email_provider_manage',
        'integration.email_private_endpoint_manage',
        'system.telescope_view',
    ];

    /**
     * Add only the approved default grants. Do not synchronize complete roles:
     * other modules own additional grants that this migration must preserve.
     */
    private const ROLE_PERMISSIONS = [
        'Admin' => [
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
            'integration.email_provider_manage',
        ],
        'Superuser' => self::PERMISSIONS,
    ];

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
            throw new RuntimeException('The permission schema must exist before Mail security permissions are deployed.');
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
                throw new RuntimeException('The complete Mail security permission catalog could not be deployed.');
            }

            $roleIds = DB::table($rolesTable)
                ->where('guard_name', 'web')
                ->whereIn('name', array_keys(self::ROLE_PERMISSIONS))
                ->pluck('id', 'name');

            foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
                $roleId = $roleIds->get($roleName);

                // Fresh installations run migrations before the default role
                // seeder. Existing installations receive the additive grants.
                if ($roleId === null) {
                    continue;
                }

                foreach ($permissions as $permission) {
                    DB::table($rolePermissionsTable)->insertOrIgnore([
                        $permissionPivot => $permissionIds->get($permission),
                        $rolePivot => $roleId,
                    ]);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only security repair. A rollback cannot distinguish these
        // approved grants from later reviewed assignments, so it must not
        // revoke permissions or delete the shared catalog entries.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
