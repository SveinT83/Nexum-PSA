<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_key')->unique();
            $table->string('type')->default('support');
            $table->foreignId('queue_id')->constrained('ticket_queues');
            $table->foreignId('status_id')->constrained('ticket_statuses');
            $table->foreignId('priority_id')->constrained('ticket_priorities');
            $table->foreignId('sla_id')->nullable()->constrained('sla')->nullOnDelete();
            $table->string('sla_source')->nullable()->index();
            $table->unsignedBigInteger('sla_source_id')->nullable()->index();
            $table->json('sla_snapshot')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->string('channel')->default('manual');
            $table->string('subject');
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('impact')->nullable();
            $table->unsignedTinyInteger('urgency')->nullable();
            $table->boolean('is_unread')->default(false);
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolve_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['queue_id', 'status_id']);
            $table->index(['owner_id', 'status_id']);
            $table->index(['updated_at', 'is_unread']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
