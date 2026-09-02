<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Jobs\AppendEmailProviderSentCopy;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailSentAppendSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    #[Test]
    public function an_acknowledged_sent_append_is_not_written_to_the_provider_twice(): void
    {
        [$account, $reconciliation] = $this->pendingReconciliation('append-once');
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Sent.Child',
            'name' => ' Child',
            'delimiter' => '.',
            'parent_path' => 'Sent',
            // Simulate legacy substring-based inference still present in DB.
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 901,
        ]);
        $client = new class($account) extends ImapClient
        {
            public int $writes = 0;

            public array $folderPaths = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendSent(string $folderPath, string $message): array
            {
                $this->writes++;
                $this->folderPaths[] = $folderPath;

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 900,
                    'imap_uid' => 901,
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $service = app(EmailSentReconciliationService::class);
        $first = $service->appendProviderSentCopy($reconciliation);
        $second = $service->appendProviderSentCopy($first->fresh());

        $this->assertSame(1, $client->writes);
        $this->assertSame(['Sent'], $client->folderPaths);
        $this->assertSame(EmailSentReconciliation::STATUS_APPENDED, $first->status);
        $this->assertSame(EmailSentReconciliation::STATUS_APPENDED, $second->status);
    }

    #[Test]
    public function queued_sent_append_uses_the_same_duplicate_safe_service_boundary(): void
    {
        [$account, $reconciliation] = $this->pendingReconciliation('queued-append');
        $client = new class($account) extends ImapClient
        {
            public int $writes = 0;

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendSent(string $folderPath, string $message): array
            {
                $this->writes++;

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 900,
                    'imap_uid' => 903,
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $job = new AppendEmailProviderSentCopy($reconciliation->id);
        $job->handle(app(EmailSentReconciliationService::class));
        $job->handle(app(EmailSentReconciliationService::class));

        $this->assertSame(1, $client->writes);
        $this->assertSame(
            EmailSentReconciliation::STATUS_APPENDED,
            $reconciliation->fresh()->status,
        );
    }

    #[Test]
    public function an_ambiguous_provider_append_failure_is_not_blindly_replayed(): void
    {
        [$account, $reconciliation] = $this->pendingReconciliation('append-ambiguous');
        $client = new class($account) extends ImapClient
        {
            public int $writes = 0;

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendSent(string $folderPath, string $message): array
            {
                $this->writes++;

                throw new RuntimeException('provider secret response must not be persisted');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $service = app(EmailSentReconciliationService::class);
        $failed = $service->appendProviderSentCopy($reconciliation);
        $repeated = $service->appendProviderSentCopy($failed->fresh());

        $this->assertSame(1, $client->writes);
        $this->assertSame(EmailSentReconciliation::STATUS_APPEND_FAILED, $failed->status);
        $this->assertSame(EmailSentReconciliation::STATUS_APPEND_FAILED, $repeated->status);
        $this->assertTrue((bool) data_get($failed->context_json, 'sent_append_error.provider_write_started'));
        $this->assertStringNotContainsString('provider secret', (string) $failed->status_message);
        $this->assertStringNotContainsString('provider secret', json_encode($failed->context_json));
    }

    #[Test]
    public function a_snapshot_read_failure_after_reservation_is_retryable_without_provider_write(): void
    {
        Storage::fake('local', ['throw' => true]);
        [$account, $reconciliation] = $this->pendingReconciliation('append-read-failure');
        $rawPath = (string) data_get($reconciliation->context_json, 'sent_raw_path');
        $deletedAfterReservation = false;
        EmailSentReconciliation::updated(function (EmailSentReconciliation $updated) use ($reconciliation, $rawPath, &$deletedAfterReservation): void {
            if ($deletedAfterReservation
                || $updated->id !== $reconciliation->id
                || $updated->status !== EmailSentReconciliation::STATUS_APPEND_STARTED) {
                return;
            }

            Storage::disk('local')->delete($rawPath);
            $deletedAfterReservation = true;
        });
        $client = new class($account) extends ImapClient
        {
            public int $writes = 0;

            public function connect(): void {}

            public function disconnect(): void {}

            public function appendSent(string $folderPath, string $message): array
            {
                $this->writes++;

                return [
                    'ok' => true,
                    'folder_path' => $folderPath,
                    'imap_uid_validity' => 900,
                    'imap_uid' => 902,
                ];
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $service = app(EmailSentReconciliationService::class);
        $failed = $service->appendProviderSentCopy($reconciliation);

        $this->assertTrue($deletedAfterReservation);
        $this->assertSame(0, $client->writes);
        $this->assertSame(EmailSentReconciliation::STATUS_APPEND_FAILED, $failed->status);
        $this->assertSame('PROVIDER_SENT_RAW_READ_FAILED', data_get($failed->context_json, 'sent_append_error.code'));
        $this->assertFalse((bool) data_get($failed->context_json, 'sent_append_error.provider_write_started'));

        Storage::disk('local')->put($rawPath, 'RFC 822 retry payload');
        $retried = $service->appendProviderSentCopy($failed->fresh());

        $this->assertSame(1, $client->writes);
        $this->assertSame(EmailSentReconciliation::STATUS_APPENDED, $retried->status);
    }

    #[Test]
    public function a_snapshot_is_removed_when_its_reconciliation_row_cannot_be_created(): void
    {
        $account = $this->account('snapshot-cleanup');
        $log = $this->log($account, 'snapshot-cleanup');

        DB::statement(<<<'SQL'
            CREATE TRIGGER email_sent_reconciliation_test_failure
            BEFORE INSERT ON email_sent_reconciliations
            BEGIN
                SELECT RAISE(ABORT, 'forced reconciliation insert failure');
            END
            SQL);

        try {
            app(EmailSentReconciliationService::class)->recordPending($log, null, [
                'to' => [['email' => 'customer@example.test', 'name' => 'Customer']],
                'cc' => [],
                'subject' => 'Snapshot cleanup',
                'body_html' => '<p>Accepted body.</p>',
                'body_text' => 'Accepted body.',
                'attachments' => [],
                'headers' => [],
            ]);

            $this->fail('The forced reconciliation insert failure was not raised.');
        } catch (QueryException) {
            $this->assertSame([], Storage::disk('local')->allFiles('email/sent-pending'));
        }
    }

    #[Test]
    public function normal_sent_sync_resolves_an_unconfirmed_send_reservation_without_resending(): void
    {
        [$account, $reconciliation] = $this->pendingReconciliation('reconciled-reservation');
        $log = $reconciliation->emailLog()->firstOrFail();
        $log->forceFill([
            'level' => 'warning',
            'code' => 'MAIL_COMPOSE_SEND_UNRESOLVED',
            'message' => 'The SMTP provider outcome could not be confirmed.',
            'context_json' => [
                'mode' => 'compose',
                'smtp_delivery' => [
                    'status' => 'unresolved',
                    'message_id' => $log->rfc_message_id,
                ],
            ],
        ])->save();
        $reconciliation->forceFill([
            'status' => EmailSentReconciliation::STATUS_APPEND_STARTED,
        ])->save();
        $sentFolder = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', 'Sent')
            ->sole();
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 902,
            'message_id' => $log->rfc_message_id,
            'subject' => 'Reconciled reservation',
            'from_email' => $account->address,
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Provider evidence confirms acceptance.',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $sentFolder->id,
            'folder_path' => $sentFolder->path,
            'imap_uid_validity' => $sentFolder->uid_validity,
            'imap_uid' => 902,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
        ]);

        app(EmailSentReconciliationService::class)->reconcilePlacement($placement);

        $this->assertSame(EmailSentReconciliation::STATUS_RECONCILED, $reconciliation->fresh()->status);
        $this->assertSame('MAIL_COMPOSE_SENT', $log->fresh()->code);
        $this->assertSame('info', $log->fresh()->level);
        $this->assertSame('accepted_reconciled', data_get($log->fresh()->context_json, 'smtp_delivery.status'));
        $this->assertSame($placement->id, $reconciliation->fresh()->sent_email_mailbox_placement_id);
    }

    /** @return array{EmailAccount, EmailSentReconciliation} */
    private function pendingReconciliation(string $suffix): array
    {
        $account = $this->account($suffix);
        EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 900,
        ]);
        $log = $this->log($account, $suffix);
        $rawPath = 'email/sent-pending/'.$account->id.'/'.$suffix.'.eml';
        Storage::disk('local')->put($rawPath, 'RFC 822 test payload');
        $reconciliation = EmailSentReconciliation::query()->create([
            'email_log_id' => $log->id,
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'rfc_message_id' => (string) $log->rfc_message_id,
            'normalized_message_id' => trim((string) $log->rfc_message_id, '<>'),
            'idempotency_key' => $log->idempotency_key,
            'status' => EmailSentReconciliation::STATUS_PENDING,
            'candidate_count' => 0,
            'context_json' => ['sent_raw_path' => $rawPath],
        ]);

        return [$account, $reconciliation];
    }

    private function account(string $suffix): EmailAccount
    {
        $address = $suffix.'@example.test';

        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Sent append safety test',
            'from_name' => 'Sent Safety',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'provider_binding_version' => 1,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'sent-safety-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'sent-safety-secret',
            'smtp_auth_type' => 'password',
        ]);
    }

    private function log(EmailAccount $account, string $suffix): EmailLog
    {
        return EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $account->id,
            'rfc_message_id' => '<'.$suffix.'@example.test>',
            'idempotency_key' => 'mail-compose:1:'.$suffix,
            'scope' => 'inbox',
            'level' => 'info',
            'code' => 'MAIL_COMPOSE_SENT',
            'message' => 'Mail message sent.',
            'context_json' => [
                'mode' => 'compose',
                'smtp_delivery' => ['provider_binding_version' => 1],
            ],
        ]);
    }
}
