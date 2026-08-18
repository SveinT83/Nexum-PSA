<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Jobs\FinalizeEmailProviderReconciliation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Tests\Fakes\FakeEmailProviderReconciliationReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailProviderReconciliationFinalSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_folder_item_summary_is_bounded_and_resumes_exactly_after_a_rolled_back_page(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('folder-page');
        $run = $this->reconciliationRun($account);
        $folderRun = $this->waitingFolderRun($run, $folder, $namespace);
        $this->insertItems($run, $folderRun, $namespace, 205, function (int $number): array {
            if ($number <= 80) {
                return [
                    EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
                    EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
                ];
            }
            if ($number <= 140) {
                return [
                    EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
                    EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
                ];
            }
            if ($number <= 180) {
                return [
                    EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
                    EmailProviderReconciliationItem::STATUS_CONFLICT,
                ];
            }

            return [
                EmailProviderReconciliationItem::KIND_OBSERVATION,
                EmailProviderReconciliationItem::STATUS_PROJECTED,
            ];
        });

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;

        // Initialization freezes the item high-water but consumes no page.
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $folderRun->refresh();
        $this->assertSame(EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING, $folderRun->item_summary_status);
        $this->assertSame(205, $folderRun->item_summary_through_id);
        $this->assertSame(0, $folderRun->item_summary_cursor_id);
        $this->assertSame(0, $folderRun->item_summary_batch_count);

        // Model a worker loss after the page read but before its transaction
        // commits. The cursor and every accumulator must roll back together.
        $failOnce = true;
        EmailProviderReconciliationFolder::saving(
            function (EmailProviderReconciliationFolder $saving) use (&$failOnce, $folderRun): void {
                if ($failOnce
                    && (int) $saving->id === (int) $folderRun->id
                    && (int) $saving->item_summary_batch_count === 1) {
                    $failOnce = false;

                    throw new RuntimeException('simulated_summary_page_worker_loss');
                }
            },
        );

        try {
            $finalizer->finalizeOneStep($run, $reader);
            $this->fail('The simulated page loss did not abort the summary transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated_summary_page_worker_loss', $exception->getMessage());
        }

        $folderRun->refresh();
        $this->assertSame(0, $folderRun->item_summary_cursor_id);
        $this->assertSame(0, $folderRun->item_summary_missing_count);
        $this->assertSame(0, $folderRun->item_summary_move_count);
        $this->assertSame(0, $folderRun->item_summary_conflict_count);
        $this->assertSame(0, $folderRun->item_summary_batch_count);

        $queries = $this->recordQueries(
            fn (): bool => $finalizer->finalizeOneStep($run, $reader),
        );
        $this->assertSingleBoundedQuery($queries, 'historical_baseline_required');

        $folderRun->refresh();
        $this->assertSame(100, $folderRun->item_summary_cursor_id);
        $this->assertSame(80, $folderRun->item_summary_missing_count);
        $this->assertSame(20, $folderRun->item_summary_move_count);
        $this->assertSame(0, $folderRun->item_summary_conflict_count);
        $this->assertSame(1, $folderRun->item_summary_batch_count);

        // Redelivery advances from the durable cursor; no page is recounted.
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $folderRun->refresh();
        $this->assertSame(EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED, $folderRun->item_summary_status);
        $this->assertSame(205, $folderRun->item_summary_cursor_id);
        $this->assertSame(80, $folderRun->item_summary_missing_count);
        $this->assertSame(60, $folderRun->item_summary_move_count);
        $this->assertSame(40, $folderRun->item_summary_conflict_count);
        $this->assertSame(3, $folderRun->item_summary_batch_count);

        // Publication is a separate invocation from the last item page.
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $folderRun->refresh();
        $this->assertSame(EmailProviderReconciliationFolder::STATUS_COMPLETE, $folderRun->status);
        $this->assertSame(80, $folderRun->missing_count);
        $this->assertSame(40, $folderRun->conflict_count);
        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function run_summary_consumes_one_hundred_row_pages_and_publishes_exact_counters(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('run-pages');
        $run = $this->reconciliationRun($account);
        $folderIds = $this->insertTerminalFolders($run, $folder, $namespace, 101);
        $folderRun = EmailProviderReconciliationFolder::query()->findOrFail($folderIds[0]);
        $this->insertItems($run, $folderRun, $namespace, 101, function (int $number): array {
            if ($number <= 30) {
                return [
                    EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
                    EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
                ];
            }
            if ($number <= 50) {
                return [
                    EmailProviderReconciliationItem::KIND_MOVE_CANDIDATE,
                    EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
                ];
            }
            if ($number <= 60) {
                return [
                    EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
                    EmailProviderReconciliationItem::STATUS_CONFLICT,
                ];
            }
            if ($number === 61) {
                return [
                    EmailProviderReconciliationItem::KIND_OBSERVATION,
                    EmailProviderReconciliationItem::STATUS_FAILED,
                ];
            }

            return [
                EmailProviderReconciliationItem::KIND_OBSERVATION,
                EmailProviderReconciliationItem::STATUS_PROJECTED,
            ];
        });
        $this->sealFoldersComplete(
            $folderIds,
            firstFolderItemThroughId: (int) $run->items()->max('id'),
            firstFolderMissingCount: 30,
            firstFolderMoveCount: 20,
            firstFolderConflictCount: 10,
            firstFolderBatchCount: 2,
        );
        $run->forceFill(['folder_count' => 101])->save();

        $this->assertSqliteIndex('email_provider_reconciliation_folders', 'em_recon_folder_run_cursor_ix');
        $this->assertSqliteIndex('email_provider_reconciliation_folders', 'em_recon_folder_item_summary_ix');
        $this->assertSqliteIndex('email_provider_reconciliation_items', 'em_recon_item_run_cursor_ix');
        $this->assertSqliteIndex('email_provider_reconciliation_items', 'em_recon_item_folder_cursor_ix');

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;

        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::PHASE_SUMMARY, $run->phase);
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS, $run->final_summary_status);
        $this->assertSame($folderIds[100], $run->final_summary_folder_through_id);
        $this->assertSame(1, $run->active_slot);

        $folderQueries = $this->recordQueries(
            fn (): bool => $finalizer->finalizeOneStep($run, $reader),
        );
        $this->assertSingleBoundedQuery($folderQueries, 'select "id", "status"');
        $run->refresh();
        $this->assertSame($folderIds[99], $run->final_summary_folder_cursor_id);
        $this->assertSame(100, $run->final_summary_complete_folder_count);
        $this->assertSame(1, $run->final_summary_batch_count);
        $this->assertSame(1, $run->active_slot);

        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_ITEMS, $run->final_summary_status);
        $this->assertSame(101, $run->final_summary_complete_folder_count);
        $this->assertSame(2, $run->final_summary_batch_count);
        $this->assertSame(1, $run->active_slot);

        $itemQueries = $this->recordQueries(
            fn (): bool => $finalizer->finalizeOneStep($run, $reader),
        );
        $this->assertSingleBoundedQuery($itemQueries, 'automation_required');
        $run->refresh();
        $this->assertSame(100, $run->final_summary_item_cursor_id);
        $this->assertSame(3, $run->final_summary_batch_count);

        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED, $run->final_summary_status);
        $this->assertSame(30, $run->final_summary_missing_count);
        $this->assertSame(20, $run->final_summary_move_count);
        $this->assertSame(10, $run->final_summary_conflict_count);
        $this->assertSame(1, $run->final_summary_error_count);
        $this->assertTrue($run->final_summary_failed);
        $this->assertSame(4, $run->final_summary_batch_count);
        $this->assertSame(1, $run->active_slot);

        $this->assertTrue($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_PARTIAL, $run->status);
        $this->assertNull($run->active_slot);
        $this->assertSame(101, $run->complete_folder_count);
        $this->assertSame(30, $run->missing_count);
        $this->assertSame(20, $run->move_count);
        $this->assertSame(10, $run->conflict_count);
        $this->assertSame(1, $run->error_count);
        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function local_only_stale_and_blocked_folders_never_inflate_the_complete_folder_count(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('folder-outcomes');
        $run = $this->reconciliationRun($account);
        $folderIds = $this->insertTerminalFolders($run, $folder, $namespace, 3);
        EmailProviderReconciliationFolder::query()->whereKey($folderIds[0])->update([
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY,
            'status' => EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE,
        ]);
        EmailProviderReconciliationFolder::query()->whereKey($folderIds[1])->update([
            'status' => EmailProviderReconciliationFolder::STATUS_STALE,
        ]);
        EmailProviderReconciliationFolder::query()->whereKey($folderIds[2])->update([
            'status' => EmailProviderReconciliationFolder::STATUS_BLOCKED,
        ]);
        $run->forceFill(['folder_count' => 3])->save();

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED, $run->final_summary_status);
        $this->assertSame(0, $run->final_summary_complete_folder_count);
        $this->assertSame(1, $run->final_summary_conflict_count);
        $this->assertTrue($run->final_summary_blocked);
        $this->assertTrue($run->final_summary_stale);
        $this->assertSame(1, $run->active_slot);

        $this->assertTrue($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_BLOCKED, $run->status);
        $this->assertSame(0, $run->complete_folder_count);
        $this->assertNull($run->active_slot);
        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function sealed_summary_publishes_the_exact_status_precedence_and_public_counters(): void
    {
        $cases = [
            'blocked_beats_failed' => [
                EmailProviderReconciliationRun::STATUS_BLOCKED,
                ['final_summary_blocked' => true, 'final_summary_failed' => true,
                    'final_summary_conflict_count' => 2, 'final_summary_error_count' => 1],
            ],
            'failed_beats_stale' => [
                EmailProviderReconciliationRun::STATUS_PARTIAL,
                ['final_summary_failed' => true, 'final_summary_stale' => true,
                    'final_summary_conflict_count' => 2, 'final_summary_error_count' => 1],
            ],
            'stale_beats_conflict' => [
                EmailProviderReconciliationRun::STATUS_STALE,
                ['final_summary_stale' => true, 'final_summary_conflict_count' => 2],
            ],
            'conflict_beats_complete' => [
                EmailProviderReconciliationRun::STATUS_COMPLETED_WITH_CONFLICTS,
                ['final_summary_conflict_count' => 2],
            ],
            'clean_is_complete' => [EmailProviderReconciliationRun::STATUS_COMPLETED, []],
        ];

        foreach ($cases as $label => [$expectedStatus, $summary]) {
            [$account] = $this->mailbox('precedence-'.$label);
            $run = $this->reconciliationRun($account);
            $run->forceFill([
                'phase' => EmailProviderReconciliationRun::PHASE_SUMMARY,
                'final_summary_status' => EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED,
                'final_summary_complete_folder_count' => 7,
                'final_summary_missing_count' => 3,
                'final_summary_move_count' => 2,
                'final_summary_started_at' => now()->subSecond(),
                'final_summary_completed_at' => now(),
                ...$summary,
            ])->save();

            $reader = new FakeEmailProviderReconciliationReader;
            $this->assertTrue(
                app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep($run, $reader),
                $label,
            );
            $run->refresh();
            $this->assertSame($expectedStatus, $run->status, $label);
            $this->assertNull($run->active_slot, $label);
            $this->assertSame(7, $run->complete_folder_count, $label);
            $this->assertSame(3, $run->missing_count, $label);
            $this->assertSame(2, $run->move_count, $label);
            $this->assertSame((int) ($summary['final_summary_conflict_count'] ?? 0), $run->conflict_count, $label);
            $this->assertSame((int) ($summary['final_summary_error_count'] ?? 0), $run->error_count, $label);
            $this->assertSame([], $reader->calls, $label);
        }
    }

    #[Test]
    public function cancellation_intent_between_summary_pages_prevents_publication_and_resets_before_child_drain(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('cancel-pages');
        $run = $this->reconciliationRun($account);
        $folderIds = $this->insertTerminalFolders($run, $folder, $namespace, 101);
        $this->sealFoldersComplete(
            $folderIds,
            firstFolderItemThroughId: 0,
            firstFolderMissingCount: 0,
            firstFolderMoveCount: 0,
            firstFolderConflictCount: 0,
            firstFolderBatchCount: 0,
        );
        $run->forceFill(['folder_count' => 101])->save();
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;

        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame($folderIds[99], $run->final_summary_folder_cursor_id);
        $this->assertSame(100, $run->final_summary_complete_folder_count);
        $this->assertSame(1, $run->final_summary_batch_count);
        $beforeIntent = $run->only([
            'status',
            'phase',
            'active_slot',
            'final_summary_status',
            'final_summary_folder_through_id',
            'final_summary_folder_cursor_id',
            'final_summary_complete_folder_count',
            'final_summary_batch_count',
        ]);

        $run->forceFill(['cancellation_requested_at' => now()])->save();
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $this->assertSame($beforeIntent, $run->fresh()->only(array_keys($beforeIntent)));

        $this->assertTrue(app(EmailProviderReconciliationCancellationTransition::class)->transition($run->id));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLING, $run->status);
        $this->assertSame(EmailProviderReconciliationRun::PHASE_DISCOVER_END, $run->phase);
        $this->assertNull($run->final_summary_status);
        $this->assertSame(0, $run->final_summary_folder_cursor_id);
        $this->assertSame(0, $run->final_summary_complete_folder_count);

        $this->assertTrue($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_CANCELLED, $run->status);
        $this->assertNull($run->active_slot);
        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function frozen_folder_and_run_summaries_reject_delayed_child_writers(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('writer-fences');
        $run = $this->reconciliationRun($account);
        $folderRun = $this->waitingFolderRun($run, $folder, $namespace);
        $this->insertItems(
            $run,
            $folderRun,
            $namespace,
            1,
            fn (): array => [
                EmailProviderReconciliationItem::KIND_OBSERVATION,
                EmailProviderReconciliationItem::STATUS_PROJECTED,
            ],
        );
        $item = $run->items()->firstOrFail();

        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $run,
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(
            EmailProviderReconciliationFolder::ITEM_SUMMARY_RUNNING,
            $folderRun->fresh()->item_summary_status,
        );

        $this->assertQueryRejected(
            fn (): int => DB::table('email_provider_reconciliation_items')
                ->where('id', $item->id)
                ->update(['status' => EmailProviderReconciliationItem::STATUS_CONFLICT]),
            'folder_item_summary_is_sealed',
        );
        $this->assertQueryRejected(
            fn (): bool => DB::table('email_provider_reconciliation_items')->insert([
                ...$this->itemRow(
                    $run,
                    $folderRun,
                    $namespace,
                    2,
                    EmailProviderReconciliationItem::KIND_OBSERVATION,
                    EmailProviderReconciliationItem::STATUS_PROJECTED,
                ),
            ]),
            'folder_item_summary_is_sealed',
        );
        $this->assertSame(EmailProviderReconciliationItem::STATUS_PROJECTED, $item->fresh()->status);

        [$summaryAccount, $summaryFolder, $summaryNamespace] = $this->mailbox('run-writer-fences');
        $summaryRun = $this->reconciliationRun($summaryAccount);
        $summaryFolderIds = $this->insertTerminalFolders(
            $summaryRun,
            $summaryFolder,
            $summaryNamespace,
            1,
        );
        $summaryFolderRun = EmailProviderReconciliationFolder::query()->findOrFail($summaryFolderIds[0]);
        $this->insertItems(
            $summaryRun,
            $summaryFolderRun,
            $summaryNamespace,
            1,
            fn (): array => [
                EmailProviderReconciliationItem::KIND_OBSERVATION,
                EmailProviderReconciliationItem::STATUS_PROJECTED,
            ],
        );
        $summaryItem = $summaryRun->items()->firstOrFail();
        $this->assertFalse(app(EmailProviderReconciliationFinalizer::class)->finalizeOneStep(
            $summaryRun,
            new FakeEmailProviderReconciliationReader,
        ));
        $this->assertSame(EmailProviderReconciliationRun::PHASE_SUMMARY, $summaryRun->fresh()->phase);

        $this->assertQueryRejected(
            fn (): int => DB::table('email_provider_reconciliation_folders')
                ->where('id', $summaryFolderRun->id)
                ->update(['reason_code' => 'delayed_writer']),
            'run_summary_is_sealed',
        );
        $this->assertQueryRejected(
            fn (): int => DB::table('email_provider_reconciliation_items')
                ->where('id', $summaryItem->id)
                ->update(['status' => EmailProviderReconciliationItem::STATUS_CONFLICT]),
            'run_summary_is_sealed',
        );
        $this->assertQueryRejected(
            fn (): bool => DB::table('email_provider_reconciliation_items')->insert([
                ...$this->itemRow(
                    $summaryRun,
                    $summaryFolderRun,
                    $summaryNamespace,
                    2,
                    EmailProviderReconciliationItem::KIND_OBSERVATION,
                    EmailProviderReconciliationItem::STATUS_PROJECTED,
                ),
            ]),
            'run_summary_is_sealed',
        );
    }

    #[Test]
    public function evidence_beyond_a_sealed_high_water_reopens_and_finishes_stale_without_early_slot_release(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('late-evidence');
        $run = $this->reconciliationRun($account);
        $folderIds = $this->insertTerminalFolders($run, $folder, $namespace, 2);
        $firstFolder = EmailProviderReconciliationFolder::query()->findOrFail($folderIds[0]);
        $this->insertItems($run, $firstFolder, $namespace, 2, fn (int $number): array => $number === 1
            ? [EmailProviderReconciliationItem::KIND_ABSENCE_CANDIDATE,
                EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING]
            : [EmailProviderReconciliationItem::KIND_OPERATION_CONFLICT,
                EmailProviderReconciliationItem::STATUS_CONFLICT]);
        $itemIds = $run->items()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->sealFoldersComplete(
            $folderIds,
            firstFolderItemThroughId: $itemIds[1],
            firstFolderMissingCount: 1,
            firstFolderMoveCount: 0,
            firstFolderConflictCount: 1,
            firstFolderBatchCount: 1,
        );

        // Model evidence committed after a frozen high-water in an older
        // writer. Publication must detect it, expand, and mark the audit stale.
        $run->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_SUMMARY,
            'final_summary_status' => EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED,
            'final_summary_folder_through_id' => $folderIds[0],
            'final_summary_folder_cursor_id' => $folderIds[0],
            'final_summary_item_through_id' => $itemIds[0],
            'final_summary_item_cursor_id' => $itemIds[0],
            'final_summary_complete_folder_count' => 1,
            'final_summary_missing_count' => 1,
            'final_summary_batch_count' => 2,
            'final_summary_started_at' => now()->subSecond(),
            'final_summary_completed_at' => now(),
        ])->save();

        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_FOLDERS, $run->final_summary_status);
        $this->assertSame($folderIds[1], $run->final_summary_folder_through_id);
        $this->assertSame($itemIds[1], $run->final_summary_item_through_id);
        $this->assertTrue($run->final_summary_stale);
        $this->assertTrue($run->automation_scope_unsafe);
        $this->assertSame(1, $run->active_slot);

        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED, $run->final_summary_status);
        $this->assertSame(2, $run->final_summary_complete_folder_count);
        $this->assertSame(1, $run->final_summary_missing_count);
        $this->assertSame(1, $run->final_summary_conflict_count);
        $this->assertSame(1, $run->active_slot);

        $this->assertTrue($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_STALE, $run->status);
        $this->assertNull($run->active_slot);
        $this->assertSame(2, $run->complete_folder_count);
        $this->assertSame(1, $run->missing_count);
        $this->assertSame(1, $run->conflict_count);
        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function sqlite_summary_guards_reject_unknown_status_and_partial_resets_without_mutation(): void
    {
        [$account, $folder, $namespace] = $this->mailbox('guard-invalid');
        $run = $this->reconciliationRun($account);
        $folderRun = $this->waitingFolderRun($run, $folder, $namespace);
        $runBefore = $run->fresh()->getAttributes();
        $folderBefore = $folderRun->fresh()->getAttributes();

        $this->assertQueryRejected(
            fn (): int => DB::table('email_provider_reconciliation_runs')
                ->where('id', $run->id)
                ->update([
                    'phase' => EmailProviderReconciliationRun::PHASE_SUMMARY,
                    'final_summary_status' => 'future_status',
                    'final_summary_started_at' => now(),
                ]),
            'final_summary_contract_invalid',
        );
        $this->assertQueryRejected(
            fn (): int => DB::table('email_provider_reconciliation_runs')
                ->where('id', $run->id)
                ->update(['final_summary_item_cursor_id' => 1]),
            'final_summary_contract_invalid',
        );
        $this->assertQueryRejected(
            fn (): int => DB::table('email_provider_reconciliation_folders')
                ->where('id', $folderRun->id)
                ->update([
                    'item_summary_status' => 'future_status',
                    'item_summary_started_at' => now(),
                ]),
            'folder_item_summary_contract_invalid',
        );

        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame($folderBefore, $folderRun->fresh()->getAttributes());
    }

    #[Test]
    public function empty_run_seals_before_publishing_and_never_releases_its_slot_early(): void
    {
        [$account] = $this->mailbox('empty');
        $run = $this->reconciliationRun($account);
        $finalizer = app(EmailProviderReconciliationFinalizer::class);
        $reader = new FakeEmailProviderReconciliationReader;

        $this->assertFalse($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(EmailProviderReconciliationRun::PHASE_SUMMARY, $run->phase);
        $this->assertSame(EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED, $run->final_summary_status);
        $this->assertSame(0, $run->final_summary_batch_count);
        $this->assertSame(1, $run->active_slot);
        $this->assertNull($run->finished_at);

        $this->assertTrue($finalizer->finalizeOneStep($run, $reader));
        $run->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->active_slot);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function failed_callback_terminalizes_scan_and_summary_but_is_a_no_op_for_unowned_phases(): void
    {
        [$scanAccount] = $this->mailbox('failed-scan');
        $scanRun = $this->reconciliationRun($scanAccount);
        $scanRun->forceFill(['phase' => EmailProviderReconciliationRun::PHASE_SCAN])->save();
        (new FinalizeEmailProviderReconciliation($scanRun->id))->failed(
            new RuntimeException('provider-content-must-not-be-persisted'),
        );
        $scanRun->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_FAILED, $scanRun->status);
        $this->assertNull($scanRun->active_slot);
        $this->assertSame('provider_finalization_failed', $scanRun->failure_code);

        [$summaryAccount] = $this->mailbox('failed-summary');
        $summaryRun = $this->reconciliationRun($summaryAccount);
        $summaryRun->forceFill([
            'phase' => EmailProviderReconciliationRun::PHASE_SUMMARY,
            'final_summary_status' => EmailProviderReconciliationRun::FINAL_SUMMARY_SEALED,
            'final_summary_started_at' => now()->subSecond(),
            'final_summary_completed_at' => now(),
        ])->save();
        (new FinalizeEmailProviderReconciliation($summaryRun->id))->failed(
            new RuntimeException('provider-content-must-not-be-persisted'),
        );
        $summaryRun->refresh();
        $this->assertSame(EmailProviderReconciliationRun::STATUS_FAILED, $summaryRun->status);
        $this->assertNull($summaryRun->active_slot);
        $this->assertNull($summaryRun->final_summary_status);
        $this->assertSame(0, $summaryRun->final_summary_batch_count);

        [$unownedAccount] = $this->mailbox('failed-unowned');
        $unowned = $this->reconciliationRun($unownedAccount);
        $unowned->forceFill(['phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START])->save();
        $before = $unowned->fresh()->getAttributes();
        (new FinalizeEmailProviderReconciliation($unowned->id))->failed(
            new RuntimeException('provider-content-must-not-be-persisted'),
        );
        $this->assertSame($before, $unowned->fresh()->getAttributes());
    }

    /** @return array{EmailAccount, EmailFolder, EmailFolderUidNamespace} */
    private function mailbox(string $key): array
    {
        $address = 'summary-'.hash('crc32b', $key).'-'.uniqid().'@example.test';
        $account = EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Reconciliation Summary',
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
        $path = 'Projects/Summary-'.$account->id;
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => 'Summary '.$account->id,
            'delimiter' => '/',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1000 + $account->id,
            'uid_next' => 1000,
            'live_start_uid' => 1,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $folder->uid_validity,
            'uid_next_at_establishment' => 1000,
            'live_start_uid' => 1,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return [$account, $folder->fresh(), $namespace];
    }

    private function reconciliationRun(EmailAccount $account): EmailProviderReconciliationRun
    {
        $snapshotAt = now()->subMinute();
        $scopeHash = hash('sha256', 'summary-scope:'.$account->id);

        return EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_RUNNING,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'summary-run:'.$account->id.':'.uniqid('', true)),
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
            'max_folders' => 1000,
            'uid_batch_size' => 100,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => $snapshotAt,
            'started_at' => $snapshotAt,
        ]);
    }

    private function waitingFolderRun(
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
            'end_uid_validity' => $namespace->uid_validity,
            'start_uid_next' => 1000,
            'end_uid_next' => 1000,
            'start_exists_count' => 0,
            'end_exists_count' => 0,
            'supports_modseq' => true,
            'end_supports_modseq' => true,
            'scan_through_uid' => 999,
            'next_uid' => 1000,
            'baseline_max_placement_id' => 0,
            'baseline_placement_count' => 0,
            'placement_baseline_hash' => hash('sha256', ''),
            'placement_scan_hash' => hash('sha256', ''),
            'reason_code' => 'stable_absence_projection',
        ]);
    }

    /** @return array<int, int> */
    private function insertTerminalFolders(
        EmailProviderReconciliationRun $run,
        EmailFolder $folder,
        EmailFolderUidNamespace $namespace,
        int $count,
    ): array {
        $now = now();
        $rows = [];
        foreach (range(1, $count) as $number) {
            $rows[] = [
                'email_provider_reconciliation_run_id' => $run->id,
                'account_id' => $run->account_id,
                'email_folder_id' => $folder->id,
                'uid_namespace_id' => $namespace->id,
                'folder_path' => $folder->path.'/Terminal-'.$number,
                'folder_name' => 'Terminal '.$number,
                'delimiter' => '/',
                'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
                'status' => EmailProviderReconciliationFolder::STATUS_MISSING_CANDIDATE,
                'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
                'reason_code' => 'summary_fixture_terminal',
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('email_provider_reconciliation_folders')->insert($chunk);
        }

        return EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function sealFoldersComplete(
        array $folderIds,
        int $firstFolderItemThroughId,
        int $firstFolderMissingCount,
        int $firstFolderMoveCount,
        int $firstFolderConflictCount,
        int $firstFolderBatchCount,
    ): void {
        $now = now();
        EmailProviderReconciliationFolder::query()->whereKey($folderIds[0])->update([
            'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
            'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            'item_summary_through_id' => $firstFolderItemThroughId,
            'item_summary_cursor_id' => $firstFolderItemThroughId,
            'item_summary_missing_count' => $firstFolderMissingCount,
            'item_summary_move_count' => $firstFolderMoveCount,
            'item_summary_conflict_count' => $firstFolderConflictCount,
            'item_summary_batch_count' => $firstFolderBatchCount,
            'item_summary_started_at' => $now,
            'item_summary_completed_at' => $now,
            'missing_count' => $firstFolderMissingCount,
            'conflict_count' => $firstFolderConflictCount,
            'updated_at' => $now,
        ]);
        if (count($folderIds) === 1) {
            return;
        }

        EmailProviderReconciliationFolder::query()->whereIn('id', array_slice($folderIds, 1))->update([
            'status' => EmailProviderReconciliationFolder::STATUS_COMPLETE,
            'item_summary_status' => EmailProviderReconciliationFolder::ITEM_SUMMARY_SEALED,
            'item_summary_started_at' => $now,
            'item_summary_completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  callable(int): array{string, string}|array{string, string, array<string, mixed>}  $outcome
     */
    private function insertItems(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailFolderUidNamespace $namespace,
        int $count,
        callable $outcome,
    ): void {
        $rows = [];
        foreach (range(1, $count) as $number) {
            [$kind, $status, $extra] = [...$outcome($number), []];
            $rows[] = [
                ...$this->itemRow($run, $folderRun, $namespace, $number, $kind, $status),
                ...$extra,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('email_provider_reconciliation_items')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function itemRow(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailFolderUidNamespace $namespace,
        int $uid,
        string $kind,
        string $status,
    ): array {
        $now = now();

        return [
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => $uid,
            'kind' => $kind,
            'status' => $status,
            'error_code' => $status === EmailProviderReconciliationItem::STATUS_FAILED
                ? 'summary_fixture_failed'
                : null,
            'completed_at' => $now,
            'historical_baseline_required' => false,
            'historical_baseline_status' => null,
            'historical_baseline_max_id' => 0,
            'historical_baseline_cursor_id' => 0,
            'historical_baseline_claim_token' => null,
            'historical_baseline_attempt_count' => 0,
            'historical_baseline_frozen_at' => null,
            'historical_baseline_first_attempt_at' => null,
            'historical_baseline_last_attempt_at' => null,
            'historical_baseline_completed_at' => null,
            'historical_baseline_error_code' => null,
            'automation_required' => false,
            'automation_status' => null,
            'automation_claim_token' => null,
            'automation_attempt_count' => 0,
            'automation_last_attempt_at' => null,
            'automation_completed_at' => null,
            'automation_error_code' => null,
            'automation_rule_attempt_floor_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<int, array{query: string, bindings: array<int, mixed>, time: float}> */
    private function recordQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }

    /** @param array<int, array{query: string, bindings: array<int, mixed>, time: float}> $queries */
    private function assertSingleBoundedQuery(array $queries, string $signature): void
    {
        $pageQueries = array_values(array_filter(
            $queries,
            fn (array $query): bool => str_contains($query['query'], $signature)
                && str_contains($query['query'], 'order by "id" asc'),
        ));
        $this->assertCount(1, $pageQueries);
        $this->assertMatchesRegularExpression('/\blimit 100\b/i', $pageQueries[0]['query']);
    }

    private function assertSqliteIndex(string $table, string $index): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $indexes = collect(DB::select("pragma index_list('{$table}')"))->pluck('name')->all();
        $this->assertContains($index, $indexes);
    }

    private function assertQueryRejected(callable $callback, string $safeCode): void
    {
        try {
            $callback();
            $this->fail("The database accepted an invalid summary write ({$safeCode}).");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($safeCode, $exception->getMessage());
        }
    }
}
