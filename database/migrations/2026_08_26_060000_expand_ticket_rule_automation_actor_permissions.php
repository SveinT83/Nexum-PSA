<?php

use App\Modules\UserManagement\Actions\EnsureSystemActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ACTOR_KEY = 'ticket_rule_automation';

    private const ACTOR_NAME = 'Nexum Ticket Rule Automation';

    private const ACTOR_EMAIL = 'ticket-rule-automation@system.nexum.invalid';

    private const ACTOR_PERMISSIONS = [
        'ticket.update',
        'ticket.assign',
        'ticket.note_internal',
        'signal.action.execute',
    ];

    public function up(): void
    {
        $permissionsTable = config('permission.table_names.permissions');
        if (! is_string($permissionsTable) || ! Schema::hasTable($permissionsTable)) {
            throw new RuntimeException('The permission schema must exist before Slice 3 Ticket Rule permissions are deployed.');
        }

        foreach (self::ACTOR_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

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
            throw new RuntimeException('The protected Ticket Rule automation actor Slice 3 authority could not be verified.');
        }
    }

    public function down(): void
    {
        // Forward-only: completed execution evidence may depend on these exact
        // actor grants, so rollback uses a reviewed forward migration.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
