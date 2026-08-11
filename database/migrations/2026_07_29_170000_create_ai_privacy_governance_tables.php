<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_data_egress_policies', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key')->unique();
            $table->boolean('ai_enabled')->default(false);
            $table->boolean('external_processing_enabled')->default(false);
            $table->boolean('privacy_gateway_enabled')->default(true);
            $table->boolean('direct_external_enabled')->default(false);
            $table->json('allowed_processing_modes');
            $table->string('maximum_data_profile')->default('aggregate');
            $table->string('context_scope')->default('internal_only');
            $table->unsignedSmallInteger('maximum_query_days')->default(31);
            $table->unsignedSmallInteger('maximum_page_size')->default(50);
            $table->unsignedInteger('maximum_results')->default(200);
            $table->unsignedSmallInteger('requests_per_minute')->default(30);
            $table->unsignedSmallInteger('audit_retention_days')->default(90);
            $table->boolean('retain_denials')->default(true);
            $table->boolean('payload_retention_enabled')->default(false);
            $table->unsignedSmallInteger('payload_retention_days')->default(7);
            $table->boolean('employee_identification_allowed')->default(false);
            $table->text('coordination_purpose')->nullable();
            $table->text('staff_transparency_reference')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('ai_data_egress_policy_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('ai_data_egress_policies')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('policy_snapshot');
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->string('change_reason')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'revision']);
        });

        Schema::create('ai_provider_governance_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->text('purpose');
            $table->string('recipient_name');
            $table->json('processing_regions');
            $table->json('support_regions')->nullable();
            $table->string('dpa_status')->default('not_reviewed');
            $table->string('dpa_reference')->nullable();
            $table->text('subprocessor_notes')->nullable();
            $table->text('transfer_assessment')->nullable();
            $table->text('retention_declaration')->nullable();
            $table->text('training_declaration')->nullable();
            $table->string('dpia_status')->default('not_reviewed');
            $table->text('dpia_rationale')->nullable();
            $table->json('allowed_processing_modes');
            $table->string('maximum_data_profile')->default('aggregate');
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('ai_provider_id');
        });

        Schema::create('ai_model_governance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model');
            $table->string('processing_mode');
            $table->string('maximum_data_profile')->default('aggregate');
            $table->boolean('is_approved')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['ai_provider_id', 'model']);
        });

        Schema::create('ai_agent_governance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('processing_mode');
            $table->string('maximum_data_profile')->default('aggregate');
            $table->boolean('is_approved')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('ai_agent_id');
        });

        Schema::create('ai_workload_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('purpose');
            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('processing_mode')->default('local_only');
            $table->string('maximum_data_profile')->default('aggregate');
            $table->json('abilities');
            $table->json('allowed_client_ids')->nullable();
            $table->json('allowed_work_context_ids')->nullable();
            $table->boolean('employee_identification_requested')->default(false);
            $table->text('workforce_purpose')->nullable();
            $table->text('workforce_transparency_reference')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_workload_token_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('ai_workload_profile_id')->constrained('ai_workload_profiles')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->json('allowed_networks')->nullable();
            $table->unsignedSmallInteger('requests_per_minute')->default(30);
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->unique('personal_access_token_id');
        });

        Schema::create('ai_access_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->index();
            $table->foreignId('ai_workload_token_binding_id')->nullable()->constrained('ai_workload_token_bindings')->nullOnDelete();
            $table->foreignId('ai_workload_profile_id')->nullable()->constrained('ai_workload_profiles')->nullOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('route_name')->nullable();
            $table->string('requested_profile')->nullable();
            $table->string('decision');
            $table->string('reason_code');
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('result_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('sanitized_filters')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['decision', 'created_at']);
        });

        Schema::create('ai_retained_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_access_event_id')->constrained('ai_access_events')->cascadeOnDelete();
            $table->text('encrypted_request')->nullable();
            $table->text('encrypted_response')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_retained_payloads');
        Schema::dropIfExists('ai_access_events');
        Schema::dropIfExists('ai_workload_token_bindings');
        Schema::dropIfExists('ai_workload_profiles');
        Schema::dropIfExists('ai_agent_governance_policies');
        Schema::dropIfExists('ai_model_governance_policies');
        Schema::dropIfExists('ai_provider_governance_profiles');
        Schema::dropIfExists('ai_data_egress_policy_revisions');
        Schema::dropIfExists('ai_data_egress_policies');
    }
};
