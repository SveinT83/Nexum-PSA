<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_provider_inventory_runs', function (Blueprint $table): void {
            $table->id();

            // Keep the numeric account reference after account removal. These rows are
            // minimal lifecycle audit, not live mailbox data or an authorization source.
            $table->unsignedBigInteger('account_id');
            $table->string('provider', 30)->default('imap');
            $table->string('status', 40)->default('running');
            $table->unsignedInteger('max_folders');
            $table->unsignedInteger('max_messages_per_folder');
            $table->unsignedInteger('folder_count')->default(0);
            $table->unsignedInteger('complete_folder_count')->default(0);
            $table->unsignedInteger('scanned_message_count')->default(0);
            $table->unsignedInteger('confirmed_missing_count')->default(0);
            $table->unsignedInteger('confirmed_move_count')->default(0);
            $table->unsignedInteger('ambiguous_count')->default(0);
            $table->char('inventory_scope_fingerprint', 64)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'status', 'started_at'],
                'email_provider_inv_run_account_index',
            );
        });

        Schema::create('email_provider_inventory_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_provider_inventory_run_id');
            $table->foreign(
                'email_provider_inventory_run_id',
                'email_provider_inv_folder_run_fk',
            )->references('id')->on('email_provider_inventory_runs')->cascadeOnDelete();

            // Folder/account IDs and the path snapshot remain useful after a provider
            // folder is renamed or removed. No message content or provider payload is stored.
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('email_folder_id')->nullable();
            $table->string('folder_path', 512);
            $table->string('status', 40);
            $table->string('reason_code', 100)->nullable();
            $table->unsignedBigInteger('expected_uid_validity')->nullable();
            $table->unsignedBigInteger('observed_uid_validity')->nullable();
            $table->unsignedBigInteger('start_uid_next')->nullable();
            $table->unsignedBigInteger('end_uid_next')->nullable();
            $table->unsignedInteger('start_exists_count')->nullable();
            $table->unsignedInteger('end_exists_count')->nullable();
            $table->unsignedInteger('scanned_message_count')->default(0);
            $table->char('inventory_fingerprint', 64)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at');
            $table->dateTime('created_at');

            $table->unique(
                ['email_provider_inventory_run_id', 'folder_path'],
                'email_provider_inv_folder_path_unique',
            );
            $table->index(
                ['account_id', 'status', 'finished_at'],
                'email_provider_inv_folder_account_index',
            );
        });

        Schema::create('email_provider_placement_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_provider_inventory_run_id');
            $table->foreign(
                'email_provider_inventory_run_id',
                'email_provider_finding_run_fk',
            )->references('id')->on('email_provider_inventory_runs')->cascadeOnDelete();
            $table->foreignId('email_provider_inventory_folder_id');
            $table->foreign(
                'email_provider_inventory_folder_id',
                'email_provider_finding_folder_fk',
            )->references('id')->on('email_provider_inventory_folders')->cascadeOnDelete();

            // Deliberately avoid foreign keys for source/target facts. The provider
            // placement or Mail cache may later be removed while this minimal audit remains.
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('source_placement_id');
            $table->unsignedBigInteger('email_message_id');
            $table->unsignedBigInteger('email_conversation_id')->nullable();
            $table->unsignedBigInteger('source_folder_id');
            $table->string('source_folder_path', 512);
            $table->unsignedBigInteger('source_uid_validity');
            $table->unsignedBigInteger('source_uid');
            $table->string('finding_type', 40);
            $table->string('reason_code', 100);
            $table->char('identity_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('target_placement_id')->nullable();
            $table->unsignedBigInteger('target_folder_id')->nullable();
            $table->string('target_folder_path', 512)->nullable();
            $table->unsignedBigInteger('target_uid_validity')->nullable();
            $table->unsignedBigInteger('target_uid')->nullable();
            $table->dateTime('cleanup_due_at')->nullable();
            $table->dateTime('observed_at');
            $table->dateTime('created_at');

            $table->unique(
                ['email_provider_inventory_run_id', 'source_placement_id'],
                'email_provider_finding_source_unique',
            );
            $table->index(
                ['email_message_id', 'finding_type', 'cleanup_due_at'],
                'email_provider_finding_cleanup_index',
            );
            $table->index(
                ['account_id', 'finding_type', 'observed_at'],
                'email_provider_finding_account_index',
            );
        });

        Schema::create('email_provider_deletion_cleanup_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_provider_placement_finding_id')->nullable();
            $table->foreign(
                'email_provider_placement_finding_id',
                'email_provider_cleanup_finding_fk',
            )->references('id')->on('email_provider_placement_findings')->nullOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('email_message_id');
            $table->string('status', 40)->default('checking');
            $table->json('reasons_json')->nullable();
            $table->boolean('had_raw_payload')->default(false);
            $table->unsignedInteger('local_attachment_file_count')->default(0);
            $table->unsignedInteger('smart_inbox_suggestion_count')->default(0);
            $table->string('failure_code', 100)->nullable();
            $table->dateTime('retry_after')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['email_message_id', 'status', 'started_at'],
                'email_provider_cleanup_message_index',
            );
            $table->index(
                ['status', 'retry_after'],
                'email_provider_cleanup_retry_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_provider_deletion_cleanup_attempts');
        Schema::dropIfExists('email_provider_placement_findings');
        Schema::dropIfExists('email_provider_inventory_folders');
        Schema::dropIfExists('email_provider_inventory_runs');
    }
};
