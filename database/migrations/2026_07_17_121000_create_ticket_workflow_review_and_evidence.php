<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_workflow_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->nullable()->constrained('ticket_workflow_versions')->nullOnDelete();
            $table->string('state_key');
            $table->string('gate_key');
            $table->string('status')->default('pending')->index();
            $table->string('evidence_fingerprint', 64);
            $table->json('requirements_snapshot')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'gate_key', 'status'], 'ticket_review_gate_status_index');
        });

        Schema::create('ticket_workflow_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('evidence_type')->index();
            $table->string('scope_key')->nullable()->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('fingerprint', 64);
            $table->string('subject_name')->nullable();
            $table->timestamp('evidenced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('invalidated_at')->nullable();
            $table->foreignId('invalidated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->text('invalidation_reason')->nullable();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'evidence_type', 'invalidated_at'], 'ticket_evidence_active_index');
            $table->index(['source_type', 'source_id'], 'ticket_evidence_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_workflow_evidence');
        Schema::dropIfExists('ticket_workflow_reviews');
    }
};
