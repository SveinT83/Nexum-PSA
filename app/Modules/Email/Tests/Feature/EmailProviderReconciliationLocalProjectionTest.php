<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\Actions\RecordEmailMessageOpened;
use App\Modules\Email\Actions\SetEmailUnreadForMe;
use App\Modules\Email\Jobs\ProjectEmailProviderHistoricalReadBaseline;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailProviderReconciliationLocalProjectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_flipped_to_system_actor_cannot_receive_or_use_personal_mail_state(): void
    {
        Permission::findOrCreate('email.inbox_view', 'web');
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->givePermissionTo('email.inbox_view');
        $account = $this->account();
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $actor->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
        ]);
        EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $actor->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'direct_grant',
            'recorded_at' => now()->subHour(),
            'entitlement_changed_at' => now()->subHour(),
        ]);
        [$folder, $namespace] = $this->folder($account);
        [$existing, $existingPlacement] = $this->message($account, $folder, $namespace, 8);
        $state = EmailMessageUserState::query()->create([
            'email_message_id' => $existing->id,
            'user_id' => $actor->id,
            'access_epoch' => 1,
            'is_unread' => true,
            'last_opened_placement_id' => null,
            'opened_count' => 0,
        ]);

        $actor->forceFill(['is_system_actor' => true])->save();
        $actor->refresh();
        [$historical] = $this->message($account, $folder, $namespace, 9);

        $this->assertSame(
            0,
            app(ProjectHistoricalEmailReadBaseline::class)->handle($account, $historical),
        );
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $historical->id,
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $account->id,
            'user_id' => $actor->id,
            'access_epoch' => 1,
            'ordinary_view_entitled' => false,
        ]);

        $resolver = app(EmailUnreadForMeResolver::class);
        $this->assertNull($resolver->resolve($existing, $actor));
        $projected = $resolver->selectUnreadForMe(
            EmailMessage::query()->whereKey($existing->id),
            $actor,
            'system_actor_unread',
        )->firstOrFail();
        $this->assertNull($projected->system_actor_unread);
        $this->assertSame(0, $resolver->scopeUnreadMessages(
            EmailMessage::query()->whereKey($existing->id),
            $actor,
        )->count());
        $this->assertSame(0, app(EmailOrdinaryMailboxEntitlementResolver::class)
            ->scopeCurrentViewAccounts(EmailAccount::query(), $actor)
            ->count());

        try {
            app(SetEmailUnreadForMe::class)->handle($actor, $existing, false);
            $this->fail('A system actor changed personal unread state through a stale grant.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        try {
            app(RecordEmailMessageOpened::class)->handle($actor, $existing, $existingPlacement);
            $this->fail('A system actor recorded a human opened receipt through a stale grant.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $state->refresh();
        $this->assertTrue($state->is_unread);
        $this->assertSame(0, $state->opened_count);
        $this->assertNull($state->last_opened_at);

        $actor->forceFill(['is_system_actor' => false])->save();
        $reactivated = app(EmailUnreadAccessEpochService::class)
            ->ensureCurrentEntitlement($account, $actor->refresh());
        $this->assertNotNull($reactivated);
        $this->assertSame(2, $reactivated->access_epoch);
        $this->assertTrue($reactivated->ordinary_view_entitled);
        $this->assertGreaterThanOrEqual($historical->id, $reactivated->baseline_message_id);
        $this->assertFalse($resolver->resolve($existing, $actor->refresh()));

        app(SetEmailUnreadForMe::class)->handle($actor, $existing, false);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $existing->id,
            'user_id' => $actor->id,
            'access_epoch' => 1,
            'is_unread' => true,
        ]);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $existing->id,
            'user_id' => $actor->id,
            'access_epoch' => 2,
            'is_unread' => false,
        ]);
    }

    #[Test]
    public function hidden_new_folder_history_resumes_after_lost_dispatch_and_updates_large_conversation_counters(): void
    {
        $account = $this->account();
        [$folder, $namespace] = $this->folder($account);
        [$message, $placement] = $this->message($account, $folder, $namespace, 9);
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ])->save();
        $conversation = app(EmailConversationProjector::class)
            ->assignPlacement($placement->refresh());
        $this->assertNotNull($conversation);
        $now = now();
        $extraPlacements = [];
        foreach (range(1, 250) as $offset) {
            $extraPlacements[] = [
                'email_message_id' => $message->id,
                'email_conversation_id' => $conversation->id,
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'uid_namespace_id' => $namespace->id,
                'provider' => 'imap',
                'folder_path' => $folder->path,
                'remote_message_id' => '<counter-'.$offset.'@example.test>',
                'imap_uid_validity' => $namespace->uid_validity,
                'imap_uid' => 1000 + $offset,
                'provider_seen' => $offset % 2 === 0,
                'provider_answered' => false,
                'provider_flagged' => false,
                'provider_deleted' => false,
                'provider_draft' => false,
                'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                'sync_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        collect($extraPlacements)->chunk(100)->each(
            fn ($chunk) => DB::table('email_mailbox_placements')->insert($chunk->all()),
        );
        $conversation->forceFill([
            'subject' => 'Large conversation metadata sentinel',
            'message_count' => 777,
            'active_placement_count' => 0,
            'provider_unread_count' => 0,
            'metadata' => ['sentinel' => 'unchanged'],
        ])->save();

        $baselines = collect();
        foreach ([false, false, true] as $systemActor) {
            $viewer = User::factory()->create([
                'status' => User::STATUS_ACTIVE,
                'is_system_actor' => $systemActor,
            ]);
            EmailAccountUserGrant::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $viewer->id,
                'can_view' => true,
                'can_organize' => false,
                'can_send' => false,
            ]);
            $baselines->push(EmailAccountUserReadBaseline::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $viewer->id,
                'access_epoch' => 1,
                'baseline_message_id' => 0,
                'ordinary_view_entitled' => true,
                'source' => 'direct_grant',
                'recorded_at' => now()->subHour(),
                'entitlement_changed_at' => now()->subHour(),
            ]));
        }

        $run = $this->reconciliationRun($account);
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => $folder->delimiter,
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            'expected_uid_validity' => $namespace->uid_validity,
            'start_uid_validity' => $namespace->uid_validity,
            'scan_through_uid' => 9,
            'next_uid' => 10,
        ]);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'result_placement_id' => $placement->id,
            'attempt_count' => 1,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => $baselines->last()->id,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_frozen_at' => now(),
        ]);

        $projection = app(ProjectHistoricalEmailReadBaseline::class);
        $run->forceFill([
            'last_progress_at' => now()->subMinutes(5)->startOfSecond(),
        ])->save();
        $beforeFirstPage = $run->fresh()->last_progress_at;
        $firstToken = $projection->claimReconciliationBatch($item->id);
        $this->assertNotNull($firstToken);
        $this->assertTrue($run->fresh()->last_progress_at->equalTo($beforeFirstPage));
        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_PENDING,
            $projection->projectReconciliationBatch($item->id, $firstToken, 2),
        );
        $afterFirstPage = $run->fresh()->last_progress_at;
        $this->assertTrue($afterFirstPage->greaterThan($beforeFirstPage));
        $this->assertTrue(
            $afterFirstPage->equalTo($item->fresh()->historical_baseline_last_attempt_at),
        );
        $this->assertSame($baselines[1]->id, $item->fresh()->historical_baseline_cursor_id);
        $this->assertSame(
            EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            $item->fresh()->historical_baseline_status,
        );
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ]);
        $this->assertSame(2, EmailMessageUserState::query()
            ->where('email_message_id', $message->id)
            ->where('is_unread', false)
            ->count());

        // Simulate losing the next queue dispatch after the page commit. A
        // later finalizer wake simply claims the durable pending cursor.
        $secondToken = $projection->claimReconciliationBatch($item->id);
        $this->assertNotNull($secondToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertTrue($run->fresh()->last_progress_at->equalTo($afterFirstPage));
        $this->travel(2)->seconds();
        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_COMPLETED,
            $projection->projectReconciliationBatch($item->id, $secondToken, 2),
        );
        $afterActivation = $run->fresh()->last_progress_at;
        $this->assertTrue($afterActivation->greaterThan($afterFirstPage));
        $this->assertTrue($afterActivation->equalTo($item->fresh()->completed_at));
        $this->assertTrue(
            $afterActivation->equalTo($item->fresh()->historical_baseline_completed_at),
        );

        $systemBaseline = $baselines->last()->fresh();
        $this->assertFalse($systemBaseline->ordinary_view_entitled);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $systemBaseline->user_id,
        ]);
        $this->assertSame(2, EmailMessageUserState::query()
            ->where('email_message_id', $message->id)
            ->where('is_unread', false)
            ->count());
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_error_code' => null,
        ]);
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'id' => $item->id,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
            'historical_baseline_claim_token' => null,
        ]);
        $activated = $placement->fresh();
        $this->assertNotNull($activated->email_conversation_id);
        $latestPlacementId = (int) EmailMailboxPlacement::query()
            ->where('email_conversation_id', $activated->email_conversation_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->max('id');
        $this->assertDatabaseHas('email_conversations', [
            'id' => $activated->email_conversation_id,
            'subject' => 'System actor boundary',
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'latest_email_mailbox_placement_id' => $latestPlacementId,
            'message_count' => 1,
            'active_placement_count' => 251,
            'provider_unread_count' => 126,
            'has_attachments' => false,
        ]);
        $this->assertSame(
            'unchanged',
            $conversation->fresh()->metadata['sentinel'] ?? null,
        );
        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_INACTIVE,
            $projection->projectReconciliationBatch($item->id, $secondToken, 2),
        );
        $this->assertTrue($run->fresh()->last_progress_at->equalTo($afterActivation));
    }

    #[Test]
    public function exhausted_baseline_job_budget_fails_safely_and_keeps_history_hidden(): void
    {
        $account = $this->account();
        [$folder, $namespace] = $this->folder($account);
        [, $placement] = $this->message($account, $folder, $namespace, 9);
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ])->save();
        $run = $this->reconciliationRun($account);
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => $folder->delimiter,
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            'expected_uid_validity' => $namespace->uid_validity,
            'start_uid_validity' => $namespace->uid_validity,
            'scan_through_uid' => 9,
            'next_uid' => 10,
        ]);
        $firstAttempt = now()->subHours(2);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'result_placement_id' => $placement->id,
            'attempt_count' => 1,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => 0,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_frozen_at' => now(),
            'historical_baseline_first_attempt_at' => $firstAttempt,
        ]);

        $job = new ProjectEmailProviderHistoricalReadBaseline($item->id);
        $this->assertSame(10, $job->tries);
        $this->assertSame(
            $firstAttempt->copy()->addDay()->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
        );
        $job->failed(new \RuntimeException('PRIVATE-BASELINE-FAILURE-CANARY'));

        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'id' => $item->id,
            'status' => EmailProviderReconciliationItem::STATUS_FAILED,
            'error_code' => ProjectHistoricalEmailReadBaseline::FAILURE_PROJECTION,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_FAILED,
            'historical_baseline_claim_token' => null,
            'historical_baseline_error_code' => ProjectHistoricalEmailReadBaseline::FAILURE_PROJECTION,
        ]);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'id' => $placement->id,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ]);
    }

    private function account(): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => 'system-actor-boundary@example.test',
            'from_name' => 'System actor boundary',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'system-actor-boundary@example.test',
            'imap_secret' => encrypt('test-secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'system-actor-boundary@example.test',
            'smtp_secret' => encrypt('test-secret'),
            'smtp_auth_type' => 'password',
        ]);
    }

    private function reconciliationRun(EmailAccount $account): EmailProviderReconciliationRun
    {
        return EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'local-projection:'.$account->id.':'.microtime(true)),
            'provider_binding_version' => 1,
            'max_folders' => 20,
            'uid_batch_size' => 20,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now(),
            'started_at' => now(),
        ]);
    }

    /** @return array{EmailFolder, EmailFolderUidNamespace} */
    private function folder(EmailAccount $account): array
    {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'New/SystemActorBoundary',
            'name' => 'SystemActorBoundary',
            'delimiter' => '/',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 909,
            'uid_next' => 10,
            'live_start_uid' => 9,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => 909,
            'uid_next_at_establishment' => 10,
            'live_start_uid' => 9,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'provider_reconciliation_discovery',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$folder->refresh(), $namespace];
    }

    /** @return array{EmailMessage, EmailMailboxPlacement} */
    private function message(
        EmailAccount $account,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
    ): array {
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => $uid,
            'message_id' => "<system-actor-{$uid}@example.test>",
            'subject' => 'System actor boundary',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
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

        return [$message, $placement];
    }
}
