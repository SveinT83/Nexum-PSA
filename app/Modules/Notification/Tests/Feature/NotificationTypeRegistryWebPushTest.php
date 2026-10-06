<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Contracts\QueuesInternalWebPush;
use App\Modules\Notification\Jobs\SendQueuedInternalWebPush;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\QueuedInternalWebPushNotification;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Notification\Support\InternalWebPushTargetAuthorizer;
use App\Modules\Notification\Support\NotificationTypeRegistry;
use App\Modules\Notification\Support\WebPushReadiness;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationTypeRegistryWebPushTest extends TestCase
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
    }

    #[Test]
    public function every_notification_type_has_complete_channel_and_web_push_policy(): void
    {
        $definitions = NotificationTypeRegistry::all();

        $this->assertCount(28, $definitions);
        foreach ($definitions as $type => $definition) {
            $this->assertNotSame('', trim($type));
            $this->assertNotSame('', trim($definition['label']));
            $this->assertNotSame('', trim($definition['description']));
            $this->assertArrayHasKey($definition['group'], NotificationTypeRegistry::GROUPS);
            $this->assertContains($definition['audience'], [
                NotificationTypeRegistry::AUDIENCE_INTERNAL,
                NotificationTypeRegistry::AUDIENCE_CUSTOMER_PORTAL,
            ]);
            $this->assertSame(
                ['mail', 'database', 'web_push', 'web_push_preview', 'nextcloud_talk'],
                array_keys($definition['channels']),
            );
            $this->assertFalse($definition['defaults']['web_push_enabled']);

            if (! $definition['web_push']['eligible']) {
                $this->assertNotSame('', trim((string) $definition['web_push']['exclusion_reason']));

                continue;
            }

            $this->assertNull($definition['web_push']['exclusion_reason']);
            $this->assertNotNull($definition['web_push']['notification_class']);
            $this->assertNotNull($definition['web_push']['delivery']);

            if ($definition['web_push']['delivery'] === NotificationTypeRegistry::WEB_PUSH_QUEUED_INTERNAL) {
                $this->assertTrue(is_subclass_of(
                    $definition['web_push']['notification_class'],
                    QueuesInternalWebPush::class,
                ));
                $this->assertNotNull($definition['web_push']['target_kind']);
                $this->assertNotSame('', trim((string) $definition['web_push']['permission']));
            }
        }
    }

    #[Test]
    public function internal_preferences_group_every_eligible_type_and_exclude_portal_types(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Tech']);
        $user->assignRole('Tech');

        $response = $this->actingAs($user)->get(route('tech.profile.notifications'));

        $response->assertOk()
            ->assertSee('Tickets')
            ->assertSee('Assets and monitoring')
            ->assertSee('Storage')
            ->assertSee('Web Push unavailable')
            ->assertDontSee('portal_ticket_created', false);

        foreach (NotificationTypeRegistry::definitionsForAudience(NotificationTypeRegistry::AUDIENCE_INTERNAL) as $type => $definition) {
            if ($type === 'workday_reminder') {
                $response->assertDontSee('id="push_workday_reminder"', false);
                continue;
            }
            $response->assertSee($definition['label']);
            $response->assertSee($definition['description']);
            if ($definition['web_push']['eligible']) {
                $response->assertSee('id="push_'.$type.'"', false);
            } else {
                $response->assertDontSee('id="push_'.$type.'"', false);
                $response->assertSee($definition['web_push']['exclusion_reason']);
            }
        }
    }

    #[Test]
    public function preference_persistence_enables_eligible_type_and_rejects_forged_support(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'Tech']);
        $user->assignRole('Tech');

        $this->actingAs($user)
            ->post(route('tech.profile.notifications.update'), [
                'settings' => [
                    [
                        'notification_type' => 'ticket_assigned',
                        'database_enabled' => '1',
                        'web_push_enabled' => '1',
                    ],
                    [
                        'notification_type' => 'ticket_created',
                        'database_enabled' => '1',
                        'web_push_enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('tech.profile.notifications'));

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'web_push_enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'notification_type' => 'ticket_created',
            'web_push_enabled' => false,
        ]);

        $this->actingAs($user)
            ->post(route('tech.profile.notifications.update'), [
                'settings' => [[
                    'notification_type' => 'portal_ticket_created',
                    'web_push_enabled' => '1',
                ]],
            ])
            ->assertSessionHasErrors('settings.0.notification_type');
    }

    #[Test]
    public function database_notification_is_persisted_before_generic_push_is_queued(): void
    {
        Queue::fake();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id, 'subject' => 'Private subject']);
        NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);

        $user->notify(new TicketAssigned($ticket, 'Private actor'));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
        ]);
        Queue::assertPushed(SendQueuedInternalWebPush::class, function (SendQueuedInternalWebPush $job) use ($ticket, $user): bool {
            return $job->userId === $user->id
                && $job->notificationType === 'ticket_assigned'
                && $job->targetId === $ticket->id
                && ! str_contains($job->body, 'Private')
                && ! str_contains($job->title, 'Private');
        });
    }

    #[Test]
    public function delivery_rechecks_preference_permission_target_and_safe_same_origin_payload(): void
    {
        $permission = Permission::findOrCreate('ticket.view', 'web');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo($permission);
        $ticket = Ticket::factory()->create(['subject' => 'Never expose this subject']);
        NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);

        $channel = $this->createMock(WebPushChannel::class);
        $channel->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(fn (User $target): bool => $target->is($user)),
                $this->callback(function (QueuedInternalWebPushNotification $notification) use ($user): bool {
                    $payload = $notification->toWebPush($user, $notification)->toArray();

                    return $payload['title'] === 'Ticket assigned'
                        && $payload['body'] === 'Open Nexum to review the assigned Ticket.'
                        && str_starts_with($payload['data']['url'], '/tech/tickets/')
                        && ! str_contains(json_encode($payload), 'Never expose');
                }),
            );

        $job = new SendQueuedInternalWebPush(
            $user->id,
            'ticket_assigned',
            'Ticket assigned',
            'Open Nexum to review the assigned Ticket.',
            $ticket->id,
        );
        $job->handle(
            $channel,
            app(WebPushReadiness::class),
            app(InternalWebPushTargetAuthorizer::class),
        );

        NotificationSetting::query()
            ->where('user_id', $user->id)
            ->where('notification_type', 'ticket_assigned')
            ->update(['web_push_enabled' => false]);
        $suppressed = $this->createMock(WebPushChannel::class);
        $suppressed->expects($this->never())->method('send');
        $job->handle(
            $suppressed,
            app(WebPushReadiness::class),
            app(InternalWebPushTargetAuthorizer::class),
        );
    }

    #[Test]
    public function missing_target_and_disabled_or_system_user_are_suppressed(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(Permission::findOrCreate('ticket.view', 'web'));
        NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => 'ticket_assigned',
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);

        $channel = $this->createMock(WebPushChannel::class);
        $channel->expects($this->never())->method('send');
        $job = new SendQueuedInternalWebPush(
            $user->id,
            'ticket_assigned',
            'Ticket assigned',
            'Open Nexum.',
            PHP_INT_MAX,
        );
        $job->handle($channel, app(WebPushReadiness::class), app(InternalWebPushTargetAuthorizer::class));

        $user->update(['status' => User::STATUS_DISABLED]);
        $job->handle($channel, app(WebPushReadiness::class), app(InternalWebPushTargetAuthorizer::class));

        $user->update(['status' => User::STATUS_ACTIVE, 'is_system_actor' => true]);
        $job->handle($channel, app(WebPushReadiness::class), app(InternalWebPushTargetAuthorizer::class));
    }
}
