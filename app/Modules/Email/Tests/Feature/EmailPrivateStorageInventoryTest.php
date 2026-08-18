<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\EmailPrivateStorageInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailPrivateStorageInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(EmailPrivateStorage::DISK);
    }

    public function test_inventory_reconciles_every_email_reference_without_mutating_files_or_rows(): void
    {
        [$user, $account, $message] = $this->fixtures();

        $message->forceFill(['raw_path' => 'email/raw/1/INBOX/10.eml'])->save();
        Storage::disk('local')->put($message->raw_path, 'raw-message');

        $attachment = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'evidence.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 8,
            'disk' => 'local',
            'path' => 'email/attachments/1/10/evidence.txt',
            'checksum_sha1' => sha1('evidence'),
        ]);
        Storage::disk('local')->put($attachment->path, 'evidence');

        $draft = EmailComposerDraft::query()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'provider_binding_version' => 1,
            'mode' => 'new',
            'draft_key' => 'private-storage-inventory-'.$user->id,
            'status' => EmailComposerDraft::STATUS_ACTIVE,
        ]);
        $draftAttachment = EmailComposerDraftAttachment::query()->create([
            'email_composer_draft_id' => $draft->id,
            'user_id' => $user->id,
            'position' => 1,
            'filename' => 'draft.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 5,
            'disk' => 'local',
            'path' => 'email/drafts/1/draft.txt',
            'checksum_sha1' => sha1('draft'),
        ]);
        Storage::disk('local')->put($draftAttachment->path, 'draft');

        $log = EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $account->id,
            'rfc_message_id' => '<sent@example.test>',
            'scope' => 'inbox',
            'level' => 'info',
            'code' => 'MAIL_COMPOSE_SENT',
            'message' => 'Mail message sent.',
        ]);
        $sentPath = 'email/sent-pending/1/pending.eml';
        EmailSentReconciliation::query()->create([
            'email_log_id' => $log->id,
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'rfc_message_id' => '<sent@example.test>',
            'normalized_message_id' => 'sent@example.test',
            'status' => EmailSentReconciliation::STATUS_PENDING,
            'context_json' => ['sent_raw_path' => $sentPath],
        ]);
        Storage::disk('local')->put($sentPath, 'sent');

        Storage::disk('local')->put('email/attachments/legacy/a.bin', 'duplicate');
        Storage::disk('local')->put('email/attachments/legacy/b.bin', 'duplicate');

        $before = Storage::disk('local')->allFiles('email');
        $result = app(EmailPrivateStorageInventory::class)->inspect();

        $this->assertSame(6, $result['total_files']);
        $this->assertSame(4, $result['referenced_files']);
        $this->assertSame(2, $result['unreferenced_files']);
        $this->assertSame(0, $result['missing_references']);
        $this->assertCount(1, $result['duplicate_groups']);
        $this->assertSame(2, $result['duplicate_groups'][0]['count']);
        $this->assertSame([
            'draft_attachment' => 1,
            'message_attachment' => 1,
            'message_raw' => 1,
            'sent_reconciliation' => 1,
        ], $result['reference_type_counts']);
        $this->assertSame($before, Storage::disk('local')->allFiles('email'));
        $this->assertDatabaseCount('email_attachments', 1);
        $this->assertDatabaseCount('email_composer_draft_attachments', 1);
        $this->assertDatabaseCount('email_sent_reconciliations', 1);
    }

    public function test_command_redacts_paths_by_default_and_reports_missing_references_as_failure(): void
    {
        [, , $message] = $this->fixtures();
        $message->forceFill(['raw_path' => 'email/raw/private/missing.eml'])->save();
        Storage::disk('local')->put('email/attachments/private/orphan.txt', 'orphan');

        $this->artisan('email:inventory-private-storage')
            ->expectsOutputToContain('Read-only inventory')
            ->expectsOutputToContain('unreferenced scope=attachments')
            ->expectsOutputToContain('missing reference=message_raw')
            ->doesntExpectOutputToContain('private/orphan.txt')
            ->assertExitCode(1);

        $this->artisan('email:inventory-private-storage', ['--show-paths' => true])
            ->expectsOutputToContain('email/attachments/private/orphan.txt')
            ->expectsOutputToContain('email/raw/private/missing.eml')
            ->assertExitCode(1);
    }

    public function test_command_fails_closed_when_the_scan_limit_is_exceeded(): void
    {
        Storage::disk('local')->put('email/raw/one.eml', 'one');
        Storage::disk('local')->put('email/raw/two.eml', 'two');

        $this->artisan('email:inventory-private-storage', ['--limit' => 1])
            ->expectsOutputToContain('Inventory exceeded the selected file limit')
            ->assertExitCode(1);
    }

    /** @return array{User,EmailAccount,EmailMessage} */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $account = EmailAccount::query()->create([
            'address' => 'inventory@example.test',
            'description' => 'Inventory account',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'inventory@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'inventory@example.test',
            'smtp_secret' => 'secret',
            'is_active' => true,
            'provider_binding_version' => 1,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => random_int(10_000, 99_999),
            'message_id' => '<inventory-'.uniqid('', true).'@example.test>',
            'subject' => 'Inventory message',
            'from_json' => [['email' => 'sender@example.test']],
            'to_json' => [['email' => 'inventory@example.test']],
            'received_at' => now(),
            'state' => 'untriaged',
        ]);

        return [$user, $account, $message];
    }
}
