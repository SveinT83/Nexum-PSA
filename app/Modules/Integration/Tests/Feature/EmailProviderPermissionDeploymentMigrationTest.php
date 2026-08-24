<?php

namespace App\Modules\Integration\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmailProviderPermissionDeploymentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL_SECURITY_PERMISSIONS = [
        'email.mailbox_sync_manage',
        'email.canonical_cutover_manage',
        'email.break_glass_activate',
        'email.break_glass_audit',
        'email.raw_source_view',
        'integration.email_provider_manage',
        'integration.email_private_endpoint_manage',
        'system.telescope_view',
    ];

    private const ADMIN_PERMISSIONS = [
        'email.mailbox_sync_manage',
        'email.canonical_cutover_manage',
        'integration.email_provider_manage',
    ];

    #[Test]
    public function migration_creates_the_catalog_before_default_roles_are_seeded(): void
    {
        foreach (self::MAIL_SECURITY_PERMISSIONS as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    #[Test]
    public function migration_repairs_existing_roles_without_replacing_unrelated_grants(): void
    {
        $admin = Role::findOrCreate('Admin', 'web');
        $superuser = Role::findOrCreate('Superuser', 'web');
        $tech = Role::findOrCreate('Tech', 'web');

        $accountManage = Permission::findOrCreate('email.account_manage', 'web');
        $calendarManage = Permission::findOrCreate('calendar.manage_all', 'web');
        $salesManage = Permission::findOrCreate('sales.manage', 'web');

        $admin->givePermissionTo([$accountManage, $calendarManage, $salesManage]);
        $tech->givePermissionTo([$calendarManage, $salesManage]);

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::MAIL_SECURITY_PERMISSIONS)
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = $this->migration();
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = $admin->fresh();
        $superuser = $superuser->fresh();
        $tech = $tech->fresh();

        foreach (self::ADMIN_PERMISSIONS as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission));
        }
        foreach (array_diff(self::MAIL_SECURITY_PERMISSIONS, self::ADMIN_PERMISSIONS) as $permission) {
            $this->assertFalse($admin->hasPermissionTo($permission));
        }
        foreach (self::MAIL_SECURITY_PERMISSIONS as $permission) {
            $this->assertTrue($superuser->hasPermissionTo($permission));
            $this->assertDatabaseCountForPermission($permission, 1);
        }

        $this->assertTrue($admin->hasPermissionTo('email.account_manage'));
        $this->assertTrue($admin->hasPermissionTo('calendar.manage_all'));
        $this->assertTrue($admin->hasPermissionTo('sales.manage'));
        $this->assertTrue($tech->hasPermissionTo('calendar.manage_all'));
        $this->assertTrue($tech->hasPermissionTo('sales.manage'));

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->fresh()->hasPermissionTo('integration.email_provider_manage'));
        $this->assertTrue($tech->fresh()->hasPermissionTo('calendar.manage_all'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_21_100000_ensure_mail_security_permissions_are_deployed.php');
    }

    private function assertDatabaseCountForPermission(string $permission, int $expected): void
    {
        $this->assertSame(
            $expected,
            Permission::query()->where('name', $permission)->where('guard_name', 'web')->count(),
            "Unexpected catalog count for {$permission}.",
        );
    }
}
