<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\CancelEmailProviderReconciliation;
use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailPlacementCreateResult;
use App\Modules\Email\DTOs\EmailProviderReconciliationBindingSnapshot;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\DTOs\EmailProviderReconciliationMetadataPage;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\DTOs\EmailProviderReconciliationStoredMessage;
use App\Modules\Email\Jobs\FinalizeEmailProviderReconciliation;
use App\Modules\Email\Jobs\ImportEmailProviderReconciliationItem;
use App\Modules\Email\Jobs\ProcessEmailProviderReconciliationAutomation;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\TransitionEmailProviderReconciliationCancellation;
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
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderAbsenceProjector;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Services\EmailProviderReconciliationCoordinator;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Services\EmailProviderReconciliationFingerprint;
use App\Modules\Email\Services\EmailProviderReconciliationImporter;
use App\Modules\Email\Services\EmailProviderReconciliationScanner;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationMessageStore;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailProviderReconciliationCancellationRaceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cancellation_intent_is_idempotent_recoverable_and_uses_the_import_provider_lease(): void
    {
        Queue::fake();
        $account = $this->account('intent');
        [$folder, $namespace] = $this->folder($account);
        $run = $this->reconciliationRun(
            $account,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_SCAN,
        );
        $folderRun = $this->folderRun(
            $run,
            $folder,
            $namespace,
            EmailProviderReconciliationFolder::STATUS_SCANNING,
            $this->scanningFields($namespace),
        );
        $item = $this->importItem($run, $folderRun, $namespace, 42);
        $firstActor = User::factory()->create();
        $secondActor = User::factory()->create();

        $first = app(CancelEmailProviderReconciliation::class)->handle(
            $account,
            $run,
            $firstActor,
        );
        $requestedAt = $first->cancellation_requested_at?->toISOString();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_RUNNING, $first->status);
        $this->assertSame($firstActor->id, $first->cancelled_by);
        $this->assertNotNull($requestedAt);
        Queue::assertPushed(
            TransitionEmailProviderReconciliationCancellation::class,
            fn (TransitionEmailProviderReconciliationCancellation $job): bool => $job->runId === $run->id,
        );

        // Model losing the first queue dispatch. Repeating the already-durable
        // intent must preserve its original actor/time. RefreshDatabase keeps
        // an outer transaction open, so the second afterCommit callback is not
        // asserted here; the transition job below models the recovered wake.
        Queue::fake();
        $second = app(CancelEmailProviderReconciliation::class)->handle(
            $account,
            $run->fresh(),
            $secondActor,
        );
        $this->assertSame(EmailProviderReconciliationRun::STATUS_RUNNING, $second->status);
        $this->assertSame($firstActor->id, $second->cancelled_by);
        $this->assertSame($requestedAt, $second->cancellation_requested_at?->toISOString());
        $transitionJob = new TransitionEmailProviderReconciliationCancellation($run->id);
        $importJob = new ImportEmailProviderReconciliationItem($item->id);
        $transitionMiddleware = $transitionJob->middleware()[0];
        $importMiddleware = $importJob->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $transitionMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $importMiddleware);
        $this->assertTrue($transitionMiddleware->shareKey);
        $this->assertSame(
            $importMiddleware->getLockKey($importJob),
            $transitionMiddleware->getLockKey($transitionJob),
        );

        $transitionJob->handle(app(EmailProviderReconciliationCancellationTransition::class));
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLING,
            $run->fresh()->status,
        );
        Queue::assertPushed(
            FinalizeEmailProviderReconciliation::class,
            fn (FinalizeEmailProviderReconciliation $job): bool => $job->runId === $run->id,
        );

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new CancellationCallbackReader;
        foreach (range(1, 5) as $_) {
            if ($finalizer->finalizeOneStep($run->fresh(), $reader)) {
                break;
            }
        }

        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertNull($run->fresh()->active_slot);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_CANCELLED, $folderRun->fresh()->status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    #[Test]
    public function list_and_folder_state_results_cannot_commit_after_cancellation_intent(): void
    {
        Queue::fake();
        $descriptor = $this->descriptor();

        $listAccount = $this->account('list');
        $listRun = $this->reconciliationRun(
            $listAccount,
            EmailProviderReconciliationRun::STATUS_QUEUED,
            EmailProviderReconciliationRun::PHASE_DISCOVER_START,
        );
        $listReader = new CancellationCallbackReader;
        $listReader->folders = [$descriptor];
        $listReader->afterDiscover = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($listAccount, $listRun);

        $this->assertSame(
            [],
            app(EmailProviderReconciliationCoordinator::class)->discoverStart($listRun, $listReader),
        );
        $this->assertNotNull($listRun->fresh()->cancellation_requested_at);
        $this->assertNull($listRun->fresh()->start_folder_scope_hash);
        $this->assertSame(0, $listRun->folders()->count());

        $stateAccount = $this->account('state');
        [$folder, $namespace] = $this->folder($stateAccount, $descriptor->path);
        $stateRun = $this->reconciliationRun(
            $stateAccount,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_SCAN,
        );
        $folderRun = $this->folderRun(
            $stateRun,
            $folder,
            $namespace,
            EmailProviderReconciliationFolder::STATUS_PENDING,
        );
        $stateReader = new CancellationCallbackReader;
        $stateReader->state = new EmailProviderReconciliationFolderState(901, 99, 12, false, null);
        $stateReader->afterFolderState = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($stateAccount, $stateRun);

        $result = app(EmailProviderReconciliationScanner::class)->scanOnePage(
            $folderRun,
            $stateReader,
        );

        $this->assertFalse($result['folder_finished']);
        $this->assertSame([], $result['import_item_ids']);
        $this->assertNotNull($stateRun->fresh()->cancellation_requested_at);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_PENDING, $folderRun->fresh()->status);
        $this->assertNull($folderRun->fresh()->start_uid_validity);
        $this->assertSame(43, $folder->fresh()->uid_next);
        $this->assertSame($namespace->id, $folder->fresh()->active_uid_namespace_id);
    }

    #[Test]
    public function metadata_and_terminal_pages_cannot_project_flags_or_advance_after_intent(): void
    {
        Queue::fake();
        $account = $this->account('metadata');
        [$folder, $namespace] = $this->folder($account);
        $run = $this->reconciliationRun(
            $account,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_SCAN,
        );
        $folderRun = $this->folderRun(
            $run,
            $folder,
            $namespace,
            EmailProviderReconciliationFolder::STATUS_SCANNING,
            $this->scanningFields($namespace),
        );
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 42);
        $placement->forceFill([
            'provider_seen' => false,
            'provider_flagged' => false,
            'flags_json' => [],
        ])->save();
        $before = $placement->fresh()->getAttributes();
        $reader = new CancellationCallbackReader;
        $reader->page = new EmailProviderReconciliationMetadataPage([
            new EmailProviderReconciliationMessageMetadata(
                uid: 42,
                modseq: null,
                seen: true,
                answered: false,
                flagged: true,
                deleted: false,
                draft: false,
                customFlags: ['cancelled-page'],
            ),
        ], completeThroughUid: 42);
        $reader->afterMetadata = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($account, $run);

        $result = app(EmailProviderReconciliationScanner::class)->scanOnePage($folderRun, $reader);

        $this->assertFalse($result['folder_finished']);
        $this->assertSame([], $result['import_item_ids']);
        $this->assertSame($before, $placement->fresh()->getAttributes());
        $this->assertSame(42, $folderRun->fresh()->next_uid);
        $this->assertSame(0, $folderRun->fresh()->batch_count);
        $this->assertSame(0, $folderRun->items()->count());

        $terminalAccount = $this->account('terminal');
        [$terminalFolder, $terminalNamespace] = $this->folder($terminalAccount);
        $terminalRun = $this->reconciliationRun(
            $terminalAccount,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_SCAN,
        );
        $terminalFolderRun = $this->folderRun(
            $terminalRun,
            $terminalFolder,
            $terminalNamespace,
            EmailProviderReconciliationFolder::STATUS_SCANNING,
            $this->scanningFields($terminalNamespace, nextUid: 43),
        );
        $terminalReader = new CancellationCallbackReader;
        $terminalReader->page = new EmailProviderReconciliationMetadataPage(
            [],
            terminal: true,
            completeThroughUid: 42,
        );
        $terminalReader->afterMetadata = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($terminalAccount, $terminalRun);

        $terminalResult = app(EmailProviderReconciliationScanner::class)->scanOnePage(
            $terminalFolderRun,
            $terminalReader,
        );

        $this->assertFalse($terminalResult['folder_finished']);
        $this->assertSame(43, $terminalFolderRun->fresh()->next_uid);
        $this->assertSame(0, $terminalFolderRun->fresh()->batch_count);
        $this->assertNull($terminalFolderRun->fresh()->reason_code);
        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_SCANNING,
            $terminalFolderRun->fresh()->status,
        );
    }

    #[Test]
    public function peek_null_after_intent_does_not_terminalize_the_claim_or_call_store(): void
    {
        Queue::fake();
        $account = $this->account('peek');
        [$folder, $namespace] = $this->folder($account);
        $run = $this->reconciliationRun(
            $account,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            EmailProviderReconciliationRun::PHASE_IMPORTS,
        );
        $folderRun = $this->folderRun(
            $run,
            $folder,
            $namespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            [
                'expected_uid_validity' => $namespace->uid_validity,
                'start_uid_validity' => $namespace->uid_validity,
                'scan_through_uid' => 42,
                'next_uid' => 43,
            ],
        );
        $item = $this->importItem($run, $folderRun, $namespace, 42);
        $reader = new CancellationCallbackReader;
        $reader->message = null;
        $reader->afterPeek = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($account, $run);
        $store = new FakeEmailProviderReconciliationMessageStore;

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $item,
            $reader,
            $store,
        );

        $this->assertSame(EmailProviderReconciliationItem::STATUS_RUNNING, $status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_RUNNING, $item->fresh()->status);
        $this->assertSame(1, $item->fresh()->attempt_count);
        $this->assertNull($item->fresh()->completed_at);
        $this->assertNull($item->fresh()->error_code);
        $this->assertSame([], $store->calls);
        $this->assertNotNull($run->fresh()->cancellation_requested_at);
        Queue::assertPushed(
            TransitionEmailProviderReconciliationCancellation::class,
            fn (TransitionEmailProviderReconciliationCancellation $job): bool => $job->runId === $run->id,
        );
    }

    #[Test]
    public function intent_after_hidden_store_commit_never_activates_or_automates_the_occurrence(): void
    {
        Queue::fake();
        $account = $this->account('peek-body');
        [$folder, $namespace] = $this->folder($account);
        $run = $this->reconciliationRun(
            $account,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            EmailProviderReconciliationRun::PHASE_IMPORTS,
        );
        $folderRun = $this->folderRun(
            $run,
            $folder,
            $namespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            [
                'expected_uid_validity' => $namespace->uid_validity,
                'start_uid_validity' => $namespace->uid_validity,
                'scan_through_uid' => 42,
                'next_uid' => 43,
            ],
        );
        $item = $this->importItem($run, $folderRun, $namespace, 42);
        $envelope = Message::fromString(implode("\r\n", [
            'Message-ID: <cancel-after-store@example.test>',
            'Subject: Hidden cancellation result',
            'From: sender@example.test',
            '',
            'Provider content remains hidden.',
        ]));
        $envelope->setUid(42)->setFolderPath($folder->path);
        $reader = new CancellationCallbackReader;
        $reader->message = new EmailProviderReconciliationPeekedMessage(
            ['imap_uid' => 42],
            $envelope,
        );
        $store = new FakeEmailProviderReconciliationMessageStore;
        $storedMessage = null;
        $storedPlacement = null;
        $store->callback = function (array $arguments) use (
            $account,
            $folder,
            $namespace,
            $run,
            &$storedMessage,
            &$storedPlacement,
        ): EmailProviderReconciliationStoredMessage {
            [$storedMessage, $storedPlacement] = $this->messageAndPlacement(
                $account,
                $folder,
                $namespace,
                (int) $arguments['uid'],
            );
            $storedMessage->forceFill([
                'raw_path' => sprintf(
                    'email/raw/v2/%d/%s/%d/%d.eml',
                    $account->id,
                    hash('sha256', $folder->path),
                    $namespace->uid_validity,
                    $arguments['uid'],
                ),
            ])->save();
            $storedPlacement->forceFill([
                'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
                'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
                'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
            ])->save();
            $this->assertNotNull(
                app(EmailConversationProjector::class)
                    ->assignPendingPlacement($storedPlacement->fresh()),
            );

            // This is the unlocked boundary after Store has committed a
            // durable hidden reference and before Importer acceptance.
            app(CancelEmailProviderReconciliation::class)->handle($account, $run);

            return new EmailProviderReconciliationStoredMessage(
                $storedMessage->id,
                $storedPlacement->id,
                app(EmailProviderMessageIdentity::class)->forMessage($storedMessage),
                EmailPlacementCreateResult::CREATED_PENDING,
                1,
            );
        };

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $item,
            $reader,
            $store,
        );

        $this->assertSame(EmailProviderReconciliationItem::STATUS_RUNNING, $status);
        $this->assertCount(1, $store->calls);
        $this->assertSame(1, $store->calls[0]['claimAttempt']);
        $this->assertInstanceOf(EmailMessage::class, $storedMessage);
        $this->assertInstanceOf(EmailMailboxPlacement::class, $storedPlacement);
        $this->assertNotNull($storedMessage->fresh()->raw_path);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $storedPlacement->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_PENDING, $storedPlacement->fresh()->sync_status);
        $this->assertSame(
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            $storedPlacement->fresh()->sync_error_code,
        );
        $storedPlacement = $storedPlacement->fresh();
        $this->assertSame(0, $storedPlacement->conversation()->firstOrFail()->active_placement_count);
        $this->assertNull($item->fresh()->result_placement_id);
        $this->assertFalse($item->fresh()->automation_required);
        $this->assertNull($item->fresh()->automation_status);
        Queue::assertNotPushed(ProcessInboundRules::class);
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
        $this->assertSame(0, EmailRuleExecutionAttempt::query()
            ->where('email_message_id', $storedMessage->id)
            ->count());

        app(EmailProviderReconciliationCancellationTransition::class)->transition($run->id);
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        foreach (range(1, 5) as $_) {
            if ($finalizer->finalizeOneStep($run->fresh(), $reader)) {
                break;
            }
        }

        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_CANCELLED, $item->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $storedPlacement->fresh()->local_state);
        $this->assertSame(
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
            $storedPlacement->fresh()->sync_error_code,
        );
        $this->assertNotNull($storedMessage->fresh()->raw_path);
    }

    #[Test]
    public function end_list_and_folder_state_results_cannot_commit_after_intent(): void
    {
        Queue::fake();
        $descriptor = $this->descriptor();
        $scopeHash = app(EmailProviderReconciliationFingerprint::class)->folderScope([$descriptor]);

        $listAccount = $this->account('end-list');
        [$listFolder, $listNamespace] = $this->folder($listAccount, $descriptor->path);
        $listRun = $this->reconciliationRun(
            $listAccount,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_FINALIZE,
            $this->completedLocalSnapshotFields() + ['start_folder_scope_hash' => $scopeHash],
        );
        $this->folderRun(
            $listRun,
            $listFolder,
            $listNamespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
        );
        $listReader = new CancellationCallbackReader;
        $listReader->folders = [$descriptor];
        $listReader->afterDiscover = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($listAccount, $listRun);

        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $listRun,
            $listReader,
        ));
        $this->assertNotNull($listRun->fresh()->cancellation_requested_at);
        $this->assertNull($listRun->fresh()->end_folder_scope_hash);
        $this->assertSame(EmailProviderReconciliationRun::PHASE_FINALIZE, $listRun->fresh()->phase);

        $stateAccount = $this->account('end-state');
        [$stateFolder, $stateNamespace] = $this->folder($stateAccount, $descriptor->path);
        $stateRun = $this->reconciliationRun(
            $stateAccount,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            $this->completedLocalSnapshotFields() + [
                'start_folder_scope_hash' => $scopeHash,
                'end_folder_scope_hash' => $scopeHash,
            ],
        );
        $stateFolderRun = $this->folderRun(
            $stateRun,
            $stateFolder,
            $stateNamespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            $this->scanningFields($stateNamespace) + ['reason_code' => null],
        );
        $stateReader = new CancellationCallbackReader;
        $stateReader->state = new EmailProviderReconciliationFolderState(901, 43, 1, false, null);
        $stateReader->afterFolderState = fn () => app(CancelEmailProviderReconciliation::class)
            ->handle($stateAccount, $stateRun);

        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $stateRun,
            $stateReader,
        ));
        $this->assertNotNull($stateRun->fresh()->cancellation_requested_at);
        $this->assertNull($stateFolderRun->fresh()->end_uid_validity);
        $this->assertNull($stateFolderRun->fresh()->end_uid_next);
        $this->assertNull($stateFolderRun->fresh()->end_exists_count);
    }

    #[Test]
    public function intent_before_historical_page_or_activation_keeps_history_hidden_and_personal_state_absent(): void
    {
        Queue::fake();
        $scope = $this->historicalScope('baseline-page');
        $projection = app(ProjectHistoricalEmailReadBaseline::class);
        $claimedPageToken = $projection->claimReconciliationBatch($scope['item']->id);
        $this->assertNotNull($claimedPageToken);
        app(CancelEmailProviderReconciliation::class)->handle($scope['account'], $scope['run']);

        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_CANCELLED,
            $projection->projectReconciliationBatch(
                $scope['item']->id,
                $claimedPageToken,
                1,
            ),
        );
        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CANCELLED,
            $scope['item']->fresh()->status,
        );
        $this->assertSame(
            EmailProviderReconciliationItem::HISTORICAL_BASELINE_CANCELLED,
            $scope['item']->fresh()->historical_baseline_status,
        );
        $this->assertSame(0, EmailMessageUserState::query()
            ->where('email_message_id', $scope['message']->id)
            ->count());
        $this->assertHistoricalPlacementHidden($scope['placement']);

        $paged = $this->historicalScope('baseline-paged');
        $secondViewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $paged['account']->id,
            'user_id' => $secondViewer->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
        ]);
        $secondBaseline = EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $paged['account']->id,
            'user_id' => $secondViewer->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'direct_grant',
            'recorded_at' => now()->subHour(),
            'entitlement_changed_at' => now()->subHour(),
        ]);
        $paged['item']->forceFill([
            'historical_baseline_max_id' => $secondBaseline->id,
        ])->save();
        $pageToken = $projection->claimReconciliationBatch($paged['item']->id);
        $this->assertNotNull($pageToken);
        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_PENDING,
            $projection->projectReconciliationBatch($paged['item']->id, $pageToken, 1),
        );
        $cursorAfterFirstPage = $paged['item']->fresh()->historical_baseline_cursor_id;
        $this->assertGreaterThan(0, $cursorAfterFirstPage);
        $this->assertSame(1, EmailMessageUserState::query()
            ->where('email_message_id', $paged['message']->id)
            ->count());

        app(CancelEmailProviderReconciliation::class)->handle($paged['account'], $paged['run']);
        $this->assertNull($projection->claimReconciliationBatch($paged['item']->id));
        $this->assertSame(
            $cursorAfterFirstPage,
            $paged['item']->fresh()->historical_baseline_cursor_id,
        );
        $this->assertSame(1, EmailMessageUserState::query()
            ->where('email_message_id', $paged['message']->id)
            ->count());
        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CANCELLED,
            $paged['item']->fresh()->status,
        );
        $this->assertHistoricalPlacementHidden($paged['placement']);

        $activation = $this->historicalScope('baseline-activation');
        $token = hash('sha256', 'cancel-before-historical-activation');
        $activation['item']->forceFill([
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
            'historical_baseline_claim_token' => $token,
            'historical_baseline_attempt_count' => 1,
            'historical_baseline_first_attempt_at' => now(),
            'historical_baseline_last_attempt_at' => now(),
            'historical_baseline_cursor_id' => $activation['item']->historical_baseline_max_id,
        ])->save();
        app(CancelEmailProviderReconciliation::class)->handle(
            $activation['account'],
            $activation['run'],
        );
        $complete = new ReflectionMethod(ProjectHistoricalEmailReadBaseline::class, 'completeReconciliationBatch');
        $complete->setAccessible(true);

        $this->assertSame(
            ProjectHistoricalEmailReadBaseline::RECONCILIATION_CANCELLED,
            $complete->invoke($projection, $activation['item']->id, $token),
        );
        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_CANCELLED,
            $activation['item']->fresh()->status,
        );
        $this->assertSame(0, EmailMessageUserState::query()
            ->where('email_message_id', $activation['message']->id)
            ->count());
        $this->assertHistoricalPlacementHidden($activation['placement']);
    }

    #[Test]
    public function intent_blocks_db_only_absence_and_automation_claims(): void
    {
        Queue::fake();
        $absenceAccount = $this->account('absence');
        [$absenceFolder, $absenceNamespace] = $this->folder($absenceAccount);
        $absenceRun = $this->reconciliationRun(
            $absenceAccount,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::PHASE_DISCOVER_END,
        );
        $absenceFolderRun = $this->folderRun(
            $absenceRun,
            $absenceFolder,
            $absenceNamespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            ['reason_code' => 'stable_absence_projection'],
        );
        [$absenceMessage, $absencePlacement] = $this->messageAndPlacement(
            $absenceAccount,
            $absenceFolder,
            $absenceNamespace,
            42,
        );
        $absenceItem = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $absenceRun->id,
            'email_provider_reconciliation_folder_id' => $absenceFolderRun->id,
            'uid_namespace_id' => $absenceNamespace->id,
            'imap_uid' => 42,
            'kind' => EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            'source_placement_id' => $absencePlacement->id,
            'identity_hash' => app(\App\Modules\Email\Services\EmailProviderMessageIdentity::class)
                ->forMessage($absenceMessage),
            'placement_sync_version_before' => $absencePlacement->sync_version,
        ]);
        app(CancelEmailProviderReconciliation::class)->handle($absenceAccount, $absenceRun);

        $this->assertFalse(app(EmailProviderAbsenceProjector::class)->confirmMissing(
            $absenceRun,
            $absenceItem,
        ));
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $absencePlacement->fresh()->local_state);
        $this->assertNull($absencePlacement->fresh()->provider_missing_at);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PENDING, $absenceItem->fresh()->status);

        $automationAccount = $this->account('automation');
        [$automationFolder, $automationNamespace] = $this->folder($automationAccount);
        $automationRun = $this->reconciliationRun(
            $automationAccount,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            EmailProviderReconciliationRun::PHASE_IMPORTS,
        );
        $automationFolderRun = $this->folderRun(
            $automationRun,
            $automationFolder,
            $automationNamespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
        );
        [, $automationPlacement] = $this->messageAndPlacement(
            $automationAccount,
            $automationFolder,
            $automationNamespace,
            42,
        );
        $automationItem = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $automationRun->id,
            'email_provider_reconciliation_folder_id' => $automationFolderRun->id,
            'uid_namespace_id' => $automationNamespace->id,
            'imap_uid' => 42,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'result_placement_id' => $automationPlacement->id,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_PENDING,
            'automation_attempt_count' => 0,
            'completed_at' => now(),
        ]);
        app(CancelEmailProviderReconciliation::class)->handle($automationAccount, $automationRun);

        (new ProcessEmailProviderReconciliationAutomation($automationItem->id))->handle();

        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_CANCELLED,
            $automationItem->fresh()->automation_status,
        );
        $this->assertSame(0, $automationItem->fresh()->automation_attempt_count);
        $this->assertNull($automationItem->fresh()->automation_claim_token);
        $this->assertSame(0, EmailRuleExecutionAttempt::query()
            ->where('email_message_id', $automationPlacement->email_message_id)
            ->count());
    }

    private function account(string $suffix): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => "reconciliation-cancel-{$suffix}@example.test",
            'from_name' => 'Cancellation race test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => "reconciliation-cancel-{$suffix}@example.test",
            'imap_secret' => encrypt('test-secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => "reconciliation-cancel-{$suffix}@example.test",
            'smtp_secret' => encrypt('test-secret'),
            'smtp_auth_type' => 'password',
        ]);
    }

    /** @return array{EmailFolder,EmailFolderUidNamespace} */
    private function folder(EmailAccount $account, string $path = 'INBOX'): array
    {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => $path,
            'delimiter' => '/',
            'role' => $path === 'INBOX' ? EmailFolder::ROLE_INBOX : EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 901,
            'uid_next' => 43,
            'live_start_uid' => 41,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => 901,
            'uid_next_at_establishment' => 43,
            'live_start_uid' => 41,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'cancellation_race_test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$folder->fresh(), $namespace];
    }

    /** @param array<string,mixed> $attributes */
    private function reconciliationRun(
        EmailAccount $account,
        string $status,
        string $phase,
        array $attributes = [],
    ): EmailProviderReconciliationRun {
        return EmailProviderReconciliationRun::query()->create($attributes + [
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => $status,
            'phase' => $phase,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'cancellation-race:'.$account->id),
            'provider_binding_version' => 1,
            'max_folders' => 20,
            'uid_batch_size' => 20,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now()->subMinute(),
            'started_at' => $status === EmailProviderReconciliationRun::STATUS_QUEUED
                ? null
                : now()->subMinute(),
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function folderRun(
        EmailProviderReconciliationRun $run,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        string $status,
        array $attributes = [],
    ): EmailProviderReconciliationFolder {
        return EmailProviderReconciliationFolder::query()->create($attributes + [
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $run->account_id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => $status,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
        ]);
    }

    /** @return array<string,mixed> */
    private function scanningFields(
        EmailFolderUidNamespace $namespace,
        int $nextUid = 42,
    ): array {
        return [
            'expected_uid_validity' => $namespace->uid_validity,
            'start_uid_validity' => $namespace->uid_validity,
            'start_uid_next' => 43,
            'start_exists_count' => 1,
            'scan_through_uid' => 42,
            'next_uid' => $nextUid,
        ];
    }

    /** @return array<string,mixed> */
    private function completedLocalSnapshotFields(): array
    {
        return [
            'local_folder_snapshot_status' => EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED,
            'local_folder_snapshot_through_id' => 0,
            'local_folder_snapshot_cursor_id' => 0,
            'local_folder_snapshot_count' => 0,
            'local_folder_snapshot_hash' => hash('sha256', ''),
            'local_folder_snapshot_batch_count' => 0,
            'local_folder_snapshot_started_at' => now()->subMinute(),
            'local_folder_snapshot_completed_at' => now()->subMinute(),
        ];
    }

    private function importItem(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailFolderUidNamespace $namespace,
        int $uid,
    ): EmailProviderReconciliationItem {
        return EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
        ]);
    }

    /** @return array{EmailMessage,EmailMailboxPlacement} */
    private function messageAndPlacement(
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
            'message_id' => '<cancel-'.$account->id.'-'.$uid.'@example.test>',
            'subject' => 'Cancellation boundary',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMinute()->startOfSecond(),
            'size_bytes' => 2048,
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
            'provider_seen' => false,
            'provider_answered' => false,
            'provider_flagged' => false,
            'provider_deleted' => false,
            'provider_draft' => false,
            'flags_json' => [],
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);

        return [$message, $placement];
    }

    /**
     * @return array{
     *   account:EmailAccount,
     *   run:EmailProviderReconciliationRun,
     *   message:EmailMessage,
     *   placement:EmailMailboxPlacement,
     *   item:EmailProviderReconciliationItem
     * }
     */
    private function historicalScope(string $suffix): array
    {
        $account = $this->account($suffix);
        [$folder, $namespace] = $this->folder($account, 'Projects/History');
        [$message, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 42);
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ])->save();
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
        ]);
        $baseline = EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'direct_grant',
            'recorded_at' => now()->subHour(),
            'entitlement_changed_at' => now()->subHour(),
        ]);
        $run = $this->reconciliationRun(
            $account,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            EmailProviderReconciliationRun::PHASE_IMPORTS,
        );
        $folderRun = $this->folderRun(
            $run,
            $folder,
            $namespace,
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            [
                'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
                'import_policy' => EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
                'expected_uid_validity' => $namespace->uid_validity,
                'start_uid_validity' => $namespace->uid_validity,
                'scan_through_uid' => 42,
                'next_uid' => 43,
            ],
        );
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 42,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'result_placement_id' => $placement->id,
            'attempt_count' => 1,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => $baseline->id,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_frozen_at' => now(),
        ]);

        return compact('account', 'run', 'message', 'placement', 'item');
    }

    private function assertHistoricalPlacementHidden(EmailMailboxPlacement $placement): void
    {
        $placement->refresh();
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_PENDING, $placement->sync_status);
        $this->assertSame(
            EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
            $placement->sync_error_code,
        );
    }

    private function descriptor(): EmailProviderReconciliationFolderDescriptor
    {
        return new EmailProviderReconciliationFolderDescriptor(
            path: 'Projects/Cancellation',
            name: 'Cancellation',
            delimiter: '/',
        );
    }
}

/**
 * Read-only fake with hooks immediately after a provider result is obtained.
 * It lets the tests commit cancellation intent in the exact unlocked gap before
 * the coordinator/importer revalidates and persists that result.
 */
final class CancellationCallbackReader implements EmailProviderReconciliationReader
{
    /** @var array<int,EmailProviderReconciliationFolderDescriptor> */
    public array $folders = [];

    public ?EmailProviderReconciliationFolderState $state = null;

    public ?EmailProviderReconciliationMetadataPage $page = null;

    public ?EmailProviderReconciliationPeekedMessage $message = null;

    public ?Closure $afterDiscover = null;

    public ?Closure $afterFolderState = null;

    public ?Closure $afterMetadata = null;

    public ?Closure $afterPeek = null;

    public function binding(
        int $accountId,
        int $expectedBindingVersion,
    ): EmailProviderReconciliationBindingSnapshot {
        return new EmailProviderReconciliationBindingSnapshot(
            bindingVersion: $expectedBindingVersion,
            configurationVersion: 1,
            credentialVersion: 0,
            runtimeFingerprint: str_repeat('a', 64),
        );
    }

    public function discoverFolders(
        int $accountId,
        int $expectedBindingVersion,
        int $timeCapSeconds,
    ): array {
        if ($this->afterDiscover) {
            ($this->afterDiscover)();
        }

        return $this->folders;
    }

    public function folderState(
        int $accountId,
        int $expectedBindingVersion,
        string $folderPath,
        int $timeCapSeconds,
    ): EmailProviderReconciliationFolderState {
        if ($this->afterFolderState) {
            ($this->afterFolderState)();
        }

        return $this->state ?? new EmailProviderReconciliationFolderState(901, 43, 1, false, null);
    }

    public function metadataPage(
        int $accountId,
        int $expectedBindingVersion,
        string $folderPath,
        int $uidValidity,
        int $afterUid,
        int $throughUid,
        int $limit,
        int $timeCapSeconds,
    ): EmailProviderReconciliationMetadataPage {
        if ($this->afterMetadata) {
            ($this->afterMetadata)();
        }

        return $this->page ?? new EmailProviderReconciliationMetadataPage(
            [],
            terminal: true,
            completeThroughUid: $throughUid,
        );
    }

    public function messageByUidPeek(
        int $accountId,
        int $expectedBindingVersion,
        string $folderPath,
        int $uidValidity,
        int $uid,
        int $timeCapSeconds,
    ): ?EmailProviderReconciliationPeekedMessage {
        if ($this->afterPeek) {
            ($this->afterPeek)();
        }

        return $this->message;
    }
}
