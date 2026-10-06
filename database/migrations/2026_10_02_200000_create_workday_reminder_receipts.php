<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One logical reminder per employee/local work date; only explicit snooze advances generation.
        Schema::create('workday_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained(env('AUTH_USER_TABLE', 'user_management'))->restrictOnDelete();
            $table->date('work_date');
            $table->string('timezone', 64);
            $table->unsignedInteger('generation')->default(1);
            $table->dateTime('due_at');
            $table->dateTime('snoozed_until')->nullable();
            $table->dateTime('expires_at')->index();
            $table->uuid('notification_id');
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
        });
        Schema::create('workday_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained('workday_reminders')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->string('channel', 16);
            $table->string('state', 24)->default('pending');
            $table->json('mail_snapshot')->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['reminder_id', 'generation', 'channel'], 'workday_delivery_generation_channel');
            $table->index(['state', 'id']);
        });
        Schema::create('workday_reminder_cursors', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_user_id')->default(0);
            $table->dateTime('last_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('workday_reminders')->exists()) {
            throw new RuntimeException('Retained reminder receipts exist. Disable Workday and use reviewed retention cleanup.');
        }
        Schema::dropIfExists('workday_reminder_deliveries');
        Schema::dropIfExists('workday_reminders');
        Schema::dropIfExists('workday_reminder_cursors');
    }
};
