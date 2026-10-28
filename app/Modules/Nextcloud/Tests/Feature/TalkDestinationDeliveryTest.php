<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDelivery;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination;
use App\Modules\Nextcloud\Services\TalkDestinationRegistry;
use App\Modules\Nextcloud\Services\TalkNotificationDelivery;
use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TalkDestinationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function configured(string $host = 'a'): array
    {
        $connection = NextcloudConnection::factory()->create([
            'name' => 'Talk '.$host, 'base_url' => 'https://'.$host.'.example.test', 'scope' => 'global',
            'mode' => NextcloudConnection::MODE_MANAGED, 'is_active' => true,
            'settings' => ['unrelated_setting' => 'keep', 'talk_notifications' => ['enabled' => true]]]);
        $destination = NextcloudTalkDestination::create(['connection_id' => $connection->id,
            'provider_binding' => app(TalkDestinationRegistry::class)->binding($connection),
            'conversation_token' => 'same123', 'name' => 'Approved '.$host, 'room_type' => 2,
            'is_enabled' => true, 'is_available' => true, 'health_status' => 'available']);
        $channel = NotificationChannel::firstOrCreate(['driver' => 'nextcloud_talk'],
            ['name' => 'nextcloud_talk', 'label' => 'Nextcloud Talk', 'is_enabled' => false]);
        $channel->forceFill(['is_enabled' => true, 'config' => ['talk_destination_id' => $destination->id,
            'nextcloud_connection_id' => $connection->id, 'talk_conversation_token' => $destination->conversation_token,
            'delivery_mode' => 'conversation']])->save();

        return [$connection, $destination, $channel];
    }

    private function provider(bool $readback = true): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($readback) {
            if (str_contains($request->url(), '/api/v4/room/')) {
                return Http::response(['ocs' => ['meta' => ['statuscode' => 100],
                    'data' => ['token' => 'same123', 'readOnly' => 0, 'permissions' => 128]]]);
            }
            if ($request->method() === 'POST') {
                return Http::response(['ocs' => ['meta' => ['statuscode' => 100],
                    'data' => ['id' => 42, 'token' => 'same123', 'referenceId' => $request['referenceId']]]], 201);
            }

            return Http::response(['ocs' => ['meta' => ['statuscode' => 100],
                'data' => $readback ? [['id' => 42, 'token' => 'same123', 'message' => 'SENSITIVE-BODY-CANARY']] : []]]);
        });
    }

    #[Test]
    public function exact_connection_token_and_independent_readback_are_verified_once(): void
    {
        [$connection, $destination] = $this->configured('b');
        $this->provider();
        $service = app(TalkNotificationDelivery::class);
        $result = $service->send($destination, 'SENSITIVE-BODY-CANARY', 'stable-notification');
        $this->assertSame('confirmed', $result['state']);
        $this->assertTrue($result['delivery_confirmed']);
        $this->assertSame(42, $result['message_id']);
        $again = $service->send($destination->fresh(), 'SENSITIVE-BODY-CANARY', 'stable-notification');
        $this->assertTrue($again['replayed']);
        $this->assertSame($result['delivery_id'], $again['delivery_id']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://b.example.test/')
            && str_contains($request->url(), '/api/v1/chat/same123'));
        $this->assertStringNotContainsString('SENSITIVE-BODY-CANARY',
            json_encode(NextcloudTalkDelivery::firstOrFail()->getAttributes()));
        $this->assertSame('keep', $connection->fresh()->settings['unrelated_setting']);
    }

    #[Test]
    public function accepted_without_readback_stays_unverified_and_is_never_sent_again(): void
    {
        [, $destination] = $this->configured();
        $this->provider(false);
        $service = app(TalkNotificationDelivery::class);
        $first = $service->send($destination, 'Fixture', 'stable-unknown');
        $this->assertSame('provider_accepted', $first['state']);
        $this->assertFalse($first['delivery_confirmed']);
        $again = $service->send($destination->fresh(), 'Fixture', 'stable-unknown');
        $this->assertSame('provider_accepted', $again['state']);
        $this->assertTrue($again['replayed']);
        Http::assertSentCount(3);
    }

    #[Test]
    public function global_connection_and_destination_switches_stop_before_provider_io(): void
    {
        [$connection, $destination, $channel] = $this->configured();
        Http::preventStrayRequests();
        Http::fake();
        $service = app(TalkNotificationDelivery::class);
        $channel->update(['is_enabled' => false]);
        $this->assertSame('talk_global_disabled', $service->send($destination, 'Fixture', 'disabled-global')['reason_code']);
        $channel->update(['is_enabled' => true]);
        app(TalkDestinationRegistry::class)->saveSettings($connection, ['enabled' => false]);
        $this->assertSame('blocked', $service->send($destination, 'Fixture', 'disabled-connection')['state']);
        app(TalkDestinationRegistry::class)->saveSettings($connection->fresh(), ['enabled' => true]);
        $destination->update(['is_enabled' => false]);
        $this->assertSame('blocked', $service->send($destination, 'Fixture', 'disabled-destination')['state']);
        Http::assertNothingSent();
    }

    #[Test]
    public function changing_global_target_cannot_reroute_a_retried_identity(): void
    {
        [$a, $first] = $this->configured('a');
        $this->provider();
        $service = app(TalkNotificationDelivery::class);
        $service->send($first, 'Fixture', 'stable-target');
        [, $other] = $this->configured('b');
        $outcome = $service->send($other, 'Fixture', 'stable-target');
        $this->assertSame('talk_identity_or_target_changed', $outcome['reason_code']);
        Http::assertSentCount(3);
        $this->assertNotSame($first->connection_id, $other->connection_id);
        $this->assertSame($first->conversation_token, $other->conversation_token);
    }

    #[Test]
    public function discovery_and_selection_are_connection_scoped_and_defaults_are_explicit(): void
    {
        [$a, $first] = $this->configured('a');
        [$b, $other] = $this->configured('b');
        $registry = app(TalkDestinationRegistry::class);
        $before = $registry->revision($a->fresh());
        $context = $registry->update($a, true, [$first->id], $first->id, $before);
        $this->assertSame($first->id, $context['default_destination_id']);
        $this->assertTrue($other->fresh()->is_enabled);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $registry->update($a->fresh(), true, [$other->id], $other->id, $context['revision']);
    }

    #[Test]
    public function changed_provider_identity_invalidates_cached_destinations_before_any_io(): void
    {
        [$c, $destination] = $this->configured();
        Http::fake();
        $c->update(['base_url' => 'https://different.example.test']);
        $this->assertSame('blocked', app(TalkNotificationDelivery::class)->send($destination, 'Fixture', 'changed-host')['state']);
        Http::assertNothingSent();
    }

    #[Test]
    public function failed_discovery_marks_only_its_connection_unknown_and_keeps_other_features(): void
    {
        [$a, $first] = $this->configured('a');
        [, $other] = $this->configured('b');
        Http::fake(['*' => Http::response('<html>SECRET-body</html>', 200)]);
        $registry = app(TalkDestinationRegistry::class);
        try {
            $registry->refresh($a, $registry->revision($a->fresh()));
            $this->fail('HTML was accepted as Talk discovery.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
        $this->assertSame('unknown', $first->fresh()->health_status);
        $this->assertSame('available', $other->fresh()->health_status);
        $this->assertSame('keep', $a->fresh()->settings['unrelated_setting']);
    }

    #[Test]
    public function timeout_after_message_post_is_unknown_and_replay_does_not_post_again(): void
    {
        [, $destination] = $this->configured();
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['ocs' => ['meta' => ['statuscode' => 100],
                    'data' => ['token' => 'same123', 'readOnly' => 0, 'permissions' => 128]]]);
            }
            throw new \Illuminate\Http\Client\ConnectionException('SECRET-timeout');
        });
        $service = app(TalkNotificationDelivery::class);
        $first = $service->send($destination, 'SECRET-message', 'timeout-stable');
        $this->assertSame('unknown', $first['state']);
        $this->assertTrue($first['reconciliation_required']);
        $this->assertStringNotContainsString('SECRET', json_encode($first));
        $this->assertTrue($service->send($destination->fresh(), 'SECRET-message', 'timeout-stable')['replayed']);
        Http::assertSentCount(1); // Laravel records responses, not the POST whose transport threw.
    }

    #[Test]
    public function provider_denial_and_malformed_success_never_become_confirmed_delivery(): void
    {
        [, $destination] = $this->configured();
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['ocs' => ['meta' => ['statuscode' => 100],
                    'data' => ['token' => 'same123', 'readOnly' => 0, 'permissions' => 128]]]);
            }

            return Http::response('SECRET-denial', 403);
        });
        $service = app(TalkNotificationDelivery::class);
        $first = $service->send($destination, 'Fixture', 'denied-stable');
        $this->assertSame('failed', $first['state']);
        $this->assertFalse($first['delivery_confirmed']);
        $destination->refresh()->update(['health_status' => 'available']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['ocs' => ['meta' => ['statuscode' => 100],
                    'data' => ['token' => 'same123', 'readOnly' => 0, 'permissions' => 128]]]);
            }

            return Http::response('<html>SECRET-success</html>', 200);
        });
        $result = $service->send($destination->fresh(), 'Fixture', 'malformed-stable');
        $this->assertSame('unknown', $result['state']);
        $this->assertStringNotContainsString('SECRET', json_encode($result));
    }

    #[Test]
    public function native_notification_channel_uses_service_user_and_preserves_exact_legacy_recipient_scope(): void
    {
        [, $destination] = $this->configured('b');
        $user = \App\Models\Core\User::factory()->create(['status' => \App\Models\Core\User::STATUS_ACTIVE]);
        $notification = new class extends \Illuminate\Notifications\Notification
        {
            public function toNextcloudTalk($notifiable): array
            {
                return ['message' => 'Generic fixture'];
            }
        };
        $notification->id = (string) \Illuminate\Support\Str::uuid();
        $this->provider();
        $channel = app(\App\Modules\Notification\Channels\NextcloudTalkChannel::class);
        $blocked = $channel->sendExact($user, $notification, 'https://legacy.example.test/SECRET');
        $this->assertSame('nextcloud_talk_legacy_target_requires_migration', $blocked['reason_code']);
        Http::assertNothingSent();
        $sent = $channel->sendExact($user, $notification, null);
        $this->assertSame('delivered', $sent['status']);
        $this->assertTrue($sent['delivery_confirmed']);
        Http::assertSentCount(3);
        $channel->sendExact($user, $notification, null);
        Http::assertSentCount(3);
    }
}
