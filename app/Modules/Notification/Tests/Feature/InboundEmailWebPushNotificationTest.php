<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\Actions\ResolveInboundEmailNotificationRecipients;
use App\Modules\Notification\Models\NotificationInboundEmailScope;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
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
        $email = $this->emailMessage($account, [
            'state' => 'untriaged',
            'subject' => 'Backup warning',
            'from_name' => 'Monitoring',
            'from_email' => 'alerts@example.test',
        ]);

        app(DispatchInboundEmailNotification::class)->handle($email);
        app(DispatchInboundEmailNotification::class)->handle($email->fresh());

        $notification = $subscriber->notifications()->sole();

        $this->assertSame('inbound_email_received', $notification->data['type']);
        $this->assertSame($email->id, $notification->data['email_message_id']);
        $this->assertSame(route('tech.inbox.show', $email, false), $notification->data['url']);
        $this->assertStringNotContainsString('alerts@example.test', json_encode($notification->data, JSON_THROW_ON_ERROR));

        Notification::assertSentTo(
            $subscriber,
            InboundEmailRoutedNotification::class,
            fn (InboundEmailRoutedNotification $sent, array $channels): bool => in_array(WebPushChannel::class, $channels, true)
                && $sent->databaseNotificationId === $notification->id
        );
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
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Customer reply',
            'body' => 'Reply body',
            'metadata' => ['email_message_id' => $email->id],
            'read_at' => null,
        ]);

        app(DispatchInboundEmailNotification::class)->handle($email);

        $notification = $user->notifications()->sole();
        $this->assertNull($notification->read_at);

        $this->actingAs($user)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('nexum-close-notifications');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($message->fresh()->read_at);
    }

    #[Test]
    public function inbox_source_view_marks_only_current_users_exact_email_notification_read(): void
    {
        $user = $this->activeUser(['email.inbox_view']);
        $other = $this->activeUser(['email.inbox_view']);
        $account = $this->emailAccount();
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

        app(DispatchInboundEmailNotification::class)->handle($email);
        app(DispatchInboundEmailNotification::class)->handle($unrelated);

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

        app(DispatchInboundEmailNotification::class)->handle($email);

        $notification = $user->notifications()->sole();
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);
        $ticketMessage = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
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

        app(DispatchInboundEmailNotification::class)->handle($this->emailMessage($firstAccount));
        app(DispatchInboundEmailNotification::class)->handle($this->emailMessage($secondAccount, [
            'imap_uid' => 9101,
            'message_id' => '<message-9101@example.test>',
            'subject' => 'Second account',
        ]));

        $this->assertSame(1, $subscriber->notifications()->count());
        $this->assertSame($firstAccount->id, $subscriber->notifications()->sole()->data['email_account_id']);
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
            'is_active' => true,
            'is_global_default' => true,
            'defaults_for' => ['tickets'],
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function emailMessage(EmailAccount $account, array $overrides = []): EmailMessage
    {
        return EmailMessage::query()->create(array_merge([
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
    }
}
