<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_canonical_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('root_source_email_message_id');
            $table->string('algorithm_version', 40);
            $table->string('status', 24)->default('active')->index();
            $table->string('normalized_message_id', 255)->nullable()->index();
            $table->string('message_id', 255)->nullable();
            $table->string('subject', 512)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable()->index();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->json('headers_json')->nullable();
            $table->string('in_reply_to', 255)->nullable();
            $table->longText('references')->nullable();
            $table->string('direction', 16)->nullable();
            $table->dateTime('received_at')->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_oversize')->default(false);
            $table->longText('body_html_sanitized')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('raw_path', 1024)->nullable();
            $table->char('raw_source_sha256', 64)->nullable();
            $table->unsignedInteger('attachments_count')->default(0);
            $table->char('checksum_sha1', 40)->nullable();
            $table->char('strict_evidence_hash', 64)->index();
            $table->char('root_projection_hash', 64);
            $table->boolean('evidence_complete')->default(false)->index();
            $table->unsignedInteger('source_count')->default(1);
            $table->dateTime('last_verified_at')->nullable();
            $table->dateTime('drifted_at')->nullable();
            $table->timestamps();

            $table->foreign('root_source_email_message_id', 'em_canon_msg_root_fk')
                ->references('id')->on('email_messages')->restrictOnDelete();
            $table->index(
                ['root_source_email_message_id', 'status'],
                'em_canon_msg_root_status_ix',
            );
        });

        Schema::create('email_canonical_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_email_message_id');
            $table->unsignedBigInteger('source_email_attachment_id');
            $table->unsignedSmallInteger('position');
            $table->string('filename', 255);
            $table->string('content_type', 255)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 40);
            $table->string('path', 1024);
            $table->boolean('is_inline')->default(false);
            $table->string('cid', 255)->nullable();
            $table->char('checksum_sha1', 40);
            $table->char('actual_sha256', 64);
            $table->dateTime('created_at')->nullable();

            $table->foreign('canonical_email_message_id', 'em_canon_att_msg_fk')
                ->references('id')->on('email_canonical_messages')->cascadeOnDelete();
            $table->foreign('source_email_attachment_id', 'em_canon_att_source_fk')
                ->references('id')->on('email_attachments')->restrictOnDelete();
            $table->unique(
                ['canonical_email_message_id', 'position'],
                'em_canon_att_msg_position_uq',
            );
            $table->index('source_email_attachment_id', 'em_canon_att_source_ix');
        });

        Schema::create('email_canonical_message_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_email_message_id');
            $table->unsignedBigInteger('source_email_message_id');
            $table->string('mapping_kind', 32);
            $table->char('strict_evidence_hash', 64);
            $table->char('source_state_hash', 64);
            $table->boolean('evidence_complete')->default(false);
            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->dateTime('mapped_at');
            $table->timestamps();

            $table->foreign('canonical_email_message_id', 'em_canon_source_msg_fk')
                ->references('id')->on('email_canonical_messages')->restrictOnDelete();
            $table->foreign('source_email_message_id', 'em_canon_source_source_fk')
                ->references('id')->on('email_messages')->restrictOnDelete();
            $table->foreign('mapped_by', 'em_canon_source_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique('source_email_message_id', 'em_canon_source_source_uq');
            $table->index(
                ['canonical_email_message_id', 'source_email_message_id'],
                'em_canon_source_component_ix',
            );
        });

        Schema::create('email_canonical_cutover_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->unsignedBigInteger('rolled_back_by')->nullable();
            $table->unsignedBigInteger('source_correlation_run_id')->nullable();
            $table->string('operation', 32)->index();
            $table->string('status', 24)->default('previewed')->index();
            $table->string('algorithm_version', 40);
            $table->json('account_scope_json');
            $table->unsignedBigInteger('frozen_min_message_id')->nullable();
            $table->unsignedBigInteger('frozen_max_message_id')->nullable();
            $table->unsignedInteger('item_cap');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('rolled_back_count')->default(0);
            $table->char('scope_fingerprint', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('requested_mode', 16)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_class', 255)->nullable();
            $table->dateTime('previewed_at');
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('rolled_back_at')->nullable();
            $table->timestamps();

            $table->foreign('requested_by', 'em_cutover_run_requester_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('applied_by', 'em_cutover_run_applier_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('rolled_back_by', 'em_cutover_run_rollback_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('source_correlation_run_id', 'em_cutover_run_corr_fk')
                ->references('id')->on('email_canonical_correlation_runs')->restrictOnDelete();
            $table->index(
                ['operation', 'status', 'created_at'],
                'em_cutover_run_operation_status_ix',
            );
        });

        Schema::create('email_canonical_cutover_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_canonical_cutover_run_id');
            $table->string('item_key', 96);
            $table->string('item_kind', 32)->index();
            $table->char('component_key', 64)->nullable()->index();
            $table->unsignedBigInteger('email_account_id');
            $table->unsignedBigInteger('source_email_message_id')->nullable();
            $table->unsignedBigInteger('proposed_root_source_message_id')->nullable();
            $table->unsignedBigInteger('previous_canonical_email_message_id')->nullable();
            $table->unsignedBigInteger('applied_canonical_email_message_id')->nullable();
            $table->string('previous_mapping_kind', 32)->nullable();
            $table->char('previous_evidence_hash', 64)->nullable();
            $table->char('previous_source_state_hash', 64)->nullable();
            $table->boolean('previous_evidence_complete')->nullable();
            $table->unsignedBigInteger('previous_mapped_by')->nullable();
            $table->dateTime('previous_mapped_at')->nullable();
            $table->char('previous_canonical_state_hash', 64)->nullable();
            $table->char('strict_evidence_hash', 64)->nullable();
            $table->char('source_state_hash', 64)->nullable();
            $table->boolean('evidence_complete')->default(false);
            $table->json('correlation_candidate_ids_json')->nullable();
            $table->json('previous_placement_pointers_json')->nullable();
            $table->string('previous_read_mode', 16)->nullable();
            $table->boolean('previous_read_mode_row_exists')->nullable();
            $table->unsignedBigInteger('previous_read_mode_updated_by')->nullable();
            $table->unsignedInteger('previous_read_mode_lock_version')->nullable();
            $table->string('proposed_read_mode', 16)->nullable();
            $table->unsignedBigInteger('parity_attestation_id')->nullable();
            $table->char('parity_attestation_fingerprint', 64)->nullable();
            $table->string('status', 24)->default('previewed')->index();
            $table->string('error_code', 80)->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('rolled_back_at')->nullable();
            $table->timestamps();

            $table->foreign('email_canonical_cutover_run_id', 'em_cutover_item_run_fk')
                ->references('id')->on('email_canonical_cutover_runs')->cascadeOnDelete();
            $table->foreign('email_account_id', 'em_cutover_item_account_fk')
                ->references('id')->on('email_accounts')->restrictOnDelete();
            $table->foreign('source_email_message_id', 'em_cutover_item_source_fk')
                ->references('id')->on('email_messages')->restrictOnDelete();
            $table->foreign('proposed_root_source_message_id', 'em_cutover_item_root_fk')
                ->references('id')->on('email_messages')->restrictOnDelete();
            $table->foreign('previous_canonical_email_message_id', 'em_cutover_item_previous_fk')
                ->references('id')->on('email_canonical_messages')->restrictOnDelete();
            $table->foreign('applied_canonical_email_message_id', 'em_cutover_item_applied_fk')
                ->references('id')->on('email_canonical_messages')->restrictOnDelete();
            $table->unique(
                ['email_canonical_cutover_run_id', 'item_key'],
                'em_cutover_item_run_key_uq',
            );
            $table->index(
                ['source_email_message_id', 'status'],
                'em_cutover_item_source_status_ix',
            );
        });

        Schema::create('email_canonical_read_modes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_account_id');
            $table->string('mode', 16)->default('legacy')->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign('email_account_id', 'em_canon_mode_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('updated_by', 'em_canon_mode_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique('email_account_id', 'em_canon_mode_account_uq');
        });

        Schema::create('email_canonical_parity_attestations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_account_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->string('algorithm_version', 40);
            $table->string('status', 24)->default('pending')->index();
            $table->boolean('strict_evidence')->default(true);
            $table->unsignedBigInteger('frozen_max_placement_id')->default(0);
            $table->unsignedBigInteger('frozen_active_placement_count')->default(0);
            $table->unsignedBigInteger('next_placement_id')->default(0);
            $table->unsignedBigInteger('verified_placement_count')->default(0);
            $table->unsignedBigInteger('total_evidence_bytes')->default(0);
            $table->char('scope_state_hash', 64);
            $table->char('rolling_evidence_hash', 64);
            $table->char('attestation_fingerprint', 64)->nullable()->index('em_parity_attest_fingerprint_ix');
            $table->string('error_code', 80)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('email_account_id', 'em_parity_attest_account_fk')
                ->references('id')->on('email_accounts')->restrictOnDelete();
            $table->foreign('requested_by', 'em_parity_attest_requester_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('completed_by', 'em_parity_attest_completer_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['email_account_id', 'strict_evidence', 'status', 'id'],
                'em_parity_attest_account_status_ix',
            );
        });

        Schema::create('email_canonical_parity_attestation_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_canonical_parity_attestation_id');
            $table->unsignedBigInteger('email_mailbox_placement_id');
            $table->unsignedBigInteger('source_email_message_id');
            $table->unsignedBigInteger('canonical_email_message_id');
            $table->char('source_state_hash', 64);
            $table->char('strict_evidence_hash', 64)->nullable();
            $table->char('canonical_projection_hash', 64);
            $table->char('placement_state_hash', 64);
            $table->unsignedBigInteger('evidence_bytes')->default(0);
            $table->dateTime('created_at');

            $table->foreign('email_canonical_parity_attestation_id', 'em_parity_item_attest_fk')
                ->references('id')->on('email_canonical_parity_attestations')->cascadeOnDelete();
            $table->unique(
                ['email_canonical_parity_attestation_id', 'email_mailbox_placement_id'],
                'em_parity_item_attest_placement_uq',
            );
            $table->index('source_email_message_id', 'em_parity_item_source_ix');
        });

        Schema::table('email_canonical_cutover_items', function (Blueprint $table): void {
            $table->foreign('parity_attestation_id', 'em_cutover_item_parity_attest_fk')
                ->references('id')->on('email_canonical_parity_attestations')->restrictOnDelete();
        });

        Schema::table('email_mailbox_placements', function (Blueprint $table): void {
            $table->unsignedBigInteger('canonical_email_message_id')
                ->nullable()
                ->after('email_message_id');
            $table->foreign('canonical_email_message_id', 'em_placement_canonical_fk')
                ->references('id')->on('email_canonical_messages')->restrictOnDelete();
            $table->index(
                ['account_id', 'canonical_email_message_id'],
                'em_placement_account_canonical_ix',
            );
        });
    }

    public function down(): void
    {
        $durableTables = [
            'email_canonical_messages',
            'email_canonical_message_attachments',
            'email_canonical_message_sources',
            'email_canonical_cutover_runs',
            'email_canonical_cutover_items',
            'email_canonical_read_modes',
            'email_canonical_parity_attestations',
            'email_canonical_parity_attestation_items',
        ];
        $hasDurableEvidence = collect($durableTables)->contains(
            fn (string $table): bool => Schema::hasTable($table) && DB::table($table)->exists(),
        );
        $hasPlacementPointers = Schema::hasColumn(
            'email_mailbox_placements',
            'canonical_email_message_id',
        ) && DB::table('email_mailbox_placements')
            ->whereNotNull('canonical_email_message_id')
            ->exists();

        if ($hasDurableEvidence || $hasPlacementPointers) {
            throw new RuntimeException(
                'Canonical projections, pointers, modes, previews, and parity attestations are durable audit evidence and must be preserved before schema rollback.',
            );
        }

        if (Schema::hasColumn('email_mailbox_placements', 'canonical_email_message_id')) {
            Schema::table('email_mailbox_placements', function (Blueprint $table): void {
                DB::getDriverName() === 'sqlite'
                    ? $table->dropForeign(['canonical_email_message_id'])
                    : $table->dropForeign('em_placement_canonical_fk');
                $table->dropIndex('em_placement_account_canonical_ix');
                $table->dropColumn('canonical_email_message_id');
            });
        }

        Schema::dropIfExists('email_canonical_read_modes');
        Schema::dropIfExists('email_canonical_cutover_items');
        Schema::dropIfExists('email_canonical_cutover_runs');
        Schema::dropIfExists('email_canonical_parity_attestation_items');
        Schema::dropIfExists('email_canonical_parity_attestations');
        Schema::dropIfExists('email_canonical_message_sources');
        Schema::dropIfExists('email_canonical_message_attachments');
        Schema::dropIfExists('email_canonical_messages');
    }
};
