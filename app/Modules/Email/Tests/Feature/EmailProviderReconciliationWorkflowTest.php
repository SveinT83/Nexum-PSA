<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\Contracts\EmailProviderReconciliationMessageStore;
use App\Modules\Email\DTOs\EmailPlacementCreateResult;
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
use App\Modules\Email\Jobs\ProjectEmailProviderHistoricalReadBaseline;
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
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Services\EmailProviderReconciliationCoordinator;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Services\EmailProviderReconciliationImporter;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Services\EmailProviderReconciliationScanner;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\InboundEmailSignalClassifier;
use App\Modules\Email\Services\PersonalEmailRuleEngine;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationMessageStore;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationReader;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailProviderReconciliationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_empty_scan_cycle_seals_writers_before_end_discovery_and_reaches_a_terminal_summary(): void
    {
        [$account, $folder] = $this->mailbox(
            'reconcile-empty-cycle@example.test',
            EmailFolder::ROLE_CUSTOM,
            909,
            1,
        );
        $folder->forceFill([
            'is_selectable' => false,
            'sync_enabled' => false,
        ])->save();
        $run = $this->reconciliationRun($account);
        $reader = new FakeEmailProviderReconciliationReader;
        $reader->folders = [];

        $this->assertSame([], app(EmailProviderReconciliationCoordinator::class)->discoverStart(
            $run,
            $reader,
        ));
        $this->assertSame(EmailProviderReconciliationRun::PHASE_SCAN, $run->fresh()->phase);
        $discoverCalls = count(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'discover',
        ));

        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $run->fresh(),
            $reader,
        ));
        $this->assertSame(EmailProviderReconciliationRun::PHASE_FINALIZE, $run->fresh()->phase);
        $this->assertSame($discoverCalls, count(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'discover',
        )));

        $this->finish($run, $reader);

        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNull($run->fresh()->active_slot);
        $this->assertSame(
            EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED,
            $run->fresh()->final_summary_status,
        );
    }

    #[Test]
    public function an_all_known_cycle_with_no_imports_crosses_the_scan_finalization_barrier_and_completes(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-known-cycle@example.test',
            EmailFolder::ROLE_CUSTOM,
            909,
            10,
        );
        $this->completedBaseline($account);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 9);
        $run = $this->reconciliationRun($account);
        $state = new EmailProviderReconciliationFolderState(909, 10, 1, true, 100);
        $reader = $this->reader($folder, [$state, $state]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    uid: 9,
                    modseq: 100,
                    seen: false,
                    answered: false,
                    flagged: false,
                    deleted: false,
                    draft: false,
                ),
            ], completeThroughUid: 9),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 9),
        ];

        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $finished = false;
        foreach (range(1, 10) as $_) {
            $finished = $scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished'];
            if ($finished) {
                break;
            }
        }
        $this->assertTrue($finished);
        $this->assertFalse($run->items()
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->exists());
        $this->assertSame(EmailProviderReconciliationRun::PHASE_SCAN, $run->fresh()->phase);

        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $run->fresh(),
            $reader,
        ));
        $this->assertSame(EmailProviderReconciliationRun::PHASE_FINALIZE, $run->fresh()->phase);

        $this->finish($run, $reader);

        $this->assertSame(EmailProviderReconciliationFolder::STATUS_COMPLETE, $folderRun->fresh()->status);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(2, $placement->fresh()->sync_version);
        $this->assertCount(0, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'peek',
        ));
    }

    #[Test]
    public function live_inbox_import_uses_one_exact_peek_and_a_store_contract_with_no_provider_mutation_option(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-import@example.test', EmailFolder::ROLE_INBOX, 910, 501);
        $this->completedBaseline($account);
        $run = $this->reconciliationRun($account);
        $state = new EmailProviderReconciliationFolderState(910, 501, 1, false, null);
        $reader = $this->reader($folder, [$state, $state]);
        $reader->metadataPages[$folder->path] = [
            $scanPage = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    uid: 500,
                    modseq: null,
                    seen: false,
                    answered: false,
                    flagged: true,
                    deleted: false,
                    draft: false,
                    customFlags: ['Customer'],
                ),
            ], completeThroughUid: 500),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 500),
            $verifiedPage = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    uid: 500,
                    modseq: null,
                    seen: true,
                    answered: false,
                    flagged: true,
                    deleted: false,
                    draft: false,
                    customFlags: ['Customer'],
                ),
            ], completeThroughUid: 500),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 500),
            $verifiedPage,
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 500),
        ];
        $providerPayload = [
            'message_id' => '<provider-import-500@example.test>',
            'subject' => 'Exact provider import',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMinute()->startOfSecond(),
            'size_bytes' => 4096,
            'body_text' => 'Fetched once with BODY.PEEK.',
        ];
        $providerMessage = Message::fromString(implode("\r\n", [
            'Message-ID: <provider-import-500@example.test>',
            'Subject: Exact provider import',
            'From: sender@example.test',
            '',
            'Fetched once with BODY.PEEK.',
        ]));
        $providerMessage->setUid(500)->setFolderPath($folder->path);
        $reader->messages[$folder->path.':500'] = new EmailProviderReconciliationPeekedMessage(
            $providerPayload + ['imap_uid' => 500],
            $providerMessage,
        );
        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $page = $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->assertCount(1, $page['import_item_ids']);
        $item = EmailProviderReconciliationItem::query()->findOrFail($page['import_item_ids'][0]);
        $this->assertNull($item->source_placement_id);
        Queue::fake();

        // The terminal page wins before the detached PEEK/import worker.
        // Neither it nor a redelivered folder job may consume verification
        // evidence until the import is no longer retryable.
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame('nomodseq_imports_pending', $folderRun->fresh()->reason_code);
        $this->assertNull($folderRun->fresh()->metadata_verification_status);
        $metadataCallCount = count(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        ));
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame($metadataCallCount, count(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        )));
        Queue::assertPushed(
            ImportEmailProviderReconciliationItem::class,
            fn (ImportEmailProviderReconciliationItem $job): bool => $job->itemId === $item->id,
        );

        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = function (array $arguments) use ($account, $folder, $namespace): EmailProviderReconciliationStoredMessage {
            /** @var EmailProviderReconciliationPeekedMessage $peeked */
            $peeked = $arguments['peeked'];
            $payload = $peeked->payload();
            $this->assertSame($arguments['uid'], (int) $peeked->message()->getUid());
            $message = EmailMessage::query()->create([
                'account_id' => $account->id,
                'mailbox' => $folder->path,
                'imap_uid' => $arguments['uid'],
                'imap_uid_validity' => $namespace->uid_validity,
                'message_id' => $payload['message_id'],
                'subject' => $payload['subject'],
                'from_email' => $payload['from_email'],
                'received_at' => $payload['received_at'],
                'size_bytes' => $payload['size_bytes'],
                'state' => 'untriaged',
                'body_text' => $payload['body_text'],
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
                'imap_uid' => $arguments['uid'],
                'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
                'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
                'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
                'sync_version' => 1,
                'provider_seen' => true,
                'provider_flagged' => true,
                'flags_json' => ['\\Seen', '\\Flagged', 'customer'],
            ]);
            $this->assertNotNull(
                app(EmailConversationProjector::class)->assignPendingPlacement($placement),
            );

            return new EmailProviderReconciliationStoredMessage(
                $message->id,
                $placement->id,
                app(EmailProviderMessageIdentity::class)->forMessage($message),
                EmailPlacementCreateResult::CREATED_PENDING,
                1,
            );
        };
        app(EmailProviderReconciliationImporter::class)->importOne($item, $reader, $store);

        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame('nomodseq_baseline_pending', $folderRun->fresh()->reason_code);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame('nomodseq_verification_pending', $folderRun->fresh()->reason_code);
        $observation = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->firstOrFail();
        $this->assertTrue($observation->provider_seen);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT, $observation->status);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertTrue($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame(
            EmailProviderReconciliationFolder::METADATA_VERIFICATION_COMPLETED,
            $folderRun->fresh()->metadata_verification_status,
        );

        $this->assertCount(1, $store->calls);
        $this->assertTrue($store->calls[0]['runInboundRules']);
        $this->assertArrayNotHasKey('allowProviderMutation', $store->calls[0]);
        $this->assertArrayNotHasKey('allow_provider_mutation', $store->calls[0]);
        $this->assertSame(
            ['runId', 'itemId', 'claimAttempt', 'accountId', 'folderId', 'uidNamespaceId', 'uidValidity', 'uid', 'peeked', 'runInboundRules'],
            array_map(
                fn ($parameter): string => $parameter->getName(),
                (new ReflectionMethod(EmailProviderReconciliationMessageStore::class, 'store'))->getParameters(),
            ),
        );
        $item = $item->fresh();
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $item->status);
        $this->assertTrue($item->automation_required);
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
            $item->automation_status,
        );
        Queue::assertNotPushed(
            ProcessEmailProviderReconciliationAutomation::class,
        );

        // Account-wide stable end evidence and the NOMODSEQ verification pass
        // must complete before correlation can expose this as runnable work.
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        foreach (range(1, 20) as $_) {
            $finalizer->finalizeOneStep($run->fresh(), $reader);
            if ($item->fresh()->automation_status
                === EmailProviderReconciliationItem::AUTOMATION_PENDING) {
                break;
            }
        }
        $item->refresh();
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_PENDING, $item->automation_status);
        $this->assertNotNull($folderRun->fresh()->end_uid_validity);
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId === $item->id
                && ! array_key_exists('allowProviderMutation', get_object_vars($job)),
        );

        // Simulate a lost worker after it acquired the token. It may already
        // have produced an AI/Ticket/Signal/notification side effect, so the
        // bounded orphan sweep wakes a worker only to attest a visible failure;
        // it must never replay the local automation blindly.
        $item->forceFill([
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
            'automation_claim_token' => hash('sha256', 'lost-worker'),
            'automation_attempt_count' => 1,
            'automation_last_attempt_at' => now()->subSeconds(
                ProcessEmailProviderReconciliationAutomation::ABANDONED_CLAIM_SECONDS + 1,
            ),
            'automation_rule_attempt_floor_id' => 0,
        ])->save();
        app(UniqueLock::class)->release(
            new ProcessEmailProviderReconciliationAutomation($item->id),
        );
        Queue::fake();
        (new FinalizeEmailProviderReconciliation($run->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            $reader,
        );
        Queue::assertPushed(
            ProcessEmailProviderReconciliationAutomation::class,
            fn (ProcessEmailProviderReconciliationAutomation $job): bool => $job->itemId === $item->id,
        );

        (new ProcessEmailProviderReconciliationAutomation($item->id))->handle();
        $item = $item->fresh();
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_FAILED, $item->automation_status);
        $this->assertSame('provider_reconciliation_automation_worker_lost', $item->automation_error_code);
        $this->assertSame(1, $item->automation_attempt_count);
        $this->assertNull($item->automation_claim_token);
        (new ProcessEmailProviderReconciliationAutomation($item->id))->handle();
        $this->assertSame(1, $item->fresh()->automation_attempt_count);

        $this->finish($run, $reader);

        $imported = EmailMailboxPlacement::query()->where('imap_uid', 500)->firstOrFail();
        $this->assertTrue($imported->provider_seen);
        $this->assertTrue($imported->provider_flagged);
        $this->assertSame(['\\Seen', '\\Flagged', 'customer'], $imported->flags_json);
        $this->assertCount(1, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'peek',
        ));
        $this->assertTrue($observation->fresh()->provider_seen);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_PARTIAL, $run->fresh()->status);
    }

    #[Test]
    public function prior_run_store_pending_occurrence_repairs_through_scanner_and_finalizer_without_snapshot_drift(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-cross-run-store@example.test',
            EmailFolder::ROLE_CUSTOM,
            923,
            43,
        );
        [$message, $placement] = $this->messageAndPlacement(
            $account,
            $folder,
            $namespace,
            42,
        );
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::STORE_PENDING_CODE,
            'last_provider_reconciliation_run_id' => null,
            'last_provider_observed_sync_version' => null,
            'last_provider_observed_at' => null,
        ])->save();
        $this->assertNotNull(
            app(EmailConversationProjector::class)->assignPendingPlacement($placement),
        );

        $run = $this->reconciliationRun($account);
        $state = new EmailProviderReconciliationFolderState(923, 43, 1, true, 100);
        $reader = $this->reader($folder, [$state, $state]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    uid: 42,
                    modseq: 99,
                    seen: false,
                    answered: false,
                    flagged: false,
                    deleted: false,
                    draft: false,
                ),
            ], completeThroughUid: 42),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 42),
        ];
        $providerMessage = Message::fromString(implode("\r\n", [
            'Message-ID: '.$message->message_id,
            'Subject: '.$message->subject,
            'From: '.$message->from_email,
            '',
            'Detached provider content.',
        ]));
        $providerMessage->setUid(42)->setFolderPath($folder->path);
        $reader->messages[$folder->path.':42'] = new EmailProviderReconciliationPeekedMessage(
            ['imap_uid' => 42],
            $providerMessage,
        );

        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $importId = null;
        foreach (range(1, 10) as $_) {
            $page = $scanner->scanOnePage($folderRun->fresh(), $reader);
            $importId = $page['import_item_ids'][0] ?? $importId;
            if ($importId !== null) {
                break;
            }
        }
        $this->assertNotNull($importId);
        $item = EmailProviderReconciliationItem::query()->findOrFail($importId);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = fn (): EmailProviderReconciliationStoredMessage => new EmailProviderReconciliationStoredMessage(
            $message->id,
            $placement->id,
            app(EmailProviderMessageIdentity::class)->forMessage($message),
            EmailPlacementCreateResult::RESUMED_PENDING,
            1,
        );

        $this->assertSame(
            EmailProviderReconciliationItem::STATUS_PROJECTED,
            app(EmailProviderReconciliationImporter::class)->importOne($item, $reader, $store),
        );
        $finished = false;
        foreach (range(1, 10) as $_) {
            $finished = $scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished'];
            if ($finished) {
                break;
            }
        }
        $this->assertTrue($finished);
        $this->finish($run, $reader);

        $folderRun->refresh();
        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_COMPLETE,
            $folderRun->status,
        );
        $this->assertSame($folderRun->placement_baseline_hash, $folderRun->placement_scan_hash);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_SYNCED, $placement->fresh()->sync_status);
        $this->assertNull($placement->fresh()->sync_error_code);
        $this->assertSame($run->id, $placement->fresh()->last_provider_reconciliation_run_id);
        $this->assertSame(2, $placement->fresh()->last_provider_observed_sync_version);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertFalse($item->fresh()->automation_required);
    }

    #[Test]
    public function stable_flag_evidence_completes_or_stales_local_operations_without_provider_replay(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-flag-op@example.test', EmailFolder::ROLE_INBOX, 911, 10);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 9, providerSeen: true);
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'reconcile-flag-op-'.$account->id,
            'source_folder_path' => $folder->path,
            'expected_placement_sync_version' => $placement->sync_version,
            'expected_provider_uid' => $placement->imap_uid,
            'expected_uid_validity' => $placement->imap_uid_validity,
        ]);
        $run = $this->reconciliationRun($account);
        $reader = $this->reader($folder, [
            new EmailProviderReconciliationFolderState(911, 10, 1, false, null),
            new EmailProviderReconciliationFolderState(911, 10, 1, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            $inventoryPage = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(9, null, false, false, false, false, false),
            ], completeThroughUid: 9),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 9),
            $inventoryPage,
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 9),
        ];

        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->assertTrue($placement->fresh()->provider_seen, 'Optimistic state is not overwritten mid-cycle.');
        $observation = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->firstOrFail();
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PENDING, $observation->status);
        $this->assertNull($observation->completed_at);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertFalse($placement->fresh()->provider_seen);
        $this->assertNotNull($observation->fresh()->completed_at);
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $operation->fresh()->status);
        $this->assertSame(EmailRemoteOperation::FAILURE_STALE, $operation->fresh()->failure_classification);
        $this->assertSame('PROVIDER_RECONCILIATION_STALE', $operation->fresh()->status_reason_code);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertCount(0, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'peek',
        ));
    }

    #[Test]
    public function ambiguous_move_operation_keeps_a_stably_absent_source_visible_as_a_conflict(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-move-conflict@example.test', EmailFolder::ROLE_INBOX, 912, 1);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 42);
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'move',
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'idempotency_key' => 'reconcile-move-conflict-'.$account->id,
            'source_folder_path' => $folder->path,
            'target_folder_path' => 'Archive',
            'expected_placement_sync_version' => $placement->sync_version,
            'expected_provider_uid' => $placement->imap_uid,
            'expected_uid_validity' => $placement->imap_uid_validity,
            // Legacy/tampered JSON is never authoritative target evidence.
            'provider_response_json' => [
                'target_imap_uid' => 42,
                'target_uid_validity' => 999999,
            ],
        ]);
        $run = $this->reconciliationRun($account);
        $reader = $this->reader($folder, [
            new EmailProviderReconciliationFolderState(912, 1, 0, false, null),
            new EmailProviderReconciliationFolderState(912, 1, 0, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
        ];

        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $operation->fresh()->status);
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'email_provider_reconciliation_run_id' => $run->id,
            'source_placement_id' => $placement->id,
            'email_remote_operation_id' => $operation->id,
            'kind' => EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
            'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
            'error_code' => 'provider_absence_operation_ambiguous',
        ]);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS, $run->fresh()->status);
    }

    #[Test]
    public function move_operation_reconciliation_requires_the_authoritative_target_tuple_in_the_active_namespace(): void
    {
        [$account, $sourceFolder, $sourceNamespace] = $this->mailbox(
            'reconcile-authoritative-move@example.test',
            EmailFolder::ROLE_INBOX,
            922,
            43,
        );
        [, $sourcePlacement] = $this->messageAndPlacement(
            $account,
            $sourceFolder,
            $sourceNamespace,
            42,
        );
        $targetFolder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'Archive',
            'name' => 'Archive',
            'delimiter' => '/',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 923,
            'uid_next' => 8,
            'live_start_uid' => 7,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
        ]);
        $targetNamespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $targetFolder->id,
            'generation' => 1,
            'uid_validity' => 923,
            'uid_next_at_establishment' => 8,
            'live_start_uid' => 7,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $targetFolder->forceFill(['active_uid_namespace_id' => $targetNamespace->id])->save();
        [, $targetPlacement] = $this->messageAndPlacement(
            $account,
            $targetFolder->fresh(),
            $targetNamespace,
            7,
        );

        $run = $this->reconciliationRun($account);
        $sourceFolderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $sourceFolder->id,
            'uid_namespace_id' => $sourceNamespace->id,
            'folder_path' => $sourceFolder->path,
            'folder_name' => $sourceFolder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => 922,
            'start_uid_validity' => 922,
            'end_uid_validity' => 922,
            'reason_code' => 'stable_end_validated',
        ]);
        EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $targetFolder->id,
            'uid_namespace_id' => $targetNamespace->id,
            'folder_path' => $targetFolder->path,
            'folder_name' => $targetFolder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => 923,
            'start_uid_validity' => 923,
            'end_uid_validity' => 923,
            'reason_code' => 'stable_end_validated',
        ]);
        $targetPlacement->forceFill([
            'last_provider_reconciliation_run_id' => $run->id,
        ])->save();
        $absence = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $sourceFolderRun->id,
            'uid_namespace_id' => $sourceNamespace->id,
            'imap_uid' => $sourcePlacement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            'source_placement_id' => $sourcePlacement->id,
            'placement_sync_version_before' => $sourcePlacement->sync_version,
        ]);

        $operationValues = [
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $sourceFolder->id,
            'email_mailbox_placement_id' => $sourcePlacement->id,
            'provider' => 'imap',
            'operation_type' => 'move',
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'source_folder_path' => $sourceFolder->path,
            'target_folder_path' => $targetFolder->path,
            'expected_placement_sync_version' => $sourcePlacement->sync_version,
            'expected_provider_uid' => $sourcePlacement->imap_uid,
            'expected_uid_validity' => $sourcePlacement->imap_uid_validity,
        ];
        $forged = EmailRemoteOperation::query()->create($operationValues + [
            'idempotency_key' => 'reconcile-forged-target-'.$account->id,
            'provider_response_json' => [
                'target_imap_uid' => $targetPlacement->imap_uid,
                'target_uid_validity' => $targetPlacement->imap_uid_validity,
            ],
        ]);
        $observer = app(EmailProviderRemoteOperationObserver::class);

        $this->assertNull($observer->reconcileStableSourceAbsence($absence));
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $forged->fresh()->status);
        $this->assertNull($absence->fresh()->target_placement_id);

        // Terminalize the deliberately forged operation before creating a
        // second operation for the same source. Production serializes behind
        // the oldest unresolved operation and must not skip it.
        $forged->forceFill([
            'status' => EmailRemoteOperation::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'next_attempt_at' => null,
            'status_reason_code' => 'REMOTE_OPERATION_CANCELLED',
            'status_reason_message' => 'Test fixture cancellation after forged evidence was rejected.',
        ])->save();

        $authoritative = EmailRemoteOperation::query()->create($operationValues + [
            'idempotency_key' => 'reconcile-authoritative-target-'.$account->id,
            'acknowledged_target_uid_validity' => 923,
            'acknowledged_target_uid' => 7,
            // A conflicting JSON value cannot override first-class evidence.
            'provider_response_json' => ['target_imap_uid' => 7000],
        ]);

        $this->assertSame(
            $targetPlacement->id,
            $observer->reconcileStableSourceAbsence($absence->fresh()),
        );
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $authoritative->fresh()->status);
        $this->assertSame($targetPlacement->id, $absence->fresh()->target_placement_id);

        // A later UIDVALIDITY reset may reuse the acknowledged UID for a
        // different message. The old tuple must remain ambiguous even while
        // the stale placement and forged response JSON are still present.
        $targetNamespace->forceFill([
            'status' => EmailFolderUidNamespace::STATUS_SUPERSEDED,
            'superseded_at' => now(),
        ])->save();
        $replacementNamespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $targetFolder->id,
            'generation' => 2,
            'uid_validity' => 924,
            'uid_next_at_establishment' => 8,
            'live_start_uid' => 7,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_reset',
            'established_at' => now(),
        ]);
        $targetFolder->forceFill([
            'uid_validity' => 924,
            'active_uid_namespace_id' => $replacementNamespace->id,
        ])->save();
        [, $replacementPlacement] = $this->messageAndPlacement(
            $account,
            $targetFolder->fresh(),
            $replacementNamespace,
            7,
        );
        $replacementPlacement->forceFill([
            'last_provider_reconciliation_run_id' => $run->id,
        ])->save();
        $resetOperation = EmailRemoteOperation::query()->create($operationValues + [
            'idempotency_key' => 'reconcile-reset-target-'.$account->id,
            'acknowledged_target_uid_validity' => 923,
            'acknowledged_target_uid' => 7,
            'provider_response_json' => [
                'target_imap_uid' => 7,
                'target_uid_validity' => 924,
            ],
        ]);

        $this->assertNull($observer->reconcileStableSourceAbsence($absence->fresh()));
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $resetOperation->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $sourcePlacement->fresh()->local_state);
    }

    #[Test]
    public function an_import_cancelled_before_claim_performs_no_provider_read_or_local_store(): void
    {
        [$account, $folder] = $this->mailbox('reconcile-import-cancel@example.test', EmailFolder::ROLE_INBOX, 914, 51);
        $this->completedBaseline($account);
        $run = $this->reconciliationRun($account);
        $reader = $this->reader($folder, [
            new EmailProviderReconciliationFolderState(914, 51, 1, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(50, null, false, false, false, false, false),
            ], completeThroughUid: 50),
        ];
        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $page = $scanner->scanOnePage($folderRun->fresh(), $reader);
        $item = EmailProviderReconciliationItem::query()->findOrFail($page['import_item_ids'][0]);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_CANCELLING,
            'cancellation_requested_at' => now(),
        ])->save();
        $store = new FakeEmailProviderReconciliationMessageStore;

        $status = app(EmailProviderReconciliationImporter::class)->importOne($item, $reader, $store);

        $this->assertSame(EmailProviderReconciliationItem::STATUS_PENDING, $status);
        $this->finish($run, $reader);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_CANCELLED, $item->fresh()->status);
        $this->assertSame([], $store->calls);
        $this->assertCount(0, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'peek',
        ));
    }

    #[Test]
    public function operation_evidence_with_a_wrong_immutable_uid_remains_a_visible_conflict(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-operation-scope@example.test', EmailFolder::ROLE_INBOX, 915, 10);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 9, providerSeen: true);
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'reconcile-wrong-uid-op-'.$account->id,
            'source_folder_path' => $folder->path,
            'expected_placement_sync_version' => $placement->sync_version,
            'expected_provider_uid' => 8,
            'expected_uid_validity' => $placement->imap_uid_validity,
        ]);
        $run = $this->reconciliationRun($account);
        $reader = $this->reader($folder, [
            new EmailProviderReconciliationFolderState(915, 10, 1, false, null),
            new EmailProviderReconciliationFolderState(915, 10, 1, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            $inventoryPage = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(9, null, false, false, false, false, false),
            ], completeThroughUid: 9),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 9),
            $inventoryPage,
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 9),
        ];
        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $operation->fresh()->status);
        $this->assertFalse($placement->fresh()->provider_seen);
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'email_provider_reconciliation_run_id' => $run->id,
            'email_remote_operation_id' => $operation->id,
            'kind' => EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
            'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
            'error_code' => 'provider_operation_ambiguous',
        ]);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS, $run->fresh()->status);
    }

    #[Test]
    public function finalization_recovers_undispatched_and_abandoned_import_claims_after_worker_death(): void
    {
        [$account, $folder] = $this->mailbox('reconcile-import-recovery@example.test', EmailFolder::ROLE_INBOX, 916, 61);
        $this->completedBaseline($account);
        $run = $this->reconciliationRun($account);
        $reader = $this->reader($folder, [
            new EmailProviderReconciliationFolderState(916, 61, 1, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(60, null, false, false, false, false, false),
            ], completeThroughUid: 60),
        ];
        $folderRun = $this->discoverOne($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $page = $scanner->scanOnePage($folderRun->fresh(), $reader);
        $item = EmailProviderReconciliationItem::query()->findOrFail($page['import_item_ids'][0]);

        Queue::fake();
        (new FinalizeEmailProviderReconciliation($run->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            $reader,
        );
        Queue::assertPushed(
            ImportEmailProviderReconciliationItem::class,
            fn (ImportEmailProviderReconciliationItem $job): bool => $job->itemId === $item->id,
        );

        // Queue::fake records recovery jobs without running the normal
        // ShouldBeUniqueUntilProcessing lock release. Simulate workers
        // crossing that boundary before invoking the import handle directly.
        app(UniqueLock::class)->release(new ImportEmailProviderReconciliationItem($item->id));
        app(UniqueLock::class)->release(new FinalizeEmailProviderReconciliation($run->id));

        Queue::fake();
        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
            'attempt_count' => 1,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
        ])->save();
        $store = new FakeEmailProviderReconciliationMessageStore;
        (new ImportEmailProviderReconciliationItem($item->id))->handle(
            app(EmailProviderReconciliationImporter::class),
            $reader,
            $store,
        );

        Queue::assertPushed(
            ImportEmailProviderReconciliationItem::class,
            fn (ImportEmailProviderReconciliationItem $job): bool => $job->itemId === $item->id,
        );
        Queue::assertPushed(
            FinalizeEmailProviderReconciliation::class,
            fn (FinalizeEmailProviderReconciliation $job): bool => $job->runId === $run->id,
        );
        $this->assertSame([], $store->calls);
        $this->assertCount(0, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'peek',
        ));
    }

    #[Test]
    public function finalizer_recovery_branches_are_fair_bounded_and_exclude_recent_claims(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-recovery-bounds@example.test',
            EmailFolder::ROLE_INBOX,
            930,
            10,
        );
        $run = $this->reconciliationRun($account);
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => $namespace->uid_validity,
        ]);
        $uid = 1000;
        $create = function (array $overrides = []) use ($folderRun, $namespace, $run, &$uid): EmailProviderReconciliationItem {
            return EmailProviderReconciliationItem::query()->create(array_merge([
                'email_provider_reconciliation_run_id' => $run->id,
                'email_provider_reconciliation_folder_id' => $folderRun->id,
                'uid_namespace_id' => $namespace->id,
                'imap_uid' => $uid++,
                'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
                'status' => EmailProviderReconciliationItem::STATUS_PENDING,
            ], $overrides));
        };
        $invoke = static function (
            FinalizeEmailProviderReconciliation $job,
            string $method,
            EmailProviderReconciliationRun $run,
        ): void {
            $reflection = new ReflectionMethod($job, $method);
            $reflection->invoke($job, $run);
        };
        $job = new FinalizeEmailProviderReconciliation((int) $run->id);
        $staleAt = now()->subMinutes(3);

        $pendingImports = collect(range(1, 105))->map(fn (): int => (int) $create()->id);
        $nullAttemptImport = $create([
            'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
            'attempt_count' => 1,
            'first_attempt_at' => $staleAt,
        ]);
        $staleImport = $create([
            'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
            'attempt_count' => 1,
            'first_attempt_at' => $staleAt,
            'last_attempt_at' => $staleAt,
        ]);
        $recentImport = $create([
            'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
            'attempt_count' => 1,
            'first_attempt_at' => now(),
            'last_attempt_at' => now(),
        ]);

        Queue::fake();
        $invoke($job, 'dispatchImportRecovery', $run);
        $importJobs = Queue::pushed(ImportEmailProviderReconciliationItem::class);
        $importIds = $importJobs->map(fn (ImportEmailProviderReconciliationItem $queued): int => $queued->itemId);
        $this->assertCount(100, $importIds);
        $this->assertCount(100, $importIds->unique());
        $this->assertContains($nullAttemptImport->id, $importIds);
        $this->assertContains($staleImport->id, $importIds);
        $this->assertNotContains($recentImport->id, $importIds);
        $this->assertSame(98, $importIds->intersect($pendingImports)->count());

        $pendingAutomation = collect(range(1, 105))->map(fn (): int => (int) $create([
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'completed_at' => $staleAt,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_PENDING,
        ])->id);
        $awaitingAutomation = $create([
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'completed_at' => $staleAt,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
            'automation_claim_token' => hash('sha256', 'bounded-awaiting-fanout'),
            'automation_attempt_count' => 1,
            'automation_last_attempt_at' => $staleAt,
            'automation_rule_attempt_floor_id' => 0,
        ]);
        $staleAutomation = $create([
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'completed_at' => $staleAt,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
            'automation_claim_token' => hash('sha256', 'bounded-stale-automation'),
            'automation_attempt_count' => 1,
            'automation_last_attempt_at' => $staleAt,
            'automation_rule_attempt_floor_id' => 0,
        ]);
        $recentAutomation = $create([
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'completed_at' => now(),
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
            'automation_claim_token' => hash('sha256', 'bounded-recent-automation'),
            'automation_attempt_count' => 1,
            'automation_last_attempt_at' => now(),
            'automation_rule_attempt_floor_id' => 0,
        ]);

        Queue::fake();
        $invoke($job, 'dispatchAutomationRecovery', $run);
        $automationJobs = Queue::pushed(ProcessEmailProviderReconciliationAutomation::class);
        $automationIds = $automationJobs->map(
            fn (ProcessEmailProviderReconciliationAutomation $queued): int => $queued->itemId,
        );
        $this->assertCount(100, $automationIds);
        $this->assertCount(100, $automationIds->unique());
        $this->assertContains($awaitingAutomation->id, $automationIds);
        $this->assertContains($staleAutomation->id, $automationIds);
        $this->assertNotContains($recentAutomation->id, $automationIds);
        $this->assertSame(98, $automationIds->intersect($pendingAutomation)->count());

        $pendingBaselines = collect(range(1, 105))->map(fn (): int => (int) $create([
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_frozen_at' => $staleAt,
        ])->id);
        $staleBaseline = $create([
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
            'historical_baseline_claim_token' => hash('sha256', 'bounded-stale-baseline'),
            'historical_baseline_attempt_count' => 1,
            'historical_baseline_frozen_at' => $staleAt,
            'historical_baseline_first_attempt_at' => $staleAt,
            'historical_baseline_last_attempt_at' => $staleAt,
        ]);
        $recentBaseline = $create([
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
            'historical_baseline_claim_token' => hash('sha256', 'bounded-recent-baseline'),
            'historical_baseline_attempt_count' => 1,
            'historical_baseline_frozen_at' => now(),
            'historical_baseline_first_attempt_at' => now(),
            'historical_baseline_last_attempt_at' => now(),
        ]);

        Queue::fake();
        $invoke($job, 'dispatchHistoricalBaselineRecovery', $run);
        $baselineJobs = Queue::pushed(ProjectEmailProviderHistoricalReadBaseline::class);
        $baselineIds = $baselineJobs->map(
            fn (ProjectEmailProviderHistoricalReadBaseline $queued): int => $queued->itemId,
        );
        $this->assertCount(100, $baselineIds);
        $this->assertCount(100, $baselineIds->unique());
        $this->assertContains($staleBaseline->id, $baselineIds);
        $this->assertNotContains($recentBaseline->id, $baselineIds);
        $this->assertSame(99, $baselineIds->intersect($pendingBaselines)->count());
    }

    #[Test]
    public function abandoned_automation_claims_never_replay_or_attest_complete(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-rule-attempt-barrier@example.test',
            EmailFolder::ROLE_INBOX,
            917,
            70,
        );
        $run = $this->reconciliationRun($account);
        $scopeHash = hash('sha256', 'rule-attempt-scope');
        $snapshotAt = now()->subMinute();
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
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
            'folder_count' => 1,
        ])->save();
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'reason_code' => 'stable_end_validated',
        ]);

        Queue::fake();
        foreach ([
            EmailRuleExecutionAttempt::STATUS_RUNNING,
            EmailRuleExecutionAttempt::STATUS_FAILED,
        ] as $attemptStatus) {
            $uid = $attemptStatus === EmailRuleExecutionAttempt::STATUS_RUNNING ? 68 : 69;
            [$message, $placement] = $this->messageAndPlacement(
                $account,
                $folder,
                $namespace,
                $uid,
            );
            $item = EmailProviderReconciliationItem::query()->create([
                'email_provider_reconciliation_run_id' => $run->id,
                'email_provider_reconciliation_folder_id' => $folderRun->id,
                'uid_namespace_id' => $namespace->id,
                'imap_uid' => $uid,
                'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
                'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
                'result_placement_id' => $placement->id,
                'automation_required' => true,
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                'automation_claim_token' => hash('sha256', 'lost-rule-worker-'.$uid),
                'automation_attempt_count' => 1,
                'automation_last_attempt_at' => now()->subSeconds(
                    ProcessEmailProviderReconciliationAutomation::ABANDONED_CLAIM_SECONDS + 1,
                ),
                'automation_rule_attempt_floor_id' => 0,
                'completed_at' => now()->subMinute(),
            ]);
            EmailRuleExecutionAttempt::query()->create([
                'email_message_id' => $message->id,
                'email_mailbox_placement_id' => $placement->id,
                'routing_phase' => 'normal',
                'status' => $attemptStatus,
                'idempotency_key' => hash('sha256', 'reconciliation-attempt-'.$uid),
                'matched' => true,
                'stop_processing' => false,
                'conditions_json' => [],
                'actions_json' => [
                    ['type' => 'tag_message', 'value' => 'canary'],
                    ['type' => 'create_ticket', 'value' => 'canary'],
                ],
                'started_at' => now()->subMinute(),
                'finished_at' => $attemptStatus === EmailRuleExecutionAttempt::STATUS_FAILED
                    ? now()->subMinute()
                    : null,
            ]);

            (new ProcessEmailProviderReconciliationAutomation($item->id))->handle();

            $this->assertSame(
                EmailProviderReconciliationItem::AUTOMATION_FAILED,
                $item->fresh()->automation_status,
            );
            $this->assertSame(
                'provider_reconciliation_automation_worker_lost',
                $item->fresh()->automation_error_code,
            );
            $this->assertSame(1, $item->fresh()->automation_attempt_count);
        }

        $throughId = (int) $run->items()->max('id');
        $summaryAt = now();
        $folderRun->forceFill([
            'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            'item_summary_through_id' => $throughId,
            'item_summary_cursor_id' => $throughId,
            'item_summary_nonterminal' => false,
            'item_summary_batch_count' => 1,
            'item_summary_started_at' => $summaryAt,
            'item_summary_completed_at' => $summaryAt,
            'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
            'reason_code' => null,
            'finished_at' => $summaryAt,
        ])->save();

        $this->finish($run, new FakeEmailProviderReconciliationReader);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_PARTIAL, $run->fresh()->status);
    }

    #[Test]
    public function post_claim_throwable_is_severed_and_terminal_without_replay(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-automation-throw@example.test',
            EmailFolder::ROLE_INBOX,
            919,
            90,
        );
        [$message, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 89);
        $run = $this->reconciliationRun($account);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            'started_at' => now(),
        ])->save();
        $identity = app(EmailProviderMessageIdentity::class)->forMessage($message);
        $observedSyncVersion = (int) $placement->sync_version;
        $placement->forceFill([
            'last_provider_reconciliation_run_id' => $run->id,
            'last_provider_observed_sync_version' => $observedSyncVersion,
            'last_provider_observed_identity_hash' => $identity,
            'last_provider_observed_at' => now(),
        ])->save();
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => $namespace->uid_validity,
        ]);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $placement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'result_placement_id' => $placement->id,
            'identity_hash' => $identity,
            'placement_sync_version_before' => $observedSyncVersion,
            'placement_sync_version_after' => $observedSyncVersion,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_PENDING,
            'automation_attempt_count' => 0,
            'completed_at' => now(),
        ]);
        $summaryAt = now();
        $folderRun->forceFill([
            'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            'item_summary_through_id' => $item->id,
            'item_summary_cursor_id' => $item->id,
            'item_summary_missing_count' => 0,
            'item_summary_move_count' => 0,
            'item_summary_conflict_count' => 0,
            'item_summary_nonterminal' => false,
            'item_summary_batch_count' => 1,
            'item_summary_started_at' => $summaryAt,
            'item_summary_completed_at' => $summaryAt,
            'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
            'missing_count' => 0,
            'conflict_count' => 0,
            'reason_code' => null,
            'finished_at' => $summaryAt,
        ])->save();

        $canary = 'SUBJECT_BODY_SQLSTATE_PROVIDER_CANARY';
        $engine = Mockery::mock(InboundEmailRuleEngine::class);
        $engine->shouldReceive('allowsInboundAutomation')
            ->once()
            ->andThrow(new RuntimeException($canary));
        $this->app->instance(InboundEmailRuleEngine::class, $engine);
        Queue::fake();

        try {
            (new ProcessEmailProviderReconciliationAutomation($item->id))->handle();
            $this->fail('The post-claim automation fault was not severed.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_reconciliation_automation_failed', $exception->safeCode);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($canary, (string) $exception);
        }

        $item->refresh();
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_FAILED, $item->automation_status);
        $this->assertSame('provider_reconciliation_automation_failed', $item->automation_error_code);
        $this->assertSame(1, $item->automation_attempt_count);
        $this->assertNull($item->automation_claim_token);
        $this->assertNotNull($item->automation_completed_at);

        // Terminal evidence makes redelivery a no-op; the throwing action is
        // called exactly once and the attempt counter cannot advance.
        (new ProcessEmailProviderReconciliationAutomation($item->id))->handle();
        $this->assertSame(1, $item->fresh()->automation_attempt_count);
    }

    #[Test]
    public function cancellation_waits_for_a_fresh_token_owned_automation_claim(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-automation-cancel@example.test',
            EmailFolder::ROLE_INBOX,
            918,
            80,
        );
        [$message, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 79);
        $run = $this->reconciliationRun($account);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_CANCELLING,
            'cancellation_requested_at' => now(),
        ])->save();
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
        ]);
        $token = hash('sha256', 'fresh-automation-worker');
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $placement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'result_placement_id' => $placement->id,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
            'automation_claim_token' => $token,
            'automation_attempt_count' => 1,
            'automation_last_attempt_at' => now(),
            'automation_rule_attempt_floor_id' => 0,
            'completed_at' => now(),
        ]);

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $this->assertFalse($finalizer->finalizeOneStep(
            $run->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLING, $run->fresh()->status);
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_RUNNING, $item->fresh()->automation_status);
        $this->assertSame($token, $item->fresh()->automation_claim_token);

        EmailProviderReconciliationItem::query()
            ->whereKey($item->id)
            ->where('automation_claim_token', $token)
            ->update([
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_COMPLETED,
                'automation_claim_token' => null,
                'automation_completed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertFalse($finalizer->finalizeOneStep(
            $run->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_CANCELLED, $folderRun->fresh()->status);
        $this->assertTrue($finalizer->finalizeOneStep(
            $run->fresh(),
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_CANCELLED, $folderRun->fresh()->status);
        $this->assertSame(EmailProviderReconciliationItem::AUTOMATION_COMPLETED, $item->fresh()->automation_status);
        $this->assertSame($message->id, $placement->fresh()->email_message_id);
    }

    #[Test]
    public function historical_baseline_is_a_recoverable_visibility_barrier_and_cancellation_preserves_a_linearized_completion(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-history-barrier@example.test',
            EmailFolder::ROLE_CUSTOM,
            921,
            101,
        );
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 100);
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ])->save();
        $run = $this->reconciliationRun($account);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
        ])->save();
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
        ]);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $placement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'result_placement_id' => $placement->id,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => 0,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_frozen_at' => now(),
        ]);
        $reader = new FakeEmailProviderReconciliationReader;

        Queue::fake();
        (new FinalizeEmailProviderReconciliation($run->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            $reader,
        );
        Queue::assertPushed(
            ProjectEmailProviderHistoricalReadBaseline::class,
            fn (ProjectEmailProviderHistoricalReadBaseline $job): bool => $job->itemId === $item->id,
        );
        $this->assertSame(EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS, $run->fresh()->status);
        $this->assertNull($folderRun->fresh()->end_uid_validity);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);

        $token = hash('sha256', 'fresh-historical-baseline-worker');
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_CANCELLING,
            'cancellation_requested_at' => now(),
        ])->save();
        $item->forceFill([
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
            'historical_baseline_claim_token' => $token,
            'historical_baseline_attempt_count' => 1,
            'historical_baseline_first_attempt_at' => now(),
            'historical_baseline_last_attempt_at' => now(),
        ])->save();

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLING, $run->fresh()->status);
        $this->assertSame($token, $item->fresh()->historical_baseline_claim_token);

        // Model the narrow race where a bounded worker committed activation
        // before cancellation acquired the run lock. That completed work is
        // authoritative and remains visible; cancellation drains only work
        // that was still pending or running when its intent linearized.
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_error_code' => null,
        ])->save();
        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'completed_at' => now(),
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
            'historical_baseline_claim_token' => null,
            'historical_baseline_completed_at' => now(),
        ])->save();

        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_SYNCED, $placement->fresh()->sync_status);
        $this->assertNull($placement->fresh()->sync_error_code);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $item->fresh()->status);
        $this->assertSame(
            EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
            $item->fresh()->historical_baseline_status,
        );

        $this->assertTrue($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLED, $run->fresh()->status);
    }

    #[Test]
    public function finalizer_wakes_the_bounded_historical_baseline_job_before_visible_completion(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-history-complete@example.test',
            EmailFolder::ROLE_CUSTOM,
            924,
            2,
        );
        [$message, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 1);
        $run = $this->reconciliationRun($account);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
        ])->save();
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
            'last_provider_reconciliation_run_id' => $run->id,
            'last_provider_observed_sync_version' => 1,
            'last_provider_observed_identity_hash' => app(EmailProviderMessageIdentity::class)
                ->forMessage($message),
            'last_provider_observed_at' => now(),
        ])->save();
        $this->assertNotNull(
            app(EmailConversationProjector::class)->assignPendingPlacement($placement),
        );
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE,
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES,
            'expected_uid_validity' => $namespace->uid_validity,
        ]);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $placement->imap_uid,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'result_placement_id' => $placement->id,
            'identity_hash' => app(EmailProviderMessageIdentity::class)->forMessage($message),
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 1,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => 0,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_frozen_at' => now(),
        ]);
        Queue::fake();

        (new FinalizeEmailProviderReconciliation($run->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            new FakeEmailProviderReconciliationReader,
        );

        Queue::assertPushed(
            ProjectEmailProviderHistoricalReadBaseline::class,
            fn (ProjectEmailProviderHistoricalReadBaseline $job): bool => $job->itemId === $item->id,
        );
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        (new ProjectEmailProviderHistoricalReadBaseline($item->id))->handle(
            app(ProjectHistoricalEmailReadBaseline::class),
        );
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $item->fresh()->status);
        $this->assertSame(
            EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
            $item->fresh()->historical_baseline_status,
        );
        $this->assertFalse($item->fresh()->automation_required);

        $scopeHash = hash('sha256', 'historical-complete:'.$run->id);
        $snapshotAt = now();
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
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
            'folder_count' => 1,
        ])->save();
        $summaryAt = now();
        $folderRun->forceFill([
            'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            'item_summary_through_id' => $item->id,
            'item_summary_cursor_id' => $item->id,
            'item_summary_nonterminal' => false,
            'item_summary_batch_count' => 1,
            'item_summary_started_at' => $summaryAt,
            'item_summary_completed_at' => $summaryAt,
            'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
            'finished_at' => $summaryAt,
        ])->save();

        $this->finish($run, new FakeEmailProviderReconciliationReader);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    #[Test]
    public function hidden_reconciliation_placement_cannot_run_any_inbound_automation(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-hidden-rules@example.test',
            EmailFolder::ROLE_INBOX,
            922,
            12,
        );
        [$message, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 11);
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_PENDING,
            'sync_error_code' => EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE,
        ])->save();

        $ruleEngine = Mockery::mock(InboundEmailRuleEngine::class);
        $classifier = Mockery::mock(InboundEmailSignalClassifier::class);
        $personalRules = Mockery::mock(PersonalEmailRuleEngine::class);
        $notifications = Mockery::mock(DispatchInboundEmailNotification::class);
        $ruleEngine->shouldNotReceive('allowsInboundAutomation');
        $classifier->shouldNotReceive('classifyAndRecord');
        $personalRules->shouldNotReceive('process');
        $notifications->shouldNotReceive('handle');

        (new ProcessInboundRules($message->id))->handle(
            $ruleEngine,
            $classifier,
            $personalRules,
            $notifications,
        );
    }

    #[Test]
    public function folder_reappearance_resets_the_two_separated_complete_absence_cycles(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-folder-absence@example.test', EmailFolder::ROLE_CUSTOM, 913, 100);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 55);
        $first = $this->reconciliationRun($account);
        $firstReader = new FakeEmailProviderReconciliationReader;
        $firstReader->folders = [];
        $this->assertSame([], app(EmailProviderReconciliationCoordinator::class)->discoverStart($first, $firstReader));
        $this->finish($first, $firstReader);

        $firstFolder = $first->folders()->firstOrFail();
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE, $firstFolder->status);
        $this->assertTrue($folder->fresh()->sync_enabled);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);

        $this->travel(301)->seconds();
        $present = $this->reconciliationRun($account);
        $presentReader = $this->reader($folder, [
            new EmailProviderReconciliationFolderState(913, 100, 1, false, null),
            new EmailProviderReconciliationFolderState(913, 100, 1, false, null),
        ]);
        $presentReader->metadataPages[$folder->path] = [
            $presentInventory = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(55, null, false, false, false, false, false),
            ], completeThroughUid: 99),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 99),
            $presentInventory,
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 99),
        ];
        $presentFolder = $this->discoverOne($present, $presentReader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($presentFolder, $presentReader);
        $scanner->scanOnePage($presentFolder->fresh(), $presentReader);
        $scanner->scanOnePage($presentFolder->fresh(), $presentReader);
        $scanner->scanOnePage($presentFolder->fresh(), $presentReader);
        $scanner->scanOnePage($presentFolder->fresh(), $presentReader);
        $this->finish($present, $presentReader);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_COMPLETE, $presentFolder->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);

        $this->travel(301)->seconds();
        $second = $this->reconciliationRun($account);
        $secondReader = new FakeEmailProviderReconciliationReader;
        $secondReader->folders = [];
        $this->assertSame([], app(EmailProviderReconciliationCoordinator::class)->discoverStart($second, $secondReader));
        $this->finish($second, $secondReader);

        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE,
            $second->folders()->firstOrFail()->status,
        );
        $this->assertTrue($folder->fresh()->sync_enabled);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);

        $this->travel(301)->seconds();
        $third = $this->reconciliationRun($account);
        $thirdReader = new FakeEmailProviderReconciliationReader;
        $thirdReader->folders = [];
        $this->assertSame([], app(EmailProviderReconciliationCoordinator::class)->discoverStart($third, $thirdReader));
        $this->finish($third, $thirdReader);

        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_MISSING_CONFIRMED,
            $third->folders()->firstOrFail()->status,
        );
        $this->assertFalse($folder->fresh()->sync_enabled);
        $this->assertFalse($folder->fresh()->is_selectable);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $third->fresh()->status);
        $this->travelBack();
    }

    private function finish(
        EmailProviderReconciliationRun $run,
        FakeEmailProviderReconciliationReader $reader,
    ): void {
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        foreach (range(1, 30) as $_) {
            if ($finalizer->finalizeOneStep($run->fresh(), $reader)) {
                return;
            }
        }

        $this->fail('The reconciliation workflow did not finish within bounded test steps.');
    }

    private function discoverOne(
        EmailProviderReconciliationRun $run,
        FakeEmailProviderReconciliationReader $reader,
    ): EmailProviderReconciliationFolder {
        $ids = app(EmailProviderReconciliationCoordinator::class)->discoverStart($run, $reader);
        $this->assertCount(1, $ids);

        return EmailProviderReconciliationFolder::query()->findOrFail($ids[0]);
    }

    /** @param array<int, EmailProviderReconciliationFolderState> $states */
    private function reader(EmailFolder $folder, array $states): FakeEmailProviderReconciliationReader
    {
        $reader = new FakeEmailProviderReconciliationReader;
        $reader->folders = [new EmailProviderReconciliationFolderDescriptor(
            path: $folder->path,
            name: $folder->name,
            delimiter: '/',
            specialUse: $folder->role === EmailFolder::ROLE_INBOX ? '\\Inbox' : null,
        )];
        $reader->folderStates[$folder->path] = $states;

        return $reader;
    }

    private function completedBaseline(EmailAccount $account): EmailProviderReconciliationRun
    {
        $run = $this->reconciliationRun($account, active: false);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_COMPLETED,
            'active_slot' => null,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ])->save();

        return $run;
    }

    private function reconciliationRun(
        EmailAccount $account,
        bool $active = true,
    ): EmailProviderReconciliationRun {
        return EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
            'active_slot' => $active ? 1 : null,
            'idempotency_key' => hash('sha256', 'workflow:'.$account->id.':'.microtime(true)),
            'provider_binding_version' => 1,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now(),
        ]);
    }

    /** @return array{EmailAccount, EmailFolder, EmailFolderUidNamespace} */
    private function mailbox(
        string $address,
        string $role,
        int $uidValidity,
        int $uidNext,
    ): array {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Provider Reconciliation',
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
        $path = $role === EmailFolder::ROLE_INBOX ? 'INBOX' : 'Projects';
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
            'uid_next' => $uidNext,
            'live_start_uid' => 1,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'uid_next_at_establishment' => $uidNext,
            'live_start_uid' => 1,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$account, $folder->refresh(), $namespace];
    }

    /** @return array{EmailMessage, EmailMailboxPlacement} */
    private function messageAndPlacement(
        EmailAccount $account,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $uid,
        bool $providerSeen = false,
    ): array {
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid' => $uid,
            'imap_uid_validity' => $namespace->uid_validity,
            'message_id' => "<workflow-{$account->id}-{$uid}@example.test>",
            'subject' => 'Reconciliation workflow',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subHour()->startOfSecond(),
            'size_bytes' => 4096,
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
            'provider_seen' => $providerSeen,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);

        return [$message, $placement];
    }
}
