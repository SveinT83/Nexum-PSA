<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailConversationProjectionBoundedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pending_assignment_is_invisible_and_activation_refreshes_a_large_conversation_set_based(): void
    {
        [$account, $folder] = $this->mailbox('bounded-conversation@example.test');
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'msg:'.hash('sha256', 'bounded-root@example.test'),
            'status' => EmailConversation::STATUS_ACTIVE,
            'metadata' => ['source' => 'mail_header_projection'],
        ]);
        $now = now()->subDay();

        collect(range(1, 1000))->chunk(100)->each(function ($uids) use ($account, $now): void {
            DB::table('email_messages')->insert($uids->map(fn (int $uid): array => [
                'account_id' => $account->id,
                'mailbox' => 'INBOX',
                'imap_uid_validity' => 9101,
                'imap_uid' => $uid,
                'message_id' => $uid === 1
                    ? '<bounded-root@example.test>'
                    : '<bounded-'.$uid.'@example.test>',
                'subject' => 'Bounded message '.$uid,
                'from_email' => 'sender@example.test',
                'received_at' => $now->copy()->addSeconds($uid),
                'state' => 'untriaged',
                'attachments_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
        $messages = EmailMessage::query()
            ->where('account_id', $account->id)
            ->whereBetween('imap_uid', [1, 1000])
            ->get(['id', 'imap_uid', 'message_id'])
            ->keyBy('imap_uid');
        collect(range(1, 1000))->chunk(100)->each(
            function ($uids) use ($account, $conversation, $folder, $messages, $now): void {
                DB::table('email_mailbox_placements')->insert($uids->map(
                    fn (int $uid): array => [
                        'email_message_id' => $messages->get($uid)->id,
                        'email_conversation_id' => $conversation->id,
                        'account_id' => $account->id,
                        'email_folder_id' => $folder->id,
                        'provider' => 'imap',
                        'folder_path' => $folder->path,
                        'remote_message_id' => $messages->get($uid)->message_id,
                        'imap_uid_validity' => 9101,
                        'imap_uid' => $uid,
                        'provider_seen' => $uid % 2 === 0,
                        'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                        'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                        'sync_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                )->all());
            },
        );

        $projector = app(EmailConversationProjector::class);
        $projector->refreshConversation($conversation);
        $conversation = $conversation->fresh();
        $this->assertSame(1000, $conversation->message_count);
        $this->assertSame(1000, $conversation->active_placement_count);
        $this->assertSame(500, $conversation->provider_unread_count);
        $this->assertSame($messages->get(1)->id, $conversation->first_email_message_id);
        $this->assertSame($messages->get(1000)->id, $conversation->latest_email_message_id);
        $this->assertFalse($conversation->has_attachments);

        $pendingMessage = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 9101,
            'imap_uid' => 1001,
            'message_id' => '<bounded-pending@example.test>',
            'in_reply_to' => '<bounded-root@example.test>',
            'references' => '<bounded-root@example.test>',
            'subject' => 'Newest pending private subject',
            'from_email' => 'sender@example.test',
            'received_at' => $now->copy()->addSeconds(1001),
            'state' => 'untriaged',
            'attachments_count' => 1,
        ]);
        $pending = EmailMailboxPlacement::query()->create([
            'email_message_id' => $pendingMessage->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'remote_message_id' => $pendingMessage->message_id,
            'imap_uid_validity' => 9101,
            'imap_uid' => 1001,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_version' => 1,
            'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
        ]);
        EmailAttachment::query()->create([
            'message_id' => $pendingMessage->id,
            'filename' => 'pending-private.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 7,
            'disk' => 'local',
            'path' => 'email/attachments/pending-private.txt',
            'checksum_sha1' => sha1('private'),
        ]);
        $visibleBeforePending = $this->visibleProjection($conversation);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $assigned = $projector->assignPendingPlacement($pending);

        $this->assertSame($conversation->id, $assigned?->id);
        $this->assertSame($conversation->id, $pending->fresh()->email_conversation_id);
        $this->assertSame(
            $conversation->getRawOriginal('updated_at'),
            $conversation->fresh()->getRawOriginal('updated_at'),
        );
        $this->assertSame($visibleBeforePending, $this->visibleProjection($conversation->fresh()));

        // An unrelated refresh must continue to exclude the pending content.
        $projector->refreshConversation($conversation);
        $this->assertSame($visibleBeforePending, $this->visibleProjection($conversation->fresh()));

        $pending->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_error_code' => null,
        ])->save();
        $projector->refreshForPlacement($pending->fresh());
        $conversation = $conversation->fresh();

        $this->assertSame(1001, $conversation->message_count);
        $this->assertSame(1001, $conversation->active_placement_count);
        $this->assertSame(501, $conversation->provider_unread_count);
        $this->assertSame($messages->get(1)->id, $conversation->first_email_message_id);
        $this->assertSame($pendingMessage->id, $conversation->latest_email_message_id);
        $this->assertSame($pending->id, $conversation->latest_email_mailbox_placement_id);
        $this->assertSame('Newest pending private subject', $conversation->subject);
        $this->assertTrue($conversation->has_attachments);
        $this->assertSame('INBOX', $conversation->metadata['latest_folder_path']);

        $unboundedConversationSelects = collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(ltrim($sql), 'select')
                && str_contains($sql, 'email_mailbox_placements')
                && str_contains($sql, 'email_conversation_id')
                && ! str_contains($sql, 'count(')
                && ! str_starts_with(ltrim($sql), 'select exists(')
                && ! str_contains($sql, 'limit 1'),
        );
        $this->assertSame([], $unboundedConversationSelects->values()->all());
    }

    #[Test]
    public function a_conversation_created_for_pending_store_content_stays_empty_until_activation(): void
    {
        [$account, $folder] = $this->mailbox('pending-shell@example.test', 9102);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 9102,
            'imap_uid' => 1,
            'message_id' => '<pending-shell@example.test>',
            'subject' => 'Pending shell secret',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'attachments_count' => 1,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => 9102,
            'imap_uid' => 1,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
        ]);
        EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'pending.txt',
            'path' => 'email/attachments/pending.txt',
        ]);

        $projector = app(EmailConversationProjector::class);
        $conversation = $projector->assignPendingPlacement($placement);

        $this->assertNotNull($conversation);
        $this->assertNull($conversation->subject);
        $this->assertNull($conversation->first_email_message_id);
        $this->assertNull($conversation->latest_email_message_id);
        $this->assertNull($conversation->latest_email_mailbox_placement_id);
        $this->assertSame(0, $conversation->message_count);
        $this->assertSame(0, $conversation->active_placement_count);
        $this->assertSame(0, $conversation->provider_unread_count);
        $this->assertFalse($conversation->has_attachments);
        $projector->refreshConversation($conversation);
        $this->assertNull($conversation->fresh()->subject);

        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_error_code' => null,
        ])->save();
        $projector->refreshForPlacement($placement->fresh());
        $conversation = $conversation->fresh();

        $this->assertSame('Pending shell secret', $conversation->subject);
        $this->assertSame($message->id, $conversation->first_email_message_id);
        $this->assertSame($message->id, $conversation->latest_email_message_id);
        $this->assertSame($placement->id, $conversation->latest_email_mailbox_placement_id);
        $this->assertSame(1, $conversation->message_count);
        $this->assertSame(1, $conversation->active_placement_count);
        $this->assertSame(1, $conversation->provider_unread_count);
        $this->assertTrue($conversation->has_attachments);
    }

    /** @return array<string, mixed> */
    private function visibleProjection(EmailConversation $conversation): array
    {
        $projection = $conversation->only([
            'subject',
            'first_email_message_id',
            'latest_email_message_id',
            'latest_email_mailbox_placement_id',
            'message_count',
            'active_placement_count',
            'provider_unread_count',
            'has_attachments',
            'first_message_at',
            'last_message_at',
            'metadata',
        ]);
        $projection['first_message_at'] = $conversation->getRawOriginal('first_message_at');
        $projection['last_message_at'] = $conversation->getRawOriginal('last_message_at');

        return $projection;
    }

    /** @return array{EmailAccount, EmailFolder} */
    private function mailbox(string $address, int $uidValidity = 9101): array
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Bounded Conversation',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => encrypt('test-secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => encrypt('test-secret'),
            'smtp_auth_type' => 'password',
        ]);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);

        return [$account, $folder];
    }
}
