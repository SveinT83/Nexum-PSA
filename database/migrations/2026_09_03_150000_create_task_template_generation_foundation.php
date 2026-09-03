<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_template_items', function (Blueprint $table): void {
            $table->integer('due_offset_minutes')->nullable()->after('estimated_minutes');
            $table->integer('scheduled_start_offset_minutes')->nullable()->after('due_offset_minutes');
            $table->integer('scheduled_end_offset_minutes')->nullable()->after('scheduled_start_offset_minutes');
        });

        Schema::create('task_template_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_group_id')->constrained('task_template_groups')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('trigger_type', 40)->index();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->foreignId('work_context_id')->nullable()->constrained('work_contexts')->nullOnDelete();
            $table->string('template_name');
            $table->timestamp('template_updated_at')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 30)->default('running')->index();
            $table->unsignedInteger('task_count')->default(0);
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('task_template_run_id')->nullable()->after('template_item_id')
                ->constrained('task_template_runs')->nullOnDelete();
        });

        Schema::table('task_recurring_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->after('owner_id')->index();
            $table->string('timezone', 64)->default('Europe/Oslo')->after('interval_config');
            $table->integer('due_offset_minutes')->nullable()->after('timezone');
            $table->unsignedBigInteger('assigned_to')->nullable()->after('due_offset_minutes')->index();
            $table->string('last_result', 30)->nullable()->after('last_run_at');
            $table->string('last_failure_reason', 1000)->nullable()->after('last_result');
        });
    }

    public function down(): void
    {
        Schema::table('task_recurring_templates', function (Blueprint $table): void {
            $table->dropColumn(['created_by', 'timezone', 'due_offset_minutes', 'assigned_to', 'last_result', 'last_failure_reason']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('task_template_run_id');
        });

        Schema::dropIfExists('task_template_runs');

        Schema::table('task_template_items', function (Blueprint $table): void {
            $table->dropColumn(['due_offset_minutes', 'scheduled_start_offset_minutes', 'scheduled_end_offset_minutes']);
        });
    }
};
