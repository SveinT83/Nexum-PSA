<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailProviderSendOutcomeUnresolvedException;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Notification\Channels\EmailAccountMailChannel;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Exceptions\EmailAccountNotificationDeliveryException;
use App\Modules\Notification\Notifications\EmailAccountResetPasswordNotification;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use SensitiveParameter;
use Tests\TestCase;

class EmailAccountMailChannelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exact_frozen_account_binding_renders_and_sends_without_the_system_mailer(): void
    {
        $account = $this->account(['system']);
        $mailer = $this->recordingMailer();
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();

        $notification = new TestQueuedEmailAccountMailNotification('system');
        $notifiable = (new AnonymousNotifiable)->route('mail', [
            'recipient@example.test' => 'Recipient',
        ]);

        $this->assertSame([EmailAccountMailChannel::class], $notification->via($notifiable));
        $snapshot = $notification->emailAccountMailSnapshot();
        $this->assertTrue($snapshot['captured']);
        $this->assertSame($account->id, $snapshot['account_id']);
        $this->assertSame(1, $snapshot['provider_binding_version']);

        $result = app(EmailAccountMailChannel::class)->send($notifiable, $notification);

        $this->assertSame('delivered', $result['status']);
        $this->assertSame(1, $mailer->calls);
        $this->assertSame($account->id, $mailer->accountId);
        $this->assertSame(1, $mailer->bindingVersion);
        $this->assertSame('Strict notification subject', $mailer->subject);
        $this->assertStringContainsString('Strict notification body', $mailer->html);
        $this->assertSame([
            ['email' => 'recipient@example.test', 'name' => 'Recipient'],
        ], $mailer->recipients);
        Mail::assertNothingSent();
    }

    #[Test]
    public function laravel_notification_sender_delivers_the_pre_frozen_synchronous_clone(): void
    {
        $account = $this->account(['system']);
        $mailer = $this->recordingMailer();
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'sync@example.test');

        NotificationFacade::send($notifiable, new TestEmailAccountMailNotification('system'));

        $this->assertSame(1, $mailer->calls);
        $this->assertSame($account->id, $mailer->accountId);
        $this->assertSame([
            ['email' => 'sync@example.test', 'name' => ''],
        ], $mailer->recipients);
        Mail::assertNothingSent();
    }

    #[Test]
    public function queued_notification_payload_is_frozen_before_dispatch_and_rebind_never_replays(): void
    {
        $account = $this->account(['system']);
        $mailer = $this->recordingMailer();
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        Queue::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'queued@example.test');

        NotificationFacade::send($notifiable, new TestQueuedEmailAccountMailNotification('system'));

        $queued = null;
        Queue::assertPushed(
            SendQueuedNotifications::class,
            function (SendQueuedNotifications $job) use (&$queued, $account): bool {
                $snapshot = $job->notification->emailAccountMailSnapshot();
                $queued = $job;

                return $snapshot['captured']
                    && $snapshot['account_id'] === $account->id
                    && $snapshot['provider_binding_version'] === 1;
            },
        );
        $this->assertInstanceOf(SendQueuedNotifications::class, $queued);
        $serialized = serialize($queued);
        $this->assertStringNotContainsString('legacy-provider-secret', $serialized);
        $this->assertStringNotContainsString('8.8.8.8', $serialized);

        $account->forceFill(['provider_binding_version' => 2])->save();
        $queued->handle(app(ChannelManager::class));

        $this->assertSame(0, $mailer->calls);
        Mail::assertNothingSent();
    }

    #[Test]
    public function queued_snapshot_does_not_adopt_a_rebound_or_revoked_provider(): void
    {
        $account = $this->account(['system']);
        $mailer = $this->recordingMailer();
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'recipient@example.test');
        $notification = new TestQueuedEmailAccountMailNotification('system');

        $notification->via($notifiable);
        $serialized = serialize($notification);
        $this->assertStringNotContainsString('legacy-provider-secret', $serialized);
        $this->assertStringNotContainsString('8.8.8.8', $serialized);
        $this->assertStringNotContainsString('1.1.1.1', $serialized);

        $account->forceFill(['provider_binding_version' => 2])->save();
        $rebound = app(EmailAccountMailChannel::class)->send($notifiable, unserialize($serialized));
        $this->assertSame('blocked', $rebound['status']);
        $this->assertSame('provider_binding_stale', $rebound['reason_code']);

        $account->forceFill([
            'provider_binding_version' => 1,
            'is_active' => false,
        ])->save();
        $revoked = app(EmailAccountMailChannel::class)->send($notifiable, unserialize($serialized));
        $this->assertSame('blocked', $revoked['status']);
        $this->assertSame('provider_account_selection_stale', $revoked['reason_code']);
        $this->assertSame(0, $mailer->calls);
        Mail::assertNothingSent();
    }

    #[Test]
    public function ambiguous_smtp_outcome_is_terminal_for_the_notification_attempt(): void
    {
        $this->account(['system']);
        $mailer = $this->recordingMailer(unresolved: true);
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'recipient@example.test');
        $notification = new TestQueuedEmailAccountMailNotification('system');
        $notification->via($notifiable);

        $result = app(EmailAccountMailChannel::class)->send($notifiable, $notification);

        $this->assertSame('unresolved', $result['status']);
        $this->assertSame('smtp_send_outcome_unresolved', $result['reason_code']);
        $this->assertSame(1, $mailer->calls);
        Mail::assertNothingSent();
    }

    #[Test]
    public function synchronous_missing_provider_failure_is_visible_and_sanitized(): void
    {
        $mailer = $this->recordingMailer();
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'recipient@example.test');

        try {
            NotificationFacade::send($notifiable, new TestEmailAccountMailNotification('system'));
            $this->fail('A synchronous notification may not claim success without a provider.');
        } catch (EmailAccountNotificationDeliveryException $exception) {
            $this->assertSame('provider_binding_snapshot_missing', $exception->reasonCode);
            $this->assertStringNotContainsString('recipient@example.test', $exception->getMessage());
        }

        $this->assertSame(0, $mailer->calls);
        Mail::assertNothingSent();
    }

    #[Test]
    public function synchronous_ambiguous_outcome_is_visible_but_carries_no_replayable_provider_error(): void
    {
        $this->account(['system']);
        $mailer = $this->recordingMailer(unresolved: true);
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'recipient@example.test');

        try {
            NotificationFacade::send($notifiable, new TestEmailAccountMailNotification('system'));
            $this->fail('An unresolved synchronous SMTP result must be surfaced honestly.');
        } catch (EmailAccountNotificationDeliveryException $exception) {
            $this->assertSame('smtp_send_outcome_unresolved', $exception->reasonCode);
            $this->assertStringNotContainsString('recipient@example.test', $exception->getMessage());
        }

        $this->assertSame(1, $mailer->calls);
        Mail::assertNothingSent();
    }

    #[Test]
    public function missing_provider_is_visible_as_blocked_without_invoking_any_mail_transport(): void
    {
        $mailer = $this->recordingMailer();
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        Mail::fake();
        $notifiable = (new AnonymousNotifiable)->route('mail', 'recipient@example.test');
        $notification = new TestQueuedEmailAccountMailNotification('system');

        $notification->via($notifiable);
        $result = app(EmailAccountMailChannel::class)->send($notifiable, $notification);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('provider_binding_snapshot_missing', $result['reason_code']);
        $this->assertSame(0, $mailer->calls);
        Mail::assertNothingSent();
    }

    #[Test]
    public function every_application_mail_notification_and_password_reset_use_the_strict_channel_contract(): void
    {
        $paths = [
            app_path('Modules/Notification/Notifications/InboundEmailRoutedNotification.php'),
            app_path('Modules/Notification/Notifications/CustomerPortalNotification.php'),
            app_path('Modules/Notification/Notifications/TicketAssigned.php'),
            app_path('Modules/Notification/Notifications/TicketStatusChanged.php'),
            app_path('Modules/Notification/Notifications/TicketCommentAdded.php'),
            app_path('Modules/Notification/Notifications/AssetAlertTriggered.php'),
            app_path('Modules/Notification/Notifications/TicketSlaWarning.php'),
            app_path('Modules/Storage/Notifications/SupplierOrderImportDailyDigestNotification.php'),
            app_path('Modules/Storage/Notifications/SupplierOrderImportExceptionNotification.php'),
            app_path('Modules/Booking/Notifications/BookingRequestReceived.php'),
            app_path('Modules/Booking/Notifications/BookingRequestConfirmed.php'),
            app_path('Modules/Booking/Notifications/BookingRequestDeclined.php'),
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringContainsString('EmailAccountMailNotification', $source, $path);
            $this->assertStringContainsString('RoutesEmailThroughAccount', $source, $path);
            $this->assertStringContainsString('freezeEmailAccountMailSnapshot', $source, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/channels\[\]\s*=\s*[\'\"]mail[\'\"]|return\s*\[\s*[\'\"]mail[\'\"]\s*\]/',
                $source,
                $path,
            );
        }

        $this->account(['system']);
        NotificationFacade::fake();
        $user = User::query()->create([
            'name' => 'Reset Recipient',
            'email' => 'reset@example.test',
            'password' => 'irrelevant-test-password',
            'status' => User::STATUS_ACTIVE,
        ]);

        $user->sendPasswordResetNotification('reset-token-canary');

        NotificationFacade::assertSentTo(
            $user,
            EmailAccountResetPasswordNotification::class,
            function (EmailAccountResetPasswordNotification $notification) use ($user): bool {
                return $notification->via($user) === [EmailAccountMailChannel::class]
                    && $notification->emailAccountMailSnapshot()['account_id'] !== null;
            },
        );
    }

    #[Test]
    public function notification_content_and_reset_token_are_hidden_from_exception_trace_arguments(): void
    {
        $send = new ReflectionMethod(EmailAccountMailChannel::class, 'send');
        foreach ($send->getParameters() as $parameter) {
            $this->assertCount(
                1,
                $parameter->getAttributes(SensitiveParameter::class),
                $parameter->getName().' must be hidden from exception traces.',
            );
        }

        $reset = new ReflectionMethod(EmailAccountResetPasswordNotification::class, '__construct');
        $this->assertCount(1, $reset->getParameters()[0]->getAttributes(SensitiveParameter::class));
    }

    private function account(array $scopes): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => 'notification-provider@example.test',
            'description' => 'Strict Notification provider',
            'from_name' => 'Nexum',
            'account_kind' => EmailAccount::KIND_SYSTEM,
            'is_active' => true,
            'is_global_default' => true,
            'defaults_for' => $scopes,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'notification-provider@example.test',
            'imap_secret' => Crypt::encryptString('legacy-provider-secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'notification-provider@example.test',
            'smtp_secret' => Crypt::encryptString('legacy-provider-secret'),
            'smtp_auth_type' => 'password',
        ]);
    }

    private function recordingMailer(bool $unresolved = false): SmtpAccountMailer
    {
        return new class($unresolved) extends SmtpAccountMailer
        {
            public int $calls = 0;

            public ?int $accountId = null;

            public ?int $bindingVersion = null;

            public ?string $subject = null;

            public ?string $html = null;

            /** @var array<int, array{email: string, name?: string|null}> */
            public array $recipients = [];

            public function __construct(private readonly bool $unresolved) {}

            public function sendMessage(
                EmailAccount $account,
                array $toRecipients,
                string $subject,
                string $html,
                string $text,
                array $attachments = [],
                array $ccRecipients = [],
                array $options = [],
            ): string {
                $this->calls++;
                $this->accountId = (int) $account->id;
                $this->bindingVersion = $options['provider_binding_version'] ?? null;
                $this->subject = $subject;
                $this->html = $html;
                $this->recipients = $toRecipients;

                if ($this->unresolved) {
                    throw new EmailProviderSendOutcomeUnresolvedException('safe unresolved');
                }

                return '<strict-notification@example.test>';
            }
        };
    }
}

class TestEmailAccountMailNotification extends Notification implements EmailAccountMailNotification
{
    use Queueable, RoutesEmailThroughAccount;

    public function __construct(private readonly string $scope)
    {
        $this->freezeEmailAccountMailSnapshot($scope);
    }

    public function via(object $notifiable): array
    {
        return [$this->emailAccountMailChannel($this->scope)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Strict notification subject')
            ->line('Strict notification body');
    }
}

final class TestQueuedEmailAccountMailNotification extends TestEmailAccountMailNotification implements ShouldQueue {}
