<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\DTOs\EmailPlacementCreateResult;
use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\DTOs\EmailProviderReconciliationStoredMessage;
use App\Modules\Email\Jobs\ProcessEmailProviderReconciliationAutomation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationImporter;
use App\Modules\Email\Services\EmailProviderReconciliationPlacementProjector;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationMessageStore;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailProviderReconciliationImportActivationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function created_pending_live_inbox_activates_only_after_exact_store_attestation(): void
    {
        $scope = $this->scope();
        $reader = $this->reader($scope);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = function (array $arguments) use ($scope): EmailProviderReconciliationStoredMessage {
            [$message, $placement] = $this->pendingStoredOccurrence(
                $scope,
                $arguments['uid'],
                EmailProviderReconciliationStore::STORE_PENDING_CODE,
            );

            return new EmailProviderReconciliationStoredMessage(
                $message->id,
                $placement->id,
                app(EmailProviderMessageIdentity::class)->forMessage($message),
                EmailPlacementCreateResult::CREATED_PENDING,
                1,
            );
        };
        Queue::fake();

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $scope['item'],
            $reader,
            $store,
        );

        $item = $scope['item']->fresh();
        $placement = EmailMailboxPlacement::query()->findOrFail($item->result_placement_id);
        $conversation = $placement->conversation()->firstOrFail();
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $item->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_SYNCED, $placement->sync_status);
        $this->assertNull($placement->sync_error_code);
        $this->assertSame(1, $placement->sync_version);
        $this->assertSame($scope['run']->id, $placement->last_provider_reconciliation_run_id);
        $this->assertTrue($item->automation_required);
        $this->assertSame(
            EmailProviderReconciliationItem::AUTOMATION_AWAITING_CORRELATION,
            $item->automation_status,
        );
        $this->assertFalse($item->automationTerminal());
        $this->assertSame(1, $conversation->active_placement_count);
        $this->assertSame(1, $conversation->message_count);
        Queue::assertNotPushed(ProcessEmailProviderReconciliationAutomation::class);
        $this->assertSame(['binding', 'peek'], array_column($reader->calls, 'operation'));
    }

    #[Test]
    public function pending_version_drift_is_a_visible_conflict_and_never_publishes_the_placement(): void
    {
        $scope = $this->scope(address: 'activation-drift@example.test');
        $reader = $this->reader($scope);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = function (array $arguments) use ($scope): EmailProviderReconciliationStoredMessage {
            [$message, $placement] = $this->pendingStoredOccurrence(
                $scope,
                $arguments['uid'],
                EmailProviderReconciliationStore::STORE_PENDING_CODE,
            );
            $placement->forceFill(['sync_version' => 2])->save();

            return new EmailProviderReconciliationStoredMessage(
                $message->id,
                $placement->id,
                app(EmailProviderMessageIdentity::class)->forMessage($message),
                EmailPlacementCreateResult::CREATED_PENDING,
                1,
            );
        };

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $scope['item'],
            $reader,
            $store,
        );

        $item = $scope['item']->fresh();
        $placement = EmailMailboxPlacement::query()->findOrFail($item->result_placement_id);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_CONFLICT, $status);
        $this->assertSame('reconciliation_store_scope_drift', $item->error_code);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_PENDING, $placement->sync_status);
        $this->assertSame(EmailProviderReconciliationStore::STORE_PENDING_CODE, $placement->sync_error_code);
        $this->assertFalse($item->automation_required);
        $this->assertSame(0, $placement->conversation()->firstOrFail()->active_placement_count);
        $this->assertTrue($scope['run']->fresh()->automation_scope_unsafe);
        $this->assertSame(
            EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
            $scope['run']->fresh()->automation_scope_error_code,
        );
        $this->assertNotNull($scope['run']->fresh()->automation_scope_unsafe_at);
    }

    #[Test]
    public function exact_safe_preexisting_occurrence_converges_without_mutating_or_automating_it(): void
    {
        $scope = $this->scope(address: 'activation-preexisting@example.test');
        [$message, $placement] = $this->activeOccurrence($scope, $scope['item']->imap_uid);
        $before = $placement->getAttributes();
        $reader = $this->reader($scope);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = fn (array $arguments): EmailProviderReconciliationStoredMessage => new EmailProviderReconciliationStoredMessage(
            $message->id,
            $placement->id,
            app(EmailProviderMessageIdentity::class)->forMessage($message),
            EmailPlacementCreateResult::PREEXISTING,
            1,
        );

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $scope['item'],
            $reader,
            $store,
        );

        $this->assertSame(EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT, $status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT, $scope['item']->fresh()->status);
        $this->assertFalse($scope['item']->fresh()->automation_required);
        $this->assertSame($before, $placement->fresh()->getAttributes());
        $this->assertFalse($scope['run']->fresh()->automation_scope_unsafe);
    }

    #[Test]
    public function weak_preexisting_poll_race_materializes_account_wide_automation_barrier(): void
    {
        $scope = $this->scope(address: 'activation-preexisting-weak@example.test');
        [$message, $placement] = $this->activeOccurrence($scope, $scope['item']->imap_uid);
        $message->forceFill(['size_bytes' => 0])->save();
        $reader = $this->reader($scope);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = fn (array $arguments): EmailProviderReconciliationStoredMessage => new EmailProviderReconciliationStoredMessage(
            $message->id,
            $placement->id,
            null,
            EmailPlacementCreateResult::PREEXISTING,
            1,
        );

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $scope['item'],
            $reader,
            $store,
        );

        $this->assertSame(EmailProviderReconciliationItem::STATUS_ALREADY_PRESENT, $status);
        $this->assertTrue($scope['run']->fresh()->automation_scope_unsafe);
        $this->assertSame(
            EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
            $scope['run']->fresh()->automation_scope_error_code,
        );
        $this->assertNotNull($scope['run']->fresh()->automation_scope_unsafe_at);
    }

    #[Test]
    public function store_success_after_clock_advance_is_owned_by_attempt_generation(): void
    {
        $scope = $this->scope(address: 'activation-clock-success@example.test');
        $reader = $this->reader($scope);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = function (array $arguments) use ($scope): EmailProviderReconciliationStoredMessage {
            [$message, $placement] = $this->pendingStoredOccurrence(
                $scope,
                $arguments['uid'],
                EmailProviderReconciliationStore::STORE_PENDING_CODE,
            );
            $this->travel(2)->seconds();

            return new EmailProviderReconciliationStoredMessage(
                $message->id,
                $placement->id,
                app(EmailProviderMessageIdentity::class)->forMessage($message),
                EmailPlacementCreateResult::CREATED_PENDING,
                1,
            );
        };

        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $scope['item'],
            $reader,
            $store,
        );

        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $status);
        $this->assertSame(1, $scope['item']->fresh()->attempt_count);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $scope['item']->fresh()->status);
    }

    #[Test]
    public function store_exception_after_clock_advance_releases_the_same_attempt_to_pending(): void
    {
        $scope = $this->scope(address: 'activation-clock-failure@example.test');
        $reader = $this->reader($scope);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = function (): never {
            $this->travel(2)->seconds();

            throw new RuntimeException('fixture failure');
        };

        try {
            app(EmailProviderReconciliationImporter::class)->importOne(
                $scope['item'],
                $reader,
                $store,
            );
            $this->fail('The fixture Store exception must leave through the importer failure path.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture failure', $exception->getMessage());
        }

        $item = $scope['item']->fresh();
        $this->assertSame(1, $item->attempt_count);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PENDING, $item->status);
        $this->assertSame('provider_import_failed', $item->error_code);
        $this->assertNotNull($item->last_attempt_at);
    }

    #[Test]
    public function universal_pending_marker_forces_a_later_run_import_and_blocks_stable_projection(): void
    {
        $scope = $this->scope(address: 'activation-cross-run@example.test');
        [$message, $placement] = $this->pendingStoredOccurrence(
            $scope,
            $scope['item']->imap_uid,
            EmailProviderReconciliationStore::STORE_PENDING_CODE,
        );
        $placement->forceFill([
            'last_provider_reconciliation_run_id' => null,
            'last_provider_observed_at' => null,
        ])->save();
        $scope['item']->delete();
        $metadata = new EmailProviderReconciliationMessageMetadata(
            uid: (int) $placement->imap_uid,
            modseq: null,
            seen: true,
            answered: false,
            flagged: false,
            deleted: false,
            draft: false,
        );
        $scope['run']->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_SCAN,
        ])->save();
        $scope['folder_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_SCANNING,
            'reason_code' => null,
            'next_uid' => 42,
            'scan_through_uid' => 42,
        ])->save();

        $result = app(EmailProviderReconciliationPlacementProjector::class)->observe(
            $scope['folder_run'],
            $metadata,
            (int) $scope['folder_run']->next_uid - 1,
            (int) $scope['folder_run']->scan_through_uid,
        );

        $this->assertNotNull($result['import_item_id']);
        $observation = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $scope['run']->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->firstOrFail();
        $this->assertSame($placement->id, $observation->source_placement_id);
        $scope['run']->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
        ])->save();
        $scope['folder_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'reason_code' => 'stable_operation_projection',
        ])->save();
        $this->assertFalse(
            app(EmailProviderReconciliationPlacementProjector::class)
                ->applyStableObservation($scope['run'], $observation),
        );
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationStore::STORE_PENDING_CODE, $placement->fresh()->sync_error_code);
        $this->assertSame($message->id, $placement->fresh()->email_message_id);

        $scope['run']->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
        ])->save();
        $scope['folder_run']->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            'reason_code' => 'stable_end_validated',
            'next_uid' => 43,
        ])->save();
        $import = EmailProviderReconciliationItem::query()->findOrFail($result['import_item_id']);
        $store = new FakeEmailProviderReconciliationMessageStore;
        $store->callback = fn (array $arguments): EmailProviderReconciliationStoredMessage => new EmailProviderReconciliationStoredMessage(
            $message->id,
            $placement->id,
            app(EmailProviderMessageIdentity::class)->forMessage($message),
            EmailPlacementCreateResult::RESUMED_PENDING,
            1,
        );
        $status = app(EmailProviderReconciliationImporter::class)->importOne(
            $import,
            $this->reader($scope, $import),
            $store,
        );

        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $import->fresh()->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::SYNC_SYNCED, $placement->fresh()->sync_status);
        $this->assertNull($placement->fresh()->sync_error_code);
        $this->assertSame($placement->id, $observation->fresh()->source_placement_id);
    }

    /**
     * @return array{
     *   account:EmailAccount,
     *   folder:EmailFolder,
     *   namespace:EmailFolderUidNamespace,
     *   run:EmailProviderReconciliationRun,
     *   folder_run:EmailProviderReconciliationFolder,
     *   item:EmailProviderReconciliationItem
     * }
     */
    private function scope(string $address = 'activation@example.test'): array
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Reconciliation Activation',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
        ]);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'INBOX',
            'name' => 'INBOX',
            'delimiter' => '/',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 701,
            'uid_next' => 43,
            'live_start_uid' => 41,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => 701,
            'uid_next_at_establishment' => 43,
            'live_start_uid' => 41,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();
        $run = EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            'phase' => EmailProviderReconciliationRun::PHASE_IMPORTS,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'activation:'.$address),
            'provider_binding_version' => 1,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);
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
            'reason_code' => 'stable_end_validated',
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
            'expected_uid_validity' => $namespace->uid_validity,
            'start_uid_validity' => $namespace->uid_validity,
            'start_uid_next' => 43,
            'start_exists_count' => 1,
            'scan_through_uid' => 42,
            'next_uid' => 43,
        ]);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 42,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
        ]);

        return [
            'account' => $account,
            'folder' => $folder->refresh(),
            'namespace' => $namespace,
            'run' => $run,
            'folder_run' => $folderRun,
            'item' => $item,
        ];
    }

    private function reader(
        array $scope,
        ?EmailProviderReconciliationItem $item = null,
    ): FakeEmailProviderReconciliationReader {
        $item ??= $scope['item'];
        $reader = new FakeEmailProviderReconciliationReader;
        $message = Message::fromString(implode("\r\n", [
            'Message-ID: <activation@example.test>',
            'Subject: Activation boundary',
            'From: sender@example.test',
            '',
            'Detached provider content.',
        ]));
        $message->setUid((int) $item->imap_uid)
            ->setFolderPath((string) $scope['folder']->path);
        $reader->messages[$scope['folder']->path.':'.$item->imap_uid]
            = new EmailProviderReconciliationPeekedMessage([
                'imap_uid' => (int) $item->imap_uid,
            ], $message);

        return $reader;
    }

    /** @return array{EmailMessage,EmailMailboxPlacement} */
    private function pendingStoredOccurrence(array $scope, int $uid, string $marker): array
    {
        $message = $this->message($scope, $uid);
        $placement = $this->placement(
            $scope,
            $message,
            $uid,
            EmailMailboxPlacement::LOCAL_HIDDEN,
            EmailMailboxPlacement::SYNC_PENDING,
            $marker,
        );
        $this->assertNotNull(
            app(EmailConversationProjector::class)->assignPendingPlacement($placement),
        );

        return [$message, $placement->refresh()];
    }

    /** @return array{EmailMessage,EmailMailboxPlacement} */
    private function activeOccurrence(array $scope, int $uid): array
    {
        $message = $this->message($scope, $uid);
        $placement = $this->placement(
            $scope,
            $message,
            $uid,
            EmailMailboxPlacement::LOCAL_ACTIVE,
            EmailMailboxPlacement::SYNC_SYNCED,
            null,
        );
        app(EmailConversationProjector::class)->assignPlacement($placement);

        return [$message, $placement->refresh()];
    }

    private function message(array $scope, int $uid): EmailMessage
    {
        return EmailMessage::query()->create([
            'account_id' => $scope['account']->id,
            'mailbox' => $scope['folder']->path,
            'imap_uid_validity' => $scope['namespace']->uid_validity,
            'imap_uid' => $uid,
            'message_id' => '<activation-'.$scope['account']->id.'-'.$uid.'@example.test>',
            'subject' => 'Activation boundary',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subMinute()->startOfSecond(),
            'size_bytes' => 2048,
            'state' => 'untriaged',
        ]);
    }

    private function placement(
        array $scope,
        EmailMessage $message,
        int $uid,
        string $localState,
        string $syncStatus,
        ?string $errorCode,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $scope['account']->id,
            'email_folder_id' => $scope['folder']->id,
            'uid_namespace_id' => $scope['namespace']->id,
            'provider' => 'imap',
            'folder_path' => $scope['folder']->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $scope['namespace']->uid_validity,
            'imap_uid' => $uid,
            'provider_seen' => true,
            'flags_json' => ['\\Seen'],
            'local_state' => $localState,
            'sync_status' => $syncStatus,
            'sync_version' => 1,
            'sync_error_code' => $errorCode,
        ]);
    }
}
