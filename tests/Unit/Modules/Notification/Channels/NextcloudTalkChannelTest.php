<?php

namespace Tests\Unit\Modules\Notification\Channels;

use App\Models\Core\User;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class NextcloudTalkChannelTest extends TestCase
{
    use RefreshDatabase;

    private NextcloudTalkChannel $channel;

    private NextcloudConnection $connection;

    private NotificationChannel $talkChannelConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = app(NextcloudTalkChannel::class);

        $this->connection = NextcloudConnection::factory()->create([
            'base_url' => 'https://nextcloud.example.com',
            'service_username' => 'admin',
            'service_password' => 'test-password',
            'is_active' => true,
            'talk_bot_id' => 1,
            'talk_bot_secret' => 'test-bot-secret',
            'talk_default_conversation_token' => 'support-room',
            'talk_bot_features' => [],
        ]);

        $this->talkChannelConfig = NotificationChannel::query()->updateOrCreate(
            ['name' => 'nextcloud_talk'],
            [
                'label' => 'Nextcloud Talk',
                'driver' => 'nextcloud_talk',
                'is_enabled' => true,
                'config' => [],
            ]
        );
    }

    #[Test]
    public function it_sends_via_bot_api_when_bot_is_configured(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/support-room/message' => Http::response([
                'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 201], 'data' => []],
            ], 201),
        ]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'ticket_key' => 'TK-42',
            'subject' => 'Printer on fire',
        ]);

        $setting = NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'nextcloud_talk_enabled' => true,
        ]);

        $notification = new TicketAssigned($ticket, 'Admin');
        $result = $this->channel->send($user, $notification);

        // Should have made a bot API call (signed message)
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot/support-room/message')
                && $request->hasHeader('X-Nextcloud-Talk-Bot-Random')
                && $request->hasHeader('X-Nextcloud-Talk-Bot-Signature')
                && $request->hasHeader('OCS-APIRequest');
        });
        $this->assertSame('delivered', $result['status']);
    }

    #[Test]
    public function it_falls_back_to_webhook_when_no_bot_configured(): void
    {
        // Remove bot config from connection
        $this->connection->update([
            'talk_bot_id' => null,
            'talk_bot_secret' => null,
        ]);

        $webhookUrl = 'https://nextcloud.example.com/apps/spreed/api/v1/room/support-room/webhook';

        $this->talkChannelConfig->update([
            'config' => ['default_webhook_url' => $webhookUrl],
        ]);

        Http::fake([
            $webhookUrl => Http::response([], 200),
        ]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'ticket_key' => 'TK-99',
            'subject' => 'Server down',
        ]);

        $setting = NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'nextcloud_talk_enabled' => true,
        ]);

        $notification = new TicketAssigned($ticket, 'Admin');
        $this->channel->send($user, $notification);

        // Should have made a webhook POST (no signing headers)
        Http::assertSent(function ($request) use ($webhookUrl) {
            return $request->url() === $webhookUrl
                && ! $request->hasHeader('X-Nextcloud-Talk-Bot-Signature');
        });
    }

    #[Test]
    public function it_skips_sending_when_channel_is_disabled(): void
    {
        $this->talkChannelConfig->update(['is_enabled' => false]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $notification = new TicketAssigned($ticket, 'Admin');
        $result = $this->channel->send($user, $notification);

        Http::assertNothingSent();
        $this->assertSame('suppressed', $result['status']);
    }

    #[Test]
    public function it_skips_sending_when_no_active_connection(): void
    {
        $this->connection->update(['is_active' => false]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $notification = new TicketAssigned($ticket, 'Admin');
        $this->channel->send($user, $notification);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_uses_per_user_conversation_token_when_set(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/personal-room/message' => Http::response([
                'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 201], 'data' => []],
            ], 201),
        ]);

        $user = User::factory()->create();

        $setting = NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'nextcloud_talk_enabled' => true,
            'nextcloud_talk_webhook_url' => 'https://nextcloud.example.com/apps/spreed/api/v1/room/personal-room/webhook',
        ]);

        $ticket = Ticket::factory()->create(['ticket_key' => 'TK-55']);
        $notification = new TicketAssigned($ticket, 'Admin');
        $this->channel->send($user, $notification);

        // Should have used the per-user conversation token
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot/personal-room/message');
        });
    }

    #[Test]
    public function exact_delivery_uses_only_the_authorized_notification_type_target(): void
    {
        $user = User::factory()->create();
        NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'nextcloud_talk_enabled' => true,
            'nextcloud_talk_webhook_url' => 'https://nextcloud.example.com/apps/spreed/api/v1/room/unrelated-room/webhook',
        ]);
        $exactUrl = 'https://nextcloud.example.com/apps/spreed/api/v1/room/inbound-room/webhook';
        NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'notification_type' => 'inbound_email_received',
            'nextcloud_talk_enabled' => true,
            'nextcloud_talk_webhook_url' => $exactUrl,
        ]);
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/inbound-room/message' => Http::response([
                'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 201], 'data' => []],
            ], 201),
        ]);

        $result = $this->channel->sendExact(
            $user,
            new TicketAssigned(Ticket::factory()->create(), 'Admin'),
            $exactUrl,
        );

        $this->assertSame('delivered', $result['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains(
            $request->url(),
            '/bot/inbound-room/message',
        ));
        Http::assertNotSent(fn ($request): bool => str_contains(
            $request->url(),
            '/bot/unrelated-room/message',
        ));
    }

    #[Test]
    public function invalid_exact_target_is_suppressed_without_falling_back_to_a_default_room(): void
    {
        Http::fake();

        $result = $this->channel->sendExact(
            User::factory()->create(),
            new TicketAssigned(Ticket::factory()->create(), 'Admin'),
            'not-a-talk-webhook',
        );

        $this->assertSame('suppressed', $result['status']);
        $this->assertSame('nextcloud_talk_conversation_missing', $result['reason_code']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_formats_rich_messages_for_bot_api(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/support-room/message' => Http::response([
                'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 201], 'data' => []],
            ], 201),
        ]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'ticket_key' => 'TK-42',
            'subject' => 'Internet is down',
        ]);

        NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'nextcloud_talk_enabled' => true,
        ]);

        $notification = new TicketAssigned($ticket, 'Svein');
        $this->channel->send($user, $notification);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $message = $body['message'] ?? '';

            // Should contain rich formatting from toNextcloudTalk()
            return str_contains($message, '**Internet is down**')
                && str_contains($message, 'Assigned by')
                && str_contains($message, 'View Ticket');
        });
    }

    #[Test]
    public function explicit_connection_never_falls_back_to_another_active_tenant(): void
    {
        $other = NextcloudConnection::factory()->create([
            'base_url' => 'https://other-nextcloud.example.com',
            'service_username' => 'other-admin',
            'service_password' => 'other-password',
            'is_active' => true,
            'is_default' => true,
            'talk_bot_id' => 2,
            'talk_bot_secret' => 'other-secret',
            'talk_default_conversation_token' => 'other-room',
            'talk_bot_features' => [],
        ]);
        $this->talkChannelConfig->update([
            'config' => ['nextcloud_connection_id' => $this->connection->id],
        ]);
        $this->connection->update(['is_active' => false]);
        Http::fake();

        $result = $this->channel->send(
            User::factory()->create(),
            new TicketAssigned(Ticket::factory()->create(), 'Admin'),
        );

        $this->assertSame('suppressed', $result['status']);
        $this->assertSame('nextcloud_talk_connection_missing', $result['reason_code']);
        Http::assertNothingSent();
        $this->assertTrue($other->fresh()->is_active);
    }

    #[Test]
    public function webhook_failures_are_unresolved_and_logs_never_include_provider_content_or_endpoint(): void
    {
        $this->connection->update([
            'talk_bot_id' => null,
            'talk_bot_secret' => null,
        ]);
        $webhook = 'https://nextcloud.example.com/apps/spreed/api/v1/room/private-token/webhook';
        $this->talkChannelConfig->update(['config' => ['default_webhook_url' => $webhook]]);
        $user = User::factory()->create();
        $notification = new TicketAssigned(Ticket::factory()->create(), 'Admin');

        Log::spy();
        Http::fake([$webhook => Http::response('provider-body-canary', 503)]);
        $result = $this->channel->send($user, $notification);
        $this->assertSame('unresolved', $result['status']);
        Log::shouldHaveReceived('warning')->once()->with(
            'NextcloudTalk: Webhook delivery failed.',
            ['status' => 503],
        );

        Log::spy();
        Http::fake(fn () => throw new RuntimeException('provider-exception-canary '.$webhook));
        $result = $this->channel->send($user, $notification);
        $this->assertSame('unresolved', $result['status']);
        Log::shouldHaveReceived('error')->once()->with(
            'NextcloudTalk: Webhook delivery exception.',
            ['exception' => RuntimeException::class],
        );
    }
}
