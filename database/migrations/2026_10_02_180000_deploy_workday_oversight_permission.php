<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** One explicit default oversight grant; tokens and other roles are not upgraded. */
    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        DB::table($tables['permissions'])->insertOrIgnore(['name' => 'workday.view_all', 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now()]);
        if (DB::connection()->pretending()) {
            return;
        }
        $permission = DB::table($tables['permissions'])->where('name', 'workday.view_all')->where('guard_name', 'web')->value('id');
        $role = DB::table($tables['roles'])->where('name', 'Superuser')->where('guard_name', 'web')->value('id');
        if ($role) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore([
                $columns['permission_pivot_key'] ?? 'permission_id' => $permission,
                $columns['role_pivot_key'] ?? 'role_id' => $role,
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only; disabling the feature preserves subsequent explicit role decisions.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
