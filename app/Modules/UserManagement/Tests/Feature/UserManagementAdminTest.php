<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketTechnicianProfile;
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
            ->assertSee('Listed User')
            ->assertSee(route('tech.admin.user_management.show', $user), false);
    }

    #[Test]
    public function admin_can_open_user_employee_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Employee Profile User',
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole('Tech');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.user_management.show', $user))
            ->assertOk()
            ->assertViewIs('usermanagement::Admin.show')
            ->assertSee('Employee Profile')
            ->assertSee('Employee Profile User')
            ->assertSee('Roles')
            ->assertSee('Tech');
    }

    #[Test]
    public function admin_can_update_user_contact_details_from_employee_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Employee Name',
            'email' => 'old.employee@example.test',
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.user_management.profile.update', $user), [
                'name' => 'New Employee Name',
                'email' => 'new.employee@example.test',
                'phone_work' => '+47 73500000',
                'phone_private' => '+47 40000000',
            ])
            ->assertRedirect(route('tech.admin.user_management.show', $user))
            ->assertSessionHas('success', 'User profile updated successfully.');

        $user->refresh();

        $this->assertSame('New Employee Name', $user->name);
        $this->assertSame('new.employee@example.test', $user->email);
        $this->assertSame('+47 73500000', $user->phone_work);
        $this->assertSame('+47 40000000', $user->phone_private);
    }

    #[Test]
    public function employee_profile_shows_ticket_technician_skills_when_profile_exists(): void
    {
        $category = Category::create([
            'name' => 'Network',
            'slug' => 'network',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $tag = Tag::create([
            'name' => 'Fiber',
            'slug' => 'fiber',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'name' => 'Skilled Technician',
            'status' => User::STATUS_ACTIVE,
        ]);
        $profile = TicketTechnicianProfile::create([
            'user_id' => $user->id,
            'is_assignable' => true,
            'max_open_tickets' => 7,
            'timezone' => 'Europe/Oslo',
            'working_hours' => [],
        ]);
        $profile->categories()->attach($category->id);
        $profile->tags()->attach($tag->id);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.user_management.show', $user))
            ->assertOk()
            ->assertSee('Ticket Technician Profile')
            ->assertSee('Network')
            ->assertSee('Fiber')
            ->assertSee(route('tech.admin.settings.tickets.technicians.edit', $profile), false);
    }

    #[Test]
    public function admin_can_update_roles_from_user_employee_profile(): void
    {
        $salesRole = Role::create(['name' => 'Sales']);
        $techRole = Role::where('name', 'Tech')->firstOrFail();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($techRole);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.user_management.roles.update-user', $user), [
                'roles' => [$salesRole->id],
            ])
            ->assertRedirect(route('tech.admin.user_management.show', $user))
            ->assertSessionHas('success', 'User roles updated successfully.');

        $user->refresh();

        $this->assertTrue($user->hasRole('Sales'));
        $this->assertFalse($user->hasRole('Tech'));
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
    public function active_internal_user_opening_root_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('tech.dashboard'));
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

        $defaultAdmin = User::where('email', 'admin@tdpsa.com')->firstOrFail();

        $this->assertSame(User::STATUS_ACTIVE, $defaultAdmin->status);
        $this->assertNotNull($defaultAdmin->email_verified_at);

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
