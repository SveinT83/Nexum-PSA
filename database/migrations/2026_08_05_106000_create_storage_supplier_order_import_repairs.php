<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep every AI/manual correction as immutable audit evidence.
     */
    public function up(): void
    {
        Schema::create('storage_purchase_order_import_repairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')
                ->constrained('storage_purchase_order_imports', 'id', 'spo_import_repair_import_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->uuid('ai_execution_uuid')->nullable()->index();
            $table->string('status');
            $table->char('original_document_checksum', 64)->nullable();
            $table->json('corrected_document');
            $table->char('corrected_document_checksum', 64);
            $table->foreignId('profile_candidate_version_id')
                ->nullable()
                ->constrained('storage_purchase_order_import_profile_versions', 'id', 'spo_import_repair_profile_version_fk')
                ->nullOnDelete();
            $table->json('validation_results');
            $table->json('decision_summary')->nullable();
            $table->foreignId('actor_id')
                ->constrained('user_management', 'id', 'spo_import_repair_actor_fk')
                ->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['import_id', 'sequence'], 'spo_import_repair_sequence_unique');
            $table->index(['import_id', 'status', 'created_at'], 'spo_import_repair_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_purchase_order_import_repairs');
    }
};
