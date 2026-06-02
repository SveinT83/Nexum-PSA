<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\UserManagement\Jobs\SendUserInviteEmail;
use App\Modules\UserManagement\Models\InviteToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Sales']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function authenticated_api_user_can_manage_users(): void
    {
        Notification::fake();
        Queue::fake();

        $techRole = Role::where('name', 'Tech')->firstOrFail();
        $salesRole = Role::where('name', 'Sales')->firstOrFail();

        Sanctum::actingAs($this->admin, ['users.read', 'users.create', 'users.update']);

        $this->getJson('/api/v1/users/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Tech'])
            ->assertJsonFragment(['name' => 'Sales']);

        $createResponse = $this->postJson('/api/v1/users', [
            'name' => 'API Technician',
            'email' => 'api-technician@example.test',
            'status' => User::STATUS_PENDING,
            'role_ids' => [$techRole->id, $salesRole->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API Technician')
            ->assertJsonPath('data.status', User::STATUS_PENDING);

        $userId = $createResponse->json('data.id');
        $user = User::query()->findOrFail($userId);

        $this->assertTrue($user->hasRole('Tech'));
        $this->assertTrue($user->hasRole('Sales'));
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
        $this->assertSame(1, InviteToken::query()->where('user_id', $user->id)->count());
        Queue::assertPushed(SendUserInviteEmail::class);

        $this->getJson('/api/v1/users?q=API%20Technician')
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.roles.0', 'Sales')
            ->assertJsonPath('data.0.roles.1', 'Tech');

        $this->patchJson("/api/v1/users/{$user->id}", [
            'name' => 'Updated API Technician',
            'email' => 'updated-api-technician@example.test',
            'phone_work' => '+47 73503030',
            'phone_private' => '+47 40003030',
            'timezone' => 'Europe/Oslo',
            'working_hours' => [
                'monday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
            ],
            'availability_notes' => 'API updated availability.',
            'profile_notes' => 'API updated profile.',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated API Technician')
            ->assertJsonPath('data.profile.timezone', 'Europe/Oslo')
            ->assertJsonPath('data.profile.working_hours.monday.start', '09:00');

        $this->postJson("/api/v1/users/{$user->id}/status", [
            'status' => User::STATUS_ACTIVE,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_ACTIVE);

        $this->postJson("/api/v1/users/{$user->id}/roles", [
            'role_ids' => [$salesRole->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'Sales');

        $user->refresh();
        $this->assertTrue($user->hasRole('Sales'));
        $this->assertFalse($user->hasRole('Tech'));
    }

    #[Test]
    public function user_api_can_resend_invite_only_to_pending_users(): void
    {
        Notification::fake();
        Queue::fake();

        $pending = User::factory()->create(['status' => User::STATUS_PENDING]);
        $active = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Sanctum::actingAs($this->admin, ['users.update']);

        $this->postJson("/api/v1/users/{$pending->id}/invite")
            ->assertOk()
            ->assertJsonPath('data.message', "Invitation sent to {$pending->email}.");

        $this->assertSame(1, InviteToken::query()->where('user_id', $pending->id)->count());
        Queue::assertPushed(SendUserInviteEmail::class);

        $this->postJson("/api/v1/users/{$active->id}/invite")
            ->assertStatus(422);
    }

    #[Test]
    public function users_read_api_token_cannot_mutate_users(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_PENDING]);

        Sanctum::actingAs($this->admin, ['users.read']);

        $this->postJson("/api/v1/users/{$user->id}/status", [
            'status' => User::STATUS_ACTIVE,
        ])
            ->assertForbidden();

        $this->assertSame(User::STATUS_PENDING, $user->refresh()->status);
    }
}
