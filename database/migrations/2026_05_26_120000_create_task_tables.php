<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_open')->default(false);
            $table->boolean('is_in_progress')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->boolean('is_done')->default(false);
            $table->boolean('is_cancelled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('task_template_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('owner_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->foreignId('status_id')->nullable()->constrained('task_statuses')->nullOnDelete();
            $table->foreignId('queue_id')->nullable()->constrained('ticket_queues')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('ticket_priorities')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->string('visibility')->default('internal')->index();
            $table->string('source_type')->default('manual')->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('template_group_id')->nullable()->index();
            $table->unsignedBigInteger('template_item_id')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('scheduled_start_at')->nullable()->index();
            $table->timestamp('scheduled_end_at')->nullable()->index();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->unsignedBigInteger('completed_by')->nullable()->index();
            $table->boolean('blocks_owner_completion')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['status_id', 'assigned_to']);
            $table->index(['queue_id', 'priority_id']);
        });

        Schema::create('task_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('related_type');
            $table->unsignedBigInteger('related_id');
            $table->string('relation_type')->default('related');
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->unique(['task_id', 'related_type', 'related_id', 'relation_type'], 'task_relations_unique');
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('dependency_type')->default('blocks_completion');
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id', 'dependency_type'], 'task_dependencies_unique');
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_checked')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('checked_by')->nullable()->index();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('source_type')->default('manual')->index();
            $table->date('work_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->boolean('billable')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'user_id']);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->string('source')->default('upload')->index();
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('disk')->default('local');
            $table->string('path', 1024);
            $table->char('checksum_sha1', 40)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type')->index();
            $table->string('visibility')->default('internal')->index();
            $table->text('body')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
        });

        Schema::create('task_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_group_id')->constrained('task_template_groups')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('task_template_items')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('task_statuses')->nullOnDelete();
            $table->foreignId('queue_id')->nullable()->constrained('ticket_queues')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('ticket_priorities')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->boolean('blocks_owner_completion')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('task_template_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_item_id')->constrained('task_template_items')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_template_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_item_id')->constrained('task_template_items')->cascadeOnDelete();
            $table->foreignId('depends_on_template_item_id')->constrained('task_template_items')->cascadeOnDelete();
            $table->string('dependency_type')->default('blocks_completion');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });

        Schema::create('task_recurring_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_group_id')->constrained('task_template_groups')->cascadeOnDelete();
            $table->string('name');
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('interval')->default('monthly');
            $table->json('interval_config')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_recurring_templates');
        Schema::dropIfExists('task_template_dependencies');
        Schema::dropIfExists('task_template_checklist_items');
        Schema::dropIfExists('task_template_items');
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_time_entries');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('task_relations');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_template_groups');
        Schema::dropIfExists('task_statuses');
    }
};
