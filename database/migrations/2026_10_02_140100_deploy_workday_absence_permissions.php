<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $names = ['workday.absence_view_own', 'workday.absence_manage_own'];
        foreach ($names as $name) {
            DB::table($tables['permissions'])->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::connection()->pretending()) {
            return;
        }
        DB::transaction(function () use ($tables, $columns, $names) {
            $roles = DB::table($tables['roles'])->where('guard_name', 'web')
                ->whereIn('name', ['Tech', 'Sales', 'Economy', 'Storage', 'Viewer', 'Admin', 'Superuser'])->pluck('id');
            $permissions = DB::table($tables['permissions'])->where('guard_name', 'web')->whereIn('name', $names)->pluck('id');
            foreach ($roles as $role) {
                foreach ($permissions as $permission) {
                    DB::table($tables['role_has_permissions'])->insertOrIgnore([
                        $columns['role_pivot_key'] ?? 'role_id' => $role, $columns['permission_pivot_key'] ?? 'permission_id' => $permission]);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve grants/revocations during code rollback, as with the manual Workday permissions.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
