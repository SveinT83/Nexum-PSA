<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_provider_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->string('provider', 30)->default('imap');
            $table->string('trigger', 24);
            $table->string('status', 40)->default('queued');
            $table->string('phase', 32)->default('discover_start');

            // Only one non-null slot may exist per account. Terminal runs clear
            // the slot, while their durable audit and idempotency key remain.
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->char('idempotency_key', 64)->unique();

            // Provider-I/O work captures the Email binding before dispatch and
            // records the resolved runtime versions without retaining secrets,
            // endpoints, usernames, or provider responses.
            $table->unsignedInteger('provider_binding_version');
            $table->unsignedInteger('provider_configuration_version')->nullable();
            $table->unsignedInteger('provider_credential_version')->nullable();
            $table->char('provider_runtime_fingerprint', 64)->nullable();

            $table->char('start_folder_scope_hash', 64)->nullable();
            $table->char('end_folder_scope_hash', 64)->nullable();

            // Local folders can greatly outnumber the bounded provider LIST
            // scope after years of rename/delete churn. Freeze an account-ID
            // high-water and advance one durable page per queue invocation.
            $table->string('local_folder_snapshot_status', 16)->nullable();
            $table->unsignedBigInteger('local_folder_snapshot_through_id')->default(0);
            $table->unsignedBigInteger('local_folder_snapshot_cursor_id')->default(0);
            $table->unsignedBigInteger('local_folder_snapshot_count')->default(0);
            $table->char('local_folder_snapshot_hash', 64)->nullable();
            $table->unsignedInteger('local_folder_snapshot_batch_count')->default(0);
            $table->dateTime('local_folder_snapshot_started_at')->nullable();
            $table->dateTime('local_folder_snapshot_completed_at')->nullable();

            // This monotonic bit is materialized by every provider-evidence
            // writer. Correlation can then fail closed in O(1) instead of
            // rescanning a potentially multi-million-placement run for each
            // bounded automation page.
            $table->boolean('automation_scope_unsafe')->default(false);
            $table->string('automation_scope_error_code', 80)->nullable();
            $table->dateTime('automation_scope_unsafe_at')->nullable();

            // Final outcome aggregation also spans an unbounded local-folder
            // and item ledger. Freeze both high-waters and consume one
            // durable 100-row page per finalizer invocation before sealing.
            $table->string('final_summary_status', 16)->nullable();
            $table->unsignedBigInteger('final_summary_folder_through_id')->default(0);
            $table->unsignedBigInteger('final_summary_folder_cursor_id')->default(0);
            $table->unsignedBigInteger('final_summary_item_through_id')->default(0);
            $table->unsignedBigInteger('final_summary_item_cursor_id')->default(0);
            $table->unsignedInteger('final_summary_complete_folder_count')->default(0);
            $table->unsignedBigInteger('final_summary_missing_count')->default(0);
            $table->unsignedBigInteger('final_summary_move_count')->default(0);
            $table->unsignedBigInteger('final_summary_conflict_count')->default(0);
            $table->unsignedBigInteger('final_summary_error_count')->default(0);
            $table->boolean('final_summary_blocked')->default(false);
            $table->boolean('final_summary_failed')->default(false);
            $table->boolean('final_summary_stale')->default(false);
            $table->boolean('final_summary_automation_failed')->default(false);
            $table->unsignedInteger('final_summary_batch_count')->default(0);
            $table->dateTime('final_summary_started_at')->nullable();
            $table->dateTime('final_summary_completed_at')->nullable();

            $table->unsignedSmallInteger('max_folders');
            $table->unsignedSmallInteger('uid_batch_size');
            $table->unsignedSmallInteger('provider_time_cap_seconds');
            $table->unsignedInteger('normal_interval_seconds');

            $table->unsignedInteger('folder_count')->default(0);
            $table->unsignedInteger('complete_folder_count')->default(0);
            $table->unsignedBigInteger('batch_count')->default(0);
            $table->unsignedBigInteger('observed_count')->default(0);
            $table->unsignedBigInteger('import_count')->default(0);
            $table->unsignedBigInteger('flag_change_count')->default(0);
            $table->unsignedBigInteger('missing_count')->default(0);
            $table->unsignedBigInteger('move_count')->default(0);
            $table->unsignedBigInteger('conflict_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);

            $table->dateTime('queued_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_progress_at')->nullable();
            $table->dateTime('retry_at')->nullable();
            $table->dateTime('cancellation_requested_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('account_id', 'em_recon_run_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('requested_by', 'em_recon_run_requester_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('cancelled_by', 'em_recon_run_canceller_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique(['account_id', 'active_slot'], 'em_recon_run_active_uq');
            $table->index(['account_id', 'status', 'created_at'], 'em_recon_run_account_status_ix');
            $table->index(['status', 'retry_at'], 'em_recon_run_retry_ix');
        });

        Schema::create('email_provider_reconciliation_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_provider_reconciliation_run_id');
            $table->foreignId('account_id');
            $table->foreignId('email_folder_id')->nullable();
            $table->foreignId('uid_namespace_id')->nullable();
            $table->string('folder_path', 512);
            $table->string('folder_name', 255);
            $table->string('delimiter', 10)->nullable();
            $table->string('parent_path', 512)->nullable();
            $table->string('remote_id', 1024)->nullable();
            $table->string('special_use', 80)->nullable();
            $table->boolean('provider_selectable')->default(true);
            $table->boolean('provider_sync_enabled')->default(true);
            $table->string('discovery_state', 32);
            $table->string('status', 40)->default('pending');
            $table->string('import_policy', 32)->default('live');

            $table->unsignedBigInteger('expected_uid_validity')->nullable();
            $table->unsignedBigInteger('start_uid_validity')->nullable();
            $table->unsignedBigInteger('end_uid_validity')->nullable();
            $table->unsignedBigInteger('start_uid_next')->nullable();
            $table->unsignedBigInteger('end_uid_next')->nullable();
            $table->unsignedBigInteger('start_exists_count')->nullable();
            $table->unsignedBigInteger('end_exists_count')->nullable();
            $table->unsignedBigInteger('start_highest_modseq')->nullable();
            $table->unsignedBigInteger('end_highest_modseq')->nullable();
            // Start support is frozen in `supports_modseq`; end support is
            // recorded separately so a capability change also invalidates
            // negative evidence. Ordinary non-CONDSTORE IMAP remains valid.
            $table->boolean('supports_modseq')->default(false);
            $table->boolean('end_supports_modseq')->nullable();
            $table->unsignedBigInteger('scan_through_uid')->default(0);
            $table->unsignedBigInteger('next_uid')->default(1);

            // The scan hash covers only placements that existed when discovery
            // froze the baseline. Imports created by this run cannot hide a
            // concurrent local operation or bless a changed source placement.
            $table->unsignedBigInteger('baseline_max_placement_id')->default(0);
            $table->unsignedBigInteger('baseline_placement_count')->default(0);
            $table->char('placement_baseline_hash', 64)->nullable();
            $table->char('placement_scan_hash', 64)->nullable();

            // Placement snapshots may span many queue invocations. This
            // single purpose-owned cursor is reset between checkpoints and
            // advances at most one hard-capped row page per invocation.
            $table->string('placement_snapshot_purpose', 40)->nullable();
            $table->string('placement_snapshot_status', 16)->nullable();
            $table->unsignedBigInteger('placement_snapshot_through_id')->default(0);
            $table->unsignedBigInteger('placement_snapshot_cursor_id')->default(0);
            $table->unsignedBigInteger('placement_snapshot_count')->default(0);
            $table->char('placement_snapshot_hash', 64)->nullable();
            $table->unsignedInteger('placement_snapshot_batch_count')->default(0);
            $table->dateTime('placement_snapshot_started_at')->nullable();
            $table->dateTime('placement_snapshot_completed_at')->nullable();
            $table->char('inventory_hash', 64)->default(
                'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            );

            // A mailbox without per-folder MODSEQ needs two identical,
            // complete UID+FLAGS inventories before scan-time flags are
            // stable enough to project. This second cursor is independently
            // resumable and never creates imports or observations.
            $table->string('metadata_verification_status', 16)->nullable();
            $table->unsignedBigInteger('metadata_verification_next_uid')->default(1);
            $table->unsignedBigInteger('metadata_verification_count')->default(0);
            $table->char('metadata_verification_hash', 64)->nullable();
            $table->unsignedInteger('metadata_verification_batch_count')->default(0);
            $table->dateTime('metadata_verification_started_at')->nullable();
            $table->dateTime('metadata_verification_completed_at')->nullable();

            // Folder ledgers can span many UID windows. Seal their terminal
            // counters from one frozen, bounded item page at a time before
            // publishing the folder outcome to the run summary.
            $table->string('item_summary_status', 16)->nullable();
            $table->unsignedBigInteger('item_summary_through_id')->default(0);
            $table->unsignedBigInteger('item_summary_cursor_id')->default(0);
            $table->unsignedBigInteger('item_summary_missing_count')->default(0);
            $table->unsignedBigInteger('item_summary_move_count')->default(0);
            $table->unsignedBigInteger('item_summary_conflict_count')->default(0);
            $table->boolean('item_summary_nonterminal')->default(false);
            $table->unsignedInteger('item_summary_batch_count')->default(0);
            $table->dateTime('item_summary_started_at')->nullable();
            $table->dateTime('item_summary_completed_at')->nullable();

            $table->unsignedInteger('batch_count')->default(0);
            $table->unsignedBigInteger('observed_count')->default(0);
            $table->unsignedBigInteger('import_count')->default(0);
            $table->unsignedBigInteger('flag_change_count')->default(0);
            $table->unsignedBigInteger('missing_count')->default(0);
            $table->unsignedBigInteger('conflict_count')->default(0);
            $table->string('reason_code', 80)->nullable();
            $table->dateTime('scan_started_at')->nullable();
            $table->dateTime('last_progress_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_provider_reconciliation_run_id', 'em_recon_folder_run_fk')
                ->references('id')->on('email_provider_reconciliation_runs')->cascadeOnDelete();
            $table->foreign('account_id', 'em_recon_folder_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('email_folder_id', 'em_recon_folder_local_fk')
                ->references('id')->on('email_folders')->nullOnDelete();
            $table->foreign('uid_namespace_id', 'em_recon_folder_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->nullOnDelete();
            $table->unique(
                ['email_provider_reconciliation_run_id', 'folder_path'],
                'em_recon_folder_run_path_uq',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'status', 'id'],
                'em_recon_folder_run_status_ix',
            );
            $table->index(
                [
                    'email_provider_reconciliation_run_id',
                    'discovery_state',
                    'status',
                    'id',
                ],
                'em_recon_folder_scope_status_ix',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'id'],
                'em_recon_folder_run_cursor_ix',
            );
            $table->index(
                [
                    'email_provider_reconciliation_run_id',
                    'item_summary_status',
                    'status',
                    'id',
                ],
                'em_recon_folder_item_summary_ix',
            );
            $table->index(['account_id', 'status', 'finished_at'], 'em_recon_folder_account_status_ix');
            $table->index(['email_folder_id', 'status', 'finished_at'], 'em_recon_folder_history_ix');
        });

        Schema::create('email_provider_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_provider_reconciliation_run_id');
            $table->foreignId('email_provider_reconciliation_folder_id');
            $table->foreignId('uid_namespace_id');
            $table->unsignedBigInteger('imap_uid');
            $table->string('kind', 32);
            $table->string('status', 32)->default('pending');
            $table->foreignId('source_placement_id')->nullable();
            $table->foreignId('target_placement_id')->nullable();
            $table->foreignId('result_placement_id')->nullable();
            $table->unsignedBigInteger('provider_modseq')->nullable();
            $table->boolean('provider_seen')->nullable();
            $table->boolean('provider_answered')->nullable();
            $table->boolean('provider_flagged')->nullable();
            $table->boolean('provider_deleted')->nullable();
            $table->boolean('provider_draft')->nullable();
            $table->json('custom_flags_json')->nullable();
            $table->char('custom_flags_hash', 64)->nullable();
            $table->unsignedInteger('placement_sync_version_before')->nullable();
            $table->unsignedInteger('placement_sync_version_after')->nullable();
            $table->foreignId('email_remote_operation_id')->nullable();
            $table->foreignId('email_provider_placement_finding_id')->nullable();
            $table->char('identity_hash', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->dateTime('first_attempt_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            // A folder discovered after the first complete account baseline
            // keeps imported history hidden until read-for-me baselines have
            // been projected in separately bounded, token-owned batches.
            $table->boolean('historical_baseline_required')->default(false);
            $table->string('historical_baseline_status', 32)->nullable();
            $table->unsignedBigInteger('historical_baseline_max_id')->default(0);
            $table->unsignedBigInteger('historical_baseline_cursor_id')->default(0);
            $table->char('historical_baseline_claim_token', 64)->nullable();
            $table->unsignedInteger('historical_baseline_attempt_count')->default(0);
            $table->dateTime('historical_baseline_frozen_at')->nullable();
            $table->dateTime('historical_baseline_first_attempt_at')->nullable();
            $table->dateTime('historical_baseline_last_attempt_at')->nullable();
            $table->dateTime('historical_baseline_completed_at')->nullable();
            $table->string('historical_baseline_error_code', 80)->nullable();

            // Live Inbox imports defer local automation until the detached
            // message, placement, raw snapshot, and attachments have all
            // passed the reconciliation Store boundary. A token-owned claim
            // makes crash recovery and redelivery safe without granting the
            // automation job any provider-mutation authority.
            $table->boolean('automation_required')->default(false);
            $table->string('automation_status', 32)->nullable();
            $table->char('automation_claim_token', 64)->nullable();
            $table->unsignedSmallInteger('automation_attempt_count')->default(0);
            $table->dateTime('automation_last_attempt_at')->nullable();
            $table->dateTime('automation_completed_at')->nullable();
            $table->string('automation_error_code', 80)->nullable();
            $table->unsignedBigInteger('automation_rule_attempt_floor_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_provider_reconciliation_run_id', 'em_recon_item_run_fk')
                ->references('id')->on('email_provider_reconciliation_runs')->cascadeOnDelete();
            $table->foreign('email_provider_reconciliation_folder_id', 'em_recon_item_folder_fk')
                ->references('id')->on('email_provider_reconciliation_folders')->cascadeOnDelete();
            $table->foreign('uid_namespace_id', 'em_recon_item_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->restrictOnDelete();
            $table->foreign('source_placement_id', 'em_recon_item_source_fk')
                ->references('id')->on('email_mailbox_placements')->nullOnDelete();
            $table->foreign('target_placement_id', 'em_recon_item_target_fk')
                ->references('id')->on('email_mailbox_placements')->nullOnDelete();
            $table->foreign('result_placement_id', 'em_recon_item_result_fk')
                ->references('id')->on('email_mailbox_placements')->nullOnDelete();
            $table->foreign('email_remote_operation_id', 'em_recon_item_operation_fk')
                ->references('id')->on('email_remote_operations')->nullOnDelete();
            $table->foreign('email_provider_placement_finding_id', 'em_recon_item_finding_fk')
                ->references('id')->on('email_provider_placement_findings')->nullOnDelete();
            $table->unique(
                [
                    'email_provider_reconciliation_run_id',
                    'email_provider_reconciliation_folder_id',
                    'uid_namespace_id',
                    'imap_uid',
                    'kind',
                ],
                'em_recon_item_scope_uid_kind_uq',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'status', 'id'],
                'em_recon_item_run_status_ix',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'automation_status', 'id'],
                'em_recon_item_run_automation_ix',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'kind', 'identity_hash', 'id'],
                'em_recon_item_run_identity_ix',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'kind', 'status', 'id'],
                'em_recon_item_run_kind_status_ix',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'id'],
                'em_recon_item_run_cursor_ix',
            );
            $table->index(
                ['email_provider_reconciliation_folder_id', 'kind', 'status', 'id'],
                'em_recon_item_folder_kind_status_ix',
            );
            $table->index(
                ['email_provider_reconciliation_folder_id', 'id'],
                'em_recon_item_folder_cursor_ix',
            );
            $table->index(
                ['email_provider_reconciliation_run_id', 'historical_baseline_status', 'id'],
                'em_recon_item_run_hist_base_ix',
            );
            $table->index(['source_placement_id', 'kind'], 'em_recon_item_source_kind_ix');
            $table->index(
                ['email_provider_reconciliation_run_id', 'target_placement_id', 'kind', 'status'],
                'em_recon_item_run_target_kind_ix',
            );
            $table->index(
                ['result_placement_id', 'kind', 'status'],
                'em_recon_item_result_kind_ix',
            );
            $table->index(['email_remote_operation_id', 'status'], 'em_recon_item_operation_ix');
        });

        // Every bounded baseline page uses this exact account-scoped cursor.
        // The composite index prevents interleaved accounts from turning a
        // 100-row page into an unbounded scan across the global primary key.
        if (! Schema::hasIndex('email_account_user_read_baselines', 'em_read_base_account_cursor_ix')) {
            Schema::table('email_account_user_read_baselines', function (Blueprint $table): void {
                $table->index(['email_account_id', 'id'], 'em_read_base_account_cursor_ix');
            });
        }
        if (! Schema::hasIndex('email_folders', 'em_folder_recon_local_cursor_ix')) {
            Schema::table('email_folders', function (Blueprint $table): void {
                $table->index(['account_id', 'id'], 'em_folder_recon_local_cursor_ix');
            });
        }

        Schema::table('email_mailbox_placements', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_provider_reconciliation_run_id')->nullable();
            $table->unsignedInteger('last_provider_observed_sync_version')->nullable();
            $table->char('last_provider_observed_identity_hash', 64)->nullable();
            $table->dateTime('last_provider_observed_at')->nullable();
            $table->foreign('last_provider_reconciliation_run_id', 'em_place_last_recon_run_fk')
                ->references('id')->on('email_provider_reconciliation_runs')->nullOnDelete();
            $table->index(
                ['account_id', 'email_folder_id', 'local_state', 'last_provider_reconciliation_run_id'],
                'em_place_provider_observed_ix',
            );
            // Correlation intentionally seeds from frozen run evidence even
            // when a source has since become hidden, missing, or soft-deleted.
            // Keep that bounded query independent of current folder/state.
            $table->index(
                [
                    'account_id',
                    'last_provider_reconciliation_run_id',
                    'last_provider_observed_identity_hash',
                    'id',
                ],
                'em_place_recon_identity_ix',
            );
        });

        $this->addPositiveBindingGuard();
        $this->addActiveSlotGuard();
        $this->addAutomationScopeGuard();
        $this->addFinalSummaryGuard();
        $this->addPlacementObservedVersionGuard();
        $this->addPlacementObservedIdentityGuard();
        $this->addLocalFolderSnapshotGuard();
        $this->addMetadataVerificationGuard();
        $this->addFolderItemSummaryGuard();
        $this->addPlacementSnapshotGuard();
        $this->addHistoricalBaselineGuard();
        $this->addAutomationGuard();
        $this->addSummaryWriteBarriers();
    }

    public function down(): void
    {
        $hasEvidence = Schema::hasTable('email_provider_reconciliation_runs')
            && DB::table('email_provider_reconciliation_runs')->exists();
        $hasPlacementPointers = Schema::hasColumn(
            'email_mailbox_placements',
            'last_provider_reconciliation_run_id',
        ) && DB::table('email_mailbox_placements')
            ->where(function ($query): void {
                $query->whereNotNull('last_provider_reconciliation_run_id')
                    ->orWhereNotNull('last_provider_observed_sync_version')
                    ->orWhereNotNull('last_provider_observed_identity_hash')
                    ->orWhereNotNull('last_provider_observed_at');
            })
            ->exists();

        if ($hasEvidence || $hasPlacementPointers) {
            throw new RuntimeException(
                'Provider reconciliation evidence must be preserved before schema rollback.',
            );
        }

        $this->dropSummaryWriteBarriers();
        $this->dropAutomationGuard();
        $this->dropHistoricalBaselineGuard();
        $this->dropPlacementSnapshotGuard();
        $this->dropFolderItemSummaryGuard();
        $this->dropMetadataVerificationGuard();
        $this->dropLocalFolderSnapshotGuard();
        $this->dropPlacementObservedIdentityGuard();
        $this->dropPlacementObservedVersionGuard();
        $this->dropAutomationScopeGuard();
        $this->dropFinalSummaryGuard();
        $this->dropActiveSlotGuard();
        $this->dropPositiveBindingGuard();

        if (Schema::hasColumn('email_mailbox_placements', 'last_provider_reconciliation_run_id')) {
            Schema::table('email_mailbox_placements', function (Blueprint $table): void {
                DB::getDriverName() === 'sqlite'
                    ? $table->dropForeign(['last_provider_reconciliation_run_id'])
                    : $table->dropForeign('em_place_last_recon_run_fk');
                $table->dropIndex('em_place_recon_identity_ix');
                $table->dropIndex('em_place_provider_observed_ix');
                $table->dropColumn([
                    'last_provider_reconciliation_run_id',
                    'last_provider_observed_sync_version',
                    'last_provider_observed_identity_hash',
                    'last_provider_observed_at',
                ]);
            });
        }

        Schema::dropIfExists('email_provider_reconciliation_items');
        Schema::dropIfExists('email_provider_reconciliation_folders');
        Schema::dropIfExists('email_provider_reconciliation_runs');

        if (Schema::hasTable('email_account_user_read_baselines')
            && Schema::hasIndex('email_account_user_read_baselines', 'em_read_base_account_cursor_ix')) {
            Schema::table('email_account_user_read_baselines', function (Blueprint $table): void {
                $table->dropIndex('em_read_base_account_cursor_ix');
            });
        }
        if (Schema::hasTable('email_folders')
            && Schema::hasIndex('email_folders', 'em_folder_recon_local_cursor_ix')) {
            Schema::table('email_folders', function (Blueprint $table): void {
                $table->dropIndex('em_folder_recon_local_cursor_ix');
            });
        }
    }

    /**
     * Eloquent immutability is not enough: queue repair/import code can use
     * the query builder directly. Reject zero before any provider-I/O ledger
     * can carry an ambiguous or legacy-serialized binding snapshot.
     */
    private function addPositiveBindingGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_binding_positive_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}`"
                .' check (`provider_binding_version` >= 1)',
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                .' when NEW.provider_binding_version < 1 begin'
                ." select raise(abort, 'provider_binding_version_must_be_positive'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update of provider_binding_version on `{$table}`"
                .' when NEW.provider_binding_version < 1 begin'
                ." select raise(abort, 'provider_binding_version_must_be_positive'); end",
            );
        }
    }

    private function dropPositiveBindingGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_binding_positive_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /** Reject an ambiguous zero version even through query-builder repair paths. */
    private function addPlacementObservedVersionGuard(): void
    {
        $table = 'email_mailbox_placements';
        $constraint = 'em_place_observed_version_positive_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}`"
                .' check (`last_provider_observed_sync_version` is null'
                .' or `last_provider_observed_sync_version` >= 1)',
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                .' when NEW.last_provider_observed_sync_version is not null'
                .' and NEW.last_provider_observed_sync_version < 1 begin'
                ." select raise(abort, 'provider_observed_sync_version_must_be_positive'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update of last_provider_observed_sync_version on `{$table}`"
                .' when NEW.last_provider_observed_sync_version is not null'
                .' and NEW.last_provider_observed_sync_version < 1 begin'
                ." select raise(abort, 'provider_observed_sync_version_must_be_positive'); end",
            );
        }
    }

    private function dropPlacementObservedVersionGuard(): void
    {
        $table = 'email_mailbox_placements';
        $constraint = 'em_place_observed_version_positive_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /** Reject malformed frozen identity evidence through raw repair paths. */
    private function addPlacementObservedIdentityGuard(): void
    {
        $table = 'email_mailbox_placements';
        $constraint = 'em_place_observed_identity_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}`"
                .' check (`last_provider_observed_identity_hash` is null'
                ." or binary `last_provider_observed_identity_hash` regexp '^[0-9a-f]{64}$')",
            );

            return;
        }

        if ($driver === 'sqlite') {
            $invalid = 'NEW.last_provider_observed_identity_hash is not null'
                .' and (length(NEW.last_provider_observed_identity_hash) != 64'
                ." or NEW.last_provider_observed_identity_hash glob '*[^0-9a-f]*')";
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when {$invalid} begin"
                ." select raise(abort, 'provider_observed_identity_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update of last_provider_observed_identity_hash"
                ." on `{$table}` when {$invalid} begin"
                ." select raise(abort, 'provider_observed_identity_invalid'); end",
            );
        }
    }

    private function dropPlacementObservedIdentityGuard(): void
    {
        $table = 'email_mailbox_placements';
        $constraint = 'em_place_observed_identity_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /**
     * The `(account_id, active_slot)` unique index only enforces one active
     * run when every non-null slot is exactly 1. Guard query-builder and repair
     * paths so a value such as 2 cannot create a second active ledger.
     */
    private function addActiveSlotGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_active_slot_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}`"
                .' check (`active_slot` is null or `active_slot` = 1)',
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                .' when NEW.active_slot is not null and NEW.active_slot <> 1 begin'
                ." select raise(abort, 'active_slot_must_be_one_or_null'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update of active_slot on `{$table}`"
                .' when NEW.active_slot is not null and NEW.active_slot <> 1 begin'
                ." select raise(abort, 'active_slot_must_be_one_or_null'); end",
            );
        }
    }

    private function dropActiveSlotGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_active_slot_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /**
     * Provider-evidence writers may only move the account-wide automation
     * scope from safe to unsafe. A raw repair must not clear that durable
     * fail-closed fact or attach arbitrary provider content as its reason.
     */
    private function addAutomationScopeGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_auto_scope_ck';
        $immutable = 'em_recon_run_auto_scope_immutable';
        $driver = DB::connection()->getDriverName();
        $contract = '`automation_scope_unsafe` in (0,1)'
            .' and ((`automation_scope_unsafe` = 0'
            .' and `automation_scope_error_code` is null'
            .' and `automation_scope_unsafe_at` is null)'
            .' or (`automation_scope_unsafe` = 1'
            .' and `automation_scope_error_code` is not null'
            ." and `automation_scope_error_code` = 'provider_reconciliation_automation_scope_unsafe'"
            .' and `automation_scope_unsafe_at` is not null))';

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ({$contract})",
            );
            DB::unprepared(
                "create trigger `{$immutable}` before update on `{$table}` for each row begin"
                .' if OLD.automation_scope_unsafe = 1 and NEW.automation_scope_unsafe <> 1 then'
                ." signal sqlstate '45000' set message_text = 'automation_scope_unsafe_is_monotonic';"
                .' end if; end',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = 'NEW.automation_scope_unsafe in (0,1)'
                .' and ((NEW.automation_scope_unsafe = 0'
                .' and NEW.automation_scope_error_code is null'
                .' and NEW.automation_scope_unsafe_at is null)'
                .' or (NEW.automation_scope_unsafe = 1'
                .' and NEW.automation_scope_error_code is not null'
                ." and NEW.automation_scope_error_code = 'provider_reconciliation_automation_scope_unsafe'"
                .' and NEW.automation_scope_unsafe_at is not null))';
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'automation_scope_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'automation_scope_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$immutable}` before update of automation_scope_unsafe on `{$table}`"
                .' when OLD.automation_scope_unsafe = 1 and NEW.automation_scope_unsafe <> 1 begin'
                ." select raise(abort, 'automation_scope_unsafe_is_monotonic'); end",
            );
        }
    }

    private function dropAutomationScopeGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_auto_scope_ck';
        $immutable = 'em_recon_run_auto_scope_immutable';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::unprepared("drop trigger if exists `{$immutable}`");
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$immutable}`");
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /** Guard the bounded final outcome cursor against partial repair writes. */
    private function addFinalSummaryGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_final_summary_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->finalSummaryContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->finalSummaryContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'final_summary_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'final_summary_contract_invalid'); end",
            );
        }
    }

    private function dropFinalSummaryGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_final_summary_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function finalSummaryContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $phase = $column('phase');
        $runStatus = $column('status');
        $activeSlot = $column('active_slot');
        $status = $column('final_summary_status');
        $folderThrough = $column('final_summary_folder_through_id');
        $folderCursor = $column('final_summary_folder_cursor_id');
        $itemThrough = $column('final_summary_item_through_id');
        $itemCursor = $column('final_summary_item_cursor_id');
        $completeFolders = $column('final_summary_complete_folder_count');
        $missing = $column('final_summary_missing_count');
        $moves = $column('final_summary_move_count');
        $conflicts = $column('final_summary_conflict_count');
        $errors = $column('final_summary_error_count');
        $blocked = $column('final_summary_blocked');
        $failed = $column('final_summary_failed');
        $stale = $column('final_summary_stale');
        $automationFailed = $column('final_summary_automation_failed');
        $batches = $column('final_summary_batch_count');
        $startedAt = $column('final_summary_started_at');
        $completedAt = $column('final_summary_completed_at');
        $publicCompleteFolders = $column('complete_folder_count');
        $publicMissing = $column('missing_count');
        $publicMoves = $column('move_count');
        $publicConflicts = $column('conflict_count');
        $publicErrors = $column('error_count');
        $finishedAt = $column('finished_at');

        $defaults = "{$folderThrough} = 0"
            ." and {$folderCursor} = 0"
            ." and {$itemThrough} = 0"
            ." and {$itemCursor} = 0"
            ." and {$completeFolders} = 0"
            ." and {$missing} = 0"
            ." and {$moves} = 0"
            ." and {$conflicts} = 0"
            ." and {$errors} = 0"
            ." and {$blocked} = 0"
            ." and {$failed} = 0"
            ." and {$stale} = 0"
            ." and {$automationFailed} = 0"
            ." and {$batches} = 0"
            ." and {$startedAt} is null"
            ." and {$completedAt} is null";
        $common = "{$status} is not null"
            ." and {$status} in ('folders','items','sealed')"
            ." and {$phase} = 'summary'"
            ." and {$folderCursor} <= {$folderThrough}"
            ." and {$itemCursor} <= {$itemThrough}"
            ." and {$blocked} in (0,1)"
            ." and {$failed} in (0,1)"
            ." and {$stale} in (0,1)"
            ." and {$automationFailed} in (0,1)"
            ." and {$startedAt} is not null";
        $active = "{$runStatus} in ('running','cancelling')"
            ." and {$activeSlot} = 1"
            ." and {$finishedAt} is null";
        $publishedOutcome = "(({$runStatus} = 'blocked'"
            ." and {$blocked} = 1"
            ." and {$conflicts} > 0)"
            ." or ({$runStatus} = 'partial'"
            ." and {$blocked} = 0"
            ." and ({$failed} = 1 or {$automationFailed} = 1)"
            ." and {$errors} > 0)"
            ." or ({$runStatus} = 'stale'"
            ." and {$blocked} = 0"
            ." and {$failed} = 0"
            ." and {$automationFailed} = 0"
            ." and {$stale} = 1"
            ." and {$errors} = 0)"
            ." or ({$runStatus} = 'completed_with_conflicts'"
            ." and {$blocked} = 0"
            ." and {$failed} = 0"
            ." and {$automationFailed} = 0"
            ." and {$stale} = 0"
            ." and {$conflicts} > 0"
            ." and {$errors} = 0)"
            ." or ({$runStatus} = 'completed'"
            ." and {$blocked} = 0"
            ." and {$failed} = 0"
            ." and {$automationFailed} = 0"
            ." and {$stale} = 0"
            ." and {$conflicts} = 0"
            ." and {$errors} = 0))";
        $published = "{$publishedOutcome}"
            ." and {$activeSlot} is null"
            ." and {$finishedAt} is not null"
            ." and {$publicCompleteFolders} = {$completeFolders}"
            ." and {$publicMissing} = {$missing}"
            ." and {$publicMoves} = {$moves}"
            ." and {$publicConflicts} = {$conflicts}"
            ." and {$publicErrors} = {$errors}";

        return "(({$status} is null"
            ." and {$phase} <> 'summary'"
            ." and {$defaults})"
            ." or ({$common}"
            ." and (({$status} = 'folders'"
            ." and {$active}"
            ." and {$completedAt} is null)"
            ." or ({$status} = 'items'"
            ." and {$active}"
            ." and {$folderCursor} = {$folderThrough}"
            ." and {$completedAt} is null)"
            ." or ({$status} = 'sealed'"
            ." and {$folderCursor} = {$folderThrough}"
            ." and {$itemCursor} = {$itemThrough}"
            ." and {$completedAt} is not null"
            ." and (({$active}) or ({$published}))))))";
    }

    /** Guard the durable local-folder cursor against partial repair writes. */
    private function addLocalFolderSnapshotGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_local_snap_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->localFolderSnapshotContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->localFolderSnapshotContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'local_folder_snapshot_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'local_folder_snapshot_contract_invalid'); end",
            );
        }
    }

    private function dropLocalFolderSnapshotGuard(): void
    {
        $table = 'email_provider_reconciliation_runs';
        $constraint = 'em_recon_run_local_snap_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function localFolderSnapshotContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $status = $column('local_folder_snapshot_status');
        $through = $column('local_folder_snapshot_through_id');
        $cursor = $column('local_folder_snapshot_cursor_id');
        $count = $column('local_folder_snapshot_count');
        $hash = $column('local_folder_snapshot_hash');
        $batches = $column('local_folder_snapshot_batch_count');
        $startedAt = $column('local_folder_snapshot_started_at');
        $completedAt = $column('local_folder_snapshot_completed_at');

        return "(({$status} is null"
            ." and {$through} = 0"
            ." and {$cursor} = 0"
            ." and {$count} = 0"
            ." and {$hash} is null"
            ." and {$batches} = 0"
            ." and {$startedAt} is null"
            ." and {$completedAt} is null)"
            ." or ({$status} is not null"
            ." and {$status} in ('running','completed')"
            ." and {$cursor} <= {$through}"
            ." and {$count} <= {$cursor}"
            ." and (({$cursor} = 0 and {$batches} = 0)"
            ." or ({$cursor} > 0 and {$batches} > 0))"
            ." and {$hash} is not null"
            ." and length({$hash}) = 64"
            ." and {$startedAt} is not null"
            ." and (({$status} = 'running' and {$completedAt} is null)"
            ." or ({$status} = 'completed'"
            ." and {$cursor} = {$through}"
            ." and {$completedAt} is not null))))";
    }

    /** Guard the ordinary-IMAP second UID+FLAGS inventory cursor. */
    private function addMetadataVerificationGuard(): void
    {
        $table = 'email_provider_reconciliation_folders';
        $constraint = 'em_recon_folder_meta_verify_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->metadataVerificationContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->metadataVerificationContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'metadata_verification_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'metadata_verification_contract_invalid'); end",
            );
        }
    }

    private function dropMetadataVerificationGuard(): void
    {
        $table = 'email_provider_reconciliation_folders';
        $constraint = 'em_recon_folder_meta_verify_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function metadataVerificationContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $status = $column('metadata_verification_status');
        $nextUid = $column('metadata_verification_next_uid');
        $count = $column('metadata_verification_count');
        $hash = $column('metadata_verification_hash');
        $batches = $column('metadata_verification_batch_count');
        $startedAt = $column('metadata_verification_started_at');
        $completedAt = $column('metadata_verification_completed_at');
        $supportsModseq = $column('supports_modseq');
        $scanThroughUid = $column('scan_through_uid');

        return "(({$status} is null"
            ." and {$nextUid} = 1"
            ." and {$count} = 0"
            ." and {$hash} is null"
            ." and {$batches} = 0"
            ." and {$startedAt} is null"
            ." and {$completedAt} is null)"
            ." or ({$status} is not null"
            ." and {$status} in ('running','completed','failed')"
            ." and {$supportsModseq} = 0"
            ." and {$nextUid} >= 1"
            ." and {$nextUid} <= {$scanThroughUid} + 1"
            ." and {$count} <= {$scanThroughUid}"
            ." and (({$batches} = 0"
            ." and {$status} = 'running'"
            ." and {$nextUid} = 1"
            ." and {$count} = 0)"
            ." or {$batches} > 0)"
            ." and {$hash} is not null"
            ." and length({$hash}) = 64"
            ." and {$startedAt} is not null"
            ." and (({$status} = 'running' and {$completedAt} is null)"
            ." or ({$status} in ('completed','failed')"
            ." and {$nextUid} = {$scanThroughUid} + 1"
            ." and {$completedAt} is not null))))";
    }

    /** Guard each folder's bounded terminal item-summary cursor. */
    private function addFolderItemSummaryGuard(): void
    {
        $table = 'email_provider_reconciliation_folders';
        $constraint = 'em_recon_folder_item_summary_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->folderItemSummaryContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->folderItemSummaryContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'folder_item_summary_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'folder_item_summary_contract_invalid'); end",
            );
        }
    }

    private function dropFolderItemSummaryGuard(): void
    {
        $table = 'email_provider_reconciliation_folders';
        $constraint = 'em_recon_folder_item_summary_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function folderItemSummaryContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $status = $column('item_summary_status');
        $folderStatus = $column('status');
        $through = $column('item_summary_through_id');
        $cursor = $column('item_summary_cursor_id');
        $missing = $column('item_summary_missing_count');
        $moves = $column('item_summary_move_count');
        $conflicts = $column('item_summary_conflict_count');
        $nonterminal = $column('item_summary_nonterminal');
        $batches = $column('item_summary_batch_count');
        $startedAt = $column('item_summary_started_at');
        $completedAt = $column('item_summary_completed_at');
        $publicMissing = $column('missing_count');
        $publicConflicts = $column('conflict_count');

        return "(({$status} is null"
            ." and {$folderStatus} not in ('complete','missing_confirmed')"
            ." and {$through} = 0"
            ." and {$cursor} = 0"
            ." and {$missing} = 0"
            ." and {$moves} = 0"
            ." and {$conflicts} = 0"
            ." and {$nonterminal} = 0"
            ." and {$batches} = 0"
            ." and {$startedAt} is null"
            ." and {$completedAt} is null)"
            ." or ({$status} is not null"
            ." and {$status} in ('running','sealed')"
            ." and {$cursor} <= {$through}"
            ." and {$nonterminal} in (0,1)"
            ." and {$startedAt} is not null"
            ." and (({$status} = 'running'"
            ." and {$folderStatus} in ('waiting_for_imports','pending')"
            ." and {$completedAt} is null)"
            ." or ({$status} = 'sealed'"
            ." and {$cursor} = {$through}"
            ." and {$completedAt} is not null"
            ." and (({$folderStatus} in ('waiting_for_imports','pending','stale'))"
            ." or ({$folderStatus} in ('complete','missing_confirmed')"
            ." and {$nonterminal} = 0"
            ." and {$publicMissing} = {$missing}"
            ." and {$publicConflicts} = {$conflicts}))))))";
    }

    /**
     * The item row is the reliability boundary for hidden historical imports.
     * Guard raw query-builder/repair paths so they cannot attest completion,
     * widen a frozen cursor, or create an unowned running claim.
     */
    private function addHistoricalBaselineGuard(): void
    {
        $table = 'email_provider_reconciliation_items';
        $constraint = 'em_recon_item_hist_base_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->historicalBaselineContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->historicalBaselineContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'historical_baseline_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'historical_baseline_contract_invalid'); end",
            );
        }
    }

    /** Guard the durable placement cursor against raw query-builder drift. */
    private function addPlacementSnapshotGuard(): void
    {
        $table = 'email_provider_reconciliation_folders';
        $constraint = 'em_recon_folder_place_snap_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->placementSnapshotContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->placementSnapshotContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'placement_snapshot_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'placement_snapshot_contract_invalid'); end",
            );
        }
    }

    private function dropPlacementSnapshotGuard(): void
    {
        $table = 'email_provider_reconciliation_folders';
        $constraint = 'em_recon_folder_place_snap_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function placementSnapshotContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $purpose = $column('placement_snapshot_purpose');
        $status = $column('placement_snapshot_status');
        $through = $column('placement_snapshot_through_id');
        $cursor = $column('placement_snapshot_cursor_id');
        $count = $column('placement_snapshot_count');
        $hash = $column('placement_snapshot_hash');
        $batches = $column('placement_snapshot_batch_count');
        $startedAt = $column('placement_snapshot_started_at');
        $completedAt = $column('placement_snapshot_completed_at');

        return "(({$purpose} is null"
            ." and {$status} is null"
            ." and {$through} = 0"
            ." and {$cursor} = 0"
            ." and {$count} = 0"
            ." and {$hash} is null"
            ." and {$batches} = 0"
            ." and {$startedAt} is null"
            ." and {$completedAt} is null)"
            ." or ({$purpose} is not null"
            ." and {$status} is not null"
            ." and {$purpose} in ('baseline','scan_end','remote_end','remote_projection','local_freeze','local_projection')"
            ." and {$status} in ('running','completed')"
            ." and {$cursor} <= {$through}"
            ." and {$count} <= {$through}"
            ." and {$batches} >= 1"
            ." and (({$cursor} = 0 and {$count} = 0)"
            ." or ({$cursor} > 0 and {$count} > 0 and {$count} <= {$cursor}))"
            ." and {$hash} is not null"
            ." and length({$hash}) = 64"
            ." and {$startedAt} is not null"
            ." and (({$status} = 'running' and {$completedAt} is null)"
            ." or ({$status} = 'completed' and {$completedAt} is not null))))";
    }

    private function dropHistoricalBaselineGuard(): void
    {
        $table = 'email_provider_reconciliation_items';
        $constraint = 'em_recon_item_hist_base_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function historicalBaselineContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $kind = $column('kind');
        $itemStatus = $column('status');
        $required = $column('historical_baseline_required');
        $status = $column('historical_baseline_status');
        $maximum = $column('historical_baseline_max_id');
        $cursor = $column('historical_baseline_cursor_id');
        $token = $column('historical_baseline_claim_token');
        $attempts = $column('historical_baseline_attempt_count');
        $frozenAt = $column('historical_baseline_frozen_at');
        $firstAttemptAt = $column('historical_baseline_first_attempt_at');
        $lastAttemptAt = $column('historical_baseline_last_attempt_at');
        $completedAt = $column('historical_baseline_completed_at');
        $errorCode = $column('historical_baseline_error_code');

        return "{$required} in (0,1)"
            ." and (({$required} = 0"
            ." and {$status} is null"
            ." and {$maximum} = 0"
            ." and {$cursor} = 0"
            ." and {$token} is null"
            ." and {$attempts} = 0"
            ." and {$frozenAt} is null"
            ." and {$firstAttemptAt} is null"
            ." and {$lastAttemptAt} is null"
            ." and {$completedAt} is null"
            ." and {$errorCode} is null)"
            ." or ({$required} = 1"
            ." and {$kind} = 'import'"
            ." and {$status} is not null"
            ." and {$status} in ('pending','running','completed','failed','cancelled')"
            ." and (({$status} in ('pending','running')"
            ." and {$itemStatus} = 'waiting_for_baseline')"
            ." or ({$status} = 'completed' and {$itemStatus} = 'projected')"
            ." or ({$status} = 'failed' and {$itemStatus} = 'failed')"
            ." or ({$status} = 'cancelled' and {$itemStatus} = 'cancelled'))"
            ." and {$frozenAt} is not null"
            ." and {$cursor} <= {$maximum}"
            ." and (({$status} = 'running'"
            ." and {$token} is not null"
            ." and {$lastAttemptAt} is not null"
            ." and {$completedAt} is null)"
            ." or ({$status} = 'pending'"
            ." and {$token} is null"
            ." and {$completedAt} is null)"
            ." or ({$status} in ('completed','failed','cancelled')"
            ." and {$token} is null"
            ." and {$completedAt} is not null))))";
    }

    /**
     * Once a folder or run freezes its summary high-water, child evidence is
     * immutable. Automation-only item columns remain writable until the run
     * itself enters the summary phase; every main evidence column is fenced.
     */
    private function addSummaryWriteBarriers(): void
    {
        $driver = DB::connection()->getDriverName();
        $protected = array_values(array_intersect(
            [
                'email_provider_reconciliation_run_id',
                'email_provider_reconciliation_folder_id',
                'uid_namespace_id',
                'imap_uid',
                'kind',
                'status',
                'source_placement_id',
                'target_placement_id',
                'result_placement_id',
                'provider_modseq',
                'provider_seen',
                'provider_answered',
                'provider_flagged',
                'provider_deleted',
                'provider_draft',
                'custom_flags_json',
                'custom_flags_hash',
                'placement_sync_version_before',
                'placement_sync_version_after',
                'email_remote_operation_id',
                'email_provider_placement_finding_id',
                'identity_hash',
                'attempt_count',
                'error_code',
                'first_attempt_at',
                'last_attempt_at',
                'completed_at',
                'historical_baseline_required',
                'historical_baseline_status',
                'historical_baseline_max_id',
                'historical_baseline_cursor_id',
                'historical_baseline_claim_token',
                'historical_baseline_attempt_count',
                'historical_baseline_frozen_at',
                'historical_baseline_first_attempt_at',
                'historical_baseline_last_attempt_at',
                'historical_baseline_completed_at',
                'historical_baseline_error_code',
            ],
            Schema::getColumnListing('email_provider_reconciliation_items'),
        ));

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $changed = implode(' or ', array_map(
                fn (string $column): string => "not (OLD.`{$column}` <=> NEW.`{$column}`)",
                $protected,
            ));
            $this->createMariaSummaryBarrierTriggers($changed);

            return;
        }

        if ($driver === 'sqlite') {
            $columns = implode(', ', $protected);
            DB::unprepared(
                'create trigger `em_recon_item_folder_sum_ins`'
                .' before insert on `email_provider_reconciliation_items`'
                .' when exists (select 1 from email_provider_reconciliation_folders f'
                .' where f.id = NEW.email_provider_reconciliation_folder_id'
                .' and f.item_summary_status is not null) begin'
                ." select raise(abort, 'folder_item_summary_is_sealed'); end",
            );
            DB::unprepared(
                "create trigger `em_recon_item_folder_sum_upd` before update of {$columns}"
                .' on `email_provider_reconciliation_items`'
                .' when exists (select 1 from email_provider_reconciliation_folders f'
                .' where f.id in (OLD.email_provider_reconciliation_folder_id,'
                .' NEW.email_provider_reconciliation_folder_id)'
                .' and f.item_summary_status is not null) begin'
                ." select raise(abort, 'folder_item_summary_is_sealed'); end",
            );
            DB::unprepared(
                'create trigger `em_recon_item_folder_sum_del`'
                .' before delete on `email_provider_reconciliation_items`'
                .' when exists (select 1 from email_provider_reconciliation_folders f'
                .' where f.id = OLD.email_provider_reconciliation_folder_id'
                .' and f.item_summary_status is not null) begin'
                ." select raise(abort, 'folder_item_summary_is_sealed'); end",
            );
            foreach (['insert' => 'NEW', 'update' => 'NEW', 'delete' => 'OLD'] as $event => $row) {
                $runReference = $event === 'update'
                    ? 'r.id in (OLD.email_provider_reconciliation_run_id,'
                        .' NEW.email_provider_reconciliation_run_id)'
                    : "r.id = {$row}.email_provider_reconciliation_run_id";
                DB::unprepared(
                    "create trigger `em_recon_item_run_sum_{$event}` before {$event}"
                    .' on `email_provider_reconciliation_items`'
                    .' when exists (select 1 from email_provider_reconciliation_runs r'
                    ." where {$runReference}"
                    ." and r.phase = 'summary') begin"
                    ." select raise(abort, 'run_summary_is_sealed'); end",
                );
                DB::unprepared(
                    "create trigger `em_recon_folder_run_sum_{$event}` before {$event}"
                    .' on `email_provider_reconciliation_folders`'
                    .' when exists (select 1 from email_provider_reconciliation_runs r'
                    ." where {$runReference}"
                    ." and r.phase = 'summary') begin"
                    ." select raise(abort, 'run_summary_is_sealed'); end",
                );
            }
        }
    }

    private function createMariaSummaryBarrierTriggers(string $protectedChanged): void
    {
        DB::unprepared(
            'create trigger `em_recon_item_folder_sum_ins` before insert'
            .' on `email_provider_reconciliation_items` for each row begin'
            .' if exists (select 1 from email_provider_reconciliation_folders f'
            .' where f.id = NEW.email_provider_reconciliation_folder_id'
            .' and f.item_summary_status is not null) then'
            ." signal sqlstate '45000' set message_text = 'folder_item_summary_is_sealed';"
            .' end if; end',
        );
        DB::unprepared(
            'create trigger `em_recon_item_folder_sum_upd` before update'
            .' on `email_provider_reconciliation_items` for each row begin'
            ." if ({$protectedChanged}) and exists"
            .' (select 1 from email_provider_reconciliation_folders f'
            .' where f.id in (OLD.email_provider_reconciliation_folder_id,'
            .' NEW.email_provider_reconciliation_folder_id)'
            .' and f.item_summary_status is not null) then'
            ." signal sqlstate '45000' set message_text = 'folder_item_summary_is_sealed';"
            .' end if; end',
        );
        DB::unprepared(
            'create trigger `em_recon_item_folder_sum_del` before delete'
            .' on `email_provider_reconciliation_items` for each row begin'
            .' if exists (select 1 from email_provider_reconciliation_folders f'
            .' where f.id = OLD.email_provider_reconciliation_folder_id'
            .' and f.item_summary_status is not null) then'
            ." signal sqlstate '45000' set message_text = 'folder_item_summary_is_sealed';"
            .' end if; end',
        );
        foreach (['insert' => 'NEW', 'update' => 'NEW', 'delete' => 'OLD'] as $event => $row) {
            $runReference = $event === 'update'
                ? 'r.id in (OLD.email_provider_reconciliation_run_id,'
                    .' NEW.email_provider_reconciliation_run_id)'
                : "r.id = {$row}.email_provider_reconciliation_run_id";
            DB::unprepared(
                "create trigger `em_recon_item_run_sum_{$event}` before {$event}"
                .' on `email_provider_reconciliation_items` for each row begin'
                .' if exists (select 1 from email_provider_reconciliation_runs r'
                ." where {$runReference}"
                ." and r.phase = 'summary') then"
                ." signal sqlstate '45000' set message_text = 'run_summary_is_sealed';"
                .' end if; end',
            );
            DB::unprepared(
                "create trigger `em_recon_folder_run_sum_{$event}` before {$event}"
                .' on `email_provider_reconciliation_folders` for each row begin'
                .' if exists (select 1 from email_provider_reconciliation_runs r'
                ." where {$runReference}"
                ." and r.phase = 'summary') then"
                ." signal sqlstate '45000' set message_text = 'run_summary_is_sealed';"
                .' end if; end',
            );
        }
    }

    private function dropSummaryWriteBarriers(): void
    {
        foreach ([
            'em_recon_item_folder_sum_ins',
            'em_recon_item_folder_sum_upd',
            'em_recon_item_folder_sum_del',
            'em_recon_item_run_sum_insert',
            'em_recon_item_run_sum_update',
            'em_recon_item_run_sum_delete',
            'em_recon_folder_run_sum_insert',
            'em_recon_folder_run_sum_update',
            'em_recon_folder_run_sum_delete',
        ] as $trigger) {
            DB::unprepared("drop trigger if exists `{$trigger}`");
        }
    }

    /**
     * Automation is a durable side-effect boundary. Raw repair paths may not
     * make awaiting work look terminal, create an unowned running claim, or
     * attest a suppressed/failed outcome without a stable safe reason.
     */
    private function addAutomationGuard(): void
    {
        $table = 'email_provider_reconciliation_items';
        $constraint = 'em_recon_item_automation_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ("
                .$this->automationContract()
                .')',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->automationContract('NEW.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'reconciliation_automation_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$valid}) begin"
                ." select raise(abort, 'reconciliation_automation_contract_invalid'); end",
            );
        }
    }

    private function dropAutomationGuard(): void
    {
        $table = 'email_provider_reconciliation_items';
        $constraint = 'em_recon_item_automation_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    private function automationContract(string $prefix = ''): string
    {
        $column = static fn (string $name): string => $prefix === ''
            ? "`{$name}`"
            : $prefix.$name;
        $kind = $column('kind');
        $itemStatus = $column('status');
        $required = $column('automation_required');
        $status = $column('automation_status');
        $token = $column('automation_claim_token');
        $attempts = $column('automation_attempt_count');
        $lastAttemptAt = $column('automation_last_attempt_at');
        $completedAt = $column('automation_completed_at');
        $errorCode = $column('automation_error_code');
        $attemptFloor = $column('automation_rule_attempt_floor_id');

        return "{$required} in (0,1)"
            ." and (({$required} = 0"
            ." and {$status} is null"
            ." and {$token} is null"
            ." and {$attempts} = 0"
            ." and {$lastAttemptAt} is null"
            ." and {$completedAt} is null"
            ." and {$errorCode} is null"
            ." and {$attemptFloor} is null)"
            ." or ({$required} = 1"
            ." and {$kind} = 'import'"
            ." and {$itemStatus} = 'projected'"
            ." and {$status} is not null"
            ." and {$status} in ('awaiting_correlation','pending','running','awaiting_notification_fanout','completed','suppressed','failed','cancelled')"
            ." and (({$status} = 'awaiting_correlation'"
            ." and {$token} is null"
            ." and {$attempts} = 0"
            ." and {$lastAttemptAt} is null"
            ." and {$completedAt} is null"
            ." and {$errorCode} is null"
            ." and {$attemptFloor} is null)"
            ." or ({$status} = 'pending'"
            ." and {$token} is null"
            ." and {$attempts} = 0"
            ." and {$lastAttemptAt} is null"
            ." and {$completedAt} is null"
            ." and {$errorCode} is null"
            ." and {$attemptFloor} is null)"
            ." or ({$status} = 'running'"
            ." and {$token} is not null"
            ." and length({$token}) = 64"
            ." and {$attempts} >= 1"
            ." and {$lastAttemptAt} is not null"
            ." and {$completedAt} is null"
            ." and {$errorCode} is null"
            ." and {$attemptFloor} is not null)"
            ." or ({$status} = 'awaiting_notification_fanout'"
            ." and {$token} is not null"
            ." and length({$token}) = 64"
            ." and {$attempts} >= 1"
            ." and {$lastAttemptAt} is not null"
            ." and {$completedAt} is null"
            ." and {$errorCode} is null"
            ." and {$attemptFloor} is not null)"
            ." or ({$status} = 'completed'"
            ." and {$token} is null"
            ." and {$attempts} >= 1"
            ." and {$lastAttemptAt} is not null"
            ." and {$completedAt} is not null"
            ." and {$errorCode} is null"
            ." and {$attemptFloor} is not null)"
            ." or ({$status} = 'suppressed'"
            ." and {$token} is null"
            ." and {$attempts} = 0"
            ." and {$lastAttemptAt} is null"
            ." and {$completedAt} is not null"
            ." and {$errorCode} is not null"
            ." and {$attemptFloor} is null)"
            ." or ({$status} = 'failed'"
            ." and {$token} is null"
            ." and {$completedAt} is not null"
            ." and {$errorCode} is not null"
            ." and (({$attempts} = 0"
            ." and {$lastAttemptAt} is null"
            ." and {$attemptFloor} is null)"
            ." or ({$attempts} >= 1"
            ." and {$lastAttemptAt} is not null"
            ." and {$attemptFloor} is not null)))"
            ." or ({$status} = 'cancelled'"
            ." and {$token} is null"
            ." and {$attempts} = 0"
            ." and {$lastAttemptAt} is null"
            ." and {$completedAt} is not null"
            ." and {$errorCode} is not null"
            ." and {$attemptFloor} is null))))";
    }
};
