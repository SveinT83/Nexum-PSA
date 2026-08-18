<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_canonical_correlation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->string('algorithm_version', 32);
            $table->string('status', 32)->default('queued')->index();
            $table->json('account_scope_json');
            $table->unsignedBigInteger('frozen_min_message_id')->default(1);
            $table->unsignedBigInteger('frozen_max_message_id');
            $table->unsignedSmallInteger('message_cap');
            $table->unsignedSmallInteger('group_cap');
            $table->unsignedSmallInteger('pair_cap');
            $table->unsignedSmallInteger('per_group_cap');
            $table->unsignedBigInteger('evidence_snapshot_byte_cap');
            $table->unsignedBigInteger('evidence_run_byte_cap');
            $table->unsignedBigInteger('scoped_evidence_bytes')->default(0);
            $table->unsignedBigInteger('evidence_bytes_processed')->default(0);
            $table->string('discovery_phase', 32)->default('message_id');
            $table->unsignedBigInteger('cursor_message_id')->default(0);
            $table->char('scope_fingerprint', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedInteger('scoped_message_count')->default(0);
            $table->unsignedInteger('groups_processed')->default(0);
            $table->unsignedInteger('pairs_processed')->default(0);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('strong_count')->default(0);
            $table->unsignedInteger('possible_count')->default(0);
            $table->unsignedInteger('ambiguous_count')->default(0);
            $table->unsignedInteger('different_count')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('initial_scope_verified_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('requested_by', 'em_corr_run_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('cancelled_by', 'em_corr_run_cancel_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['algorithm_version', 'status', 'created_at'],
                'em_corr_run_version_status_ix',
            );
        });

        Schema::create('email_canonical_correlation_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_canonical_correlation_run_id');
            $table->unsignedBigInteger('left_email_message_id');
            $table->unsignedBigInteger('right_email_message_id');
            $table->unsignedBigInteger('left_email_account_id');
            $table->unsignedBigInteger('right_email_account_id');
            $table->string('candidate_class', 48)->index();
            $table->json('reason_codes_json');
            $table->char('correlation_key_hash', 64);
            $table->char('left_evidence_hash', 64);
            $table->char('right_evidence_hash', 64);
            $table->char('pair_fingerprint', 64);
            $table->unsignedSmallInteger('group_size')->default(2);
            $table->string('review_state', 32)->default('unreviewed')->index();
            $table->foreignId('reviewed_by')->nullable();
            $table->string('review_reason_code', 80)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_canonical_correlation_run_id', 'em_corr_candidate_run_fk')
                ->references('id')->on('email_canonical_correlation_runs')->cascadeOnDelete();
            $table->foreign('reviewed_by', 'em_corr_candidate_reviewer_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique(
                ['email_canonical_correlation_run_id', 'left_email_message_id', 'right_email_message_id'],
                'em_corr_candidate_run_pair_uq',
            );
            $table->index(
                ['left_email_account_id', 'right_email_account_id', 'review_state'],
                'em_corr_candidate_accounts_review_ix',
            );
            $table->index(
                ['correlation_key_hash', 'candidate_class'],
                'em_corr_candidate_key_class_ix',
            );
        });

        Schema::create('email_canonical_correlation_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_canonical_correlation_candidate_id');
            $table->foreignId('inspected_by')->nullable();
            $table->char('left_evidence_hash', 64);
            $table->char('right_evidence_hash', 64);
            $table->dateTime('inspected_at');
            $table->dateTime('created_at')->nullable();

            $table->foreign('email_canonical_correlation_candidate_id', 'em_corr_inspection_candidate_fk')
                ->references('id')->on('email_canonical_correlation_candidates')->cascadeOnDelete();
            $table->foreign('inspected_by', 'em_corr_inspection_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique(
                ['email_canonical_correlation_candidate_id', 'inspected_by', 'left_evidence_hash', 'right_evidence_hash'],
                'em_corr_inspection_evidence_uq',
            );
        });
    }

    public function down(): void
    {
        $hasReviewedCandidates = Schema::hasTable('email_canonical_correlation_candidates')
            && DB::table('email_canonical_correlation_candidates')
                ->where('review_state', '!=', 'unreviewed')
                ->exists();
        $hasInspectionAudit = Schema::hasTable('email_canonical_correlation_inspections')
            && DB::table('email_canonical_correlation_inspections')->exists();
        if ($hasReviewedCandidates || $hasInspectionAudit) {
            throw new \RuntimeException(
                'Reviewed or inspected canonical-correlation evidence must be exported or carried forward before rollback.',
            );
        }

        Schema::dropIfExists('email_canonical_correlation_inspections');
        Schema::dropIfExists('email_canonical_correlation_candidates');
        Schema::dropIfExists('email_canonical_correlation_runs');
    }
};
