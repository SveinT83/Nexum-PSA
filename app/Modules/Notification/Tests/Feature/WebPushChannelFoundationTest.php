<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Exceptions\TemporaryWebPushDeliveryException;
use App\Modules\Notification\Jobs\SendWebPushDeviceTest;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Support\AuditedWebPushReportHandler;
use App\Modules\Notification\Support\WebPushReadiness;
use App\Modules\UserManagement\Actions\UpdateUserStatus;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushMessage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WebPushChannelFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webpush.enabled' => true,
            'webpush.vapid.subject' => 'mailto:webpush@example.test',
            'webpush.vapid.public_key' => 'public-vapid-test-key',
            'webpush.vapid.private_key' => 'private-vapid-test-key',
        ]);

        Permission::findOrCreate('notification.manage_channels', 'web');
    }

    #[Test]
    public function readiness_requires_the_explicit_environment_gate_and_never_serializes_the_private_key(): void
    {
        $readiness = app(WebPushReadiness::class);

        $this->assertTrue($readiness->isReady());
        $this->assertTrue($readiness->toArray()['ready']);
        $this->assertSame('public-vapid-test-key', $readiness->toArray()['public_key']);
        $this->assertStringNotContainsString(
            'private-vapid-test-key',
            json_encode($readiness->toArray(), JSON_THROW_ON_ERROR),
        );

        config(['webpush.enabled' => false]);

        $this->assertFalse($readiness->isReady());
        $this->assertSame('disabled', $readiness->toArray()['state']);
        $this->assertNull($readiness->toArray()['public_key']);
    }

    #[Test]
    public function user_preferences_render_opt_in_device_management_without_exposing_private_configuration(): void
    {
        $user = $this->makeUser('Tech');

        $response = $this->actingAs($user)
            ->get(route('tech.profile.notifications'));

        $response->assertOk();
        $response->assertSee('Web Push devices');
        $response->assertSee('Enable on this device');
        $response->assertSee('Notification.requestPermission()', false);
        $response->assertSee(route('tech.profile.notifications.web-push.devices.store'), false);
        $response->assertDontSee('private-vapid-test-key', false);
        $response->assertDontSee('web_push_enabled', false);
        $response->assertDontSee('web_push_preview_enabled', false);
    }

    #[Test]
    public function new_notification_settings_keep_web_push_business_events_off(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $setting = NotificationSetting::getForUser($user, 'ticket_assigned');

        $this->assertFalse($setting->web_push_enabled);
        $this->assertFalse($setting->web_push_preview_enabled);
        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
        ]);
    }

    #[Test]
    public function active_user_can_register_and_list_only_a_safe_device_summary(): void
    {
        $user = $this->makeUser('Tech');
        $payload = $this->validSubscriptionPayload();

        $register = $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0')
            ->postJson(route('tech.profile.notifications.web-push.devices.store'), $payload);

        $register
            ->assertCreated()
            ->assertJsonPath('device.browser', 'Google Chrome')
            ->assertJsonPath('device.platform', 'Windows');

        $subscription = WebPushSubscription::query()->firstOrFail();

        $this->assertSame($user->id, $subscription->subscribable_id);
        $this->assertSame($payload['endpoint'], $subscription->endpoint);
        $this->assertDatabaseHas('web_push_subscription_events', [
            'target_user_id' => $user->id,
            'actor_id' => $user->id,
            'subscription_public_id' => $subscription->public_id,
            'action' => 'registered',
        ]);

        $list = $this->getJson(route('tech.profile.notifications.web-push.devices.index'));
        $list
            ->assertOk()
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.id', $subscription->public_id)
            ->assertJsonMissingPath('devices.0.endpoint')
            ->assertJsonMissingPath('devices.0.public_key')
            ->assertJsonMissingPath('devices.0.auth_token');

        $this->assertStringNotContainsString($payload['endpoint'], $list->getContent());
        $this->assertStringNotContainsString($payload['keys']['p256dh'], $list->getContent());
        $this->assertStringNotContainsString($payload['keys']['auth'], $list->getContent());
    }

    #[Test]
    public function owned_registration_refreshes_transport_material_without_a_second_registration_audit(): void
    {
        $user = $this->makeUser('Tech');
        $subscription = $this->makeSubscription($user);
        $payload = $this->validSubscriptionPayload($subscription->endpoint);
        $payload['keys']['p256dh'] = str_repeat('C', 65);
        $payload['keys']['auth'] = str_repeat('D', 16);

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/126.0')
            ->postJson(route('tech.profile.notifications.web-push.devices.store'), $payload)
            ->assertOk()
            ->assertJsonPath('device.platform', 'Android');

        $subscription->refresh();
        $this->assertSame($payload['keys']['p256dh'], $subscription->public_key);
        $this->assertSame($payload['keys']['auth'], $subscription->auth_token);
        $this->assertSame('Android', $subscription->platform_family);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseCount('web_push_subscription_events', 0);
    }

    #[Test]
    public function registration_is_blocked_when_global_or_vapid_readiness_is_incomplete(): void
    {
        $user = $this->makeUser('Tech');

        config(['webpush.enabled' => false]);

        $this->actingAs($user)
            ->postJson(
                route('tech.profile.notifications.web-push.devices.store'),
                $this->validSubscriptionPayload(),
            )
            ->assertServiceUnavailable()
            ->assertJsonPath('readiness.state', 'disabled')
            ->assertJsonPath('readiness.public_key', null);

        config([
            'webpush.enabled' => true,
            'webpush.vapid.private_key' => null,
        ]);

        $this->postJson(
            route('tech.profile.notifications.web-push.devices.store'),
            $this->validSubscriptionPayload(),
        )
            ->assertServiceUnavailable()
            ->assertJsonPath('readiness.state', 'incomplete_configuration');

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    #[Test]
    public function registration_rejects_malformed_or_oversized_transport_data(): void
    {
        $user = $this->makeUser('Tech');

        $this->actingAs($user)
            ->postJson(route('tech.profile.notifications.web-push.devices.store'), [
                'endpoint' => 'http://push.example.test/not-secure',
                'keys' => [
                    'p256dh' => '<script>alert(1)</script>',
                    'auth' => 'short',
                ],
                'content_encoding' => 'unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'endpoint',
                'keys.p256dh',
                'keys.auth',
                'content_encoding',
            ]);

        $payload = $this->validSubscriptionPayload();
        $payload['endpoint'] = 'https://push.example.test/'.str_repeat('a', 501);

        $this->postJson(route('tech.profile.notifications.web-push.devices.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');
    }

    #[Test]
    public function an_endpoint_cannot_be_transferred_between_users(): void
    {
        $owner = $this->makeUser('Tech');
        $other = $this->makeUser('Tech');
        $subscription = $this->makeSubscription($owner);

        $this->actingAs($other)
            ->postJson(
                route('tech.profile.notifications.web-push.devices.store'),
                $this->validSubscriptionPayload($subscription->endpoint),
            )
            ->assertConflict();

        $subscription->refresh();
        $this->assertTrue($subscription->belongsToUser($owner));
        $this->assertFalse($subscription->belongsToUser($other));
    }

    #[Test]
    public function current_device_resolution_does_not_reveal_another_users_subscription(): void
    {
        $owner = $this->makeUser('Tech');
        $other = $this->makeUser('Tech');
        $subscription = $this->makeSubscription($owner);

        $this->actingAs($other)
            ->postJson(route('tech.profile.notifications.web-push.devices.current'), [
                'endpoint' => $subscription->endpoint,
            ])
            ->assertOk()
            ->assertJsonPath('device', null);

        $this->actingAs($owner)
            ->postJson(route('tech.profile.notifications.web-push.devices.current'), [
                'endpoint' => $subscription->endpoint,
            ])
            ->assertOk()
            ->assertJsonPath('device.id', $subscription->public_id)
            ->assertJsonMissingPath('device.endpoint');

        $this->assertNotNull($subscription->fresh()->last_seen_at);
    }

    #[Test]
    public function user_can_revoke_an_owned_device_but_not_another_users_device(): void
    {
        $owner = $this->makeUser('Tech');
        $other = $this->makeUser('Tech');
        $owned = $this->makeSubscription($owner);
        $otherDevice = $this->makeSubscription($other, 'https://push.example.test/subscriptions/other');

        $this->actingAs($owner)
            ->deleteJson(route('tech.profile.notifications.web-push.devices.destroy', $otherDevice))
            ->assertNotFound();

        $this->deleteJson(route('tech.profile.notifications.web-push.devices.destroy', $owned))
            ->assertOk()
            ->assertJsonPath('removed', true);

        $this->assertModelMissing($owned);
        $this->assertModelExists($otherDevice);
        $this->assertDatabaseHas('web_push_subscription_events', [
            'target_user_id' => $owner->id,
            'actor_id' => $owner->id,
            'subscription_public_id' => $owned->public_id,
            'action' => 'user_revoked',
        ]);
    }

    #[Test]
    public function self_test_is_queued_only_for_the_current_users_selected_device(): void
    {
        Queue::fake();
        $owner = $this->makeUser('Tech');
        $other = $this->makeUser('Tech');
        $subscription = $this->makeSubscription($owner);

        $this->actingAs($owner)
            ->postJson(route('tech.profile.notifications.web-push.test'), [
                'endpoint' => $subscription->endpoint,
            ])
            ->assertAccepted()
            ->assertJsonPath('queued', true);

        Queue::assertPushed(
            SendWebPushDeviceTest::class,
            fn (SendWebPushDeviceTest $job): bool => $job->userId === $owner->id
                && $job->subscriptionPublicId === $subscription->public_id,
        );

        $this->actingAs($other)
            ->postJson(route('tech.profile.notifications.web-push.test'), [
                'endpoint' => $subscription->endpoint,
            ])
            ->assertNotFound();

        Queue::assertPushed(SendWebPushDeviceTest::class, 1);
    }

    #[Test]
    public function self_test_is_rate_limited_to_three_requests_per_ten_minutes(): void
    {
        Queue::fake();
        $user = $this->makeUser('Tech');
        $subscription = $this->makeSubscription($user);
        $route = route('tech.profile.notifications.web-push.test');
        $payload = ['endpoint' => $subscription->endpoint];

        $this->actingAs($user)->postJson($route, $payload)->assertAccepted();
        $this->postJson($route, $payload)->assertAccepted();
        $this->postJson($route, $payload)->assertAccepted();
        $this->postJson($route, $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        Queue::assertPushed(SendWebPushDeviceTest::class, 3);
    }

    #[Test]
    public function temporary_self_test_job_policy_is_bounded(): void
    {
        $job = new SendWebPushDeviceTest(10, 'subscription-id');

        $this->assertSame(3, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertCount(3, $job->backoff());
    }

    #[Test]
    public function administrator_can_view_safe_inventory_and_revoke_but_technician_cannot(): void
    {
        $admin = $this->makeUser('Admin');
        $technician = $this->makeUser('Tech');
        $target = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Target Technician',
            'email' => 'target@example.test',
        ]);
        $subscription = $this->makeSubscription($target);

        $inventoryRoute = route('tech.admin.notification-channels.web-push.devices.index');

        $this->actingAs($technician)
            ->get($inventoryRoute)
            ->assertForbidden();

        $inventory = $this->actingAs($admin)->get($inventoryRoute);
        $inventory
            ->assertOk()
            ->assertSee('Target Technician')
            ->assertSee($subscription->device_label)
            ->assertDontSee($subscription->endpoint, false)
            ->assertDontSee($subscription->public_key, false)
            ->assertDontSee($subscription->auth_token, false)
            ->assertDontSee('private-vapid-test-key', false);

        $this->delete(route('tech.admin.notification-channels.web-push.devices.destroy', $subscription))
            ->assertRedirect($inventoryRoute);

        $this->assertModelMissing($subscription);
        $this->assertDatabaseHas('web_push_subscription_events', [
            'target_user_id' => $target->id,
            'actor_id' => $admin->id,
            'subscription_public_id' => $subscription->public_id,
            'action' => 'administrator_revoked',
        ]);
    }

    #[Test]
    public function disabling_a_user_removes_all_devices_once_while_logout_keeps_them(): void
    {
        $user = $this->makeUser('Tech');
        $first = $this->makeSubscription($user);
        $second = $this->makeSubscription(
            $user,
            'https://push.example.test/subscriptions/second',
        );

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertModelExists($first);
        $this->assertModelExists($second);

        app(UpdateUserStatus::class)->handle($user->fresh(), User::STATUS_DISABLED);

        $this->assertDatabaseMissing('push_subscriptions', ['subscribable_id' => $user->id]);
        $this->assertDatabaseCount('web_push_subscription_events', 2);
        $this->assertDatabaseHas('web_push_subscription_events', [
            'target_user_id' => $user->id,
            'actor_id' => null,
            'action' => 'user_disabled',
        ]);

        app(UpdateUserStatus::class)->handle($user->fresh(), User::STATUS_DISABLED);

        $this->assertDatabaseCount('web_push_subscription_events', 2);
    }

    #[Test]
    public function shared_service_worker_handles_visible_push_and_safe_same_origin_clicks(): void
    {
        $serviceWorker = file_get_contents(public_path('sw.js'));

        $this->assertIsString($serviceWorker);
        $this->assertStringContainsString('self.addEventListener("fetch"', $serviceWorker);
        $this->assertStringContainsString('self.addEventListener("push"', $serviceWorker);
        $this->assertStringContainsString('showNotification', $serviceWorker);
        $this->assertStringContainsString('self.addEventListener("notificationclick"', $serviceWorker);
        $this->assertStringContainsString('url.origin !== self.location.origin', $serviceWorker);
        $this->assertStringContainsString('NOTIFICATION_FALLBACK_URL', $serviceWorker);
    }

    #[Test]
    public function expired_provider_response_removes_and_audits_subscription_without_retry(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $subscription = $this->makeSubscription($user);
        Event::fake();
        $report = new MessageSentReport(
            new Request('POST', $subscription->endpoint),
            new Response(410),
            false,
            'Gone',
        );

        app(AuditedWebPushReportHandler::class)->handleReport(
            $report,
            $subscription,
            new WebPushMessage,
        );

        $this->assertModelMissing($subscription);
        $this->assertDatabaseHas('web_push_subscription_events', [
            'target_user_id' => $user->id,
            'actor_id' => null,
            'subscription_public_id' => $subscription->public_id,
            'action' => 'expired_endpoint_removed',
        ]);
    }

    #[Test]
    public function temporary_provider_response_throws_sanitized_retry_signal_and_keeps_subscription(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $subscription = $this->makeSubscription($user);
        Event::fake();
        $report = new MessageSentReport(
            new Request('POST', $subscription->endpoint),
            new Response(503, [], 'sensitive provider body'),
            false,
            'Provider unavailable',
        );

        try {
            app(AuditedWebPushReportHandler::class)->handleReport(
                $report,
                $subscription,
                new WebPushMessage,
            );
            $this->fail('A temporary provider response must signal a queue retry.');
        } catch (TemporaryWebPushDeliveryException $exception) {
            $this->assertSame(
                'Temporary Web Push delivery failure (HTTP 503).',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($subscription->endpoint, $exception->getMessage());
            $this->assertStringNotContainsString('sensitive provider body', $exception->getMessage());
        }

        $this->assertModelExists($subscription);
    }

    #[Test]
    public function permanent_provider_failure_logs_only_safe_subscription_identity(): void
    {
        Log::spy();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $subscription = $this->makeSubscription($user);
        Event::fake();
        $report = new MessageSentReport(
            new Request('POST', $subscription->endpoint),
            new Response(400, [], 'sensitive provider body'),
            false,
            'Bad request',
        );

        app(AuditedWebPushReportHandler::class)->handleReport(
            $report,
            $subscription,
            new WebPushMessage,
        );

        Log::shouldHaveReceived('warning')->once()->with(
            'Web Push delivery failed without retry.',
            [
                'subscription_public_id' => $subscription->public_id,
                'http_status' => 400,
            ],
        );
        $this->assertModelExists($subscription);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding: string}
     */
    private function validSubscriptionPayload(
        string $endpoint = 'https://push.example.test/subscriptions/current',
    ): array {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => str_repeat('A', 65),
                'auth' => str_repeat('B', 16),
            ],
            'content_encoding' => 'aes128gcm',
        ];
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => $role]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSubscription(
        User $user,
        string $endpoint = 'https://push.example.test/subscriptions/current',
    ): WebPushSubscription {
        $subscription = $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => str_repeat('A', 65),
            'auth_token' => str_repeat('B', 16),
            'content_encoding' => 'aes128gcm',
            'device_label' => 'Google Chrome on Windows',
            'browser_family' => 'Google Chrome',
            'platform_family' => 'Windows',
            'last_seen_at' => now(),
        ]);

        $this->assertInstanceOf(WebPushSubscription::class, $subscription);

        return $subscription;
    }
}
