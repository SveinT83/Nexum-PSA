<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_alerts', function (Blueprint $table): void {
            $table->string('severity', 32)->default('warning')->after('status')->index();
            $table->json('provider_context')->nullable()->after('severity');
        });

        Schema::create('rmm_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('rule_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('stop_processing')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->json('conditions');
            $table->json('actions');
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'priority'], 'rmm_rules_runtime_order');
        });

        Schema::create('rmm_alert_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_alert_id')->nullable()->constrained('asset_alerts')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 32);
            $table->string('integration_type', 64);
            $table->string('fingerprint');
            $table->string('severity', 32);
            $table->string('title');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('processing_status', 32)->default('pending');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['asset_alert_id', 'sequence'], 'rmm_occurrence_sequence_unique');
            $table->index(['fingerprint', 'occurred_at'], 'rmm_occurrence_fingerprint_time');
            $table->index(['processing_status', 'processing_started_at'], 'rmm_occurrence_processing');
        });

        Schema::create('rmm_alert_rule_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rmm_alert_occurrence_id')
                ->constrained('rmm_alert_occurrences')
                ->restrictOnDelete();
            $table->foreignId('rmm_alert_rule_id')
                ->constrained('rmm_alert_rules')
                ->restrictOnDelete();
            $table->uuid('rule_key');
            $table->unsignedInteger('rule_revision');
            $table->string('rule_name');
            $table->boolean('matched')->default(false);
            $table->string('status', 32);
            $table->json('rule_snapshot');
            $table->json('condition_results')->nullable();
            $table->json('action_results')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['rmm_alert_occurrence_id', 'rule_key'],
                'rmm_execution_occurrence_rule_unique'
            );
            $table->index(['status', 'completed_at'], 'rmm_execution_status_time');
        });

        Schema::create('rmm_alert_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rmm_alert_occurrence_id')
                ->constrained('rmm_alert_occurrences')
                ->restrictOnDelete();
            $table->foreignId('asset_alert_id')->nullable()->constrained('asset_alerts')->nullOnDelete();
            $table->foreignId('rmm_alert_rule_execution_id')
                ->constrained('rmm_alert_rule_executions')
                ->restrictOnDelete();
            $table->uuid('rule_key');
            $table->unsignedInteger('action_index');
            $table->string('action_type', 64);
            $table->string('fingerprint');
            $table->nullableMorphs('target');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['rmm_alert_occurrence_id', 'rule_key', 'action_index'],
                'rmm_work_action_unique'
            );
            $table->index(['fingerprint', 'action_type'], 'rmm_work_fingerprint_action');
        });
    }

    public function down(): void
    {
        $auditTables = [
            'rmm_alert_rules',
            'rmm_alert_occurrences',
            'rmm_alert_rule_executions',
            'rmm_alert_work_items',
        ];
        $hasAuditData = collect($auditTables)
            ->contains(fn (string $table): bool => DB::table($table)->exists());
        $hasEnrichedAlerts = DB::table('asset_alerts')
            ->where(function ($query): void {
                $query->whereNotNull('provider_context')
                    ->orWhere('severity', '!=', 'warning');
            })
            ->exists();

        if ($hasAuditData || $hasEnrichedAlerts) {
            throw new RuntimeException('RMM Alert Rules rollback refused because rule, audit, or enriched alert evidence exists.');
        }

        Schema::dropIfExists('rmm_alert_work_items');
        Schema::dropIfExists('rmm_alert_rule_executions');
        Schema::dropIfExists('rmm_alert_occurrences');
        Schema::dropIfExists('rmm_alert_rules');

        Schema::table('asset_alerts', function (Blueprint $table): void {
            $table->dropIndex(['severity']);
            $table->dropColumn(['severity', 'provider_context']);
        });
    }
};
