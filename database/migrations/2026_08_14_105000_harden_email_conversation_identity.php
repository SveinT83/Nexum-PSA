<?php

use App\Modules\Email\Services\EmailConversationIdentityReconciler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_conversation_correlation_issues')) {
            $hasTicketLinks = Schema::hasTable('email_ticket_conversation_links');

            Schema::create('email_conversation_correlation_issues', function (Blueprint $table) use ($hasTicketLinks): void {
                $table->id();
                $table->char('fingerprint', 64)->unique('email_conv_corr_issue_fingerprint_unique');
                $table->string('issue_type', 80)->index();
                $table->string('status', 32)->default('open')->index();
                $table->foreignId('account_id')->nullable();
                $table->foreignId('email_message_id')->nullable();
                $table->foreignId('email_mailbox_placement_id')->nullable();
                $table->foreignId('source_email_conversation_id')->nullable();
                $table->foreignId('target_email_conversation_id')->nullable();
                $table->unsignedBigInteger('email_ticket_conversation_link_id')->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->json('evidence_json')->nullable();
                $table->dateTime('first_detected_at');
                $table->dateTime('last_detected_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->foreign('account_id', 'email_conv_corr_issue_account_fk')
                    ->references('id')
                    ->on('email_accounts')
                    ->nullOnDelete();
                $table->foreign('email_message_id', 'email_conv_corr_issue_message_fk')
                    ->references('id')
                    ->on('email_messages')
                    ->nullOnDelete();
                $table->foreign('email_mailbox_placement_id', 'email_conv_corr_issue_placement_fk')
                    ->references('id')
                    ->on('email_mailbox_placements')
                    ->nullOnDelete();
                $table->foreign('source_email_conversation_id', 'email_conv_corr_issue_source_fk')
                    ->references('id')
                    ->on('email_conversations')
                    ->nullOnDelete();
                $table->foreign('target_email_conversation_id', 'email_conv_corr_issue_target_fk')
                    ->references('id')
                    ->on('email_conversations')
                    ->nullOnDelete();

                if ($hasTicketLinks) {
                    $table->foreign('email_ticket_conversation_link_id', 'email_conv_corr_issue_ticket_link_fk')
                        ->references('id')
                        ->on('email_ticket_conversation_links')
                        ->nullOnDelete();
                }

                $table->index(
                    ['account_id', 'issue_type', 'status'],
                    'email_conv_corr_issue_review_index',
                );
                $table->index(
                    ['email_mailbox_placement_id', 'status'],
                    'email_conv_corr_issue_placement_index',
                );
            });
        }

        // This is intentionally forward-only: canonical mail and provider state are never rewritten.
        app(EmailConversationIdentityReconciler::class)->reconcileAll();
    }

    public function down(): void
    {
        // Projection corrections are safe to retain; rollback only removes the review ledger.
        Schema::dropIfExists('email_conversation_correlation_issues');
    }
};
