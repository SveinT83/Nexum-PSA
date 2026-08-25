<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_conversation_action_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('operation', 48);
            $table->string('scope_kind', 40);
            $table->unsignedBigInteger('active_email_account_id')->nullable();
            $table->unsignedBigInteger('active_email_conversation_id')->nullable();
            $table->boolean('target_personal_unread');
            $table->boolean('provider_seen_requested')->default(false);
            $table->string('status', 32)->index();
            $table->unsignedInteger('item_cap');
            $table->unsignedInteger('account_count')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('personal_applied_count')->default(0);
            $table->unsignedInteger('provider_pending_count')->default(0);
            $table->unsignedInteger('provider_succeeded_count')->default(0);
            $table->unsignedInteger('denied_count')->default(0);
            $table->unsignedInteger('stale_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->char('request_fingerprint', 64);
            $table->char('scope_fingerprint', 64);
            $table->string('idempotency_key', 160)->unique();
            $table->string('error_code', 80)->nullable();
            $table->dateTime('previewed_at');
            $table->dateTime('expires_at')->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('requested_by', 'em_conv_action_run_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['requested_by', 'status', 'expires_at'],
                'em_conv_action_run_actor_status_ix',
            );
        });

        Schema::create('email_conversation_action_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('run_id');
            $table->unsignedInteger('ordinal');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('email_conversation_id');
            $table->unsignedBigInteger('email_message_id');
            $table->unsignedBigInteger('email_mailbox_placement_id');
            $table->unsignedBigInteger('email_folder_id');
            $table->unsignedBigInteger('uid_namespace_id');
            $table->unsignedBigInteger('imap_uid_validity');
            $table->unsignedBigInteger('imap_uid');
            $table->unsignedInteger('access_epoch');
            $table->unsignedInteger('provider_binding_version');
            $table->unsignedInteger('placement_sync_version');
            $table->char('source_fingerprint', 64);
            $table->char('item_fingerprint', 64);
            $table->boolean('personal_selected')->default(true);
            $table->boolean('personal_before')->nullable();
            $table->boolean('personal_target');
            $table->string('personal_status', 24);
            $table->string('personal_reason_code', 80)->nullable();
            $table->boolean('provider_selected')->default(false);
            $table->boolean('provider_before')->nullable();
            $table->boolean('provider_target')->default(true);
            $table->string('provider_status', 24);
            $table->string('provider_reason_code', 80)->nullable();
            $table->unsignedBigInteger('email_remote_operation_id')->nullable();
            $table->char('claim_token', 64)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('claim_expires_at')->nullable();
            $table->dateTime('personal_applied_at')->nullable();
            $table->dateTime('provider_reserved_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id', 'em_conv_action_item_run_fk')
                ->references('id')->on('email_conversation_action_runs')->cascadeOnDelete();
            $table->unique(
                ['run_id', 'ordinal'],
                'em_conv_action_item_ordinal_uq',
            );
            $table->unique(
                ['run_id', 'email_mailbox_placement_id'],
                'em_conv_action_item_placement_uq',
            );
            $table->index(
                ['run_id', 'personal_status', 'provider_status', 'id'],
                'em_conv_action_item_progress_ix',
            );
            $table->index(
                ['account_id', 'email_conversation_id'],
                'em_conv_action_item_scope_ix',
            );
            $table->index('email_remote_operation_id', 'em_conv_action_item_remote_op_ix');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('email_conversation_action_items')
                && DB::table('email_conversation_action_items')->exists())
            || (Schema::hasTable('email_conversation_action_runs')
                && DB::table('email_conversation_action_runs')->exists())) {
            throw new \RuntimeException(
                'Refusing to remove Email conversation-action acknowledgement evidence.',
            );
        }

        Schema::dropIfExists('email_conversation_action_items');
        Schema::dropIfExists('email_conversation_action_runs');
    }
};
