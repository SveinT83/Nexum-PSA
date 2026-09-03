<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\DTOs\EmailPlacementCreateResult;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationMessagePayload;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Services\EmailRawMessageSnapshot;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\InboundAttachmentPersister;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailProviderReconciliationStoreTest extends TestCase
{
    use RefreshDatabase;

    private int $providerClientResolutions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->app->bind(ImapClient::class, function (): never {
            $this->providerClientResolutions++;

            throw new \RuntimeException('A reconciliation store must never resolve a provider client.');
        });
    }

    #[Test]
    public function store_authorization_is_attempt_bound_cancellation_aware_and_does_not_rewrite_the_claim_clock(): void
    {
        $account = $this->account('store-claim-authorization@example.test');
        [$folder, $namespace] = $this->folder(
            $account,
            'Archive/Claims',
            EmailFolder::ROLE_ARCHIVE,
            712,
            83,
        );
        $run = $this->reconciliationRun($account);
        [$folderRun, $successfulItem] = $this->scope($run, $folder, $namespace, 80);
        $claimedAt = now()->subMinute()->startOfSecond();
        $successfulItem->forceFill(['last_attempt_at' => $claimedAt])->save();

        $this->travel(2)->seconds();
        $this->store(
            $run,
            $folderRun,
            $successfulItem,
            $folder,
            $namespace,
            80,
            $this->peeked(
                $account,
                $folder,
                $namespace,
                80,
                '<store-claim-success@example.test>',
            ),
        );
        $this->assertTrue($successfulItem->fresh()->last_attempt_at->equalTo($claimedAt));

        [, $reclaimedItem] = $this->scope($run, $folder, $namespace, 81);
        $reclaimedAt = now()->subSeconds(30)->startOfSecond();
        $reclaimedItem->forceFill([
            'attempt_count' => 2,
            'last_attempt_at' => $reclaimedAt,
        ])->save();
        try {
            $this->store(
                $run,
                $folderRun,
                $reclaimedItem,
                $folder,
                $namespace,
                81,
                $this->peeked(
                    $account,
                    $folder,
                    $namespace,
                    81,
                    '<store-claim-reclaimed@example.test>',
                ),
                claimAttempt: 1,
            );
            $this->fail('An older Store generation survived a claim reclaim.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_store_claim_stale', $exception->safeCode);
        }
        $this->assertTrue($reclaimedItem->fresh()->last_attempt_at->equalTo($reclaimedAt));

        [, $cancelledItem] = $this->scope($run, $folder, $namespace, 82);
        $cancelledAt = now()->subSeconds(20)->startOfSecond();
        $cancelledItem->forceFill(['last_attempt_at' => $cancelledAt])->save();
        $run->forceFill(['cancellation_requested_at' => now()])->save();
        try {
            $this->store(
                $run,
                $folderRun,
                $cancelledItem,
                $folder,
                $namespace,
                82,
                $this->peeked(
                    $account,
                    $folder,
                    $namespace,
                    82,
                    '<store-claim-cancelled@example.test>',
                ),
            );
            $this->fail('Store wrote provider content after cancellation intent won.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_store_claim_stale', $exception->safeCode);
        }

        foreach ([81, 82] as $uid) {
            $this->assertDatabaseMissing('email_messages', [
                'account_id' => $account->id,
                'mailbox' => $folder->path,
                'imap_uid_validity' => $namespace->uid_validity,
                'imap_uid' => $uid,
            ]);
            $this->assertDatabaseMissing('email_mailbox_placements', [
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'uid_namespace_id' => $namespace->id,
                'imap_uid_validity' => $namespace->uid_validity,
                'imap_uid' => $uid,
            ]);
            Storage::disk('local')->assertMissing(sprintf(
                'email/raw/v2/%d/%s/%d/%d.eml',
                $account->id,
                hash('sha256', $folder->path),
                $namespace->uid_validity,
                $uid,
            ));
        }
        $this->assertTrue($cancelledItem->fresh()->last_attempt_at->equalTo($cancelledAt));
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function summary_phase_rejects_a_stale_store_claim_before_any_content_side_effect(): void
    {
        $account = $this->account('store-summary-fence@example.test');
        [$folder, $namespace] = $this->folder(
            $account,
            'Archive/Summary fence',
            EmailFolder::ROLE_ARCHIVE,
            713,
            83,
        );
        $run = $this->reconciliationRun($account);
        [$folderRun, $item] = $this->scope($run, $folder, $namespace, 80);
        $run->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_SUMMARY,
            'final_summary_status' => EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS,
            'final_summary_started_at' => now(),
        ])->save();
        $itemBefore = $item->fresh()->getAttributes();

        try {
            $this->store(
                $run->fresh(),
                $folderRun,
                $item,
                $folder,
                $namespace,
                80,
                $this->peeked(
                    $account,
                    $folder,
                    $namespace,
                    80,
                    '<store-summary-fence@example.test>',
                    [['summary.txt', 'private provider bytes', 'text/plain']],
                ),
            );
            $this->fail('A stale Store claim crossed the final-summary write barrier.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_store_claim_stale', $exception->safeCode);
        }

        $this->assertDatabaseMissing('email_messages', [
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 80,
        ]);
        $this->assertDatabaseMissing('email_mailbox_placements', [
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 80,
        ]);
        $this->assertDatabaseCount('email_attachments', 0);
        $this->assertDatabaseCount('email_canonical_messages', 0);
        $this->assertDatabaseCount('email_canonical_message_sources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('email'));
        $this->assertSame($itemBefore, $item->fresh()->getAttributes());
        Queue::assertNotPushed(ProcessInboundRules::class);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function ordinary_provider_ingest_stamps_strong_move_evidence_create_only_and_delayed_duplicates_cannot_regress_it(): void
    {
        $account = $this->account('ordinary-provider-observation@example.test');
        [$folder, $namespace] = $this->folder(
            $account,
            'Archive/Observed',
            EmailFolder::ROLE_ARCHIVE,
            713,
            91,
        );
        $receivedAt = now()->subHour()->startOfSecond();
        $payload = [
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'mailbox' => $folder->path,
            'uid_validity' => $namespace->uid_validity,
            'imap_uid' => 90,
            'message_id' => '<ordinary-observed@example.test>',
            'subject' => 'Strong provider observation',
            'from_email' => 'sender@example.test',
            'received_at' => $receivedAt,
            'size_bytes' => 4096,
            'is_oversize' => true,
            'provider_seen' => true,
            'run_inbound_rules' => false,
            'allow_provider_mutation' => false,
            'run_provider_reconciliation' => false,
        ];

        EmailAccountProviderLockContext::withinHeld(
            $account->id,
            fn () => app()->call([(new StoreInboundMessage($payload)), 'handle']),
        );
        $message = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('mailbox', $folder->path)
            ->where('imap_uid_validity', $namespace->uid_validity)
            ->where('imap_uid', 90)
            ->firstOrFail();
        $placement = $message->placements()->sole();
        $identity = app(EmailProviderMessageIdentity::class);
        $expectedHash = $identity->forMessage($message);
        $this->assertNotNull($expectedHash);
        $this->assertSame($expectedHash, $identity->forProviderPayload($payload));
        $this->assertSame($expectedHash, $placement->last_provider_observed_identity_hash);
        $this->assertSame($placement->sync_version, $placement->last_provider_observed_sync_version);
        $this->assertNotNull($placement->last_provider_observed_at);
        EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => hash('sha256', 'ordinary-observed-race:'.$placement->id),
            'source_folder_path' => $folder->path,
            'request_json' => ['seen' => false],
            'expected_placement_sync_version' => 7,
            'expected_provider_uid' => 90,
            'expected_uid_validity' => $namespace->uid_validity,
        ]);
        $placement->forceFill([
            'provider_seen' => false,
            'provider_flagged' => true,
            'flags_json' => ['\\Flagged'],
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_ERROR,
            'sync_version' => 7,
            'sync_error_code' => 'remote_operation_conflict',
            'sync_error_message' => 'The local operation remains authoritative.',
        ])->save();
        $beforeDelayedDuplicate = $placement->fresh()->getAttributes();

        $this->travel(2)->seconds();
        EmailAccountProviderLockContext::withinHeld(
            $account->id,
            fn () => app()->call([(new StoreInboundMessage($payload)), 'handle']),
        );

        $this->assertSame($beforeDelayedDuplicate, $placement->fresh()->getAttributes());
        $this->assertDatabaseHas('email_remote_operations', [
            'email_mailbox_placement_id' => $placement->id,
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'expected_placement_sync_version' => 7,
        ]);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function ordinary_duplicate_resumes_only_a_missing_conversation_pointer_without_reprojecting_the_placement(): void
    {
        $account = $this->account('ordinary-provider-conversation-resume@example.test');
        [$folder, $namespace] = $this->folder(
            $account,
            'Archive/Conversation-Crash',
            EmailFolder::ROLE_ARCHIVE,
            714,
            101,
        );
        $receivedAt = now()->subHour()->startOfSecond();
        $payload = [
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'mailbox' => $folder->path,
            'uid_validity' => $namespace->uid_validity,
            'imap_uid' => 100,
            'message_id' => '<ordinary-conversation-resume@example.test>',
            'subject' => 'Conversation pointer crash recovery',
            'from_email' => 'sender@example.test',
            'received_at' => $receivedAt,
            'size_bytes' => 2048,
            'is_oversize' => true,
            'provider_seen' => true,
            'run_inbound_rules' => false,
            'allow_provider_mutation' => false,
            'run_provider_reconciliation' => false,
        ];
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 100,
            'message_id' => $payload['message_id'],
            'subject' => $payload['subject'],
            'from_email' => $payload['from_email'],
            'received_at' => $receivedAt,
            'size_bytes' => $payload['size_bytes'],
            'is_oversize' => true,
            'state' => 'untriaged',
            'attachments_count' => 0,
        ]);
        $identityHash = app(EmailProviderMessageIdentity::class)->forMessage($message);
        $this->assertNotNull($identityHash);
        $placement = $this->placement($message, $folder, $namespace, 100);
        $placement->forceFill([
            'email_conversation_id' => null,
            'provider_seen' => false,
            'provider_flagged' => true,
            'flags_json' => ['\\Flagged'],
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_ERROR,
            'sync_version' => 7,
            'last_provider_observed_sync_version' => 7,
            'last_provider_observed_identity_hash' => $identityHash,
            'last_provider_observed_at' => now()->subMinutes(5),
            'last_reconciled_at' => now()->subMinutes(4),
            'sync_error_code' => 'remote_operation_conflict',
            'sync_error_message' => 'The local operation remains authoritative.',
        ])->save();
        app(\App\Modules\Email\Services\EmailCanonicalSelfMapper::class)->map($message);
        $before = $placement->fresh()->getAttributes();
        unset($before['email_conversation_id'], $before['updated_at']);

        $this->travel(2)->seconds();
        EmailAccountProviderLockContext::withinHeld(
            $account->id,
            fn () => app()->call([(new StoreInboundMessage($payload)), 'handle']),
        );

        $placement->refresh();
        $after = $placement->getAttributes();
        unset($after['email_conversation_id'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertNotNull($placement->email_conversation_id);
        $conversation = $placement->conversation()->firstOrFail();
        $this->assertSame(1, $conversation->message_count);
        $this->assertSame(1, $conversation->active_placement_count);
        $this->assertSame($message->id, $conversation->first_email_message_id);
        $this->assertSame($message->id, $conversation->latest_email_message_id);
        $this->assertSame($placement->id, $conversation->latest_email_mailbox_placement_id);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function preloaded_redelivery_repairs_partial_artifacts_and_preserves_legacy_files_without_provider_io(): void
    {
        $account = $this->account('store-resume@example.test');
        [$folder, $namespace] = $this->folder($account, 'Projects/A', EmailFolder::ROLE_CUSTOM, 701, 3);
        $run = $this->reconciliationRun($account);
        [$folderRun, $item] = $this->scope($run, $folder, $namespace, 2);
        $peeked = $this->peeked(
            $account,
            $folder,
            $namespace,
            2,
            '<resume-artifacts@example.test>',
            [
                ['first.txt', 'first private bytes', 'text/plain'],
                ['second.txt', 'second private bytes', 'text/plain'],
            ],
        );

        $legacyRawPath = 'email/raw/'.$account->id.'/Projects_A/701/2.eml';
        Storage::disk('local')->put($legacyRawPath, 'legacy bytes must not be trusted or deleted');
        $expectedRaw = sprintf(
            'email/raw/v2/%d/%s/701/2.eml',
            $account->id,
            hash('sha256', 'Projects/A'),
        );
        Storage::disk('local')->put($expectedRaw, 'truncated raw crash artifact');
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 2,
            'message_id' => '<resume-artifacts@example.test>',
            'subject' => 'Interrupted local store',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'size_bytes' => (int) $peeked->payload()['size_bytes'],
            'state' => 'untriaged',
            'raw_path' => $legacyRawPath,
            'attachments_count' => 9,
        ]);
        $pendingPlacement = $this->placement($message, $folder, $namespace, 2);
        $pendingPlacement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
        ])->save();

        $firstContent = 'first private bytes';
        $legacyAttachmentPath = sprintf(
            'email/attachments/%d/Projects_A/2/001-%s-first.txt',
            $account->id,
            substr(sha1($firstContent), 0, 12),
        );
        Storage::disk('local')->put($legacyAttachmentPath, 'truncated row-backed attachment');
        EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'first.txt',
            'content_type' => 'application/octet-stream',
            'size_bytes' => 1,
            'disk' => 'local',
            'path' => $legacyAttachmentPath,
            'is_inline' => true,
            'cid' => 'stale-content-id',
            'checksum_sha1' => sha1('stale metadata'),
        ]);
        $secondContent = 'second private bytes';
        $orphanAttachmentPath = sprintf(
            'email/attachments/v2/%d/%s/701/2/002-%s-second.txt',
            $account->id,
            hash('sha256', 'Projects/A'),
            substr(sha1($secondContent), 0, 12),
        );
        Storage::disk('local')->put($orphanAttachmentPath, 'truncated metadata-free attachment');

        $this->store($run, $folderRun, $item, $folder, $namespace, 2, $peeked);

        $message = $message->fresh();
        $this->assertSame($expectedRaw, $message->raw_path);
        Storage::disk('local')->assertExists($expectedRaw);
        $this->assertSame(
            app(EmailRawMessageSnapshot::class)->serialize($peeked->message()),
            Storage::disk('local')->get($expectedRaw),
        );
        Storage::disk('local')->assertExists($legacyRawPath);
        $this->assertSame(2, $message->attachments_count);
        $this->assertSame(2, $message->attachments()->count());
        $this->assertDatabaseHas('email_attachments', [
            'message_id' => $message->id,
            'path' => $legacyAttachmentPath,
            'filename' => 'first.txt',
            'content_type' => 'text/plain',
            'size_bytes' => strlen($firstContent),
            'is_inline' => false,
            'cid' => null,
            'checksum_sha1' => sha1($firstContent),
        ]);
        $this->assertSame($firstContent, Storage::disk('local')->get($legacyAttachmentPath));
        $newAttachment = $message->attachments()
            ->where('filename', 'second.txt')
            ->firstOrFail();
        $this->assertSame($orphanAttachmentPath, $newAttachment->path);
        $this->assertSame($secondContent, Storage::disk('local')->get($newAttachment->path));
        $this->assertStringStartsWith(
            'email/attachments/v2/'.$account->id.'/'.hash('sha256', 'Projects/A').'/701/2/',
            $newAttachment->path,
        );
        Storage::disk('local')->assertExists($newAttachment->path);

        $this->store($run, $folderRun, $item, $folder, $namespace, 2, $peeked);
        $this->assertSame(2, $message->attachments()->count());
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function preexisting_active_artifact_failures_are_attested_without_any_repair_or_state_mutation(): void
    {
        $account = $this->account('store-preexisting-immutable@example.test');
        [$folder, $namespace] = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 711, 70);
        $run = $this->reconciliationRun($account);
        $disk = Storage::disk('local');
        $cases = [];

        foreach ([
            70 => 'raw',
            71 => 'attachment',
        ] as $uid => $corruptArtifact) {
            [$folderRun, $item] = $this->scope($run, $folder, $namespace, $uid);
            $peeked = $this->peeked(
                $account,
                $folder,
                $namespace,
                $uid,
                '<preexisting-'.$uid.'@example.test>',
                [['active-'.$uid.'.txt', 'active attachment '.$uid, 'text/plain']],
            );
            $raw = app(EmailRawMessageSnapshot::class)->serialize($peeked->message());
            $this->assertNotNull($raw);
            $rawPath = 'email/raw/legacy-preexisting-'.$uid.'.eml';
            $disk->put($rawPath, $raw);
            $message = EmailMessage::query()->create([
                'account_id' => $account->id,
                'mailbox' => $folder->path,
                'imap_uid_validity' => $namespace->uid_validity,
                'imap_uid' => $uid,
                'message_id' => '<preexisting-'.$uid.'@example.test>',
                'subject' => 'Existing active content '.$uid,
                'from_email' => 'sender@example.test',
                'received_at' => now()->subMinute(),
                'size_bytes' => strlen($raw),
                'state' => 'untriaged',
                'body_text' => 'Existing active body '.$uid,
                'raw_path' => $rawPath,
                'attachments_count' => 0,
            ]);
            $placement = $this->placement($message, $folder, $namespace, $uid);
            $attachmentResult = app(InboundAttachmentPersister::class)->persistWithResult(
                $message,
                $peeked->message()->getAttachments(),
            );
            $this->assertFalse($attachmentResult->hasFailures());
            $message->forceFill([
                'attachments_count' => $message->attachments()->count(),
            ])->save();
            $attachment = $message->attachments()->sole();
            $conversation = app(\App\Modules\Email\Services\EmailConversationProjector::class)
                ->assignPlacement($placement);
            $this->assertNotNull($conversation);
            app(\App\Modules\Email\Services\EmailCanonicalSelfMapper::class)->map($message);
            $placement->refresh()->forceFill([
                'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                'sync_status' => EmailMailboxPlacement::SYNC_ERROR,
                'sync_version' => 7,
                'sync_error_code' => 'active_operation_conflict',
                'sync_error_message' => 'Existing active conflict remains authoritative.',
                'remote_modseq' => 900 + $uid,
                'provider_seen' => true,
                'provider_flagged' => true,
                'flags_json' => ['\\Seen', '\\Flagged'],
            ])->save();

            if ($corruptArtifact === 'raw') {
                $disk->put($rawPath, 'corrupt active raw bytes');
            } else {
                $disk->put($attachment->path, 'corrupt active attachment bytes');
            }

            $cases[] = [
                'folder_run' => $folderRun,
                'item' => $item,
                'uid' => $uid,
                'peeked' => $peeked,
                'message' => $message,
                'placement' => $placement,
                'attachment' => $attachment,
                'conversation' => $conversation,
                'message_before' => $message->fresh()->getAttributes(),
                'placement_before' => $placement->fresh()->getAttributes(),
                'attachment_before' => $attachment->fresh()->getAttributes(),
                'conversation_before' => $conversation->fresh()->getAttributes(),
                'raw_before' => $disk->get($rawPath),
                'attachment_bytes_before' => $disk->get($attachment->path),
                'safe_code' => $corruptArtifact === 'raw'
                    ? 'reconciliation_store_artifacts_incomplete'
                    : 'reconciliation_attachment_persistence_failed',
            ];
        }

        // Any old repair attempt would now encounter a failing write. The new
        // PREEXISTING seam must not call put at all, even for corrupt content.
        $writeForbiddenDisk = \Mockery::mock($disk);
        $writeForbiddenDisk->shouldReceive('put')->never();
        Storage::shouldReceive('disk')->with('local')->andReturn($writeForbiddenDisk);

        foreach ($cases as $case) {
            try {
                $this->store(
                    $run,
                    $case['folder_run'],
                    $case['item'],
                    $folder,
                    $namespace,
                    $case['uid'],
                    $case['peeked'],
                );
                $this->fail('A corrupt PREEXISTING active occurrence passed Store attestation.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame($case['safe_code'], $exception->safeCode);
                $this->assertNull($exception->getPrevious());
            }

            $this->assertSame($case['message_before'], $case['message']->fresh()->getAttributes());
            $this->assertSame($case['placement_before'], $case['placement']->fresh()->getAttributes());
            $this->assertSame($case['attachment_before'], $case['attachment']->fresh()->getAttributes());
            $this->assertSame($case['conversation_before'], $case['conversation']->fresh()->getAttributes());
            $this->assertSame($case['raw_before'], $disk->get($case['message']->raw_path));
            $this->assertSame(
                $case['attachment_bytes_before'],
                $disk->get($case['attachment']->path),
            );
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function exact_mailbox_hash_paths_do_not_collide_and_deleting_one_identity_leaves_the_other_intact(): void
    {
        $account = $this->account('store-paths@example.test');
        $run = $this->reconciliationRun($account);
        $stored = [];

        foreach (['Projects/A', 'Projects_A'] as $path) {
            [$folder, $namespace] = $this->folder($account, $path, EmailFolder::ROLE_CUSTOM, 702, 8);
            [$folderRun, $item] = $this->scope($run, $folder, $namespace, 7);
            $peeked = $this->peeked(
                $account,
                $folder,
                $namespace,
                7,
                '<'.str_replace('/', '-', $path).'@example.test>',
                [['same.txt', 'same attachment bytes', 'text/plain']],
            );

            $result = $this->store($run, $folderRun, $item, $folder, $namespace, 7, $peeked);
            $message = EmailMessage::query()->findOrFail($result->messageId);
            $stored[] = [
                'message' => $message,
                'raw' => $message->raw_path,
                'attachment' => $message->attachments()->sole()->path,
            ];
        }

        $this->assertNotSame($stored[0]['raw'], $stored[1]['raw']);
        $this->assertNotSame($stored[0]['attachment'], $stored[1]['attachment']);
        $this->assertStringContainsString(hash('sha256', 'Projects/A'), $stored[0]['raw']);
        $this->assertStringContainsString(hash('sha256', 'Projects_A'), $stored[1]['raw']);

        Storage::disk('local')->delete([
            $stored[0]['raw'],
            $stored[0]['attachment'],
        ]);
        Storage::disk('local')->assertMissing($stored[0]['raw']);
        Storage::disk('local')->assertMissing($stored[0]['attachment']);
        Storage::disk('local')->assertExists($stored[1]['raw']);
        Storage::disk('local')->assertExists($stored[1]['attachment']);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function preloaded_placement_failure_rolls_back_message_and_writes_no_private_files_while_ordinary_orphans_stay_closed(): void
    {
        $account = $this->account('store-atomic-placement@example.test');
        [$folder, $namespace] = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 710, 41);
        $run = $this->reconciliationRun($account);
        [$folderRun, $item] = $this->scope($run, $folder, $namespace, 40);
        $peeked = $this->peeked(
            $account,
            $folder,
            $namespace,
            40,
            '<atomic-placement-failure@example.test>',
            [['never-orphan.txt', 'never orphan private bytes', 'text/plain']],
        );
        EmailMailboxPlacement::creating(function (): never {
            throw new \RuntimeException('PRIVATE-PLACEMENT-CREATE-CANARY');
        });

        try {
            $this->store($run, $folderRun, $item, $folder, $namespace, 40, $peeked);
            $this->fail('A preloaded message committed without its hidden placement.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_store_failed', $exception->safeCode);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE-PLACEMENT', $exception->getMessage());
        }

        $this->assertDatabaseMissing('email_messages', [
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 40,
        ]);
        $this->assertDatabaseCount('email_mailbox_placements', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('email'));

        // Fail closed on the legacy crash shape too: an ordinary duplicate
        // poll cannot turn an unplaced/incomplete row ACTIVE or run rules.
        $orphan = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 41,
            'message_id' => '<legacy-orphan@example.test>',
            'subject' => 'Incomplete orphan must stay closed',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'raw_path' => null,
            'attachments_count' => 0,
        ]);
        $ordinary = new StoreInboundMessage([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'mailbox' => $folder->path,
            'uid_validity' => $namespace->uid_validity,
            'imap_uid' => 41,
            'message_id' => $orphan->message_id,
            'subject' => $orphan->subject,
            'received_at' => $orphan->received_at,
            'size_bytes' => 100,
            'is_oversize' => false,
            'run_inbound_rules' => true,
            'allow_provider_mutation' => false,
            'run_provider_reconciliation' => false,
        ]);
        EmailAccountProviderLockContext::withinHeld(
            $account->id,
            fn () => app()->call([$ordinary, 'handle']),
        );

        $this->assertDatabaseMissing('email_mailbox_placements', [
            'email_message_id' => $orphan->id,
        ]);
        Queue::assertNotPushed(ProcessInboundRules::class);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function preloaded_orphan_commits_hidden_placement_and_raw_reference_before_repair_bytes(): void
    {
        $account = $this->account('store-orphan-repair-order@example.test');
        [$folder, $namespace] = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 711, 43);
        $run = $this->reconciliationRun($account);
        [$folderRun, $item] = $this->scope($run, $folder, $namespace, 42);
        $orphan = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 42,
            'message_id' => '<preloaded-orphan-repair@example.test>',
            'subject' => 'Preloaded orphan repair',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'raw_path' => null,
            'attachments_count' => 0,
        ]);
        $peeked = $this->peeked(
            $account,
            $folder,
            $namespace,
            42,
            $orphan->message_id,
            [['repair.txt', 'repair private bytes', 'text/plain']],
        );
        $expectedRawPath = sprintf(
            'email/raw/v2/%d/%s/711/42.eml',
            $account->id,
            hash('sha256', 'INBOX'),
        );
        Storage::disk('local')->put('email/raw', 'block raw directory creation');

        try {
            $this->store($run, $folderRun, $item, $folder, $namespace, 42, $peeked);
            $this->fail('A preloaded orphan repair ignored a raw write failure.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_raw_persistence_failed', $exception->safeCode);
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame($expectedRawPath, $orphan->fresh()->raw_path);
        Storage::disk('local')->assertMissing($expectedRawPath);
        $placement = $orphan->placements()->sole();
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_PENDING, $placement->sync_status);
        $this->assertSame(
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            $placement->sync_error_code,
        );
        $this->assertFalse($orphan->hasActiveProviderPlacement());
        Queue::assertNothingPushed();

        Storage::disk('local')->delete('email/raw');
        $stored = $this->store(
            $run,
            $folderRun,
            $item,
            $folder,
            $namespace,
            42,
            $peeked,
        );
        $this->assertSame(EmailPlacementCreateResult::RESUMED_PENDING, $stored->placementDisposition);
        Storage::disk('local')->assertExists($expectedRawPath);
        $this->assertSame(1, $orphan->fresh()->attachments_count);
        $this->assertSame(1, $orphan->attachments()->count());
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function policy_rejection_completes_but_allowed_attachment_storage_failure_is_safe_and_retryable(): void
    {
        $account = $this->account('store-policy@example.test');
        [$folder, $namespace] = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 703, 12);
        $run = $this->reconciliationRun($account);
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'attachment_allowed_mime_types'],
            ['value' => 'application/pdf'],
        );
        [$rejectedFolderRun, $rejectedItem] = $this->scope($run, $folder, $namespace, 10);
        $rejected = $this->peeked(
            $account,
            $folder,
            $namespace,
            10,
            '<policy-rejected@example.test>',
            [['ignored.txt', 'policy content', 'text/plain']],
        );

        $result = $this->store(
            $run,
            $rejectedFolderRun,
            $rejectedItem,
            $folder,
            $namespace,
            10,
            $rejected,
        );
        $rejectedMessage = EmailMessage::query()->findOrFail($result->messageId);
        $this->assertSame(EmailPlacementCreateResult::CREATED_PENDING, $result->placementDisposition);
        $this->assertSame(0, $rejectedMessage->attachments_count);
        $this->assertSame(0, $rejectedMessage->attachments()->count());
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $result->placementId,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
        ]);

        CommonSetting::query()->where('type', 'emailhub')
            ->where('name', 'attachment_allowed_mime_types')
            ->update(['value' => 'text/plain']);
        [$failedFolderRun, $failedItem] = $this->scope($run, $folder, $namespace, 11);
        $canary = 'PRIVATE-CONTENT-CANARY-DO-NOT-LOG';
        $failed = $this->peeked(
            $account,
            $folder,
            $namespace,
            11,
            '<private-address-canary@example.test>',
            [['PRIVATE-FILENAME-CANARY.txt', $canary, 'text/plain']],
        );
        Storage::disk('local')->put('email/attachments', 'block attachment directory creation');
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        try {
            $this->store($run, $failedFolderRun, $failedItem, $folder, $namespace, 11, $failed);
            $this->fail('The strict reconciliation store accepted an attachment write failure.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_attachment_persistence_failed', $exception->safeCode);
            $this->assertSame(
                'The provider could not complete a bounded reconciliation read.',
                $exception->getMessage(),
            );
            $this->assertNull($exception->getPrevious());
        }

        $joinedLog = implode("\n", $logged);
        $this->assertStringNotContainsString($canary, $joinedLog);
        $this->assertStringNotContainsString('PRIVATE-FILENAME-CANARY', $joinedLog);
        $this->assertStringNotContainsString('private-address-canary', $joinedLog);

        $failedMessage = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('mailbox', $folder->path)
            ->where('imap_uid_validity', $namespace->uid_validity)
            ->where('imap_uid', 11)
            ->firstOrFail();
        $failedPlacement = $failedMessage->placements()->sole();
        $failedAttachment = $failedMessage->attachments()->sole();
        $this->assertSame(1, $failedMessage->attachments_count);
        Storage::disk('local')->assertMissing($failedAttachment->path);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $failedPlacement->local_state);
        $this->assertSame(
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            $failedPlacement->sync_error_code,
        );
        $this->assertPendingMessageIsInaccessible($account, $failedMessage, $failedPlacement);

        Storage::disk('local')->delete('email/attachments');
        $retried = $this->store(
            $run,
            $failedFolderRun,
            $failedItem,
            $folder,
            $namespace,
            11,
            $failed,
        );
        $this->assertSame(
            EmailPlacementCreateResult::RESUMED_PENDING,
            $retried->placementDisposition,
        );
        $message = EmailMessage::query()->findOrFail($retried->messageId);
        $this->assertSame(1, $message->attachments_count);
        $this->assertSame(1, $message->attachments()->count());
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function new_folder_history_stays_hidden_until_bounded_baseline_while_a_later_live_uid_is_normal(): void
    {
        Permission::findOrCreate('email.inbox_view', 'web');
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('email.inbox_view');
        $account = $this->account('store-baseline@example.test');
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
        ]);
        EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'direct_grant',
            'recorded_at' => now()->subHour(),
            'entitlement_changed_at' => now()->subHour(),
        ]);
        [$folder, $namespace] = $this->folder($account, 'New/Customer', EmailFolder::ROLE_CUSTOM, 704, 9);
        $run = $this->reconciliationRun($account);
        [$historyFolderRun, $historyItem] = $this->scope(
            $run,
            $folder,
            $namespace,
            9,
            EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            9,
        );
        $history = $this->peeked(
            $account,
            $folder,
            $namespace,
            9,
            '<new-folder-history@example.test>',
        );
        $historyResult = $this->store(
            $run,
            $historyFolderRun,
            $historyItem,
            $folder,
            $namespace,
            9,
            $history,
        );
        $historyMessage = EmailMessage::query()->findOrFail($historyResult->messageId);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $historyMessage->id,
            'user_id' => $viewer->id,
        ]);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $historyResult->placementId,
            'email_message_id' => $historyMessage->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ]);

        [, $malformedLiveItem] = $this->scope(
            $run,
            $folder,
            $namespace,
            10,
            EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            9,
        );
        try {
            $this->store(
                $run,
                $historyFolderRun,
                $malformedLiveItem,
                $folder,
                $namespace,
                10,
                $this->peeked(
                    $account,
                    $folder,
                    $namespace,
                    10,
                    '<malformed-new-folder-live@example.test>',
                ),
            );
            $this->fail('A post-high-water UID used the historical no-rules policy.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame(
                'reconciliation_new_folder_baseline_scope_mismatch',
                $exception->safeCode,
            );
        }
        $this->assertDatabaseMissing('email_messages', [
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 10,
        ]);

        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_COMPLETED,
            'active_slot' => null,
            'finished_at' => now(),
        ])->save();
        $liveRun = $this->reconciliationRun($account);
        [$liveFolderRun, $liveItem] = $this->scope(
            $liveRun,
            $folder,
            $namespace,
            10,
            EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            EmailProviderReconciliationFolder::IMPORT_LIVE,
            10,
        );
        $live = $this->peeked(
            $account,
            $folder,
            $namespace,
            10,
            '<new-folder-live@example.test>',
        );
        $liveResult = $this->store(
            $liveRun,
            $liveFolderRun,
            $liveItem,
            $folder,
            $namespace,
            10,
            $live,
        );
        $liveMessage = EmailMessage::query()->findOrFail($liveResult->messageId);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $liveMessage->id,
            'user_id' => $viewer->id,
        ]);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $liveResult->placementId,
            'email_message_id' => $liveMessage->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
        ]);
        $this->assertTrue(app(EmailUnreadForMeResolver::class)->resolve($liveMessage, $viewer));
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function deferred_draft_and_sent_placements_project_locally_before_activation_without_provider_io(): void
    {
        $account = $this->account('store-local-projection@example.test');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $run = $this->reconciliationRun($account);

        [$draftFolder, $draftNamespace] = $this->folder(
            $account,
            'Drafts',
            EmailFolder::ROLE_DRAFTS,
            705,
            21,
        );
        [$draftFolderRun, $draftItem] = $this->scope(
            $run,
            $draftFolder,
            $draftNamespace,
            20,
            EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            21,
        );
        $draft = EmailComposerDraft::query()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'provider_binding_version' => 1,
            'mode' => 'compose',
            'draft_key' => 'local-projection-draft',
            'status' => EmailComposerDraft::STATUS_ACTIVE,
            'subject' => 'Draft projection',
            'body_text' => 'Draft body',
            'last_saved_at' => now(),
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
            'provider_draft_message_id' => '<draft-local@example.test>',
            'provider_draft_normalized_message_id' => 'draft-local@example.test',
        ]);
        $staleDraft = EmailComposerDraft::query()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'provider_binding_version' => 2,
            'mode' => 'compose',
            'draft_key' => 'stale-local-projection-draft',
            'status' => EmailComposerDraft::STATUS_ACTIVE,
            'subject' => 'Stale Draft projection',
            'body_text' => 'Stale Draft body',
            'last_saved_at' => now()->addSecond(),
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
            'provider_draft_message_id' => '<draft-local@example.test>',
            'provider_draft_normalized_message_id' => 'draft-local@example.test',
        ]);
        $draftPeeked = $this->peeked(
            $account,
            $draftFolder,
            $draftNamespace,
            20,
            '<draft-local@example.test>',
        );
        $draftStored = $this->store(
            $run,
            $draftFolderRun,
            $draftItem,
            $draftFolder,
            $draftNamespace,
            20,
            $draftPeeked,
        );
        $this->assertSame(
            EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
            $draft->fresh()->provider_draft_status,
        );
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $draftStored->placementId,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ]);
        // An exact Store redelivery repairs artifacts but still cannot expose
        // or project the Draft before the durable baseline item owns it.
        $this->store(
            $run,
            $draftFolderRun,
            $draftItem,
            $draftFolder,
            $draftNamespace,
            20,
            $draftPeeked,
        );
        $this->assertSame(
            EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
            $draft->fresh()->provider_draft_status,
        );
        $this->completeDeferredBaseline($draftItem, $draftStored->placementId);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_SYNCED, $draft->fresh()->provider_draft_status);
        $this->assertSame(20, $draft->fresh()->provider_draft_uid);
        $this->assertSame(EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED, $staleDraft->fresh()->provider_draft_status);

        [$sentFolder, $sentNamespace] = $this->folder(
            $account,
            'Sent',
            EmailFolder::ROLE_SENT,
            706,
            31,
        );
        [$sentFolderRun, $sentItem] = $this->scope(
            $run,
            $sentFolder,
            $sentNamespace,
            30,
            EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            31,
        );
        $log = EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $account->id,
            'rfc_message_id' => '<sent-local@example.test>',
            'scope' => 'inbox',
            'level' => 'info',
        ]);
        $sentReconciliation = EmailSentReconciliation::query()->create([
            'email_log_id' => $log->id,
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'rfc_message_id' => '<sent-local@example.test>',
            'normalized_message_id' => 'sent-local@example.test',
            'status' => EmailSentReconciliation::STATUS_PENDING,
        ]);
        $sentPeeked = $this->peeked(
            $account,
            $sentFolder,
            $sentNamespace,
            30,
            '<sent-local@example.test>',
        );
        $sentStored = $this->store(
            $run,
            $sentFolderRun,
            $sentItem,
            $sentFolder,
            $sentNamespace,
            30,
            $sentPeeked,
        );
        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $sentReconciliation->fresh()->status);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $sentStored->placementId,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ]);
        $this->store(
            $run,
            $sentFolderRun,
            $sentItem,
            $sentFolder,
            $sentNamespace,
            30,
            $sentPeeked,
        );
        $this->assertSame(EmailSentReconciliation::STATUS_PENDING, $sentReconciliation->fresh()->status);
        $this->completeDeferredBaseline($sentItem, $sentStored->placementId);
        $sentReconciliation = $sentReconciliation->fresh();
        $this->assertSame(EmailSentReconciliation::STATUS_RECONCILED, $sentReconciliation->status);
        $this->assertSame($sentStored->messageId, $sentReconciliation->sent_email_message_id);
        $this->assertSame($sentStored->placementId, $sentReconciliation->sent_email_mailbox_placement_id);
        $this->assertNotSame($draftStored->placementId, $sentStored->placementId);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function canonical_projection_failure_remains_safe_and_retryable_before_store_success(): void
    {
        $account = $this->account('store-canonical@example.test');
        [$folder, $namespace] = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 707, 41);
        $existingMessage = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 39,
            'message_id' => '<canonical-visible-root@example.test>',
            'subject' => 'Visible conversation remains unchanged',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMinute(),
            'state' => 'untriaged',
        ]);
        $existingPlacement = $this->placement($existingMessage, $folder, $namespace, 39);
        $visibleConversation = app(\App\Modules\Email\Services\EmailConversationProjector::class)
            ->assignPlacement($existingPlacement);
        $this->assertNotNull($visibleConversation);
        $visibleConversationBefore = $visibleConversation->fresh()->getAttributes();
        $run = $this->reconciliationRun($account);
        [$folderRun, $item] = $this->scope($run, $folder, $namespace, 40);
        $peeked = $this->peeked(
            $account,
            $folder,
            $namespace,
            40,
            '<canonical-retry@example.test>',
            [['canonical.txt', 'canonical attachment', 'text/plain']],
            '<canonical-visible-root@example.test>',
        );
        $failCanonicalCreate = true;
        EmailCanonicalMessage::creating(function () use (&$failCanonicalCreate): void {
            if ($failCanonicalCreate) {
                throw new \RuntimeException('PRIVATE-CANONICAL-CREATE-CANARY');
            }
        });

        try {
            $this->store($run, $folderRun, $item, $folder, $namespace, 40, $peeked);
            $this->fail('The reconciliation Store attested success without its canonical dual-write.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('reconciliation_canonical_projection_failed', $exception->safeCode);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE-CANONICAL', $exception->getMessage());
        }

        $message = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('mailbox', $folder->path)
            ->where('imap_uid_validity', $namespace->uid_validity)
            ->where('imap_uid', 40)
            ->firstOrFail();
        $placement = EmailMailboxPlacement::query()
            ->where('email_message_id', $message->id)
            ->firstOrFail();
        $this->assertNotNull($message->raw_path);
        $this->assertSame(1, $message->attachments_count);
        $this->assertNull($placement->canonical_email_message_id);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_PENDING, $placement->sync_status);
        $this->assertSame(
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            $placement->sync_error_code,
        );
        $this->assertSame($visibleConversation->id, $placement->email_conversation_id);
        $this->assertSame(
            $visibleConversationBefore,
            $visibleConversation->fresh()->getAttributes(),
        );
        $this->assertDatabaseMissing('email_canonical_message_sources', [
            'source_email_message_id' => $message->id,
        ]);
        $this->assertNull($item->fresh()->automation_status);
        Queue::assertNothingPushed();
        $this->assertPendingMessageIsInaccessible(
            $account,
            $message,
            $placement,
            $message->attachments()->sole(),
        );

        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_ERROR,
            'sync_version' => 7,
            'sync_error_code' => 'remote_operation_conflict',
            'sync_error_message' => 'Safe local conflict detail.',
            'remote_modseq' => 999,
            'provider_seen' => true,
            'provider_flagged' => true,
            'flags_json' => ['\\Seen', '\\Flagged'],
        ])->save();
        EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => hash('sha256', 'store-redelivery-conflict:'.$placement->id),
            'source_folder_path' => $folder->path,
            'request_json' => ['seen' => true],
            'expected_placement_sync_version' => 7,
            'expected_provider_uid' => 40,
            'expected_uid_validity' => 707,
        ]);
        $preservedProjection = $placement->fresh()->only([
            'local_state',
            'sync_status',
            'sync_version',
            'sync_error_code',
            'sync_error_message',
            'remote_modseq',
            'provider_seen',
            'provider_flagged',
            'flags_json',
        ]);

        $failCanonicalCreate = false;
        $stored = $this->store(
            $run,
            $folderRun,
            $item,
            $folder,
            $namespace,
            40,
            $peeked,
        );
        $this->assertSame(EmailPlacementCreateResult::PREEXISTING, $stored->placementDisposition);
        $this->assertSame(7, $stored->placementSyncVersion);
        $mapping = EmailCanonicalMessageSource::query()
            ->where('source_email_message_id', $stored->messageId)
            ->firstOrFail();
        $this->assertSame(
            $mapping->canonical_email_message_id,
            EmailMailboxPlacement::query()->findOrFail($stored->placementId)->canonical_email_message_id,
        );
        $this->assertSame(1, EmailCanonicalMessageSource::query()
            ->where('source_email_message_id', $stored->messageId)
            ->count());
        $this->assertSame(
            $preservedProjection,
            EmailMailboxPlacement::query()->findOrFail($stored->placementId)->only(
                array_keys($preservedProjection),
            ),
        );
        $this->assertDatabaseHas('email_remote_operations', [
            'email_mailbox_placement_id' => $placement->id,
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'expected_placement_sync_version' => 7,
        ]);
        $this->assertSame(0, $this->providerClientResolutions);
    }

    #[Test]
    public function moved_and_renamed_active_placements_remain_readable_without_legacy_message_tuple_parity(): void
    {
        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('email.raw_source_view', 'web');
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo(['email.inbox_view', 'email.raw_source_view']);
        $account = $this->account('moved-placement-access@example.test');
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        [$sourceFolder, $sourceNamespace] = $this->folder(
            $account,
            'INBOX',
            EmailFolder::ROLE_INBOX,
            708,
            50,
        );
        [$targetFolder, $targetNamespace] = $this->folder(
            $account,
            'Archive/2026',
            EmailFolder::ROLE_ARCHIVE,
            709,
            75,
        );
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $sourceFolder->path,
            'imap_uid_validity' => $sourceNamespace->uid_validity,
            'imap_uid' => 50,
            'message_id' => '<moved-placement-access@example.test>',
            'subject' => 'Moved placement remains readable',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Moved body.',
            'raw_path' => 'email/raw/moved-placement.eml',
            'attachments_count' => 1,
        ]);
        $sourcePlacement = $this->placement($message, $sourceFolder, $sourceNamespace, 50);
        $sourcePlacement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
        ])->save();
        $targetPlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $targetFolder->id,
            'uid_namespace_id' => $targetNamespace->id,
            'provider' => 'imap',
            'folder_path' => $targetFolder->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $targetNamespace->uid_validity,
            'imap_uid' => 75,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 7,
        ]);
        Storage::disk('local')->put($message->raw_path, 'moved raw bytes');
        $attachment = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'moved.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 22,
            'disk' => 'local',
            'path' => 'email/attachments/moved.txt',
            'checksum_sha1' => sha1('moved attachment bytes'),
        ]);
        Storage::disk('local')->put($attachment->path, 'moved attachment bytes');

        $this->assertTrue($message->hasActiveProviderPlacement($targetPlacement));
        $this->assertFalse($message->hasActiveProviderPlacement($sourcePlacement->fresh()));
        $this->assertTrue(app(\App\Modules\Email\Services\MailboxAccess::class)
            ->canViewMessage($viewer, $message));
        $this->assertFalse($message->isActiveProviderInboxMessage());

        $this->actingAs($viewer)
            ->get(route('tech.mail.raw-source.show', $targetPlacement))
            ->assertOk();
        $this->get(route('tech.mail.attachments.download', [$targetPlacement, $attachment]))
            ->assertOk();
        $this->get(route('tech.inbox.show', $message))->assertNotFound();

        $targetFolder->forceFill([
            'path' => 'Archive/Renamed',
            'name' => 'Renamed',
        ])->save();
        $targetPlacement->forceFill(['folder_path' => 'Archive/Renamed'])->save();
        $targetPlacement->refresh();

        $this->assertTrue($message->hasActiveProviderPlacement($targetPlacement));
        $this->assertTrue(app(\App\Modules\Email\Services\MailboxAccess::class)
            ->canViewMessage($viewer, $message));
        $this->get(route('tech.mail.raw-source.show', $targetPlacement))->assertOk();
        $this->get(route('tech.mail.attachments.download', [$targetPlacement, $attachment]))
            ->assertOk();
        $this->assertSame(0, $this->providerClientResolutions);
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Store Boundary',
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
    }

    private function assertPendingMessageIsInaccessible(
        EmailAccount $account,
        EmailMessage $message,
        EmailMailboxPlacement $placement,
        ?EmailAttachment $attachment = null,
    ): void {
        foreach ([
            'email.inbox_view',
            'email.inbox_manage',
            'email.raw_source_view',
            'email.rule_manage',
            'email.rule_reprocess',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo([
            'email.inbox_view',
            'email.inbox_manage',
            'email.raw_source_view',
            'email.rule_manage',
            'email.rule_reprocess',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $rule = EmailRule::query()->create([
            'name' => 'Pending boundary '.$message->id,
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
            'is_active' => true,
            'conditions_json' => [[
                'field' => 'subject',
                'operator' => 'contains',
                'value' => 'Provider',
            ]],
            'actions_json' => [['type' => 'archive', 'value' => null]],
        ]);
        $rule->accounts()->attach($account->id);
        app(EmailRulePublisher::class)->publish($rule, $viewer);

        $this->actingAs($viewer)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertDontSee((string) $message->subject);
        $this->get(route('tech.inbox.show', $message))->assertNotFound();
        $this->get(route('tech.mail.raw-source.show', $placement))->assertNotFound();
        if ($attachment) {
            $this->get(route('tech.inbox.download', $attachment))->assertNotFound();
            $this->get(route('tech.mail.attachments.download', [$placement, $attachment]))
                ->assertNotFound();
        }

        Sanctum::actingAs($viewer, ['email.read', 'email.update', 'email.rules.read']);
        $this->getJson(route('api.v1.email.inbox.messages.index'))
            ->assertOk()
            ->assertJsonMissing(['id' => $message->id]);
        $this->getJson(route('api.v1.email.inbox.messages.show', $message))->assertNotFound();
        $this->postJson(
            route('api.v1.email.mailbox.placements.operations.store', $placement),
            ['operation' => 'mark_seen'],
        )->assertNotFound();
        $this->postJson(route('api.v1.email.rules.preview', $rule), [
            'email_message_id' => $message->id,
        ])->assertNotFound();

        $this->actingAs($viewer)
            ->post(route('tech.admin.settings.email.rules.reprocess-preview', $rule), [
                'email_message_id' => $message->id,
            ])
            ->assertNotFound();
        Queue::assertNotPushed(ProcessInboundRules::class);
    }

    /** @return array{EmailFolder, EmailFolderUidNamespace} */
    private function folder(
        EmailAccount $account,
        string $path,
        string $role,
        int $uidValidity,
        int $liveStartUid,
    ): array {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => basename(str_replace('\\', '/', $path)),
            'delimiter' => '/',
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'uid_next' => $liveStartUid + 1,
            'live_start_uid' => $liveStartUid,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'uid_next_at_establishment' => $liveStartUid + 1,
            'live_start_uid' => $liveStartUid,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$folder->refresh(), $namespace];
    }

    private function reconciliationRun(EmailAccount $account): EmailProviderReconciliationRun
    {
        return EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'store-test:'.$account->id.':'.microtime(true)),
            'provider_binding_version' => 1,
            'max_folders' => 20,
            'uid_batch_size' => 20,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function completeDeferredBaseline(
        EmailProviderReconciliationItem $item,
        int $placementId,
    ): void {
        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'result_placement_id' => $placementId,
            'completed_at' => null,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => 0,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_claim_token' => null,
            'historical_baseline_attempt_count' => 0,
            'historical_baseline_frozen_at' => now(),
            'historical_baseline_first_attempt_at' => null,
            'historical_baseline_last_attempt_at' => null,
            'historical_baseline_completed_at' => null,
            'historical_baseline_error_code' => null,
        ])->save();

        $projection = app(ProjectHistoricalEmailReadBaseline::class);
        $token = $projection->claimReconciliationBatch($item->id);
        $this->assertNotNull($token);
        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_COMPLETED,
            $projection->projectReconciliationBatch($item->id, $token),
        );
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placementId,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_error_code' => null,
        ]);
    }

    /** @return array{EmailProviderReconciliationFolder, EmailProviderReconciliationItem} */
    private function scope(
        EmailProviderReconciliationRun $run,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
        string $discovery = EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
        string $importPolicy = EmailProviderReconciliationFolder::IMPORT_LIVE,
        ?int $scanThroughUid = null,
    ): array {
        $folderRun = EmailProviderReconciliationFolder::query()->firstOrCreate(
            [
                'email_provider_reconciliation_run_id' => $run->id,
                'folder_path' => $folder->path,
            ],
            [
                'account_id' => $run->account_id,
                'email_folder_id' => $folder->id,
                'uid_namespace_id' => $namespace->id,
                'folder_name' => $folder->name,
                'delimiter' => $folder->delimiter,
                'discovery_state' => $discovery,
                'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                'import_policy' => $importPolicy,
                'expected_uid_validity' => $namespace->uid_validity,
                'start_uid_validity' => $namespace->uid_validity,
                'scan_through_uid' => $scanThroughUid ?? $uid,
                'next_uid' => $uid + 1,
            ],
        );
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
            'attempt_count' => 1,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
        ]);

        return [$folderRun, $item];
    }

    private function peeked(
        EmailAccount $account,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
        string $messageId,
        array $attachments = [],
        ?string $inReplyTo = null,
    ): EmailProviderReconciliationPeekedMessage {
        $mime = (new Email)
            ->from('sender@example.test')
            ->to($account->address)
            ->subject('Provider store test')
            ->text('Provider store body.');
        $mime->getHeaders()->addIdHeader('Message-ID', trim($messageId, '<>'));
        if ($inReplyTo !== null) {
            $mime->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
            $mime->getHeaders()->addTextHeader('References', $inReplyTo);
        }
        foreach ($attachments as [$filename, $content, $contentType]) {
            $mime->attach($content, $filename, $contentType);
        }
        $raw = $mime->toString();
        $message = Message::fromString($raw);
        $message->setUid($uid)->setFolderPath($folder->path);
        $payload = app(EmailProviderReconciliationMessagePayload::class)->make(
            $message,
            $account->id,
            1,
            $folder->path,
            $namespace->uid_validity,
            strlen($raw),
            false,
        );

        return new EmailProviderReconciliationPeekedMessage($payload, $message);
    }

    private function store(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailProviderReconciliationItem $item,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
        EmailProviderReconciliationPeekedMessage $peeked,
        ?int $claimAttempt = null,
    ): mixed {
        return EmailAccountProviderLockContext::withinHeld(
            (int) $run->account_id,
            fn () => app(EmailProviderReconciliationStore::class)->store(
                runId: $run->id,
                itemId: $item->id,
                claimAttempt: $claimAttempt ?? (int) $item->attempt_count,
                accountId: $run->account_id,
                folderId: $folder->id,
                uidNamespaceId: $namespace->id,
                uidValidity: $namespace->uid_validity,
                uid: $uid,
                peeked: $peeked,
                runInboundRules: $folderRun->import_policy
                    === EmailProviderReconciliationFolder::IMPORT_LIVE,
            ),
        );
    }

    private function placement(
        EmailMessage $message,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $message->account_id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => $uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
    }
}
