<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\CancelEmailProviderReconciliation;
use App\Modules\Email\Actions\StartEmailProviderReconciliation;
use App\Modules\Email\Contracts\EmailProviderIdleHintReader;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\DTOs\EmailProviderReconciliationMetadataPage;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\Jobs\DispatchEmailProviderReconciliation;
use App\Modules\Email\Jobs\FinalizeEmailProviderReconciliation;
use App\Modules\Email\Jobs\ImportEmailProviderReconciliationItem;
use App\Modules\Email\Jobs\ListenForEmailProviderChanges;
use App\Modules\Email\Jobs\ReconcileEmailProviderAccount;
use App\Modules\Email\Jobs\ReconcileEmailProviderFolderBatch;
use App\Modules\Email\Jobs\TransitionEmailProviderReconciliationCancellation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Queries\EmailMailboxMaintenanceQuery;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationBindingPolicy;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Services\EmailProviderReconciliationCoordinator;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Services\EmailProviderReconciliationFingerprint;
use App\Modules\Email\Services\EmailProviderReconciliationFolderProjector;
use App\Modules\Email\Services\EmailProviderReconciliationImporter;
use App\Modules\Email\Services\EmailProviderReconciliationPlacementProjector;
use App\Modules\Email\Services\EmailProviderReconciliationPlacementSnapshot;
use App\Modules\Email\Services\EmailProviderReconciliationPolicy;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Services\EmailProviderReconciliationScanner;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationMessageStore;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationReader;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailProviderReconciliationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sparse_uid_inventory_is_resumable_and_reconciles_all_provider_flags_without_personal_state_or_body_reads(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-sparse@example.test', 800, 10_001);
        [$message, $placement] = $this->messageAndPlacement(
            $account,
            $folder,
            $namespace,
            9_123,
        );
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $personal = EmailMessageUserState::query()->create([
            'email_message_id' => $message->id,
            'user_id' => $actor->id,
            'last_opened_placement_id' => $placement->id,
            'is_unread' => true,
            'opened_count' => 4,
            'first_opened_at' => now()->subDay(),
            'last_opened_at' => now()->subHour(),
        ]);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(800, 10_001, 2, false, null),
            new EmailProviderReconciliationFolderState(800, 10_001, 2, false, null),
        ]);
        $inventory = [
            new EmailProviderReconciliationMessageMetadata(
                uid: 100,
                modseq: null,
                seen: false,
                answered: false,
                flagged: false,
                deleted: false,
                draft: false,
            ),
            new EmailProviderReconciliationMessageMetadata(
                uid: 9_123,
                modseq: null,
                seen: true,
                answered: true,
                flagged: true,
                deleted: true,
                draft: true,
                customFlags: ['Zeta', 'alpha', '\\Seen', 'ALPHA'],
            ),
        ];
        $inventoryPage = new EmailProviderReconciliationMetadataPage(
            $inventory,
            completeThroughUid: 10_000,
        );
        $terminalPage = new EmailProviderReconciliationMetadataPage(
            [],
            terminal: true,
            completeThroughUid: 10_000,
        );
        $reader->metadataPages[$folder->path] = [
            $inventoryPage,
            $terminalPage,
            $inventoryPage,
            $terminalPage,
        ];

        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $this->assertFalse($scanner->scanOnePage($folderRun, $reader)['folder_finished']);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertTrue($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->finish($run, $reader);

        $placement = $placement->fresh();
        $this->assertTrue($placement->provider_seen);
        $this->assertTrue($placement->provider_answered);
        $this->assertTrue($placement->provider_flagged);
        $this->assertTrue($placement->provider_deleted);
        $this->assertTrue($placement->provider_draft);
        $this->assertSame([
            '\\Seen',
            '\\Answered',
            '\\Flagged',
            '\\Deleted',
            '\\Draft',
            'alpha',
            'zeta',
        ], $placement->flags_json);
        $this->assertSame(2, $placement->sync_version);
        $this->assertSame($run->id, $placement->last_provider_reconciliation_run_id);
        $this->assertTrue($personal->fresh()->is_unread);
        $this->assertSame(4, $personal->fresh()->opened_count);
        $this->assertSame(0, EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->count(), 'The first baseline suppresses unknown historical imports.');
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'email_provider_reconciliation_run_id' => $run->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9_123,
            'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
            'provider_seen' => true,
            'provider_answered' => true,
            'provider_flagged' => true,
            'provider_deleted' => true,
            'provider_draft' => true,
            'placement_sync_version_before' => 1,
            'placement_sync_version_after' => 2,
        ]);
        $this->assertSame(['alpha', 'zeta'], EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('imap_uid', 9_123)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->firstOrFail()
            ->custom_flags_json);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame([], array_values(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'peek',
        )));
        $metadataCalls = array_values(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        ));
        $this->assertSame(0, $metadataCalls[0]['afterUid']);
        $this->assertSame(10_000, $metadataCalls[0]['throughUid']);
        $this->assertSame(10_000, $metadataCalls[1]['afterUid']);
        $this->assertSame(0, $metadataCalls[2]['afterUid']);
        $this->assertSame(10_000, $metadataCalls[3]['afterUid']);
    }

    #[Test]
    public function sparse_uid_windows_resume_above_one_million_and_timeout_never_advances_the_cursor(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-sparse-resume@example.test',
            809,
            1_020_001,
        );
        [, $placement] = $this->messageAndPlacement(
            $account,
            $folder,
            $namespace,
            1_010_005,
        );
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(809, 1_020_001, 1, false, null),
            new EmailProviderReconciliationFolderState(809, 1_020_001, 1, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage(
                [],
                completeThroughUid: 1_010_000,
            ),
            new EmailProviderReconciliationReadException('provider_timeout'),
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    1_010_005,
                    null,
                    true,
                    false,
                    false,
                    false,
                    false,
                ),
            ], completeThroughUid: 1_020_000),
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 1_020_000,
            ),
            new EmailProviderReconciliationMetadataPage(
                [],
                completeThroughUid: 1_010_000,
            ),
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    1_010_005,
                    null,
                    true,
                    false,
                    false,
                    false,
                    false,
                ),
            ], completeThroughUid: 1_020_000),
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 1_020_000,
            ),
        ];

        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);

        // This represents a worker resuming after earlier bounded empty
        // windows, rather than imposing a lifetime ceiling on a sparse folder.
        $folderRun->forceFill(['next_uid' => 1_000_001])->save();
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame(1_010_001, $folderRun->fresh()->next_uid);
        $this->assertSame(1, $folderRun->fresh()->batch_count);

        try {
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $this->fail('A provider timeout must fail rather than certify an empty window.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_timeout', $exception->safeCode);
        }
        $this->assertSame(1_010_001, $folderRun->fresh()->next_uid);
        $this->assertSame(1, $folderRun->fresh()->batch_count);

        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame(1_020_001, $folderRun->fresh()->next_uid);
        $this->assertSame(1, $folderRun->fresh()->observed_count);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $folderRun->fresh()->forceFill([
            // Model a second-pass worker resuming after 100 already-attested
            // empty numeric windows below this sparse region.
            'metadata_verification_next_uid' => 1_000_001,
            'metadata_verification_batch_count' => 100,
        ])->save();
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertTrue($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->finish($run, $reader);

        $this->assertTrue($placement->fresh()->provider_seen);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $metadataCalls = array_values(array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        ));
        $this->assertSame(
            [
                1_000_000,
                1_010_000,
                1_010_000,
                1_020_000,
                1_000_000,
                1_010_000,
                1_020_000,
            ],
            array_column($metadataCalls, 'afterUid'),
        );
    }

    #[Test]
    public function provider_confirmed_windows_enforce_the_hard_span_and_only_exact_terminal_closes(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-window-boundary@example.test',
            810,
            20_002,
        );
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 5);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(810, 20_002, 0, false, null),
            new EmailProviderReconciliationFolderState(810, 20_002, 0, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage(
                [],
                completeThroughUid: EmailProviderReconciliationPolicy::HARD_UID_WINDOW_SPAN + 1,
            ),
            new EmailProviderReconciliationMetadataPage(
                [],
                completeThroughUid: EmailProviderReconciliationPolicy::HARD_UID_WINDOW_SPAN,
            ),
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 20_000,
            ),
            new EmailProviderReconciliationMetadataPage([], completeThroughUid: 20_000),
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 20_001,
            ),
            new EmailProviderReconciliationMetadataPage(
                [],
                completeThroughUid: EmailProviderReconciliationPolicy::HARD_UID_WINDOW_SPAN,
            ),
            new EmailProviderReconciliationMetadataPage([], completeThroughUid: 20_000),
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 20_001,
            ),
        ];

        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);

        foreach (['hard span', 'frozen terminal boundary'] as $failure) {
            try {
                $scanner->scanOnePage($folderRun->fresh(), $reader);
                $this->fail("An invalid {$failure} must fail closed.");
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame('provider_metadata_window_incomplete', $exception->safeCode);
            }

            if ($failure === 'hard span') {
                $this->assertSame(1, $folderRun->fresh()->next_uid);
                $scanner->scanOnePage($folderRun->fresh(), $reader);
                $this->assertSame(10_001, $folderRun->fresh()->next_uid);
            } else {
                $this->assertSame(10_001, $folderRun->fresh()->next_uid);
            }
            $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
            $this->assertSame(
                EmailProviderReconciliationFolder::STATUS_SCANNING,
                $folderRun->fresh()->status,
            );
        }

        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->assertSame(20_001, $folderRun->fresh()->next_uid);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame(20_002, $folderRun->fresh()->next_uid);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertTrue($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->finish($run, $reader);

        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    #[Test]
    public function ordinary_imap_without_modseq_can_confirm_absence_after_an_explicit_empty_terminal_page(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-no-modseq@example.test', 801, 1);
        [$message, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 77);
        $placement->forceFill([
            'last_provider_observed_sync_version' => 2,
            'last_provider_observed_identity_hash' => app(EmailProviderMessageIdentity::class)
                ->forMessage($message),
            'last_provider_observed_at' => now()->subMinute(),
        ])->save();
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(801, 1, 0, false, null),
            new EmailProviderReconciliationFolderState(801, 1, 0, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
        ];

        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertNotNull($placement->fresh()->provider_missing_at);
        $this->assertSoftDeleted('email_messages', ['id' => $message->id]);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->missing_count);
        $this->assertTrue($run->fresh()->automation_scope_unsafe);
        $this->assertSame(
            EmailProviderReconciliationRun::AUTOMATION_SCOPE_UNSAFE_CODE,
            $run->fresh()->automation_scope_error_code,
        );
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'email_provider_reconciliation_run_id' => $run->id,
            'kind' => EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
            'status' => EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
            'source_placement_id' => $placement->id,
            'placement_sync_version_before' => 1,
        ]);
    }

    #[Test]
    public function nomodseq_flag_evidence_requires_two_exact_inventories_and_timeout_never_advances_verification(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-nomodseq-flag-drift@example.test',
            8_011,
            2,
        );
        [$message, $placement] = $this->messageAndPlacement(
            $account,
            $folder,
            $namespace,
            1,
        );
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $personal = EmailMessageUserState::query()->create([
            'email_message_id' => $message->id,
            'user_id' => $actor->id,
            'last_opened_placement_id' => $placement->id,
            'is_unread' => true,
            'opened_count' => 3,
            'first_opened_at' => now()->subDay(),
            'last_opened_at' => now()->subHour(),
        ]);
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'provider' => 'imap',
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'nomodseq-drift-'.$account->id,
            'source_folder_path' => $folder->path,
            'expected_placement_sync_version' => $placement->sync_version,
            'expected_provider_uid' => $placement->imap_uid,
            'expected_uid_validity' => $placement->imap_uid_validity,
        ]);
        $run = $this->reconciliationRun($account);
        $state = new EmailProviderReconciliationFolderState(8_011, 2, 1, false, null);
        $reader = $this->readerFor($folder, [$state]);
        $reader->metadataPages[$folder->path] = [
            $inventoryPage = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    1,
                    null,
                    true,
                    false,
                    false,
                    false,
                    false,
                ),
            ], completeThroughUid: 1),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 1),
            new EmailProviderReconciliationReadException('provider_timeout'),
            new EmailProviderReconciliationMetadataPage([
                // The flag changed behind the first-pass cursor while the
                // UIDNEXT/EXISTS tuple remained identical.
                new EmailProviderReconciliationMessageMetadata(
                    1,
                    null,
                    false,
                    false,
                    false,
                    false,
                    false,
                ),
            ], completeThroughUid: 1),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 1),
        ];

        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $folderRun->refresh();
        $this->assertSame('nomodseq_verification_pending', $folderRun->reason_code);
        $this->assertSame(
            EmailProviderReconciliationFolder::METADATA_VERIFICATION_RUNNING,
            $folderRun->metadata_verification_status,
        );

        try {
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $this->fail('A verification timeout must not certify or advance a metadata window.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_timeout', $exception->safeCode);
        }
        $this->assertSame(1, $folderRun->fresh()->metadata_verification_next_uid);
        $this->assertSame(0, $folderRun->fresh()->metadata_verification_batch_count);
        $this->assertFalse($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $this->assertSame(2, $folderRun->fresh()->metadata_verification_next_uid);
        $this->assertTrue($scanner->scanOnePage($folderRun->fresh(), $reader)['folder_finished']);
        $folderRun->refresh();

        $this->assertSame(EmailProviderReconciliationFolder::STATUS_STALE, $folderRun->status);
        $this->assertSame(
            EmailProviderReconciliationFolder::METADATA_VERIFICATION_FAILED,
            $folderRun->metadata_verification_status,
        );
        $this->assertSame('provider_nomodseq_inventory_drift', $folderRun->reason_code);
        $this->assertFalse($placement->fresh()->provider_seen);
        $this->assertSame(1, $placement->fresh()->sync_version);
        $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $operation->fresh()->status);
        $this->assertTrue($personal->fresh()->is_unread);
        $this->assertSame(3, $personal->fresh()->opened_count);
        $this->finish($run, $reader);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_STALE, $run->fresh()->status);

        try {
            DB::table('email_provider_reconciliation_folders')
                ->where('id', $folderRun->id)
                ->update(['metadata_verification_status' => null]);
            $this->fail('The database accepted a null verifier discriminator with durable evidence.');
        } catch (QueryException) {
            $this->assertSame(
                EmailProviderReconciliationFolder::METADATA_VERIFICATION_FAILED,
                $folderRun->fresh()->metadata_verification_status,
            );
        }
    }

    #[Test]
    public function drifting_tuple_and_incomplete_or_failed_pages_never_close_negative_evidence(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-drift@example.test', 802, 1);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 88);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(802, 1, 0, false, null),
            new EmailProviderReconciliationFolderState(802, 2, 0, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
        ];
        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_STALE, $run->fresh()->status);
        $this->assertSame('provider_tuple_drift', $folderRun->fresh()->reason_code);

        [$otherAccount, $otherFolder, $otherNamespace] = $this->mailbox('reconcile-partial@example.test', 803, 500);
        [, $otherPlacement] = $this->messageAndPlacement($otherAccount, $otherFolder, $otherNamespace, 99);
        $otherRun = $this->reconciliationRun($otherAccount);
        $partialReader = $this->readerFor($otherFolder, [
            new EmailProviderReconciliationFolderState(803, 500, 1, false, null),
        ]);
        $partialReader->metadataPages[$otherFolder->path] = [
            new EmailProviderReconciliationMetadataPage([], terminal: false),
            new EmailProviderReconciliationReadException('provider_timeout'),
        ];
        $otherFolderRun = $this->discover($otherRun, $partialReader);
        $scanner->scanOnePage($otherFolderRun, $partialReader);

        try {
            $scanner->scanOnePage($otherFolderRun->fresh(), $partialReader);
            $this->fail('A nonterminal empty page must fail closed.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_metadata_window_incomplete', $exception->safeCode);
        }
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $otherPlacement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_SCANNING, $otherFolderRun->fresh()->status);
    }

    #[Test]
    public function an_end_state_uidvalidity_reset_blocks_the_folder_without_projecting_cycle_evidence(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-end-uidvalidity-reset@example.test',
            8_021,
            2,
        );
        [$message, $placement] = $this->messageAndPlacement(
            $account,
            $folder,
            $namespace,
            1,
        );
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(8_021, 2, 1, false, null),
            new EmailProviderReconciliationFolderState(8_022, 2, 1, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            $inventoryPage = new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(
                    uid: 1,
                    modseq: null,
                    seen: true,
                    answered: true,
                    flagged: true,
                    deleted: true,
                    draft: true,
                ),
            ], completeThroughUid: 1),
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 1,
            ),
            $inventoryPage,
            new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: 1,
            ),
        ];

        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertSame(EmailProviderReconciliationRun::STATUS_BLOCKED, $run->fresh()->status);
        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_BLOCKED,
            $folderRun->fresh()->status,
        );
        $this->assertSame('uidvalidity_changed', $folderRun->fresh()->reason_code);
        $this->assertSame(8_022, $folderRun->fresh()->end_uid_validity);
        $this->assertSame(EmailFolder::SYNC_ERROR, $folder->fresh()->sync_status);
        $this->assertSame('IMAP_UIDVALIDITY_CHANGED', $folder->fresh()->sync_error_code);

        $placement = $placement->fresh();
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->local_state);
        $this->assertFalse($placement->provider_seen);
        $this->assertFalse($placement->provider_answered);
        $this->assertFalse($placement->provider_flagged);
        $this->assertFalse($placement->provider_deleted);
        $this->assertFalse($placement->provider_draft);
        $this->assertSame(1, $placement->sync_version);
        $this->assertDatabaseHas('email_messages', ['id' => $message->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('email_provider_reconciliation_items', [
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'kind' => EmailProviderReconciliationItem::KIND_OBSERVATION,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
        ]);

        Permission::findOrCreate('email.account_manage', 'web');
        Permission::findOrCreate('email.mailbox_sync_manage', 'web');
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo(['email.account_manage', 'email.mailbox_sync_manage']);
        $maintenance = app(EmailMailboxMaintenanceQuery::class)->forAccount($account, $operator);
        $visibleFolder = $maintenance['reconciliationDetailRun']->folders->sole();
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_BLOCKED, $visibleFolder->status);
        $this->assertSame('uidvalidity_changed', $visibleFolder->reason_code);
    }

    #[Test]
    public function metadata_contract_requires_an_empty_terminal_and_exact_custom_flag_order(): void
    {
        $metadata = new EmailProviderReconciliationMessageMetadata(
            9,
            null,
            false,
            false,
            false,
            false,
            false,
            ['Beta', 'alpha', 'ALPHA', '\\Recent', '\\Seen'],
        );
        $this->assertSame(['alpha', 'beta'], $metadata->customFlags);
        $this->assertSame(
            hash('sha256', json_encode(['alpha', 'beta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            $metadata->customFlagsHash,
        );

        try {
            new EmailProviderReconciliationMessageMetadata(
                10,
                null,
                false,
                false,
                false,
                false,
                false,
                array_fill(0, 129, 'oversized'),
            );
            $this->fail('Provider-controlled custom flags must remain bounded.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new EmailProviderReconciliationMetadataPage(
            [$metadata],
            terminal: true,
            completeThroughUid: 9,
        );
    }

    #[Test]
    public function provider_read_errors_never_expose_unbounded_or_unsafe_codes(): void
    {
        $this->assertSame(
            'provider_timeout',
            (new EmailProviderReconciliationReadException('provider_timeout'))->safeCode,
        );
        $this->assertSame(
            'provider_reconciliation_read_failed',
            (new EmailProviderReconciliationReadException(
                'imap password=test-secret failed for user@example.test',
            ))->safeCode,
        );
        $this->assertSame(
            'provider_reconciliation_read_failed',
            (new EmailProviderReconciliationReadException(str_repeat('x', 81)))->safeCode,
        );
    }

    #[Test]
    public function peeked_message_envelope_is_uid_bound_non_serializable_and_debug_redacted(): void
    {
        $message = Message::fromString(implode("\r\n", [
            'Message-ID: <peek-redaction@example.test>',
            'Subject: Private reconciliation subject',
            '',
            'Private reconciliation body.',
        ]));
        $message->setUid(44)->setFolderPath('INBOX');
        $peeked = new EmailProviderReconciliationPeekedMessage([
            'imap_uid' => 44,
            'subject' => 'Private reconciliation subject',
            'body_text' => 'Private reconciliation body.',
        ], $message);

        $this->assertSame(44, $peeked->payload()['imap_uid']);
        $this->assertSame(44, (int) $peeked->message()->getUid());
        $debug = print_r($peeked, true);
        $this->assertStringContainsString('[REDACTED]', $debug);
        $this->assertStringNotContainsString('Private reconciliation subject', $debug);
        $this->assertStringNotContainsString('Private reconciliation body', $debug);

        try {
            serialize($peeked);
            $this->fail('The provider PEEK envelope must not serialize.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Provider reconciliation PEEK results may not be serialized.',
                $exception->getMessage(),
            );
        }

        $this->expectException(InvalidArgumentException::class);
        new EmailProviderReconciliationPeekedMessage(['imap_uid' => 45], $message);
    }

    #[Test]
    public function idle_presence_is_token_owned_bounded_and_released_on_stop_or_exception(): void
    {
        Cache::flush();
        $account = $this->account('reconcile-idle@example.test');
        $lease = EmailProviderIdlePresenceLease::acquire($account->id, 1);
        $this->assertNotNull($lease);
        $this->assertTrue(EmailProviderIdlePresenceLease::active($account->id));
        $this->travel(2)->seconds();
        $this->assertFalse(EmailProviderIdlePresenceLease::active($account->id), 'A lost listener is released by TTL.');
        $this->travelBack();

        $activeThroughShutdownJitter = false;
        $jitterProbe = function () use ($account, &$activeThroughShutdownJitter): bool {
            $this->travel(36)->seconds();
            try {
                $activeThroughShutdownJitter = EmailProviderIdlePresenceLease::active($account->id);
            } finally {
                $this->travelBack();
            }

            return false;
        };
        $boundedHints = new class($jitterProbe) implements EmailProviderIdleHintReader
        {
            public function __construct(private readonly \Closure $probe) {}

            public function waitForOpaqueHint(int $accountId, int $expectedBindingVersion, int $maxSeconds): bool
            {
                return ($this->probe)();
            }
        };
        (new ListenForEmailProviderChanges($account->id, 1))->handle(
            $boundedHints,
            app(EmailProviderReconciliationBindingPolicy::class),
            app(StartEmailProviderReconciliation::class),
        );
        $this->assertTrue(
            $activeThroughShutdownJitter,
            'Drain presence must outlive the worker timeout during shutdown jitter.',
        );
        $this->assertFalse(EmailProviderIdlePresenceLease::active($account->id));

        $observedPresence = false;
        $hints = new class($account->id, $observedPresence) implements EmailProviderIdleHintReader
        {
            public function __construct(private int $accountId, private bool &$observedPresence) {}

            public function waitForOpaqueHint(int $accountId, int $expectedBindingVersion, int $maxSeconds): bool
            {
                $this->observedPresence = EmailProviderIdlePresenceLease::active($this->accountId);

                throw new RuntimeException('simulated idle disconnect');
            }
        };
        $job = new ListenForEmailProviderChanges($account->id, 1);
        try {
            $job->handle(
                $hints,
                app(EmailProviderReconciliationBindingPolicy::class),
                app(StartEmailProviderReconciliation::class),
            );
            $this->fail('The fake listener must disconnect.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated idle disconnect', $exception->getMessage());
        }
        $this->assertTrue($observedPresence);
        $this->assertFalse(EmailProviderIdlePresenceLease::active($account->id));
    }

    #[Test]
    public function idle_fails_closed_for_missing_binding_and_for_pause_after_socket_open(): void
    {
        Cache::flush();
        Queue::fake();
        $account = $this->account('reconcile-idle-pause@example.test');
        $calls = 0;
        $hints = new class($account->id, $calls) implements EmailProviderIdleHintReader
        {
            public function __construct(private int $accountId, private int &$calls) {}

            public function waitForOpaqueHint(int $accountId, int $expectedBindingVersion, int $maxSeconds): bool
            {
                $this->calls++;
                EmailAccount::query()->whereKey($this->accountId)->update([
                    'provider_runtime_paused_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            }
        };

        (new ListenForEmailProviderChanges($account->id, 0))->handle(
            $hints,
            app(EmailProviderReconciliationBindingPolicy::class),
            app(StartEmailProviderReconciliation::class),
        );
        $this->assertSame(0, $calls);

        (new ListenForEmailProviderChanges($account->id))->handle(
            $hints,
            app(EmailProviderReconciliationBindingPolicy::class),
            app(StartEmailProviderReconciliation::class),
        );
        $this->assertSame(0, $calls, 'A missing serialized binding snapshot must fail closed.');

        (new ListenForEmailProviderChanges($account->id, 1))->handle(
            $hints,
            app(EmailProviderReconciliationBindingPolicy::class),
            app(StartEmailProviderReconciliation::class),
        );
        $this->assertSame(1, $calls);
        $this->assertFalse(EmailProviderIdlePresenceLease::active($account->id));
        Queue::assertNotPushed(ReconcileEmailProviderAccount::class);
    }

    #[Test]
    public function exact_active_run_status_helper_blocks_cutover_until_terminal(): void
    {
        $account = $this->account('reconcile-active-status@example.test');
        $run = $this->reconciliationRun($account);

        $this->assertSame([
            EmailProviderReconciliationRun::STATUS_QUEUED,
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            EmailProviderReconciliationRun::STATUS_CANCELLING,
        ], EmailProviderReconciliationRun::ACTIVE_STATUSES);
        $this->assertTrue(EmailProviderReconciliationRun::accountHasActiveRun($account->id));

        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_COMPLETED,
            'active_slot' => null,
            'finished_at' => now(),
        ])->save();
        $this->assertFalse(EmailProviderReconciliationRun::accountHasActiveRun($account->id));
    }

    #[Test]
    public function reconciliation_binding_snapshot_is_positive_even_through_query_builder_bypass(): void
    {
        $account = $this->account('reconcile-binding-db-guard@example.test');
        $run = $this->reconciliationRun($account);

        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $run->id)
                ->update(['provider_binding_version' => 0]);
            $this->fail('The database must reject a zero binding update.');
        } catch (QueryException) {
            $this->assertSame(1, $run->fresh()->provider_binding_version);
        }

        try {
            DB::table('email_provider_reconciliation_runs')->insert([
                'account_id' => $account->id,
                'provider' => 'imap',
                'trigger' => EmailProviderReconciliationRun::TRIGGER_CATCHUP,
                'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
                'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
                'active_slot' => null,
                'idempotency_key' => hash('sha256', 'zero-binding-insert:'.$account->id),
                'provider_binding_version' => 0,
                'max_folders' => 10,
                'uid_batch_size' => 10,
                'provider_time_cap_seconds' => 10,
                'normal_interval_seconds' => 300,
                'queued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject a zero binding insert.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('email_provider_reconciliation_runs', [
                'idempotency_key' => hash('sha256', 'zero-binding-insert:'.$account->id),
            ]);
        }
    }

    #[Test]
    public function reconciliation_active_slot_is_one_or_null_even_through_query_builder_bypass(): void
    {
        $account = $this->account('reconcile-active-slot-db-guard@example.test');
        $run = $this->reconciliationRun($account);

        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $run->id)
                ->update(['active_slot' => 2]);
            $this->fail('The database must reject a noncanonical active-slot update.');
        } catch (QueryException) {
            $this->assertSame(1, $run->fresh()->active_slot);
        }

        $idempotencyKey = hash('sha256', 'invalid-active-slot-insert:'.$account->id);
        try {
            DB::table('email_provider_reconciliation_runs')->insert([
                'account_id' => $account->id,
                'provider' => 'imap',
                'trigger' => EmailProviderReconciliationRun::TRIGGER_CATCHUP,
                'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
                'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
                'active_slot' => 2,
                'idempotency_key' => $idempotencyKey,
                'provider_binding_version' => 1,
                'max_folders' => 10,
                'uid_batch_size' => 10,
                'provider_time_cap_seconds' => 10,
                'normal_interval_seconds' => 300,
                'queued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject a second numeric active-slot value.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('email_provider_reconciliation_runs', [
                'idempotency_key' => $idempotencyKey,
            ]);
        }
    }

    #[Test]
    public function local_folder_discovery_is_hard_bounded_durable_and_never_repeats_provider_list_after_loss(): void
    {
        config()->set('email_provider_reconciliation.local_folder_snapshot_batch_size', 2);
        Queue::fake();
        [$account, $inbox] = $this->mailbox(
            'reconcile-local-folder-cursor@example.test',
            8_031,
            10,
        );
        foreach (range(1, 5) as $number) {
            EmailFolder::query()->create([
                'account_id' => $account->id,
                'provider' => 'imap',
                'path' => 'Local/History/'.$number,
                'name' => 'History '.$number,
                'delimiter' => '/',
                'parent_path' => 'Local/History',
                'role' => EmailFolder::ROLE_CUSTOM,
                'is_selectable' => true,
                'sync_enabled' => true,
                'sync_status' => EmailFolder::SYNC_SYNCED,
            ]);
        }
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($inbox, []);
        $makeCoordinator = fn (): EmailProviderReconciliationCoordinator => new EmailProviderReconciliationCoordinator(
            app(EmailProviderReconciliationBindingPolicy::class),
            app(EmailProviderReconciliationFingerprint::class),
        );

        (new ReconcileEmailProviderAccount($run->id))->handle($makeCoordinator(), $reader);
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL, $run->phase);
        $this->assertSame(EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_RUNNING, $run->local_folder_snapshot_status);
        $this->assertSame(2, $run->local_folder_snapshot_count);
        $this->assertSame(1, $run->local_folder_snapshot_batch_count);
        $this->assertLessThan($run->local_folder_snapshot_through_id, $run->local_folder_snapshot_cursor_id);
        $frozenThroughId = $run->local_folder_snapshot_through_id;
        $firstHash = $run->local_folder_snapshot_hash;
        Queue::assertPushed(ReconcileEmailProviderAccount::class, 1);
        Queue::assertNotPushed(ReconcileEmailProviderFolderBatch::class);
        Queue::assertNotPushed(FinalizeEmailProviderReconciliation::class);
        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $run,
            $reader,
        ));
        app(UniqueLock::class)->release(new ReconcileEmailProviderAccount($run->id));

        // This folder was not present at the frozen high-water and belongs to
        // the next reconciliation cycle even if it appears before resumption.
        $lateFolder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'Local/History/Late',
            'name' => 'Late',
            'delimiter' => '/',
            'parent_path' => 'Local/History',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $this->assertGreaterThan($frozenThroughId, $lateFolder->id);

        // Fresh coordinator instances model hard worker loss after each
        // committed page. Only the durable run cursor carries progress.
        (new ReconcileEmailProviderAccount($run->id))->handle($makeCoordinator(), $reader);
        $run->refresh();
        $this->assertSame(4, $run->local_folder_snapshot_count);
        $this->assertSame(2, $run->local_folder_snapshot_batch_count);
        $this->assertNotSame($firstHash, $run->local_folder_snapshot_hash);
        Queue::assertPushed(ReconcileEmailProviderAccount::class, 2);
        Queue::assertNotPushed(ReconcileEmailProviderFolderBatch::class);
        app(UniqueLock::class)->release(new ReconcileEmailProviderAccount($run->id));

        (new ReconcileEmailProviderAccount($run->id))->handle($makeCoordinator(), $reader);
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::PHASE_SCAN, $run->phase);
        $this->assertSame(EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED, $run->local_folder_snapshot_status);
        $this->assertSame($frozenThroughId, $run->local_folder_snapshot_cursor_id);
        $this->assertSame(6, $run->local_folder_snapshot_count);
        $this->assertSame(3, $run->local_folder_snapshot_batch_count);
        $this->assertNotNull($run->local_folder_snapshot_completed_at);
        Queue::assertPushed(ReconcileEmailProviderFolderBatch::class, 1);
        $this->assertSame(1, collect($reader->calls)->where('operation', 'discover')->count());
        $this->assertSame(1, collect($reader->calls)->where('operation', 'binding')->count());
        $this->assertDatabaseCount('email_provider_reconciliation_folders', 6);
        $this->assertSame(5, EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('discovery_state', EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY)
            ->count());
        $this->assertDatabaseMissing('email_provider_reconciliation_folders', [
            'email_provider_reconciliation_run_id' => $run->id,
            'folder_path' => $lateFolder->path,
        ]);

        $expectedHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $fingerprints = app(EmailProviderReconciliationFingerprint::class);
        foreach (EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('id', '<=', $frozenThroughId)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->orderBy('id')
            ->get() as $folder) {
            $folderHash = $fingerprints->make([
                'id' => (int) $folder->id,
                'path' => (string) $folder->path,
                'name' => (string) $folder->name,
                'delimiter' => $folder->delimiter,
                'parent_path' => $folder->parent_path,
                'remote_id' => $folder->remote_id,
                'special_use' => $folder->special_use,
                'active_uid_namespace_id' => $folder->active_uid_namespace_id,
                'is_selectable' => (bool) $folder->is_selectable,
                'sync_enabled' => (bool) $folder->sync_enabled,
            ]);
            $expectedHash = hash('sha256', $expectedHash."\n".$folderHash);
        }
        $this->assertSame($expectedHash, $run->local_folder_snapshot_hash);

        // Redelivery after completion is idempotent and provider-free.
        $ids = $makeCoordinator()->discoverStart($run, $reader);
        $this->assertCount(1, $ids);
        $this->assertSame(3, $run->fresh()->local_folder_snapshot_batch_count);
        $this->assertSame(1, collect($reader->calls)->where('operation', 'discover')->count());

        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $run->id)
                ->update(['local_folder_snapshot_status' => null]);
            $this->fail('The database must reject a null discriminator on non-empty local evidence.');
        } catch (QueryException) {
            $this->assertSame(
                EmailProviderReconciliationRun::LOCAL_FOLDER_SNAPSHOT_COMPLETED,
                $run->fresh()->local_folder_snapshot_status,
            );
        }

        try {
            DB::table('email_provider_reconciliation_runs')
                ->where('id', $run->id)
                ->update([
                    'local_folder_snapshot_cursor_id' => $frozenThroughId + 1,
                ]);
            $this->fail('The database must reject a cursor beyond the frozen local-folder high-water.');
        } catch (QueryException) {
            $this->assertSame($frozenThroughId, $run->fresh()->local_folder_snapshot_cursor_id);
        }
        $this->assertTrue(Schema::hasIndex('email_folders', 'em_folder_recon_local_cursor_ix'));
    }

    #[Test]
    public function historical_baseline_ledger_is_database_guarded_and_account_cursor_indexed(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-historical-baseline-guard@example.test',
            8_041,
            10,
        );
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(8_041, 10, 1, false, null),
        ]);
        $folderRun = $this->discover($run, $reader);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
        ]);

        $this->assertTrue(Schema::hasIndex(
            'email_account_user_read_baselines',
            'em_read_base_account_cursor_ix',
        ));

        try {
            DB::table('email_provider_reconciliation_items')
                ->where('id', $item->id)
                ->update([
                    'historical_baseline_required' => true,
                    'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                ]);
            $this->fail('A required baseline without frozen evidence must be rejected.');
        } catch (QueryException) {
            $this->assertFalse($item->fresh()->historical_baseline_required);
        }

        try {
            DB::table('email_provider_reconciliation_items')->insert([
                'email_provider_reconciliation_run_id' => $run->id,
                'email_provider_reconciliation_folder_id' => $folderRun->id,
                'uid_namespace_id' => $namespace->id,
                'imap_uid' => 8,
                'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
                'status' => EmailProviderReconciliationItem::STATUS_PENDING,
                'historical_baseline_required' => false,
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A non-required item cannot carry a baseline state.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('email_provider_reconciliation_items', [
                'email_provider_reconciliation_run_id' => $run->id,
                'imap_uid' => 8,
                'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            ]);
        }

        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE,
            'historical_baseline_required' => true,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
            'historical_baseline_max_id' => 5,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_frozen_at' => now(),
        ])->save();

        foreach ([
            ['historical_baseline_status' => null],
            ['historical_baseline_cursor_id' => 6],
            [
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                'historical_baseline_last_attempt_at' => now(),
            ],
            [
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
                'historical_baseline_claim_token' => null,
            ],
        ] as $invalidUpdate) {
            try {
                DB::table('email_provider_reconciliation_items')
                    ->where('id', $item->id)
                    ->update($invalidUpdate);
                $this->fail('The database accepted an incoherent historical baseline transition.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, DB::table('email_provider_reconciliation_items')
            ->where('id', $item->id)
            ->update([
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                'historical_baseline_claim_token' => hash('sha256', 'baseline-claim'),
                'historical_baseline_attempt_count' => 1,
                'historical_baseline_first_attempt_at' => now(),
                'historical_baseline_last_attempt_at' => now(),
            ]));
        $this->assertSame(1, DB::table('email_provider_reconciliation_items')
            ->where('id', $item->id)
            ->update([
                'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
                'historical_baseline_cursor_id' => 5,
                'historical_baseline_claim_token' => null,
                'historical_baseline_completed_at' => now(),
                'completed_at' => now(),
            ]));
        $this->assertTrue($item->fresh()->historicalBaselineTerminal());
    }

    #[Test]
    public function new_folder_and_live_cycles_share_uidnext_minus_one_high_water(): void
    {
        $account = $this->account('reconcile-new-folder-high-water@example.test');
        $baseline = $this->reconciliationRun($account);
        $baseline->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_COMPLETED,
            'active_slot' => null,
            'finished_at' => now()->subMinute(),
        ])->save();

        $run = $this->reconciliationRun($account);
        $reader = new FakeEmailProviderReconciliationReader;
        $reader->folders = [new EmailProviderReconciliationFolderDescriptor(
            path: 'Projects/New',
            name: 'New',
            delimiter: '/',
        )];
        $state = new EmailProviderReconciliationFolderState(8_042, 10, 1, false, null);
        $reader->folderStates['Projects/New'] = [$state];
        $folderRun = $this->discover($run, $reader);
        $folderRun = app(EmailProviderReconciliationFolderProjector::class)->initialize(
            $folderRun,
            $state,
        );
        $folder = $folderRun->folder()->firstOrFail();

        $this->assertSame(EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE, $folderRun->discovery_state);
        $this->assertSame(EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES, $folderRun->import_policy);
        $this->assertSame(9, $folderRun->scan_through_uid);
        $this->assertSame(9, $folder->live_start_uid);
        $this->assertSame(9, $folderRun->uidNamespace()->firstOrFail()->live_start_uid);
        $history = app(EmailProviderReconciliationPlacementProjector::class)->observe(
            $folderRun,
            new EmailProviderReconciliationMessageMetadata(9, null, false, false, false, false, false),
            0,
            9,
        );
        $this->assertNotNull($history['import_item_id']);

        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_CANCELLED,
            'active_slot' => null,
            'finished_at' => now(),
        ])->save();
        $folderRun->forceFill([
            'status' => EmailProviderReconciliationFolder::STATUS_CANCELLED,
            'finished_at' => now(),
        ])->save();

        $liveRun = $this->reconciliationRun($account);
        $liveReader = new FakeEmailProviderReconciliationReader;
        $liveReader->folders = $reader->folders;
        $liveState = new EmailProviderReconciliationFolderState(8_042, 11, 2, false, null);
        $liveReader->folderStates['Projects/New'] = [$liveState];
        $liveFolderRun = $this->discover($liveRun, $liveReader);
        $liveFolderRun = app(EmailProviderReconciliationFolderProjector::class)->initialize(
            $liveFolderRun,
            $liveState,
        );

        $atHighWater = app(EmailProviderReconciliationPlacementProjector::class)->observe(
            $liveFolderRun,
            new EmailProviderReconciliationMessageMetadata(9, null, false, false, false, false, false),
            0,
            10,
        );
        $firstLive = app(EmailProviderReconciliationPlacementProjector::class)->observe(
            $liveFolderRun,
            new EmailProviderReconciliationMessageMetadata(10, null, false, false, false, false, false),
            0,
            10,
        );

        $this->assertSame(EmailProviderReconciliationFolder::IMPORT_LIVE, $liveFolderRun->import_policy);
        $this->assertSame(9, $folder->fresh()->live_start_uid);
        $this->assertNull($atHighWater['import_item_id']);
        $this->assertNotNull($firstLive['import_item_id']);
    }

    #[Test]
    public function placement_snapshots_resume_in_tiny_pages_and_gate_every_projection_phase(): void
    {
        config()->set('email_provider_reconciliation.placement_snapshot_batch_size', 2);
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-placement-snapshot@example.test',
            8_043,
            6,
        );
        $placements = collect(range(1, 5))->map(fn (int $uid): EmailMailboxPlacement => $this
            ->messageAndPlacement($account, $folder, $namespace, $uid)[1]);
        $state = new EmailProviderReconciliationFolderState(8_043, 6, 5, false, null);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [$state, $state]);
        $reader->metadataPages[$folder->path] = [
            $inventoryPage = new EmailProviderReconciliationMetadataPage(
                collect(range(1, 5))
                    ->map(fn (int $uid): EmailProviderReconciliationMessageMetadata => new EmailProviderReconciliationMessageMetadata(
                        $uid,
                        null,
                        true,
                        false,
                        false,
                        false,
                        false,
                    ))
                    ->all(),
                completeThroughUid: 5,
            ),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 5),
            $inventoryPage,
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 5),
        ];
        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);

        $scanner->scanOnePage($folderRun, $reader);
        $folderRun->refresh();
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_PENDING, $folderRun->status);
        $this->assertSame(2, $folderRun->placement_snapshot_count);
        $this->assertSame(1, $folderRun->placement_snapshot_batch_count);
        $this->assertSame(
            EmailProviderReconciliationFolder::SNAPSHOT_RUNNING,
            $folderRun->placement_snapshot_status,
        );
        $this->assertCount(1, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'state',
        ));
        $this->assertCount(0, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        ));

        // A fresh service instance models a hard worker loss. It resumes from
        // the committed cursor/hash rather than scanning again from ID zero.
        $secondPage = (new EmailProviderReconciliationPlacementSnapshot)->advance(
            $folderRun->fresh(),
            EmailProviderReconciliationFolder::SNAPSHOT_BASELINE,
            null,
            2,
        );
        $this->assertFalse($secondPage['complete']);
        $this->assertSame(4, $secondPage['count']);
        $this->assertGreaterThan(0, $secondPage['cursor_id']);

        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $folderRun->refresh();
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_SCANNING, $folderRun->status);
        $this->assertSame(5, $folderRun->baseline_placement_count);
        $this->assertSame(3, $folderRun->placement_snapshot_batch_count);
        $baselineHash = $folderRun->placement_baseline_hash;

        $scanner->scanOnePage($folderRun, $reader);
        $this->assertTrue($placements->every(
            fn (EmailMailboxPlacement $placement): bool => ! $placement->fresh()->provider_seen,
        ));
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->assertSame('nomodseq_verification_pending', $folderRun->fresh()->reason_code);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->assertTrue($placements->every(
            fn (EmailMailboxPlacement $placement): bool => ! $placement->fresh()->provider_seen,
        ));
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $folderRun->refresh();
        $this->assertSame('placement_scan_snapshot_pending', $folderRun->reason_code);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_SCANNING, $folderRun->status);
        $this->assertSame(2, $folderRun->placement_snapshot_count);
        $this->assertCount(4, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        ));

        $scanner->scanOnePage($folderRun, $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $folderRun->refresh();
        $this->assertSame(
            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
            $folderRun->status,
        );
        $this->assertSame($baselineHash, $folderRun->placement_scan_hash);
        $this->assertCount(4, array_filter(
            $reader->calls,
            fn (array $call): bool => $call['operation'] === 'metadata',
        ), 'DB snapshot resume must not re-read the provider terminal page.');

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        foreach (range(1, 3) as $validationPage) {
            $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
            $this->assertTrue($placements->every(
                fn (EmailMailboxPlacement $placement): bool => ! $placement->fresh()->provider_seen,
            ), 'remote end snapshot page '.$validationPage.' projected a flag early');
        }
        $this->assertSame('stable_end_validated', $folderRun->fresh()->reason_code);

        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertSame('stable_absence_freeze', $folderRun->fresh()->reason_code);
        foreach (range(1, 3) as $projectionPage) {
            $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
            $this->assertTrue($placements->every(
                fn (EmailMailboxPlacement $placement): bool => ! $placement->fresh()->provider_seen,
            ), 'pre-projection snapshot page '.$projectionPage.' projected a flag early');
            $this->assertSame(0, $run->items()
                ->whereIn('kind', [
                    EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
                    EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
                ])
                ->where('status', '!=', EmailProviderReconciliationItem::STATUS_PENDING)
                ->count());
        }
        $this->assertSame('stable_operation_projection', $folderRun->fresh()->reason_code);

        $this->assertFalse($finalizer->finalizeOneStep($run->fresh(), $reader));
        $this->assertTrue($placements->every(
            fn (EmailMailboxPlacement $placement): bool => $placement->fresh()->provider_seen,
        ));
        $this->finish($run, $reader);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);

        try {
            DB::table('email_provider_reconciliation_folders')
                ->where('id', $folderRun->id)
                ->update([
                    'placement_snapshot_cursor_id' => (int) $folderRun->fresh()
                        ->placement_snapshot_through_id + 1,
                ]);
            $this->fail('The database accepted a snapshot cursor beyond its frozen maximum.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            ['placement_snapshot_purpose' => null],
            ['placement_snapshot_status' => null],
        ] as $nullDiscriminator) {
            try {
                DB::table('email_provider_reconciliation_folders')
                    ->where('id', $folderRun->id)
                    ->update($nullDiscriminator);
                $this->fail('The database accepted a null placement snapshot discriminator.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function exact_page_redelivery_is_idempotent_but_changed_redelivery_invalidates_the_cycle(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-redelivery@example.test', 804, 10);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 9);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(804, 10, 1, false, null),
        ]);
        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);
        $metadata = new EmailProviderReconciliationMessageMetadata(
            9,
            null,
            true,
            false,
            false,
            false,
            false,
            ['customer'],
        );

        // Simulate a worker dying after its observation transaction commits
        // but before the durable folder cursor is advanced.
        app(EmailProviderReconciliationPlacementProjector::class)->observe(
            $folderRun->fresh(),
            $metadata,
            0,
            9,
        );
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([$metadata], completeThroughUid: 9),
        ];
        $scanner->scanOnePage($folderRun->fresh(), $reader);

        $this->assertSame(10, $folderRun->fresh()->next_uid);
        $this->assertSame(1, $folderRun->fresh()->observed_count);
        $this->assertSame(1, EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $folderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_OBSERVATION)
            ->count());
        $this->assertFalse($placement->fresh()->provider_seen, 'Projection waits for the stable end gate.');

        // An unknown live UID has no identity until its detached PEEK reaches
        // Store. Redelivery in the commit-before-cursor crash window must not
        // poison the account-wide automation scope merely because that
        // intentionally pending observation still has a null identity.
        [$unknownAccount, $unknownFolder] = $this->mailbox(
            'reconcile-redelivery-before-import@example.test',
            8_052,
            10,
        );
        $unknownBaseline = $this->reconciliationRun($unknownAccount);
        $unknownBaseline->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_COMPLETED,
            'active_slot' => null,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ])->save();
        $unknownRun = $this->reconciliationRun($unknownAccount);
        $unknownReader = $this->readerFor($unknownFolder, [
            new EmailProviderReconciliationFolderState(8_052, 10, 1, false, null),
        ]);
        $unknownFolderRun = $this->discover($unknownRun, $unknownReader);
        $scanner->scanOnePage($unknownFolderRun, $unknownReader);
        $unknownMetadata = new EmailProviderReconciliationMessageMetadata(
            9,
            null,
            false,
            false,
            false,
            false,
            false,
        );
        $projector = app(EmailProviderReconciliationPlacementProjector::class);
        $projector->observe($unknownFolderRun->fresh(), $unknownMetadata, 0, 9);
        $projector->observe($unknownFolderRun->fresh(), $unknownMetadata, 0, 9);

        $this->assertFalse($unknownRun->fresh()->automation_scope_unsafe);
        $this->assertSame(1, EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_folder_id', $unknownFolderRun->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->count());

        [$otherAccount, $otherFolder, $otherNamespace] = $this->mailbox(
            'reconcile-redelivery-drift@example.test',
            805,
            10,
        );
        [, $otherPlacement] = $this->messageAndPlacement(
            $otherAccount,
            $otherFolder,
            $otherNamespace,
            9,
        );
        $otherRun = $this->reconciliationRun($otherAccount);
        $otherReader = $this->readerFor($otherFolder, [
            new EmailProviderReconciliationFolderState(805, 10, 1, false, null),
        ]);
        $otherFolderRun = $this->discover($otherRun, $otherReader);
        $scanner->scanOnePage($otherFolderRun, $otherReader);
        $projector = app(EmailProviderReconciliationPlacementProjector::class);
        $projector->observe(
            $otherFolderRun->fresh(),
            new EmailProviderReconciliationMessageMetadata(9, null, true, false, false, false, false),
            0,
            9,
        );
        $changed = $projector->observe(
            $otherFolderRun->fresh(),
            new EmailProviderReconciliationMessageMetadata(9, null, true, false, true, false, false),
            0,
            9,
        );

        $this->assertTrue($changed['evidence_drift']);
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_STALE, $otherFolderRun->fresh()->status);
        $this->assertSame('provider_observation_redelivery_drift', $otherFolderRun->fresh()->reason_code);
        $this->assertFalse($otherPlacement->fresh()->provider_seen);
        $this->assertSame(1, $otherPlacement->fresh()->sync_version);
    }

    #[Test]
    public function unchanged_known_and_suppressed_old_uids_do_not_create_per_cycle_item_growth(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-no-item-growth@example.test',
            8_051,
            102,
        );
        $folder->forceFill(['live_start_uid' => 100])->save();
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 101);
        $metadata = [
            new EmailProviderReconciliationMessageMetadata(50, null, false, false, false, false, false),
            new EmailProviderReconciliationMessageMetadata(101, null, false, false, false, false, false),
        ];

        foreach ([1, 2] as $cycle) {
            $run = $this->reconciliationRun($account);
            $reader = $this->readerFor($folder, [
                new EmailProviderReconciliationFolderState(8_051, 102, 2, false, null),
                new EmailProviderReconciliationFolderState(8_051, 102, 2, false, null),
            ]);
            $reader->metadataPages[$folder->path] = [
                new EmailProviderReconciliationMetadataPage($metadata, completeThroughUid: 101),
                new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 101),
                new EmailProviderReconciliationMetadataPage($metadata, completeThroughUid: 101),
                new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 101),
            ];
            $folderRun = $this->discover($run, $reader);
            $scanner = app(EmailProviderReconciliationScanner::class);
            $scanner->scanOnePage($folderRun, $reader);
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $this->finish($run, $reader);

            $this->assertSame(2, $folderRun->fresh()->observed_count, 'cycle '.$cycle);
            $this->assertSame(2, $run->fresh()->observed_count, 'cycle '.$cycle);
            $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->fresh()->status);
            $this->assertSame(0, $run->items()->count(), 'cycle '.$cycle);
            $this->assertSame($run->id, $placement->fresh()->last_provider_reconciliation_run_id);
            $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        }

        $this->assertSame(0, EmailProviderReconciliationItem::query()->count());
    }

    #[Test]
    public function condstore_metadata_requires_per_message_modseq_while_ordinary_imap_does_not(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-modseq-required@example.test', 806, 2);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 1);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(806, 2, 1, true, 20),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([
                new EmailProviderReconciliationMessageMetadata(1, null, true, false, false, false, false),
            ], completeThroughUid: 1),
        ];
        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);

        try {
            $scanner->scanOnePage($folderRun->fresh(), $reader);
            $this->fail('CONDSTORE metadata without MODSEQ must fail closed.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_metadata_modseq_missing', $exception->safeCode);
        }

        $this->assertFalse($placement->fresh()->provider_seen);
        $this->assertSame(0, $folderRun->fresh()->observed_count);
    }

    #[Test]
    public function placement_version_change_during_scan_blocks_negative_evidence(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('reconcile-placement-drift@example.test', 807, 1);
        [, $placement] = $this->messageAndPlacement($account, $folder, $namespace, 44);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(807, 1, 0, false, null),
            new EmailProviderReconciliationFolderState(807, 1, 0, false, null),
        ]);
        $reader->metadataPages[$folder->path] = [
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
            new EmailProviderReconciliationMetadataPage([], terminal: true, completeThroughUid: 0),
        ];
        $folderRun = $this->discover($run, $reader);
        $scanner = app(EmailProviderReconciliationScanner::class);
        $scanner->scanOnePage($folderRun, $reader);

        $placement->forceFill(['sync_version' => 2])->save();
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $scanner->scanOnePage($folderRun->fresh(), $reader);
        $this->finish($run, $reader);

        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_STALE, $run->fresh()->status);
        $this->assertSame('placement_version_drift', $folderRun->fresh()->reason_code);
    }

    #[Test]
    public function cancellation_wakes_the_finalizer_and_releases_the_active_slot(): void
    {
        Queue::fake();
        $account = $this->account('reconcile-cancel@example.test');
        $run = $this->reconciliationRun($account);
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_SCAN,
            'started_at' => now(),
        ])->save();

        $cancelled = app(CancelEmailProviderReconciliation::class)->handle($account, $run);

        $this->assertSame(EmailProviderReconciliationRun::STATUS_RUNNING, $cancelled->status);
        $this->assertNotNull($cancelled->cancellation_requested_at);
        $this->assertFalse($cancelled->cancellable());
        Queue::assertPushed(
            TransitionEmailProviderReconciliationCancellation::class,
            fn (TransitionEmailProviderReconciliationCancellation $job): bool => $job->runId
                === $run->id,
        );
        $this->assertTrue(
            app(EmailProviderReconciliationCancellationTransition::class)->transition($run->id),
        );
        $this->assertSame(
            EmailProviderReconciliationRun::STATUS_CANCELLING,
            $run->fresh()->status,
        );
        app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $run->fresh(),
            new FakeEmailProviderReconciliationReader,
        );
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertNull($run->fresh()->active_slot);
    }

    #[Test]
    public function terminal_folder_failure_recovers_pending_and_abandoned_imports_without_provider_io(): void
    {
        Queue::fake();
        [$account, $folder, $namespace] = $this->mailbox('reconcile-job-failure@example.test', 808, 10);
        $run = $this->reconciliationRun($account);
        $reader = $this->readerFor($folder, [
            new EmailProviderReconciliationFolderState(808, 10, 0, false, null),
        ]);
        $folderRun = $this->discover($run, $reader);
        $item = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_RUNNING,
        ]);
        $pending = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 8,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PENDING,
        ]);

        (new ReconcileEmailProviderFolderBatch($folderRun->id))->failed(new RuntimeException('scan failed'));

        $this->assertSame(EmailProviderReconciliationFolder::STATUS_FAILED, $folderRun->fresh()->status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_RUNNING, $item->fresh()->status);
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PENDING, $pending->fresh()->status);
        (new FinalizeEmailProviderReconciliation($run->id))->handle(
            app(EmailProviderReconciliationCancellationTransition::class),
            app(EmailProviderReconciliationFinalizer::class),
            $reader,
        );
        $recoveries = Queue::pushed(ImportEmailProviderReconciliationItem::class)
            ->filter(fn (ImportEmailProviderReconciliationItem $job): bool => in_array(
                $job->itemId,
                [$item->id, $pending->id],
                true,
            ));
        $this->assertCount(2, $recoveries);

        $recoveryReader = new FakeEmailProviderReconciliationReader;
        $store = new FakeEmailProviderReconciliationMessageStore;
        foreach ([$item, $pending] as $recoverable) {
            (new ImportEmailProviderReconciliationItem($recoverable->id))->handle(
                app(EmailProviderReconciliationImporter::class),
                $recoveryReader,
                $store,
            );
            $this->assertSame(
                EmailProviderReconciliationItem::STATUS_FAILED,
                $recoverable->fresh()->status,
            );
            $this->assertSame('provider_import_folder_failed', $recoverable->fresh()->error_code);
        }
        $this->assertSame([], $recoveryReader->calls);
        $this->assertSame([], $store->calls);
        $this->finish($run, $reader);
        $this->assertSame(EmailProviderReconciliationRun::STATUS_PARTIAL, $run->fresh()->status);
        $this->assertNull($run->fresh()->active_slot);
    }

    #[Test]
    public function manual_runs_are_distinct_while_idle_and_explicit_dispatch_are_deduplicated_or_forced_as_intended(): void
    {
        Queue::fake();
        $account = $this->account('reconcile-idempotency@example.test');
        $start = app(StartEmailProviderReconciliation::class);

        $manualOne = $start->handle(
            $account,
            EmailProviderReconciliationRun::TRIGGER_MANUAL,
            dispatch: false,
        );
        $resumed = $start->handle(
            $account,
            EmailProviderReconciliationRun::TRIGGER_SCHEDULED,
        );
        $this->assertSame($manualOne->id, $resumed->id);
        Queue::assertPushed(
            ReconcileEmailProviderAccount::class,
            fn (ReconcileEmailProviderAccount $job): bool => $job->runId === $manualOne->id,
        );
        $this->finishRunRecord($manualOne);
        $manualTwo = $start->handle(
            $account,
            EmailProviderReconciliationRun::TRIGGER_MANUAL,
            dispatch: false,
        );
        $this->assertNotSame($manualOne->id, $manualTwo->id);
        $this->finishRunRecord($manualTwo);

        $idleOne = $start->handle(
            $account,
            EmailProviderReconciliationRun::TRIGGER_IDLE,
            dispatch: false,
        );
        $this->finishRunRecord($idleOne);
        $idleDuplicate = $start->handle(
            $account,
            EmailProviderReconciliationRun::TRIGGER_IDLE,
            dispatch: false,
        );
        $this->assertSame($idleOne->id, $idleDuplicate->id);

        $explicit = $this->account('reconcile-explicit-catchup@example.test');
        $recent = $this->reconciliationRun($explicit);
        $this->finishRunRecord($recent);
        app()->call([(new DispatchEmailProviderReconciliation($explicit->id)), 'handle']);

        $catchup = EmailProviderReconciliationRun::query()
            ->where('account_id', $explicit->id)
            ->where('active_slot', 1)
            ->firstOrFail();
        $this->assertSame(EmailProviderReconciliationRun::TRIGGER_CATCHUP, $catchup->trigger);
        Queue::assertPushed(
            ReconcileEmailProviderAccount::class,
            fn (ReconcileEmailProviderAccount $job): bool => $job->runId === $catchup->id,
        );
    }

    #[Test]
    public function all_account_dispatch_skips_a_paused_account_without_suppressing_later_accounts(): void
    {
        Queue::fake();
        $paused = $this->account('reconcile-dispatch-paused@example.test');
        $paused->forceFill(['provider_runtime_paused_at' => now()])->save();
        $eligible = $this->account('reconcile-dispatch-later@example.test');

        app()->call([(new DispatchEmailProviderReconciliation), 'handle']);

        $this->assertDatabaseMissing('email_provider_reconciliation_runs', [
            'account_id' => $paused->id,
        ]);
        $run = EmailProviderReconciliationRun::query()
            ->where('account_id', $eligible->id)
            ->firstOrFail();
        $this->assertSame(EmailProviderReconciliationRun::TRIGGER_SCHEDULED, $run->trigger);
        Queue::assertPushed(
            ReconcileEmailProviderAccount::class,
            fn (ReconcileEmailProviderAccount $job): bool => $job->runId === $run->id,
        );
    }

    #[Test]
    public function all_account_dispatch_advances_one_serialized_bounded_page_without_starvation(): void
    {
        Queue::fake();
        $accounts = collect(range(1, DispatchEmailProviderReconciliation::ACCOUNT_PAGE_SIZE + 2))
            ->map(fn (int $number): EmailAccount => $this->account(
                "reconcile-dispatch-page-{$number}@example.test",
            ));

        $firstPage = new DispatchEmailProviderReconciliation;
        app()->call([$firstPage, 'handle']);

        $this->assertSame(
            DispatchEmailProviderReconciliation::ACCOUNT_PAGE_SIZE,
            EmailProviderReconciliationRun::query()->count(),
        );
        $lastFirstPageId = (int) $accounts
            ->take(DispatchEmailProviderReconciliation::ACCOUNT_PAGE_SIZE)
            ->last()
            ->id;
        $successor = Queue::pushed(DispatchEmailProviderReconciliation::class)
            ->first(fn (DispatchEmailProviderReconciliation $job): bool => $job->accountId === null
                && $job->afterAccountId === $lastFirstPageId);
        $this->assertInstanceOf(DispatchEmailProviderReconciliation::class, $successor);
        $this->assertStringEndsWith(
            ':after:'.$lastFirstPageId,
            $successor->uniqueId(),
        );
        $serializedSuccessor = unserialize(serialize($successor));
        $this->assertInstanceOf(DispatchEmailProviderReconciliation::class, $serializedSuccessor);
        $this->assertSame($lastFirstPageId, $serializedSuccessor->afterAccountId);

        // Redelivery of the first page models a hard loss before the successor
        // acknowledgement. It must not create duplicate run rows.
        app()->call([(new DispatchEmailProviderReconciliation), 'handle']);
        $this->assertSame(
            DispatchEmailProviderReconciliation::ACCOUNT_PAGE_SIZE,
            EmailProviderReconciliationRun::query()->count(),
        );

        app()->call([$successor, 'handle']);
        $this->assertSame($accounts->count(), EmailProviderReconciliationRun::query()->count());
        $this->assertDatabaseHas('email_provider_reconciliation_runs', [
            'account_id' => $accounts->last()->id,
            'trigger' => EmailProviderReconciliationRun::TRIGGER_SCHEDULED,
        ]);
        $this->assertCount(
            $accounts->count(),
            EmailProviderReconciliationRun::query()
                ->select('account_id')
                ->distinct()
                ->get(),
        );
    }

    #[Test]
    public function reconciliation_schema_rollback_refuses_timestamp_only_provider_observation_evidence(): void
    {
        [$account, $folder, $namespace] = $this->mailbox(
            'reconcile-down-observed-at@example.test',
            999,
            2,
        );
        [, $placement] = $this->messageAndPlacement(
            $account,
            $folder,
            $namespace,
            1,
        );
        $placement->forceFill([
            'last_provider_reconciliation_run_id' => null,
            'last_provider_observed_sync_version' => null,
            'last_provider_observed_identity_hash' => null,
            'last_provider_observed_at' => now(),
        ])->save();
        $migration = require database_path(
            'migrations/2026_08_16_118000_add_email_provider_reconciliation.php',
        );

        try {
            $migration->down();
            $this->fail('Timestamp-only provider evidence must block reconciliation schema rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Provider reconciliation evidence must be preserved before schema rollback.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn(
            'email_mailbox_placements',
            'last_provider_observed_at',
        ));
    }

    private function finishRunRecord(EmailProviderReconciliationRun $run): void
    {
        $run->forceFill([
            'status' => EmailProviderReconciliationRun::STATUS_COMPLETED,
            'active_slot' => null,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => now(),
        ])->save();
    }

    private function finish(
        EmailProviderReconciliationRun $run,
        FakeEmailProviderReconciliationReader $reader,
    ): void {
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        foreach (range(1, 20) as $_) {
            if ($finalizer->finalizeOneStep($run->fresh(), $reader)) {
                return;
            }
        }

        $this->fail('The bounded reconciliation finalizer did not reach a terminal state.');
    }

    private function discover(
        EmailProviderReconciliationRun $run,
        FakeEmailProviderReconciliationReader $reader,
    ): EmailProviderReconciliationFolder {
        $ids = app(EmailProviderReconciliationCoordinator::class)->discoverStart($run, $reader);
        $this->assertCount(1, $ids);

        return EmailProviderReconciliationFolder::query()->findOrFail($ids[0]);
    }

    /** @param array<int, EmailProviderReconciliationFolderState> $states */
    private function readerFor(EmailFolder $folder, array $states): FakeEmailProviderReconciliationReader
    {
        $reader = new FakeEmailProviderReconciliationReader;
        $reader->folders = [new EmailProviderReconciliationFolderDescriptor(
            path: $folder->path,
            name: $folder->name,
            delimiter: '/',
            specialUse: '\\Inbox',
        )];
        $reader->folderStates[$folder->path] = $states;

        return $reader;
    }

    private function reconciliationRun(EmailAccount $account): EmailProviderReconciliationRun
    {
        return EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'test-run:'.$account->id.':'.microtime(true)),
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
        int $uidValidity,
        int $uidNext,
    ): array {
        $account = $this->account($address);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'INBOX',
            'name' => 'INBOX',
            'delimiter' => '/',
            'role' => EmailFolder::ROLE_INBOX,
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
    ): array {
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid' => $uid,
            'imap_uid_validity' => $namespace->uid_validity,
            'message_id' => "<reconciliation-{$account->id}-{$uid}@example.test>",
            'subject' => 'Provider reconciliation test',
            'from_email' => 'sender@example.test',
            'received_at' => now()->subHour()->startOfSecond(),
            'size_bytes' => 4096,
            'state' => 'untriaged',
            'body_text' => 'Body must remain untouched.',
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
            'last_reconciled_at' => now(),
        ]);

        return [$message, $placement];
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Provider reconciliation test',
            'from_name' => 'Nexum Reconciliation',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
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
}
