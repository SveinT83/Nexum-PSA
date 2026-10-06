<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Durable baselines and write intent survive worker crashes and pauses.
        Schema::create('tripletex_workday_sync_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('connection_id');
            $table->foreignId('user_id')->constrained('user_management')->restrictOnDelete();
            $table->date('work_date');
            $table->json('baseline')->nullable();
            $table->json('intent')->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('error_code', 100)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->unique(['connection_id', 'user_id', 'work_date'], 'tripletex_workday_scope_unique');
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('tripletex_workday_sync_states')->exists()) {
            throw new RuntimeException('Pause synchronization and preserve delivery evidence before rollback.');
        }
        Schema::dropIfExists('tripletex_workday_sync_states');
    }
};
