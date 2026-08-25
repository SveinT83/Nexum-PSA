<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\Jobs\FinalizeEmailProviderReconciliation;
use App\Modules\Email\Jobs\ProcessEmailProviderReconciliationAutomation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationAutomationCorrelator;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Services\EmailProviderReconciliationPlacementProjector;
use App\Modules\Email\Services\EmailProviderReconciliationPlacementSnapshot;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\InboundEmailSignalClassifier;
use App\Modules\Email\Services\PersonalEmailRuleEngine;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationReader;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\Actions\ResolveInboundEmailNotificationRecipients;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderReconciliationAutomationCorrelationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stable_genuine_delivery_becomes_recoverable_pending_work(): void
    {
        $scope = $this->stableScope();
        Queue::fake();

        $this->assertTrue($this->advanceCorrelation($scope));

        $item = $scope['item']->fresh();
        $this->assertFalse($scope['run']->fresh()->automation_scope_unsafe);
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_PENDING, $item->automation_status);
        $this->assertNull($item->automation_completed_at);
        $this->assertNull($item->automation_error_code);
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId === $item->id,
        );
    }

    #[Test]
    public function automation_claim_rejects_a_drifted_exact_target_before_any_rule_side_effect(): void
    {
        $scope = $this->stableScope();
        Queue::fake();

        $this->assertTrue($this->advanceCorrelation($scope));
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_PENDING,
            $scope['item']->fresh()->automation_status,
        );

        // Keep a different active occurrence on the same message so the broad
        // message-level inbound guard would pass if this worker lost its exact
        // correlated-target authority.
        $archivePlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $scope['message']->id,
            'account_id' => $scope['account']->id,
            'email_folder_id' => $scope['archive']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'provider' => 'imap',
            'folder_path' => $scope['archive']->path,
            'remote_message_id' => $scope['message']->message_id,
            'imap_uid_validity' => $scope['archive_namespace']->uid_validity,
            'imap_uid' => 73,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $scope['placement']->forceFill([
            'provider_missing_at' => now(),
            'sync_version' => 2,
        ])->save();
        $this->assertTrue($scope['message']->fresh()->hasActiveProviderPlacement());
        $this->assertTrue(
            $scope['message']->fresh()->hasActiveProviderPlacement($archivePlacement),
        );

        $this->trapInboundAutomation();
        $ruleAttemptsBefore = EmailRuleExecutionAttempt::query()->count();

        (new ProcessEmailProviderReconciliationAutomation($scope['item']->id))->handle();

        $item = $scope['item']->fresh();
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_FAILED, $item->automation_status);
        $this->assertSame(
            ProcessEmailProviderReconciliationAutomation::RESULT_SCOPE_DRIFT_CODE,
            $item->automation_error_code,
        );
        $this->assertSame(0, $item->automation_attempt_count);
        $this->assertNull($item->automation_claim_token);
        $this->assertNull($item->automation_last_attempt_at);
        $this->assertNull($item->automation_rule_attempt_floor_id);
        $this->assertNotNull($item->automation_completed_at);
        $this->assertSame($ruleAttemptsBefore, EmailRuleExecutionAttempt::query()->count());
    }

    #[Test]
    public function automation_claim_rejects_an_unresolved_operation_recorded_after_correlation(): void
    {
        $scope = $this->stableScope();
        Queue::fake();

        $this->assertTrue($this->advanceCorrelation($scope));
        $now = now();
        EmailRemoteOperation::query()->insert(array_map(
            fn (int $offset): array => [
                'account_id' => $scope['account']->id,
                'provider_binding_version' => 1,
                'email_folder_id' => $scope['inbox']->id,
                'email_mailbox_placement_id' => $scope['placement']->id,
                'provider' => 'imap',
                'operation_type' => 'mark_seen',
                'status' => EmailRemoteOperation::STATUS_PENDING,
                'idempotency_key' => hash('sha256', 'automation-claim-unresolved-'.$offset),
                'source_folder_path' => $scope['inbox']->path,
                'expected_placement_sync_version' => $scope['placement']->sync_version,
                'expected_provider_uid' => $scope['placement']->imap_uid,
                'expected_uid_validity' => $scope['placement']->imap_uid_validity,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            range(1, 105),
        ));
        $firstOperationId = (int) EmailRemoteOperation::query()
            ->where('email_mailbox_placement_id', $scope['placement']->id)
            ->min('id');
        $lastOperationId = (int) EmailRemoteOperation::query()
            ->where('email_mailbox_placement_id', $scope['placement']->id)
            ->max('id');
        $this->trapInboundAutomation();
        $ruleAttemptsBefore = EmailRuleExecutionAttempt::query()->count();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'email_remote_operations')) {
                $queries[] = strtolower($query->sql);
            }
        });

        (new ProcessEmailProviderReconciliationAutomation($scope['item']->id))->handle();

        $item = $scope['item']->fresh();
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_FAILED, $item->automation_status);
        $this->assertSame(
            ProcessEmailProviderReconciliationAutomation::RESULT_SCOPE_DRIFT_CODE,
            $item->automation_error_code,
        );
        $this->assertSame(0, $item->automation_attempt_count);
        $this->assertNull($item->automation_claim_token);
        $this->assertNull($item->automation_last_attempt_at);
        $this->assertNull($item->automation_rule_attempt_floor_id);
        $this->assertNotNull($item->automation_completed_at);
        $this->assertSame($ruleAttemptsBefore, EmailRuleExecutionAttempt::query()->count());
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('select exists', $queries[0]);
        $this->assertStringNotContainsString('order by', $queries[0]);

        $observer = app(EmailProviderRemoteOperationObserver::class);
        $queries = [];
        $this->assertTrue($observer->hasCompetingUnresolvedForPlacement(
            $scope['placement']->id,
            $firstOperationId,
        ));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('select exists', $queries[0]);

        $queries = [];
        $this->assertFalse($observer->hasPriorUnresolvedForPlacement(
            $scope['placement']->id,
            $firstOperationId,
        ));
        $this->assertCount(5, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('select exists', $query);
            $this->assertStringNotContainsString('order by', $query);
        }

        $queries = [];
        $this->assertTrue($observer->hasPriorUnresolvedForPlacement(
            $scope['placement']->id,
            $lastOperationId,
        ));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('select exists', $queries[0]);

        $queries = [];
        $this->assertSame(
            $firstOperationId,
            $observer->oldestUnresolvedForPlacement($scope['placement']->id)?->id,
        );
        $this->assertCount(6, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('limit 1', $query);
        }
    }

    #[Test]
    public function weak_target_identity_fails_one_item_without_rolling_back_the_page(): void
    {
        $scope = $this->stableScope();
        $scope['placement']->forceFill([
            'last_provider_observed_identity_hash' => null,
        ])->save();
        $scope['item']->forceFill(['identity_hash' => null])->save();
        Queue::fake();

        $this->assertTrue($this->advanceCorrelation($scope));

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_scope_invalid',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function inbox_lookalike_custom_path_is_never_eligible_for_automation(): void
    {
        $scope = $this->stableScope();
        $scope['inbox']->forceFill([
            'path' => 'INBOX ',
            'name' => 'INBOX ',
            'role' => EmailFolder::ROLE_CUSTOM,
        ])->save();
        $scope['inbox_run']->forceFill([
            'folder_path' => 'INBOX ',
            'folder_name' => 'INBOX ',
        ])->save();
        $scope['message']->forceFill(['mailbox' => 'INBOX '])->save();
        $scope['placement']->forceFill(['folder_path' => 'INBOX '])->save();
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_scope_invalid',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function same_run_stable_flag_projection_advances_frozen_import_version_before_dispatch(): void
    {
        $scope = $this->stableScope();
        $observation = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['inbox_run']->id,
            'uid_namespace_id' => $scope['inbox_namespace']->id,
            'imap_uid' => $scope['placement']->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            'source_placement_id' => $scope['placement']->id,
            'result_placement_id' => $scope['placement']->id,
            'identity_hash' => $scope['item']->identity_hash,
            'provider_seen' => true,
            'provider_answered' => false,
            'provider_flagged' => false,
            'provider_deleted' => false,
            'provider_draft' => false,
            'custom_flags_json' => [],
            'custom_flags_hash' => hash('sha256', '[]'),
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
        ]);
        $scope['inbox_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'reason_code' => 'stable_operation_projection',
        ])->save();

        $this->assertTrue(
            app(EmailProviderReconciliationPlacementProjector::class)
                ->applyStableObservation($scope['run'], $observation),
        );
        $this->sealFolderRun($scope['inbox_run']);
        $this->assertSame(2, $scope['placement']->fresh()->sync_version);
        $this->assertSame(2, $scope['placement']->fresh()->last_provider_observed_sync_version);
        $this->assertSame(2, $scope['item']->fresh()->placement_sync_version_after);
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_PENDING,
            $scope['item']->fresh()->automation_status,
        );
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId
                === $scope['item']->id,
        );
    }

    #[Test]
    public function weak_identity_known_placement_still_projects_stable_flags(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 50);
        EmailMessage::query()->whereKey($source->email_message_id)->update(['size_bytes' => 0]);
        $metadata = new EmailProviderReconciliationMessageMetadata(
            uid: 50,
            modseq: 808,
            seen: true,
            answered: false,
            flagged: true,
            deleted: false,
            draft: false,
            customFlags: ['ProjectKeyword'],
        );
        $projector = app(EmailProviderReconciliationPlacementProjector::class);

        $scope['run']->forceFill(['phase' => EmailProviderReconciliationRun::PHASE_SCAN])->save();
        $scope['archive_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_SCANNING,
            'reason_code' => null,
            'scan_through_uid' => 50,
            'next_uid' => 50,
        ])->save();
        $projector->observe($scope['archive_run']->fresh(), $metadata, 49, 50);
        $observation = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $scope['archive_run']->id)
            ->where('imap_uid', 50)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->firstOrFail();
        $scope['run']->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
        ])->save();
        $scope['archive_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'reason_code' => 'stable_operation_projection',
        ])->save();
        Queue::fake();

        $this->assertTrue($projector->applyStableObservation($scope['run'], $observation));
        $this->sealFolderRun($scope['archive_run']);

        $source->refresh();
        $this->assertTrue($source->provider_seen);
        $this->assertTrue($source->provider_flagged);
        $this->assertContains('projectkeyword', $source->flags_json);
        $this->assertNull($source->last_provider_observed_identity_hash);
        $this->assertTrue($scope['run']->fresh()->automation_scope_unsafe);
        $this->assertSame(
            EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
            $scope['run']->fresh()->automation_scope_error_code,
        );
        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_PROJECTED,
            $observation->fresh()->status,
        );
        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_COMPLETE,
            $scope['archive_run']->fresh()->status,
        );
        $this->advanceCorrelation($scope);
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_scope_unstable',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function stable_flag_projection_preserves_scan_frozen_identity_for_later_drift_check(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 49);
        $frozenIdentity = $source->last_provider_observed_identity_hash;
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => $source->id,
        ])->save();
        $observation = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['archive_run']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'imap_uid' => $source->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            'source_placement_id' => $source->id,
            'result_placement_id' => $source->id,
            'identity_hash' => $frozenIdentity,
            'provider_seen' => true,
            'provider_answered' => false,
            'provider_flagged' => false,
            'provider_deleted' => false,
            'provider_draft' => false,
            'custom_flags_json' => [],
            'custom_flags_hash' => hash('sha256', '[]'),
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
        ]);
        EmailMessage::query()->whereKey($source->email_message_id)->update([
            'subject' => 'Locally changed after scan',
        ]);
        $projector = app(EmailProviderReconciliationPlacementProjector::class);
        $scope['archive_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'reason_code' => 'stable_operation_projection',
        ])->save();

        $this->assertTrue($projector->applyStableObservation($scope['run'], $observation));
        $this->sealFolderRun($scope['archive_run']);
        $this->assertSame(
            $frozenIdentity,
            $source->fresh()->last_provider_observed_identity_hash,
        );
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_copy_source_drift',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function nomodseq_verification_materializes_weak_known_identity_barrier(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 48);
        $source->forceFill(['last_provider_observed_identity_hash' => null])->save();
        $scope['run']->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_SCAN,
        ])->save();
        $scope['archive_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_SCANNING,
            'reason_code' => 'nomodseq_baseline_pending',
        ])->save();

        $this->assertTrue(
            app(EmailProviderReconciliationPlacementProjector::class)
                ->refreshVerifiedObservation(
                    $scope['archive_run']->fresh(),
                    new EmailProviderReconciliationMessageMetadata(
                        uid: 48,
                        modseq: null,
                        seen: true,
                        answered: false,
                        flagged: false,
                        deleted: false,
                        draft: false,
                    ),
                ),
        );

        $this->assertTrue($scope['run']->fresh()->automation_scope_unsafe);
        $this->assertSame(
            EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
            $scope['run']->fresh()->automation_scope_error_code,
        );
    }

    #[Test]
    public function exact_pre_run_copy_with_message_id_bracket_variant_is_suppressed(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate(
            $scope,
            uid: 17,
            messageId: 'correlation@example.test',
        );
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => $source->id,
        ])->save();
        Queue::fake();

        $this->advanceCorrelation($scope);

        $item = $scope['item']->fresh();
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED, $item->automation_status);
        $this->assertSame('provider_reconciliation_automation_existing_copy', $item->automation_error_code);
        $this->assertNotNull($item->automation_completed_at);
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function exact_confirmed_move_target_is_suppressed(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 18);
        EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['archive_run']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'imap_uid' => 18,
            'kind' => EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
            'status' => EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
            'source_placement_id' => $source->id,
            'target_placement_id' => $scope['placement']->id,
            'identity_hash' => $scope['item']->identity_hash,
            'completed_at' => now(),
        ]);
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_existing_move',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function conflicting_move_evidence_never_suppresses_automation_as_confirmed(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 181);
        foreach ([
            [18, EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE],
            [19, EmailProviderReconciliationItem::STATUS_CONFLICT],
        ] as [$uid, $status]) {
            EmailProviderReconciliationItem::query()->create([
                'email_provider_reconciliation_run_id' => $scope['run']->id,
                'email_provider_reconciliation_folder_id' => $scope['archive_run']->id,
                'uid_namespace_id' => $scope['archive_namespace']->id,
                'imap_uid' => $uid,
                'kind' => EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
                'status' => $status,
                'source_placement_id' => $source->id,
                'target_placement_id' => $scope['placement']->id,
                'identity_hash' => $scope['item']->identity_hash,
                'error_code' => $status === EmailProviderReconciliationItem::STATUS_CONFLICT
                    ? 'provider_move_identity_ambiguous'
                    : null,
                'completed_at' => now(),
            ]);
        }
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_move_ambiguous',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function multiple_pre_run_identity_matches_fail_closed(): void
    {
        $scope = $this->stableScope();
        $first = $this->existingCandidate($scope, uid: 19);
        $second = $this->existingCandidate($scope, uid: 20);
        $third = $this->existingCandidate($scope, uid: 21);
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => max($first->id, $second->id, $third->id),
        ])->save();
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_identity_ambiguous',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function repeated_message_id_distractors_with_different_frozen_identity_stay_bounded(): void
    {
        $scope = $this->stableScope();
        $maximum = 0;
        foreach (range(1, 12) as $offset) {
            $candidate = $this->existingCandidate(
                $scope,
                uid: 100 + $offset,
                subject: 'Different immutable identity '.$offset,
            );
            $maximum = max($maximum, (int) $candidate->id);
        }
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => $maximum,
        ])->save();
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_PENDING,
            $scope['item']->fresh()->automation_status,
        );
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId
                === $scope['item']->id,
        );
    }

    #[Test]
    public function same_run_duplicate_imports_are_symmetrically_failed_without_dispatch(): void
    {
        $scope = $this->stableScope();
        [$peerMessage, $peerPlacement, $peerItem] = $this->awaitingOccurrence($scope, 43);
        $peerPlacement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_version' => 2,
        ])->save();
        $peerMessage->delete();
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $peerItem->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_scope_invalid',
            $peerItem->fresh()->automation_error_code,
        );
        $this->assertSame(
            'provider_reconciliation_automation_current_run_duplicate',
            $scope['item']->fresh()->automation_error_code,
        );
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $peerPlacement->fresh()->local_state);
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function same_run_non_automation_import_peer_still_blocks_inbox_automation(): void
    {
        $scope = $this->stableScope();
        $peer = $this->existingCandidate($scope, uid: 44);
        EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['archive_run']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'imap_uid' => $peer->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT,
            'result_placement_id' => $peer->id,
            'identity_hash' => $scope['item']->identity_hash,
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
            'completed_at' => now(),
            'automation_required' => false,
        ]);
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_current_run_duplicate',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function frozen_pre_run_peer_drift_is_failed_without_automation(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 45);
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => $source->id,
        ])->save();
        $source->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_version' => 2,
        ])->save();
        EmailMessage::query()->findOrFail($source->email_message_id)->delete();
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_copy_source_drift',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function frozen_pre_run_peer_message_fact_drift_never_becomes_a_genuine_delivery(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 46);
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => $source->id,
        ])->save();
        EmailMessage::query()->whereKey($source->email_message_id)->update([
            'subject' => 'Changed after provider observation',
        ]);
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_copy_source_drift',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function frozen_pre_run_peer_that_becomes_a_weak_identity_fails_closed(): void
    {
        $scope = $this->stableScope();
        $source = $this->existingCandidate($scope, uid: 48);
        $scope['archive_run']->forceFill([
            'baseline_max_placement_id' => $source->id,
        ])->save();
        EmailMessage::query()->whereKey($source->email_message_id)->update([
            'size_bytes' => 0,
        ]);
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_copy_source_drift',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function conflicting_import_in_any_folder_invalidates_global_correlation(): void
    {
        $scope = $this->stableScope();
        $peer = $this->existingCandidate($scope, uid: 47);
        EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['archive_run']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'imap_uid' => $peer->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
            'result_placement_id' => $peer->id,
            'identity_hash' => $scope['item']->identity_hash,
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
            'error_code' => 'reconciliation_store_scope_drift',
            'completed_at' => now(),
        ]);
        Queue::fake();

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            'provider_reconciliation_automation_scope_unstable',
            $scope['item']->fresh()->automation_error_code,
        );
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function unstable_global_scope_is_failed_in_bounded_pages(): void
    {
        $scope = $this->stableScope();
        $scope['archive_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE,
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY,
        ])->save();
        foreach (range(1, EmailProviderReconciliationAutomationCorrelator::BATCH_SIZE) as $offset) {
            EmailProviderReconciliationItem::query()->create([
                'email_provider_reconciliation_run_id' => $scope['run']->id,
                'email_provider_reconciliation_folder_id' => $scope['inbox_run']->id,
                'uid_namespace_id' => $scope['inbox_namespace']->id,
                'imap_uid' => 1000 + $offset,
                'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
                'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
                'automation_required' => true,
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
                'completed_at' => now(),
            ]);
        }
        Queue::fake();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->advanceCorrelation($scope);

        $this->assertSame(
            EmailProviderReconciliationAutomationCorrelator::BATCH_SIZE,
            EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $scope['run']->id)
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_FAILED)
                ->count(),
        );
        $this->assertSame(
            1,
            EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $scope['run']->id)
                ->where(
                    'automation_status',
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
                )->count(),
        );
        $this->advanceCorrelation($scope);
        $this->assertSame(
            EmailProviderReconciliationAutomationCorrelator::BATCH_SIZE + 1,
            EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $scope['run']->id)
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_FAILED)
                ->count(),
        );
        $this->assertSame(0, EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $scope['run']->id)
            ->where(
                'automation_status',
                EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
            )->count());
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'last_provider_observed_sync_version')
                && str_contains($sql, 'sync_version')
                && str_contains($sql, 'email_mailbox_placements'),
        ), 'Each bounded correlation page must use the materialized run bit, not rescan placements.');
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'absence_sources')
                && str_contains($sql, 'join'),
        ), 'Each bounded correlation page must not rescan the run absence ledger.');
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
    }

    #[Test]
    public function cancelling_run_terminalizes_awaiting_work_before_the_run(): void
    {
        $scope = $this->stableScope();
        foreach (range(1, EmailProviderReconciliationAutomationCorrelator::BATCH_SIZE) as $offset) {
            EmailProviderReconciliationItem::query()->create([
                'email_provider_reconciliation_run_id' => $scope['run']->id,
                'email_provider_reconciliation_folder_id' => $scope['inbox_run']->id,
                'uid_namespace_id' => $scope['inbox_namespace']->id,
                'imap_uid' => 3000 + $offset,
                'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
                'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
                'completed_at' => now(),
                'automation_required' => true,
                'automation_status' => $offset % 2 === 0
                    ? EmailProviderReconciliationItem::AUTOMATION_PENDING
                    : EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
            ]);
        }
        $this->sealStableScope($scope);
        $scope['run']->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_CANCELLING,
            'cancellation_requested_at' => now(),
        ])->save();
        $finalizer = app(EmailProviderReconciliationFinalizer::class);

        $this->assertFalse($finalizer->finalizeOneStep(
            $scope['run']->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_CANCELLED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLING,
            $scope['run']->fresh()->status,
        );
        $this->assertSame(
            EmailProviderReconciliationAutomationCorrelator::BATCH_SIZE,
            $scope['run']->items()
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_CANCELLED)
                ->count(),
        );
        $this->assertFalse($finalizer->finalizeOneStep(
            $scope['run']->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(
            EmailProviderReconciliationAutomationCorrelator::BATCH_SIZE + 1,
            $scope['run']->items()
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_CANCELLED)
                ->count(),
        );
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLING,
            $scope['run']->fresh()->status,
        );
        $this->assertTrue($finalizer->finalizeOneStep(
            $scope['run']->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLED,
            $scope['run']->fresh()->status,
        );
    }

    #[Test]
    public function finalizer_recovers_queue_loss_after_pending_commit(): void
    {
        $scope = $this->stableScope();
        $scope['item']->forceFill([
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_PENDING,
            'automation_completed_at' => null,
            'automation_error_code' => null,
        ])->save();
        Queue::fake();

        (new FinalizeEmailProviderReconciliation($scope['run']->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            new FakeEmailProviderReconciliationReader,
        );

        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId
                === $scope['item']->id,
        );
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            $scope['run']->fresh()->status,
        );
    }

    #[Test]
    public function notification_fanout_is_a_completion_barrier_and_recovers_without_replaying_rules(): void
    {
        $scope = $this->attachAwaitingFanout();
        $fanout = NotificationInboundEmailFanout::query()->sole();

        (new FinalizeEmailProviderReconciliation($scope['run']->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            new FakeEmailProviderReconciliationReader,
        );

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertNull($scope['run']->fresh()->final_summary_status);
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId
                === $scope['item']->id,
        );

        $scope['run']->forceFill([
            'last_progress_at' => now()->subMinutes(5)->startOfSecond(),
        ])->save();
        $beforeFanout = $scope['run']->fresh()->last_progress_at;
        $this->travel(2)->seconds();
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $this->assertSame(
            NotificationInboundEmailFanout::STATUS_COMPLETED,
            $fanout->fresh()->status,
        );
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_COMPLETED,
            $scope['item']->fresh()->automation_status,
        );
        $afterFanout = $scope['run']->fresh()->last_progress_at;
        $this->assertTrue($afterFanout->greaterThan($beforeFanout));
        $this->assertTrue(
            $afterFanout->equalTo($scope['item']->fresh()->automation_completed_at),
        );

        $this->travel(2)->seconds();
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertTrue(
            $scope['run']->fresh()->last_progress_at->equalTo($afterFanout),
        );
    }

    #[Test]
    public function attached_fanout_rechecks_the_exact_inbox_target_before_recipient_writes(): void
    {
        $scope = $this->attachAwaitingFanout();
        $fanout = NotificationInboundEmailFanout::query()->sole();

        // A broad message-level check would still pass on this surviving
        // Archive occurrence after the exact correlated Inbox target drifts.
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $scope['message']->id,
            'account_id' => $scope['account']->id,
            'email_folder_id' => $scope['archive']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'provider' => 'imap',
            'folder_path' => $scope['archive']->path,
            'remote_message_id' => $scope['message']->message_id,
            'imap_uid_validity' => $scope['archive_namespace']->uid_validity,
            'imap_uid' => 774,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $scope['placement']->forceFill([
            'provider_missing_at' => now(),
            'sync_version' => 2,
        ])->save();

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $this->assertSame(NotificationInboundEmailFanout::STATUS_FAILED, $fanout->fresh()->status);
        $this->assertSame(
            NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE,
            $fanout->fresh()->error_code,
        );
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('notification_inbound_external_deliveries')->count());
    }

    #[Test]
    public function cancellation_drains_an_attached_fanout_before_terminalizing_the_run(): void
    {
        $scope = $this->attachAwaitingFanout();
        $fanout = NotificationInboundEmailFanout::query()->sole();
        $cancellingRun = $scope['run']->fresh();
        $cancellingRun->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_CANCELLING,
            'cancellation_requested_at' => now(),
            'last_progress_at' => now()->subMinutes(5)->startOfSecond(),
        ])->save();
        $beforeDrain = $scope['run']->fresh()->last_progress_at;

        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $scope['run']->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLING,
            $scope['run']->fresh()->status,
        );
        $this->assertTrue($scope['run']->fresh()->last_progress_at->equalTo($beforeDrain));

        $this->travel(2)->seconds();
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertTrue($scope['run']->fresh()->last_progress_at->greaterThan($beforeDrain));
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_FAILED,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(
            DispatchInboundEmailNotification::ERROR_COMPLETED_AFTER_CANCELLATION,
            $scope['item']->fresh()->automation_error_code,
        );

        foreach (range(1, 20) as $_) {
            if (app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
                $scope['run']->fresh(),
                new FakeEmailProviderReconciliationReader,
            )) {
                break;
            }
        }
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLED,
            $scope['run']->fresh()->status,
        );
    }

    #[Test]
    public function failed_finalizer_callback_preserves_a_pending_notification_fanout_barrier(): void
    {
        $this->assertFailedFinalizerPreservesAwaitingFanout(false);
    }

    #[Test]
    public function failed_finalizer_callback_preserves_an_abandoned_notification_fanout_barrier(): void
    {
        $this->assertFailedFinalizerPreservesAwaitingFanout(true);
    }

    #[Test]
    public function automation_worker_cannot_claim_awaiting_correlation_evidence(): void
    {
        $scope = $this->stableScope();
        Queue::fake();

        (new ProcessEmailProviderReconciliationAutomation($scope['item']->id))->handle();

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(0, $scope['item']->fresh()->automation_attempt_count);
    }

    #[Test]
    public function frozen_provider_observation_version_rejects_zero_at_the_database_boundary(): void
    {
        $scope = $this->stableScope();
        $this->assertTrue(Schema::hasColumn(
            'email_mailbox_placements',
            'last_provider_observed_sync_version',
        ));
        $this->assertTrue(Schema::hasColumn(
            'email_provider_reconciliation_runs',
            'automation_scope_unsafe',
        ));
        $this->assertTrue(Schema::hasIndex(
            'email_mailbox_placements',
            'em_place_recon_identity_ix',
        ));
        $this->assertTrue(Schema::hasIndex(
            'email_provider_reconciliation_items',
            'em_recon_item_run_identity_ix',
        ));
        $this->assertTrue(Schema::hasIndex(
            'email_provider_reconciliation_items',
            'em_recon_item_run_kind_status_ix',
        ));
        $this->assertTrue(Schema::hasIndex(
            'email_provider_reconciliation_folders',
            'em_recon_folder_scope_status_ix',
        ));
        $this->assertTrue(Schema::hasIndex(
            'email_provider_reconciliation_items',
            'em_recon_item_run_target_kind_ix',
        ));
        $this->assertTrue(Schema::hasIndex(
            'email_provider_reconciliation_items',
            'em_recon_item_result_kind_ix',
        ));
        $plan = DB::select(
            'explain query plan select id from email_mailbox_placements'
            .' where account_id = ? and last_provider_reconciliation_run_id = ?'
            .' and last_provider_observed_identity_hash = ? and id > ? order by id limit 3',
            [
                $scope['account']->id,
                $scope['run']->id,
                $scope['item']->identity_hash,
                0,
            ],
        );
        $this->assertStringContainsString(
            'em_place_recon_identity_ix',
            implode(' ', array_map(
                fn (object $row): string => implode(' ', (array) $row),
                $plan,
            )),
        );
        $peerPlan = DB::select(
            'explain query plan select id from email_provider_reconciliation_items'
            .' where email_provider_reconciliation_run_id = ? and kind = ?'
            .' and identity_hash = ? and id > ? order by id limit 3',
            [
                $scope['run']->id,
                EmailProviderReconciliationItem::KIND_IMPORT,
                $scope['item']->identity_hash,
                0,
            ],
        );
        $this->assertStringContainsString(
            'em_recon_item_run_identity_ix',
            implode(' ', array_map(
                fn (object $row): string => implode(' ', (array) $row),
                $peerPlan,
            )),
        );
        $folderScopePlan = DB::select(
            'explain query plan select id from email_provider_reconciliation_folders'
            .' where email_provider_reconciliation_run_id = ? and discovery_state = ?'
            .' and status = ? order by id limit 1',
            [
                $scope['run']->id,
                EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY,
                EmailProviderReconciliationFolder::STATUS_STALE,
            ],
        );
        $this->assertStringContainsString(
            'em_recon_folder_scope_status_ix',
            implode(' ', array_map(
                fn (object $row): string => implode(' ', (array) $row),
                $folderScopePlan,
            )),
        );
        $importStatusPlan = DB::select(
            'explain query plan select id from email_provider_reconciliation_items'
            .' where email_provider_reconciliation_run_id = ? and kind = ?'
            .' and status = ? order by id limit 1',
            [
                $scope['run']->id,
                EmailProviderReconciliationItem::KIND_IMPORT,
                EmailProviderReconciliationItem::STATUS_CONFLICT,
            ],
        );
        $this->assertStringContainsString(
            'em_recon_item_run_kind_status_ix',
            implode(' ', array_map(
                fn (object $row): string => implode(' ', (array) $row),
                $importStatusPlan,
            )),
        );

        $rejected = false;
        try {
            DB::table('email_mailbox_placements')
                ->where('id', $scope['placement']->id)
                ->update(['last_provider_observed_sync_version' => 0]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $identityRejected = false;
        try {
            DB::table('email_mailbox_placements')
                ->where('id', $scope['placement']->id)
                ->update(['last_provider_observed_identity_hash' => 'not-a-hash']);
        } catch (QueryException) {
            $identityRejected = true;
        }
        $this->assertTrue($identityRejected);
        $uppercaseRejected = false;
        try {
            DB::table('email_mailbox_placements')
                ->where('id', $scope['placement']->id)
                ->update([
                    'last_provider_observed_identity_hash' => strtoupper(
                        $scope['item']->identity_hash,
                    ),
                ]);
        } catch (QueryException) {
            $uppercaseRejected = true;
        }
        $this->assertTrue($uppercaseRejected);
        DB::table('email_mailbox_placements')
            ->where('id', $scope['placement']->id)
            ->update(['last_provider_observed_sync_version' => 2]);
        $this->assertSame(2, $scope['placement']->fresh()->last_provider_observed_sync_version);

        $unsafeWithoutEvidenceRejected = false;
        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $scope['run']->id)
                ->update([
                    'automation_scope_unsafe' => true,
                    'automation_scope_error_code' => null,
                    'automation_scope_unsafe_at' => now(),
                ]);
        } catch (QueryException) {
            $unsafeWithoutEvidenceRejected = true;
        }
        $this->assertTrue($unsafeWithoutEvidenceRejected);
        DB::table('email_provider_reconciliation_runs')
            ->where('id', $scope['run']->id)
            ->update([
                'automation_scope_unsafe' => true,
                'automation_scope_error_code' => EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
                'automation_scope_unsafe_at' => now(),
            ]);
        $unsafeResetRejected = false;
        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $scope['run']->id)
                ->update([
                    'automation_scope_unsafe' => false,
                    'automation_scope_error_code' => null,
                    'automation_scope_unsafe_at' => null,
                ]);
        } catch (QueryException) {
            $unsafeResetRejected = true;
        }
        $this->assertTrue($unsafeResetRejected);
        $unsafeNullRejected = false;
        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $scope['run']->id)
                ->update(['automation_scope_unsafe' => null]);
        } catch (QueryException) {
            $unsafeNullRejected = true;
        }
        $this->assertTrue($unsafeNullRejected);
    }

    #[Test]
    public function automation_terminal_evidence_requires_a_completed_timestamp_and_safe_reason(): void
    {
        $scope = $this->stableScope();
        $rejected = false;
        try {
            DB::table('email_provider_reconciliation_items')
                ->where('id', $scope['item']->id)
                ->update([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
                    'automation_completed_at' => null,
                    'automation_error_code' => null,
                ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $pendingAttemptRejected = false;
        try {
            DB::table('email_provider_reconciliation_items')
                ->where('id', $scope['item']->id)
                ->update([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_PENDING,
                    'automation_attempt_count' => 1,
                    'automation_last_attempt_at' => now(),
                    'automation_rule_attempt_floor_id' => 0,
                ]);
        } catch (QueryException) {
            $pendingAttemptRejected = true;
        }
        $this->assertTrue($pendingAttemptRejected);
        $runningWithoutFloorRejected = false;
        try {
            DB::table('email_provider_reconciliation_items')
                ->where('id', $scope['item']->id)
                ->update([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                    'automation_claim_token' => hash('sha256', 'invalid-running-claim'),
                    'automation_attempt_count' => 1,
                    'automation_last_attempt_at' => now(),
                    'automation_rule_attempt_floor_id' => null,
                ]);
        } catch (QueryException) {
            $runningWithoutFloorRejected = true;
        }
        $this->assertTrue($runningWithoutFloorRejected);
        DB::table('email_provider_reconciliation_items')
            ->where('id', $scope['item']->id)
            ->update([
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
                'automation_completed_at' => now(),
                'automation_error_code' => 'provider_reconciliation_automation_existing_copy',
            ]);
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_SUPPRESSED,
            $scope['item']->fresh()->automation_status,
        );
    }

    #[Test]
    public function reconciliation_pending_markers_are_snapshot_neutral_only_for_v1_activation(): void
    {
        foreach ([
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ] as $index => $marker) {
            $scope = $this->snapshotScope($marker, $index + 1);
            $scope['run']->forceFill([
                'phase' => EmailProviderReconciliationRun::PHASE_SCAN,
            ])->save();
            $snapshots = app(EmailProviderReconciliationPlacementSnapshot::class);
            $baseline = $snapshots->advance(
                $scope['folder_run'],
                EmailProviderReconciliationFolder::SNAPSHOT_BASELINE,
            );
            $scope['folder_run']->forceFill([
                'baseline_max_placement_id' => $baseline['through_id'],
                'baseline_placement_count' => $baseline['count'],
                'placement_baseline_hash' => $baseline['hash'],
                'status' => EmailProviderReconciliationFolder::STATUS_SCANNING,
                'reason_code' => 'placement_scan_snapshot_pending',
            ])->save();

            $scope['placement']->forceFill([
                'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                'sync_error_code' => null,
                'last_provider_reconciliation_run_id' => $scope['run']->id,
                'last_provider_observed_sync_version' => 1,
                'last_provider_observed_identity_hash' => app(EmailProviderMessageIdentity::class)
                    ->forMessage($scope['message']),
                'last_provider_observed_at' => now(),
            ])->save();
            $scan = $snapshots->advance(
                $scope['folder_run']->fresh(),
                EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END,
                $baseline['through_id'],
            );

            $this->assertSame($baseline['hash'], $scan['hash']);
            $scope['folder_run']->forceFill([
                'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                'reason_code' => 'stable_end_validated',
                'placement_scan_hash' => $scan['hash'],
            ])->save();
            $scope['run']->forceFill([
                'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            ])->save();
            Queue::fake();
            $this->finalizeToTerminal($scope['run']);

            $this->assertSame(
                EmailProviderReconciliationRun::STATUS_COMPLETED,
                $scope['run']->fresh()->status,
            );
            $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $scope['placement']->fresh()->local_state);
            Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
        }
    }

    #[Test]
    public function failed_pending_import_remains_hidden_despite_snapshot_normalization(): void
    {
        $scope = $this->snapshotScope(EmailProviderReconciliationStore::STORE_PENDING_CODE, 9);
        $scope['run']->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_SCAN,
        ])->save();
        $snapshots = app(EmailProviderReconciliationPlacementSnapshot::class);
        $baseline = $snapshots->advance(
            $scope['folder_run'],
            EmailProviderReconciliationFolder::SNAPSHOT_BASELINE,
        );
        $scope['folder_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_SCANNING,
            'reason_code' => 'placement_scan_snapshot_pending',
        ])->save();
        $scan = $snapshots->advance(
            $scope['folder_run'],
            EmailProviderReconciliationFolder::SNAPSHOT_SCAN_END,
            $baseline['through_id'],
        );
        $scope['folder_run']->forceFill([
            'baseline_max_placement_id' => $baseline['through_id'],
            'baseline_placement_count' => $baseline['count'],
            'placement_baseline_hash' => $baseline['hash'],
            'placement_scan_hash' => $scan['hash'],
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'reason_code' => 'stable_end_validated',
        ])->save();
        $scope['run']->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
        ])->save();
        EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['folder_run']->id,
            'uid_namespace_id' => $scope['namespace']->id,
            'imap_uid' => $scope['placement']->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_FAILED,
            'result_placement_id' => $scope['placement']->id,
            'error_code' => 'provider_import_failed',
            'completed_at' => now(),
        ]);

        $this->finalizeToTerminal($scope['run']);

        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $scope['placement']->fresh()->local_state);
        $this->assertSame(
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            $scope['placement']->fresh()->sync_error_code,
        );
        $this->assertSame(EmailProviderReconciliationRun::STATUS_PARTIAL, $scope['run']->fresh()->status);
        $this->assertSame(1, $scope['run']->fresh()->error_count);
    }

    /** @return array<string,mixed> */
    private function stableScope(): array
    {
        $account = $this->account('correlation@example.test');
        [$inbox, $inboxNamespace] = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 701);
        [$archive, $archiveNamespace] = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE, 702);
        $hash = hash('sha256', 'stable-correlation-scope');
        $run = $this->reconciliationRun($account, $hash, 2);
        $inboxRun = $this->folderRun($run, $inbox, $inboxNamespace, true);
        $archiveRun = $this->folderRun($run, $archive, $archiveNamespace, true);
        [$message, $placement, $item] = $this->awaitingOccurrence([
            'account' => $account,
            'run' => $run,
            'inbox' => $inbox,
            'inbox_namespace' => $inboxNamespace,
            'inbox_run' => $inboxRun,
        ], 42);

        return compact(
            'account',
            'run',
            'inbox',
            'inboxNamespace',
            'inboxRun',
            'archive',
            'archiveNamespace',
            'archiveRun',
            'message',
            'placement',
            'item',
        ) + [
            'inbox_namespace' => $inboxNamespace,
            'inbox_run' => $inboxRun,
            'archive_namespace' => $archiveNamespace,
            'archive_run' => $archiveRun,
        ];
    }

    /** @return array<string,mixed> */
    private function attachAwaitingFanout(): array
    {
        $scope = $this->stableScope();
        Queue::fake();
        $this->assertTrue($this->advanceCorrelation($scope));

        $rules = $this->mock(InboundEmailRuleEngine::class);
        $rules->shouldReceive('allowsInboundAutomation')->once()->andReturnFalse();
        $rules->shouldNotReceive('processPreclassification');
        $rules->shouldNotReceive('process');
        $classifier = $this->mock(InboundEmailSignalClassifier::class);
        $classifier->shouldNotReceive('classifyAndRecord');
        $classifier->shouldNotReceive('shouldStopTicketRouting');
        $personalRules = $this->mock(PersonalEmailRuleEngine::class);
        $personalRules->shouldReceive('process')->once();

        $scope['run']->forceFill([
            'last_progress_at' => now()->subMinutes(5)->startOfSecond(),
        ])->save();
        $beforeAutomation = $scope['run']->fresh()->last_progress_at;
        (new ProcessEmailProviderReconciliationAutomation($scope['item']->id))->handle();

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
            $scope['item']->fresh()->automation_status,
        );
        $this->assertSame(1, NotificationInboundEmailFanout::query()->count());
        $this->assertTrue(
            $scope['run']->fresh()->last_progress_at->greaterThan($beforeAutomation),
        );

        return $scope;
    }

    private function assertFailedFinalizerPreservesAwaitingFanout(bool $abandoned): void
    {
        $scope = $this->attachAwaitingFanout();
        $fanout = NotificationInboundEmailFanout::query()->sole();
        if ($abandoned) {
            $witness = app(ResolveInboundEmailNotificationRecipients::class)
                ->pageWitness($fanout, DispatchInboundEmailNotification::PAGE_SIZE);
            $fanout->forceFill([
                'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
                'claim_token' => hash('sha256', 'abandoned-fanout-claim'),
                'page_setting_through_id' => $witness['setting_through_id'],
                'page_setting_row_count' => $witness['setting_row_count'],
                'page_owner_pending' => $witness['owner_pending'],
                'page_owner_candidate_included' => $witness['owner_candidate_included'],
                'page_attempt_count' => 1,
                'last_attempt_at' => now()->subMinutes(2),
            ])->save();
        }
        $runBefore = $scope['run']->fresh()->getAttributes();
        $itemBefore = $scope['item']->fresh()->getAttributes();
        $fanoutBefore = $fanout->fresh()->getAttributes();

        (new FinalizeEmailProviderReconciliation($scope['run']->id))->failed(
            new \RuntimeException('sanitized-finalizer-failure'),
        );

        $this->assertSame($runBefore, $scope['run']->fresh()->getAttributes());
        $this->assertSame($itemBefore, $scope['item']->fresh()->getAttributes());
        $this->assertSame($fanoutBefore, $fanout->fresh()->getAttributes());
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId
                === $scope['item']->id,
        );
        Queue::assertPushed(
            FinalizeEmailProviderReconciliation::class,
            fn (FinalizeEmailProviderReconciliation $job): bool => $job->runId
                === $scope['run']->id,
        );

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_COMPLETED,
            $scope['item']->fresh()->automation_status,
        );
        $this->finalizeToTerminal($scope['run']);
        $this->assertTrue($scope['run']->fresh()->terminal());
    }

    /** @return array{EmailMessage,EmailMailboxPlacement,EmailProviderReconciliationItem} */
    private function awaitingOccurrence(array $scope, int $uid): array
    {
        $receivedAt = Carbon::parse('2026-08-16 08:00:00', 'UTC');
        $message = EmailMessage::query()->create([
            'account_id' => $scope['account']->id,
            'mailbox' => $scope['inbox']->path,
            'imap_uid_validity' => $scope['inbox_namespace']->uid_validity,
            'imap_uid' => $uid,
            'message_id' => '<correlation@example.test>',
            'subject' => 'Correlation boundary',
            'from_email' => 'sender@example.test',
            'received_at' => $receivedAt,
            'size_bytes' => 4096,
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $scope['account']->id,
            'email_folder_id' => $scope['inbox']->id,
            'uid_namespace_id' => $scope['inbox_namespace']->id,
            'provider' => 'imap',
            'folder_path' => $scope['inbox']->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $scope['inbox_namespace']->uid_validity,
            'imap_uid' => $uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_provider_reconciliation_run_id' => $scope['run']->id,
            'last_provider_observed_sync_version' => 1,
            'last_provider_observed_identity_hash' => app(EmailProviderMessageIdentity::class)
                ->forMessage($message),
            'last_provider_observed_at' => now(),
        ]);
        $identity = app(EmailProviderMessageIdentity::class)->forMessage($message);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $scope['run']->id,
            'email_provider_reconciliation_folder_id' => $scope['inbox_run']->id,
            'uid_namespace_id' => $scope['inbox_namespace']->id,
            'imap_uid' => $uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'result_placement_id' => $placement->id,
            'identity_hash' => $identity,
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
            'completed_at' => now(),
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
        ]);

        return [$message, $placement, $item];
    }

    private function existingCandidate(
        array $scope,
        int $uid,
        string $messageId = '<correlation@example.test>',
        ?string $subject = null,
    ): EmailMailboxPlacement {
        $message = EmailMessage::query()->create([
            'account_id' => $scope['account']->id,
            'mailbox' => $scope['archive']->path,
            'imap_uid_validity' => $scope['archive_namespace']->uid_validity,
            'imap_uid' => $uid,
            'message_id' => $messageId,
            'subject' => $subject ?? $scope['message']->subject,
            'from_email' => $scope['message']->from_email,
            'received_at' => $scope['message']->received_at,
            'size_bytes' => $scope['message']->size_bytes,
            'state' => 'untriaged',
        ]);

        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $scope['account']->id,
            'email_folder_id' => $scope['archive']->id,
            'uid_namespace_id' => $scope['archive_namespace']->id,
            'provider' => 'imap',
            'folder_path' => $scope['archive']->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $scope['archive_namespace']->uid_validity,
            'imap_uid' => $uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_provider_reconciliation_run_id' => $scope['run']->id,
            'last_provider_observed_sync_version' => 1,
            'last_provider_observed_identity_hash' => app(EmailProviderMessageIdentity::class)
                ->forMessage($message),
            'last_provider_observed_at' => now(),
        ]);
    }

    private function trapInboundAutomation(): void
    {
        $ruleEngine = $this->mock(InboundEmailRuleEngine::class);
        $ruleEngine->shouldNotReceive('allowsInboundAutomation');
        $ruleEngine->shouldNotReceive('processPreclassification');
        $ruleEngine->shouldNotReceive('process');
        $classifier = $this->mock(InboundEmailSignalClassifier::class);
        $classifier->shouldNotReceive('classifyAndRecord');
        $classifier->shouldNotReceive('shouldStopTicketRouting');
        $personalRules = $this->mock(PersonalEmailRuleEngine::class);
        $personalRules->shouldNotReceive('process');
        $notifications = $this->mock(DispatchInboundEmailNotification::class);
        $notifications->shouldReceive('recoverReconciliationItem')->once()->andReturnFalse();
        $notifications->shouldNotReceive('handle');
        $notifications->shouldNotReceive('attachReconciliationIntent');
    }

    /** @return array<string,mixed> */
    private function snapshotScope(string $marker, int $suffix): array
    {
        $account = $this->account('snapshot-'.$suffix.'@example.test');
        [$folder, $namespace] = $this->folder(
            $account,
            'Archive-'.$suffix,
            EmailFolder::ROLE_ARCHIVE,
            800 + $suffix,
        );
        $hash = hash('sha256', 'snapshot-scope-'.$suffix);
        $run = $this->reconciliationRun($account, $hash, 1);
        $folderRun = $this->folderRun($run, $folder, $namespace, false);
        $folderRun->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_PENDING,
            'observed_count' => 1,
            'inventory_hash' => hash('sha256', 'inventory-'.$suffix),
        ])->save();
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 5,
            'message_id' => '<snapshot-'.$suffix.'@example.test>',
            'subject' => 'Snapshot neutral boundary',
            'from_email' => 'sender@example.test',
            'received_at' => Carbon::parse('2026-08-16 07:00:00', 'UTC'),
            'size_bytes' => 1024,
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'imap_uid_validity' => $namespace->uid_validity,
            'imap_uid' => 5,
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => $marker,
            'sync_version' => 1,
        ]);

        return [
            'account' => $account,
            'folder' => $folder,
            'namespace' => $namespace,
            'run' => $run,
            'folder_run' => $folderRun,
            'message' => $message,
            'placement' => $placement,
        ];
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Correlation Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
        ]);
    }

    /** @return array{EmailFolder,EmailFolderUidNamespace} */
    private function folder(
        EmailAccount $account,
        string $path,
        string $role,
        int $uidValidity,
    ): array {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => $path,
            'delimiter' => '/',
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'uid_next' => 50,
            'live_start_uid' => 40,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'uid_next_at_establishment' => 50,
            'live_start_uid' => 40,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$folder->refresh(), $namespace];
    }

    private function reconciliationRun(
        EmailAccount $account,
        string $scopeHash,
        int $folderCount,
    ): EmailProviderReconciliationRun {
        $snapshotAt = now()->subMinute();

        return EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'correlation:'.$account->id),
            'provider_binding_version' => 1,
            'start_folder_scope_hash' => $scopeHash,
            'end_folder_scope_hash' => $scopeHash,
            'local_folder_snapshot_status' => EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED,
            'local_folder_snapshot_through_id' => 0,
            'local_folder_snapshot_cursor_id' => 0,
            'local_folder_snapshot_count' => 0,
            'local_folder_snapshot_hash' => hash('sha256', ''),
            'local_folder_snapshot_batch_count' => 0,
            'local_folder_snapshot_started_at' => $snapshotAt,
            'local_folder_snapshot_completed_at' => $snapshotAt,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'folder_count' => $folderCount,
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);
    }

    private function folderRun(
        EmailProviderReconciliationRun $run,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        bool $complete,
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
            // Tests may append exact peer evidence after constructing the
            // shared scope. Keep the folder writable until that evidence is
            // complete, then seal it immediately before correlation.
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => $namespace->uid_validity,
            'start_uid_validity' => $namespace->uid_validity,
            'end_uid_validity' => $namespace->uid_validity,
            'start_uid_next' => 50,
            'end_uid_next' => 50,
            'start_exists_count' => 1,
            'end_exists_count' => 1,
            'start_highest_modseq' => 10,
            'end_highest_modseq' => 10,
            'supports_modseq' => true,
            'end_supports_modseq' => true,
            'scan_through_uid' => 49,
            'next_uid' => 50,
            'baseline_max_placement_id' => 0,
            'baseline_placement_count' => 0,
            'placement_baseline_hash' => hash('sha256', ''),
            'placement_scan_hash' => hash('sha256', ''),
            'inventory_hash' => hash('sha256', 'inventory:'.$folder->id),
            'observed_count' => 1,
            'reason_code' => $complete ? 'stable_end_validated' : null,
            'finished_at' => null,
        ]);
    }

    /** Seal test-built evidence before exercising the production correlator. */
    private function advanceCorrelation(array $scope): bool
    {
        $this->sealStableScope($scope);

        return app(EmailProviderReconciliationAutomationCorrelator::class)
            ->advance($scope['run']->fresh());
    }

    private function sealStableScope(array $scope): void
    {
        foreach (['inbox_run', 'archive_run'] as $key) {
            if (($scope[$key] ?? null) instanceof EmailProviderReconciliationFolder) {
                $this->sealFolderRun($scope[$key]);
            }
        }
    }

    private function sealFolderRun(EmailProviderReconciliationFolder $folderRun): void
    {
        $folderRun->refresh();
        if ($folderRun->status === EmailProviderReconciliationFolder::STATUS_COMPLETE
            || $folderRun->status !== EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS) {
            return;
        }

        $items = $folderRun->items()->orderBy('id')->get();
        $nonterminal = $items->contains(
            fn (EmailProviderReconciliationItem $item): bool => ! $item->terminal()
                || ($item->historical_baseline_required && ! $item->historicalBaselineTerminal()),
        );
        if ($nonterminal) {
            return;
        }

        $throughId = (int) ($items->last()?->id ?? 0);
        $missing = $items->where(
            'status',
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
        )->count();
        $moves = $items->filter(
            fn (EmailProviderReconciliationItem $item): bool => $item->kind
                === EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE
                && $item->status === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
        )->count();
        $conflicts = $items->where(
            'status',
            EmailProviderReconciliationItem::STATUS_CONFLICT,
        )->count();
        $now = now();
        $folderRun->forceFill([
            'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            'item_summary_through_id' => $throughId,
            'item_summary_cursor_id' => $throughId,
            'item_summary_missing_count' => $missing,
            'item_summary_move_count' => $moves,
            'item_summary_conflict_count' => $conflicts,
            'item_summary_nonterminal' => false,
            'item_summary_batch_count' => $items->isEmpty() ? 0 : (int) ceil($items->count() / 100),
            'item_summary_started_at' => $now,
            'item_summary_completed_at' => $now,
            'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
            'missing_count' => $missing,
            'conflict_count' => $conflicts,
            'reason_code' => null,
            'finished_at' => $now,
        ])->save();
    }

    private function finalizeToTerminal(EmailProviderReconciliationRun $run): void
    {
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;
        foreach (range(1, 20) as $attempt) {
            if ($finalizer->finalizeOneStep($run->fresh(), $reader)) {
                return;
            }
        }

        $this->fail('Reconciliation did not reach a terminal status within 20 bounded steps.');
    }
}
