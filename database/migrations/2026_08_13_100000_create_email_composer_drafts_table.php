<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_composer_drafts')) {
            return;
        }

        Schema::create('email_composer_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('user_management')->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->foreignId('email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements')->nullOnDelete();
            $table->string('mode', 24);
            $table->string('draft_key', 160);
            $table->string('status', 24)->default('active');
            $table->text('to_recipients')->nullable();
            $table->text('cc_recipients')->nullable();
            $table->string('subject', 512)->nullable();
            $table->mediumText('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'draft_key'], 'email_composer_drafts_user_key_unique');
            $table->index(['user_id', 'status', 'last_saved_at'], 'email_composer_drafts_user_status_index');
            $table->index(['email_account_id', 'mode', 'status'], 'email_composer_drafts_account_mode_index');
            $table->index('email_mailbox_placement_id', 'email_composer_drafts_placement_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_composer_drafts');
    }
};
