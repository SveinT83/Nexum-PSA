<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_hub_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_key', 100)->unique();
            $table->boolean('enabled')->default(false);
            $table->timestamp('grants_invalid_before')->nullable();
            $table->unsignedSmallInteger('grant_ttl_seconds')->default(300);
            $table->unsignedSmallInteger('audit_retention_days')->default(90);
            $table->unsignedInteger('default_stale_after_seconds')->default(900);
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('integration_hub_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('capability_key', 160);
            $table->string('contract_version', 20);
            $table->string('required_ability', 160);
            $table->string('required_permission', 160)->nullable();
            $table->string('input_schema', 200);
            $table->string('output_schema', 200);
            $table->string('access_mode', 20)->default('read');
            $table->string('side_effect_class', 40)->default('none');
            $table->string('risk_level', 20)->default('low');
            $table->boolean('is_reversible')->default(true);
            $table->string('idempotency_mode', 40)->default('not_applicable');
            $table->string('approval_mode', 40)->default('none');
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(30);
            $table->unsignedSmallInteger('quantity_limit')->default(50);
            $table->unsignedInteger('cost_limit_minor')->nullable();
            $table->unsignedSmallInteger('concurrency_limit')->default(5);
            $table->string('verification_method', 120);
            $table->unsignedInteger('freshness_seconds')->nullable();
            $table->json('provider_types')->nullable();
            $table->json('target_types');
            $table->string('lifecycle_state', 30)->default('active');
            $table->boolean('enabled')->default(false);
            $table->timestamp('deprecated_at')->nullable();
            $table->string('replacement_key', 160)->nullable();
            $table->string('replacement_version', 20)->nullable();
            $table->json('compatibility')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['capability_key', 'contract_version'], 'ih_capability_key_version_unique');
            $table->index(['enabled', 'lifecycle_state'], 'ih_capability_state_index');
        });

        Schema::create('integration_hub_capability_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('capability_id')->constrained('integration_hub_capabilities')->cascadeOnDelete();
            $table->string('installation_key', 100);
            $table->string('actor_kind', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('role_name', 100)->nullable();
            $table->foreignId('workload_id')->nullable()->constrained('ai_workload_profiles')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_site_id')->nullable()->constrained('client_sites')->cascadeOnDelete();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->cascadeOnDelete();
            $table->string('environment', 40)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->index(['capability_id', 'installation_key', 'enabled'], 'ih_binding_lookup_index');
        });

        Schema::create('integration_hub_execution_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('grant_id_hash', 64)->unique();
            $table->string('issuer', 255);
            $table->string('audience', 255);
            $table->string('key_id', 80);
            $table->string('service_actor_key', 120);
            $table->foreignId('issued_by_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->foreignId('workload_id')->nullable()->constrained('ai_workload_profiles')->nullOnDelete();
            $table->string('installation_key', 100);
            $table->foreignUuid('capability_id')->constrained('integration_hub_capabilities')->cascadeOnDelete();
            $table->string('capability_key', 160);
            $table->string('capability_version', 20);
            $table->json('client_ids')->nullable();
            $table->json('site_ids')->nullable();
            $table->json('integration_ids')->nullable();
            $table->string('environment', 40)->nullable();
            $table->uuid('correlation_id')->index();
            $table->string('policy_digest', 64);
            $table->string('claims_digest', 64);
            $table->timestamp('issued_at');
            $table->timestamp('not_before');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['installation_key', 'capability_key', 'capability_version'], 'ih_grant_scope_index');
        });

        Schema::create('integration_hub_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id')->index();
            $table->string('installation_key', 100);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->foreignId('workload_id')->nullable()->constrained('ai_workload_profiles')->nullOnDelete();
            $table->unsignedBigInteger('service_actor_id')->nullable()->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('client_site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->string('capability_key', 160);
            $table->string('capability_version', 20);
            $table->string('environment', 40)->nullable();
            $table->string('target_type', 80)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->json('request_summary')->nullable();
            $table->json('outcome_summary')->nullable();
            $table->string('plan_digest', 64)->nullable();
            $table->string('policy_digest', 64);
            $table->string('idempotency_key', 191)->nullable();
            $table->string('idempotency_digest', 64)->nullable()->unique();
            $table->string('status', 30)->default('queued');
            $table->string('result_status', 30)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('retain_until')->nullable()->index();
            $table->timestamps();
            $table->index(['installation_key', 'status', 'created_at'], 'ih_execution_state_index');
        });

        Schema::create('integration_hub_execution_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('execution_id')->constrained('integration_hub_executions')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('step_key', 120);
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->json('checkpoint')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['execution_id', 'sequence'], 'ih_execution_step_sequence_unique');
        });

        Schema::create('integration_hub_approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('execution_id')->constrained('integration_hub_executions')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('plan_digest', 64);
            $table->json('scope');
            $table->string('risk_level', 20);
            $table->string('status', 30)->default('pending');
            $table->timestamp('expires_at')->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_hub_approval_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_request_id')->unique()->constrained('integration_hub_approval_requests')->cascadeOnDelete();
            $table->string('decision', 30);
            $table->unsignedBigInteger('decided_by')->index();
            $table->string('reason_code', 120)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_hub_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_id')->index();
            $table->foreignUuid('execution_id')->nullable()->constrained('integration_hub_executions')->nullOnDelete();
            $table->foreignUuid('execution_grant_id')->nullable()->constrained('integration_hub_execution_grants')->nullOnDelete();
            $table->string('installation_key', 100);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->foreignId('workload_id')->nullable()->constrained('ai_workload_profiles')->nullOnDelete();
            $table->unsignedBigInteger('service_actor_id')->nullable()->index();
            $table->string('capability_key', 160)->nullable();
            $table->string('capability_version', 20)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('client_site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->string('decision', 30);
            $table->string('result_status', 30);
            $table->string('reason_code', 120);
            $table->string('source', 80)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('freshness_status', 30)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('route_name', 191)->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->json('sanitized_context')->nullable();
            $table->timestamp('retain_until')->nullable()->index();
            $table->timestamps();
            $table->index(['installation_key', 'decision', 'created_at'], 'ih_audit_decision_index');
        });

        Schema::create('integration_hub_emergency_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('installation_key', 100);
            $table->string('control_key', 191);
            $table->string('scope_type', 30);
            $table->string('scope_id', 191)->nullable();
            $table->string('capability_key', 160)->nullable();
            $table->string('capability_version', 20)->nullable();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_site_id')->nullable()->constrained('client_sites')->cascadeOnDelete();
            $table->boolean('is_disabled')->default(true);
            $table->string('reason_code', 120);
            $table->string('reason_summary', 500)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->uuid('correlation_id')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
            $table->unique(['installation_key', 'control_key'], 'ih_control_key_unique');
        });

        Schema::create('integration_hub_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('installation_key', 100);
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('client_site_id')->constrained('client_sites')->restrictOnDelete();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->string('environment', 40)->default('unknown');
            $table->string('hostname_ascii', 253);
            $table->string('hostname_unicode', 253)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->string('lifecycle_state', 30)->default('active');
            $table->string('verification_status', 30)->default('unknown');
            $table->timestamp('observed_at')->nullable();
            $table->unsignedInteger('stale_after_seconds')->default(900);
            $table->timestamp('last_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['installation_key', 'environment', 'hostname_ascii'], 'ih_domain_hostname_unique');
            $table->index(['installation_key', 'client_id', 'client_site_id'], 'ih_domain_scope_index');
        });

        Schema::table('integrations', function (Blueprint $table): void {
            $table->string('owner_scope', 30)->default('internal')->after('type');
            $table->string('installation_key', 100)->default('installation')->after('owner_scope');
            $table->foreignId('client_id')->nullable()->after('installation_key')->constrained('clients')->nullOnDelete();
            $table->foreignId('client_site_id')->nullable()->after('client_id')->constrained('client_sites')->nullOnDelete();
            $table->string('environment', 40)->default('unknown')->after('client_site_id');
            $table->string('health_status', 30)->default('unknown')->after('is_healthy');
            $table->string('health_failure_code', 120)->nullable()->after('health_status');
            $table->timestamp('health_observed_at')->nullable()->after('health_failure_code');
            $table->unsignedInteger('health_stale_after_seconds')->default(900)->after('health_observed_at');
            $table->timestamp('last_successful_observation_at')->nullable()->after('health_stale_after_seconds');
            $table->index(['installation_key', 'owner_scope', 'status'], 'integrations_hub_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table): void {
            $table->dropIndex('integrations_hub_scope_index');
            $table->dropForeign(['client_id']);
            $table->dropForeign(['client_site_id']);
            $table->dropColumn([
                'owner_scope', 'installation_key', 'client_id', 'client_site_id', 'environment',
                'health_status', 'health_failure_code', 'health_observed_at',
                'health_stale_after_seconds', 'last_successful_observation_at',
            ]);
        });

        Schema::dropIfExists('integration_hub_domains');
        Schema::dropIfExists('integration_hub_emergency_controls');
        Schema::dropIfExists('integration_hub_audit_events');
        Schema::dropIfExists('integration_hub_approval_decisions');
        Schema::dropIfExists('integration_hub_approval_requests');
        Schema::dropIfExists('integration_hub_execution_steps');
        Schema::dropIfExists('integration_hub_executions');
        Schema::dropIfExists('integration_hub_execution_grants');
        Schema::dropIfExists('integration_hub_capability_bindings');
        Schema::dropIfExists('integration_hub_capabilities');
        Schema::dropIfExists('integration_hub_settings');
    }
};
