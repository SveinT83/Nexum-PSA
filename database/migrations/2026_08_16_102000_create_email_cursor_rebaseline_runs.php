<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_cursor_rebaseline_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('email_folder_id')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->text('reason');
            $table->string('status', 32)->default('previewed')->index();
            $table->char('idempotency_key', 64)->unique();
            $table->char('preview_fingerprint', 64)->index();
            $table->foreignId('old_uid_namespace_id')->nullable();
            $table->foreignId('new_uid_namespace_id')->nullable();
            $table->unsignedBigInteger('old_uid_validity')->nullable();
            $table->unsignedBigInteger('observed_uid_validity')->nullable();
            $table->unsignedBigInteger('observed_uid_next')->nullable();
            $table->unsignedBigInteger('old_live_start_uid')->nullable();
            $table->unsignedBigInteger('new_live_start_uid')->nullable();
            $table->unsignedInteger('old_placement_count')->default(0);
            $table->unsignedInteger('retired_placement_count')->default(0);
            $table->json('provider_snapshot_json')->nullable();
            $table->json('blocker_codes_json')->nullable();
            $table->dateTime('preview_expires_at')->index();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('account_id', 'em_rebase_acct_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('email_folder_id', 'em_rebase_folder_fk')
                ->references('id')->on('email_folders')->nullOnDelete();
            $table->foreign('requested_by', 'em_rebase_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('old_uid_namespace_id', 'em_rebase_old_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->nullOnDelete();
            $table->foreign('new_uid_namespace_id', 'em_rebase_new_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->nullOnDelete();
            $table->index(['account_id', 'email_folder_id', 'created_at'], 'em_rebase_scope_created_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_cursor_rebaseline_runs');
    }
};
