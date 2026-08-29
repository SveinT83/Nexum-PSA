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

class TicketRulePublishPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function canonical_fresh_seeds_grant_publish_only_to_the_approved_default_roles(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('ticket.rule_publish'));
        $this->assertTrue(Role::findByName('Superuser')->hasPermissionTo('ticket.rule_publish'));
        $this->assertFalse(Role::findByName('Tech')->hasPermissionTo('ticket.rule_publish'));
    }

    #[Test]
    public function additive_migration_is_idempotent_and_preserves_unrelated_grants(): void
    {
        $admin = Role::findOrCreate('Admin', 'web');
        $superuser = Role::findOrCreate('Superuser', 'web');
        $tech = Role::findOrCreate('Tech', 'web');
        $unrelated = Permission::findOrCreate('ticket.manage_rules', 'web');

        $admin->givePermissionTo($unrelated);
        $tech->givePermissionTo($unrelated);
        Permission::query()
            ->where('name', 'ticket.rule_publish')
            ->where('guard_name', 'web')
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = $this->migration();
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->fresh()->hasPermissionTo('ticket.rule_publish'));
        $this->assertTrue($superuser->fresh()->hasPermissionTo('ticket.rule_publish'));
        $this->assertFalse($tech->fresh()->hasPermissionTo('ticket.rule_publish'));
        $this->assertTrue($admin->fresh()->hasPermissionTo('ticket.manage_rules'));
        $this->assertTrue($tech->fresh()->hasPermissionTo('ticket.manage_rules'));
        $this->assertSame(
            1,
            Permission::query()
                ->where('name', 'ticket.rule_publish')
                ->where('guard_name', 'web')
                ->count(),
        );

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->fresh()->hasPermissionTo('ticket.rule_publish'));
        $this->assertTrue($tech->fresh()->hasPermissionTo('ticket.manage_rules'));
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_25_241000_deploy_ticket_rule_publish_permission.php',
        );
    }
}
