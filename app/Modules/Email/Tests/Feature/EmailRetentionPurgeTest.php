<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Jobs\EmailRetentionPurgeJob;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRetentionPurgeAttempt;
use App\Modules\Email\Models\EmailRetentionPurgeRun;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\Actions\ResolveInboundEmailNotificationRecipients;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailRetentionPurgeTest extends TestCase
{
    use RefreshDatabase;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'retention_months'],
            ['value' => '12'],
        );

        $this->account = EmailAccount::query()->create([
            'address' => 'retention@example.test',
            'from_name' => 'Retention Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'provider_binding_version' => 1,
            'ticket_ingress_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'retention@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'retention@example.test',
            'smtp_secret' => 'secret',
        ]);
    }

    #[Test]
    public function expired_mail_with_an_active_provider_placement_is_preserved(): void
    {
        $message = $this->oldMessage(1);
        $this->addLocalPayloads($message);
        $this->placement($message, 1);

        $this->runPurge();

        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        Storage::disk('local')->assertExists($message->raw_path);
        Storage::disk('local')->assertExists("email/attachments/{$message->id}.txt");

        $attempt = EmailRetentionPurgeAttempt::query()->sole();
        $this->assertSame(EmailRetentionPurgeAttempt::STATUS_PROTECTED, $attempt->status);
        $this->assertContains(
            EmailRetentionEligibilityService::REASON_ACTIVE_PROVIDER_PLACEMENT,
            $attempt->reasons_json,
        );
    }

    #[Test]
    public function captured_ticket_evidence_preserves_the_mail_cache_source(): void
    {
        $message = $this->oldMessage(2);
        $this->addLocalPayloads($message);
        $ticket = Ticket::factory()->create();
        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'source_inbound_email_message_id' => $message->id,
            'inbound_email_message_id' => $message->id,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Captured evidence',
            'body' => 'The Ticket owns this independent evidence snapshot.',
            'metadata' => ['email_message_id' => $message->id],
        ]);

        $this->runPurge();

        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        $attempt = EmailRetentionPurgeAttempt::query()->sole();
        $this->assertSame(EmailRetentionPurgeAttempt::STATUS_PROTECTED, $attempt->status);
        $this->assertContains(
            EmailRetentionEligibilityService::REASON_TICKET_EVIDENCE,
            $attempt->reasons_json,
        );
    }

    #[Test]
    public function pending_and_failed_remote_operations_are_explicit_purge_blockers(): void
    {
        $folder = $this->folder();
        $pendingMessage = $this->oldMessage(3);
        $failedMessage = $this->oldMessage(4);
        $pendingPlacement = $this->placement($pendingMessage, 3, $folder, [
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'provider_deleted' => true,
        ]);
        $failedPlacement = $this->placement($failedMessage, 4, $folder, [
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'provider_deleted' => true,
        ]);

        foreach ([
            [$pendingPlacement, EmailRemoteOperation::STATUS_PENDING, 'pending'],
            [$failedPlacement, EmailRemoteOperation::STATUS_FAILED, 'failed'],
        ] as [$placement, $status, $suffix]) {
            EmailRemoteOperation::query()->create([
                'account_id' => $this->account->id,
                'provider_binding_version' => 1,
                'email_folder_id' => $folder->id,
                'email_mailbox_placement_id' => $placement->id,
                'provider' => 'imap',
                'operation_type' => 'trash',
                'status' => $status,
                'idempotency_key' => "retention-operation-{$suffix}",
            ]);
        }

        $this->runPurge();

        $this->assertNotNull(EmailMessage::withTrashed()->find($pendingMessage->id));
        $this->assertNotNull(EmailMessage::withTrashed()->find($failedMessage->id));
        $attempts = EmailRetentionPurgeAttempt::query()->orderBy('email_message_id')->get();
        $this->assertCount(2, $attempts);
        $this->assertTrue($attempts->every(
            fn (EmailRetentionPurgeAttempt $attempt): bool => in_array(
                EmailRetentionEligibilityService::REASON_REMOTE_OPERATION,
                $attempt->reasons_json,
                true,
            ),
        ));
    }

    #[Test]
    public function a_completed_fanout_keeps_its_source_until_the_linked_external_delivery_is_terminal(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(Permission::findOrCreate('email.inbox_view', 'web'));
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => true,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $message = $this->oldMessage(30);
        $placement = $this->placement($message, 30);
        $fanout = app(DispatchInboundEmailNotification::class)->handle($message);
        $this->assertNotNull($fanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertSame('completed', $fanout->fresh()->status);
        $delivery = NotificationInboundExternalDelivery::query()->sole();
        $placement->delete();

        $service = app(EmailRetentionEligibilityService::class);
        $pending = $service->assess($message->fresh(), now()->subYear());
        $this->assertContains(
            EmailRetentionEligibilityService::REASON_NOTIFICATION_FANOUT,
            $pending['reasons'],
        );

        $token = hash('sha256', 'retention-external-delivery');
        $delivery->forceFill([
            'status' => NotificationInboundExternalDelivery::STATUS_RUNNING,
            'claim_token' => $token,
            'attempt_count' => 1,
            'last_attempt_at' => now(),
        ])->save();
        $delivery->forceFill([
            'status' => NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
            'claim_token' => null,
            'completed_at' => now(),
            'error_code' => 'inbound_notification_recipient_revoked',
        ])->save();

        $terminal = $service->assess($message->fresh(), now()->subYear());
        $this->assertNotContains(
            EmailRetentionEligibilityService::REASON_NOTIFICATION_FANOUT,
            $terminal['reasons'],
        );
    }

    #[Test]
    public function an_expired_unplaced_orphan_and_its_local_payloads_are_purged(): void
    {
        $message = $this->oldMessage(5);
        $this->addLocalPayloads($message);
        $rawPath = $message->raw_path;
        $attachmentPath = "email/attachments/{$message->id}.txt";

        $this->runPurge();

        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('email_attachments', ['message_id' => $message->id]);
        Storage::disk('local')->assertMissing($rawPath);
        Storage::disk('local')->assertMissing($attachmentPath);
        $this->assertDatabaseHas('email_retention_purge_attempts', [
            'email_message_id' => $message->id,
            'status' => EmailRetentionPurgeAttempt::STATUS_PURGED,
            'had_raw_payload' => true,
            'local_attachment_file_count' => 1,
        ]);
        $this->assertDatabaseHas('email_retention_purge_runs', [
            'status' => EmailRetentionPurgeRun::STATUS_COMPLETED,
            'eligible_count' => 1,
            'purged_count' => 1,
            'failed_count' => 0,
        ]);
    }

    #[Test]
    public function storage_failure_leaves_retry_evidence_and_a_later_run_finishes_idempotently(): void
    {
        $message = $this->oldMessage(6);
        $this->addLocalPayloads($message);

        $disk = Mockery::mock();
        $disk->shouldReceive('delete')->times(3)->andReturn(false, true, true);
        Storage::shouldReceive('disk')->with('local')->twice()->andReturn($disk);

        $this->runPurge();

        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        $this->assertDatabaseHas('email_retention_purge_attempts', [
            'email_message_id' => $message->id,
            'status' => EmailRetentionPurgeAttempt::STATUS_FAILED,
            'failure_code' => 'storage_delete_failed',
        ]);

        $this->runPurge();
        $this->runPurge();

        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        $this->assertSame(1, EmailRetentionPurgeAttempt::query()
            ->where('email_message_id', $message->id)
            ->where('status', EmailRetentionPurgeAttempt::STATUS_FAILED)
            ->count());
        $this->assertSame(1, EmailRetentionPurgeAttempt::query()
            ->where('email_message_id', $message->id)
            ->where('status', EmailRetentionPurgeAttempt::STATUS_PURGED)
            ->count());
    }

    #[Test]
    public function admin_retention_preview_uses_the_same_fail_closed_reason_breakdown(): void
    {
        $eligible = $this->oldMessage(7);
        $protected = $this->oldMessage(8);
        $this->placement($protected, 8);

        $permission = Permission::findOrCreate('email.account_manage', 'web');
        $role = Role::findOrCreate('Admin', 'web');
        $role->givePermissionTo($permission);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole($role);

        $response = $this->actingAs($admin)
            ->get(route('tech.admin.settings.email.config'));

        $response
            ->assertOk()
            ->assertSee('Retention preview')
            ->assertSee('Eligible orphans')
            ->assertSee('Active provider placement')
            ->assertDontSee('Purge now');
        $response->assertViewHas('retentionPreview', function (array $preview) use ($eligible): bool {
            return $preview['expired_count'] === 2
                && $preview['eligible_count'] === 1
                && $preview['protected_count'] === 1
                && $preview['reason_counts'][EmailRetentionEligibilityService::REASON_ACTIVE_PROVIDER_PLACEMENT] === 1
                && EmailMessage::withTrashed()->whereKey($eligible->id)->exists();
        });
    }

    private function runPurge(): void
    {
        app()->call([new EmailRetentionPurgeJob(12), 'handle']);
    }

    private function oldMessage(int $uid): EmailMessage
    {
        return EmailMessage::query()->create([
            'account_id' => $this->account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 1000 + $uid,
            'message_id' => "<retention-{$uid}@example.test>",
            'subject' => "Retention test {$uid}",
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMonths(13),
            'state' => 'untriaged',
            'body_text' => 'Local cache payload.',
        ]);
    }

    private function addLocalPayloads(EmailMessage $message): void
    {
        $rawPath = "email/raw/{$message->id}.eml";
        $attachmentPath = "email/attachments/{$message->id}.txt";
        Storage::disk('local')->put($rawPath, 'raw message');
        Storage::disk('local')->put($attachmentPath, 'attachment');
        $message->forceFill(['raw_path' => $rawPath, 'attachments_count' => 1])->save();
        EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'fixture.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 10,
            'disk' => 'local',
            'path' => $attachmentPath,
        ]);
    }

    private function folder(): EmailFolder
    {
        return EmailFolder::query()->firstOrCreate(
            ['account_id' => $this->account->id, 'path' => 'INBOX'],
            [
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'uid_validity' => 100,
                'sync_status' => EmailFolder::SYNC_SYNCED,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function placement(
        EmailMessage $message,
        int $uid,
        ?EmailFolder $folder = null,
        array $overrides = [],
    ): EmailMailboxPlacement {
        $folder ??= $this->folder();

        return EmailMailboxPlacement::query()->create(array_merge([
            'email_message_id' => $message->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'imap_uid_validity' => 100,
            'imap_uid' => 2000 + $uid,
            'provider_seen' => false,
            'provider_deleted' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
        ], $overrides));
    }
}
