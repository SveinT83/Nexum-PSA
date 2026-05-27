<?php

namespace Tests\Unit\Modules\Notification\Channels;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Ticket\Models\Ticket;
use App\Models\Core\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NextcloudTalkChannelTest extends TestCase
{
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

        $this->talkChannelConfig = NotificationChannel::factory()->create([
            'driver' => 'nextcloud_talk',
            'is_enabled' => true,
            'config' => [],
        ]);
    }

    /** @test */
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
        $this->channel->send($user, $notification);

        // Should have made a bot API call (signed message)
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot/support-room/message')
                && $request->hasHeader('X-Nextcloud-Talk-Random')
                && $request->hasHeader('X-Nextcloud-Talk-Signature')
                && $request->hasHeader('OCS-APIRequest');
        });
    }

    /** @test */
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
                && !$request->hasHeader('X-Nextcloud-Talk-Signature');
        });
    }

    /** @test */
    public function it_skips_sending_when_channel_is_disabled(): void
    {
        $this->talkChannelConfig->update(['is_enabled' => false]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $notification = new TicketAssigned($ticket, 'Admin');
        $this->channel->send($user, $notification);

        Http::assertNothingSent();
    }

    /** @test */
    public function it_skips_sending_when_no_active_connection(): void
    {
        $this->connection->update(['is_active' => false]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $notification = new TicketAssigned($ticket, 'Admin');
        $this->channel->send($user, $notification);

        Http::assertNothingSent();
    }

    /** @test */
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

    /** @test */
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
}