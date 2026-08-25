<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\EmailProviderDraftSyncService;
use App\Modules\Email\Services\EmailSendOutcomeUnresolvedException;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\EmailSignatureRenderer;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Tests\TestCase;

class EmailPostSmtpSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private ?string $blockedStorageRoot = null;

    private mixed $originalStorageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::create(['name' => 'Mail post SMTP safety tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Mail post SMTP safety tech');
    }

    protected function tearDown(): void
    {
        if ($this->originalStorageRoot !== null) {
            config()->set('filesystems.disks.'.EmailPrivateStorage::DISK.'.root', $this->originalStorageRoot);
            Storage::forgetDisk(EmailPrivateStorage::DISK);
        }

        if ($this->blockedStorageRoot !== null && is_file($this->blockedStorageRoot)) {
            unlink($this->blockedStorageRoot);
        }

        parent::tearDown();
    }

    #[Test]
    public function smtp_acceptance_stays_sent_when_the_local_sent_snapshot_cannot_be_written(): void
    {
        $account = $this->sendableAccount();
        $this->blockEmailPrivateStorageRoot();

        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                return '<accepted-before-storage-failure@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Post SMTP safety')
            ->set('composerBodyHtml', '<p>The provider accepts this message once.</p>')
            ->call('saveComposerDraft', false)
            ->call('sendComposer')
            ->assertSet('composerOpen', false)
            ->assertSet('mailActionStatus.type', 'warning')
            ->assertSee('Message sent from post-smtp-safety@example.test.')
            ->assertSee('local Sent snapshot could not be stored')
            ->assertSee('Do not resend it.')
            ->assertDontSee('could not be sent');

        $this->assertSame(1, $mailer->calls);

        $log = EmailLog::query()->where('code', 'MAIL_COMPOSE_SENT')->sole();
        $reconciliation = EmailSentReconciliation::query()->sole();
        $draft = EmailComposerDraft::query()->sole();

        $this->assertSame('info', $log->level);
        $this->assertSame('failed', data_get($log->context_json, 'provider_sent.snapshot_status'));
        $this->assertSame('EMAIL_PRIVATE_STORAGE_WRITE_FAILED', data_get($log->context_json, 'provider_sent.snapshot_error_code'));
        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $reconciliation->status);
        $this->assertSame('failed', data_get($reconciliation->context_json, 'sent_raw_snapshot.status'));
        $this->assertArrayNotHasKey('sent_raw_path', $reconciliation->context_json ?? []);
        $this->assertSame(EmailComposerDraft::STATUS_SENT, $draft->status);

        $submission = EmailOutboundSubmission::query()->sole();
        app(SendEmailComposerMessage::class)->handleNew($account, $this->actor, [
            'to' => 'customer@example.test',
            'subject' => 'Post SMTP safety',
            'body_html' => '<p>The provider accepts this message once.</p>',
            'idempotency_key' => 'submission-'.$submission->public_id,
        ]);

        $this->assertSame(1, $mailer->calls, 'An idempotent retry must not call SMTP again.');
        $this->assertSame(1, EmailLog::query()->where('code', 'MAIL_COMPOSE_SENT')->count());
    }

    #[Test]
    public function smtp_acceptance_stays_sent_when_the_reconciliation_record_itself_fails(): void
    {
        $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);

        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public ?string $providerSentStatusBeforeDelivery = null;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                $this->providerSentStatusBeforeDelivery = data_get(
                    EmailLog::query()->sole()->context_json,
                    'provider_sent.status',
                );

                return '<accepted-before-reconciliation-failure@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $this->app->instance(
            EmailSentReconciliationService::class,
            new class(app(EmailPrivateStorage::class)) extends EmailSentReconciliationService
            {
                public function recordPending(EmailLog $log, ?EmailMailboxPlacement $sourcePlacement = null, array $sentPayload = []): ?EmailSentReconciliation
                {
                    throw new RuntimeException('database credentials must never reach the user');
                }
            },
        );

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Reconciliation record safety')
            ->set('composerBodyHtml', '<p>The accepted message remains sent.</p>')
            ->call('saveComposerDraft', false)
            ->call('sendComposer')
            ->assertSet('composerOpen', false)
            ->assertSet('mailActionStatus.type', 'warning')
            ->assertSee('Sent-folder tracking could not be recorded')
            ->assertSee('Do not resend it.')
            ->assertDontSee('database credentials')
            ->assertDontSee('could not be sent');

        $this->assertSame(1, $mailer->calls);
        $this->assertSame('reservation_failed', $mailer->providerSentStatusBeforeDelivery);
        $this->assertDatabaseCount('email_sent_reconciliations', 0);
        $this->assertSame(EmailComposerDraft::STATUS_SENT, EmailComposerDraft::query()->sole()->status);

        $log = EmailLog::query()->sole();
        $this->assertSame('warning', $log->level);
        $this->assertSame('record_failed', data_get($log->context_json, 'provider_sent.status'));
        $this->assertSame('SENT_RECONCILIATION_RECORD_FAILED', data_get($log->context_json, 'provider_sent.error_code'));
    }

    #[Test]
    public function atomic_reservation_blocks_a_reentrant_send_and_stashes_message_id_before_smtp(): void
    {
        $account = $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $payload = [
            'to' => 'customer@example.test',
            'subject' => 'Atomic send reservation',
            'body_html' => '<p>Deliver this message exactly once.</p>',
            'idempotency_key' => 'atomic-reservation',
        ];

        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public int $blockedReentries = 0;

            public bool $reservationVisibleBeforeProvider = false;

            public bool $reconciliationVisibleBeforeProvider = false;

            public array $options = [];

            public ?\Closure $reenter = null;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                $this->options = $options;
                $reservation = EmailLog::query()
                    ->where('rfc_message_id', $options['message_id'] ?? null)
                    ->first();
                $this->reservationVisibleBeforeProvider = $reservation?->code === 'MAIL_COMPOSE_SEND_RESERVED';
                $this->reconciliationVisibleBeforeProvider = $reservation
                    ? EmailSentReconciliation::query()->where('email_log_id', $reservation->id)->exists()
                    : false;
                ($this->reenter)();

                return (string) $options['message_id'];
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $mailer->reenter = function () use ($account, $payload, $mailer): void {
            try {
                app(SendEmailComposerMessage::class)->handleNew($account, $this->actor, $payload);
                $this->fail('The reentrant request must be blocked before a second SMTP call.');
            } catch (EmailSendOutcomeUnresolvedException) {
                $mailer->blockedReentries++;
            }
        };

        $log = app(SendEmailComposerMessage::class)->handleNew($account, $this->actor, $payload);

        $this->assertSame(1, $mailer->calls);
        $this->assertSame(1, $mailer->blockedReentries);
        $this->assertTrue($mailer->reservationVisibleBeforeProvider);
        $this->assertTrue($mailer->reconciliationVisibleBeforeProvider);
        $this->assertNotEmpty($mailer->options['message_id'] ?? null);
        $this->assertSame($mailer->options['message_id'], $log->rfc_message_id);
        $this->assertSame('MAIL_COMPOSE_SENT', $log->code);
        $this->assertSame('accepted', data_get($log->context_json, 'smtp_delivery.status'));
        $this->assertSame(1, EmailLog::query()->count());
        $this->assertSame(1, EmailSentReconciliation::query()->count());
    }

    #[Test]
    public function unresolved_provider_outcome_keeps_one_reservation_and_blocks_retry(): void
    {
        $account = $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $payload = [
            'to' => 'customer@example.test',
            'subject' => 'Unresolved provider outcome',
            'body_html' => '<p>Do not duplicate this message.</p>',
            'idempotency_key' => 'unresolved-provider-outcome',
        ];

        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                throw new RuntimeException('secret provider response after DATA');
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $messages = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(SendEmailComposerMessage::class)->handleNew($account, $this->actor, $payload);
                $this->fail('An unresolved send must not return as accepted.');
            } catch (EmailSendOutcomeUnresolvedException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame(1, $mailer->calls);
        $this->assertCount(2, $messages);
        $this->assertStringNotContainsString('secret provider response', implode(' ', $messages));
        $this->assertStringContainsString('Do not resend', $messages[0]);
        $this->assertStringContainsString('No second send was attempted', $messages[1]);

        $log = EmailLog::query()->sole();
        $this->assertSame('MAIL_COMPOSE_SEND_UNRESOLVED', $log->code);
        $this->assertSame('unresolved', data_get($log->context_json, 'smtp_delivery.status'));
        $this->assertNotEmpty($log->rfc_message_id);
        $this->assertSame(1, EmailSentReconciliation::query()->count());
    }

    #[Test]
    public function unresolved_provider_outcome_is_shown_as_a_safe_composer_warning(): void
    {
        $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                throw new RuntimeException('secret provider response after DATA');
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Safe unresolved warning')
            ->set('composerBodyHtml', '<p>Keep this composer open for review.</p>')
            ->call('sendComposer')
            ->assertSet('composerOpen', true)
            ->assertSet('mailActionStatus.type', 'warning')
            ->assertSee('provider send outcome could not be confirmed')
            ->assertSee('Do not resend it')
            ->assertDontSee('secret provider response')
            ->assertDontSee('could not be sent');

        $component
            ->call('sendComposer')
            ->assertSet('composerOpen', true)
            ->assertSet('mailActionStatus.type', 'warning')
            ->assertSee('No second send was attempted');

        $this->assertSame(1, $mailer->calls);
    }

    #[Test]
    public function pre_provider_composer_failure_does_not_expose_internal_exception_details(): void
    {
        $this->sendableAccount();
        $this->app->instance(
            SendEmailComposerMessage::class,
            new class(app(MailboxAccess::class), app(SmtpAccountMailer::class), app(EmailSignatureRenderer::class), app(EmailSentReconciliationService::class)) extends SendEmailComposerMessage
            {
                public function handleNew(EmailAccount $account, User $actor, array $payload): EmailLog
                {
                    throw new RuntimeException('SQLSTATE contains an internal database credential');
                }
            },
        );

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Safe pre-provider failure')
            ->set('composerBodyHtml', '<p>This was not handed to the provider.</p>')
            ->call('sendComposer')
            ->assertSet('composerOpen', true)
            ->assertSet('mailActionStatus.type', 'danger')
            ->assertSee('could not be prepared for sending')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('database credential')
            ->assertDontSee('could not be sent');

        $this->assertSame(0, EmailLog::query()->count());
    }

    #[Test]
    public function sent_sync_confirmation_wins_over_an_ambiguous_smtp_exception(): void
    {
        $account = $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public ?\Closure $confirmInSent = null;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                ($this->confirmInSent)($account, (string) $options['message_id']);

                throw new RuntimeException('SMTP response was lost after provider acceptance');
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $mailer->confirmInSent = function (EmailAccount $account, string $messageId): void {
            $folder = EmailFolder::query()->create([
                'account_id' => $account->id,
                'path' => 'Sent',
                'name' => 'Sent',
                'role' => EmailFolder::ROLE_SENT,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => 992,
            ]);
            $message = EmailMessage::query()->create([
                'account_id' => $account->id,
                'mailbox' => 'Sent',
                'imap_uid' => 9921,
                'message_id' => trim($messageId, '<>'),
                'subject' => 'Confirmed despite lost response',
                'from_email' => $account->address,
                'received_at' => now(),
                'state' => 'untriaged',
            ]);
            $placement = EmailMailboxPlacement::query()->create([
                'email_message_id' => $message->id,
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'folder_path' => 'Sent',
                'imap_uid_validity' => 992,
                'imap_uid' => 9921,
                'provider_seen' => true,
            ]);

            app(EmailSentReconciliationService::class)->reconcilePlacement($placement);
        };

        $log = app(SendEmailComposerMessage::class)->handleNew($account, $this->actor, [
            'to' => 'customer@example.test',
            'subject' => 'Confirmed despite lost response',
            'body_html' => '<p>Normal Sent sync is stronger evidence.</p>',
            'idempotency_key' => 'sent-sync-wins',
        ]);

        $this->assertSame(1, $mailer->calls);
        $this->assertSame('MAIL_COMPOSE_SENT', $log->code);
        $this->assertSame('accepted_reconciled', data_get($log->context_json, 'smtp_delivery.status'));
        $this->assertSame(EmailSentReconciliation::STATUS_RECONCILED, EmailSentReconciliation::query()->sole()->status);
        $this->assertDatabaseMissing('email_logs', [
            'id' => $log->id,
            'code' => 'MAIL_COMPOSE_SEND_UNRESOLVED',
        ]);
    }

    #[Test]
    public function provider_reconciled_reservation_is_reused_without_another_smtp_write(): void
    {
        $account = $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                return '<unexpected-second-send@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $log = EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $account->id,
            'rfc_message_id' => '<accepted-by-reconciliation@example.test>',
            'scope' => 'inbox',
            'level' => 'info',
            'code' => 'MAIL_COMPOSE_SEND_RESERVED',
            'message' => 'Provider acceptance recovered from Sent.',
            'idempotency_key' => 'mail-compose:'.$this->actor->id.':accepted-reconciled',
            'context_json' => [
                'smtp_delivery' => ['status' => 'accepted_reconciled'],
            ],
        ]);

        $reused = app(SendEmailComposerMessage::class)->handleNew($account, $this->actor, [
            'to' => 'customer@example.test',
            'subject' => 'Already accepted',
            'body_html' => '<p>Never send this twice.</p>',
            'idempotency_key' => 'accepted-reconciled',
        ]);

        $this->assertSame(0, $mailer->calls);
        $this->assertSame($log->id, $reused->id);
        $this->assertSame('accepted_reconciled', data_get($reused->context_json, 'smtp_delivery.status'));
    }

    #[Test]
    public function accepted_send_returns_warning_and_retains_reservation_when_log_finalization_fails(): void
    {
        $account = $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $payload = [
            'to' => 'customer@example.test',
            'subject' => 'Accepted before log failure',
            'body_html' => '<p>The provider accepted this once.</p>',
            'idempotency_key' => 'accepted-log-finalization-failure',
        ];

        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                return (string) $options['message_id'];
            }
        };
        $reconciliations = new class(app(EmailPrivateStorage::class)) extends EmailSentReconciliationService
        {
            public function recordPending(EmailLog $log, ?EmailMailboxPlacement $sourcePlacement = null, array $sentPayload = []): ?EmailSentReconciliation
            {
                return null;
            }
        };
        $action = new class(app(MailboxAccess::class), $mailer, app(EmailSignatureRenderer::class), $reconciliations) extends SendEmailComposerMessage
        {
            protected function persistAcceptedLog(EmailLog $log, array $attributes): EmailLog
            {
                throw new RuntimeException('outbound log database write failed');
            }
        };

        $log = $action->handleNew($account, $this->actor, $payload);

        $this->assertSame(1, $mailer->calls);
        $this->assertSame('MAIL_COMPOSE_SENT', $log->code);
        $this->assertSame('warning', $log->level);
        $this->assertSame('accepted', data_get($log->context_json, 'smtp_delivery.status'));
        $this->assertSame('finalize_failed', data_get($log->context_json, 'smtp_delivery.local_log_status'));
        $this->assertSame('MAIL_COMPOSE_SEND_RESERVED', $log->fresh()->code);

        try {
            $action->handleNew($account, $this->actor, $payload);
            $this->fail('The durable unresolved reservation must block another SMTP call.');
        } catch (EmailSendOutcomeUnresolvedException $exception) {
            $this->assertStringContainsString('No second send was attempted', $exception->getMessage());
        }

        $this->assertSame(1, $mailer->calls);
    }

    #[Test]
    public function smtp_account_telemetry_failure_cannot_reverse_provider_acceptance(): void
    {
        $account = $this->sendableAccount();
        $account->forceFill([
            'smtp_secret' => Crypt::encryptString('post-smtp-safety-secret'),
        ])->save();
        $mailer = new class extends SmtpAccountMailer
        {
            public int $deliveries = 0;

            protected function deliver(EsmtpTransport $transport, SymfonyEmail $email): void
            {
                $this->deliveries++;
            }

            protected function recordSuccessfulSendTelemetry(EmailAccount $account): void
            {
                throw new RuntimeException('account telemetry database unavailable');
            }
        };

        $messageId = $mailer->sendMessage(
            $account,
            [['email' => 'customer@example.test', 'name' => 'Customer']],
            'Telemetry safety',
            '<p>Accepted message.</p>',
            'Accepted message.',
            options: ['message_id' => '<telemetry-safe@example.test>'],
        );

        $this->assertSame(1, $mailer->deliveries);
        $this->assertSame('<telemetry-safe@example.test>', $messageId);
    }

    #[Test]
    public function draft_cleanup_failure_after_acceptance_is_shown_as_sent_warning(): void
    {
        $this->sendableAccount();
        Storage::fake(EmailPrivateStorage::DISK);
        $mailer = new class extends SmtpAccountMailer
        {
            public int $calls = 0;

            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;

                return (string) $options['message_id'];
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);
        $this->app->instance(
            EmailComposerDraftService::class,
            new class(app(MailboxAccess::class), app(EmailProviderDraftSyncService::class), app(EmailPrivateStorage::class), app(EmailAccountProviderRuntimeResolver::class)) extends EmailComposerDraftService
            {
                public function markSent(User $user, string $mode, EmailAccount $account, ?EmailMailboxPlacement $placement = null): ?EmailComposerDraft
                {
                    throw new RuntimeException('draft cleanup database unavailable');
                }

                public function markDraftSent(User $user, EmailComposerDraft $draft, int $expectedVersion): EmailComposerDraft
                {
                    throw new RuntimeException('draft cleanup database unavailable');
                }
            },
        );

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('startCompose')
            ->set('composerTo', 'customer@example.test')
            ->set('composerSubject', 'Draft cleanup safety')
            ->set('composerBodyHtml', '<p>This message is accepted once.</p>')
            ->call('saveComposerDraft', false)
            ->call('sendComposer')
            ->assertSet('composerOpen', false)
            ->assertSet('mailActionStatus.type', 'warning')
            ->assertSee('Message sent from post-smtp-safety@example.test.')
            ->assertSee('local draft cleanup could not be completed')
            ->assertSee('Do not resend it.')
            ->assertDontSee('could not be sent')
            ->assertDontSee('database unavailable');

        $this->assertSame(1, $mailer->calls);
        $this->assertSame(EmailComposerDraft::STATUS_SEND_RESERVED, EmailComposerDraft::query()->sole()->status);
        $this->assertSame('MAIL_COMPOSE_SENT', EmailLog::query()->sole()->code);
    }

    private function blockEmailPrivateStorageRoot(): void
    {
        $this->blockedStorageRoot = tempnam(sys_get_temp_dir(), 'email-storage-block-');
        $this->assertNotFalse($this->blockedStorageRoot);
        $this->originalStorageRoot = config('filesystems.disks.'.EmailPrivateStorage::DISK.'.root');
        config()->set('filesystems.disks.'.EmailPrivateStorage::DISK.'.root', $this->blockedStorageRoot);
        Storage::forgetDisk(EmailPrivateStorage::DISK);
    }

    private function sendableAccount(): EmailAccount
    {
        $account = EmailAccount::query()->create([
            'address' => 'post-smtp-safety@example.test',
            'description' => 'Mail post SMTP safety account',
            'from_name' => 'Post SMTP Safety',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'post-smtp-safety@example.test',
            'imap_secret' => Crypt::encryptString('post-smtp-safety-secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'post-smtp-safety@example.test',
            'smtp_secret' => Crypt::encryptString('post-smtp-safety-secret'),
            'smtp_auth_type' => 'password',
        ]);

        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $this->actor->id,
            'can_view' => false,
            'can_organize' => false,
            'can_send' => true,
            'granted_at' => now(),
        ]);

        return $account;
    }
}
