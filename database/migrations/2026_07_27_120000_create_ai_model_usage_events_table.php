<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the sanitized, attempt-level AI model usage ledger.
     */
    public function up(): void
    {
        Schema::create('ai_model_usage_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            // User ownership is resolved in application code for compatibility with
            // installations that retain a legacy user-management key definition.
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->foreignId('work_context_id')->nullable()->constrained('work_contexts')->nullOnDelete();
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->foreignId('ai_chat_id')->nullable()->constrained('ai_chats')->nullOnDelete();
            $table->foreignId('ai_chat_message_id')->nullable()->constrained('ai_chat_messages')->nullOnDelete();
            $table->string('feature_key', 120);
            $table->string('operation_key', 120);
            $table->string('domain', 80);
            $table->string('billing_classification', 60)->default('internal');
            $table->string('correlation_id', 191)->nullable();
            $table->string('requested_model', 191);
            $table->string('actual_model', 191)->nullable();
            $table->string('endpoint_kind', 60);
            $table->string('provider_request_id', 191)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('status', 30);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('finish_reason', 120)->nullable();
            $table->string('error_category', 120)->nullable();
            $table->string('error_code', 191)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('audio_input_tokens')->nullable();
            $table->unsignedBigInteger('audio_output_tokens')->nullable();
            $table->string('usage_source', 40)->default('unavailable');
            $table->decimal('provider_reported_cost', 24, 12)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->json('provider_timing')->nullable();
            $table->json('non_token_usage')->nullable();
            $table->json('provider_usage')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['execution_id', 'attempt_number'], 'ai_usage_execution_attempt_unique');
            $table->index(['feature_key', 'created_at'], 'ai_usage_feature_created_idx');
            $table->index(['ai_provider_id', 'created_at'], 'ai_usage_provider_created_idx');
            $table->index(['ai_agent_id', 'created_at'], 'ai_usage_agent_created_idx');
            $table->index(['work_context_id', 'created_at'], 'ai_usage_work_context_created_idx');
            $table->index(['requested_model', 'created_at'], 'ai_usage_model_created_idx');
            $table->index(['status', 'created_at'], 'ai_usage_status_created_idx');
            $table->index(['subject_type', 'subject_id'], 'ai_usage_subject_idx');
        });
    }

    /**
     * Drop only the AI usage ledger introduced by this slice.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_usage_events');
    }
};
