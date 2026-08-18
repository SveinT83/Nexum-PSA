<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_unread_handover_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id');
            $table->foreignId('requested_by');
            $table->foreignId('target_user_id');
            $table->string('status', 32)->default('previewed')->index();
            $table->text('reason');
            $table->json('folder_scope_json');
            $table->dateTime('date_from');
            $table->dateTime('date_to');
            $table->unsignedSmallInteger('requested_cap');
            $table->unsignedInteger('access_epoch');
            $table->unsignedBigInteger('baseline_message_id');
            $table->char('authorization_fingerprint', 64);
            $table->char('snapshot_fingerprint', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedSmallInteger('selected_count')->default(0);
            $table->unsignedSmallInteger('applied_count')->default(0);
            $table->unsignedSmallInteger('already_unread_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->dateTime('preview_expires_at')->index();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_account_id', 'em_unread_run_account_fk')
                ->references('id')->on('email_accounts');
            $table->foreign('requested_by', 'em_unread_run_actor_fk')
                ->references('id')->on('user_management');
            $table->foreign('target_user_id', 'em_unread_run_target_fk')
                ->references('id')->on('user_management');
            $table->index(
                ['email_account_id', 'target_user_id', 'created_at'],
                'em_unread_run_account_target_ix',
            );
        });

        Schema::create('email_unread_handover_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_unread_handover_run_id');
            $table->unsignedSmallInteger('snapshot_order');
            $table->unsignedBigInteger('email_message_id');
            $table->unsignedBigInteger('email_mailbox_placement_id');
            $table->unsignedBigInteger('email_folder_id');
            $table->unsignedInteger('access_epoch');
            $table->string('status', 32)->default('previewed')->index();
            $table->dateTime('applied_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_unread_handover_run_id', 'em_unread_item_run_fk')
                ->references('id')->on('email_unread_handover_runs')->cascadeOnDelete();
            $table->unique(
                ['email_unread_handover_run_id', 'email_message_id'],
                'em_unread_item_run_message_uq',
            );
            $table->index(
                ['email_unread_handover_run_id', 'status', 'snapshot_order'],
                'em_unread_item_run_status_ix',
            );
        });
    }

    public function down(): void
    {
        $this->assertNoDurableHandoverAuditWouldBeDeleted();

        Schema::dropIfExists('email_unread_handover_items');
        Schema::dropIfExists('email_unread_handover_runs');
    }

    private function assertNoDurableHandoverAuditWouldBeDeleted(): void
    {
        if ((Schema::hasTable('email_unread_handover_runs')
                && DB::table('email_unread_handover_runs')->exists())
            || (Schema::hasTable('email_unread_handover_items')
                && DB::table('email_unread_handover_items')->exists())) {
            throw new RuntimeException(
                'Cannot roll back Email unread handover audit while durable runs or items exist; '
                .'export or carry them forward before dropping the audit schema.',
            );
        }
    }
};
