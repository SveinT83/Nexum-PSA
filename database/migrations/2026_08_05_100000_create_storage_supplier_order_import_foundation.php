<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create fail-closed policy, import, line, and attempt records.
     */
    public function up(): void
    {
        Schema::create('storage_purchase_order_automation_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Default supplier-order policy');
            $table->boolean('is_current')->default(true)->index();
            $table->string('runtime_mode')->default('off');
            $table->string('default_outcome')->default('needs_attention');
            $table->foreignId('automation_user_id')->nullable()->constrained('user_management', 'id', 'spo_auto_policy_actor_fk')->nullOnDelete();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('storage_warehouses', 'id', 'spo_auto_policy_warehouse_fk')->nullOnDelete();
            $table->foreignId('ai_workload_profile_id')->nullable()->constrained('ai_workload_profiles', 'id', 'spo_auto_policy_workload_fk')->nullOnDelete();
            $table->string('ai_mode')->default('off');
            $table->string('provider_outage_behavior')->default('needs_attention');
            $table->unsignedTinyInteger('deterministic_confidence_threshold')->default(100);
            $table->unsignedTinyInteger('ai_confidence_threshold')->default(98);
            $table->decimal('amount_tolerance', 12, 4)->default(0.01);
            $table->unsignedInteger('max_lines')->default(250);
            $table->unsignedInteger('max_quantity_per_line')->default(10000);
            $table->decimal('max_order_total', 15, 2)->default(1000000);
            $table->unsignedInteger('max_new_items')->default(0);
            $table->string('supplier_bootstrap_mode')->default('existing_only');
            $table->string('new_item_mode')->default('review_only');
            $table->unsignedInteger('retry_limit')->default(3);
            $table->unsignedInteger('retry_base_seconds')->default(60);
            $table->unsignedInteger('ai_timeout_seconds')->default(30);
            $table->unsignedInteger('ai_max_output_tokens')->default(4000);
            $table->decimal('ai_max_cost_per_import', 12, 4)->nullable();
            $table->string('ai_cost_currency', 3)->nullable();
            $table->string('ai_consensus_mode')->default('off');
            $table->foreignId('ai_consensus_workload_profile_id')
                ->nullable()
                ->constrained('ai_workload_profiles', 'id', 'spo_auto_policy_consensus_workload_fk')
                ->nullOnDelete();
            $table->unsignedInteger('circuit_breaker_failures')->default(5);
            $table->unsignedInteger('retention_days')->default(730);
            $table->boolean('silent_success')->default(true);
            $table->boolean('daily_digest_enabled')->default(false);
            $table->json('advanced_rules')->nullable();
            $table->unsignedInteger('revision_number')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('storage_purchase_order_automation_policy_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')
                ->constrained('storage_purchase_order_automation_policies', 'id', 'spo_auto_revision_policy_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('snapshot');
            $table->char('checksum', 64);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management', 'id', 'spo_auto_revision_actor_fk')->nullOnDelete();
            $table->timestamp('activated_at');
            $table->timestamps();

            $table->unique(['policy_id', 'revision_number'], 'storage_po_policy_revision_unique');
            $table->index(['policy_id', 'activated_at'], 'storage_po_policy_revision_active_index');
        });

        Schema::create('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source_domain', 64)->default('email');
            $table->string('source_type', 128);
            $table->string('source_id', 191)->nullable();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained('signals')->nullOnDelete();
            $table->foreignId('signal_rule_id')->nullable()->constrained('signal_rules')->nullOnDelete();
            $table->string('signal_action_key')->nullable();
            $table->char('source_action_hash', 64)->unique();
            $table->char('source_fingerprint', 64);
            $table->json('safe_source_snapshot');
            $table->json('trusted_auth_snapshot')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('external_order_number')->nullable();
            $table->char('domain_identity_hash', 64)->nullable()->unique();
            $table->foreignId('revision_of_import_id')
                ->nullable()
                ->constrained('storage_purchase_order_imports')
                ->nullOnDelete();
            $table->foreignId('policy_revision_id')
                ->nullable()
                ->constrained('storage_purchase_order_automation_policy_revisions')
                ->nullOnDelete();
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->unique()
                ->constrained('storage_purchase_orders')
                ->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('stage')->default('detect');
            $table->string('reason_code')->nullable();
            $table->json('reason_context')->nullable();
            $table->string('extraction_method')->nullable();
            $table->string('canonical_schema_version')->default('1.0');
            $table->string('parser_version')->default('1.0');
            $table->json('normalized_document')->nullable();
            $table->json('validation_results')->nullable();
            $table->json('confidence_dimensions')->nullable();
            $table->json('commercial_snapshot')->nullable();
            $table->json('delivery_snapshot')->nullable();
            $table->string('decision')->nullable();
            $table->uuid('ai_execution_uuid')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('last_actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'stage', 'next_retry_at'], 'storage_po_import_work_index');
            $table->index(['vendor_id', 'external_order_number'], 'storage_po_import_vendor_order_index');
            $table->index(['source_domain', 'source_type', 'source_id'], 'storage_po_import_source_index');
            $table->index(['created_at', 'status'], 'storage_po_import_created_status_index');
        });

        Schema::create('storage_purchase_order_import_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('storage_purchase_order_imports')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('source_row_identifier')->nullable();
            $table->string('supplier_sku')->nullable();
            $table->string('normalized_supplier_sku')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 4)->nullable();
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->decimal('line_total', 15, 4)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('evidence')->nullable();
            $table->json('extracted_fields')->nullable();
            $table->json('field_confidence')->nullable();
            $table->foreignId('item_id')->nullable()->constrained('storage_items')->nullOnDelete();
            $table->string('mapping_status')->default('unresolved');
            $table->string('resolution_method')->nullable();
            $table->json('warnings')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'position'], 'storage_po_import_line_position_unique');
            $table->index(['mapping_status', 'item_id'], 'storage_po_import_line_mapping_index');
            $table->index(['normalized_supplier_sku'], 'storage_po_import_line_supplier_sku_index');
        });

        Schema::create('storage_purchase_order_import_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('storage_purchase_order_imports')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('stage');
            $table->string('method')->nullable();
            $table->string('status');
            $table->string('reason_code')->nullable();
            $table->char('input_fingerprint', 64)->nullable();
            $table->char('output_fingerprint', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('service_identity')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'attempt_number', 'stage'], 'storage_po_import_attempt_stage_unique');
            $table->index(['status', 'started_at'], 'storage_po_import_attempt_status_index');
        });
    }

    /**
     * Remove the disabled foundation in dependency order.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_purchase_order_import_attempts');
        Schema::dropIfExists('storage_purchase_order_import_lines');
        Schema::dropIfExists('storage_purchase_order_imports');
        Schema::dropIfExists('storage_purchase_order_automation_policy_revisions');
        Schema::dropIfExists('storage_purchase_order_automation_policies');
    }
};
