<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add durable queue, scheduler, alert, and retention evidence for supplier-order imports.
     */
    public function up(): void
    {
        if (! Schema::hasTable('storage_purchase_order_import_operations')) {
            Schema::create('storage_purchase_order_import_operations', function (Blueprint $table): void {
                $table->id();
                $table->string('operation_key')->unique();
                $table->timestamp('scheduler_heartbeat_at')->nullable();
                $table->timestamp('worker_heartbeat_at')->nullable();
                $table->index('scheduler_heartbeat_at', 'spo_import_ops_scheduler_heartbeat_idx');
                $table->index('worker_heartbeat_at', 'spo_import_ops_worker_heartbeat_idx');
                $table->timestamp('worker_sample_scheduled_at')->nullable();
                $table->unsignedInteger('worker_queue_latency_seconds')->nullable();
                $table->timestamp('last_dispatch_started_at')->nullable();
                $table->timestamp('last_dispatch_completed_at')->nullable();
                $table->unsignedInteger('last_dispatched_import_count')->default(0);
                $table->timestamp('last_health_check_at')->nullable();
                $table->timestamp('last_maintenance_at')->nullable();
                $table->unsignedInteger('last_recovered_import_count')->default(0);
                $table->timestamp('last_retention_at')->nullable();
                $table->unsignedInteger('last_retention_metadata_count')->default(0);
                $table->timestamp('last_digest_at')->nullable();
                $table->string('health_state')->default('unknown');
                $table->json('health_snapshot')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('storage_purchase_order_import_dispatches')) {
            Schema::create('storage_purchase_order_import_dispatches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('import_id')
                    ->unique()
                    ->constrained('storage_purchase_order_imports', 'id', 'spo_import_dispatch_import_fk')
                    ->cascadeOnDelete();
                $table->uuid('claim_token')->unique();
                $table->unsignedInteger('dispatch_count')->default(0);
                $table->string('previous_status')->nullable();
                $table->string('status')->default('claimed');
                $table->timestamp('claimed_at');
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('worker_started_at')->nullable();
                $table->timestamp('worker_completed_at')->nullable();
                $table->string('last_outcome')->nullable();
                $table->timestamps();

                $table->index(['status', 'claimed_at'], 'storage_po_import_dispatch_work_index');
            });
        }

        if (! Schema::hasTable('storage_purchase_order_import_operational_alerts')) {
            Schema::create('storage_purchase_order_import_operational_alerts', function (Blueprint $table): void {
                $table->id();
                $table->char('dedupe_key', 64);
                $table->unique('dedupe_key', 'spo_import_ops_alert_dedupe_unique');
                $table->string('alert_type');
                $table->string('severity')->default('warning');
                $table->foreignId('import_id')
                    ->nullable()
                    ->constrained('storage_purchase_order_imports', 'id', 'spo_import_ops_alert_import_fk')
                    ->nullOnDelete();
                $table->foreignId('profile_id')
                    ->nullable()
                    ->constrained('storage_purchase_order_import_profiles', 'id', 'spo_import_ops_alert_profile_fk')
                    ->nullOnDelete();
                $table->unsignedInteger('occurrence')->default(1);
                $table->string('reason_code')->nullable();
                $table->string('title');
                $table->text('summary');
                $table->json('context')->nullable();
                $table->timestamp('first_detected_at')->useCurrent();
                $table->timestamp('last_detected_at')->useCurrent();
                $table->timestamp('last_notified_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['alert_type', 'resolved_at'], 'storage_po_import_alert_open_index');
                $table->index(['severity', 'last_detected_at'], 'storage_po_import_alert_severity_index');
            });
        }

        if (! Schema::hasTable('storage_purchase_order_import_alert_deliveries')) {
            Schema::create('storage_purchase_order_import_alert_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('alert_id')
                    ->constrained(
                        'storage_purchase_order_import_operational_alerts',
                        'id',
                        'spo_import_alert_delivery_alert_fk',
                    )
                    ->cascadeOnDelete();
                $table->unsignedInteger('occurrence');
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('user_management', 'id', 'spo_import_alert_delivery_user_fk')
                    ->nullOnDelete();
                $table->string('status')->default('pending');
                $table->json('channels')->nullable();
                $table->timestamp('delivery_started_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('failure_class')->nullable();
                $table->timestamps();

                $table->unique(
                    ['alert_id', 'occurrence', 'user_id'],
                    'storage_po_import_alert_delivery_unique'
                );
                $table->index(['status', 'failed_at'], 'storage_po_import_alert_delivery_status_index');
            });
        }
    }

    /**
     * Remove operational state without touching import or Purchase Order history.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_purchase_order_import_alert_deliveries');
        Schema::dropIfExists('storage_purchase_order_import_operational_alerts');
        Schema::dropIfExists('storage_purchase_order_import_dispatches');
        Schema::dropIfExists('storage_purchase_order_import_operations');
    }
};
