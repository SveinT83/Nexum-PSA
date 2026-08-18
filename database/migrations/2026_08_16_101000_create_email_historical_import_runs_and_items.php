<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_historical_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->string('status', 32)->default('previewed')->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedBigInteger('uid_from')->nullable();
            $table->unsignedBigInteger('uid_to')->nullable();
            $table->unsignedInteger('requested_cap');
            $table->unsignedInteger('effective_cap');
            $table->json('folder_scope_json');
            $table->json('provider_snapshot_json');
            $table->char('preview_fingerprint', 64)->index();
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('already_present_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->dateTime('preview_expires_at')->index();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('cancellation_requested_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('account_id', 'em_hist_run_acct_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('requested_by', 'em_hist_run_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('cancelled_by', 'em_hist_run_cancel_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(['account_id', 'created_at'], 'em_hist_run_acct_created_ix');
        });

        Schema::create('email_historical_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_historical_import_run_id');
            $table->foreignId('email_folder_id')->nullable();
            $table->foreignId('uid_namespace_id')->nullable();
            $table->string('folder_path', 512);
            $table->unsignedBigInteger('uid_validity');
            $table->unsignedBigInteger('imap_uid');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedBigInteger('email_message_id')->nullable()->index();
            $table->unsignedBigInteger('email_mailbox_placement_id')->nullable()->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('first_attempt_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_historical_import_run_id', 'em_hist_item_run_fk')
                ->references('id')->on('email_historical_import_runs')->cascadeOnDelete();
            $table->foreign('email_folder_id', 'em_hist_item_folder_fk')
                ->references('id')->on('email_folders')->nullOnDelete();
            $table->foreign('uid_namespace_id', 'em_hist_item_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->nullOnDelete();
            $table->unique(
                ['email_historical_import_run_id', 'email_folder_id', 'uid_validity', 'imap_uid'],
                'em_hist_item_scope_uid_uq',
            );
            $table->index(
                ['email_historical_import_run_id', 'status', 'id'],
                'em_hist_item_run_status_ix',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_historical_import_items');
        Schema::dropIfExists('email_historical_import_runs');
    }
};
