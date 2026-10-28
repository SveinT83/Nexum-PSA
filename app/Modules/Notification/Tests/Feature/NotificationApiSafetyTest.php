<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Actions\OpenNotification;
use App\Modules\Notification\Jobs\SendWebPushDeviceTest;
use App\Modules\Notification\Models\NotificationApiCommand;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Support\NotificationPrivacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Failures and ambiguous external claims must not disclose transport input or send twice. */
class NotificationApiSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo('notification.manage_channels');
        $this->token = $this->actor->createToken('Notification safety', ['*'])->plainTextToken;
        $this->bearer();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function bearer(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function talk(): NotificationChannel
    {
        $connection = NextcloudConnection::create(['name' => 'Internal cloud', 'scope' => 'global',
            'mode' => 'sync', 'is_default' => true, 'is_active' => true, 'base_url' => 'https://cloud.example.test',
            'service_username' => 'fixture', 'service_password' => 'synthetic-not-a-credential']);

        $registry = app(\App\Modules\Nextcloud\Services\TalkDestinationRegistry::class);
        $registry->saveSettings($connection, ['enabled' => true]);
        $destination = \App\Modules\Nextcloud\Models\NextcloudTalkDestination::create(['connection_id' => $connection->id,
            'provider_binding' => $registry->binding($connection->fresh()), 'conversation_token' => 'safe123', 'name' => 'Approved',
            'is_enabled' => true, 'is_available' => true, 'health_status' => 'available']);
        return NotificationChannel::updateOrCreate(['name' => 'nextcloud_talk'], ['label' => 'Nextcloud Talk',
            'driver' => 'nextcloud_talk', 'is_enabled' => true,
            'config' => ['nextcloud_connection_id' => $connection->id, 'talk_destination_id' => $destination->id,
                'talk_conversation_token' => 'safe123', 'delivery_mode' => 'conversation']]);
    }

    #[Test]
    public function provider_acceptance_is_not_delivery_and_unknown_outcome_is_never_reexecuted(): void
    {
        config(['notification-api.external_tests_enabled' => true]);
        $channel = $this->talk();
        Http::fake(function ($request) {
            $data = str_contains($request->url(), '/api/v4/room/')
                ? ['token' => 'safe123', 'readOnly' => 0, 'permissions' => 128]
                : ($request->method() === 'POST' ? ['id' => 91, 'token' => 'safe123'] : []);
            return Http::response(['ocs' => ['meta' => ['statuscode' => 100], 'data' => $data]]);
        });
        $etag = \App\Modules\Notification\Actions\ExecuteNotificationApiCommand::etag($channel->fresh());
        $success = $this->postJson('/api/v1/notifications/channels/'.$channel->id.'/test', [], $this->key() + ['If-Match' => $etag])
            ->assertOk()->assertJsonPath('data.state', 'provider_accepted')->assertJsonPath('data.delivery_confirmed', false);
        $this->assertStringNotContainsString('SECRET', $success->getContent());
        // A successful re-discovery is explicit; uncertain cached destinations cannot be retried blindly.
        $destination = \App\Modules\Nextcloud\Models\NextcloudTalkDestination::firstOrFail();
        $destination->update(['health_status' => 'available']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['ocs' => ['meta' => ['statuscode' => 100], 'data' => ['token' => 'safe123', 'readOnly' => 0, 'permissions' => 128]]]);
            }
            throw new ConnectionException('SECRET-exception');
        });
        $key = $this->key() + ['If-Match' => \App\Modules\Notification\Actions\ExecuteNotificationApiCommand::etag($channel->fresh())];
        $first = $this->postJson('/api/v1/notifications/channels/'.$channel->id.'/test', [], $key)
            ->assertAccepted()->assertJsonPath('data.state', 'unknown');
        $this->assertStringNotContainsString('SECRET', $first->getContent());
        $this->assertSame('unknown', NotificationApiCommand::findOrFail($first->json('operation_id'))->state);
        $this->bearer();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('Should never be sent', 200)]);
        $this->postJson('/api/v1/notifications/channels/'.$channel->id.'/test', [], $key)
            ->assertAccepted()->assertJsonPath('replayed', true);
        Http::assertNothingSent();
        $this->assertStringNotContainsString('SECRET', json_encode(DB::table('notification_api_commands')->get()));
    }

    #[Test]
    public function default_off_api_test_gate_does_not_activate_a_provider_or_queue(): void
    {
        config(['notification-api.external_tests_enabled' => false]);
        $channel = $this->talk();
        $this->postJson('/api/v1/notifications/channels/'.$channel->id.'/test', [], $this->key())->assertStatus(503);
        $this->postJson('/api/v1/notifications/web-push/test', ['endpoint' => 'https://push.example.test/SECRET'], $this->key())->assertStatus(503);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function self_test_throttle_and_durable_claim_limit_distinct_deliveries(): void
    {
        config(['notification-api.external_tests_enabled' => true, 'webpush.enabled' => true,
            'webpush.vapid.public_key' => 'test-public', 'webpush.vapid.private_key' => 'test-private',
            'webpush.vapid.subject' => 'mailto:test@example.test']);
        $endpoint = 'https://push.example.test/device';
        $this->postJson('/api/v1/notifications/web-push/devices', ['endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('A', 40), 'auth' => str_repeat('B', 24)]])->assertCreated();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/notifications/web-push/test', ['endpoint' => $endpoint], $this->key())->assertAccepted();
        }
        $this->postJson('/api/v1/notifications/web-push/test', ['endpoint' => $endpoint], $this->key())->assertStatus(429);
        Queue::assertPushed(SendWebPushDeviceTest::class, 3);
    }

    #[Test]
    public function dangerous_redirects_and_diagnostic_copies_are_rejected(): void
    {
        $open = app(OpenNotification::class);
        foreach (['//evil.test', '/portal-attacker', '/portal\\evil', 'https://evil.test/portal', "/portal\nhost"] as $url) {
            $this->assertSame('/portal', $open->safeTarget($this->actor, $url, true));
        }
        $this->assertTrue(NotificationPrivacy::matches(Request::create('/api/v1/notifications/web-push/devices', 'POST')));
        $this->assertTrue(NotificationPrivacy::matches(Request::create('/tech/profile/notifications', 'POST')));
        $this->assertFalse(NotificationPrivacy::matches(Request::create('/tech/tickets', 'GET')));
    }

    #[Test]
    public function successful_admin_revocation_replays_without_a_second_audit_and_cannot_cross_token(): void
    {
        $subscription = $this->actor->updatePushSubscription('https://push.example.test/device', str_repeat('A', 40), str_repeat('B', 24));
        $path = '/api/v1/notifications/admin/web-push/devices/'.$subscription->public_id;
        $key = $this->key();
        $response = $this->deleteJson($path, [], $key)->assertOk()->assertJsonPath('removed', true);
        $this->bearer();
        $this->deleteJson($path, [], $key)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::table('web_push_subscription_events')->where('action', 'administrator_revoked')->count());
        $newToken = $this->actor->createToken('Other credential', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->getJson('/api/v1/notifications/operations/'.$response->json('operation_id'))->assertNotFound();
    }

    #[Test]
    public function token_scope_and_expiry_changes_are_rechecked_even_when_authentication_is_cached(): void
    {
        $this->getJson('/api/v1/notifications/channels')->assertOk();
        $id = (int) explode('|', $this->token, 2)[0];
        \Laravel\Sanctum\PersonalAccessToken::findOrFail($id)->update(['abilities' => ['notifications.read']]);
        $this->getJson('/api/v1/notifications/channels')->assertForbidden();
        \Laravel\Sanctum\PersonalAccessToken::findOrFail($id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
