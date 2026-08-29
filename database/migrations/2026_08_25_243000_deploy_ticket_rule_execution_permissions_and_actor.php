<?php

use App\Modules\UserManagement\Actions\EnsureSystemActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ADMIN_PERMISSIONS = [
        'ticket.rule_preview',
        'ticket.rule_execution_view',
    ];

    private const ACTOR_KEY = 'ticket_rule_automation';

    private const ACTOR_NAME = 'Nexum Ticket Rule Automation';

    private const ACTOR_EMAIL = 'ticket-rule-automation@system.nexum.invalid';

    private const ACTOR_PERMISSIONS = [
        'ticket.update',
        'signal.action.execute',
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
            throw new RuntimeException('The permission schema must exist before Ticket Rule execution permissions are deployed.');
        }

        DB::transaction(function () use (
            $permissionsTable,
            $rolesTable,
            $rolePermissionsTable,
            $permissionPivot,
            $rolePivot,
        ): void {
            $now = now();

            foreach (array_merge(self::ADMIN_PERMISSIONS, self::ACTOR_PERMISSIONS) as $permission) {
                DB::table($permissionsTable)->insertOrIgnore([
                    'name' => $permission,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds = DB::table($permissionsTable)
                ->where('guard_name', 'web')
                ->whereIn('name', self::ADMIN_PERMISSIONS)
                ->pluck('id', 'name');

            if ($permissionIds->count() !== count(self::ADMIN_PERMISSIONS)) {
                throw new RuntimeException('Ticket Rule execution permissions could not be deployed.');
            }

            $roleIds = DB::table($rolesTable)
                ->where('guard_name', 'web')
                ->whereIn('name', self::ROLES)
                ->pluck('id', 'name');

            foreach (self::ROLES as $roleName) {
                $roleId = $roleIds->get($roleName);

                // Fresh installations run migrations before default roles are seeded.
                if ($roleId === null) {
                    continue;
                }

                foreach ($permissionIds as $permissionId) {
                    DB::table($rolePermissionsTable)->insertOrIgnore([
                        $permissionPivot => $permissionId,
                        $rolePivot => $roleId,
                    ]);

                    if (! DB::table($rolePermissionsTable)
                        ->where($permissionPivot, $permissionId)
                        ->where($rolePivot, $roleId)
                        ->exists()) {
                        throw new RuntimeException("A Ticket Rule execution grant for {$roleName} could not be verified.");
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $actor = app(EnsureSystemActor::class)->handle(
            key: self::ACTOR_KEY,
            name: self::ACTOR_NAME,
            email: self::ACTOR_EMAIL,
            permissions: self::ACTOR_PERMISSIONS,
        );
        $actualPermissions = $actor->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $expectedPermissions = collect(self::ACTOR_PERMISSIONS)->sort()->values()->all();

        if (! $actor->isSystemActor()
            || $actor->isActive()
            || $actor->roles()->exists()
            || $actualPermissions !== $expectedPermissions) {
            throw new RuntimeException('The protected Ticket Rule automation actor could not be verified.');
        }
    }

    public function down(): void
    {
        // Forward-only deployment: preserve the protected actor and reviewed permission grants.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
