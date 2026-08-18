<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderAbsenceProjector;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderConfirmedMoveStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exact_confirmed_move_atomically_rebinds_all_personal_state_and_opened_pointer(): void
    {
        $scope = $this->scope();
        $firstOpened = now()->subDays(3)->startOfSecond();
        $lastOpened = now()->subDay()->startOfSecond();
        $markedRead = now()->subHours(12)->startOfSecond();
        $markedUnread = now()->subHours(6)->startOfSecond();
        $readUser = User::factory()->create();
        $unreadUser = User::factory()->create();
        $readState = EmailMessageUserState::query()->create([
            'email_message_id' => $scope['source_message']->id,
            'user_id' => $readUser->id,
            'access_epoch' => 1,
            'last_opened_placement_id' => $scope['source_placement']->id,
            'is_unread' => false,
            'opened_count' => 4,
            'first_opened_at' => $firstOpened,
            'last_opened_at' => $lastOpened,
            'marked_read_at' => $markedRead,
        ]);
        $unreadState = EmailMessageUserState::query()->create([
            'email_message_id' => $scope['source_message']->id,
            'user_id' => $unreadUser->id,
            'access_epoch' => 7,
            'last_opened_placement_id' => null,
            'is_unread' => true,
            'opened_count' => 0,
            'marked_unread_at' => $markedUnread,
        ]);
        $readCreatedAt = $readState->created_at;
        $unreadCreatedAt = $unreadState->created_at;

        $this->assertTrue(app(EmailProviderAbsenceProjector::class)->confirmMissing(
            $scope['run'],
            $scope['absence'],
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
        ));

        $scope['absence']->refresh();
        $scope['source_placement']->refresh();
        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
            $scope['absence']->status,
        );
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $scope['source_placement']->local_state);
        $this->assertNotNull($scope['source_placement']->provider_missing_at);
        $this->assertSoftDeleted('email_messages', ['id' => $scope['source_message']->id]);
        $this->assertDatabaseCount('email_message_user_states', 2);

        $readState->refresh();
        $unreadState->refresh();
        $this->assertSame($scope['target_message']->id, $readState->email_message_id);
        $this->assertSame($scope['target_placement']->id, $readState->last_opened_placement_id);
        $this->assertFalse($readState->is_unread);
        $this->assertSame(1, $readState->access_epoch);
        $this->assertSame(4, $readState->opened_count);
        $this->assertTrue($firstOpened->equalTo($readState->first_opened_at));
        $this->assertTrue($lastOpened->equalTo($readState->last_opened_at));
        $this->assertTrue($markedRead->equalTo($readState->marked_read_at));
        $this->assertTrue($readCreatedAt->equalTo($readState->created_at));

        $this->assertSame($scope['target_message']->id, $unreadState->email_message_id);
        $this->assertNull($unreadState->last_opened_placement_id);
        $this->assertTrue($unreadState->is_unread);
        $this->assertSame(7, $unreadState->access_epoch);
        $this->assertSame(0, $unreadState->opened_count);
        $this->assertTrue($markedUnread->equalTo($unreadState->marked_unread_at));
        $this->assertTrue($unreadCreatedAt->equalTo($unreadState->created_at));
        $this->assertFalse($scope['run']->fresh()->automation_scope_unsafe);
    }

    #[Test]
    public function target_user_epoch_collision_rolls_back_the_entire_confirmed_move(): void
    {
        $scope = $this->scope();
        $user = User::factory()->create();
        $sourceState = EmailMessageUserState::query()->create([
            'email_message_id' => $scope['source_message']->id,
            'user_id' => $user->id,
            'access_epoch' => 3,
            'last_opened_placement_id' => $scope['source_placement']->id,
            'is_unread' => false,
            'opened_count' => 2,
        ]);
        $targetState = EmailMessageUserState::query()->create([
            'email_message_id' => $scope['target_message']->id,
            'user_id' => $user->id,
            'access_epoch' => 3,
            'last_opened_placement_id' => $scope['target_placement']->id,
            'is_unread' => true,
            'opened_count' => 1,
        ]);

        $this->assertFalse(app(EmailProviderAbsenceProjector::class)->confirmMissing(
            $scope['run'],
            $scope['absence'],
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
        ));

        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CONFLICT,
            $scope['absence']->fresh()->status,
        );
        $this->assertSame(
            'provider_move_personal_state_conflict',
            $scope['absence']->fresh()->error_code,
        );
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $scope['source_placement']->fresh()->local_state);
        $this->assertNull($scope['source_placement']->fresh()->provider_missing_at);
        $this->assertNull(EmailMessage::withTrashed()->findOrFail($scope['source_message']->id)->deleted_at);
        $this->assertSame($scope['source_message']->id, $sourceState->fresh()->email_message_id);
        $this->assertSame($scope['source_placement']->id, $sourceState->fresh()->last_opened_placement_id);
        $this->assertSame($scope['target_message']->id, $targetState->fresh()->email_message_id);
        $this->assertSame($scope['target_placement']->id, $targetState->fresh()->last_opened_placement_id);
        $this->assertTrue($scope['run']->fresh()->automation_scope_unsafe);
        $this->assertSame(
            EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
            $scope['run']->fresh()->automation_scope_error_code,
        );
    }

    #[Test]
    public function same_message_move_only_rebinds_the_exact_opened_placement_pointer(): void
    {
        $scope = $this->scope(sameMessage: true);
        $user = User::factory()->create();
        $state = EmailMessageUserState::query()->create([
            'email_message_id' => $scope['source_message']->id,
            'user_id' => $user->id,
            'access_epoch' => 2,
            'last_opened_placement_id' => $scope['source_placement']->id,
            'is_unread' => false,
            'opened_count' => 8,
        ]);

        $this->assertTrue(app(EmailProviderAbsenceProjector::class)->confirmMissing(
            $scope['run'],
            $scope['absence'],
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
        ));

        $state->refresh();
        $this->assertSame($scope['source_message']->id, $state->email_message_id);
        $this->assertSame($scope['target_placement']->id, $state->last_opened_placement_id);
        $this->assertSame(8, $state->opened_count);
        $this->assertFalse($state->is_unread);
        $this->assertNull(EmailMessage::withTrashed()->findOrFail($scope['source_message']->id)->deleted_at);
    }

    #[Test]
    public function target_namespace_drift_is_a_conflict_and_never_touches_personal_state(): void
    {
        $scope = $this->scope();
        $user = User::factory()->create();
        $state = EmailMessageUserState::query()->create([
            'email_message_id' => $scope['source_message']->id,
            'user_id' => $user->id,
            'access_epoch' => 1,
            'last_opened_placement_id' => $scope['source_placement']->id,
            'is_unread' => false,
            'opened_count' => 1,
        ]);
        $scope['target_namespace']->forceFill([
            'status' => EmailFolderUidNamespace::STATUS_SUPERSEDED,
            'superseded_at' => now(),
        ])->save();

        $this->assertFalse(app(EmailProviderAbsenceProjector::class)->confirmMissing(
            $scope['run'],
            $scope['absence'],
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
        ));

        $this->assertSame(EmailProviderReconciliationItem::STATUS_CONFLICT, $scope['absence']->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $scope['source_placement']->fresh()->local_state);
        $this->assertSame($scope['source_message']->id, $state->fresh()->email_message_id);
        $this->assertSame($scope['source_placement']->id, $state->fresh()->last_opened_placement_id);
    }

    #[Test]
    public function finalizer_keeps_source_visible_when_frozen_target_message_facts_drift(): void
    {
        $scope = $this->scope();
        $scope['run']->forceFill([
            'end_folder_scope_hash' => hash('sha256', 'confirmed-move-end-scope'),
        ])->save();
        $scope['source_folder_run']->forceFill([
            'reason_code' => 'stable_absence_projection',
        ])->save();
        $scope['target_message']->forceFill([
            'subject' => 'Changed after the provider scan',
        ])->save();

        $this->assertFalse(
            app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
                $scope['run']->fresh(),
                new FakeEmailProviderReconciliationReader,
            ),
        );

        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CONFLICT,
            $scope['absence']->fresh()->status,
        );
        $this->assertSame(
            'provider_move_personal_state_conflict',
            $scope['absence']->fresh()->error_code,
        );
        $this->assertSame(
            EmailMailboxPlacement::LOCAL_ACTIVE,
            $scope['source_placement']->fresh()->local_state,
        );
        $this->assertNull($scope['source_placement']->fresh()->provider_missing_at);
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'kind' => EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
            'source_placement_id' => $scope['source_placement']->id,
            'target_placement_id' => $scope['target_placement']->id,
            'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
            'error_code' => 'provider_move_personal_state_conflict',
        ]);
    }

    #[Test]
    public function finalizer_keeps_source_visible_when_frozen_source_message_facts_drift(): void
    {
        $scope = $this->scope();
        $scope['run']->forceFill([
            'end_folder_scope_hash' => hash('sha256', 'confirmed-move-source-drift-end-scope'),
        ])->save();
        $scope['source_folder_run']->forceFill([
            'reason_code' => 'stable_absence_projection',
        ])->save();
        $scope['source_message']->forceFill([
            'from_email' => 'changed-after-provider-scan@example.test',
        ])->save();

        $this->assertFalse(
            app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
                $scope['run']->fresh(),
                new FakeEmailProviderReconciliationReader,
            ),
        );

        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CONFLICT,
            $scope['absence']->fresh()->status,
        );
        $this->assertSame(
            'provider_move_personal_state_conflict',
            $scope['absence']->fresh()->error_code,
        );
        $this->assertSame(
            EmailMailboxPlacement::LOCAL_ACTIVE,
            $scope['source_placement']->fresh()->local_state,
        );
        $this->assertNull($scope['source_placement']->fresh()->provider_missing_at);
    }

    /**
     * @return array{
     *   source_message:EmailMessage,
     *   target_message:EmailMessage,
     *   source_placement:EmailMailboxPlacement,
     *   target_placement:EmailMailboxPlacement,
     *   target_namespace:EmailFolderUidNamespace,
     *   run:EmailProviderReconciliationRun,
     *   source_folder_run:EmailProviderReconciliationFolder,
     *   target_folder_run:EmailProviderReconciliationFolder,
     *   absence:EmailProviderReconciliationItem
     * }
     */
    private function scope(bool $sameMessage = false): array
    {
        $account = EmailAccount::query()->create([
            'address' => 'confirmed-move-'.uniqid().'@example.test',
            'from_name' => 'Confirmed Move',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
        ]);
        [$sourceFolder, $sourceNamespace] = $this->folder($account, 'Archive', 401);
        [$targetFolder, $targetNamespace] = $this->folder($account, 'INBOX', 402);
        $receivedAt = now()->subDay()->startOfSecond();
        $messageAttributes = [
            'account_id' => $account->id,
            'message_id' => '<confirmed-move@example.test>',
            'subject' => 'Exact confirmed move',
            'from_email' => 'sender@example.test',
            'received_at' => $receivedAt,
            'size_bytes' => 8192,
            'state' => 'untriaged',
        ];
        $sourceMessage = EmailMessage::query()->create($messageAttributes + [
            'mailbox' => $sourceFolder->path,
            'imap_uid_validity' => $sourceNamespace->uid_validity,
            'imap_uid' => 41,
        ]);
        $targetMessage = $sameMessage
            ? $sourceMessage
            : EmailMessage::query()->create($messageAttributes + [
                'mailbox' => $targetFolder->path,
                'imap_uid_validity' => $targetNamespace->uid_validity,
                'imap_uid' => 71,
            ]);
        $sourcePlacement = $this->placement(
            $sourceMessage,
            $sourceFolder,
            $sourceNamespace,
            41,
        );
        $targetPlacement = $this->placement(
            $targetMessage,
            $targetFolder,
            $targetNamespace,
            71,
        );
        $run = EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'confirmed-move:'.uniqid()),
            'provider_binding_version' => 1,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);
        $sourceFolderRun = $this->folderRun($run, $sourceFolder, $sourceNamespace);
        $targetFolderRun = $this->folderRun($run, $targetFolder, $targetNamespace);
        $sourceFolderRun->forceFill(['reason_code' => 'stable_absence_projection'])->save();
        $identity = app(EmailProviderMessageIdentity::class)->forMessage($sourceMessage);
        $targetPlacement->forceFill([
            'last_provider_reconciliation_run_id' => $run->id,
            'last_provider_observed_sync_version' => 1,
            'last_provider_observed_identity_hash' => $identity,
            'last_provider_observed_at' => now(),
        ])->save();
        EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $targetFolderRun->id,
            'uid_namespace_id' => $targetNamespace->id,
            'imap_uid' => $targetPlacement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
            'status' => EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
            'source_placement_id' => $targetPlacement->id,
            'result_placement_id' => $targetPlacement->id,
            'identity_hash' => $identity,
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
            'completed_at' => now(),
        ]);
        $absence = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $sourceFolderRun->id,
            'uid_namespace_id' => $sourceNamespace->id,
            'imap_uid' => $sourcePlacement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            'source_placement_id' => $sourcePlacement->id,
            'target_placement_id' => $targetPlacement->id,
            'identity_hash' => $identity,
            'placement_sync_version_before' => 1,
        ]);

        return [
            'source_message' => $sourceMessage,
            'target_message' => $targetMessage,
            'source_placement' => $sourcePlacement,
            'target_placement' => $targetPlacement,
            'target_namespace' => $targetNamespace,
            'run' => $run,
            'source_folder_run' => $sourceFolderRun,
            'target_folder_run' => $targetFolderRun,
            'absence' => $absence,
        ];
    }

    /** @return array{EmailFolder,EmailFolderUidNamespace} */
    private function folder(EmailAccount $account, string $path, int $uidValidity): array
    {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => $path,
            'delimiter' => '/',
            'role' => $path === 'INBOX' ? EmailFolder::ROLE_INBOX : EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'uid_next' => 100,
            'live_start_uid' => 99,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'uid_next_at_establishment' => 100,
            'live_start_uid' => 99,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$folder->refresh(), $namespace];
    }

    private function placement(
        EmailMessage $message,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $folder->account_id,
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

    private function folderRun(
        EmailProviderReconciliationRun $run,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
    ): EmailProviderReconciliationFolder {
        return EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $run->account_id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => $namespace->uid_validity,
            'start_uid_validity' => $namespace->uid_validity,
            'start_uid_next' => 100,
            'start_exists_count' => 1,
            'end_uid_validity' => $namespace->uid_validity,
            'end_uid_next' => 100,
            'end_exists_count' => 1,
            'reason_code' => 'stable_end_validated',
        ]);
    }
}
