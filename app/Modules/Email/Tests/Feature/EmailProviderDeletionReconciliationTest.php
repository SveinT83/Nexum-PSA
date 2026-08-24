<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Jobs\CleanupEmailProviderDeletionCache;
use App\Modules\Email\Jobs\DispatchEmailProviderDeletionReconciliation;
use App\Modules\Email\Jobs\ReconcileEmailProviderDeletionAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderDeletionCleanupAttempt;
use App\Modules\Email\Models\EmailProviderInventoryFolder;
use App\Modules\Email\Models\EmailProviderInventoryRun;
use App\Modules\Email\Models\EmailProviderPlacementFinding;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderDeletionCleanupService;
use App\Modules\Email\Services\EmailProviderDeletionReconciler;
use App\Modules\Email\Services\EmailProviderDeletionSettings;
use App\Modules\Email\Services\EmailProviderInventoryScanner;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderDeletionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $nextUid = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    #[Test]
    public function complete_stable_inventory_confirms_external_deletion_and_is_idempotent(): void
    {
        [$account, $folder] = $this->mailbox('deleted@example.test');
        $message = $this->message($account, $folder, ['subject' => 'Deleted at provider']);
        $placement = $this->placement($message, $folder);
        $conversationId = $placement->email_conversation_id;
        $otherAccount = $this->mailbox('isolated@example.test')[0];
        $otherFolder = $otherAccount->folders()->first();
        $otherMessage = $this->message($otherAccount, $otherFolder, [
            'imap_uid' => $message->imap_uid,
            'message_id' => $message->message_id,
            'subject' => $message->subject,
        ]);
        $otherPlacement = $this->placement($otherMessage, $otherFolder, ['imap_uid' => $placement->imap_uid]);

        $firstRun = $this->reconcile($account, $this->completeSnapshot($account, [
            [$folder, []],
        ]));

        $this->assertSame(EmailProviderInventoryRun::STATUS_COMPLETED, $firstRun->status);
        $this->assertSame(1, $firstRun->confirmed_missing_count);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 2,
        ]);
        $this->assertNotNull($placement->fresh()->provider_missing_at);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $otherPlacement->id]);
        $this->assertSoftDeleted('email_messages', ['id' => $message->id]);
        $this->assertDatabaseHas('email_provider_placement_findings', [
            'source_placement_id' => $placement->id,
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversationId,
            'finding_type' => EmailProviderPlacementFinding::TYPE_CONFIRMED_MISSING,
            'reason_code' => 'provider_absence_confirmed',
        ]);
        $this->assertDatabaseHas('email_conversations', [
            'id' => $conversationId,
            'active_placement_count' => 0,
            'provider_unread_count' => 0,
        ]);

        $secondRun = $this->reconcile($account, $this->completeSnapshot($account, [
            [$folder, []],
        ]));

        $this->assertSame(EmailProviderInventoryRun::STATUS_COMPLETED, $secondRun->status);
        $this->assertSame(0, $secondRun->confirmed_missing_count);
        $this->assertSame(1, EmailProviderPlacementFinding::query()
            ->where('source_placement_id', $placement->id)
            ->count());
    }

    #[Test]
    public function stable_target_identity_distinguishes_a_move_and_preserves_the_surviving_placement(): void
    {
        [$account, $inbox] = $this->mailbox('moved@example.test');
        $archive = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE);
        $message = $this->message($account, $inbox, ['subject' => 'Moved at provider']);
        $source = $this->placement($message, $inbox);
        $target = $this->placement($message, $archive);
        $fingerprint = app(EmailProviderMessageIdentity::class)->forMessage($message);

        $run = $this->reconcile($account, $this->completeSnapshot($account, [
            [$inbox, []],
            [$archive, [$this->providerEvidence($target, $fingerprint)]],
        ]));

        $this->assertSame(EmailProviderInventoryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->confirmed_move_count);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $source->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
        ]);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $target->id]);
        $this->assertNotNull(EmailMessage::query()->find($message->id));
        $this->assertDatabaseHas('email_provider_placement_findings', [
            'source_placement_id' => $source->id,
            'finding_type' => EmailProviderPlacementFinding::TYPE_CONFIRMED_MOVE,
            'target_placement_id' => $target->id,
            'target_folder_id' => $archive->id,
            'target_uid' => $target->imap_uid,
        ]);
        $this->assertSame(1, $source->conversation->fresh()->active_placement_count);

        $this->travel(8)->days();
        $cleanup = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(1, $cleanup['skipped']);
        $this->assertDatabaseMissing('email_mailbox_placements', ['id' => $source->id]);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $target->id]);
        $this->assertNotNull(EmailMessage::query()->find($message->id));
    }

    #[Test]
    public function exact_provider_reappearance_during_grace_restores_the_tombstone_and_cancels_cleanup(): void
    {
        [$account, $folder] = $this->mailbox('reappeared@example.test');
        $message = $this->message($account, $folder, ['subject' => 'Returned provider item']);
        $placement = $this->placement($message, $folder);
        $fingerprint = app(EmailProviderMessageIdentity::class)->forMessage($message);

        $this->reconcile($account, $this->completeSnapshot($account, [[$folder, []]]));
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertSoftDeleted('email_messages', ['id' => $message->id]);

        $run = $this->reconcile($account, $this->completeSnapshot($account, [[
            $folder,
            [$this->providerEvidence($placement, $fingerprint)],
        ]]));

        $this->assertSame(EmailProviderInventoryRun::STATUS_COMPLETED, $run->status);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 3,
        ]);
        $this->assertNull($placement->fresh()->provider_missing_at);
        $this->assertNotNull(EmailMessage::query()->find($message->id));
        $this->assertDatabaseHas('email_provider_placement_findings', [
            'source_placement_id' => $placement->id,
            'finding_type' => EmailProviderPlacementFinding::TYPE_REAPPEARED,
            'reason_code' => 'provider_placement_reappeared',
        ]);

        $this->travel(8)->days();
        $cleanup = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(0, $cleanup['scanned']);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertNotNull(EmailMessage::query()->find($message->id));
    }

    #[Test]
    public function incomplete_inventory_and_uidvalidity_reset_fail_closed(): void
    {
        [$account, $folder] = $this->mailbox('fail-closed@example.test');
        $message = $this->message($account, $folder);
        $placement = $this->placement($message, $folder);
        $incomplete = $this->completeSnapshot($account, [[$folder, []]]);
        $incomplete['status'] = 'incomplete';
        $incomplete['failure_code'] = 'folder_inventory_incomplete';
        $incomplete['folders'][0]['status'] = EmailProviderInventoryFolder::STATUS_INCOMPLETE;
        $incomplete['folders'][0]['reason_code'] = 'uid_inventory_count_mismatch';

        $incompleteRun = $this->reconcile($account, $incomplete);

        $this->assertSame(EmailProviderInventoryRun::STATUS_BLOCKED, $incompleteRun->status);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertDatabaseCount('email_provider_placement_findings', 0);

        $uidReset = $this->completeSnapshot($account, [[$folder, []]]);
        $uidReset['folders'][0]['observed_uid_validity'] = 999;
        $uidResetRun = $this->reconcile($account, $uidReset);

        $this->assertSame(EmailProviderInventoryRun::STATUS_BLOCKED, $uidResetRun->status);
        $this->assertSame('folder_projection_changed_or_mismatched', $uidResetRun->failure_code);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertDatabaseCount('email_provider_placement_findings', 0);

        $omittedFolder = $this->completeSnapshot($account, [[$folder, []]]);
        $omittedFolder['reported_folder_count'] = 2;
        $omittedFolderRun = $this->reconcile($account, $omittedFolder);

        $this->assertSame(EmailProviderInventoryRun::STATUS_BLOCKED, $omittedFolderRun->status);
        $this->assertSame('inventory_folder_count_mismatch', $omittedFolderRun->failure_code);
        $this->assertSame(2, $omittedFolderRun->folder_count);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertDatabaseCount('email_provider_placement_findings', 0);
    }

    #[Test]
    public function concurrent_placement_change_and_unresolved_remote_operation_remain_ambiguous(): void
    {
        [$account, $folder] = $this->mailbox('race@example.test');
        $changedMessage = $this->message($account, $folder, ['subject' => 'Concurrent change']);
        $changedPlacement = $this->placement($changedMessage, $folder);
        $operationMessage = $this->message($account, $folder, ['subject' => 'Remote operation']);
        $operationPlacement = $this->placement($operationMessage, $folder);
        EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $operationPlacement->id,
            'provider' => 'imap',
            'operation_type' => 'move',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'provider-deletion-race-operation',
        ]);
        $snapshot = $this->completeSnapshot($account, [[$folder, []]]);
        $scanner = Mockery::mock(EmailProviderInventoryScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturnUsing(
            function () use ($changedPlacement, $snapshot): array {
                $changedPlacement->increment('sync_version');

                return $snapshot;
            },
        );
        $this->app->instance(EmailProviderInventoryScanner::class, $scanner);

        $run = app(EmailProviderDeletionReconciler::class)->reconcileAccount(
            $account,
            providerBindingVersion: 1,
        );

        $this->assertSame(EmailProviderInventoryRun::STATUS_COMPLETED_WITH_AMBIGUITY, $run->status);
        $this->assertSame(2, $run->ambiguous_count);
        $this->assertDatabaseHas('email_provider_placement_findings', [
            'source_placement_id' => $changedPlacement->id,
            'finding_type' => EmailProviderPlacementFinding::TYPE_AMBIGUOUS,
            'reason_code' => 'placement_changed_during_inventory',
        ]);
        $this->assertDatabaseHas('email_provider_placement_findings', [
            'source_placement_id' => $operationPlacement->id,
            'finding_type' => EmailProviderPlacementFinding::TYPE_AMBIGUOUS,
            'reason_code' => 'remote_operation_unresolved',
        ]);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $changedPlacement->id]);
        $this->assertDatabaseHas('email_mailbox_placements', ['id' => $operationPlacement->id]);
    }

    #[Test]
    public function ticket_capture_and_link_survive_provider_placement_loss_and_cleanup(): void
    {
        [$account, $folder] = $this->mailbox('ticket-evidence@example.test');
        $message = $this->message($account, $folder, ['subject' => 'Ticket evidence']);
        $placement = $this->placement($message, $folder);
        $ticket = Ticket::factory()->create();
        $ticketMessage = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'source_inbound_email_message_id' => $message->id,
            'inbound_email_message_id' => $message->id,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Captured independently',
            'body' => 'Ticket-owned evidence must survive provider deletion.',
            'metadata' => ['email_message_id' => $message->id],
        ]);
        $link = EmailTicketConversationLink::query()->create([
            'ticket_id' => $ticket->id,
            'email_message_id' => $message->id,
            'email_mailbox_placement_id' => $placement->id,
            'account_id' => $account->id,
            'email_conversation_id' => $placement->email_conversation_id,
            'conversation_key' => $placement->conversation->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'linked_at' => now(),
        ]);

        $this->reconcile($account, $this->completeSnapshot($account, [[$folder, []]]));
        $this->travel(8)->days();
        $stats = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(0, $stats['purged']);
        $this->assertSame(1, $stats['protected']);
        $this->assertDatabaseHas('ticket_messages', ['id' => $ticketMessage->id]);
        $this->assertDatabaseHas('email_ticket_conversation_links', [
            'id' => $link->id,
            'email_message_id' => $message->id,
            'email_mailbox_placement_id' => null,
        ]);
        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        $this->assertContains(
            EmailRetentionEligibilityService::REASON_TICKET_EVIDENCE,
            EmailProviderDeletionCleanupAttempt::query()->sole()->reasons_json,
        );
    }

    #[Test]
    public function grace_cleanup_purges_orphan_payload_and_derived_smart_inbox_data(): void
    {
        [$account, $folder] = $this->mailbox('cleanup@example.test');
        $message = $this->message($account, $folder, ['subject' => 'Cleanup source']);
        $placement = $this->placement($message, $folder);
        $rawPath = "email/raw/{$message->id}.eml";
        $attachmentPath = "email/attachments/{$message->id}.txt";
        Storage::disk('local')->put($rawPath, 'raw');
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
        $suggestionId = DB::table('email_smart_inbox_suggestions')->insertGetId([
            'user_id' => null,
            'account_id' => $account->id,
            'email_conversation_id' => $placement->email_conversation_id,
            'selected_email_mailbox_placement_id' => $placement->id,
            'effect_type' => 'review_summary',
            'proposal_json' => json_encode(['summary' => 'Derived summary']),
            'proposal_fingerprint' => hash('sha256', 'proposal'),
            'confidence' => '0.9000',
            'source_fingerprint' => hash('sha256', 'source'),
            'source_message_ids_json' => json_encode([$message->id]),
            'schema_version' => 'email.smart_inbox.suggestion.v1',
            'status' => 'pending',
            'idempotency_key' => hash('sha256', 'cleanup-suggestion'),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('email_smart_inbox_suggestion_events')->insert([
            'email_smart_inbox_suggestion_id' => $suggestionId,
            'event_type' => 'generated',
            'to_status' => 'pending',
            'after_json' => json_encode(['status' => 'pending']),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->reconcile($account, $this->completeSnapshot($account, [[$folder, []]]));
        $beforeGrace = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(0, $beforeGrace['scanned']);
        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
        ]);
        $this->assertDatabaseHas('email_smart_inbox_suggestions', ['id' => $suggestionId]);
        Storage::disk('local')->assertExists($rawPath);
        Storage::disk('local')->assertExists($attachmentPath);
        $this->travel(8)->days();

        $afterGrace = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );
        $repeat = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(1, $afterGrace['purged']);
        $this->assertSame(0, $afterGrace['failed']);
        $this->assertSame(0, $repeat['scanned']);
        $this->assertDatabaseMissing('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('email_smart_inbox_suggestions', ['id' => $suggestionId]);
        $this->assertDatabaseMissing('email_smart_inbox_suggestion_events', [
            'email_smart_inbox_suggestion_id' => $suggestionId,
        ]);
        Storage::disk('local')->assertMissing($rawPath);
        Storage::disk('local')->assertMissing($attachmentPath);
        $this->assertDatabaseHas('email_provider_deletion_cleanup_attempts', [
            'email_message_id' => $message->id,
            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PURGED,
            'smart_inbox_suggestion_count' => 1,
        ]);
        $this->assertDatabaseHas('email_provider_placement_findings', [
            'source_placement_id' => $placement->id,
            'email_message_id' => $message->id,
        ]);
    }

    #[Test]
    public function cleanup_retry_treats_a_file_deleted_by_a_partial_prior_attempt_as_already_clean(): void
    {
        [$account, $folder] = $this->mailbox('cleanup-retry@example.test');
        $message = $this->message($account, $folder, ['subject' => 'Retry cleanup source']);
        $placement = $this->placement($message, $folder);
        $rawPath = "email/raw/{$message->id}.eml";
        $attachmentPath = "email/attachments/{$message->id}.txt";
        $message->forceFill(['raw_path' => $rawPath, 'attachments_count' => 1])->save();
        EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'retry-fixture.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 10,
            'disk' => 'local',
            'path' => $attachmentPath,
        ]);

        $this->reconcile($account, $this->completeSnapshot($account, [[$folder, []]]));
        $this->travel(8)->days();

        $disk = Mockery::mock();
        $disk->shouldReceive('exists')
            ->with($attachmentPath)
            ->times(3)
            ->andReturn(true, false, false);
        $disk->shouldReceive('delete')
            ->with($attachmentPath)
            ->once()
            ->andReturnTrue();
        $disk->shouldReceive('exists')
            ->with($rawPath)
            ->times(4)
            ->andReturn(true, true, true, false);
        $disk->shouldReceive('delete')
            ->with($rawPath)
            ->twice()
            ->andReturn(false, true);
        Storage::shouldReceive('disk')
            ->with('local')
            ->twice()
            ->andReturn($disk);

        $firstAttempt = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(1, $firstAttempt['failed']);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
        ]);
        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        $this->assertDatabaseHas('email_provider_deletion_cleanup_attempts', [
            'email_message_id' => $message->id,
            'status' => EmailProviderDeletionCleanupAttempt::STATUS_FAILED,
            'failure_code' => 'storage_delete_failed',
        ]);

        $this->travel(2)->days();
        $retry = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(1, $retry['purged']);
        $this->assertSame(0, $retry['failed']);
        $this->assertDatabaseMissing('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        $this->assertDatabaseHas('email_provider_deletion_cleanup_attempts', [
            'email_message_id' => $message->id,
            'status' => EmailProviderDeletionCleanupAttempt::STATUS_PURGED,
        ]);
        $this->assertSame(
            2,
            EmailProviderDeletionCleanupAttempt::query()
                ->where('email_message_id', $message->id)
                ->count(),
        );
    }

    #[Test]
    public function cleanup_keeps_the_tombstone_while_a_grace_window_remote_operation_is_unresolved(): void
    {
        [$account, $folder] = $this->mailbox('cleanup-operation@example.test');
        $message = $this->message($account, $folder, ['subject' => 'Operation protected cleanup']);
        $placement = $this->placement($message, $folder);

        $this->reconcile($account, $this->completeSnapshot($account, [[$folder, []]]));
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'move',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'provider-deletion-grace-operation-'.$placement->id,
        ]);
        $this->travel(8)->days();

        $protected = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(1, $protected['protected']);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
        ]);
        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        $this->assertContains(
            EmailRetentionEligibilityService::REASON_REMOTE_OPERATION,
            EmailProviderDeletionCleanupAttempt::query()->sole()->reasons_json,
        );

        $operation->forceFill(['status' => EmailRemoteOperation::STATUS_CANCELLED])->save();
        $this->travel(2)->days();
        $retry = app(EmailProviderDeletionCleanupService::class)->cleanupDue(
            app(EmailRetentionEligibilityService::class),
            12,
        );

        $this->assertSame(1, $retry['purged']);
        $this->assertDatabaseMissing('email_mailbox_placements', ['id' => $placement->id]);
        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
    }

    #[Test]
    public function provider_deletion_jobs_are_inert_while_the_opt_in_setting_is_disabled(): void
    {
        [$account] = $this->mailbox('jobs-disabled@example.test');
        $settings = app(EmailProviderDeletionSettings::class);
        $reconciler = Mockery::mock(EmailProviderDeletionReconciler::class);
        $reconciler->shouldNotReceive('reconcileAccount');
        $cleanup = Mockery::mock(EmailProviderDeletionCleanupService::class);
        $cleanup->shouldNotReceive('cleanupDue');
        $eligibility = app(EmailRetentionEligibilityService::class);
        Queue::fake();

        $this->assertFalse($settings->enabled());
        (new ReconcileEmailProviderDeletionAccount($account->id))->handle($settings, $reconciler);
        (new DispatchEmailProviderDeletionReconciliation)->handle($settings);
        (new CleanupEmailProviderDeletionCache)->handle($settings, $cleanup, $eligibility);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('email_provider_inventory_runs', 0);
        $this->assertDatabaseCount('email_provider_deletion_cleanup_attempts', 0);
    }

    #[Test]
    public function provider_deletion_jobs_run_normally_after_explicit_opt_in(): void
    {
        [$account] = $this->mailbox('jobs-enabled@example.test');
        CommonSetting::query()->create([
            'type' => 'emailhub',
            'name' => EmailProviderDeletionSettings::ENABLED_SETTING,
            'value' => '1',
        ]);
        $settings = app(EmailProviderDeletionSettings::class);
        $reconciler = Mockery::mock(EmailProviderDeletionReconciler::class);
        $reconciler->shouldReceive('reconcileAccount')
            ->once()
            ->with(
                Mockery::on(fn (EmailAccount $candidate): bool => $candidate->is($account)),
                50,
                2000,
                100,
                7,
                1,
            );
        $cleanup = Mockery::mock(EmailProviderDeletionCleanupService::class);
        $eligibility = app(EmailRetentionEligibilityService::class);
        $cleanup->shouldReceive('cleanupDue')
            ->once()
            ->with($eligibility, 24, 25)
            ->andReturn([
                'scanned' => 0,
                'purged' => 0,
                'protected' => 0,
                'skipped' => 0,
                'failed' => 0,
            ]);
        Queue::fake();

        $this->assertTrue($settings->enabled());
        (new ReconcileEmailProviderDeletionAccount($account->id))->handle($settings, $reconciler);
        (new DispatchEmailProviderDeletionReconciliation)->handle($settings);
        (new CleanupEmailProviderDeletionCache(25))->handle($settings, $cleanup, $eligibility);

        Queue::assertPushed(
            ReconcileEmailProviderDeletionAccount::class,
            fn (ReconcileEmailProviderDeletionAccount $job): bool => $job->accountId === $account->id
                && $job->queue === 'email',
        );
    }

    #[Test]
    public function scanner_pages_a_complete_bounded_inventory_without_retaining_raw_payload(): void
    {
        [$account, $folder] = $this->mailbox('scanner@example.test');
        $payloads = collect([1, 3, 8])->map(fn (int $uid): array => [
            'imap_uid' => $uid,
            'message_id' => "<scanner-{$uid}@example.test>",
            'subject' => "Scanner {$uid}",
            'from_email' => 'sender@example.test',
            'received_at' => now()->subDay()->toIso8601String(),
            'size_bytes' => 200 + $uid,
        ])->all();
        $client = $this->fakeInventoryClient($account, $folder, $payloads, [101, 101]);
        $scanner = $this->scannerWithClient($client);

        $snapshot = $scanner->scan($account, 5, 10, 2, 1);

        $this->assertSame('complete', $snapshot['status']);
        $this->assertSame(3, $snapshot['folders'][0]['scanned_message_count']);
        $this->assertCount(3, $snapshot['folders'][0]['messages']);
        $this->assertArrayNotHasKey('subject', $snapshot['folders'][0]['messages'][0]);
        $this->assertArrayNotHasKey('from_email', $snapshot['folders'][0]['messages'][0]);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $snapshot['folders'][0]['inventory_fingerprint'],
        );
        $this->assertSame(2, $client->fetchCalls);

        $limited = $scanner->scan($account, 5, 2, 2, 1);
        $this->assertSame('incomplete', $limited['status']);
        $this->assertSame('message_limit_exceeded', $limited['folders'][0]['reason_code']);
    }

    #[Test]
    public function scanner_rejects_uidvalidity_change_during_inventory(): void
    {
        [$account, $folder] = $this->mailbox('uid-reset-scanner@example.test');
        $payload = [[
            'imap_uid' => 1,
            'message_id' => '<uid-reset@example.test>',
            'subject' => 'UID reset',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subDay()->toIso8601String(),
            'size_bytes' => 300,
        ]];
        $client = $this->fakeInventoryClient($account, $folder, $payload, [101, 202]);

        $snapshot = $this->scannerWithClient($client)->scan($account, 5, 10, 10, 1);

        $this->assertSame('incomplete', $snapshot['status']);
        $this->assertSame('folder_changed_during_inventory', $snapshot['folders'][0]['reason_code']);
    }

    private function reconcile(EmailAccount $account, array $snapshot): EmailProviderInventoryRun
    {
        $scanner = Mockery::mock(EmailProviderInventoryScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn($snapshot);
        $this->app->instance(EmailProviderInventoryScanner::class, $scanner);

        return app(EmailProviderDeletionReconciler::class)->reconcileAccount(
            $account,
            providerBindingVersion: 1,
        );
    }

    /**
     * @param  array<int, array{0: EmailFolder, 1: array<int, array<string, mixed>>}>  $folderMessages
     * @return array<string, mixed>
     */
    private function completeSnapshot(EmailAccount $account, array $folderMessages): array
    {
        $folders = collect($folderMessages)->map(function (array $entry) use ($account): array {
            [$folder, $messages] = $entry;
            $uids = collect($messages)->pluck('imap_uid')->sort()->values()->all();

            return [
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'folder_path' => $folder->path,
                'status' => EmailProviderInventoryFolder::STATUS_COMPLETE,
                'reason_code' => null,
                'expected_uid_validity' => $folder->uid_validity,
                'observed_uid_validity' => $folder->uid_validity,
                'start_uid_next' => ($uids === [] ? 1 : max($uids) + 1),
                'end_uid_next' => ($uids === [] ? 1 : max($uids) + 1),
                'start_exists_count' => count($messages),
                'end_exists_count' => count($messages),
                'scanned_message_count' => count($messages),
                'inventory_fingerprint' => hash('sha256', implode(',', $uids)),
                'messages' => $messages,
                'started_at' => now(),
                'finished_at' => now(),
            ];
        })->all();

        return [
            'account_id' => $account->id,
            'provider' => 'imap',
            'status' => 'complete',
            'failure_code' => null,
            'max_folders' => 50,
            'max_messages_per_folder' => 2000,
            'started_at' => now(),
            'finished_at' => now(),
            'scope_fingerprint' => hash('sha256', 'scope-'.$account->id),
            'folders' => $folders,
            'reported_folder_count' => count($folders),
        ];
    }

    private function providerEvidence(EmailMailboxPlacement $placement, ?string $fingerprint): array
    {
        return [
            'imap_uid' => $placement->imap_uid,
            'uid_validity' => $placement->imap_uid_validity,
            'identity_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array{0: EmailAccount, 1: EmailFolder}
     */
    private function mailbox(string $address): array
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Provider inventory test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'provider_binding_version' => 1,
            'ticket_ingress_enabled' => false,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'secret',
        ]);

        return [$account, $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX)];
    }

    private function folder(EmailAccount $account, string $path, string $role): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => $path,
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 101,
            'uid_next' => 2000,
            'live_start_uid' => 0,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function message(EmailAccount $account, EmailFolder $folder, array $overrides = []): EmailMessage
    {
        $uid = (int) ($overrides['imap_uid'] ?? $this->nextUid++);

        return EmailMessage::query()->create(array_merge([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid' => $uid,
            'message_id' => "<provider-inventory-{$account->id}-{$uid}@example.test>",
            'subject' => "Provider inventory {$uid}",
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMonths(13)->startOfSecond(),
            'size_bytes' => 4096 + $uid,
            'state' => 'untriaged',
            'body_text' => 'Local Mail cache.',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function placement(
        EmailMessage $message,
        EmailFolder $folder,
        array $overrides = [],
    ): EmailMailboxPlacement {
        $placement = EmailMailboxPlacement::query()->create(array_merge([
            'email_message_id' => $message->id,
            'account_id' => $message->account_id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $message->imap_uid,
            'provider_seen' => false,
            'provider_deleted' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_reconciled_at' => now(),
        ], $overrides));

        app(EmailConversationProjector::class)->assignPlacement($placement);

        return $placement->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  array<int, int>  $uidValiditySequence
     */
    private function fakeInventoryClient(
        EmailAccount $account,
        EmailFolder $folder,
        array $payloads,
        array $uidValiditySequence,
    ): ImapClient {
        return new class($account, $folder, $payloads, $uidValiditySequence) extends ImapClient
        {
            public int $fetchCalls = 0;

            private int $stateCalls = 0;

            public function __construct(
                EmailAccount $account,
                private readonly EmailFolder $testFolder,
                private readonly array $payloads,
                private readonly array $uidValiditySequence,
            ) {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void {}

            public function folders(): array
            {
                return [[
                    'path' => $this->testFolder->path,
                    'is_selectable' => true,
                ]];
            }

            public function folderState(string $folderPath): array
            {
                $uidValidity = $this->uidValiditySequence[min(
                    $this->stateCalls,
                    count($this->uidValiditySequence) - 1,
                )];
                $this->stateCalls++;
                $uids = collect($this->payloads)->pluck('imap_uid');

                return [
                    'uid_validity' => $uidValidity,
                    'next_uid' => $uids->isEmpty() ? 1 : ((int) $uids->max()) + 1,
                    'exists_count' => count($this->payloads),
                    'unseen_count' => count($this->payloads),
                    'highest_modseq' => null,
                ];
            }

            public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
            {
                $this->fetchCalls++;

                return collect($this->payloads)
                    ->filter(fn (array $payload): bool => (int) $payload['imap_uid'] > $uid)
                    ->sortBy('imap_uid')
                    ->take($limit)
                    ->values()
                    ->all();
            }
        };
    }

    private function scannerWithClient(ImapClient $client): EmailProviderInventoryScanner
    {
        return new class(app(EmailProviderMessageIdentity::class), $client) extends EmailProviderInventoryScanner
        {
            public function __construct(
                EmailProviderMessageIdentity $identity,
                private readonly ImapClient $client,
            ) {
                parent::__construct($identity);
            }

            protected function makeClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };
    }
}
