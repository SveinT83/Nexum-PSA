<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_smart_inbox_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('email_conversation_id');
            $table->unsignedBigInteger('selected_email_mailbox_placement_id')->nullable();
            $table->string('effect_type', 40);
            $table->json('proposal_json');
            $table->char('proposal_fingerprint', 64);
            $table->text('explanation')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->char('source_fingerprint', 64);
            $table->json('source_message_ids_json');
            $table->string('schema_version', 80);
            $table->string('status', 30)->default('pending');
            $table->char('idempotency_key', 64);

            // Integration keeps the detailed provider ledger. Email retains only
            // the bounded trace needed to explain which governed run proposed this.
            $table->string('ai_execution_id', 100)->nullable();
            $table->unsignedBigInteger('ai_agent_id')->nullable();
            $table->uuid('ai_provider_id')->nullable();
            $table->string('ai_model', 191)->nullable();
            $table->unsignedInteger('ai_policy_revision')->nullable();
            $table->json('ai_trace_json')->nullable();

            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->dateTime('corrected_at')->nullable();
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->dateTime('dismissed_at')->nullable();
            $table->dateTime('stale_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->string('applied_reference_type', 120)->nullable();
            $table->string('applied_reference_id', 120)->nullable();
            // MariaDB strict mode requires an explicit application-owned value
            // for this non-null audit instant, so use DATETIME rather than TIMESTAMP.
            $table->dateTime('generated_at');
            $table->timestamps();

            $table->foreign('user_id', 'email_sis_user_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('account_id', 'email_sis_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('email_conversation_id', 'email_sis_conversation_fk')
                ->references('id')->on('email_conversations')->cascadeOnDelete();
            $table->foreign('selected_email_mailbox_placement_id', 'email_sis_placement_fk')
                ->references('id')->on('email_mailbox_placements')->nullOnDelete();
            $table->foreign('ai_agent_id', 'email_sis_agent_fk')
                ->references('id')->on('ai_agents')->nullOnDelete();
            $table->foreign('ai_provider_id', 'email_sis_provider_fk')
                ->references('id')->on('ai_providers')->nullOnDelete();
            $table->foreign('corrected_by', 'email_sis_corrected_by_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('dismissed_by', 'email_sis_dismissed_by_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('applied_by', 'email_sis_applied_by_fk')
                ->references('id')->on('user_management')->nullOnDelete();

            $table->unique('idempotency_key', 'email_sis_idempotency_unique');
            $table->index(['user_id', 'status', 'generated_at'], 'email_sis_user_queue_index');
            $table->index(
                ['account_id', 'email_conversation_id', 'status'],
                'email_sis_conversation_status_index',
            );
            $table->index(
                ['user_id', 'email_conversation_id', 'source_fingerprint'],
                'email_sis_user_source_index',
            );
        });

        Schema::create('email_smart_inbox_suggestion_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_smart_inbox_suggestion_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason_code', 100)->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json');
            // This is immutable audit evidence. DATETIME avoids MariaDB's
            // required-TIMESTAMP default rules while preserving exact event time.
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->foreign('email_smart_inbox_suggestion_id', 'email_sise_suggestion_fk')
                ->references('id')->on('email_smart_inbox_suggestions')->cascadeOnDelete();
            $table->foreign('actor_id', 'email_sise_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();

            $table->index(
                ['email_smart_inbox_suggestion_id', 'occurred_at'],
                'email_sise_suggestion_time_index',
            );
            $table->index(['event_type', 'occurred_at'], 'email_sise_type_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_smart_inbox_suggestion_events');
        Schema::dropIfExists('email_smart_inbox_suggestions');
    }
};
