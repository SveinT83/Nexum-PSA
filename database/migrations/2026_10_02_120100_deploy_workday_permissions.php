<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Explicit internal-role grants; no wildcard, portal or service identity bootstrap. */
    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $own = ['workday.view_own', 'workday.manage_own', 'workday.confirm_own'];
        foreach ([...$own, 'workday.manage_settings'] as $permission) {
            DB::table($tables['permissions'])->insertOrIgnore(['name' => $permission, 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::connection()->pretending()) {
            return;
        }
        DB::transaction(function () use ($tables, $columns, $own) {
            $roles = DB::table($tables['roles'])->where('guard_name', 'web')
                ->whereIn('name', ['Tech', 'Sales', 'Economy', 'Storage', 'Viewer', 'Admin', 'Superuser'])->get();
            $permissions = DB::table($tables['permissions'])->where('guard_name', 'web')
                ->whereIn('name', [...$own, 'workday.manage_settings'])->pluck('id', 'name');
            foreach ($roles as $role) {
                $grants = in_array($role->name, ['Admin', 'Superuser'], true) ? [...$own, 'workday.manage_settings'] : $own;
                foreach ($grants as $name) {
                    DB::table($tables['role_has_permissions'])->insertOrIgnore([
                        $columns['permission_pivot_key'] ?? 'permission_id' => $permissions[$name],
                        $columns['role_pivot_key'] ?? 'role_id' => $role->id,
                    ]);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only: preserve explicit grants and revocations during code rollbacks.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
