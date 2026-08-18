<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sent_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_log_id')->constrained('email_logs', indexName: 'email_sent_recon_log_fk')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('email_accounts', indexName: 'email_sent_recon_account_fk')->cascadeOnDelete();
            $table->foreignId('source_email_message_id')->nullable()->constrained('email_messages', indexName: 'email_sent_recon_source_message_fk')->nullOnDelete();
            $table->foreignId('source_email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements', indexName: 'email_sent_recon_source_place_fk')->nullOnDelete();
            $table->foreignId('sent_email_message_id')->nullable()->constrained('email_messages', indexName: 'email_sent_recon_sent_message_fk')->nullOnDelete();
            $table->foreignId('sent_email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements', indexName: 'email_sent_recon_sent_place_fk')->nullOnDelete();
            $table->foreignId('sent_email_folder_id')->nullable()->constrained('email_folders', indexName: 'email_sent_recon_sent_folder_fk')->nullOnDelete();
            $table->string('rfc_message_id', 255);
            $table->string('normalized_message_id', 255);
            $table->string('idempotency_key', 160)->nullable();
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('status_message')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->unique('email_log_id', 'email_sent_reconciliations_log_unique');
            $table->index(['account_id', 'status'], 'email_sent_reconciliations_account_status_index');
            $table->index(['account_id', 'normalized_message_id'], 'email_sent_reconciliations_message_index');
            $table->index('sent_email_mailbox_placement_id', 'email_sent_reconciliations_sent_placement_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sent_reconciliations');
    }
};
