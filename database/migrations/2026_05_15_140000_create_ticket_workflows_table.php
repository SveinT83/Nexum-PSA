<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(10);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ticket_workflow_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_workflow_id')->constrained('ticket_workflows')->cascadeOnDelete();
            $table->foreignId('ticket_status_id')->constrained('ticket_statuses')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('requires_note')->default(false);
            $table->boolean('requires_resolution')->default(false);
            $table->boolean('requires_knowledge_update')->default(false);
            $table->unsignedInteger('sort_order')->default(10);
            $table->timestamps();

            $table->unique(['ticket_workflow_id', 'ticket_status_id'], 'workflow_state_status_unique');
        });

        Schema::create('ticket_workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_workflow_id')->constrained('ticket_workflows')->cascadeOnDelete();
            $table->foreignId('from_status_id')->constrained('ticket_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('ticket_statuses')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('requires_note')->default(false);
            $table->boolean('requires_resolution')->default(false);
            $table->boolean('requires_knowledge_update')->default(false);
            $table->unsignedInteger('sort_order')->default(10);
            $table->timestamps();

            $table->unique(['ticket_workflow_id', 'from_status_id', 'to_status_id'], 'workflow_transition_unique');
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'workflow_id')) {
                $table->foreignId('workflow_id')
                    ->nullable()
                    ->after('sla_snapshot')
                    ->constrained('ticket_workflows')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'workflow_id')) {
                $table->dropConstrainedForeignId('workflow_id');
            }
        });

        Schema::dropIfExists('ticket_workflow_transitions');
        Schema::dropIfExists('ticket_workflow_states');
        Schema::dropIfExists('ticket_workflows');
    }
};
