<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailBroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('email_live.enabled', true);
        config()->set('email_live.runtime_approved', true);
        config()->set('email_live.allowed_origins', ['https://nexum.example.test']);
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);
        Broadcast::purge('reverb');
        require app_path('Modules/Email/channels.php');
    }

    public function test_module_auth_route_is_the_only_broadcast_auth_surface(): void
    {
        $route = Route::getRoutes()->getByName('tech.mail.broadcast.auth');

        $this->assertNotNull($route);
        $this->assertSame('tech/mail/broadcasting/auth', $route->uri());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tech', $route->gatherMiddleware());
        $this->assertContains('2fa.required', $route->gatherMiddleware());
        $this->assertContains('tech.permission', $route->gatherMiddleware());
        $this->assertContains('throttle:email-mail-broadcast-auth', $route->gatherMiddleware());

        $this->assertFalse(collect(Route::getRoutes())->contains(
            fn ($candidate): bool => $candidate->uri() === 'broadcasting/auth',
        ));
    }

    public function test_active_non_system_user_may_authenticate_only_their_own_private_channel(): void
    {
        $user = $this->activeInternalUser();
        $other = $this->activeInternalUser();

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$user->id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$other->id}",
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-email.user.0'.$user->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('email_live_projection_streams', 0);
    }

    public function test_system_actor_is_denied_its_own_private_channel(): void
    {
        $user = $this->activeInternalUser(['is_system_actor' => true]);

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$user->id}",
            ])
            ->assertForbidden();
    }

    public function test_guest_and_inactive_user_cannot_authenticate_a_private_channel(): void
    {
        $this->postJson(route('tech.mail.broadcast.auth'), [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-email.user.1',
        ])->assertUnauthorized();

        $inactive = $this->activeInternalUser(['status' => User::STATUS_DISABLED]);

        $this->actingAs($inactive)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$inactive->id}",
            ])
            ->assertRedirect(route('login'));
    }

    public function test_disabled_runtime_refuses_channel_authentication(): void
    {
        config()->set('email_live.enabled', false);
        $user = $this->activeInternalUser();

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$user->id}",
            ])
            ->assertStatus(503);
    }

    public function test_unapproved_or_wildcard_origin_runtime_refuses_channel_authentication(): void
    {
        $user = $this->activeInternalUser();
        config()->set('email_live.runtime_approved', false);

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$user->id}",
            ])
            ->assertStatus(503);

        config()->set('email_live.runtime_approved', true);
        config()->set('email_live.allowed_origins', ['*']);

        $this->actingAs($user)
            ->postJson(route('tech.mail.broadcast.auth'), [
                'socket_id' => '1234.5678',
                'channel_name' => "private-email.user.{$user->id}",
            ])
            ->assertStatus(503);
    }

    private function activeInternalUser(array $attributes = []): User
    {
        $permission = Permission::findOrCreate('ticket.view', 'web');
        $user = User::factory()->create(array_merge([
            'status' => User::STATUS_ACTIVE,
        ], $attributes));
        $user->givePermissionTo($permission);

        return $user;
    }
}
