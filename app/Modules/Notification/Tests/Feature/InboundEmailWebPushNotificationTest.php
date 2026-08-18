<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\Actions\RecordCanonicalNotification;
use App\Modules\Notification\Actions\ResolveInboundEmailNotificationRecipients;
use App\Modules\Notification\Contracts\InboundEmailExternalNotificationDispatcher;
use App\Modules\Notification\Jobs\DeliverInboundEmailExternalNotification;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use App\Modules\Notification\Models\NotificationInboundEmailScope;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Support\CanonicalNotificationPayloadAttestation;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InboundEmailWebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('ticket.view', 'web');
        Permission::findOrCreate('email.inbox_view', 'web');
    }

    #[Test]
    public function non_external_canonical_redelivery_never_queries_the_additive_outbox(): void
    {
        $user = $this->activeUser([]);
        $records = app(RecordCanonicalNotification::class);
        $identity = 'rolling-schema-non-external:'.$user->id;
        $payload = ['type' => 'rolling_schema_non_external'];

        $first = $records->handleWithStatus(
            $user,
            InboundEmailRoutedNotification::class,
            $identity,
            $payload,
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $redelivery = $records->handleWithStatus(
            $user,
            InboundEmailRoutedNotification::class,
            $identity,
            $payload,
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($redelivery['created']);
        $this->assertNull($redelivery['external_delivery_status']);
        $this->assertNull($redelivery['external_delivery_id']);
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'notification_inbound_external_deliveries'),
        ));
    }

    #[Test]
    public function linked_inbound_email_creates_one_owner_notification_without_extra_channels_by_default(): void
    {
        Notification::fake();

        $owner = $this->activeUser(['ticket.view']);
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create([
            'owner_id' => $owner->id,
            'subject' => 'VPN outage',
        ]);
        $email = $this->emailMessage($account, [
            'ticket_id' => $ticket->id,
            'state' => 'linked',
            'subject' => 'Re: VPN outage',
            'from_email' => 'customer@example.test',
            'from_name' => 'Customer Contact',
        ]);
        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'source_inbound_email_message_id' => $email->id,
            'inbound_email_message_id' => $email->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => $email->subject,
            'body' => 'Please check this again.',
            'metadata' => ['email_message_id' => $email->id],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);
        $this->advanceFanouts();

        $notifications = $owner->notifications()->get();

        $this->assertCount(1, $notifications);
        $notification = $notifications->sole();
        $this->assertSame(InboundEmailRoutedNotification::class, $notification->type);
        $this->assertSame('ticket_customer_reply_received', $notification->data['type']);
        $this->assertSame($email->id, $notification->data['email_message_id']);
        $this->assertSame($message->id, $notification->data['ticket_message_id']);
        $this->assertSame('inbound-email:'.$email->id.':user:'.$owner->id, $notification->delivery_identity);
        $this->assertNull($notification->read_at);

        Notification::assertNothingSent();
    }

    #[Test]
    public function explicit_inbox_subscriber_receives_canonical_inbox_notification_and_web_push(): void
    {
        Notification::fake();
        Queue::fake();
        config([
            'webpush.enabled' => true,
            'webpush.vapid.public_key' => str_repeat('A', 87),
            'webpush.vapid.private_key' => str_repeat('B', 43),
            'webpush.vapid.subject' => 'mailto:ops@example.test',
        ]);

        $subscriber = $this->activeUser(['email.inbox_view']);
        NotificationSetting::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => true,
            'nextcloud_talk_enabled' => false,
        ]);

        $account = $this->emailAccount();
        $this->grantMailbox($account, $subscriber);
        $email = $this->emailMessage($account, [
            'state' => 'untriaged',
            'subject' => 'Backup warning',
            'from_name' => 'Monitoring',
            'from_email' => 'alerts@example.test',
        ]);

        // Commit the fanout page, canonical notification, and outbox while
        // Queue::fake suppresses the post-commit external worker.
        $fanout = app(DispatchInboundEmailNotification::class)->handle($email->fresh());
        $this->assertNotNull($fanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $delivery = NotificationInboundExternalDelivery::query()->sole();
        $this->assertSame(NotificationInboundExternalDelivery::STATUS_PENDING, $delivery->status);

        // Source redelivery and the scheduler may enqueue duplicate internal
        // jobs, but the token-owned outbox permits one external send only.
        $this->dispatchAndAdvance($email->fresh());
        $this->dispatchAndAdvance($email->fresh());

        Queue::assertPushed(
            DeliverInboundEmailExternalNotification::class,
            fn (DeliverInboundEmailExternalNotification $job): bool => $job->deliveryId === $delivery->id,
        );
        $dispatcher = new RecordingInboundEmailExternalNotificationDispatcher;
        $this->app->instance(InboundEmailExternalNotificationDispatcher::class, $dispatcher);
        app()->call([new DeliverInboundEmailExternalNotification(
            (int) $delivery->id,
        ), 'handle']);
        app()->call([new DeliverInboundEmailExternalNotification(
            (int) $delivery->id,
        ), 'handle']);

        $notification = $subscriber->notifications()->sole();
        $delivery = $delivery->fresh();

        $this->assertSame('inbound_email_received', $notification->data['type']);
        $this->assertSame($email->id, $notification->data['email_message_id']);
        $this->assertSame(route('tech.inbox.show', $email, false), $notification->data['url']);
        $this->assertStringNotContainsString('alerts@example.test', json_encode($notification->data, JSON_THROW_ON_ERROR));
        $this->assertSame(NotificationInboundExternalDelivery::STATUS_COMPLETED, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNull($delivery->claim_token);

        $this->assertSame(1, $dispatcher->calls);
        $this->assertSame($subscriber->id, $dispatcher->userId);
        $this->assertSame($notification->id, $dispatcher->databaseNotificationId);
        $this->assertSame([
            'mail' => false,
            'web_push' => true,
            'nextcloud_talk' => false,
            'nextcloud_talk_webhook_url' => null,
        ], $dispatcher->requested);
    }

    #[Test]
    public function external_outbox_does_not_retrofit_legacy_rows_reauthorizes_and_preserves_orphans(): void
    {
        Notification::fake();
        Queue::fake();
        config([
            'webpush.enabled' => true,
            'webpush.vapid.public_key' => str_repeat('A', 87),
            'webpush.vapid.private_key' => str_repeat('B', 43),
            'webpush.vapid.subject' => 'mailto:ops@example.test',
        ]);

        $subscriber = $this->activeUser(['email.inbox_view']);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $subscriber);
        NotificationSetting::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $legacyEmail = $this->emailMessage($account);
        app(RecordCanonicalNotification::class)->handle(
            $subscriber,
            InboundEmailRoutedNotification::class,
            'inbound-email:'.$legacyEmail->id.':user:'.$subscriber->id,
            $this->externalPayload($legacyEmail, $subscriber),
        );

        $this->dispatchAndAdvance($legacyEmail);

        $this->assertSame(0, NotificationInboundExternalDelivery::query()->count());
        Queue::assertNotPushed(DeliverInboundEmailExternalNotification::class);

        $revokedEmail = $this->emailMessage($account, [
            'imap_uid' => 9002,
            'message_id' => '<message-9002@example.test>',
        ]);
        $this->dispatchAndAdvance($revokedEmail);
        $delivery = NotificationInboundExternalDelivery::query()->sole();
        EmailAccountUserGrant::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $subscriber->id)
            ->delete();

        app()->call([new DeliverInboundEmailExternalNotification($delivery->id), 'handle']);

        $this->assertSame(NotificationInboundExternalDelivery::STATUS_SUPPRESSED, $delivery->fresh()->status);
        $this->assertSame('inbound_notification_recipient_revoked', $delivery->fresh()->error_code);
        Notification::assertNothingSent();

        $this->grantMailbox($account, $subscriber);
        $lostWorkerEmail = $this->emailMessage($account, [
            'imap_uid' => 9003,
            'message_id' => '<message-9003@example.test>',
        ]);
        $this->dispatchAndAdvance($lostWorkerEmail);
        $lostDelivery = NotificationInboundExternalDelivery::query()
            ->where('status', NotificationInboundExternalDelivery::STATUS_PENDING)
            ->sole();
        $lostDelivery->forceFill([
            'status' => NotificationInboundExternalDelivery::STATUS_RUNNING,
            'claim_token' => hash('sha256', 'lost-delivery-worker'),
            'attempt_count' => 1,
            'last_attempt_at' => now()->subSeconds(
                DeliverInboundEmailExternalNotification::ABANDONED_CLAIM_SECONDS + 1,
            ),
        ])->save();
        app()->call([new DeliverInboundEmailExternalNotification($lostDelivery->id), 'handle']);
        $this->assertSame(
            NotificationInboundExternalDelivery::STATUS_UNRESOLVED,
            $lostDelivery->fresh()->status,
        );
        $this->assertSame(
            'inbound_notification_external_worker_lost',
            $lostDelivery->fresh()->error_code,
        );
        Notification::assertNothingSent();
    }

    #[Test]
    public function external_delivery_rejects_tampered_authority_payload_before_dispatch(): void
    {
        Queue::fake();
        $subscriber = $this->activeUser(['email.inbox_view']);
        $setting = NotificationSetting::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => true,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $subscriber);
        $dispatcher = new RecordingInboundEmailExternalNotificationDispatcher;
        $this->app->instance(InboundEmailExternalNotificationDispatcher::class, $dispatcher);
        $cases = [
            ['payload', 'email_message_id', true],
            ['payload', 'email_account_id', 1.0],
            ['payload', 'inbound_notification_fanout_id', '1'],
            ['payload', 'notification_setting_id', 0],
            ['identity', 'delivery_identity', 'tampered-delivery-identity'],
        ];

        foreach ($cases as $offset => [$target, $field, $malformed]) {
            $email = $this->emailMessage($account, [
                'imap_uid' => 9100 + $offset,
                'message_id' => '<malformed-authority-'.$offset.'@example.test>',
            ]);
            $fanout = $this->dispatchAndAdvance($email);
            $this->assertNotNull($fanout);
            $delivery = NotificationInboundExternalDelivery::query()
                ->where('status', NotificationInboundExternalDelivery::STATUS_PENDING)
                ->latest('id')
                ->firstOrFail();
            $canonical = $subscriber->notifications()->whereKey($delivery->notification_id)->firstOrFail();
            $payload = $canonical->data;
            $this->assertSame((int) $setting->id, (int) $payload['notification_setting_id']);
            if ($target === 'identity') {
                $canonical->forceFill(['delivery_identity' => $malformed])->save();
            } else {
                $payload[$field] = $field === 'inbound_notification_fanout_id'
                    ? (string) $fanout->id
                    : $malformed;
                DB::table('notifications')->where('id', $canonical->id)->update([
                    // Preserve a JSON REAL token for 1.0. Eloquent's normal
                    // cast canonicalizes it to integer 1, which would leave
                    // an originally valid authority payload byte-equivalent.
                    'data' => json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                    ),
                    'updated_at' => now(),
                ]);
            }

            app()->call([new DeliverInboundEmailExternalNotification((int) $delivery->id), 'handle']);

            $this->assertSame(
                NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
                $delivery->fresh()->status,
            );
            $this->assertSame(
                'inbound_notification_payload_attestation_failed',
                $delivery->fresh()->error_code,
            );
        }

        $this->assertSame(0, $dispatcher->calls);
    }

    #[Test]
    public function external_delivery_rejects_correctly_attested_non_integer_authority_ids(): void
    {
        Queue::fake();
        $subscriber = $this->activeUser(['email.inbox_view']);
        $setting = NotificationSetting::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $subscriber);
        $dispatcher = new RecordingInboundEmailExternalNotificationDispatcher;
        $this->app->instance(InboundEmailExternalNotificationDispatcher::class, $dispatcher);
        $cases = [
            ['email_message_id', true],
            ['email_account_id', 1.0],
            ['inbound_notification_fanout_id', '1'],
            ['notification_setting_id', (string) $setting->id],
            ['notification_setting_id', 0],
        ];

        foreach ($cases as $offset => [$field, $malformed]) {
            $email = $this->emailMessage($account, [
                'imap_uid' => 9200 + $offset,
                'message_id' => '<attested-malformed-authority-'.$offset.'@example.test>',
            ]);
            $fanout = NotificationInboundEmailFanout::query()->create([
                'email_message_id' => $email->id,
                'source_email_message_id' => $email->id,
                'email_account_id' => $account->id,
                'notification_setting_through_id' => $setting->id,
            ]);
            $notificationId = (string) \Illuminate\Support\Str::uuid();
            $deliveryIdentity = 'attested-malformed-authority:'.$offset;
            $payload = [
                ...$this->externalPayload($email, $subscriber),
                'delivery_identity' => $deliveryIdentity,
                'inbound_notification_fanout_id' => $fanout->id,
                'notification_setting_id' => $setting->id,
            ];
            $payload[$field] = $field === 'inbound_notification_fanout_id'
                ? (string) $fanout->id
                : $malformed;
            $canonicalJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
            $payloadHash = CanonicalNotificationPayloadAttestation::hash(
                $notificationId,
                InboundEmailRoutedNotification::class,
                $deliveryIdentity,
                User::class,
                (int) $subscriber->id,
                $canonicalJson,
            );
            DB::table('notifications')->insert([
                'id' => $notificationId,
                'type' => InboundEmailRoutedNotification::class,
                'delivery_identity' => $deliveryIdentity,
                'notifiable_type' => User::class,
                'notifiable_id' => $subscriber->id,
                'data' => $canonicalJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $delivery = NotificationInboundExternalDelivery::query()->create([
                'notification_id' => $notificationId,
                'user_id' => $subscriber->id,
                'inbound_notification_fanout_id' => $fanout->id,
                'canonical_payload_hash' => $payloadHash,
                'requested_mail' => false,
                'requested_web_push' => true,
                'requested_nextcloud_talk' => false,
                'status' => NotificationInboundExternalDelivery::STATUS_PENDING,
            ]);

            app()->call([new DeliverInboundEmailExternalNotification((int) $delivery->id), 'handle']);

            $this->assertSame(
                NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
                $delivery->fresh()->status,
            );
            $this->assertSame(
                'inbound_notification_payload_invalid',
                $delivery->fresh()->error_code,
            );
        }

        $this->assertSame(0, $dispatcher->calls);
    }

    #[Test]
    public function external_outbox_status_is_database_guarded_and_dispatch_is_scheduled(): void
    {
        $user = $this->activeUser([]);
        $account = $this->emailAccount();
        $email = $this->emailMessage($account);
        $fanout = NotificationInboundEmailFanout::query()->create([
            'email_message_id' => $email->id,
            'source_email_message_id' => $email->id,
            'email_account_id' => $account->id,
            'notification_setting_through_id' => 0,
        ]);
        $notificationId = (string) \Illuminate\Support\Str::uuid();
        $deliveryIdentity = 'external-state-guard:'.$notificationId;
        $canonicalJson = '{}';
        $payloadHash = CanonicalNotificationPayloadAttestation::hash(
            $notificationId,
            InboundEmailRoutedNotification::class,
            $deliveryIdentity,
            User::class,
            (int) $user->id,
            $canonicalJson,
        );
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => InboundEmailRoutedNotification::class,
            'delivery_identity' => $deliveryIdentity,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => $canonicalJson,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            DB::table('notification_inbound_external_deliveries')->insert([
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'inbound_notification_fanout_id' => $fanout->id,
                'canonical_payload_hash' => $payloadHash,
                'requested_web_push' => true,
                'status' => 'invented',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject an unknown external-delivery status.');
        } catch (QueryException) {
            $this->assertSame(0, NotificationInboundExternalDelivery::query()->count());
        }

        $delivery = NotificationInboundExternalDelivery::query()->create([
            'notification_id' => $notificationId,
            'user_id' => $user->id,
            'inbound_notification_fanout_id' => $fanout->id,
            'canonical_payload_hash' => $payloadHash,
            'requested_web_push' => true,
            'status' => NotificationInboundExternalDelivery::STATUS_PENDING,
        ]);
        try {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $delivery->id)
                ->update(['status' => 'invented']);
            $this->fail('The database must reject an unknown external-delivery status update.');
        } catch (QueryException) {
            $this->assertSame(
                NotificationInboundExternalDelivery::STATUS_PENDING,
                $delivery->fresh()->status,
            );
        }

        $invalidMailNotificationId = (string) \Illuminate\Support\Str::uuid();
        $invalidDeliveryIdentity = 'external-state-guard:'.$invalidMailNotificationId;
        $invalidPayloadHash = CanonicalNotificationPayloadAttestation::hash(
            $invalidMailNotificationId,
            InboundEmailRoutedNotification::class,
            $invalidDeliveryIdentity,
            User::class,
            (int) $user->id,
            '{}',
        );
        DB::table('notifications')->insert([
            'id' => $invalidMailNotificationId,
            'type' => InboundEmailRoutedNotification::class,
            'delivery_identity' => $invalidDeliveryIdentity,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            DB::table('notification_inbound_external_deliveries')->insert([
                'notification_id' => $invalidMailNotificationId,
                'user_id' => $user->id,
                'inbound_notification_fanout_id' => $fanout->id,
                'canonical_payload_hash' => $invalidPayloadHash,
                'requested_mail' => true,
                'mail_scope' => 'sales',
                'mail_account_id' => 1,
                'mail_provider_binding_version' => 1,
                'status' => NotificationInboundExternalDelivery::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject an unrelated Mail scope.');
        } catch (QueryException) {
            $this->assertSame(1, NotificationInboundExternalDelivery::query()->count());
        }

        $migration = require database_path(
            'migrations/2026_08_16_118200_add_inbound_notification_external_outbox.php',
        );
        try {
            $migration->down();
            $this->fail('Rollback must preserve retained external-delivery evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Inbound notification external-delivery evidence must be preserved before schema rollback.',
                $exception->getMessage(),
            );
            $this->assertTrue(Schema::hasTable('notification_inbound_external_deliveries'));
        }

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'notification.inbound_email.external_dispatch');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[Test]
    public function ticket_source_view_marks_matching_notifications_read_without_touching_ticket_message_read_state(): void
    {
        $user = $this->activeUser(['ticket.view']);
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);
        $email = $this->emailMessage($account, ['ticket_id' => $ticket->id, 'state' => 'linked']);
        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'source_inbound_email_message_id' => $email->id,
            'inbound_email_message_id' => $email->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Customer reply',
            'body' => 'Reply body',
            'metadata' => ['email_message_id' => $email->id],
            'read_at' => null,
        ]);

        $this->dispatchAndAdvance($email);

        $notification = $user->notifications()->sole();
        $this->assertNull($notification->read_at);
        $unrelated = $this->emailMessage($account, [
            'imap_uid' => 9011,
            'message_id' => '<forged-ticket-read-target@example.test>',
            'subject' => 'Unrelated notification',
        ]);
        app(RecordCanonicalNotification::class)->handle(
            $user,
            InboundEmailRoutedNotification::class,
            'inbound-email:'.$unrelated->id.':user:'.$user->id,
            $this->externalPayload($unrelated, $user),
        );
        $messageBefore = (array) DB::table('ticket_messages')->where('id', $message->id)->first();
        try {
            DB::table('ticket_messages')->where('id', $message->id)->update([
                'metadata' => json_encode(
                    ['email_message_id' => $unrelated->id],
                    JSON_THROW_ON_ERROR,
                ),
                'updated_at' => now(),
            ]);
            $this->fail('Frozen Ticket-message source metadata cannot be reparented.');
        } catch (QueryException) {
            $this->assertSame(
                $messageBefore,
                (array) DB::table('ticket_messages')->where('id', $message->id)->first(),
            );
        }
        $unrelatedNotification = $user->notifications()
            ->where('id', '!=', $notification->id)
            ->sole();

        $this->actingAs($user)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('nexum-close-notifications');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($unrelatedNotification->fresh()->read_at);
        $this->assertNull($message->fresh()->read_at);
    }

    #[Test]
    public function inbox_source_view_marks_only_current_users_exact_email_notification_read(): void
    {
        $user = $this->activeUser(['email.inbox_view']);
        $other = $this->activeUser(['email.inbox_view']);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $user);
        $this->grantMailbox($account, $other);
        $email = $this->emailMessage($account);
        $unrelated = $this->emailMessage($account, ['imap_uid' => 9002, 'subject' => 'Other']);

        foreach ([$user, $other] as $recipient) {
            NotificationSetting::query()->create([
                'user_id' => $recipient->id,
                'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
                'mail_enabled' => false,
                'database_enabled' => true,
                'web_push_enabled' => false,
                'web_push_preview_enabled' => false,
                'nextcloud_talk_enabled' => false,
            ]);
        }

        $this->dispatchAndAdvance($email);
        $this->dispatchAndAdvance($unrelated);

        $target = $user->notifications()
            ->get()
            ->firstOrFail(fn ($notification): bool => (int) $notification->data['email_message_id'] === (int) $email->id);
        $otherUsersNotification = $other->notifications()
            ->get()
            ->firstOrFail(fn ($notification): bool => (int) $notification->data['email_message_id'] === (int) $email->id);
        $unrelatedNotification = $user->notifications()
            ->get()
            ->firstOrFail(fn ($notification): bool => (int) $notification->data['email_message_id'] === (int) $unrelated->id);

        $this->actingAs($user)
            ->get(route('tech.inbox.show', $email))
            ->assertOk()
            ->assertSee('nexum-close-notifications');

        $this->assertNotNull($target->fresh()->read_at);
        $this->assertNull($otherUsersNotification->fresh()->read_at);
        $this->assertNull($unrelatedNotification->fresh()->read_at);
        $this->assertSame('untriaged', $email->fresh()->state);
    }

    #[Test]
    public function notification_open_and_ticket_read_sync_follow_an_inbox_email_that_was_later_linked_to_ticket(): void
    {
        $user = $this->activeUser(['email.inbox_view', 'ticket.view']);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $user);
        $email = $this->emailMessage($account, ['state' => 'untriaged']);

        NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);

        $this->dispatchAndAdvance($email);

        $notification = $user->notifications()->sole();
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);
        $ticketMessage = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'source_inbound_email_message_id' => $email->id,
            'inbound_email_message_id' => $email->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => $email->subject,
            'body' => 'Linked later.',
            'metadata' => ['email_message_id' => $email->id],
        ]);
        $email->forceFill([
            'ticket_id' => $ticket->id,
            'state' => 'linked',
        ])->save();

        $this->actingAs($user)
            ->get(route('tech.profile.notifications.open', $notification))
            ->assertRedirect(route('tech.tickets.show', $ticket, false));

        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($user)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('nexum-close-notifications');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($ticketMessage->fresh()->read_at);
    }

    #[Test]
    public function stored_inbound_email_scopes_restrict_only_the_inbox_subscriber_path(): void
    {
        $subscriber = $this->activeUser(['email.inbox_view']);
        $firstAccount = $this->emailAccount();
        $secondAccount = $this->emailAccount([
            'address' => 'secondary@example.test',
            'imap_username' => 'secondary@example.test',
            'smtp_username' => 'secondary@example.test',
        ]);
        $this->grantMailbox($firstAccount, $subscriber);
        $this->grantMailbox($secondAccount, $subscriber);
        NotificationSetting::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        NotificationInboundEmailScope::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'scope_kind' => NotificationInboundEmailScope::KIND_EMAIL_ACCOUNT,
            'scope_id' => $firstAccount->id,
        ]);

        $this->dispatchAndAdvance($this->emailMessage($firstAccount));
        $this->dispatchAndAdvance($this->emailMessage($secondAccount, [
            'imap_uid' => 9101,
            'message_id' => '<message-9101@example.test>',
            'subject' => 'Second account',
        ]));

        $this->assertSame(1, $subscriber->notifications()->count());
        $this->assertSame($firstAccount->id, $subscriber->notifications()->sole()->data['email_account_id']);
    }

    #[Test]
    public function inbox_subscriber_without_mailbox_grant_does_not_receive_inbound_email_notification(): void
    {
        $subscriber = $this->activeUser(['email.inbox_view']);
        $account = $this->emailAccount();
        $email = $this->emailMessage($account);

        NotificationSetting::query()->create([
            'user_id' => $subscriber->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);

        $this->dispatchAndAdvance($email);

        $this->assertSame(0, $subscriber->notifications()->count());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function activeUser(array $permissions): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function emailAccount(array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'address' => 'support@example.test',
            'description' => 'Support',
            'from_name' => 'Support',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => true,
            'defaults_for' => ['tickets'],
            'ticket_ingress_enabled' => true,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ], $overrides));
    }

    private function grantMailbox(EmailAccount $account, User $user): void
    {
        EmailAccountUserGrant::query()->updateOrCreate(
            [
                'email_account_id' => $account->id,
                'user_id' => $user->id,
            ],
            [
                'can_view' => true,
                'can_organize' => true,
                'can_send' => false,
                'granted_at' => now(),
            ],
        );
    }

    private function dispatchAndAdvance(EmailMessage $email): ?NotificationInboundEmailFanout
    {
        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        if ($fanout) {
            app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        }

        return $fanout?->fresh();
    }

    private function advanceFanouts(): void
    {
        NotificationInboundEmailFanout::query()
            ->whereIn('status', [
                NotificationInboundEmailFanout::STATUS_PENDING,
                NotificationInboundEmailFanout::STATUS_RUNNING,
            ])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (mixed $id) => app(DispatchInboundEmailNotification::class)->advance((int) $id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function emailMessage(EmailAccount $account, array $overrides = []): EmailMessage
    {
        $message = EmailMessage::query()->create(array_merge([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 9001,
            'message_id' => '<message-9001@example.test>',
            'subject' => 'Customer reply',
            'from_name' => 'Customer Contact',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => 'support@example.test']],
            'cc_json' => [],
            'headers_json' => [],
            'received_at' => Carbon::parse('2026-08-11 09:00', 'Europe/Oslo')->utc(),
            'size_bytes' => 1024,
            'is_oversize' => false,
            'state' => 'untriaged',
            'labels_json' => [],
            'body_text' => 'Please help.',
            'attachments_count' => 0,
            'checksum_sha1' => sha1(json_encode($overrides)),
        ], $overrides));

        $folder = EmailFolder::query()->firstOrCreate(
            [
                'account_id' => $account->id,
                'path' => 'INBOX',
            ],
            [
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => 1,
            ],
        );
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 1,
            'imap_uid' => $message->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'provider_missing_at' => null,
        ]);

        return $message;
    }

    /** @return array<string, mixed> */
    private function externalPayload(EmailMessage $email, User $user): array
    {
        return [
            'type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'delivery_identity' => 'inbound-email:'.$email->id.':user:'.$user->id,
            'title' => 'New inbound Email',
            'ticket_id' => null,
            'ticket_key' => null,
            'ticket_message_id' => null,
            'ticket_queue_id' => null,
            'email_message_id' => $email->id,
            'email_account_id' => $email->account_id,
            'source_type' => 'email_message',
            'source_id' => $email->id,
            'source_label' => 'Inbox email',
            'url' => route('tech.inbox.show', $email, false),
            'action_label' => 'Open Email',
            'mail_summary' => 'A new inbound email is available in the Nexum inbox.',
            'push_title' => 'Nexum',
            'push_body' => 'A new inbound email is ready in Nexum.',
            'preview_sender_name' => (string) $email->from_name,
            'preview_subject' => (string) $email->subject,
            'web_push_tag' => 'nexum-inbound-'.$email->id.'-'.$user->id,
        ];
    }
}

class RecordingInboundEmailExternalNotificationDispatcher implements InboundEmailExternalNotificationDispatcher
{
    public int $calls = 0;

    public ?int $userId = null;

    public ?string $databaseNotificationId = null;

    /** @var null|array{mail:bool,web_push:bool,nextcloud_talk:bool,nextcloud_talk_webhook_url?:?string} */
    public ?array $requested = null;

    public function deliver(
        User $user,
        InboundEmailRoutedNotification $notification,
        array $requested,
    ): array {
        $this->calls++;
        $this->userId = (int) $user->id;
        $this->databaseNotificationId = $notification->databaseNotificationId;
        $this->requested = $requested;

        return [
            'status' => NotificationInboundExternalDelivery::STATUS_COMPLETED,
            'reason_code' => null,
        ];
    }
}
