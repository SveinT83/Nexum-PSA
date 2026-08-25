<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('schedule_type')->default('one_time'); // one_time|recurring
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('recurrence_rule')->nullable();
            $table->timestamp('recurrence_ends_at')->nullable();
            $table->unsignedBigInteger('calendar_event_id')->nullable(); // Optional deterministic calendar event linkage
            $table->string('sla_mode')->default('defer_until_planned_start'); // defer_until_planned_start|non_sla_until_start|normal
            $table->string('status')->default('scheduled'); // scheduled|active|completed|cancelled|missed
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->index('planned_start_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_schedules');
    }
};
