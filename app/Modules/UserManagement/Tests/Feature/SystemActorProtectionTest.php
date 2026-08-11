<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Storage\Actions\SupplierOrderAutomationActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemActorProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function system_actor_is_hidden_and_cannot_be_managed_as_a_human_user(): void
    {
        $actor = app(SupplierOrderAutomationActor::class)->resolve();

        $this->actingAs($this->admin)
            ->get(route('tech.admin.user_management.index'))
            ->assertOk()
            ->assertDontSee($actor->name);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.user_management.show', $actor))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.user_management.status.update', $actor), [
                'status' => User::STATUS_ACTIVE,
            ])
            ->assertNotFound();

        $this->assertSame(User::STATUS_DISABLED, $actor->fresh()->status);
    }

    #[Test]
    public function system_actor_is_hidden_and_immutable_through_the_user_api(): void
    {
        $actor = app(SupplierOrderAutomationActor::class)->resolve();
        Sanctum::actingAs($this->admin, ['users.read', 'users.update']);

        $this->getJson('/api/v1/users?q=Nexum%20Supplier%20Order%20Automation')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/users/{$actor->id}")
            ->assertNotFound();

        $this->postJson("/api/v1/users/{$actor->id}/status", [
            'status' => User::STATUS_ACTIVE,
        ])->assertNotFound();

        $this->postJson("/api/v1/users/{$actor->id}/roles", [
            'role_ids' => [],
        ])->assertNotFound();

        $this->assertSame(User::STATUS_DISABLED, $actor->fresh()->status);
    }

    #[Test]
    public function system_actor_cannot_authenticate_even_if_credentials_and_status_are_tampered_with(): void
    {
        $actor = app(SupplierOrderAutomationActor::class)->resolve();
        $actor->forceFill([
            'status' => User::STATUS_ACTIVE,
            'password' => Hash::make('known-password'),
        ])->save();

        $this->post(route('login'), [
            'email' => $actor->email,
            'password' => 'known-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
