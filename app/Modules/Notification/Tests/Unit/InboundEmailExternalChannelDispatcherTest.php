<?php

namespace App\Modules\Notification\Tests\Unit;

use App\Models\Core\User;
use App\Modules\Notification\Channels\EmailAccountMailChannel;
use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Services\InboundEmailExternalChannelDispatcher;
use App\Modules\Notification\Services\InboundEmailWebPushDelivery;
use App\Modules\Notification\Support\WebPushReadiness;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEmailExternalChannelDispatcherTest extends TestCase
{
    #[Test]
    public function frozen_request_mask_never_adopts_mail_enabled_after_the_event(): void
    {
        $webPush = $this->createMock(InboundEmailWebPushDelivery::class);
        $webPush->expects($this->once())->method('send')->willReturn([
            'status' => 'delivered',
            'reason_code' => 'web_push_delivery_confirmed',
        ]);
        $dispatcher = $this->dispatcher($webPush, $this->createMock(NextcloudTalkChannel::class));

        $result = $dispatcher->deliver(
            new User(['id' => 41]),
            new ChannelFixtureInboundNotification([
                EmailAccountMailChannel::class,
                WebPushChannel::class,
            ]),
            ['mail' => false, 'web_push' => true, 'nextcloud_talk' => false],
        );

        $this->assertSame(NotificationInboundExternalDelivery::STATUS_COMPLETED, $result['status']);
    }

    #[Test]
    public function empty_exact_channel_intersection_is_suppressed(): void
    {
        $webPush = $this->createMock(InboundEmailWebPushDelivery::class);
        $webPush->expects($this->never())->method('send');
        $talk = $this->createMock(NextcloudTalkChannel::class);
        $talk->expects($this->never())->method('sendExact');

        $result = $this->dispatcher($webPush, $talk)->deliver(
            new User(['id' => 42]),
            new ChannelFixtureInboundNotification([]),
            ['mail' => false, 'web_push' => false, 'nextcloud_talk' => false],
        );

        $this->assertSame(NotificationInboundExternalDelivery::STATUS_SUPPRESSED, $result['status']);
    }

    #[Test]
    public function every_selected_channel_must_confirm_delivery(): void
    {
        $webPush = $this->createMock(InboundEmailWebPushDelivery::class);
        $webPush->method('send')->willReturn([
            'status' => 'delivered',
            'reason_code' => 'web_push_delivery_confirmed',
        ]);
        $talk = $this->createMock(NextcloudTalkChannel::class);
        $talk->method('sendExact')->willReturn([
            'status' => 'delivered',
            'reason_code' => 'nextcloud_talk_delivery_confirmed',
        ]);
        $dispatcher = $this->dispatcher($webPush, $talk);
        $notification = new ChannelFixtureInboundNotification([
            WebPushChannel::class,
            NextcloudTalkChannel::class,
        ]);
        $requested = ['mail' => false, 'web_push' => true, 'nextcloud_talk' => true];

        $this->assertSame(
            NotificationInboundExternalDelivery::STATUS_COMPLETED,
            $dispatcher->deliver(new User(['id' => 43]), $notification, $requested)['status'],
        );

        $talk = $this->createMock(NextcloudTalkChannel::class);
        $talk->method('sendExact')->willReturn([
            'status' => 'suppressed',
            'reason_code' => 'nextcloud_talk_connection_missing',
        ]);
        $this->assertSame(
            NotificationInboundExternalDelivery::STATUS_UNRESOLVED,
            $this->dispatcher($webPush, $talk)
                ->deliver(new User(['id' => 43]), $notification, $requested)['status'],
        );
    }

    #[Test]
    public function all_attempted_suppressed_channels_are_suppressed_but_blocked_is_unresolved(): void
    {
        $webPush = $this->createMock(InboundEmailWebPushDelivery::class);
        $webPush->method('send')->willReturn([
            'status' => 'suppressed',
            'reason_code' => 'web_push_subscription_missing',
        ]);
        $talk = $this->createMock(NextcloudTalkChannel::class);
        $talk->method('sendExact')->willReturn([
            'status' => 'suppressed',
            'reason_code' => 'nextcloud_talk_connection_missing',
        ]);
        $notification = new ChannelFixtureInboundNotification([
            WebPushChannel::class,
            NextcloudTalkChannel::class,
        ]);
        $requested = ['mail' => false, 'web_push' => true, 'nextcloud_talk' => true];

        $this->assertSame(
            NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
            $this->dispatcher($webPush, $talk)
                ->deliver(new User(['id' => 44]), $notification, $requested)['status'],
        );

        $this->assertSame(
            NotificationInboundExternalDelivery::STATUS_UNRESOLVED,
            $this->dispatcher($webPush, $talk)->deliver(
                new User(['id' => 44]),
                new ChannelFixtureInboundNotification([EmailAccountMailChannel::class]),
                ['mail' => true, 'web_push' => false, 'nextcloud_talk' => false],
            )['status'],
        );
    }

    private function dispatcher(
        InboundEmailWebPushDelivery $webPush,
        NextcloudTalkChannel $talk,
    ): InboundEmailExternalChannelDispatcher {
        $readiness = $this->createMock(WebPushReadiness::class);
        $readiness->method('isReady')->willReturn(true);

        return new InboundEmailExternalChannelDispatcher(
            app(EmailAccountMailChannel::class),
            $webPush,
            $talk,
            $readiness,
        );
    }
}

class ChannelFixtureInboundNotification extends InboundEmailRoutedNotification
{
    /** @param list<class-string|string> $channels */
    public function __construct(private readonly array $channels)
    {
        parent::__construct([], '00000000-0000-0000-0000-000000000001', [
            'scope' => 'system',
            'account_id' => null,
            'provider_binding_version' => null,
            'failure_code' => 'provider_binding_snapshot_missing',
        ]);
    }

    public function via(object $notifiable): array
    {
        throw new \LogicException('External delivery must not re-expand mutable settings through via().');
    }
}
