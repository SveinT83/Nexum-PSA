<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Connection setup only. No existing API token or synchronization activation is expanded. */
    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        DB::table($tables['permissions'])->insertOrIgnore([
            'name' => 'integration.tripletex_manage', 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (DB::connection()->pretending()) {
            return;
        }
        $permission = DB::table($tables['permissions'])->where('name', 'integration.tripletex_manage')
            ->where('guard_name', 'web')->value('id');
        foreach (DB::table($tables['roles'])->whereIn('name', ['Admin', 'Superuser'])->where('guard_name', 'web')->pluck('id') as $role) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore([
                $columns['permission_pivot_key'] ?? 'permission_id' => $permission,
                $columns['role_pivot_key'] ?? 'role_id' => $role,
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve any later explicit assignments. Operational rollback disables connector writes.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
