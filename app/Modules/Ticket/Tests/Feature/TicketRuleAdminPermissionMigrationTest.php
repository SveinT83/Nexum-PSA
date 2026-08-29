<?php

namespace App\Modules\Ticket\Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketRuleAdminPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function permission_catalog_contains_the_retry_and_full_rerun_boundaries(): void
    {
        $catalog = app(PermissionSeeder::class)->permissions();

        $this->assertContains('ticket.rule_retry', $catalog);
        $this->assertContains('ticket.rule_full_rerun', $catalog);
    }

    #[Test]
    public function migrate_then_seed_bootstraps_only_approved_roles_without_reviving_removed_grants(): void
    {
        $restricted = ['ticket.rule_retry', 'ticket.rule_full_rerun'];

        Role::query()->delete();
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $restricted)
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Production order is all migrations first, then the permission and role seeders.
        $this->migration()->up();
        $this->assertSame(0, Role::query()->where('guard_name', 'web')->count());
        $this->seed(PermissionSeeder::class);
        $this->assertSame(0, Role::query()->where('guard_name', 'web')->count());
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::findByName('Admin', 'web');
        $superuser = Role::findByName('Superuser', 'web');
        $technician = Role::findByName('Tech', 'web');
        $viewer = Role::findByName('Viewer', 'web');

        foreach ($restricted as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission));
            $this->assertTrue($superuser->hasPermissionTo($permission));
            $this->assertFalse($technician->hasPermissionTo($permission));
            $this->assertFalse($viewer->hasPermissionTo($permission));
        }

        $admin->revokePermissionTo('ticket.rule_retry');
        $superuser->revokePermissionTo('ticket.rule_full_rerun');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($admin->fresh()->hasPermissionTo('ticket.rule_retry'));
        $this->assertTrue($admin->fresh()->hasPermissionTo('ticket.rule_full_rerun'));
        $this->assertTrue($superuser->fresh()->hasPermissionTo('ticket.rule_retry'));
        $this->assertFalse($superuser->fresh()->hasPermissionTo('ticket.rule_full_rerun'));

        $admin->delete();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $recreatedAdmin = Role::findByName('Admin', 'web');

        foreach ($restricted as $permission) {
            $this->assertFalse($recreatedAdmin->hasPermissionTo($permission));
        }
    }

    #[Test]
    public function broad_role_sync_neither_creates_nor_removes_migration_managed_grants(): void
    {
        $this->seed(PermissionSeeder::class);
        Role::findOrCreate('Viewer', 'web');
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::findByName('Admin', 'web');
        $superuser = Role::findByName('Superuser', 'web');
        $technician = Role::findByName('Tech', 'web');
        $viewer = Role::findByName('Viewer', 'web');
        $restricted = ['ticket.rule_retry', 'ticket.rule_full_rerun'];

        $this->assertTrue($admin->hasPermissionTo('ticket.rule_publish'));
        $this->assertTrue($admin->hasPermissionTo('ticket.rule_preview'));
        $this->assertTrue($admin->hasPermissionTo('ticket.rule_execution_view'));
        foreach ($restricted as $permission) {
            $this->assertFalse($admin->hasPermissionTo($permission));
            $this->assertFalse($superuser->hasPermissionTo($permission));
            $this->assertFalse($technician->hasPermissionTo($permission));
            $this->assertFalse($viewer->hasPermissionTo($permission));
        }

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($restricted as $permission) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($permission));
            $this->assertTrue($superuser->fresh()->hasPermissionTo($permission));
            $this->assertFalse($technician->fresh()->hasPermissionTo($permission));
            $this->assertFalse($viewer->fresh()->hasPermissionTo($permission));
        }

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($restricted as $permission) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($permission));
            $this->assertTrue($superuser->fresh()->hasPermissionTo($permission));
            $this->assertFalse($technician->fresh()->hasPermissionTo($permission));
            $this->assertFalse($viewer->fresh()->hasPermissionTo($permission));
        }
    }

    #[Test]
    public function additive_migration_is_idempotent_grants_only_approved_roles_and_preserves_other_grants(): void
    {
        $admin = Role::findOrCreate('Admin', 'web');
        $superuser = Role::findOrCreate('Superuser', 'web');
        $tech = Role::findOrCreate('Tech', 'web');
        $unrelated = Permission::findOrCreate('ticket.manage_rules', 'web');

        $admin->givePermissionTo($unrelated);
        $tech->givePermissionTo($unrelated);
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['ticket.rule_retry', 'ticket.rule_full_rerun'])
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = $this->migration();
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['ticket.rule_retry', 'ticket.rule_full_rerun'] as $permission) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($permission));
            $this->assertTrue($superuser->fresh()->hasPermissionTo($permission));
            $this->assertFalse($tech->fresh()->hasPermissionTo($permission));
            $this->assertSame(
                1,
                Permission::query()
                    ->where('guard_name', 'web')
                    ->where('name', $permission)
                    ->count(),
            );
        }
        $this->assertTrue($admin->fresh()->hasPermissionTo('ticket.manage_rules'));
        $this->assertTrue($tech->fresh()->hasPermissionTo('ticket.manage_rules'));

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->fresh()->hasPermissionTo('ticket.rule_retry'));
        $this->assertTrue($superuser->fresh()->hasPermissionTo('ticket.rule_full_rerun'));
        $this->assertTrue($tech->fresh()->hasPermissionTo('ticket.manage_rules'));
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_26_100000_deploy_ticket_rule_admin_execution_permissions.php',
        );
    }
}
