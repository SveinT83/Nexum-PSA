<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
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
}
