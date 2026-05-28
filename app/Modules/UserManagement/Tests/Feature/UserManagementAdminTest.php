<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Tech']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function admin_can_list_users(): void
    {
        $user = User::factory()->create([
            'name' => 'Listed User',
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole('Tech');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.user_management.index'))
            ->assertOk()
            ->assertViewIs('usermanagement::Admin.index')
            ->assertSee('Listed User');
    }

    #[Test]
    public function admin_can_manually_activate_pending_user(): void
    {
        $user = User::factory()->create([
            'status' => User::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.user_management.status.update', $user), [
                'status' => User::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('tech.admin.user_management.index'));

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    #[Test]
    public function admin_can_list_roles_as_rows_with_counts(): void
    {
        Permission::findOrCreate('ticket.view');
        Permission::findOrCreate('user.manage_roles');
        $techRole = Role::where('name', 'Tech')->firstOrFail();
        $techRole->givePermissionTo('ticket.view');
        $this->admin->givePermissionTo('user.manage_roles');

        $techUser = User::factory()->create([
            'name' => 'Role Count User',
            'status' => User::STATUS_ACTIVE,
        ]);
        $techUser->assignRole('Tech');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.user_management.roles.index'))
            ->assertOk()
            ->assertSee('Role')
            ->assertSee('Permissions')
            ->assertSee('Users')
            ->assertSee('Tech')
            ->assertSee('1')
            ->assertSee(route('tech.admin.user_management.roles.edit', $techRole), false)
            ->assertDontSee('Actions')
            ->assertDontSee('Edit')
            ->assertDontSee('ticket.view');
    }

    #[Test]
    public function admin_route_is_blocked_without_required_permission(): void
    {
        Permission::findOrCreate('user.view');

        $role = Role::create(['name' => 'No User Access']);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('tech.admin.user_management.index'))
            ->assertForbidden();
    }

    #[Test]
    public function custom_role_can_open_admin_route_with_required_permission(): void
    {
        Permission::findOrCreate('user.view');

        $role = Role::create(['name' => 'Custom User Manager']);
        $role->givePermissionTo('user.view');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('tech.admin.user_management.index'))
            ->assertOk()
            ->assertViewIs('usermanagement::Admin.index');
    }

    #[Test]
    public function pending_user_with_role_is_logged_out_from_tech_routes(): void
    {
        $user = User::factory()->create([
            'status' => User::STATUS_PENDING,
        ]);
        $user->assignRole('Tech');

        $this->actingAs($user)
            ->get(route('tech.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function permission_seeders_create_catalog_and_sync_superuser(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\AdminUserSeeder::class);

        foreach (['ticket.view', 'sales.quote_manage', 'user.manage_permissions', 'system.queue_manage'] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission]);
        }

        $superuser = Role::where('name', 'Superuser')->firstOrFail();

        $this->assertSame(Permission::count(), $superuser->permissions()->count());

        foreach (['Admin', 'Tech', 'Sales', 'Economy', 'Storage', 'Viewer'] as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role]);
        }

        $this->assertTrue(Role::where('name', 'Tech')->firstOrFail()->hasPermissionTo('ticket.reply_customer'));
        $this->assertTrue(Role::where('name', 'Sales')->firstOrFail()->hasPermissionTo('sales.quote_manage'));
        $this->assertTrue(Role::where('name', 'Economy')->firstOrFail()->hasPermissionTo('economy.generate_orders'));
        $this->assertTrue(Role::where('name', 'Storage')->firstOrFail()->hasPermissionTo('storage.pick'));
        $this->assertFalse(Role::where('name', 'Viewer')->firstOrFail()->hasPermissionTo('ticket.update'));
    }
}
